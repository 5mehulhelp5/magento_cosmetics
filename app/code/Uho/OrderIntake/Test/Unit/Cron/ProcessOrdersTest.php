<?php

declare(strict_types=1);

namespace Uho\OrderIntake\Test\Unit\Cron;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Uho\OrderIntake\Api\Data\OrderIntakeInterface;
use Uho\OrderIntake\Cron\ProcessOrders;
use Uho\OrderIntake\Model\OrderBuilder;
use Uho\OrderIntake\Model\OrderIntake;
use Uho\OrderIntake\Model\ResourceModel\OrderIntake as OrderIntakeResource;
use Uho\OrderIntake\Model\ResourceModel\OrderIntake\Collection;
use Uho\OrderIntake\Model\ResourceModel\OrderIntake\CollectionFactory;

class ProcessOrdersTest extends TestCase
{
    private const int BATCH_SIZE = 50;

    private CollectionFactory&MockObject $collectionFactory;
    private OrderIntakeResource&MockObject $orderIntakeResource;
    private OrderBuilder&MockObject $orderBuilder;
    private LoggerInterface&MockObject $logger;
    private Collection&MockObject $collection;
    private ProcessOrders $cron;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->orderIntakeResource = $this->createMock(OrderIntakeResource::class);
        $this->orderBuilder = $this->createMock(OrderBuilder::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->collection = $this->createMock(Collection::class);

        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->cron = new ProcessOrders(
            $this->collectionFactory,
            $this->orderIntakeResource,
            $this->orderBuilder,
            $this->logger,
        );
    }

    public function testExecuteFiltersToPendingRowsAndLimitsTheBatchSize(): void
    {
        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with(OrderIntakeInterface::STATUS, OrderIntakeInterface::STATUS_PENDING)
            ->willReturnSelf();
        $this->collection->expects($this->once())->method('setPageSize')->with(self::BATCH_SIZE)->willReturnSelf();
        $this->collection->expects($this->once())->method('setCurPage')->with(1)->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->cron->execute();
    }

    public function testExecuteMarksARowCompleteAndSavesItsOrderId(): void
    {
        $row = $this->givenRow(1);
        $this->givenCollectionYields([$row]);

        $this->orderBuilder->method('build')->with($row)->willReturn(999);

        $row->expects($this->once())->method('setOrderId')->with(999);
        $row->expects($this->once())->method('setStatus')->with(OrderIntakeInterface::STATUS_COMPLETE);
        $row->expects($this->once())->method('setErrorMessage')->with(null);
        $this->orderIntakeResource->expects($this->once())->method('save')->with($row);
        $this->logger->expects($this->never())->method('error');

        $this->cron->execute();
    }

    public function testExecuteMarksAFailingRowAsErrorWithTheExceptionMessage(): void
    {
        $row = $this->givenRow(2);
        $this->givenCollectionYields([$row]);

        $this->orderBuilder->method('build')
            ->with($row)
            ->willThrowException(new LocalizedException(__('Store "bogus" does not exist or is inactive.')));

        $row->expects($this->never())->method('setOrderId');
        $row->expects($this->once())->method('setStatus')->with(OrderIntakeInterface::STATUS_ERROR);
        $row->expects($this->once())->method('setErrorMessage')
            ->with('Store "bogus" does not exist or is inactive.');
        $this->orderIntakeResource->expects($this->once())->method('save')->with($row);
        $this->logger->expects($this->once())->method('error');

        $this->cron->execute();
    }

    public function testExecuteContinuesTheBatchPastAFailingRow(): void
    {
        $rowOne = $this->givenRow(10);
        $rowTwo = $this->givenRow(11);
        $rowThree = $this->givenRow(12);
        $this->givenCollectionYields([$rowOne, $rowTwo, $rowThree]);

        $this->orderBuilder->method('build')->willReturnCallback(
            static function (OrderIntake $orderIntake) use ($rowOne, $rowTwo, $rowThree): int {
                return match ($orderIntake) {
                    $rowOne => 101,
                    $rowTwo => throw new LocalizedException(__('boom')),
                    $rowThree => 103,
                    default => throw new \LogicException('Unexpected row passed to OrderBuilder::build().'),
                };
            }
        );

        $rowOne->expects($this->once())->method('setStatus')->with(OrderIntakeInterface::STATUS_COMPLETE);
        $rowTwo->expects($this->once())->method('setStatus')->with(OrderIntakeInterface::STATUS_ERROR);
        $rowThree->expects($this->once())->method('setStatus')->with(OrderIntakeInterface::STATUS_COMPLETE);

        $this->orderIntakeResource->expects($this->exactly(3))->method('save');

        $this->cron->execute();
    }

    private function givenRow(int $entityId): OrderIntake&MockObject
    {
        $row = $this->createMock(OrderIntake::class);
        $row->method('getEntityId')->willReturn($entityId);

        return $row;
    }

    /**
     * @param array<int, OrderIntake&MockObject> $rows
     */
    private function givenCollectionYields(array $rows): void
    {
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();
        $this->collection->method('setCurPage')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($rows));
    }
}
