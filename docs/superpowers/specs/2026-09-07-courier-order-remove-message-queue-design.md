# Remove Message Queue from Courier Order Modules

Date: 2026-09-07
Modules: `Uho_CourierOrderApi`, `Uho_CourierOrderProcessor`

## Goals

Remove the Magento Message Queue framework entirely from both modules. Courier order
requests are already persisted as rows in `uho_courier_order_request` with a `status`
column (`pending -> processing -> completed/failed/retry/failed_partial`) — that row
**is** the queue. A cron job replaces the publish/consume mechanism by directly polling
and processing eligible rows in-process. All existing retry/backoff/stuck-reclaim/partial-
resume semantics are preserved unchanged; only the transport mechanism (queue -> cron)
changes.

## Current State (for reference)

- `CourierOrderManagement::submit()` persists a `pending` row, then publishes a
  `uho.courier.order.request.created` message via `PublisherInterface`.
- `CourierOrderRequestConsumer` (queue consumer, run continuously by a consumer daemon —
  `consumers_wait_for_messages=1` in `app/etc/env.php`) claims and processes the row
  near-instantly.
- `RequeueRetryEligibleRequests` cron (`*/2 * * * *`) finds retry-eligible rows and
  re-publishes them (message-queue delivery only happens once per publish).
- `ReclaimStuckRequests` cron (`*/5 * * * *`) reclaims rows stuck in `processing` back to
  `retry` — already queue-agnostic, no changes needed.
- `MassRequeue` admin mass action resets failed/failed_partial rows to `retry` and
  publishes them.

## Target Architecture

### Removed entirely
- `Uho_CourierOrderApi/etc/communication.xml`
- `Uho_CourierOrderApi/etc/queue_publisher.xml`
- `Uho_CourierOrderApi/Api/Data/CourierOrderQueueMessageInterface.php`
- `Uho_CourierOrderApi/Model/Data/CourierOrderQueueMessage.php`
  (+ its `di.xml` preference entry)
- `Uho_CourierOrderProcessor/etc/queue.xml`
- `Uho_CourierOrderProcessor/etc/queue_consumer.xml`
- `Uho_CourierOrderProcessor/etc/queue_topology.xml`
- `<module name="Magento_MessageQueue"/>` sequence entries in both modules' `module.xml`

### Renamed / repurposed
- `Uho_CourierOrderProcessor/Model/Consumer/CourierOrderRequestConsumer.php` →
  `Uho_CourierOrderProcessor/Model/Processor/CourierOrderRequestProcessor.php`.
  Public method changes from `process(CourierOrderQueueMessageInterface $message)` to
  `process(int $requestId): void`. Internal claim -> resume/place order -> invoice ->
  shipment -> status-transition logic is unchanged.
- `Uho_CourierOrderProcessor/Model/Cron/RequeueRetryEligibleRequests.php` →
  `Uho_CourierOrderProcessor/Model/Cron/ProcessPendingRequests.php`. Queries rows where
  `status = pending` OR (`status = retry` AND `next_retry_at <= now`), capped at 50 rows
  per run, and calls `CourierOrderRequestProcessor::process($requestId)` directly for
  each instead of publishing.

### Unchanged
- `Uho_CourierOrderProcessor/Model/Cron/ReclaimStuckRequests.php` — already queue-agnostic.
- `Uho_CourierOrderProcessor/Model/Status/RequestStatusManager.php` — atomic claim/retry/
  failed/completed/partial/reclaim logic unchanged.
- `Uho_CourierOrderProcessor/Model/Config.php` — max attempts / backoff / stuck-timeout
  tunables unchanged.

### Modified call sites
- `Uho_CourierOrderApi/Model/CourierOrderManagement.php::submit()` — drop
  `PublisherInterface` and `CourierOrderQueueMessageInterfaceFactory` dependencies and the
  `publish()` call. Persists the row as `STATUS_PENDING` and returns the accept result;
  no publish step.
- `Uho_CourierOrderProcessor/Controller/Adminhtml/CourierOrderRequest/MassRequeue.php` —
  drop `PublisherInterface`/queue-message-factory dependencies. Resets status to `retry`
  only; the next `ProcessPendingRequests` cron run (within 5 minutes) picks it up. No
  synchronous processing from the controller.
- `Uho_CourierOrderProcessor/etc/crontab.xml` — `ProcessPendingRequests` scheduled
  `*/5 * * * *`. `ReclaimStuckRequests` schedule unchanged (`*/5 * * * *`).

## Data Flow

```
API submit()
  -> validate, resolve address, plan reconciliation
  -> save row: status=pending, attempts=0
  -> return ACCEPTED (no publish)

Cron: ProcessPendingRequests (*/5 * * * *)
  -> SELECT WHERE status=pending OR (status=retry AND next_retry_at<=now), LIMIT 50
  -> for each: CourierOrderRequestProcessor::process($requestId)
       -> RequestStatusManager::claim($requestId)   [atomic UPDATE, unchanged]
       -> if claimed: resume-or-place order -> invoice -> shipment -> markCompleted
       -> on TransientProcessingException: markRetry (schedules next_retry_at via backoff)
       -> on other Throwable: markFailed / markPartial (unchanged)

Cron: ReclaimStuckRequests (*/5 * * * *, unchanged)
  -> processing stuck beyond timeout -> retry (picked up by next ProcessPendingRequests run)

Admin MassRequeue
  -> failed/failed_partial -> retry, next_retry_at=null (picked up by next cron run)
```

Worst-case latency for a new request moves from near-instant (consumer daemon) to ~5
minutes (cron interval) — an accepted trade-off for removing the queue infrastructure.

## Error Handling

Unchanged. `RequestStatusManager::claim()` (atomic `UPDATE ... WHERE status IN (pending,
retry)`) still guards against double-processing if a cron run overlaps a previous slow
one. `TransientProcessingException` still triggers backoff-scheduled retry; other
exceptions still mark `failed`/`failed_partial` as before. No new failure modes are
introduced by moving from a queue consumer loop to a cron polling loop.

## Testing

- `Uho_CourierOrderApi/Test/Unit/Architecture/ModuleDependencyBoundaryTest.php` is
  unaffected (it forbids Quote/Sales imports in the Api module, unrelated to queue
  removal).
- Any existing tests referencing `CourierOrderRequestConsumer` or the queue message
  interface/class must be updated to use `CourierOrderRequestProcessor` and the
  `int $requestId` signature.

## Documentation Cleanup

- Doc comments referencing "message queue" / "consumer" / "publish" across both modules
  (e.g. `CourierOrderManagementInterface`, `PayloadValidator`,
  `Model/Exception/InvalidPayloadException`, `ShipmentCreator`, `ShippingAssigner`,
  `RequestStatusManager`, `RequestStatusManager`'s class doc, `ProcessPendingRequests`)
  updated to describe cron-based processing instead of queue publish/consume.
- `Uho_CourierOrderApi/etc/db_schema.xml` comment "Earliest time the consumer may retry"
  reworded to "processor" (cosmetic only, no schema change — column/table definitions are
  untouched).
- `docs/erp/courier-order-api-architecture.md` sections describing message-queue
  publish/consume flow (referenced by section number in several class docblocks) updated
  to describe the cron-polling flow.

## Out of Scope

- No changes to `uho_courier_order_request` table schema/columns — the existing status
  state machine is reused as-is.
- No changes to `RequestStatusManager`'s claim/retry/backoff semantics.
- No changes to `ReclaimStuckRequests`.
- No change to how the admin UI labels the "Requeue" mass action (still resets to
  `retry` conceptually; only the publish step is removed).
