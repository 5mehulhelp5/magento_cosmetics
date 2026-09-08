<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Request;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

use function count;
use function max;
use function min;
use function sprintf;
class Lifecycle
{
    private const string TABLE = 'uho_courier_order_request';
    private const int MAX_ATTEMPTS = 5;
    private const int STUCK_TIMEOUT_MINUTES = 30;

    /** @var int[] */
    private const array BACKOFF_MINUTES = [1, 5, 15, 30, 60];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function claim(int $requestId): bool
    {
        $connection = $this->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $now = $connection->formatDate(new \DateTimeImmutable());

        $affected = $connection->update(
            $table,
            [
                'status' => Record::STATUS_PROCESSING,
                'claimed_at' => $now,
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
            ],
            [
                'request_id = ?' => $requestId,
                "status IN ('" . Record::STATUS_PENDING . "', '" . Record::STATUS_RETRY . "')",
                '(next_retry_at IS NULL OR next_retry_at <= ?)' => $now,
            ]
        );

        return $affected === 1;
    }

    public function markRetry(int $requestId, string $reason): void
    {
        $connection = $this->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $attempts = (int) $connection->fetchOne(
            $connection->select()->from($table, ['attempts'])->where('request_id = ?', $requestId)
        );

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->markFailed($requestId, $reason . ' (max attempts exceeded)');

            return;
        }

        $connection->update(
            $table,
            [
                'status' => Record::STATUS_RETRY,
                'failure_reason' => $reason,
                'next_retry_at' => $connection->formatDate(
                    (new \DateTimeImmutable())->modify(sprintf('+%d minutes', $this->getBackoffMinutes($attempts)))
                ),
                'claimed_at' => null,
            ],
            ['request_id = ?' => $requestId]
        );
    }

    public function markFailed(int $requestId, string $reason): void
    {
        $this->updateTerminalState(
            $requestId,
            [
                'status' => Record::STATUS_FAILED,
                'failure_reason' => $reason,
            ]
        );
    }

    public function markCompleted(int $requestId, string $orderIncrementId): void
    {
        $this->updateTerminalState(
            $requestId,
            [
                'status' => Record::STATUS_COMPLETED,
                'order_increment_id' => $orderIncrementId,
                'failure_reason' => null,
            ]
        );
    }

    public function markPartial(int $requestId, string $orderIncrementId, string $reason): void
    {
        $this->updateTerminalState(
            $requestId,
            [
                'status' => Record::STATUS_FAILED_PARTIAL,
                'order_increment_id' => $orderIncrementId,
                'failure_reason' => $reason,
            ]
        );
    }

    public function requeue(int $requestId, bool $clearFailureReason = false): bool
    {
        $connection = $this->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $data = [
            'status' => Record::STATUS_RETRY,
            'next_retry_at' => null,
            'claimed_at' => null,
        ];
        if ($clearFailureReason) {
            $data['failure_reason'] = null;
        }

        $affected = $connection->update(
            $table,
            $data,
            [
                'request_id = ?' => $requestId,
                "status IN ('" . Record::STATUS_FAILED . "', '" . Record::STATUS_FAILED_PARTIAL . "')",
            ]
        );

        return $affected === 1;
    }

    public function reclaimStuck(): int
    {
        $connection = $this->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $threshold = $connection->formatDate(
            (new \DateTimeImmutable())->modify(sprintf('-%d minutes', self::STUCK_TIMEOUT_MINUTES))
        );
        $now = $connection->formatDate(new \DateTimeImmutable());

        return $connection->update(
            $table,
            [
                'status' => Record::STATUS_RETRY,
                'failure_reason' => 'Reclaimed by cron sweeper: stuck in processing beyond timeout',
                'next_retry_at' => $now,
                'claimed_at' => null,
            ],
            [
                'status = ?' => Record::STATUS_PROCESSING,
                'claimed_at < ?' => $threshold,
            ]
        );
    }

    private function updateTerminalState(int $requestId, array $data): void
    {
        $data['next_retry_at'] = null;
        $data['claimed_at'] = null;
        $this->getConnection()->update(
            $this->resourceConnection->getTableName(self::TABLE),
            $data,
            ['request_id = ?' => $requestId]
        );
    }

    private function getBackoffMinutes(int $attempt): int
    {
        $index = max(0, min($attempt - 1, count(self::BACKOFF_MINUTES) - 1));

        return self::BACKOFF_MINUTES[$index];
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }
}
