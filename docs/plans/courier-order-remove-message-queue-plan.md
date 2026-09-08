# Plan: Remove Message Queue from `Uho_CourierOrder`

> **Audience:** This document is written to be executed by an implementation agent (e.g.
> `@magento-implementation` or a general-purpose coding agent) with no prior context beyond
> this file and the repository itself. Follow the steps in order. Do not skip the verification
> checklist.
>
> **Spec:** `docs/superpowers/specs/2026-09-08-courier-order-remove-message-queue-design.md` —
> read that first for the full rationale. This document is the step-by-step execution of it.

## Status
- [x] Implemented (steps 1-9 complete; manual verification checklist still pending)

## Context / Why

`Uho_CourierOrder` currently uses the Magento Message Queue (DB-backed, connection `db`) as a
notification mechanism: `CourierOrderManagement::submit()` persists a `pending` row in
`uho_courier_order_request` and publishes a `uho.courier.order.request.created` message; a
consumer daemon (`consumers_wait_for_messages=1` in `app/etc/env.php`) picks it up and calls
`RequestProcessor::process()` near-instantly.

The `uho_courier_order_request.status` column already encodes everything the queue is used
for (`pending -> processing -> completed/failed/retry/failed_partial`), and
`RequestProcessor::process(int $requestId)` is already a standalone orchestration method
(extracted during the `CourierOrderApi`/`CourierOrderProcessor` module merge). The queue is
pure transport overhead at this point — a cron that polls for eligible rows and calls
`RequestProcessor::process()` directly does the same job with less infrastructure (no queue
consumer daemon to keep running, no message serialization).

This trades near-instant pickup of new orders for up-to-2-minute pickup (the chosen cron
interval) — an accepted trade-off, see spec.

## Migration Steps (execute in order)

### 1. Rewrite the cron job that picks up pending + retry-eligible rows

Rename `app/code/Uho/CourierOrder/Model/Cron/RequeueRetryEligibleRequests.php` to
`app/code/Uho/CourierOrder/Model/Cron/ProcessPendingRequests.php` (delete the old file, create
the new one — do not leave the old class around).

New class body:

```php
<?php

declare(strict_types=1);

namespace Uho\CourierOrder\Model\Cron;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Uho\CourierOrder\Model\Request\CollectionFactory;
use Uho\CourierOrder\Model\Request\Record;
use Uho\CourierOrder\Model\RequestProcessor;

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
```

Notes for the implementer:
- The `WHERE` clause intentionally mirrors `Lifecycle::claim()`'s own guard
  (`status IN (pending, retry) AND (next_retry_at IS NULL OR next_retry_at <= now)`), so no
  separate "requeue" write is needed before calling `process()` — `RequestProcessor::process()`
  calls `Lifecycle::claim()` itself, which is the atomic double-dispatch guard.
- `ORDER BY request_id ASC` ensures oldest-first processing so a sustained backlog can't starve
  older rows.
- Do not wrap the `foreach` loop body in a try/catch — `RequestProcessor::process()` already
  catches `TransientProcessingException` and `\Throwable` internally and transitions status
  accordingly; a failure in one row must not abort the batch for the rest.

### 2. Update the cron schedule

Edit `app/code/Uho/CourierOrder/etc/crontab.xml`: replace the
`uho_courier_order_requeue_retry_eligible_requests` job with:

```xml
<job name="uho_courier_order_process_pending_requests"
     instance="Uho\CourierOrder\Model\Cron\ProcessPendingRequests"
     method="execute">
    <schedule>*/2 * * * *</schedule>
</job>
```

Leave the `uho_courier_order_reclaim_stuck_requests` job (`ReclaimStuckRequests`, `*/5 * * * *`)
untouched.

### 3. Drop the publish call from `submit()`

Edit `app/code/Uho/CourierOrder/Model/CourierOrderManagement.php`:
- Remove the `Publisher $publisher` constructor parameter and its `use` statement.
- Remove the `$this->publisher->publish((int) $record->getRequestId());` line in `submit()`.
- The method still persists the row as `STATUS_PENDING` and returns the `ACCEPTED` result
  unchanged otherwise.

### 4. Drop the publish call from `MassRequeue`

Edit `app/code/Uho/CourierOrder/Controller/Adminhtml/CourierOrderRequest/MassRequeue.php`:
- Remove the `Publisher $publisher` constructor parameter and its `use` statement.
- Remove the `$this->publisher->publish((int) $record->getRequestId());` line.
- Keep the `Lifecycle::requeue((int) $record->getRequestId(), true)` call and the
  success/skipped message logic unchanged — the next `ProcessPendingRequests` cron run picks up
  the now-`retry` rows.

### 5. Simplify `Lifecycle::requeue()`

