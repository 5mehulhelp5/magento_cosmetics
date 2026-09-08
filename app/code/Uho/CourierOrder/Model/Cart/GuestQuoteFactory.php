<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cart;

use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Store;
use Uho\CourierOrder\Model\Request\Record;

use function sprintf;
class GuestQuoteFactory
{
    public function __construct(
        private readonly QuoteFactory $quoteFactory,
        private readonly StoreRepositoryInterface $storeRepository,
    ) {
    }

    public function create(Record $record): Quote
    {
        $store = $this->storeRepository->getById($record->getStoreId());
        if (!$store instanceof Store) {
            throw new \LogicException('Expected Magento store model instance.');
        }

        /** @var Quote $quote */
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setCurrency();
        $quote->setCustomerEmail(sprintf('courier-order-%d@guest.invalid', (int) $record->getRequestId()));
        $quote->setCheckoutMethod(CartManagementInterface::METHOD_GUEST);
        $quote->setCustomerIsGuest(true);

        return $quote;
    }
}
