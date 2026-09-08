<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Address;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Perspective\NovaposhtaCatalog\Api\Data\CityInterface;
use Perspective\NovaposhtaCatalog\Api\Data\WarehouseInterface;
use Perspective\NovaposhtaCatalog\Model\ResourceModel\City\City\CollectionFactory as CityCollectionFactory;
use Perspective\NovaposhtaCatalog\Model\ResourceModel\Warehouse\Warehouse\CollectionFactory as WarehouseCollectionFactory;
use Perspective\NovaposhtaCatalog\Model\Warehouse\WarehouseStatuses;
use Uho\CourierOrder\Model\Exception\AmbiguousMatchException;

use function array_map;
use function array_unique;
use function count;
use function sprintf;
use function trim;
class AddressResolver
{
    public function __construct(
        private readonly CityCollectionFactory $cityCollectionFactory,
        private readonly WarehouseCollectionFactory $warehouseCollectionFactory,
        private readonly NameNormalizer $nameNormalizer,
        private readonly ResolvedAddressFactory $resolvedAddressFactory,
    ) {
    }

    public function resolve(string $cityName, string $warehouseNumber): ResolvedAddress
    {
        $city = $this->resolveCity($cityName);
        $warehouse = $this->resolveWarehouse((string) $city[CityInterface::REF], $warehouseNumber);

        return $this->resolvedAddressFactory->create([
            'cityRef' => (string) $city[CityInterface::REF],
            'cityName' => (string) ($city[CityInterface::DESCRIPTION_UA] ?? $city[CityInterface::DESCRIPTION_RU] ?? ''),
            'warehouseRef' => (string) $warehouse[WarehouseInterface::REF],
            'warehouseName' => (string) (
                $warehouse[WarehouseInterface::DESCRIPTION_UA] ?? $warehouse[WarehouseInterface::DESCRIPTION_RU] ?? ''
            ),
        ]);
    }

    private function resolveCity(string $cityName): array
    {
        $normalized = $this->nameNormalizer->normalize($cityName);
        if ($normalized === '') {
            throw new NoSuchEntityException(new Phrase('City not found: empty city name.'));
        }

        $collection = $this->cityCollectionFactory->create();
        $connection = $collection->getConnection();
        $normalizedExpr = static fn (string $column): string => sprintf(
            "LOWER(TRIM(REPLACE(REPLACE(%s, '’', CHAR(39)), 'ʼ', CHAR(39))))",
            $connection->quoteIdentifier($column)
        );
        $collection->getSelect()->where(
            sprintf('%s = ?', $normalizedExpr(CityInterface::DESCRIPTION_UA)),
            $normalized
        )->orWhere(
            sprintf('%s = ?', $normalizedExpr(CityInterface::DESCRIPTION_RU)),
            $normalized
        );

        $rows = $collection->getData();
        $distinctRefs = array_unique(array_map(
            static fn (array $row): string => (string) ($row[CityInterface::REF] ?? ''),
            $rows
        ));

        if (count($distinctRefs) === 0) {
            throw new NoSuchEntityException(new Phrase('City not found: %1', [$cityName]));
        }
        if (count($distinctRefs) > 1) {
            throw new AmbiguousMatchException(new Phrase('City name is ambiguous: %1', [$cityName]));
        }

        return $rows[0];
    }

    private function resolveWarehouse(string $cityRef, string $warehouseNumber): array
    {
        $number = trim($warehouseNumber);
        if ($number === '') {
            throw new NoSuchEntityException(new Phrase('Warehouse not found: empty warehouse number.'));
        }

        $collection = $this->warehouseCollectionFactory->create();
        $collection->addFieldToFilter(WarehouseInterface::CITY_REF, ['eq' => $cityRef])
            ->addFieldToFilter(WarehouseInterface::NUMBER_IN_CITY, ['eq' => $number])
            ->addFieldToFilter(WarehouseInterface::WAREHOUSE_STATUS, ['eq' => WarehouseStatuses::WORKING]);

        $rows = $collection->getData();
        $distinctRefs = array_unique(array_map(
            static fn (array $row): string => (string) ($row[WarehouseInterface::REF] ?? ''),
            $rows
        ));

        if (count($distinctRefs) === 0) {
            throw new NoSuchEntityException(
                new Phrase('Warehouse not found or not working: number %1 in city %2', [$warehouseNumber, $cityRef])
            );
        }
        if (count($distinctRefs) > 1) {
            throw new AmbiguousMatchException(
                new Phrase('Warehouse number is ambiguous: %1 in city %2', [$warehouseNumber, $cityRef])
            );
        }

        return $rows[0];
    }
}
