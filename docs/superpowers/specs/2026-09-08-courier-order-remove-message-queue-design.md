# Remove Message Queue from `Uho_CourierOrder`

Date: 2026-09-08
Module: `Uho_CourierOrder`

> Supersedes `docs/superpowers/specs/2026-09-07-courier-order-remove-message-queue-design.md`,
> which was written against the pre-merge `Uho_CourierOrderApi` / `Uho_CourierOrderProcessor`
> split. That split has since been merged into the single `Uho_CourierOrder` module (see
> `docs/plans/courier-order-module-merge-plan.md`), and the merge already extracted
> `RequestProcessor::process(int $requestId)` as a standalone orchestration method. This spec
> reflects the current module structure.

## Goal

Remove the Magento Message Queue framework entirely from `Uho_CourierOrder`. Courier order
requests are already persisted as rows in `uho_courier_order_request` with a `status` column
(`pending -> processing -> completed/failed/retry/failed_partial`) — that row **is** the queue.
A single cron job replaces the publish/consume mechanism by directly polling and processing
eligible rows in-process. All existing claim/retry/backoff/stuck-reclaim semantics in
`Model/Request/Lifecycle.php` are preserved unchanged; only the transport mechanism
(queue -> cron) changes.

## Current State (for reference)

- `CourierOrderManagement::submit()` persists a `pending` row, then publishes a
  `uho.courier.order.request.created` message via `Model/Request/Publisher.php`.
- `Model/Consumer/CourierOrderRequestConsumer.php` (queue consumer, run continuously by a
  consumer daemon — `consumers_wait_for_messages=1` in `app/etc/env.php`) is a thin adapter
  that calls `RequestProcessor::process()` near-instantly after a message arrives.
- `Model/Cron/RequeueRetryEligibleRequests.php` (`*/2 * * * *`) finds retry-eligible rows
  (`status=retry AND next_retry_at<=now`), calls `Lifecycle::requeue()` on each, then
  re-publishes them. Only handles *retries* — brand-new `pending` rows are handled by the
  queue consumer, not this cron.
- `Model/Cron/ReclaimStuckRequests.php` (`*/5 * * * *`) reclaims rows stuck in `processing`
  back to `retry` — already queue-agnostic, no changes needed.
- `Controller/Adminhtml/CourierOrderRequest/MassRequeue.php` resets failed/failed_partial
  rows to `retry` via `Lifecycle::requeue()` and publishes them.

## Target Architecture

### Removed entirely
- `Model/Request/Publisher.php`
- `Model/Request/Message.php`
- `Model/Consumer/CourierOrderRequestConsumer.php`
- `etc/communication.xml`
- `etc/queue.xml`
- `etc/queue_consumer.xml`
- `etc/queue_publisher.xml`
- `etc/queue_topology.xml`
- `<module name="Magento_MessageQueue"/>` and `<module name="Magento_MysqlMq"/>` sequence
  entries in `etc/module.xml` (verified: no other custom module in this repo depends on this
  module's sequence for MQ load order; Magento core's other queues are unaffected since they
  don't depend on this module's declaration)

