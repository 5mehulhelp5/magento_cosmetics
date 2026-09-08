<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Address;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\AddressExtensionInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Store\Model\StoreManagerInterface;
use Uho\NovaposhtaCheckout\Api\AddressComposerInterface;
use Uho\NovaposhtaCheckout\Api\Data\ComposedAddressInterface;

use function trim;
/**
 * Shared read+compose+apply logic for the two service-contract plugins that intercept an
 * incoming shipping AddressInterface before core persists or validates it (docs §6 B3).
 *
 * Both Magento\Checkout\Model\ShippingInformationManagement::saveAddressInformation() and
 * Magento\Quote\Model\ShippingAddressManagement::assign() eventually merge the mutated address
 * into the real quote address entity via `$quoteAddress->addData($address->getData())` (see
 * Magento\Quote\Model\Quote::setShippingAddress()). That is a flat-array copy of `_data`: it does
 * NOT unpack `extension_attributes` into columns. So the composed values are applied here as
 * plain setData() keys — which do survive that merge, the resource model save, and the
 * fieldset.xml quote-to-order copy — rather than only as extension attributes.
 */
class AddressExtensionRefApplier
{
    public function __construct(
        private readonly AddressComposerInterface $addressComposer,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @throws LocalizedException When exactly one ref is present, or the region/postcode cannot
     *                            be derived.
     * @throws NoSuchEntityException When either ref does not resolve to a selectable local record.
     */
    public function apply(AddressInterface $address): void
    {
        $extensionAttributes = $address->getExtensionAttributes();
        $this->discardClientSuppliedSnapshotAttributes($extensionAttributes);

        $cityRef = $this->normaliseRef($extensionAttributes?->getUhoNpCityRef());
        $warehouseRef = $this->normaliseRef($extensionAttributes?->getUhoNpWarehouseRef());

        if ($cityRef === null && $warehouseRef === null) {
            // Neither ref present: not a Nova Poshta selection at all, let core validation
            // reject the address on its own terms (e.g. missing country_id).
            return;
        }

        if ($cityRef === null || $warehouseRef === null) {
            // Exactly one ref present: a half-formed Nova Poshta selection must never reach
            // core validation as a partially-composed address.
            throw new LocalizedException(
                __('Both a Nova Poshta city and warehouse must be selected.')
            );
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $composed = $this->addressComposer->compose($cityRef, $warehouseRef, $storeId);

        $this->applyComposedAddress($address, $composed);
    }

    private function applyComposedAddress(AddressInterface $address, ComposedAddressInterface $composed): void
    {
        $address->setCountryId($composed->getCountryId());
        $address->setCity($composed->getCity());
        $address->setStreet($composed->getStreet());
        $address->setRegion($composed->getRegion());
        $address->setRegionId($composed->getRegionId());
        $address->setPostcode($composed->getPostcode());

        // These five are physical quote_address/sales_order_address columns (etc/db_schema.xml),
        // not just extension attributes — see the class docblock for why setData() is required.
        $address->setData('uho_np_city_ref', $composed->getCityRef());
        $address->setData('uho_np_city_name', $composed->getCityName());
        $address->setData('uho_np_warehouse_ref', $composed->getWarehouseRef());
        $address->setData('uho_np_warehouse_name', $composed->getWarehouseName());
        $address->setData('uho_np_warehouse_site_key', $composed->getWarehouseSiteKey());
    }

    private function normaliseRef(?string $ref): ?string
    {
        $ref = trim((string) $ref);

        return $ref !== '' ? $ref : null;
    }

    /**
     * `uho_np_city_name` / `uho_np_warehouse_name` / `uho_np_warehouse_site_key` are declared as
     * extension attributes so a saved value can ride the REST/GraphQL response contract (docs
     * §7), but {@see Composer::compose()} is meant to be their only writer. Nothing in this
     * module currently reads these three back off extension attributes (only the refs are read,
     * above), so a client-supplied value for them is inert today — but that safety depends on
     * every future reader consistently preferring the flat `getData()` snapshot columns over
     * `getExtensionAttributes()`. Clearing any incoming client value here up front, regardless of
     * whether a Nova Poshta selection is even present, removes that dependency instead of relying
     * on it.
     */
    private function discardClientSuppliedSnapshotAttributes(?AddressExtensionInterface $extensionAttributes): void
    {
        $extensionAttributes?->setUhoNpCityName(null);
        $extensionAttributes?->setUhoNpWarehouseName(null);
        $extensionAttributes?->setUhoNpWarehouseSiteKey(null);
    }
}
