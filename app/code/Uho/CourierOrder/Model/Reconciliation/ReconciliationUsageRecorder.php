<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Reconciliation;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Throwable;
use Zend_Db_Expr;

use function array_unique;
use function array_values;

class ReconciliationUsageRecorder
{
    private const string TABLE = 'catalog_product_entity';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EligibleProductPool $eligibleProductPool,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function recordUsage(Plan $plan): void
    {
        try {
            $skus = $this->getDistinctSkus($plan);
            if ($skus === []) {
                return;
            }

            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::TABLE);

            $connection->update(
                $table,
                ['reconciliation_number' => new Zend_Db_Expr('reconciliation_number + 1')],
                [$connection->quoteInto('sku IN (?)', $skus)]
            );

            $this->eligibleProductPool->invalidate();
        } catch (Throwable $e) {
            $this->logger->error(
                'Uho_CourierOrder: failed to record reconciliation usage: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * @return string[]
     */
    private function getDistinctSkus(Plan $plan): array
    {
        $skus = [];
        foreach ($plan->getLines() as $line) {
            $skus[] = $line->getSku();
        }

        return array_values(array_unique($skus));
    }
}
