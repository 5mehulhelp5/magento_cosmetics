<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cart;

use Magento\Quote\Model\Quote;

class PaymentAssigner
{
    private const string METHOD_CODE = 'cashondelivery';

    public function assign(Quote $quote): void
    {
        $quote->getPayment()->setMethod(self::METHOD_CODE);
    }
}
