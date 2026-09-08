<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Test\Unit\Model\Total;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Uho\CourierOrder\Model\Total\CourierReconciliationAdjustment;

class CourierReconciliationAdjustmentTest extends TestCase
{
    private const string CODE = 'courier_reconciliation_adjustment';

    private CourierReconciliationAdjustment $model;
    private Quote&MockObject $quote;
    private ShippingAssignmentInterface&MockObject $shippingAssignment;
    private Total $total;

    protected function setUp(): void
    {
        $this->model = new CourierReconciliationAdjustment();
        $this->quote = $this->createMock(Quote::class);
        $this->total = new Total([], new Json());

        $shipping = $this->createMock(ShippingInterface::class);
        $shipping->method('getAddress')->willReturn($this->createMock(Address::class));

        $this->shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $this->shippingAssignment->method('getShipping')->willReturn($shipping);
    }

    public function testCollectIsNoOpForZeroAdjustment(): void
    {
        $this->quote->method('getData')->with(self::CODE)->willReturn(0.0);

        $this->model->collect($this->quote, $this->shippingAssignment, $this->total);

        $this->assertSame(0, $this->total->getTotalAmount(self::CODE));
        $this->assertNull($this->total->getGrandTotal());
    }

    public function testCollectAddsAPositiveAdjustmentToTheGrandTotal(): void
    {
        $this->total->setGrandTotal(100.0);
        $this->total->setBaseGrandTotal(100.0);
        $this->quote->method('getData')->with(self::CODE)->willReturn(5.0);

        $this->model->collect($this->quote, $this->shippingAssignment, $this->total);

        $this->assertSame(105.0, $this->total->getGrandTotal());
        $this->assertSame(105.0, $this->total->getBaseGrandTotal());
        $this->assertSame(5.0, $this->total->getTotalAmount(self::CODE));
    }

    public function testCollectSubtractsANegativeAdjustmentFromTheGrandTotal(): void
    {
        $this->total->setGrandTotal(100.0);
        $this->total->setBaseGrandTotal(100.0);
        $this->quote->method('getData')->with(self::CODE)->willReturn(-5.0);

        $this->model->collect($this->quote, $this->shippingAssignment, $this->total);

        $this->assertSame(95.0, $this->total->getGrandTotal());
        $this->assertSame(95.0, $this->total->getBaseGrandTotal());
        $this->assertSame(-5.0, $this->total->getTotalAmount(self::CODE));
    }

    public function testFetchReturnsNothingForZeroAdjustment(): void
    {
        $this->total->setTotalAmount(self::CODE, 0.0);

        $this->assertSame([], $this->model->fetch($this->quote, $this->total));
    }

    public function testFetchReturnsThePositiveAdjustment(): void
    {
        $this->total->setTotalAmount(self::CODE, 5.0);

        $this->assertSame(5.0, $this->model->fetch($this->quote, $this->total)['value']);
    }

    public function testFetchReturnsTheNegativeAdjustment(): void
    {
        $this->total->setTotalAmount(self::CODE, -5.0);

        $this->assertSame(-5.0, $this->model->fetch($this->quote, $this->total)['value']);
    }
}
