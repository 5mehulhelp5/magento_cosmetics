# Order Intake — Implementation Plan

**Spec:** `docs/superpowers/specs/2026-09-03-order-intake-design.md` (approved)
**Module:** `Uho_OrderIntake` (`app/code/Uho/OrderIntake/`)

Each phase is independently verifiable. Do them in order — later phases depend on earlier ones.
Run `warden env exec -T php-fpm bin/magento setup:upgrade` after any phase that adds/changes
`db_schema.xml`, `di.xml`, `webapi.xml`, `acl.xml`, or `crontab.xml` (see the `warden` skill for
verified command forms — do not use the `warden magento`/`warden composer` shorthand).

---

## Phase 0 — Module scaffolding

**Files:**
- `app/code/Uho/OrderIntake/registration.php`
- `app/code/Uho/OrderIntake/etc/module.xml`

**module.xml sequence:** `Magento_Sales`, `Magento_Catalog`, `Magento_Directory`, `Magento_Webapi`,
`Uho_NovaposhtaShipping` (needed for `NovaposhtaManual::CARRIER_CODE`/`METHOD_CODE` constants in
Phase 5).

**Verify:** `warden env exec -T php-fpm bin/magento module:status Uho_OrderIntake` shows it enabled
after `setup:upgrade`.

---

## Phase 1 — Data layer: `uho_order_intake` table

**Files:**
- `app/code/Uho/OrderIntake/etc/db_schema.xml` — table per spec §3 (`entity_id` PK,
  `tracking_number` with a unique index/constraint, `status` with a default of `pending`,
  `order_id` nullable, `error_message` nullable text, `created_at`/`updated_at`).
- `app/code/Uho/OrderIntake/etc/db_schema_whitelist.json` — generate via
  `warden env exec -T php-fpm bin/magento setup:db-declaration:generate-whitelist Uho_OrderIntake`.
- `app/code/Uho/OrderIntake/Api/Data/OrderIntakeInterface.php` — getters/setters for all columns.
- `app/code/Uho/OrderIntake/Model/OrderIntake.php` — extends `AbstractModel`, implements the
  interface.
- `app/code/Uho/OrderIntake/Model/ResourceModel/OrderIntake.php` — extends `AbstractDb`.
- `app/code/Uho/OrderIntake/Model/ResourceModel/OrderIntake/Collection.php`.
- `app/code/Uho/OrderIntake/etc/di.xml` — preference for `OrderIntakeInterface` → `OrderIntake`.

**Verify:**
`warden env exec -T php-fpm bin/magento setup:upgrade` runs clean; confirm the table exists and the
unique index is on `tracking_number` via the `local-database-access` skill (`SHOW CREATE TABLE
uho_order_intake`).

---

## Phase 2 — Placeholder products

**Files:**
- `app/code/Uho/OrderIntake/Setup/Patch/Data/CreatePlaceholderProducts.php` — data patch creating
  two simple products, SKUs `order-misc-1` and `order-misc-2`, visibility "Not Visible
  Individually", status enabled, no category assignment, price `0.00` (always overridden per-order
  in Phase 5).

**Verify:** After `setup:upgrade`, confirm both SKUs exist:
`warden env exec -T php-fpm bin/magento catalog:product:show --sku order-misc-1` (or equivalent
DB check via `local-database-access`).

---

## Phase 3 — GetOrderProduct stub service

**Files:**
- `app/code/Uho/OrderIntake/Api/GetOrderProductInterface.php` —
  `execute(string $storeCode, int $orderTotal): array`, with a docblock noting this is a stub
  pending real catalog product mapping (spec §2).
- `app/code/Uho/OrderIntake/Model/GetOrderProduct.php` — returns `[]`.
- `di.xml` preference for the interface.

**Verify:** Unit test asserting `execute()` returns `[]` for arbitrary inputs (belongs in Phase 6
but can be written now since the class is trivial).

---

## Phase 4 — API endpoint

**Files:**
- `app/code/Uho/OrderIntake/Api/OrderIntakeManagementInterface.php` — `place()` method per spec §4
  (scalar args: `storeCode`, `total`, `customerName`, `phone`, `city`, `deliveryMethod` nullable,
  `trackingNumber`).
- `app/code/Uho/OrderIntake/Model/OrderIntakeManagement.php` — implements validation (required
  fields, store-code resolution via `StoreRepositoryInterface`, duplicate `trackingNumber` check
  against the resource model) and saves the row with `status = pending`.
- `app/code/Uho/OrderIntake/etc/webapi.xml` — `POST /V1/order-intake/orders` → `place()`, ACL
  resource `Uho_OrderIntake::orders`.
