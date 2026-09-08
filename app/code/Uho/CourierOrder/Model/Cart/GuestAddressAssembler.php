<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cart;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Uho\CourierOrder\Model\Address\NameParts;
use Uho\CourierOrder\Model\Address\NameSplitter;
use Uho\CourierOrder\Model\Exception\InvalidPayloadException;
use Uho\CourierOrder\Model\Request\Record;
use Uho\NovaposhtaCheckout\Api\AddressComposerInterface;
use Uho\NovaposhtaCheckout\Api\Data\ComposedAddressInterface;

class GuestAddressAssembler
{
    public function __construct(
        private readonly AddressComposerInterface $addressComposer,
        private readonly NameSplitter $nameSplitter,
    ) {
    }

    /**
     * @throws InvalidPayloadException
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function assemble(Record $record): array
    {
        $composed = $this->addressComposer->compose(
            (string) $record->getResolvedCityRef(),
            (string) $record->getResolvedWarehouseRef(),
            $record->getStoreId()
        );
        $nameParts = $this->nameSplitter->split($record->getFullName());

        return $this->toAddressData($composed, $nameParts, $record->getPhone());
    }

    private function toAddressData(
        ComposedAddressInterface $composed,
        NameParts $nameParts,
        string $phone,
    ): array {
        return [
            'firstname' => $nameParts->getFirstname(),
            'lastname' => $nameParts->getLastname(),
            'telephone' => $phone,
            'country_id' => $composed->getCountryId(),
            'city' => $composed->getCity(),
            'street' => $composed->getStreet(),
            'region' => $composed->getRegion(),
            'region_id' => $composed->getRegionId(),
            'postcode' => $composed->getPostcode(),
            'save_in_address_book' => 0,
        ];
    }
}
