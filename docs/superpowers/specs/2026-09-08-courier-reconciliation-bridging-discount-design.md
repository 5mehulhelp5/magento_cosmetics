# Bridging Fallback + Floating Discount for Courier Reconciliation Planner

Date: 2026-09-08
Module: `Uho_CourierOrder`

## Goal

`Uho\CourierOrder\Model\Reconciliation\Planner::plan()` builds a set of product lines whose sum
approximates a courier order's declared total. When neither `greedyPass` (single forward pass)
nor `boundedSearch` (bounded DFS refinement) can find a combination whose remainder is within the
configured `adjustment_ceiling_kopecks`, `plan()` throws `ReconciliationFailedException` and the
whole courier order request fails.

Add one more fallback step: when no combination undershoots closely enough, add a single bridging
product (or extra qty of one) so the selected lines *overshoot* the target, then apply a negative
`adjustmentCents` (a floating discount) that exactly cancels the overshoot. This makes
`ReconciliationFailedException` for "no close-enough combination" unreachable whenever the
eligible product pool is non-empty — the pool-emptiness check earlier in `plan()` is unaffected
and still throws.

## Current State (for reference)

- `Planner::plan()`:
  1. Converts the payload total to `targetCents`.
  2. Loads the pool via `EligibleProductPool::getPool($storeId)` (sorted rotation-count ascending,
     price descending — see the product-rotation design). Throws if the pool is empty.
  3. Runs `greedyPass($pool, $targetCents)` — walks the pool once, greedily taking the largest
     affordable qty of each product without exceeding the remaining target. Can never overshoot;
     by construction the remainder is always `>= 0`.
  4. If the greedy remainder exceeds `ceiling`, runs `boundedSearch()` — a bounded DFS that only
     explores combinations where the running remainder stays `>= 0` (same non-overshoot
     constraint), returning the best (smallest remainder) combination found within
     `max_search_nodes`. Returns `null` if it never finds a remainder `<= ceiling`, **discarding**
     whatever best-effort combination it found along the way.
  5. If `boundedSearch()` returns `null`, `plan()` throws `ReconciliationFailedException`.
  6. Otherwise builds a `Plan` (list of `PlanLine`s plus `adjustmentCents`, the leftover remainder
     — always `>= 0` today).
- Downstream, `adjustmentCents` flows: `QuoteFinalizer` sets it as quote data
  (`courier_reconciliation_adjustment`, converted to major units) → `CourierReconciliationAdjustment`
  (a quote total model) adds it to the grand total and exposes it as a totals-summary line →
  `CopyReconciliationAdjustmentToOrder` observer copies it from quote to order on conversion →
  `Totals` block (order view) renders it as an extra row in the order totals table.
- **All four of those downstream classes currently guard with `if ($adjustment <= 0.0) { return; }`**
  (or equivalent), because only non-negative top-up adjustments have ever existed. None of them,
  nor the `sales_order.courier_reconciliation_adjustment` column (`decimal`, `unsigned="false"`),
  need schema changes to support a negative value — the column is already signed.

## Target Design

### `Planner` algorithm changes

1. **`boundedSearch()` return contract changes**: instead of returning `null` when it never beats
   the ceiling, it always returns `[$best, $bestRemaining]` (the best combination found, even if
   `$bestRemaining > $ceiling`). `$best` is only ever `null` if the search never runs at all, which
   cannot happen given a non-empty pool (the root call with `$current = []` always evaluates
   before any recursion). Callers that need the old ceiling-gate behavior now check
   `$bestRemaining <= $ceiling` themselves.
2. **`plan()` picks a base combo**: run `greedyPass`, and if its remainder exceeds `ceiling`, also
   run `boundedSearch`. Take whichever of the two has the smaller remainder as the *base combo*
   (`$lines`, `$remaining`).
3. **If the base combo's remainder is `<= ceiling`**: unchanged behavior — build the `Plan` with a
   small non-negative `adjustmentCents`, same as today.
