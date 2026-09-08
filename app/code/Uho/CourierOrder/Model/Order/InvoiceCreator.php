<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Order;

use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;

class InvoiceCreator
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(OrderInterface $order): Invoice
    {
        /** @var Order $order */
        $existingInvoices = $order->getInvoiceCollection();
        if ($existingInvoices->getSize() > 0) {
            /** @var Invoice $existingInvoice */
            $existingInvoice = $existingInvoices->getFirstItem();

            return $existingInvoice;
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        /** @phpstan-ignore-next-line framework model method */
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        /** @phpstan-ignore-next-line framework model method */
        $invoice->getOrder()->setIsInProcess(true);

        $this->transaction
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();

        return $invoice;
    }
}
