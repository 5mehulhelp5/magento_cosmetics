<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Test\Unit\Model\Reconciliation;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Uho\CourierOrder\Model\Exception\ReconciliationFailedException;
use Uho\CourierOrder\Model\Reconciliation\Config;
use Uho\CourierOrder\Model\Reconciliation\EligibleProductPool;
use Uho\CourierOrder\Model\Reconciliation\Plan;
use Uho\CourierOrder\Model\Reconciliation\PlanFactory;
use Uho\CourierOrder\Model\Reconciliation\PlanLine;
use Uho\CourierOrder\Model\Reconciliation\PlanLineFactory;
use Uho\CourierOrder\Model\Reconciliation\Planner;

class PlannerTest extends TestCase
{
    private const int STORE_ID = 1;

    private EligibleProductPool&MockObject $eligibleProductPool;
    private Config&MockObject $config;
    private Planner $planner;

    protected function setUp(): void
    {
        $this->eligibleProductPool = $this->createMock(EligibleProductPool::class);
        $this->config = $this->createMock(Config::class);

        $planFactory = $this->createMock(PlanFactory::class);
        $planFactory->method('create')
            ->willReturnCallback(static fn(array $data): Plan => new Plan($data['lines'], $data['adjustmentCents']));

        $lineFactory = $this->createMock(PlanLineFactory::class);
        $lineFactory->method('create')
            ->willReturnCallback(
                static fn(array $data): PlanLine => new PlanLine($data['sku'], $data['qty'], $data['unitPriceCents'])
            );

        $this->planner = new Planner($this->eligibleProductPool, $this->config, $planFactory, $lineFactory);
    }

    public function testUndershootWithinCeilingBuildsUnchangedPlan(): void
    {
        $this->givenPool([
            ['sku' => 'SKU-A', 'priceCents' => 300, 'reconciliationNumber' => 1],
            ['sku' => 'SKU-B', 'priceCents' => 200, 'reconciliationNumber' => 2],
        ]);
        $this->givenConfig(ceiling: 5, maxSearchNodes: 1);

        $plan = $this->planner->plan('5.03', self::STORE_ID);

        $this->assertSame(3, $plan->getAdjustmentCents());
        $this->assertLines(['SKU-A' => [1, 300], 'SKU-B' => [1, 200]], $plan);
    }

    public function testGapCoveredByASingleProductAddsBridgingLineWithNegativeAdjustment(): void
    {
        $this->givenPool([
            ['sku' => 'SKU-A', 'priceCents' => 1000, 'reconciliationNumber' => 1],
            ['sku' => 'SKU-C', 'priceCents' => 60, 'reconciliationNumber' => 2],
        ]);
        $this->givenConfig(ceiling: 5, maxSearchNodes: 1);

        $plan = $this->planner->plan('10.50', self::STORE_ID);

        // base combo: SKU-A x1, remaining 50; bridged by SKU-C (60 >= 50), overshoot 10 -> -10 discount
        $this->assertSame(-10, $plan->getAdjustmentCents());
        $this->assertLines(['SKU-A' => [1, 1000], 'SKU-C' => [1, 60]], $plan);
    }

    public function testZeroLinesChosenBridgesEntireTargetWithCheapestProduct(): void
    {
        $this->givenPool([
            ['sku' => 'SKU-PRICEY', 'priceCents' => 100000, 'reconciliationNumber' => 1],
        ]);
        $this->givenConfig(ceiling: 5, maxSearchNodes: 1);

        // every product is pricier than the target -> greedy/boundedSearch select nothing
        $plan = $this->planner->plan('5.00', self::STORE_ID);

        $this->assertSame(-99500, $plan->getAdjustmentCents());
        $this->assertLines(['SKU-PRICEY' => [1, 100000]], $plan);
    }

