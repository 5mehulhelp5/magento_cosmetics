<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Uho\CourierOrder\Observer\CopyReconciliationAdjustmentToOrder;

class CopyReconciliationAdjustmentToOrderTest extends TestCase
{
    private const string CODE = 'courier_reconciliation_adjustment';

    private CopyReconciliationAdjustmentToOrder $observer;
    private Quote&MockObject $quote;
    private Order&MockObject $order;

    /** @var array<string, float> */
    private array $capturedOrderData = [];

    protected function setUp(): void
    {
        $this->observer = new CopyReconciliationAdjustmentToOrder();
        $this->quote = $this->createMock(Quote::class);
        $this->order = $this->createMock(Order::class);
    }

    public function testZeroAdjustmentIsNotCopiedToTheOrder(): void
    {
        $this->quote->method('getData')->with(self::CODE)->willReturn(0.0);
        $this->order->expects($this->never())->method('setData');

        $this->observer->execute($this->buildObserver());
    }

    public function testPositiveAdjustmentIsCopiedToTheOrder(): void
    {
        $this->quote->method('getData')->with(self::CODE)->willReturn(5.0);
        $this->captureSetData();

        $this->observer->execute($this->buildObserver());

        $this->assertSame(
            ['courier_reconciliation_adjustment' => 5.0, 'base_courier_reconciliation_adjustment' => 5.0],
            $this->capturedOrderData
        );
    }

    public function testNegativeAdjustmentIsCopiedToTheOrder(): void
    {
        $this->quote->method('getData')->with(self::CODE)->willReturn(-5.0);
        $this->captureSetData();

        $this->observer->execute($this->buildObserver());

        $this->assertSame(
            ['courier_reconciliation_adjustment' => -5.0, 'base_courier_reconciliation_adjustment' => -5.0],
            $this->capturedOrderData
        );
    }

    private function buildObserver(): EventObserver
    {
        $event = new Event(['quote' => $this->quote, 'order' => $this->order]);

        return new EventObserver(['event' => $event]);
    }

    private function captureSetData(): void
    {
        $this->order->method('setData')->willReturnCallback(function (string $key, $value): void {
            $this->capturedOrderData[$key] = $value;
        });
    }
}
