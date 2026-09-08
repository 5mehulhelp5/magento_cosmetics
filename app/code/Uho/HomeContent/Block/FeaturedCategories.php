<?php

declare(strict_types=1);

namespace Uho\HomeContent\Block;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

use function strip_tags;
use function trim;
/**
 * Renders the homepage "home-categories" cards from the store's real level-2
 * category tree instead of hand-written CMS block content, so the cards stay
 * in sync with the catalog (see cms_index_index.xml in the seed-store theme).
 */
class FeaturedCategories extends Template
{
    public function __construct(
        Context $context,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return Category[]
     */
    public function getCategories(): array
    {
        $rootCategoryId = (int) $this->storeManager->getStore()->getRootCategoryId();
        $rootCategory = $this->categoryRepository->get($rootCategoryId);

        /** @var CategoryCollection $collection */
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'description', 'url_key'])
            ->addAttributeToFilter('parent_id', $rootCategory->getId())
            ->addIsActiveFilter()
            ->addUrlRewriteToResult()
            ->setOrder('position', CategoryCollection::SORT_ORDER_ASC);

        return $collection->getItems();
    }

    public function getCategoryUrl(Category $category): string
    {
        return $category->getUrl();
    }

    public function getCategoryDescription(Category $category): string
    {
        return trim(strip_tags((string) $category->getData('description')));
    }
}
