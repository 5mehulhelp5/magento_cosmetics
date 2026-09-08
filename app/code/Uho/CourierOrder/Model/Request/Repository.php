<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Request;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;

class Repository
{
    public function __construct(
        private readonly ResourceModel $resourceModel,
        private readonly RecordFactory $recordFactory,
    ) {
    }

    public function save(Record $record): Record
    {
        try {
            $this->resourceModel->save($record);
        } catch (AlreadyExistsException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                new Phrase('Could not save courier order request: %1', [$exception->getMessage()]),
                $exception
            );
        }

        return $record;
    }

    public function getById(int $id): Record
    {
        /** @var Record $record */
        $record = $this->recordFactory->create();
        $this->resourceModel->load($record, $id);
        if ($record->getRequestId() === null) {
            throw new NoSuchEntityException(new Phrase('Courier order request with id %1 does not exist.', [$id]));
        }

        return $record;
    }

    public function getByTrackingNumber(string $trackingNumber): Record
    {
        /** @var Record $record */
        $record = $this->recordFactory->create();
        $this->resourceModel->load($record, $trackingNumber, Record::TRACKING_NUMBER);
        if ($record->getRequestId() === null) {
            throw new NoSuchEntityException(
                new Phrase('Courier order request with tracking number %1 does not exist.', [$trackingNumber])
            );
        }

        return $record;
    }
}
