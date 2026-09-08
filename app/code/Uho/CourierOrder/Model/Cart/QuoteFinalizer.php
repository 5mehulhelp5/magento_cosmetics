<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cart;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Model\Quote;
use Uho\CourierOrder\Model\Reconciliation\Plan;
use Uho\CourierOrder\Model\Request\Record;

class QuoteFinalizer
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly GuestAddressAssembler $addressAssembler,
        private readonly ShippingAssigner $shippingAssigner,
        private readonly PaymentAssigner $paymentAssigner,
    ) {
    }

    public function finalize(Quote $quote, Record $record, Plan $plan): void
    {
        $addressData = $this->addressAssembler->assemble($record);
        $billingAddress = $this->addressFactory->create(['data' => $addressData]);
        $shippingAddress = $this->addressFactory->create(['data' => $addressData]);

        $quote->setBillingAddress($billingAddress);
        $quote->setShippingAddress($shippingAddress);
        $quote->getShippingAddress()->setCollectShippingRates(true);
        $quote->getShippingAddress()->collectShippingRates();

        $this->shippingAssigner->assign($quote);
        $this->paymentAssigner->assign($quote);
        $quote->setData('courier_reconciliation_adjustment', $plan->getAdjustmentCents() / 100);
        $quote->collectTotals();
        $this->cartRepository->save($quote);
    }
}
