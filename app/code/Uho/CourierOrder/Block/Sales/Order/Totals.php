<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Block\Sales\Order;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Model\Order;

use function method_exists;
class Totals extends Template
{
    public function getSourceOrder(): Order
    {
        $parentBlock = $this->getParentBlock();
        if ($parentBlock === false || !method_exists($parentBlock, 'getSource')) {
            throw new \LogicException('Order totals parent block is unavailable.');
        }

        $source = $parentBlock->getSource();

        return $source instanceof Order ? $source : $source->getOrder();
    }

    public function initTotals(): self
    {
        $order = $this->getSourceOrder();
        $adjustment = (float) $order->getData('courier_reconciliation_adjustment');
        if ($adjustment === 0.0) {
            return $this;
        }

        $parentBlock = $this->getParentBlock();
        if ($parentBlock === false || !method_exists($parentBlock, 'addTotal')) {
            throw new \LogicException('Order totals parent block is unavailable.');
        }

        $parentBlock->addTotal(
            new DataObject([
                'code' => 'courier_reconciliation_adjustment',
                'label' => __('Courier Reconciliation Adjustment'),
                'value' => $adjustment,
            ]),
            'shipping',
        );

        return $this;
    }
}