4. **If the base combo's remainder is `> ceiling`** (today's throw condition): call a new private
   method, `bridgeGap(array $pool, array $lines, int $remaining): array`:
   - Search the **full pool** (not just unused entries) for the candidate with the smallest
     `priceCents` that is `>= $remaining`. Tie-break by lowest `reconciliationNumber` (consistent
     with the rotation-fairness goal — among equally-priced bridging candidates, prefer the
     least-recently-used one).
   - If no single product's price covers `$remaining` alone (i.e. every product is cheaper than
     the gap), fall back to the **cheapest product in the whole pool** (smallest `priceCents`,
     tie-broken the same way) with `qty = intdiv($remaining, $priceCents) + 1` (ceiling division for
     positive integers). Using the cheapest available product for this repeated-qty case minimizes
     the worst-case overshoot, since overshoot is bounded by `priceCents - 1`.
   - Add the resulting qty to `$lines`, incrementing the existing entry if that SKU is already
     present (same `$lines[$sku]` keyed-array pattern `greedyPass` already uses) rather than adding
     a duplicate line.
   - Compute `$newRemaining = $remaining - ($qty * $priceCents)`, which is now `<= 0` (an
     overshoot). Return the updated `$lines` and `$newRemaining`.
5. `plan()` uses `bridgeGap()`'s returned `$lines` and `$newRemaining` (now `<= 0`) as
   `adjustmentCents` directly — a negative value is a discount that exactly cancels the overshoot,
   landing the net total exactly on the original target. No new ceiling or config governs this
   step; it always succeeds given a non-empty pool, per the accepted tradeoff below.
6. `ReconciliationFailedException` is only thrown when the pool itself is empty (existing check,
   unchanged, happens before any of this runs).

This also covers the degenerate case where `greedyPass` selects **zero** lines at all (every
product in the pool is pricier than the entire target): `$lines = []`, `$remaining = $targetCents`
(the full target). `bridgeGap()` doesn't special-case this — since every pool product's price is
`>= $remaining` in that scenario, the "smallest price `>= remaining`" search naturally resolves to
the single cheapest product in the pool, added as one line, discounted down to the original
target.

### Accepted tradeoff: uncapped discount

There is no ceiling on how large the resulting discount can be. A courier order with a very small
total against a catalog with no cheap eligible products could produce a plan where the discount is
much larger in magnitude than the order total itself (e.g. a 5 UAH order bridged by a 100 UAH
product, discounted by 95 UAH). This is an accepted consequence of "always succeed rather than
fail closed," not a defect. No new configuration is introduced to cap it; if discount magnitude
becomes an operational concern later, a `max_floating_discount_kopecks`-style config (mirroring
`adjustment_ceiling_kopecks`) is the natural follow-up lever — out of scope here.

### Downstream rendering changes

Four call sites currently drop the adjustment entirely when it is not strictly positive. Each
changes its guard to only skip when the adjustment is exactly zero, so a negative value (discount)
renders using the same total line as a positive value (top-up) always has:

1. **`CourierReconciliationAdjustment::collect()`** — guard becomes `if ($adjustment === 0.0)`.
   `$total->setGrandTotal($total->getGrandTotal() + $adjustment)` already works correctly for a
   negative `$adjustment` (adding a negative number subtracts) — no other change needed.
2. **`CourierReconciliationAdjustment::fetch()`** — same guard change, so the totals-summary API
   response includes the negative line.
3. **`CopyReconciliationAdjustmentToOrder` observer** — same guard change, so the discount is
   copied from quote to order at conversion instead of being dropped.
4. **`Totals` block (order view)** — same guard change, so the order-view totals table renders the
   negative line via the existing `shipping`-area `addTotal()` call.

No label branching between "adjustment" and "discount" — a single "Courier Reconciliation
Adjustment" line renders a signed amount in all four places, per the decision to keep this a
minimal, uniform change.