### Renamed / repurposed
- `Model/Cron/RequeueRetryEligibleRequests.php` → `Model/Cron/ProcessPendingRequests.php`.
  Queries rows where `status = pending` OR (`status = retry` AND (`next_retry_at IS NULL` OR
  `next_retry_at <= now`)), ordered by `request_id ASC` (oldest first, so a sustained backlog
  can't starve older rows behind newer ones), capped at 20 rows per run, and calls
  `RequestProcessor::process($requestId)` directly for each instead of requeuing + publishing.
  This query mirrors `Lifecycle::claim()`'s own `WHERE` clause, so no intermediate write is
  needed before calling `process()` — `process()` calls `claim()` itself, which remains the
  atomic double-dispatch guard.
- Cron job name in `etc/crontab.xml`: `uho_courier_order_requeue_retry_eligible_requests` →
  `uho_courier_order_process_pending_requests`. Schedule unchanged: `*/2 * * * *`.

### Unchanged
- `Model/Cron/ReclaimStuckRequests.php` — already queue-agnostic.
- `Model/Request/Lifecycle.php` — `claim()`, `markRetry()`, `markFailed()`, `markCompleted()`,
  `markPartial()`, `reclaimStuck()` logic unchanged. Backoff minutes / max attempts / stuck
  timeout constants unchanged.
- `Model/RequestProcessor.php` — already takes `int $requestId` and contains the full
  claim -> resume/place order -> invoice -> shipment -> status-transition orchestration. No
  changes needed; it becomes the direct call target instead of being invoked via the consumer
  adapter.

### Modified call sites
- `Model/CourierOrderManagement.php::submit()` — drop the `Publisher` dependency and the
  `$this->publisher->publish(...)` call. Persists the row as `STATUS_PENDING` and returns the
  accept result; no publish step.
- `Controller/Adminhtml/CourierOrderRequest/MassRequeue.php` — drop the `Publisher` dependency
  and the `publish()` call. Still resets status to `retry` via `Lifecycle::requeue()`; the next
  `ProcessPendingRequests` cron run (within 2 minutes) picks it up. No synchronous processing
  from the controller.

### Cleanup: dead code in `Lifecycle::requeue()`
`Lifecycle::requeue(int $requestId, bool $clearFailureReason = false): bool` currently has two
`UPDATE` branches:
1. `status IN (failed, failed_partial) -> retry` — still needed; used by `MassRequeue`.
2. `status = retry AND next_retry_at due -> retry` (a no-op status-wise, just clears
   `next_retry_at`/`claimed_at`) — only ever called by the old `RequeueRetryEligibleRequests`
   cron, which is being removed. Once that caller is gone this branch is unreachable dead code.

Remove branch 2. `requeue()` keeps only the failed/failed_partial → retry transition, which
matches its only remaining caller (`MassRequeue`).

## Data Flow

```
API submit()
  -> validate, resolve address, plan reconciliation
  -> save row: status=pending, attempts=0
  -> return ACCEPTED (no publish)

Cron: ProcessPendingRequests (*/2 * * * *)
  -> SELECT WHERE status=pending OR (status=retry AND (next_retry_at IS NULL OR next_retry_at<=now))
     ORDER BY request_id ASC LIMIT 20
  -> for each: RequestProcessor::process($requestId)
       -> Lifecycle::claim($requestId)   [atomic UPDATE, unchanged]
       -> if claimed: resume-or-place order -> invoice -> shipment -> markCompleted
       -> on TransientProcessingException: markRetry (schedules next_retry_at via backoff)
       -> on other Throwable: markFailed / markPartial (unchanged)

Cron: ReclaimStuckRequests (*/5 * * * *, unchanged)
  -> processing stuck beyond timeout -> retry (picked up by next ProcessPendingRequests run)

Admin MassRequeue
  -> failed/failed_partial -> retry (via Lifecycle::requeue()), next_retry_at=null
     (picked up by next ProcessPendingRequests run)
```

Worst-case latency for a new request moves from near-instant (consumer daemon) to ~2 minutes
(cron interval) — an accepted trade-off for removing the queue infrastructure.

## Error Handling

Unchanged. `Lifecycle::claim()` (atomic `UPDATE ... WHERE status IN (pending, retry) AND
(next_retry_at IS NULL OR next_retry_at <= now)`) still guards against double-processing if a
cron run overlaps a previous slow one — the same guard that previously protected against
duplicate consumer delivery now also protects against `ProcessPendingRequests` re-selecting a
row still being handled by a prior, still-running invocation. `TransientProcessingException`
still triggers backoff-scheduled retry; other exceptions still mark `failed`/`failed_partial`
as before. No new failure modes are introduced by moving from a queue consumer loop to a cron
polling loop.

The 20-row cap per `ProcessPendingRequests` run bounds worst-case run time; any excess eligible
rows are simply picked up on the next run two minutes later.

## Testing

Any existing test referencing `Model/Consumer/CourierOrderRequestConsumer.php` or
`Model/Request/Message.php` must be updated to call `RequestProcessor::process(int $requestId)`
directly, or removed if it was only testing the now-deleted adapter.

## Documentation Cleanup

- `etc/db_schema.xml`: the `next_retry_at` column comment "Earliest time the consumer may
  retry" reworded to "Earliest time the cron processor may retry" (cosmetic only — no column
  type/name change).
- Doc comments referencing "message queue" / "consumer" / "publish" in the four touched files
  (`CourierOrderManagement.php`, `MassRequeue.php`, and the deleted `Publisher.php` /
  `CourierOrderRequestConsumer.php`) are resolved by deletion or by the call-site edits above.

## Out of Scope

- `docs/erp/courier-order-api-architecture.md` — already stale from the pre-merge module split
  (references `Uho_CourierOrderApi`/`Uho_CourierOrderProcessor` throughout). Bringing it in
  sync with both the merge and this change is a separate documentation task.
- No changes to `uho_courier_order_request` table schema/columns beyond the one comment reword
  — the existing status state machine is reused as-is.
- No changes to `Lifecycle`'s claim/retry/backoff semantics.
- No changes to `ReclaimStuckRequests`.
- No change to ACL resource IDs (e.g. `Uho_CourierOrderProcessor::requeue` stays as-is — a
  pre-existing legacy naming artifact from the module merge, unrelated to queue removal).
- No change to how the admin UI labels the "Requeue" mass action (still resets to `retry`
  conceptually; only the publish step is removed).
