<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Test\Unit\Model\Reconciliation;

use ArrayIterator;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Uho\CourierOrder\Model\Reconciliation\EligibleProductPool;

use function array_column;

class EligibleProductPoolTest extends TestCase
{
    private const int STORE_ID = 1;
    private const int WEBSITE_ID = 1;

    private StoreManagerInterface&MockObject $storeManager;
    private ProductCollectionFactory&MockObject $productCollectionFactory;
    private StockRegistryInterface&MockObject $stockRegistry;
    private CacheInterface&MockObject $cache;
    private Json $json;
    private EligibleProductPool $pool;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->stockRegistry = $this->createMock(StockRegistryInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->json = new Json();

        $this->pool = new EligibleProductPool(
            $this->storeManager,
            $this->productCollectionFactory,
            $this->stockRegistry,
            $this->cache,
            $this->json,
        );

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $this->storeManager->method('getStore')->with(self::STORE_ID)->willReturn($store);

        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturn(true);

        $this->stockRegistry->method('getStockItem')->willReturnCallback(
            function (int $productId): StockItemInterface {
                $stockItem = $this->createMock(StockItemInterface::class);
                $stockItem->method('getIsInStock')->willReturn(true);

                return $stockItem;
            }
        );
    }

    public function testPoolSortsByRotationCountAscendingThenPriceDescendingOnTie(): void
    {
        $this->givenCollectionProducts([
            ['id' => 1, 'sku' => 'SKU-EXPENSIVE-LOW-ROTATION', 'price' => 100.0, 'reconciliation_number' => 2],
            ['id' => 2, 'sku' => 'SKU-CHEAP-LOW-ROTATION', 'price' => 10.0, 'reconciliation_number' => 0],
            ['id' => 3, 'sku' => 'SKU-EXPENSIVE-HIGH-ROTATION', 'price' => 200.0, 'reconciliation_number' => 5],
            ['id' => 4, 'sku' => 'SKU-CHEAP-SAME-ROTATION', 'price' => 20.0, 'reconciliation_number' => 2],
        ]);

        $result = $this->pool->getPool(self::STORE_ID);

        $this->assertSame(
            [
                'SKU-CHEAP-LOW-ROTATION',
                'SKU-EXPENSIVE-LOW-ROTATION',
                'SKU-CHEAP-SAME-ROTATION',
                'SKU-EXPENSIVE-HIGH-ROTATION',
            ],
            array_column($result, 'sku')
        );
    }

    public function testEqualRotationCountsAreTieBrokenByPriceDescending(): void
    {
        $this->givenCollectionProducts([
            ['id' => 1, 'sku' => 'SKU-A', 'price' => 50.0, 'reconciliation_number' => 3],
            ['id' => 2, 'sku' => 'SKU-B', 'price' => 150.0, 'reconciliation_number' => 3],
            ['id' => 3, 'sku' => 'SKU-C', 'price' => 100.0, 'reconciliation_number' => 3],
        ]);

        $result = $this->pool->getPool(self::STORE_ID);

        $this->assertSame(['SKU-B', 'SKU-C', 'SKU-A'], array_column($result, 'sku'));
    }

    /**
     * @param array<int, array{id: int, sku: string, price: float, reconciliation_number: int}> $products
     */
    private function givenCollectionProducts(array $products): void
    {
        $items = [];
        foreach ($products as $data) {
            $product = $this->createMock(Product::class);
            $product->method('getId')->willReturn($data['id']);
            $product->method('getSku')->willReturn($data['sku']);
            $product->method('getPrice')->willReturn($data['price']);
            $product->method('getData')->with('reconciliation_number')->willReturn($data['reconciliation_number']);
            $items[] = $product;
        }

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addWebsiteFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new ArrayIterator($items));

        $this->productCollectionFactory->method('create')->willReturn($collection);
    }
}
