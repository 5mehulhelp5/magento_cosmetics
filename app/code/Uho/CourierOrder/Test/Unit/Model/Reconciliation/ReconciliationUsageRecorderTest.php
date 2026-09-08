<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Test\Unit\Model\Reconciliation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Uho\CourierOrder\Model\Reconciliation\EligibleProductPool;
use Uho\CourierOrder\Model\Reconciliation\Plan;
use Uho\CourierOrder\Model\Reconciliation\PlanLine;
use Uho\CourierOrder\Model\Reconciliation\ReconciliationUsageRecorder;

class ReconciliationUsageRecorderTest extends TestCase
{
    private const string TABLE = 'catalog_product_entity';

    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private EligibleProductPool&MockObject $eligibleProductPool;
    private LoggerInterface&MockObject $logger;
    private ReconciliationUsageRecorder $recorder;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->eligibleProductPool = $this->createMock(EligibleProductPool::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->with(self::TABLE)->willReturn(self::TABLE);

        $this->recorder = new ReconciliationUsageRecorder(
            $this->resourceConnection,
            $this->eligibleProductPool,
            $this->logger,
        );
    }

    public function testRecordUsageUpdatesDistinctSkusByOnePerSkuRegardlessOfQty(): void
    {
        $plan = $this->buildPlan([
            new PlanLine('SKU-A', 5, 1000),
            new PlanLine('SKU-B', 1, 500),
            new PlanLine('SKU-A', 2, 1000),
        ]);

        $this->connection->expects($this->once())
            ->method('quoteInto')
            ->with('sku IN (?)', ['SKU-A', 'SKU-B'])
            ->willReturn("sku IN ('SKU-A','SKU-B')");

        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                $this->callback(static function (array $bind): bool {
                    return isset($bind['reconciliation_number'])
                        && (string) $bind['reconciliation_number'] === 'reconciliation_number + 1';
                }),
                ["sku IN ('SKU-A','SKU-B')"]
            );

        $this->eligibleProductPool->expects($this->once())->method('invalidate');
        $this->logger->expects($this->never())->method('error');

        $this->recorder->recordUsage($plan);
    }

    public function testRecordUsageIsNoOpForAPlanWithNoLines(): void
    {
        $plan = $this->buildPlan([]);

        $this->resourceConnection->expects($this->never())->method('getConnection');
        $this->connection->expects($this->never())->method('update');
        $this->eligibleProductPool->expects($this->never())->method('invalidate');
        $this->logger->expects($this->never())->method('error');

        $this->recorder->recordUsage($plan);
    }

    public function testUpdateFailureIsCaughtLoggedAndSwallowed(): void
    {
        $plan = $this->buildPlan([new PlanLine('SKU-A', 1, 1000)]);

        $this->connection->method('quoteInto')->willReturn("sku IN ('SKU-A')");
        $this->connection->method('update')->willThrowException(new RuntimeException('DB is down'));

        $this->eligibleProductPool->expects($this->never())->method('invalidate');
        $this->logger->expects($this->once())->method('error');

        $this->recorder->recordUsage($plan);
    }

    public function testInvalidateFailureIsCaughtLoggedAndSwallowed(): void
    {
        $plan = $this->buildPlan([new PlanLine('SKU-A', 1, 1000)]);

        $this->connection->method('quoteInto')->willReturn("sku IN ('SKU-A')");
        $this->connection->method('update')->willReturn(1);
        $this->eligibleProductPool->method('invalidate')->willThrowException(new RuntimeException('Cache is down'));

        $this->logger->expects($this->once())->method('error');

        $this->recorder->recordUsage($plan);
    }

    /**
     * @param PlanLine[] $lines
     */
    private function buildPlan(array $lines): Plan
    {
        return new Plan($lines, 0);
    }
}
