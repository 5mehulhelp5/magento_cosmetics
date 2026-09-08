# Product Rotation for Courier Reconciliation Pool

Date: 2026-09-08
Module: `Uho_CourierOrder`

## Goal

`Uho\CourierOrder\Model\Reconciliation\EligibleProductPool::fetchPool()` sorts eligible products
by price descending only, so `Planner`'s greedy/bounded-search packing repeatedly picks the same
small set of highest-priced SKUs to reconcile every courier order total. Add a rotation counter
(`reconciliation_number`) so products that have been chosen less often surface first, spreading
reconciliation usage across the catalog over time.

## Current State (for reference)

- `EligibleProductPool::fetchPool($storeId)` loads all enabled, in-stock, priced products for a
  store's website, sorts them by `priceCents` descending, and caches the result per store for
  300 seconds (`CACHE_TTL_SECONDS`, tag `uho_courier_reconciliation_eligible_pool`).
- `Planner::plan()` reads that pool via `getPool()` and walks it index-by-index (`greedyPass`,
  falling back to `boundedSearch` if the greedy remainder exceeds the configured ceiling) to
  build a `Plan` of `PlanLine`s (`sku`, `qty`, `unitPriceCents`) that sum close to the target.
- `CourierOrderManagement::submit()` calls `Planner::plan()`, builds a `Request` record
  (including a serialized snapshot of the plan), and persists it via `Repository::save()`. On a
  duplicate `tracking_number` (`AlreadyExistsException`), it re-checks for the existing duplicate
  and returns that instead.
- Nothing today tracks how often a given SKU has been selected, so the price-descending sort is
  static and the same top-priced products are chosen on (almost) every request.

## Target Design

### Data model

Extend `catalog_product_entity` via `db_schema.xml` (same pattern already used in this module to
add `courier_reconciliation_adjustment` to `sales_order`):

```xml
<table name="catalog_product_entity" resource="default" engine="innodb">
    <column xsi:type="int" name="reconciliation_number" unsigned="true" nullable="false" default="0"
            comment="Courier reconciliation rotation counter - number of times this product has been selected into an accepted reconciliation plan"/>
</table>
```

- Global counter, not store-scoped: one physical product being rotated for reconciliation
  purposes is the same regardless of which store submitted the courier order.
- `reconciliation_number` is a plain static column on the main entity table, not an EAV
  attribute — it does not need `eav_attribute` registration and is not requested via
  `addAttributeToSelect()`. It is already present in the product collection's default `e.*`
  select.
- `db_schema_whitelist.json` is regenerated as part of implementation.
- No new index. Sorting continues to happen in PHP over the already-filtered, in-memory pool
  (unchanged from today's approach); an index can be added later if pool size ever makes SQL-side
  ordering necessary.

### `EligibleProductPool` changes

- `fetchPool()`: the `usort` comparator changes from price-descending-only to
  `reconciliation_number` ascending primary key, `priceCents` descending as a tie-break among
  products with equal rotation count.
- New public method `invalidate(): void` — calls `$this->cache->clean([self::CACHE_TAG])`. The
  existing cache tag is shared across every store's cache entry (only the cache *key* is
  store-suffixed), so a single call clears every store's cached pool at once. That's correct here
  because the counter is global: a rotation change is relevant to every store's pool, not just
  the store that triggered it.

### New class: `ReconciliationUsageRecorder`

`Uho\CourierOrder\Model\Reconciliation\ReconciliationUsageRecorder`

```php
public function recordUsage(Plan $plan): void
```

- Collects the distinct SKUs from `$plan->getLines()`. If there are none (a pure-adjustment plan
  with zero product lines), returns immediately — no DB call.
- Otherwise runs one atomic statement via `ResourceConnection`:
  `UPDATE catalog_product_entity SET reconciliation_number = reconciliation_number + 1 WHERE sku IN (...)`.
  This is a single `col = col + 1` statement, not a read-modify-write, so it's race-safe under
  concurrent submits touching the same SKU without any application-level locking.
- On success, calls `EligibleProductPool::invalidate()` so the very next `submit()` recomputes
  the pool instead of reusing a stale-ordered cached one.
