<?php

declare(strict_types=1);

namespace Uho\Store\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\ResourceModel\Group as GroupResource;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\Group;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use Magento\Store\Model\WebsiteFactory;

class CreateOlivetsStore implements DataPatchInterface
{
    private const string WEBSITE_CODE = 'ol';
    private const string WEBSITE_NAME = 'олівець';
    private const int WEBSITE_SORT_ORDER = 20;
    private const string GROUP_CODE = 'ol';
    private const string GROUP_NAME = 'олівець';
    private const string ROOT_CATEGORY_NAME = 'олівець';
    private const string STORE_CODE = 'ol_ua';
    private const string STORE_NAME = 'олівець';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryResource $categoryResource,
        private readonly WebsiteFactory $websiteFactory,
        private readonly GroupFactory $groupFactory,
        private readonly StoreFactory $storeFactory,
        private readonly WebsiteResource $websiteResource,
        private readonly GroupResource $groupResource,
        private readonly StoreResource $storeResource,
        private readonly ConfigResource $configResource,
        private readonly ReinitableConfigInterface $configReinit,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @throws AlreadyExistsException
     * @throws CouldNotSaveException
     */
    public function apply(): self
    {
        if ($this->websiteExists()) {
            return $this;
        }

        $this->moduleDataSetup->startSetup();

        $rootCategory = $this->createRootCategory();
        $website = $this->createWebsite();
        $group = $this->createStoreGroup($website, (int) $rootCategory->getId());
        $store = $this->createStoreView($website, $group);
        $this->saveStoreConfig((int) $store->getId(), (int) $website->getId());

        $this->moduleDataSetup->endSetup();
        $this->configReinit->reinit();

        return $this;
    }

    private function websiteExists(): bool
    {
        return isset($this->storeManager->getWebsites(false, true)[self::WEBSITE_CODE]);
    }

    /**
     * @throws CouldNotSaveException
     */
    private function createRootCategory(): Category
    {
        /** @var Category $parentCategory */
        $parentCategory = $this->categoryFactory->create();
        $parentCategory->load(Category::TREE_ROOT_ID);

        $category = $this->categoryFactory->create();
        $category->setName(self::ROOT_CATEGORY_NAME);
        $category->setParentId(Category::TREE_ROOT_ID);
        $category->setPath($parentCategory->getPath());
        $category->setIsActive(true);
        $category->setIncludeInMenu(true);
        $category->setDisplayMode('PRODUCTS');
        $category->setStoreId(0);

        $this->categoryResource->save($category);

        return $category;
    }

    /**
     * @throws AlreadyExistsException
     */
    private function createWebsite(): Website
    {
        $website = $this->websiteFactory->create();
        $website->setCode(self::WEBSITE_CODE);
        $website->setName(self::WEBSITE_NAME);
        $website->setSortOrder(self::WEBSITE_SORT_ORDER);
        $this->websiteResource->save($website);

        return $website;
    }

    /**
     * @throws AlreadyExistsException
     */
    private function createStoreGroup(Website $website, int $rootCategoryId): Group
    {
        $group = $this->groupFactory->create();
        $group->setCode(self::GROUP_CODE);
        $group->setWebsiteId((int) $website->getId());
        $group->setName(self::GROUP_NAME);
        $group->setRootCategoryId($rootCategoryId);
        $this->groupResource->save($group);

        $website->setDefaultGroupId((int) $group->getId());
        $this->websiteResource->save($website);

        return $group;
    }

    /**
     * @throws AlreadyExistsException
     */
    private function createStoreView(
        Website $website,
        Group $group,
    ): Store {
        $store = $this->storeFactory->create();
        $store->setCode(self::STORE_CODE);
        $store->setName(self::STORE_NAME);
        $store->setWebsiteId((int) $website->getId());
        $store->setGroupId((int) $group->getId());
        $store->setIsActive(1);
        $this->storeResource->save($store);

        $group->setDefaultStoreId((int) $store->getId());
        $this->groupResource->save($group);

        return $store;
    }

    private function saveStoreConfig(int $storeId, int $websiteId): void
    {
        $this->configResource->saveConfig('general/locale/code', 'uk_UA', 'stores', $storeId);
        $this->configResource->saveConfig('currency/options/base', 'UAH', 'stores', $storeId);
        $this->configResource->saveConfig('currency/options/default', 'UAH', 'stores', $storeId);
        $this->configResource->saveConfig('currency/options/allow', 'UAH', 'stores', $storeId);
        $this->configResource->saveConfig('catalog/price/scope', '1', 'websites', $websiteId);
    }

    public static function getDependencies(): array
    {
        return [CreateProrostokStore::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