- `app/code/Uho/OrderIntake/etc/acl.xml` — declares `Uho_OrderIntake::orders` under
  `Magento_Backend::admin`.
- `app/code/Uho/OrderIntake/etc/di.xml` — preference for
  `OrderIntakeManagementInterface`.

**Verify:**
1. `warden env exec -T php-fpm bin/magento setup:upgrade` (registers the ACL resource).
2. Create an integration/admin token, then `curl -X POST https://legal.test/rest/V1/order-intake/orders`
   with the reference payload — confirm 200 and a new `pending` row.
3. Repeat with the same `trackingNumber` — confirm rejection, no second row.
4. Submit with a bogus `storeCode` — confirm rejection.

---

## Phase 5 — Cron job & order builder

**Files:**
- `app/code/Uho/OrderIntake/etc/crontab.xml` — job code `uho_orderintake_process_orders`, schedule
  `*/5 * * * *`.
- `app/code/Uho/OrderIntake/Cron/ProcessOrders.php` — fetches up to 50 `pending` rows, delegates
  per-row work to the builder below, updates row status/`order_id`/`error_message`, catches and
  logs per-row exceptions without aborting the batch (spec §6).
- `app/code/Uho/OrderIntake/Model/OrderBuilder.php` — the core logic from spec §6:
  - Resolves items via `GetOrderProductInterface`; falls back to the two-placeholder-product split
    when empty (extract the random whole-unit split into a small dedicated method/class,
    e.g. `Model/TotalSplitter.php`, so it's independently unit-testable).
  - Builds the guest customer identity (synthesized email, split name).
  - Builds the placeholder billing/shipping address (flagged inline as pending the future NP
    address-lookup service).
  - Constructs the `Order` directly via `OrderFactory`/`OrderRepository` — **no `Quote` object is
    created or submitted**, per spec §5 (this is what keeps
    `SalesModelServiceQuoteSubmitBefore`'s warehouse-ref guard out of this path — verify no
    `sales_model_service_quote_submit_before` event fires during this flow).
  - Sets `shipping_method` from `NovaposhtaManual::CARRIER_CODE . '_' . NovaposhtaManual::METHOD_CODE`,
    `shipping_amount = 0`, payment method `cashondelivery`.
  - Creates the invoice (`InvoiceService` + `Transaction`, offline capture) and shipment
    (`ShipmentFactory` + one track: carrier `uho_novaposhta`, track number = row's
    `tracking_number`).
  - Explicitly sets order state/status to `complete` and saves.
  - Returns the new order's ID to the cron for row bookkeeping.

**Verify:**
1. POST a test row via the Phase 4 endpoint.
2. Run the cron manually: `warden env exec -T php-fpm bin/magento cron:run` (or invoke the job
   directly per the `warden` skill's guidance for testing a single cron job).
3. Confirm via `local-database-access`: the intake row is `complete` with a populated `order_id`;
   the corresponding sales order is state/status `complete`, has one invoice (fully invoiced), one
   shipment with the correct track number, and grand total equal to the original payload `total`.
4. Force a failure case (e.g. a row with an unresolvable store) and confirm the row ends up
   `error` with a message, and the batch continues to other rows.

---

## Phase 6 — Automated tests

Use the `phpunit` skill. Cover:
- `OrderIntakeManagement`: required-field validation, duplicate-`trackingNumber` rejection,
  unknown-`storeCode` rejection, happy-path save.
- `TotalSplitter` (or equivalent): both parts `> 0`, both whole units, sum equals input total,
  across a range of totals including small edge cases (e.g. total = 1, total = 2).
- `GetOrderProduct`: returns `[]`.
- `Cron\ProcessOrders`: processes a batch, marks rows `complete`/`error` appropriately, continues
  past a failing row (mock `OrderBuilder` to throw for one row in a batch of several).

**Verify:** `warden shell -c "vendor/bin/phpunit --testsuite unit --filter OrderIntake"` (adjust
filter/testsuite per the `phpunit` skill's actual invocation) passes clean.

---

## Phase 7 — Module documentation

Use the `module-documentation` skill to generate `app/code/Uho/OrderIntake/docs/README.md` and
register the module in the central `docs/modules/README.md` index, per this repo's existing
convention (see `app/code/Uho/NovaposhtaCheckout/docs/`).

---

## Explicit deferred work (not part of this plan)

- Real product mapping in `GetOrderProduct` (spec §2).
- Real Nova Poshta warehouse/address resolution replacing the placeholder address (spec §2, §6).
- Admin grid for `uho_order_intake` rows (spec §3) — only if this becomes a recurring operational
  need.
- Retry logic for `error` rows (spec §6) — currently manual requeue only.
