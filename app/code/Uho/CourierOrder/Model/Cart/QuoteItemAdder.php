<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cart;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Uho\CourierOrder\Model\Exception\TransientProcessingException;
use Uho\CourierOrder\Model\Reconciliation\Plan;
use Uho\CourierOrder\Model\Request\Record;

use function sprintf;
class QuoteItemAdder
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    public function add(Quote $quote, Record $record, Plan $plan): void
    {
        try {
            foreach ($plan->getLines() as $line) {
                $product = $this->productRepository->get($line->getSku(), false, $record->getStoreId());
                if (!$product instanceof Product) {
                    throw new \RuntimeException(sprintf('SKU "%s" did not resolve to a catalog product model.', $line->getSku()));
                }

                $result = $quote->addProduct($product, $line->getQty());
                if (!$result instanceof QuoteItem) {
                    throw new \RuntimeException(
                        sprintf('Could not add SKU "%s" to guest quote: %s', $line->getSku(), (string) $result)
                    );
                }
            }
        } catch (\Throwable $exception) {
            throw new TransientProcessingException(
                sprintf(
                    'Failed to add reconciliation plan lines to guest quote for request #%d: %s',
                    (int) $record->getRequestId(),
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }
    }
}
