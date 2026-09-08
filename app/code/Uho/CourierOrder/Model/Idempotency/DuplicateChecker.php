<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Idempotency;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory as ShipmentTrackCollectionFactory;
use Uho\CourierOrder\Api\Data\CourierOrderAcceptResultInterface;
use Uho\CourierOrder\Api\Data\CourierOrderAcceptResultInterfaceFactory;
use Uho\CourierOrder\Model\Request\Record;
use Uho\CourierOrder\Model\Request\Repository;

class DuplicateChecker
{
    public function __construct(
        private readonly ShipmentTrackCollectionFactory $shipmentTrackCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Repository $requestRepository,
        private readonly CourierOrderAcceptResultInterfaceFactory $acceptResultFactory,
    ) {
    }

    public function findDuplicate(string $trackingNumber): ?CourierOrderAcceptResultInterface
    {
        $orderIncrementId = $this->findShippedOrderIncrementId($trackingNumber);
        if ($orderIncrementId !== null) {
            return $this->acceptResultFactory->create([
                'status' => CourierOrderAcceptResultInterface::STATUS_DUPLICATE_COMPLETED,
                'requestReference' => $trackingNumber,
                'orderIncrementId' => $orderIncrementId,
            ]);
        }

        try {
            $record = $this->requestRepository->getByTrackingNumber($trackingNumber);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $this->mapRecordToResult($record);
    }

    private function findShippedOrderIncrementId(string $trackingNumber): ?string
    {
        /** @var Track $track */
        $track = $this->shipmentTrackCollectionFactory->create()
            ->addFieldToFilter('track_number', $trackingNumber)
            ->setPageSize(1)
            ->getFirstItem();
        if (!$track->getId()) {
            return null;
        }

        $orderId = (int) $track->getOrderId();
        if ($orderId <= 0) {
            return null;
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            return null;
        }

        $incrementId = $order->getIncrementId();

        return $incrementId !== null && $incrementId !== '' ? (string) $incrementId : null;
    }

    private function mapRecordToResult(Record $record): CourierOrderAcceptResultInterface
    {
        $status = match ($record->getStatus()) {
            Record::STATUS_PENDING,
            Record::STATUS_RETRY => CourierOrderAcceptResultInterface::STATUS_DUPLICATE_PENDING,
            Record::STATUS_PROCESSING => CourierOrderAcceptResultInterface::STATUS_DUPLICATE_PROCESSING,
            Record::STATUS_COMPLETED => CourierOrderAcceptResultInterface::STATUS_DUPLICATE_COMPLETED,
            default => CourierOrderAcceptResultInterface::STATUS_DUPLICATE_FAILED,
        };

        return $this->acceptResultFactory->create([
            'status' => $status,
            'requestReference' => (string) $record->getRequestId(),
            'orderIncrementId' => $record->getOrderIncrementId(),
        ]);
    }
}
