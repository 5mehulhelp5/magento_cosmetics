<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Uho\NovaposhtaShipping\Model\Carrier\NovaposhtaManual;

use function explode;
/**
 * Fail-closed guard: an order must never be placed with the Nova Poshta carrier selected but no
 * composed warehouse (docs §6 B3). The two `before` plugins in Plugin\Quote already compose the
 * address as soon as both refs are present, but this observer is the last line of defence against
 * any path that reaches order placement without going through them (e.g. a quote address saved
 * directly, or a future integration that bypasses the webapi service contracts).
 */
class SalesModelServiceQuoteSubmitBefore implements ObserverInterface
{
    /**
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        /** @var Quote $quote */
        $quote = $observer->getEvent()->getQuote();

        if ($quote->isVirtual()) {
            return;
        }

        $shippingAddress = $quote->getShippingAddress();
        $shippingMethod = (string) $shippingAddress->getShippingMethod();
        if ($shippingMethod === '') {
            return;
        }

        [$carrierCode] = explode('_', $shippingMethod, 2);
        if ($carrierCode !== NovaposhtaManual::CARRIER_CODE) {
            return;
        }

        if ((string) $shippingAddress->getData('uho_np_warehouse_ref') === '') {
            throw new LocalizedException(
                __('Please select a Nova Poshta warehouse before placing the order.')
            );
        }
    }
}
