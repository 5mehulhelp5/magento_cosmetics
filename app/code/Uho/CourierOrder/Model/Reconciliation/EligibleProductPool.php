<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Reconciliation;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;

use function round;
use function usort;
class EligibleProductPool
{
    private const string CACHE_TAG = 'uho_courier_reconciliation_eligible_pool';
    private const int CACHE_TTL_SECONDS = 300;

    public function __construct(
        private StoreManagerInterface $storeManager,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly CacheInterface $cache,
        private readonly Json $json,
    ) {
    }

    /**
     * @return array<int, array{sku: string, priceCents: int, reconciliationNumber: int}>
     */
    public function getPool(int $storeId): array
    {
        $cacheKey = self::CACHE_TAG . '_' . $storeId;
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false && $cached !== '[]') {
            return $this->json->unserialize($cached);
        }

        $pool = $this->fetchPool($storeId);
        $this->cache->save($this->json->serialize($pool), $cacheKey, [self::CACHE_TAG], self::CACHE_TTL_SECONDS);

        return $pool;
    }

    /**
     * @return array<int, array{sku: string, priceCents: int, reconciliationNumber: int}>
     * @throws NoSuchEntityException
     */
    private function fetchPool(int $storeId): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['sku', 'price'])
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addWebsiteFilter($this->storeManager->getStore($storeId)->getWebsiteId());

        $pool = [];
        /** @var Product $product */
        foreach ($collection as $product) {
            $price = (float)$product->getPrice();
            if ($price <= 0.0) {
                continue;
            }

            $stockItem = $this->stockRegistry->getStockItem((int)$product->getId());
            if (!$stockItem->getIsInStock()) {
                continue;
            }

            $pool[] = [
                'sku' => (string)$product->getSku(),
                'priceCents' => (int)round($price * 100),
                'reconciliationNumber' => (int)$product->getData('reconciliation_number'),
            ];
        }

        usort(
            $pool,
            static fn(array $a, array $b): int => ($a['reconciliationNumber'] <=> $b['reconciliationNumber'])
                ?: ($b['priceCents'] <=> $a['priceCents'])
        );

        return $pool;
    }

    /**
     * Clears the cached pool for every store so the next getPool() call recomputes it.
     */
    public function invalidate(): void
    {
        $this->cache->clean([self::CACHE_TAG]);
    }
}
