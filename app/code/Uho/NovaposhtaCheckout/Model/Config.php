<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Uho\NovaposhtaCheckout\Model\Config\Source\PostcodeStrategy;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
class Config
{
    private const string XML_PATH_POSTCODE_STRATEGY = 'uho_novaposhta/address/postcode_strategy';
    private const string XML_PATH_WAREHOUSE_CATEGORIES = 'uho_novaposhta/address/warehouse_categories';
    private const string XML_PATH_CITY_SEARCH_LIMIT = 'uho_novaposhta/address/city_search_limit';
    private const string XML_PATH_WAREHOUSE_SEARCH_LIMIT = 'uho_novaposhta/address/warehouse_search_limit';

    private const int FALLBACK_CITY_SEARCH_LIMIT = 20;
    private const int FALLBACK_WAREHOUSE_SEARCH_LIMIT = 50;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getPostcodeStrategy(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_POSTCODE_STRATEGY,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );

        return $value !== '' ? $value : PostcodeStrategy::SENTINEL;
    }

    /**
     * @return string[]
     */
    public function getWarehouseCategories(?int $storeId = null): array
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_WAREHOUSE_CATEGORIES,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function getCitySearchLimit(?int $storeId = null): int
    {
        return $this->positiveIntOrFallback(
            self::XML_PATH_CITY_SEARCH_LIMIT,
            self::FALLBACK_CITY_SEARCH_LIMIT,
            $storeId,
        );
    }

    public function getWarehouseSearchLimit(?int $storeId = null): int
    {
        return $this->positiveIntOrFallback(
            self::XML_PATH_WAREHOUSE_SEARCH_LIMIT,
            self::FALLBACK_WAREHOUSE_SEARCH_LIMIT,
            $storeId,
        );
    }

    private function positiveIntOrFallback(string $path, int $fallback, ?int $storeId): int
    {
        $value = (int) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : $fallback;
    }
}
