<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Setup\Patch\Data;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Creates the two generic placeholder simple products used by the Phase 5 order-intake cron
 * (Uho\OrderIntake\Model\OrderBuilder, not yet implemented) as line items when
 * GetOrderProductInterface has no real catalog mapping for an order's total (spec §2, §6). Never
 * intended to be purchasable through the storefront — disabled from catalog search/visibility.
 *
 * Assigned to every website so the products resolve regardless of which store the payload
 * targets; not part of the plan's explicit file list but needed for the products to be loadable
 * by SKU in a store-scoped context during order building.
 */
class CreatePlaceholderProducts implements DataPatchInterface
{
    private const array PLACEHOLDER_PRODUCTS = [
        'order-misc-1' => 'Order Placeholder 1',
        'order-misc-2' => 'Order Placeholder 2',
    ];

    public function __construct(
        private readonly ProductFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState,
    ) {
    }

    /**
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws StateException
     */
    public function apply(): self
    {
        $this->emulateAdminArea();

        foreach (self::PLACEHOLDER_PRODUCTS as $sku => $name) {
            if ($this->productExists($sku)) {
                continue;
            }

            $this->createProduct($sku, $name);
        }

        return $this;
    }

    /**
     * Product save triggers URL rewrite generation, which requires an area code to be set — CLI
     * setup:upgrade does not set one by default. Wrapped because a prior patch in the same request
     * may already have set it, which throws instead of being a no-op.
     */
    private function emulateAdminArea(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // Area code already set by an earlier patch in this request.
        }
    }

    private function productExists(string $sku): bool
    {
        try {
            $this->productRepository->get($sku);

            return true;
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    /**
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws StateException
     */
    private function createProduct(string $sku, string $name): void
    {
        /** @var Product $product */
        $product = $this->productFactory->create();
        $product->setTypeId(Type::TYPE_SIMPLE);
        $product->setAttributeSetId($this->getDefaultAttributeSetId());
        $product->setSku($sku);
        $product->setName($name);
        $product->setPrice(0.00);
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE);
        $product->setWebsiteIds($this->getAllWebsiteIds());
        $product->setStockData([
            'use_config_manage_stock' => 0,
            'manage_stock' => 0,
            'is_in_stock' => 1,
            'qty' => 0,
        ]);

        $this->productRepository->save($product);
    }

    private function getDefaultAttributeSetId(): int
    {
        return (int) $this->eavConfig->getEntityType(Product::ENTITY)->getDefaultAttributeSetId();
    }

    /**
     * @return int[]
     */
    private function getAllWebsiteIds(): array
    {
        return array_map(
            static fn (WebsiteInterface $website): int => (int) $website->getId(),
            $this->storeManager->getWebsites()
        );
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
