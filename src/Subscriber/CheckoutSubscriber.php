<?php declare(strict_types=1);

namespace FraudLabsPro\Subscriber;

use Shopware\Core\Defaults;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;


use Shopware\Core\Checkout\Order\OrderRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class CheckoutSubscriber implements EventSubscriberInterface
{
    private StateMachineRegistry $stateMachineRegistry;
    private SystemConfigService $systemConfigService;
    private RequestStack $requestStack;
    private EntityRepository $orderRepository;
    private $pluginVersion = '1.0.0';

    public function __construct(StateMachineRegistry $stateMachineRegistry, SystemConfigService $systemConfigService, RequestStack $requestStack, EntityRepository $orderRepository)
    {
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->systemConfigService = $systemConfigService;
        $this->requestStack = $requestStack;
        $this->orderRepository = $orderRepository;
    }

    public static function getSubscribedEvents(): array
    {
        // Return the events to listen to as array like this:  <event to listen to> => <method to execute>
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderLoaded'
        ];
    }

    public function onOrderLoaded(CheckoutOrderPlacedEvent $event)
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }
        $flpApiKey = $this->systemConfigService->get('FraudLabsPro.config.apiKey', null);
        if ($flpApiKey == '') {
            // No API Key found, skip the validation
            return;
        }
        $flpApproveStageId = $this->systemConfigService->get('FraudLabsPro.config.approveStageId', null);
        $flpReviewStageId = $this->systemConfigService->get('FraudLabsPro.config.reviewStageId', null);
        $flpRejectStageId = $this->systemConfigService->get('FraudLabsPro.config.rejectStageId', null);

        $request = $this->requestStack->getCurrentRequest();
        
        $order = $event->getOrder();
        //file_put_contents('/var/www/shopware/var/log/debug-order.log', print_r(get_class($order), true) . PHP_EOL, FILE_APPEND);
        //file_put_contents('/var/www/shopware/var/log/debug-order.log', print_r(get_class_methods($order), true) . PHP_EOL, FILE_APPEND);
        $context = $event->getContext();
        $salesChannelContext = $event->getSalesChannelContext();
        $billing = $salesChannelContext->getCustomer()?->getActiveBillingAddress();
        $shipping = $salesChannelContext->getCustomer()?->getActiveShippingAddress();
        $arr1 =$order->getTransactions()->getOrderIds();
        $arr2 =$order->getDeliveries()->getOrderIds();
        
        $ip = $this->getClientIp();
        $email = $order->getOrderCustomer()?->getEmail();
        $currency = $order->getCurrency()->getIsoCode();
        $paymentMode = $salesChannelContext->getPaymentMethod()->getName();
        if ($paymentMode == 'Cash on delivery') {
            $paymentMode = 'cod';
        }
        if ($paymentMode == 'PayPal') {
            $paymentMode = 'paypal ';
        }
        $totalQuantity = 0;
        $productInfoList = [];
        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }
            $totalQuantity += $lineItem->getQuantity();
            $productNumber = $lineItem->getPayload()['productNumber'];
            $quantity = $lineItem->getQuantity();
            $productInfoList[] = "{$productNumber}:{$quantity}";
        }
        $item_sku = implode(',', $productInfoList);

        
        $orderDetails = [
            'key' => $flpApiKey,
            'ip' => $ip,
            'first_name' => $billing->getFirstName(),
            'last_name' => $billing->getLastName(),
            'bill_addr' => $billing->getStreet(),
            'bill_city' => $billing->getCity(),
            'bill_state' => $billing->getCountryState()->getShortCode(),
            'bill_country' => $billing->getCountry()->getIso(),
            'bill_zip_code' => $billing->getZipcode(),
            'ship_first_name' => $shipping->getFirstName(),
            'ship_last_name' => $shipping->getLastName(),
            'ship_addr' => $shipping->getStreet(),
            'ship_city' => $shipping->getCity(),
            'ship_state' => ($shipping->getCountryState()->getShortCode()) ?? '',
            'ship_zip_code' => $shipping->getZipcode(),
            'ship_country' => $shipping->getCountry()->getIso(),
            'items' => $item_sku,
            'user_phone' => $billing->getPhoneNumber(),
            'email' => $email,
            'email_domain' => substr($email, strpos($email, '@' ) + 1),
            'email_hash' => $this->hashIt($email),
            'amount' => $order->getPrice()->getTotalPrice(),
            'quantity' => $totalQuantity,
            'currency' => $currency,
            'payment_mode' => $paymentMode,
            //'user_order_id' => $order->getId(),
            'user_order_id' => $order->getOrderNumber(),
            'device_fingerprint' => ($request->cookies->get('flp_device')) ?? '',
            'flp_checksum' => ($request->cookies->get('flp_checksum')) ?? '',
            'format' => 'json',
            'source' => 'shopware',
            'source_version' => $this->pluginVersion,
        ];
        $flpResult = $this->flpValidate($orderDetails);
        if ((is_bool($flpResult)) && ($flpResult)) {
            return;
        }
        if (! is_bool($flpResult)) {
            $finalOrderStatus = '';
            $note = '';
            if ($flpResult->fraudlabspro_status == 'REVIEW') {
                $finalOrderStatus = (($flpReviewStageId != 'no_change') ? $flpReviewStageId : '');
                $note = "Review triggered by FraudLabs Pro with Fraud score: $flpResult->fraudlabspro_score. For details, please visit https://www.fraudlabspro.com/merchant/transaction-details/$flpResult->fraudlabspro_id";
            }
            if ($flpResult->fraudlabspro_status == 'APPROVE') {
                $finalOrderStatus = (($flpApproveStageId != 'no_change') ? $flpApproveStageId : '');
                $note = "Approved by FraudLabs Pro with Fraud score: $flpResult->fraudlabspro_score. For details, please visit https://www.fraudlabspro.com/merchant/transaction-details/$flpResult->fraudlabspro_id";
            }
            if ($flpResult->fraudlabspro_status == 'REJECT') {
                $finalOrderStatus = (($flpRejectStageId != 'no_change') ? $flpRejectStageId : '');
                $note = "Rejected by FraudLabs Pro with Fraud score: $flpResult->fraudlabspro_score. For details, please visit https://www.fraudlabspro.com/merchant/transaction-details/$flpResult->fraudlabspro_id";
            }
            // $note = "Review triggered by FraudLabs Pro with Fraud score: $flpResult->fraudlabspro_score. For details, please visit https://www.fraudlabspro.com/merchant/transaction-details/$flpResult->fraudlabspro_id";
            
            if ($note != '') {
                $customFields = $order->getCustomFields() ?? [];
                // Sanitize the note before storing it
                $customFields['flp_internal_note'] = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
                $this->orderRepository->update([
                    [
                        'id' => $order->getId(),
                        'customFields' => $customFields,
                    ]
                ], $context);
            }
            if ($finalOrderStatus != '') {
                $transition = new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $order->getId(),
                    $finalOrderStatus,
                    'stateId'
                );
                $this->stateMachineRegistry->transition($transition, $context);
                if (in_array($finalOrderStatus, ['cancel'])) {
                    // $transition = new Transition(
                        // OrderDeliveryDefinition::ENTITY_NAME,
                        // $order->getId(),
                        // $finalOrderStatus,
                        // 'stateId'
                    // );
                    // $this->stateMachineRegistry->transition($transition, $context);
                    foreach ($arr1 as $arr1_key => $arr1_value) {
                        $transition = new Transition(
                            OrderTransactionDefinition::ENTITY_NAME,
                            $arr1_key,
                            $finalOrderStatus,
                            'stateId'
                        );
                        $this->stateMachineRegistry->transition($transition, $context);
                    }
                }
            }
        }
        return;
    }
    
    private function flpValidate(array $orderDetails)
    {
        if ((count($orderDetails) == 0)) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.fraudlabspro.com/v2/order/screen');
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        curl_setopt($ch, CURLOPT_HTTP_VERSION, '1.1');
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (is_array($orderDetails)) ? http_build_query($orderDetails) : $orderDetails);

        $response = curl_exec($ch);

        curl_close($ch);
        
        if (is_null($json = json_decode($response)) === TRUE) {
            return true;
        }
        $flpErr = ($json->fraudlabspro_error_code ?? '');
        if ($flpErr) {
            return true;
        }
        return $json;
        // return true;
    }

    private function getClientIp()
    {
        $request = $this->requestStack->getCurrentRequest();
        $ipAddress = $request ? $request->getClientIp() : 'unknown';
        return $ipAddress;
    }

    private function hashIt($s, $prefix = 'fraudlabspro_')
    {
        $hash = $prefix . $s;
        for ($i = 0; $i < 65536; ++$i) {
            $hash = sha1($prefix . $hash);
        }
        $hash2 = hash('sha256', $hash);
        return $hash2;
    }
}
