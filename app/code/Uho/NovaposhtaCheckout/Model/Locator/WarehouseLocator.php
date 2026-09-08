<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Locator;

use Magento\Framework\Data\Collection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Perspective\NovaposhtaCatalog\Api\Data\WarehouseInterface;
use Perspective\NovaposhtaCatalog\Model\ResourceModel\Warehouse\Warehouse\CollectionFactory;
use Uho\NovaposhtaCheckout\Api\Data\WarehouseOptionInterface;
use Uho\NovaposhtaCheckout\Api\WarehouseLocatorInterface;
use Uho\NovaposhtaCheckout\Model\Cache\ReferenceDataCache;
use Uho\NovaposhtaCheckout\Model\Config;

use function array_map;
use function implode;
use function mb_strtolower;
use function mb_substr;
use function min;
use function preg_match;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function trim;
/**
 * Warehouse read model for the storefront selector.
 *
 * Kyiv alone holds 7,904 of the 54,311 warehouse rows, so the city filter is deliberately the
 * indexed city_ref equality lookup and the result never leaves the database unbounded. The
 * vendor's WarehouseRepositoryInterface::getListOfWarehousesByCityRef() is not used: it
 * hydrates one model per row and filters in PHP, and its error path emits a synthetic
 * '-502' option that must never reach an address.
 */
class WarehouseLocator implements WarehouseLocatorInterface
{
    private const string CACHE_NAMESPACE = 'warehouse_list';
    private const string STATUS_WORKING = 'Working';
    private const int MAX_QUERY_LENGTH = 64;
    private const string LOCALE_PREFIX_RU = 'ru';
    private const string CITY_REF_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly CollectionFactory $warehouseCollectionFactory,
        private readonly Config $config,
        private readonly ReferenceDataCache $referenceDataCache,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResolverInterface $localeResolver,
        private readonly WarehouseOptionFactory $warehouseOptionFactory,
    ) {
    }

    /**
     * @return WarehouseOptionInterface[]
     */
    public function getForCity(string $cityRef, string $query = '', ?int $limit = null): array
    {
        $ref = trim($cityRef);
        if (preg_match(self::CITY_REF_PATTERN, $ref) !== 1) {
            return [];
        }

        $storeId = $this->resolveStoreId();
        $labelColumn = $this->resolveLabelColumn();
        $term = $this->normaliseTerm($query);
        $categories = $this->config->getWarehouseCategories($storeId);
        $pageSize = $this->clampLimit($limit, $this->config->getWarehouseSearchLimit($storeId));

        $rows = $this->referenceDataCache->get(
            [self::CACHE_NAMESPACE, $storeId, $labelColumn, $ref, $term, $pageSize, implode(',', $categories)],
            fn (): array => $this->fetchRows($ref, $term, $labelColumn, $categories, $pageSize),
        );

        return array_map(
            fn (array $row): WarehouseOptionInterface => $this->warehouseOptionFactory->create([
                'ref' => $row['ref'],
                'label' => $row['label'],
                'number' => $row['number'],
                'siteKey' => $row['siteKey'],
            ]),
            $rows,
        );
    }

    /**
     * @param string[] $categories
     * @return array<int, array{ref: string, label: string, number: string, siteKey: string}>
     */
    private function fetchRows(
        string $cityRef,
        string $term,
        string $labelColumn,
        array $categories,
        int $pageSize,
    ): array {
        $collection = $this->warehouseCollectionFactory->create();
        $collection->addFieldToSelect([
            WarehouseInterface::REF,
            WarehouseInterface::SITE_KEY,
            WarehouseInterface::NUMBER_IN_CITY,
            $labelColumn,
        ])
            ->addFieldToFilter(WarehouseInterface::CITY_REF, $cityRef)
            ->addFieldToFilter(WarehouseInterface::WAREHOUSE_STATUS, self::STATUS_WORKING);

        if ($categories !== []) {
            $collection->addFieldToFilter(WarehouseInterface::CATEGORY_OF_WAREHOUSE, ['in' => $categories]);
        }

        if ($term !== '') {
            $collection->addFieldToFilter(
                [$labelColumn, WarehouseInterface::NUMBER_IN_CITY],
                [
                    ['like' => $this->likeContains($term)],
                    ['like' => $this->likePrefix($term)],
                ],
            );
        }

        // Rows of a city arrive from the sync in warehouse-number order, so the primary key is
        // both the natural ordering and the one the city_ref index can satisfy without a filesort.
        $collection->addOrder(WarehouseInterface::ID, Collection::SORT_ORDER_ASC)
            ->setPageSize($pageSize)
            ->setCurPage(1);

        $rows = [];
        foreach ($collection->getData() as $row) {
            $ref = trim((string) ($row[WarehouseInterface::REF] ?? ''));
            $label = trim((string) ($row[$labelColumn] ?? ''));
            if ($ref === '' || $label === '') {
                continue;
            }

            $rows[] = [
                'ref' => $ref,
                'label' => $label,
                'number' => trim((string) ($row[WarehouseInterface::NUMBER_IN_CITY] ?? '')),
                'siteKey' => trim((string) ($row[WarehouseInterface::SITE_KEY] ?? '')),
            ];
        }

        return $rows;
    }

    private function normaliseTerm(string $query): string
    {
        $term = trim(preg_replace('/\s+/u', ' ', $query) ?? '');

        return mb_strtolower(mb_substr($term, 0, self::MAX_QUERY_LENGTH));
    }

    /**
     * Neutralises the LIKE wildcards in the submitted term. The value itself is still bound by
     * addFieldToFilter(), which quotes it — this only stops '%' and '_' acting as wildcards.
     */
    private function escapeLikeTerm(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    private function likeContains(string $term): string
    {
        return '%' . $this->escapeLikeTerm($term) . '%';
    }

    private function likePrefix(string $term): string
    {
        return $this->escapeLikeTerm($term) . '%';
    }

    private function clampLimit(?int $requested, int $maximum): int
    {
        if ($requested === null || $requested < 1) {
            return $maximum;
        }

        return min($requested, $maximum);
    }

    private function resolveLabelColumn(): string
    {
        return str_starts_with((string) $this->localeResolver->getLocale(), self::LOCALE_PREFIX_RU)
            ? WarehouseInterface::DESCRIPTION_RU
            : WarehouseInterface::DESCRIPTION_UA;
    }

    private function resolveStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException) {
            return 0;
        }
    }
}