Edit `app/code/Uho/CourierOrder/Model/Request/Lifecycle.php`. `requeue()` currently has two
`UPDATE` attempts: one for `status IN (failed, failed_partial) -> retry` (still needed, used by
`MassRequeue`), and a second fallback for `status = retry AND next_retry_at` due (only ever
reached when called by the old `RequeueRetryEligibleRequests` cron, which step 1 deletes).
Remove the second `UPDATE` branch — after step 1, nothing calls `requeue()` on an
already-`retry` row, so it's dead code. The method should end up doing a single `UPDATE`
guarded by `status IN (failed, failed_partial)` and returning `$affected === 1`.

### 6. Delete the queue transport files

Delete these files entirely:
- `app/code/Uho/CourierOrder/Model/Request/Publisher.php`
- `app/code/Uho/CourierOrder/Model/Request/Message.php`
- `app/code/Uho/CourierOrder/Model/Consumer/CourierOrderRequestConsumer.php` (and the now-empty
  `Model/Consumer/` directory)
- `app/code/Uho/CourierOrder/etc/communication.xml`
- `app/code/Uho/CourierOrder/etc/queue.xml`
- `app/code/Uho/CourierOrder/etc/queue_consumer.xml`
- `app/code/Uho/CourierOrder/etc/queue_publisher.xml`
- `app/code/Uho/CourierOrder/etc/queue_topology.xml`

No PHP code outside these files and the four call sites touched in steps 1/3/4 references
`Publisher`, `Message`, or `CourierOrderRequestConsumer` (verified by repo-wide grep during
design) — no other cleanup needed.

### 7. Clean up `module.xml`

Edit `app/code/Uho/CourierOrder/etc/module.xml`: remove the
`<module name="Magento_MessageQueue"/>` and `<module name="Magento_MysqlMq"/>` `<sequence>`
entries. Leave every other sequence entry unchanged.

### 8. Cosmetic schema comment reword

Edit `app/code/Uho/CourierOrder/etc/db_schema.xml`: reword the `next_retry_at` column's
`comment` attribute from `"Earliest time the consumer may retry"` to `"Earliest time the cron
processor may retry"`. This is a comment-only change — do not alter the column's `xsi:type`,
`name`, or `nullable` attributes.

### 9. Regenerate and verify (via Warden, per repo conventions)

```
warden env exec -T php-fpm bin/magento setup:upgrade
warden env exec -T php-fpm bin/magento setup:di:compile
warden env exec -T php-fpm bin/magento cache:flush
```

`setup:upgrade` will pick up the `db_schema.xml` comment change as a harmless `ALTER ... COMMENT`
on `next_retry_at`.

## Verification Checklist

- [ ] `setup:upgrade` runs clean with no schema errors.
- [ ] `setup:di:compile` succeeds with no missing-class errors (confirms nothing still
      references the deleted `Publisher`/`Message`/`CourierOrderRequestConsumer` classes).
- [ ] `POST /V1/courier-orders` accepts a valid test payload, persists a `pending` row, and
      returns the expected `ACCEPTED` result — with no publish step and no error from the
      removed `Publisher` dependency.
- [ ] `bin/magento cron:run` (or waiting for the scheduled cron) picks up the new `pending` row
      via `uho_courier_order_process_pending_requests` and processes it end-to-end (quote ->
      order -> invoice -> shipment -> `status = completed`).
- [ ] A `retry`-status row with a due `next_retry_at` is picked up by the same cron job on its
      next run.
- [ ] Admin grid (`uho_courier_order_request` listing) loads and displays existing + new rows
      unchanged.
- [ ] Admin "Mass Requeue" action on a `failed`/`failed_partial` row transitions it to `retry`
      with no error (no more `Publisher` call), and it is picked up by the next
      `ProcessPendingRequests` cron run.
- [ ] `ReclaimStuckRequests` cron still runs without error and behaves as before (unchanged
      code, but confirm no fallout from the sibling cron rename).
- [ ] Two concurrent cron runs (or a manual overlap test) do not double-process the same row —
      confirms `Lifecycle::claim()`'s atomic guard still protects against double dispatch now
      that it's the only entry point.
- [ ] No RabbitMQ/DB-queue traffic is generated for courier order requests (queue consumer for
      `uho.courier.order.request.created` no longer exists/registered).
- [ ] PHPCS/PHPStan run clean (or with no new warnings beyond pre-existing baseline).

## Risks / Things To Double-Check

- **In-flight queue messages**: if any `uho.courier.order.request.created` messages are still
  unconsumed in the queue at deploy time (e.g. consumer daemon was down), they will never be
  processed after this change removes the consumer registration — the underlying `pending` row
  still exists in `uho_courier_order_request` and will be picked up by the first
  `ProcessPendingRequests` cron run after deploy, so no data is lost, but confirm the queue is
  drained (or accept the message is simply discarded once its target row is already
  `pending`/being polled) before deploying.
- **Consumer daemon**: if a supervisor/process manager outside this repo is configured to run
  `bin/magento queue:consumers:start uhoCourierOrderRequestConsumer` (or a wildcard
  `consumers:start-all`) for this specific consumer, remove or update that process definition
  after this change ships — it will fail to start once the consumer is unregistered. No such
  configuration was found in this repo (checked `deploy/`, no supervisor config present), so
  this is likely managed outside the repo if it exists at all.
