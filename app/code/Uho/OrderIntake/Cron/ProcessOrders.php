<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Cron;

use Psr\Log\LoggerInterface;
use Throwable;
use Uho\OrderIntake\Api\Data\OrderIntakeInterface;
use Uho\OrderIntake\Model\OrderBuilder;
use Uho\OrderIntake\Model\OrderIntake;
use Uho\OrderIntake\Model\ResourceModel\OrderIntake as OrderIntakeResource;
use Uho\OrderIntake\Model\ResourceModel\OrderIntake\CollectionFactory as OrderIntakeCollectionFactory;

/**
 * Processes pending Uho_OrderIntake rows into real Magento orders (spec §6). Runs every 5 minutes
 * (see etc/crontab.xml), up to BATCH_SIZE rows per run. Per-row failures are caught and recorded on
 * the row so one bad row never aborts the rest of the batch.
 */
class ProcessOrders
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private readonly OrderIntakeCollectionFactory $collectionFactory,
        private readonly OrderIntakeResource $orderIntakeResource,
        private readonly OrderBuilder $orderBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(OrderIntakeInterface::STATUS, OrderIntakeInterface::STATUS_PENDING);
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage(1);

        /** @var OrderIntake $orderIntake */
        foreach ($collection as $orderIntake) {
            $this->processRow($orderIntake);
        }
    }

    private function processRow(OrderIntake $orderIntake): void
    {
        try {
            $orderId = $this->orderBuilder->build($orderIntake);
            $orderIntake->setOrderId($orderId);
            $orderIntake->setStatus(OrderIntakeInterface::STATUS_COMPLETE);
            $orderIntake->setErrorMessage(null);
        } catch (Throwable $exception) {
            $orderIntake->setStatus(OrderIntakeInterface::STATUS_ERROR);
            $orderIntake->setErrorMessage($exception->getMessage());
            $this->logger->error(
                sprintf(
                    'Uho_OrderIntake: failed to process row #%d: %s',
                    (int) $orderIntake->getEntityId(),
                    $exception->getMessage(),
                ),
                ['exception' => $exception],
            );
        }

        $this->orderIntakeResource->save($orderIntake);
    }
}
