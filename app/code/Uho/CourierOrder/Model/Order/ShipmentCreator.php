<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Order;

use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Convert\Order as OrderConverter;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

class ShipmentCreator
{
    private const string CARRIER_CODE = 'uho_novaposhta';
    private const string CARRIER_TITLE = 'Нова Пошта';

    public function __construct(
        private readonly OrderConverter $orderConverter,
        private readonly TrackFactory $trackFactory,
        private readonly ShipmentCollectionFactory $shipmentCollectionFactory,
        private readonly Transaction $transaction,
    ) {
    }

    public function create(OrderInterface $order, string $trackingNumber): Shipment
    {
        /** @var Order $order */
        /** @var Shipment $existingShipment */
        $existingShipment = $this->shipmentCollectionFactory->create()
            ->addFieldToFilter('order_id', ['eq' => (int) $order->getEntityId()])
            ->setPageSize(1)
            ->getFirstItem();
        if ($existingShipment->getId()) {
            return $existingShipment;
        }

        $shipment = $this->orderConverter->toShipment($order);

        foreach ($order->getAllItems() as $orderItem) {
            if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }

            $shipmentItem = $this->orderConverter->itemToShipmentItem($orderItem)
                ->setQty($orderItem->getQtyToShip());
            $shipment->addItem($shipmentItem);
        }

        $track = $this->trackFactory->create();
        if (!$track instanceof Track) {
            throw new \LogicException('Expected shipment track model instance.');
        }

        $track->setNumber($trackingNumber);
        $track->setCarrierCode(self::CARRIER_CODE);
        $track->setTitle(self::CARRIER_TITLE);
        $shipment->addTrack($track);

        $shipment->register();
        /** @phpstan-ignore-next-line framework model method */
        $shipment->getOrder()->setIsInProcess(true);

        $this->transaction
            ->addObject($shipment)
            ->addObject($shipment->getOrder())
            ->save();

        return $shipment;
    }
}
