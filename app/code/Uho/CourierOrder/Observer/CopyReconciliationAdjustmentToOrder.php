<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Quote custom totals are not automatically copied onto the order entity during
 * quote-to-order conversion — this observer copies the collected
 * courier_reconciliation_adjustment amount so it persists on sales_order and can be rendered
 * on the order-view "Order Total" table and PDF invoice via the standard sales_totals layout.
 */
class CopyReconciliationAdjustmentToOrder implements ObserverInterface
{
    public function execute(EventObserver $observer): void
    {
        /** @var Quote $quote */
        $quote = $observer->getEvent()->getData('quote');
        /** @var Order $order */
        $order = $observer->getEvent()->getData('order');

        $adjustment = (float) ($quote->getData('courier_reconciliation_adjustment') ?? 0);
        if ($adjustment === 0.0) {
            return;
        }

        $order->setData('courier_reconciliation_adjustment', $adjustment);
        $order->setData('base_courier_reconciliation_adjustment', $adjustment);
    }
}
