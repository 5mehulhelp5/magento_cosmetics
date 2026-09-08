<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cart;

use Magento\Quote\Model\Quote;

class ShippingAssigner
{
    private const string CARRIER_CODE = 'uho_novaposhta';
    private const string METHOD_CODE = 'pickup';

    public function assign(Quote $quote): void
    {
        $quote->getShippingAddress()->setShippingMethod(self::CARRIER_CODE . '_' . self::METHOD_CODE);
    }
}
