<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;
use Uho\CourierOrder\Model\Cart\CartBuilder;
use Uho\CourierOrder\Model\Exception\TransientProcessingException;
use Uho\CourierOrder\Model\Order\InvoiceCreator;
use Uho\CourierOrder\Model\Order\OrderPlacer;
use Uho\CourierOrder\Model\Order\ShipmentCreator;
use Uho\CourierOrder\Model\Request\Lifecycle;
use Uho\CourierOrder\Model\Request\Record;
use Uho\CourierOrder\Model\Request\Repository;

use function sprintf;
class RequestProcessor
{
    public function __construct(
        private readonly Repository $requestRepository,
        private readonly Lifecycle $lifecycle,
        private readonly CartBuilder $cartBuilder,
        private readonly OrderPlacer $orderPlacer,
        private readonly InvoiceCreator $invoiceCreator,
        private readonly ShipmentCreator $shipmentCreator,
        private readonly OrderFactory $orderFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(int $requestId): void
    {
        if (!$this->lifecycle->claim($requestId)) {
            return;
        }

        try {
            $record = $this->requestRepository->getById($requestId);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('Courier order request #%d not found after claim: %s', $requestId, $exception->getMessage())
            );

            return;
        }

        $order = null;

        try {
            $order = $this->resumeOrPlaceOrder($record);
            $this->invoiceCreator->create($order);
            $this->shipmentCreator->create($order, $record->getTrackingNumber());
            $this->lifecycle->markCompleted($requestId, (string) $order->getIncrementId());
        } catch (TransientProcessingException $exception) {
            $this->lifecycle->markRetry($requestId, $exception->getMessage());
        } catch (\Throwable $exception) {
            if ($order !== null) {
                $this->lifecycle->markPartial(
                    $requestId,
                    (string) $order->getIncrementId(),
                    $exception->getMessage()
                );
            } else {
                $this->lifecycle->markFailed($requestId, $exception->getMessage());
            }

            $this->logger->error(
                sprintf('Courier order request #%d processing failed: %s', $requestId, $exception->getMessage()),
                ['exception' => $exception]
            );
        }
    }

    private function resumeOrPlaceOrder(Record $record): OrderInterface
    {
        if ($record->getOrderIncrementId() !== null) {
            $order = $this->orderFactory->create()->loadByIncrementId($record->getOrderIncrementId());
            if (!$order->getId()) {
                throw new TransientProcessingException(
                    sprintf(
                        'Request #%d references order_increment_id %s but no such order exists',
                        (int) $record->getRequestId(),
                        $record->getOrderIncrementId()
                    )
                );
            }

            return $order;
        }

        $quote = $this->cartBuilder->build($record);
        $order = $this->orderPlacer->place($quote);

        $this->lifecycle->markPartial(
            (int) $record->getRequestId(),
            (string) $order->getIncrementId(),
            'Order placed; invoice/shipment pending'
        );

        return $order;
    }
}
