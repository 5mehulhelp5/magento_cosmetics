<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Reconciliation;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const string XML_PATH_ADJUSTMENT_CEILING_KOPECKS = 'uho_courier_order_api/reconciliation/adjustment_ceiling_kopecks';
    private const string XML_PATH_MAX_SEARCH_NODES = 'uho_courier_order_api/reconciliation/max_search_nodes';

    private const int DEFAULT_ADJUSTMENT_CEILING_KOPECKS = 100;
    private const int DEFAULT_MAX_SEARCH_NODES = 5000;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getAdjustmentCeilingKopecks(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_ADJUSTMENT_CEILING_KOPECKS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== null ? (int) $value : self::DEFAULT_ADJUSTMENT_CEILING_KOPECKS;
    }

    public function getMaxSearchNodes(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_MAX_SEARCH_NODES, ScopeInterface::SCOPE_STORE, $storeId);

        return $value !== null ? (int) $value : self::DEFAULT_MAX_SEARCH_NODES;
    }
}
