<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Total;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

class CourierReconciliationAdjustment extends AbstractTotal
{
    private const string CODE = 'courier_reconciliation_adjustment';

    public function __construct()
    {
        $this->setCode(self::CODE);
    }

    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total,
    ): self {
        parent::collect($quote, $shippingAssignment, $total);

        $adjustment = (float) ($quote->getData(self::CODE) ?? 0);
        if ($adjustment === 0.0) {
            return $this;
        }

        /** @phpstan-ignore-next-line framework total model methods */
        $total->setGrandTotal($total->getGrandTotal() + $adjustment);
        /** @phpstan-ignore-next-line framework total model methods */
        $total->setBaseGrandTotal($total->getBaseGrandTotal() + $adjustment);
        $total->setTotalAmount(self::CODE, $adjustment);
        $total->setBaseTotalAmount(self::CODE, $adjustment);

        return $this;
    }

    public function fetch(Quote $quote, Total $total): array
    {
        $amount = (float) $total->getTotalAmount(self::CODE);
        if ($amount === 0.0) {
            return [];
        }

        return [
            'code' => self::CODE,
            'title' => __('Courier Reconciliation Adjustment'),
            'value' => $amount,
        ];
    }
}
