<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Block\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;

use function is_array;
/**
 * Strips the shipping-address fieldset down to firstname, lastname and telephone plus the two
 * Nova Poshta selectors injected by layout XML; everything else is server-composed by
 * Model\Address\Composer (§3.1, §6 B1). Applies the same reduction to every billing-address
 * fieldset as a safety net for decision #1 ("Force billing = shipping"), in case the
 * `Uho_NovaposhtaCheckout/js/model/billing-address-mixin` JS ever fails to keep the checkbox
 * hidden or the two addresses in sync (§6 B4).
 *
 * Registered on Magento\Checkout\Block\Onepage::$layoutProcessors with no explicit sortOrder
 * (etc/frontend/di.xml): plain DI array arguments merge in module-sequence order, and this
 * module sequences after Magento_Checkout, so this instance always runs after the core
 * LayoutProcessor and DirectoryDataProcessor that generate these fields in the first place.
 *
 * Fields are hidden, not removed: Magento_Checkout/js/model/shipping-rates-validator and several
 * checkoutProvider bindings expect the keys to exist even when nobody can edit them.
 */
class LayoutProcessor implements LayoutProcessorInterface
{
    /**
     * @var string[]
     */
    private const array HIDDEN_SHIPPING_FIELDS = [
        'company',
        'street',
        'city',
        'region',
        'region_id',
        'postcode',
        'country_id',
        'fax',
        'vat_id',
        'prefix',
        'middlename',
        'suffix',
    ];

    /**
     * Same set as HIDDEN_SHIPPING_FIELDS plus 'city': the architecture doc's B4 field list
     * (company, street, region, region_id, postcode, country_id, fax, vat_id, prefix,
     * middlename, suffix) omits 'city', but leaving a lone city field visible while street,
     * region and postcode are hidden would render a half-empty, confusing fieldset — city is
     * kept hidden here for consistency with the shipping fieldset. Flagged for confirmation.
     *
     * @var string[]
     */
    private const array HIDDEN_BILLING_FIELDS = [
        'company',
        'street',
        'city',
        'region',
        'region_id',
        'postcode',
        'country_id',
        'fax',
        'vat_id',
        'prefix',
        'middlename',
        'suffix',
    ];

    /**
     * @var string[]
     */
    private const array SHIPPING_FIELDSET_PATH = [
        'components', 'checkout',
        'children', 'steps',
        'children', 'shipping-step',
        'children', 'shippingAddress',
        'children', 'shipping-address-fieldset',
        'children',
    ];

    /**
     * The component name every billing-address instance is registered under, regardless of which
     * payment method it belongs to or whether it lives at `payment.children.afterMethods` (shared
     * form) or nested per payment code under `payment.children.payments-list` (see
     * Magento\Checkout\Block\Checkout\LayoutProcessor::getBillingAddressComponent()). Walking the
     * tree for this marker, instead of hardcoding a path per payment method, is what keeps this
     * working as installed payment methods (e.g. Braintree) change.
     */
    private const string BILLING_ADDRESS_COMPONENT = 'Magento_Checkout/js/view/billing-address';

    public function process($jsLayout)
    {
        $fieldset = $this->readByPath($jsLayout, self::SHIPPING_FIELDSET_PATH);
        if ($fieldset !== null) {
            $jsLayout = $this->writeByPath(
                $jsLayout,
                self::SHIPPING_FIELDSET_PATH,
                $this->hideFields($fieldset, self::HIDDEN_SHIPPING_FIELDS),
            );
        }

        return $this->hideBillingFieldsets($jsLayout);
    }

    /**
     * Recursively finds every `Magento_Checkout/js/view/billing-address` node and hides its
     * address fields, regardless of where in the tree it is nested.
     */
    private function hideBillingFieldsets(array $node): array
    {
        if (($node['component'] ?? null) === self::BILLING_ADDRESS_COMPONENT
            && isset($node['children']['form-fields']['children'])
            && is_array($node['children']['form-fields']['children'])
        ) {
            $node['children']['form-fields']['children'] = $this->hideFields(
                $node['children']['form-fields']['children'],
                self::HIDDEN_BILLING_FIELDS,
            );
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->hideBillingFieldsets($value);
            }
        }

        return $node;
    }

    /**
     * @param string[] $fields
     */
    private function hideFields(array $fieldset, array $fields): array
    {
        foreach ($fields as $field) {
            if (!isset($fieldset[$field]) || !is_array($fieldset[$field])) {
                $fieldset[$field] = [];
            }

            $fieldset[$field]['visible'] = false;
            unset($fieldset[$field]['validation']['required-entry']);
            unset($fieldset[$field]['config']['customEntry']);

            if (isset($fieldset[$field]['children']) && is_array($fieldset[$field]['children'])) {
                foreach ($fieldset[$field]['children'] as $childKey => $child) {
                    if (is_array($child)) {
                        $fieldset[$field]['children'][$childKey]['visible'] = false;
                    }
                }
            }
        }

        return $fieldset;
    }

    /**
     * @param string[] $path
     */
    private function readByPath(array $jsLayout, array $path): ?array
    {
        $node = $jsLayout;
        foreach ($path as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @param string[] $path
     */
    private function writeByPath(array $jsLayout, array $path, array $value): array
    {
        $cursor = &$jsLayout;
        foreach ($path as $key) {
            if (!isset($cursor[$key]) || !is_array($cursor[$key])) {
                $cursor[$key] = [];
            }
            $cursor = &$cursor[$key];
        }
        $cursor = $value;

        return $jsLayout;
    }
}
