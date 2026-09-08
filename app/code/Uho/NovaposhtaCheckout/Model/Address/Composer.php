<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Address;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Perspective\NovaposhtaCatalog\Api\CityRepositoryInterface;
use Perspective\NovaposhtaCatalog\Api\Data\WarehouseInterface;
use Perspective\NovaposhtaCatalog\Model\City\City;

use function preg_match;
use function strcasecmp;
use function trim;
use Perspective\NovaposhtaCatalog\Model\ResourceModel\Warehouse\Warehouse\CollectionFactory
    as WarehouseCollectionFactory;
use Uho\NovaposhtaCheckout\Api\AddressComposerInterface;
use Uho\NovaposhtaCheckout\Api\Data\ComposedAddressInterface;
use Uho\NovaposhtaCheckout\Model\Address\PostcodeStrategy\StrategyPool;
use Uho\NovaposhtaCheckout\Model\Config;
use Uho\NovaposhtaCheckout\Model\Region\Resolver as RegionResolver;

/**
 * Turns a (city ref, warehouse ref) pair into a complete Magento address.
 *
 * The storefront supplies nothing but the two refs; street, region, region_id and
 * postcode are derived here and nowhere else, so a hand-crafted checkout payload
 * cannot inject them.
 */
class Composer implements AddressComposerInterface
{
    private const string COUNTRY_ID = 'UA';
    private const string WAREHOUSE_STATUS_WORKING = 'Working';
    private const string REF_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @var string[]
     */
    private const array WAREHOUSE_COLUMNS = [
        'id',
        WarehouseInterface::SITE_KEY,
        WarehouseInterface::DESCRIPTION_UA,
        WarehouseInterface::DESCRIPTION_RU,
        WarehouseInterface::SHORT_ADDRESS_UA,
        WarehouseInterface::REF,
        WarehouseInterface::NUMBER_IN_CITY,
        WarehouseInterface::CITY_REF,
        WarehouseInterface::CITY_DESCRIPTION_UA,
        WarehouseInterface::SETTLEMENT_AREA_DESCRIPTION,
        WarehouseInterface::CATEGORY_OF_WAREHOUSE,
        WarehouseInterface::WAREHOUSE_STATUS,
    ];

    public function __construct(
        private readonly CityRepositoryInterface $cityRepository,
        private readonly WarehouseCollectionFactory $warehouseCollectionFactory,
        private readonly RegionResolver $regionResolver,
        private readonly StrategyPool $postcodeStrategyPool,
        private readonly Config $config,
        private readonly ComposedAddressFactory $composedAddressFactory,
    ) {
    }

    public function compose(string $cityRef, string $warehouseRef, ?int $storeId = null): ComposedAddressInterface
    {
        $cityRef = $this->assertRef($cityRef, 'city');
        $warehouseRef = $this->assertRef($warehouseRef, 'warehouse');

        $city = $this->loadCity($cityRef);
        $warehouse = $this->loadWarehouse($cityRef, $warehouseRef, $storeId);

        $area = trim((string) $warehouse->getData(WarehouseInterface::SETTLEMENT_AREA_DESCRIPTION));
        $regionCode = $this->regionResolver->resolveRegionCode($area, $cityRef, (int) $warehouse->getData('id'));
        $regionId = $this->regionResolver->resolveRegionId($regionCode);

        $postcode = $this->postcodeStrategyPool
            ->get($this->config->getPostcodeStrategy($storeId))
            ->getPostcode($regionCode);

        $cityName = $this->firstNonEmpty(
            (string) $city->getDescriptionUa(),
            (string) $warehouse->getData(WarehouseInterface::CITY_DESCRIPTION_UA),
        );
        if ($cityName === null) {
            throw new NoSuchEntityException(
                __('The selected Nova Poshta city has no name and cannot be used as a delivery address.')
            );
        }

        $warehouseName = $this->firstNonEmpty(
            (string) $warehouse->getData(WarehouseInterface::DESCRIPTION_UA),
            (string) $warehouse->getData(WarehouseInterface::DESCRIPTION_RU),
            (string) $warehouse->getData(WarehouseInterface::SHORT_ADDRESS_UA),
        );
        if ($warehouseName === null) {
            throw new NoSuchEntityException(
                __('The selected Nova Poshta warehouse has no address and cannot be used as a delivery address.')
            );
        }

        $street = trim((string) $warehouse->getData(WarehouseInterface::SHORT_ADDRESS_UA));

        return $this->composedAddressFactory->create([
            'countryId' => self::COUNTRY_ID,
            'city' => $cityName,
            'street' => [$street],
            'region' => $area,
            'regionId' => $regionId,
            'postcode' => $postcode,
            'cityRef' => $cityRef,
            'cityName' => $cityName,
            'warehouseRef' => $warehouseRef,
            'warehouseName' => $warehouseName,
            'warehouseSiteKey' => trim((string) $warehouse->getData(WarehouseInterface::SITE_KEY)),
        ]);
    }

    /**
     * Rejects anything that is not a Nova Poshta GUID before it reaches the database.
     *
     * This also stops the vendor repositories' `-502` pseudo-option from ever being looked up.
     */
    private function assertRef(string $ref, string $kind): string
    {
        $ref = trim($ref);

        if (preg_match(self::REF_PATTERN, $ref) !== 1) {
            throw new NoSuchEntityException(
                $kind === 'city'
                    ? __('The selected Nova Poshta city is not valid. Please choose a city from the list.')
                    : __('The selected Nova Poshta warehouse is not valid. Please choose one from the list.')
            );
        }

        return $ref;
    }

    private function loadCity(string $cityRef): City
    {
        $city = $this->cityRepository->getCityByCityRef($cityRef);

        if (!$city->getId() || strcasecmp((string) $city->getRef(), $cityRef) !== 0) {
            throw new NoSuchEntityException(
                __('The selected Nova Poshta city is not valid. Please choose a city from the list.')
            );
        }

        return $city;
    }

    /**
     * Targeted query rather than the vendor list methods: `ref` is an unindexed longtext, so the
     * indexed `city_ref` predicate is what keeps this off a 54k-row full scan.
     */
    private function loadWarehouse(string $cityRef, string $warehouseRef, ?int $storeId): DataObject
    {
        $collection = $this->warehouseCollectionFactory->create();
        $collection->addFieldToSelect(self::WAREHOUSE_COLUMNS);
        $collection->addFieldToFilter(WarehouseInterface::CITY_REF, $cityRef);
        $collection->addFieldToFilter(WarehouseInterface::REF, $warehouseRef);
        $collection->addFieldToFilter(WarehouseInterface::WAREHOUSE_STATUS, self::WAREHOUSE_STATUS_WORKING);

        $categories = $this->config->getWarehouseCategories($storeId);
        if ($categories !== []) {
            $collection->addFieldToFilter(WarehouseInterface::CATEGORY_OF_WAREHOUSE, ['in' => $categories]);
        }

        $collection->setPageSize(1);
        $collection->setCurPage(1);

        $warehouse = $collection->getFirstItem();

        if (!$warehouse->getData('id')) {
            throw new NoSuchEntityException(
                __('The selected Nova Poshta warehouse is not available. Please choose another one.')
            );
        }

        return $warehouse;
    }

    private function firstNonEmpty(string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
