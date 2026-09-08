<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Validator;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Uho\CourierOrder\Api\Data\CourierOrderRequestInterface;
use Uho\CourierOrder\Model\Address\NameSplitter;
use Uho\CourierOrder\Model\Exception\InvalidPayloadException;

use function preg_match;
use function trim;
class PayloadValidator
{
    private const string TOTAL_PATTERN = '/^\d+(\.\d{1,2})?$/';

    public function __construct(
        private readonly NameSplitter $nameSplitter,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function validate(CourierOrderRequestInterface $request): int
    {
        $this->requireNonEmpty($request->getTrackingNumber(), 'tracking_number');
        $this->requireNonEmpty($request->getPhone(), 'phone');
        $this->requireNonEmpty($request->getCityName(), 'city_name');
        $this->requireNonEmpty($request->getWarehouseNumber(), 'warehouse_number');
        $this->requireNonEmpty($request->getStoreCode(), 'store_code');

        $this->nameSplitter->split($request->getFullName());
        $this->validateTotal($request->getTotal());

        return $this->resolveStoreId($request->getStoreCode());
    }

    private function requireNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidPayloadException(new Phrase('%1 is required.', [$field]));
        }
    }

    private function validateTotal(string $total): void
    {
        if (!preg_match(self::TOTAL_PATTERN, trim($total))) {
            throw new InvalidPayloadException(
                new Phrase('total must be a positive decimal with at most 2 fractional digits: %1', [$total])
            );
        }
    }

    private function resolveStoreId(string $storeCode): int
    {
        try {
            $store = $this->storeManager->getStore($storeCode);
        } catch (NoSuchEntityException $exception) {
            throw new NoSuchEntityException(new Phrase('Unknown store_code: %1', [$storeCode]), $exception);
        }

        if (!$store instanceof Store) {
            throw new NoSuchEntityException(new Phrase('Unknown store_code: %1', [$storeCode]));
        }

        if (!$store->isActive()) {
            throw new NoSuchEntityException(new Phrase('store_code is not active: %1', [$storeCode]));
        }

        return (int) $store->getId();
    }
}
