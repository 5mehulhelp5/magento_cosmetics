<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model\Locator;

use Magento\Framework\Data\Collection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Perspective\NovaposhtaCatalog\Api\Data\CityInterface;
use Perspective\NovaposhtaCatalog\Model\ResourceModel\City\City\CollectionFactory;
use Uho\NovaposhtaCheckout\Api\CityLocatorInterface;
use Uho\NovaposhtaCheckout\Api\Data\CitySuggestionInterface;
use Uho\NovaposhtaCheckout\Model\Cache\ReferenceDataCache;
use Uho\NovaposhtaCheckout\Model\Config;

use function array_map;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function min;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function trim;
/**
 * Prefix search over the 11k-row local city table.
 *
 * Neither name column is indexed (both are longtext), so every miss is a full scan; the read is
 * therefore kept to the two columns actually needed, capped by page size and served from
 * ReferenceDataCache on repeat.
 */
class CityLocator implements CityLocatorInterface
{
    public const int MIN_QUERY_LENGTH = 2;

    private const string CACHE_NAMESPACE = 'city_search';
    private const int MAX_QUERY_LENGTH = 64;
    private const string LOCALE_PREFIX_RU = 'ru';

    public function __construct(
        private readonly CollectionFactory $cityCollectionFactory,
        private readonly Config $config,
        private readonly ReferenceDataCache $referenceDataCache,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResolverInterface $localeResolver,
        private readonly CitySuggestionFactory $citySuggestionFactory,
    ) {
    }

    /**
     * @return CitySuggestionInterface[]
     */
    public function search(string $query, ?int $limit = null): array
    {
        $term = $this->normaliseTerm($query);
        if ($term === '') {
            return [];
        }

        $storeId = $this->resolveStoreId();
        $labelColumn = $this->resolveLabelColumn();
        $pageSize = $this->clampLimit($limit, $this->config->getCitySearchLimit($storeId));

        $rows = $this->referenceDataCache->get(
            [self::CACHE_NAMESPACE, $storeId, $labelColumn, $term, $pageSize],
            fn (): array => $this->fetchRows($term, $labelColumn, $pageSize),
        );

        return array_map(
            fn (array $row): CitySuggestionInterface => $this->citySuggestionFactory->create([
                'ref' => $row['ref'],
                'label' => $row['label'],
            ]),
            $rows,
        );
    }

    /**
     * @return array<int, array{ref: string, label: string}>
     */
    private function fetchRows(string $term, string $labelColumn, int $pageSize): array
    {
        $collection = $this->cityCollectionFactory->create();
        $collection->addFieldToSelect([CityInterface::REF, $labelColumn])
            ->addFieldToFilter($labelColumn, ['like' => $this->likePrefix($term)])
            ->addOrder($labelColumn, Collection::SORT_ORDER_ASC)
            ->setPageSize($pageSize)
            ->setCurPage(1);

        $rows = [];
        foreach ($collection->getData() as $row) {
            $ref = trim((string) ($row[CityInterface::REF] ?? ''));
            $label = trim((string) ($row[$labelColumn] ?? ''));
            if ($ref === '' || $label === '') {
                continue;
            }

            $rows[] = ['ref' => $ref, 'label' => $label];
        }

        return $rows;
    }

    /**
     * Lower-cased because the column collation is case-insensitive anyway, which makes the
     * cache key stable across capitalisations of the same search.
     */
    private function normaliseTerm(string $query): string
    {
        $term = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return '';
        }

        return mb_strtolower(mb_substr($term, 0, self::MAX_QUERY_LENGTH));
    }

    /**
     * Neutralises the LIKE wildcards so a submitted '%' cannot turn a prefix match into a
     * full-table contains scan. The value itself is still bound by addFieldToFilter().
     */
    private function likePrefix(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
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
            ? CityInterface::DESCRIPTION_RU
            : CityInterface::DESCRIPTION_UA;
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