    public function testBridgingTieBreaksOnLowestReconciliationNumberRegardlessOfPoolOrder(): void
    {
        $this->givenPool([
            ['sku' => 'SKU-HIGH-REC', 'priceCents' => 500, 'reconciliationNumber' => 9],
            ['sku' => 'SKU-LOW-REC', 'priceCents' => 500, 'reconciliationNumber' => 3],
        ]);
        $this->givenConfig(ceiling: 5, maxSearchNodes: 1);

        // base combo: SKU-HIGH-REC x1 (first in pool), remaining 50; both products tie at price
        // 500 as bridging candidates -> the lower reconciliation number wins the tie
        $plan = $this->planner->plan('5.50', self::STORE_ID);

        $this->assertSame(-450, $plan->getAdjustmentCents());
        $this->assertLines(['SKU-HIGH-REC' => [1, 500], 'SKU-LOW-REC' => [1, 500]], $plan);
    }

    public function testBridgingSkuAlreadyInBaseComboIncrementsExistingLineInsteadOfDuplicating(): void
    {
        $this->givenPool([
            ['sku' => 'SKU-A', 'priceCents' => 1000, 'reconciliationNumber' => 1],
        ]);
        $this->givenConfig(ceiling: 5, maxSearchNodes: 1);

        // base combo: SKU-A x3, remaining 50; only bridging candidate is SKU-A itself
        $plan = $this->planner->plan('30.50', self::STORE_ID);

        $this->assertSame(-950, $plan->getAdjustmentCents());
        $this->assertLines(['SKU-A' => [4, 1000]], $plan);
    }

    public function testEmptyPoolStillThrowsReconciliationFailedException(): void
    {
        $this->givenPool([]);
        $this->givenConfig(ceiling: 5, maxSearchNodes: 1);

        $this->expectException(ReconciliationFailedException::class);

        $this->planner->plan('10.00', self::STORE_ID);
    }

    /**
     * bridgeGap()'s "no single product covers the gap" fallback can only be exercised directly:
     * greedyPass/boundedSearch always leave a remainder smaller than every pool product's price
     * (the leftover of a floor-division selection is always < the divisor), so plan() itself
     * never drives bridgeGap() into this branch.
     */
    public function testGapLargerThanAnySingleProductRepeatsTheCheapestProduct(): void
    {
        $pool = [
            ['sku' => 'SKU-CHEAP', 'priceCents' => 80, 'reconciliationNumber' => 2],
            ['sku' => 'SKU-EXPENSIVE', 'priceCents' => 500, 'reconciliationNumber' => 1],
        ];

        $method = new ReflectionMethod(Planner::class, 'bridgeGap');
        $method->setAccessible(true);
        [$lines, $remaining] = $method->invoke($this->planner, $pool, [], 1000);

        $this->assertSame(-40, $remaining);
        $this->assertSame(
            ['sku' => 'SKU-CHEAP', 'qty' => 13, 'unitPriceCents' => 80],
            $lines['SKU-CHEAP']
        );
    }

    /**
     * @param array<int, array{sku: string, priceCents: int, reconciliationNumber: int}> $pool
     */
    private function givenPool(array $pool): void
    {
        $this->eligibleProductPool->method('getPool')->with(self::STORE_ID)->willReturn($pool);
    }

    private function givenConfig(int $ceiling, int $maxSearchNodes): void
    {
        $this->config->method('getAdjustmentCeilingKopecks')->with(self::STORE_ID)->willReturn($ceiling);
        $this->config->method('getMaxSearchNodes')->with(self::STORE_ID)->willReturn($maxSearchNodes);
    }

    /**
     * @param array<string, array{0: int, 1: int}> $expected sku => [qty, unitPriceCents]
     */
    private function assertLines(array $expected, Plan $plan): void
    {
        $actual = [];
        foreach ($plan->getLines() as $line) {
            $actual[$line->getSku()] = [$line->getQty(), $line->getUnitPriceCents()];
        }

        $this->assertSame($expected, $actual);
    }
}
