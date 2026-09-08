<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cron;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Uho\CourierOrder\Model\Request\CollectionFactory;
use Uho\CourierOrder\Model\Request\Record;
use Uho\CourierOrder\Model\RequestProcessor;

use function sprintf;
class ProcessPendingRequests
{
    private const int BATCH_SIZE = 20;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly RequestProcessor $requestProcessor,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $collection = $this->collectionFactory->create();
        $connection = $collection->getConnection();
        $now = $this->dateTime->gmtDate();

        $pendingCondition = $connection->quoteInto(Record::STATUS . ' = ?', Record::STATUS_PENDING);
        $retryCondition = $connection->quoteInto(Record::STATUS . ' = ?', Record::STATUS_RETRY)
            . ' AND (' . Record::NEXT_RETRY_AT . ' IS NULL OR '
            . $connection->quoteInto(Record::NEXT_RETRY_AT . ' <= ?', $now) . ')';

        $collection->getSelect()->where("({$pendingCondition}) OR ({$retryCondition})");
        $collection->setOrder(Record::REQUEST_ID, 'ASC');
        $collection->setPageSize(self::BATCH_SIZE)->setCurPage(1);

        $processed = 0;

        /** @var Record $request */
        foreach ($collection->getItems() as $request) {
            $this->requestProcessor->process((int) $request->getRequestId());
            $processed++;
        }

        if ($processed > 0) {
            $this->logger->info(
                sprintf('Courier order processor: processed %d pending/retry-eligible request(s)', $processed)
            );
        }
    }
}
