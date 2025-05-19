<?php declare(strict_types=1);

namespace FraudLabsPro;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class FraudLabsPro extends Plugin
{

    public function install(InstallContext $installContext): void
    {
        // Do stuff such as creating a new payment method
        parent::install($installContext);
        $this->createCustomField($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Activate entities, such as a new payment method
        // Or create new entities here, because now your plugin is installed and active for sure
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // Deactivate entities, such as a new payment method
        // Or remove previously created entities
    }

    public function update(UpdateContext $updateContext): void
    {
        // Update necessary stuff, mostly non-database related
        parent::update($updateContext);
        $this->createCustomField($updateContext->getContext());
    }

    private function createCustomField(\Shopware\Core\Framework\Context $context): void
    {
        /** @var EntityRepository $customFieldSetRepository */
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $customFieldRepository = $this->container->get('custom_field.repository');
        
        $customFieldSetName = 'internal_order_notes';
        $customFieldName = 'flp_internal_note';

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $customFieldSetName));
        $criteria->addAssociation('customFields'); // Load related custom fields

        $existing_set = $customFieldSetRepository->search($criteria, $context);

        if ($existing_set->getTotal() > 0) {
            $customFieldSet = $existing_set->first();
            $customFields = $customFieldSet->getCustomFields();
            $fieldExists = false;
            if ($customFields) {
                foreach ($customFields as $field) {
                    if ($field->getName() === $customFieldName) {
                        $fieldExists = true;
                        break;
                    }
                }
            }
            if ($fieldExists) {
                return;
            }
        }

        $customFieldSetRepository->upsert([
            [
                'name' => 'internal_order_notes',
                'config' => [
                    'label' => [
                        'en-GB' => 'Internal Order Notes',
                        'de-DE' => 'Interne Bestellnotizen',
                    ],
                ],
                'customFields' => [
                    [
                        'name' => 'flp_internal_note',
                        'type' => 'text',
                        'config' => [
                            'label' => [
                                'en-GB' => 'Internal Note',
                                'de-DE' => 'Interne Notiz',
                            ],
                            'componentName' => 'sw-textarea-field',
                            'customFieldType' => 'text',
                            'type' => 'text',
                            'readonly' => true,
                            'apiAware' => false,
                        ],
                    ],
                ],
                'relations' => [
                    ['entityName' => 'order'],
                ],
            ]
        ], $context);
    }

    public function postInstall(InstallContext $installContext): void
    {
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
    }
}