- The whole method body is wrapped in try/catch. On any `\Throwable`, logs via
  `Psr\Log\LoggerInterface::error()` and swallows the exception — see Error Handling below.
- Increment is **+1 per SKU per plan, regardless of qty**: a SKU picked at qty=5 still only
  advances its own rotation count by 1. The counter tracks how often a SKU is *chosen*, which is
  what drives selection fairness — not how many units it happened to need that time.

### `CourierOrderManagement::submit()` changes

- New constructor dependency: `ReconciliationUsageRecorder`.
- After `$this->requestRepository->save($record)` succeeds, call
  `$this->reconciliationUsageRecorder->recordUsage($plan)`.
- Not called on the duplicate-tracking-number path (`AlreadyExistsException` branch) — that path
  returns an existing accepted request, not a newly accepted one, so no new usage occurred.

### Data flow

1. `submit()` calls `Planner::plan()`, which reads `EligibleProductPool::getPool()` (sorted by
   rotation count ascending, then price descending) and builds a `Plan`.
2. The `Request` record is persisted.
3. On successful save, `ReconciliationUsageRecorder::recordUsage($plan)` increments
   `reconciliation_number` for every SKU in the plan's lines, then invalidates the cached pool for
   all stores.
4. The next `submit()` call (any store) gets a cache miss, recomputes the pool from the database,
   and the just-used SKUs now sort later relative to less-recently-used ones.

### Sort behavior tradeoff

Today's price-descending sort makes `Planner`'s packing efficient: trying expensive items first
reaches the target total in fewer lines, within `max_lines`/`max_qty_per_line`. Making
`reconciliation_number` the primary sort key means least-used products are tried first
*regardless of price*, which:

- Maximizes rotation/fairness across the whole catalog (the explicit goal of this change).
- Can reduce packing efficiency: cheaper or otherwise atypical-price products may now be tried
  before expensive ones, so more lines could be needed to reach a target, and `boundedSearch` may
  need more nodes to close the gap under `adjustment_ceiling_kopecks`.

This is an accepted tradeoff, not a defect. `max_lines`, `max_qty_per_line`, and
`max_search_nodes` are already configurable per store (`Reconciliation\Config`) and are the
levers to reach for if `ReconciliationFailedException` rates increase after deploy. No new
configuration is introduced by this change.

## Error Handling

- `ReconciliationUsageRecorder::recordUsage()` failures (DB error, cache error) are caught,
  logged, and swallowed. `submit()` still returns `STATUS_ACCEPTED` — the courier order request
  was legitimately accepted and persisted; a failed rotation-counter update is a fairness/cosmetic
  concern, not grounds to fail an already-accepted request.
- No new failure mode is introduced into `Planner` or `EligibleProductPool::getPool()` — both
  continue to behave as today except for the changed sort order.

## Testing

- `EligibleProductPoolTest`: given pool entries with mixed `reconciliation_number`/price
  combinations, assert the resulting order matches rotation-ascending-then-price-descending.
- `ReconciliationUsageRecorderTest`:
  - Verifies the `UPDATE ... WHERE sku IN (...)` statement is built correctly from a plan's
    lines (distinct SKUs, `+1` semantics regardless of qty).
  - Verifies `EligibleProductPool::invalidate()` is called after a successful update.
  - Verifies a plan with zero lines is a no-op (no DB call, no cache invalidation).
  - Verifies an exception during the update or invalidation is caught, logged, and not
    propagated.
- `CourierOrderManagementTest`: verifies `recordUsage()` fires only after a successful
  `Repository::save()`, and is not called on the duplicate-tracking-number
  (`AlreadyExistsException`) path.

## Out of Scope

- Per-store rotation tracking (counter is intentionally global — see Data model).
- Admin UI/grid to view or reset `reconciliation_number`.
- Moving pool sorting from PHP (`usort`) into SQL (`ORDER BY`) — unchanged from today's approach.
- Changing when the underlying `Request` is actually fulfilled into a real order; this design
  only tracks *planning* usage at `submit()` time, per the "increment on successful request save"
  decision.