**Verified safe**: this reuses Magento's standard currency-formatting pipeline
(`Totals::formatValue()` → `Order::formatPrice()`/`formatPriceTxt()` →
`Currency::formatPrecision()`/`formatTxt()`), the exact same pipeline core's own
`Magento\SalesRule\Model\Quote\Discount` total uses — core also stores its discount amount as
negative (`$address->setDiscountAmount(-$discountAmount)`) and passes it straight through with no
template-level negation. No double-negative or malformed-currency risk. The PDF invoice renderer
(`Order\Pdf\Total\DefaultTotal`) goes through the same `Currency` formatter and only prepends an
`amount_prefix` string if one is explicitly configured, which this module does not do. Separately
noted: this module only wires the total into the `quote > totals` section (`sales.xml`), not into
an order/PDF totals section, so the PDF invoice does not render this line item today regardless of
sign — pre-existing behavior, unrelated to and unaffected by this change.

## Data Flow

1. `Planner::plan()` runs `greedyPass`, optionally `boundedSearch`, and picks the smaller-remainder
   result as the base combo.
2. If the base combo's remainder is within `ceiling`, `plan()` builds the `Plan` as it does today.
3. Otherwise `bridgeGap()` adds one bridging product (new line or incremented existing line) so the
   lines overshoot the target, and returns a negative remainder.
4. `plan()` builds the `Plan` with the (possibly negative) remainder as `adjustmentCents`.
5. `CartBuilder` → `QuoteItemAdder` adds all `Plan` lines (bridging line included) to the guest
   quote exactly as any other line — no special casing needed there.
6. `QuoteFinalizer` sets `courier_reconciliation_adjustment` from `adjustmentCents` (now possibly
   negative) and calls `collectTotals()`.
7. `CourierReconciliationAdjustment` folds the (possibly negative) adjustment into the grand total
   and totals-summary.
8. On order placement, `CopyReconciliationAdjustmentToOrder` copies the (possibly negative) value
   onto the order entity.
9. Order view's `Totals` block renders the (possibly negative) line in the order totals table.
10. `ReconciliationUsageRecorder` (existing, unchanged) increments `reconciliation_number` for
    every distinct SKU in the final plan's lines, including the bridging SKU — it already operates
    on `Plan::getLines()` without caring how those lines were chosen.

## Error Handling

- `ReconciliationFailedException` is now only reachable when `EligibleProductPool::getPool()`
  returns an empty pool (existing check, unchanged).
- `bridgeGap()` has no failure path of its own: given a non-empty pool (guaranteed by the point
  it's called), a bridging candidate always exists, and integer division with `+ 1` always
  produces a `qty >= 1`.
- No changes to `ReconciliationUsageRecorder`'s existing try/catch/swallow error handling.

## Testing

- **New `PlannerTest`** (no test file exists for `Planner` today):
  - Under-shoot within ceiling → unchanged plan, non-negative `adjustmentCents` (regression
    coverage for existing behavior).
  - Under-shoot beyond ceiling, pool has a product covering the gap in 1 unit → bridging line
    added, negative `adjustmentCents` exactly cancels the overshoot.
  - Gap bigger than any single product's price → cheapest pool product repeated
    `intdiv($gap, $price) + 1` times, negative `adjustmentCents`.
  - Zero lines chosen at all (every product pricier than target) → single cheapest-pool-product
    line, negative `adjustmentCents` covering the full original target.
  - Tie-break: two candidate products at the same bridging price, differing
    `reconciliationNumber` → lower rotation number is chosen.
  - Bridging SKU already present in the base combo → existing line's qty is incremented, not
    duplicated.
  - Empty pool → still throws `ReconciliationFailedException` (unchanged).
- **`CourierReconciliationAdjustment`, `CopyReconciliationAdjustmentToOrder`, `Totals`**: no unit
  tests exist for these three classes today. Add basic unit tests alongside the guard change for
  each: zero adjustment → no-op (unchanged), positive adjustment → renders/copies (regression),
  negative adjustment → renders/copies (new).

## Out of Scope

- Capping discount magnitude via a new config value (see "Accepted tradeoff" above).
- Any label/UX distinction between a top-up adjustment and a discount — both render as the same
  signed "Courier Reconciliation Adjustment" line.
- Wiring `courier_reconciliation_adjustment` into an order/PDF totals section — it is not wired
  there today and this change does not add it.
- Changing `ReconciliationUsageRecorder`'s behavior — it already operates generically on
  `Plan::getLines()`.
