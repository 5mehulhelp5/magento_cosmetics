<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Order;

use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Places the guest order from the built quote. Guest checkout does not require a customer
 * association — CartManagementInterface::placeOrder() works directly against a quote flagged
 * customer_is_guest (set by CartBuilder).
 */
class OrderPlacer
{
    public function __construct(
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function place(Quote $quote): OrderInterface
    {
        $orderId = $this->cartManagement->placeOrder((int) $quote->getId());

        return $this->orderRepository->get($orderId);
    }
}
