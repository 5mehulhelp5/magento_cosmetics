<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Test\Unit\Block\Sales\Order;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Uho\CourierOrder\Block\Sales\Order\Totals;

class TotalsTest extends TestCase
{
    private Order&MockObject $order;

    protected function setUp(): void
    {
        $this->order = $this->createMock(Order::class);
    }

    public function testInitTotalsIsNoOpForZeroAdjustment(): void
    {
        $this->order->method('getData')->with('courier_reconciliation_adjustment')->willReturn(0.0);
        $parentBlock = $this->createMock(FakeOrderTotalsParentBlock::class);
        $parentBlock->expects($this->never())->method('addTotal');

        $this->buildBlock($parentBlock)->initTotals();
    }

    public function testInitTotalsRendersAPositiveAdjustment(): void
    {
        $this->order->method('getData')->with('courier_reconciliation_adjustment')->willReturn(5.0);
        $parentBlock = $this->createMock(FakeOrderTotalsParentBlock::class);
        $parentBlock->expects($this->once())
            ->method('addTotal')
            ->with($this->callback(static fn(DataObject $total): bool => $total->getValue() === 5.0), 'shipping');

        $this->buildBlock($parentBlock)->initTotals();
    }

    public function testInitTotalsRendersANegativeAdjustment(): void
    {
        $this->order->method('getData')->with('courier_reconciliation_adjustment')->willReturn(-5.0);
        $parentBlock = $this->createMock(FakeOrderTotalsParentBlock::class);
        $parentBlock->expects($this->once())
            ->method('addTotal')
            ->with($this->callback(static fn(DataObject $total): bool => $total->getValue() === -5.0), 'shipping');

        $this->buildBlock($parentBlock)->initTotals();
    }

    private function buildBlock(FakeOrderTotalsParentBlock&MockObject $parentBlock): Totals
    {
        $parentBlock->method('getSource')->willReturn($this->order);

        $block = $this->getMockBuilder(Totals::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentBlock'])
            ->getMock();
        $block->method('getParentBlock')->willReturn($parentBlock);

        return $block;
    }
}

/**
 * Test double combining the "getSource" (order totals block) and "addTotal" (layout totals
 * block) methods Totals::initTotals()/getSourceOrder() duck-type against via method_exists().
 */
interface FakeOrderTotalsParentBlock
{
    public function getSource(): Order;

    public function addTotal(DataObject $total, ?string $area = null): void;
}
