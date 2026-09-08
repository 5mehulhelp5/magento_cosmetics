<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cart;

use Magento\Quote\Model\Quote;
use Uho\CourierOrder\Model\Reconciliation\Planner;
use Uho\CourierOrder\Model\Request\Record;

class CartBuilder
{
    public function __construct(
        private readonly GuestQuoteFactory $guestQuoteFactory,
        private readonly QuoteItemAdder $quoteItemAdder,
        private readonly QuoteFinalizer $quoteFinalizer,
        private readonly Planner $reconciliationPlanner,
    ) {
    }

    public function build(Record $record): Quote
    {
        $quote = $this->guestQuoteFactory->create($record);
        $plan = $this->reconciliationPlanner->plan($record->getTotal(), $record->getStoreId());

        $this->quoteItemAdder->add($quote, $record, $plan);
        $this->quoteFinalizer->finalize($quote, $record, $plan);

        return $quote;
    }
}
