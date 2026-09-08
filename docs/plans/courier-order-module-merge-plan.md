# Plan: Merge `Uho_CourierOrderApi` + `Uho_CourierOrderProcessor` into `Uho_CourierOrder`

> **Audience:** This document is written to be executed by an implementation agent (e.g. `@magento-implementation` or a general-purpose coding agent) with no prior context beyond this file and the repository itself. Follow the steps in order. Do not skip the verification steps. Do not delete the old modules until Step 10's checklist passes.

## Status
- [ ] Not started

## Context / Why

Two existing modules implement a single courier-order intake → processing workflow, split across module boundaries for architectural purity rather than genuine reuse:

- `app/code/Uho/CourierOrderApi` — intake: validates payload, checks idempotency, resolves address, builds a reconciliation plan, persists `uho_courier_order_request`, publishes MQ topic `uho.courier.order.request.created`, exposes `POST /V1/courier-orders`.
- `app/code/Uho/CourierOrderProcessor` — execution: consumes that topic, claims/retries requests, builds a guest quote, places order, creates invoice/shipment, adds reconciliation totals to the order, admin grid + requeue UI, cron recovery jobs.

**Coupling evidence (why these must be one module):**
- `app/code/Uho/CourierOrderProcessor/etc/module.xml:6` hard-declares a dependency on `Uho_CourierOrderApi`.
- Processor imports Api internals directly (not just its Web API contract) in:
  - `Model/Consumer/CourierOrderRequestConsumer.php:10-12,30-35`
  - `Model/Cart/CartBuilder.php:14-15,54`
  - `Model/Cart/GuestAddressAssembler.php:7-8,20-21`
  - `Controller/Adminhtml/CourierOrderRequest/MassRequeue.php:13-16,37`
  - `Ui/Component/Listing/DataProvider.php:9,23`
  - `Model/Source/RequestStatusOptions.php:8`
  - `Model/Status/RequestStatusManager.php:9`
- ACL is cross-module: `CourierOrderProcessor/etc/acl.xml:7-9` nests processor permissions under `Uho_CourierOrderApi::courier`.
- Queue ownership is split for the *same* message: Api owns topic/publisher (`CourierOrderApi/etc/communication.xml:4-5`, `etc/queue_publisher.xml:4-5`), Processor owns queue/consumer/topology (`CourierOrderProcessor/etc/queue.xml:5-6`, `etc/queue_consumer.xml:7`, `etc/queue_topology.xml:5-8`).
- The Api-owned DB table already stores processor lifecycle state (`CourierOrderApi/etc/db_schema.xml:26-38`: `reconciliation_plan`, `status`, `attempts`, `next_retry_at`, `claimed_at`, `failure_reason`, `order_increment_id`) — it is not a clean "API" boundary, it's shared internal state.
- Repo-wide search found **no third custom module** in `app/code` consuming `Uho\CourierOrderApi\*` besides `CourierOrderProcessor` itself.

**Conclusion:** `CourierOrderProcessor` is unusable without `CourierOrderApi`, and `CourierOrderApi` is operationally incomplete without `CourierOrderProcessor`. Merge them.

## Over-Architecture Findings To Fix During Merge

- **Public repository for internal row state** — `CourierOrderApi/Api/CourierOrderRequestRepositoryInterface.php`, `Model/CourierOrderRequestRepository.php`, `Api/Data/CourierOrderRequestRecordInterface.php`, `Model/CourierOrderRequestRecord.php`. Only ever consumed inside this workflow. Demote to a plain internal `Model` class; delete the interfaces.
- **Single-implementation interfaces with no real polymorphism / no external consumer** (delete or fold into concrete classes):
  - `Api/CourierOrderResolverInterface.php` → `Model/Resolver/AddressResolver.php`
  - `Api/ReconciliationPlannerInterface.php` → `Model/Reconciliation/ReconciliationPlanner.php`
  - `Api/Data/ResolvedAddressInterface.php` → `Model/Data/ResolvedAddress.php`
  - `Api/Data/ReconciliationPlanInterface.php` / `ReconciliationPlanLineInterface.php`
  - `Api/Data/CourierOrderQueueMessageInterface.php`
- **Dead/unused fields:**
  - Persisted `reconciliation_plan` column (`CourierOrderApi/etc/db_schema.xml:26`, written in `CourierOrderManagement.php:61,102-123`) is recalculated later anyway in `CourierOrderProcessor/Model/Cart/CartBuilder.php:54` — no runtime code reads it back. Treat as audit-only or remove.
  - `comment`/`items` on `CourierOrderRequestInterface` (`Api/Data/CourierOrderRequestInterface.php:102-119`, `Model/Data/CourierOrderRequest.php:111-134`) — accepted but unused downstream.
  - `ResolvedAddressInterface::getWarehouseSiteKey()` — set in `AddressResolver.php:49` but never read.
  - `$storeId` parameter on `CourierOrderResolverInterface` (`Api/CourierOrderResolverInterface.php:16,24`, `AddressResolver.php:37`) — unused.
- **Purity-driven test caused a real code smell:** `CourierOrderApi/Test/Unit/Architecture/ModuleDependencyBoundaryTest.php` forced `Model/Idempotency/DuplicateChecker.php:56-64` to use raw SQL against the sales table just to avoid a "forbidden" dependency. Delete this test; let `DuplicateChecker` use normal Magento service contracts/collections once modules are merged.
- **Global store policy hidden in a feature module:** `CourierOrderApi/Setup/Patch/Data/DisableOrderConfirmationEmail.php` disables *all* new-order confirmation emails store-wide, not just for courier orders. This should not live in this module — see Step 8.

## SOLID / KISS Findings To Fix During Merge

- **SRP violation:** `CourierOrderProcessor/Model/Cart/CartBuilder.php:24-104` reprices, creates the quote, adds items, assembles addresses, assigns shipping/payment, sets custom totals, collects totals, and saves — all in one class. Split into focused collaborators (e.g. quote items, guest address building, quote finalization) while keeping `CartBuilder` as a thin orchestrator.
- **SRP violation:** `CourierOrderProcessor/Model/Consumer/CourierOrderRequestConsumer.php:28-116` is both the MQ adapter and the full application orchestrator. Extract a `RequestProcessor` service; make the consumer a thin adapter that calls it.
- **Status lifecycle bypassed:** `RequestStatusManager` is supposed to own status transitions, but `Controller/Adminhtml/CourierOrderRequest/MassRequeue.php:66-69` mutates status fields directly. Add a `requeue()` method to the lifecycle/status service and route all mutations through it.
- **Duplicated publish logic** across `CourierOrderApi/Model/CourierOrderManagement.php:126-130`, `CourierOrderProcessor/Model/Cron/RequeueRetryEligibleRequests.php:45-47`, and `MassRequeue.php:71-73`. Consolidate into one `RequestPublisher` service.
- **Unused config abstraction:** `CourierOrderProcessor/Model/Config.php` has no corresponding `system.xml`/`config.xml`. Either wire real admin config in the merged module, or replace it with fixed constants — do not keep unused indirection.
- **Performance smell (fix opportunistically, not blocking):** `CourierOrderApi/Model/Reconciliation/EligibleProductPool.php:61-67` does full product iteration with a per-product `StockRegistry` lookup (N+1). Consider batch stock lookup.

## Proposed Merged Module Structure

Module name: **`Uho_CourierOrder`**

```
app/code/Uho/CourierOrder/
├── Api/
│   ├── CourierOrderManagementInterface.php          # keep: Web API contract
│   └── Data/
│       ├── CourierOrderRequestInterface.php          # keep: Web API contract
│       ├── CourierOrderItemInterface.php             # keep: Web API contract
│       └── CourierOrderAcceptResultInterface.php      # keep: Web API contract
├── Block/Sales/Order/Totals.php
├── Controller/Adminhtml/CourierOrderRequest/{Index,MassRequeue}.php
├── Model/
│   ├── CourierOrderManagement.php
│   ├── Request/
│   │   ├── Record.php               # formerly CourierOrderRequestRecord (no public interface)
│   │   ├── ResourceModel.php
│   │   ├── Collection.php
│   │   ├── Lifecycle.php            # status transitions incl. requeue()
│   │   └── Publisher.php            # single place that publishes to the MQ topic
│   ├── Address/
│   │   ├── NameSplitter.php
│   │   ├── AddressResolver.php      # no public interface
│   │   └── ResolvedAddress.php      # plain data object, no public interface
│   ├── Reconciliation/
│   │   ├── EligibleProductPool.php
│   │   ├── Planner.php              # no public interface
│   │   ├── Plan.php
│   │   └── PlanLine.php
│   ├── Cart/{CartBuilder.php,GuestAddressAssembler.php,PaymentAssigner.php,ShippingAssigner.php}
│   ├── Order/{OrderPlacer.php,InvoiceCreator.php,ShipmentCreator.php}
│   ├── Cron/{ReclaimStuckRequests.php,RequeueRetryEligibleRequests.php}
│   ├── Consumer/CourierOrderRequestConsumer.php   # thin adapter over Request/RequestProcessor
│   ├── RequestProcessor.php                        # extracted orchestration from the consumer
│   ├── Idempotency/DuplicateChecker.php            # uses normal service contracts, no raw SQL workaround
│   ├── Validator/PayloadValidator.php
│   ├── Source/RequestStatusOptions.php
│   └── Total/CourierReconciliationAdjustment.php
├── Observer/CopyReconciliationAdjustmentToOrder.php
├── Ui/Component/Listing/DataProvider.php
└── etc/
    ├── module.xml
    ├── acl.xml
    ├── webapi.xml
    ├── communication.xml
    ├── queue_publisher.xml
    ├── queue.xml
    ├── queue_consumer.xml
    ├── queue_topology.xml
    ├── crontab.xml
    ├── events.xml
    ├── sales.xml
    ├── db_schema.xml
    ├── db_schema_whitelist.json
    ├── adminhtml/{system.xml,routes.xml,menu.xml}
    └── ...
```

### Keep as public contract
- `CourierOrderManagementInterface`
- `Api/Data/CourierOrderRequestInterface`, `CourierOrderItemInterface`, `CourierOrderAcceptResultInterface`
- Everything these expose is the genuine external boundary (`POST /V1/courier-orders`).

### Simplify (internal refactor, not a contract change)
- `CourierOrderRequestConsumer` → delegate to new `RequestProcessor`.
- `CartBuilder` → keep as orchestrator, extract internal collaborators.
- All publish call sites → route through one `Request/Publisher`.
- All requeue/status-reset call sites → route through `Request/Lifecycle`.

### Delete outright
- `CourierOrderRequestRepositoryInterface`, `CourierOrderRequestRecordInterface`
- `CourierOrderResolverInterface`, `ReconciliationPlannerInterface`
- `CourierOrderQueueMessageInterface`, `ResolvedAddressInterface`
- `ReconciliationPlanInterface`, `ReconciliationPlanLineInterface`
- `ModuleDependencyBoundaryTest.php`
- `DisableOrderConfirmationEmail` data patch **from this module** (see Step 8 — decide where, if anywhere, this policy should live)

## Migration Steps (execute in order)

1. **Confirm there are no other consumers.**
   Search the whole repo (`app/code`, tests, any scripts) for `Uho\CourierOrderApi\` and `Uho\CourierOrderProcessor\` references outside these two modules. As of this plan's writing, none were found — re-verify before deleting anything, since code may have changed since.

2. **Freeze runtime identifiers.**
   Do not rename, in this pass: DB table/column names, the MQ topic name (`uho.courier.order.request.created`), queue/consumer names, the Web API route (`/V1/courier-orders`), or existing `system.xml` config paths. Renaming any of these is a breaking change to in-flight data/messages and is out of scope for this merge.

3. **Create the new module `app/code/Uho/CourierOrder`.**
   - New `etc/module.xml` declaring the union of both modules' external Magento dependencies (Sales, Quote, Checkout, Catalog, MessageQueue, etc. — read both existing `module.xml` files and combine `<sequence>` entries).
   - Do not add a `setup_version`; this codebase uses declarative schema (Magento 2.4.9) so it is not required.

4. **Move and merge XML configuration verbatim first, refactor after.**
   Copy (not rewrite) into the new module, preserving all existing attribute values (topic names, queue names, routes, ACL resource IDs, cron job codes, event names):
   - `webapi.xml`, `communication.xml`, `queue_publisher.xml`, `queue.xml`, `queue_consumer.xml`, `queue_topology.xml`
   - `crontab.xml`, `events.xml`, `sales.xml`
   - `adminhtml/routes.xml`, `adminhtml/menu.xml`, `adminhtml/system.xml` (if any)
   - UI component and layout XML files under `view/`
   - Merge both `db_schema.xml` files into one (combine table/column declarations exactly as they exist today — do not alter column types/names).
   - Merge both `db_schema_whitelist.json` files (union of entries).

5. **Preserve ACL resource IDs for at least one release.**
   Keep the existing resource ID strings (e.g. `Uho_CourierOrderApi::create`, `Uho_CourierOrderProcessor::grid`, `...::requeue`) inside the merged module's `acl.xml`, even though the module name changed, so existing admin roles and integration tokens are not silently revoked. Plan a follow-up cleanup release to rename these once confirmed safe.

6. **Move PHP classes into the new namespace/structure** per the "Proposed Merged Module Structure" tree above, updating `namespace` and `use` statements accordingly. Do this move before behavioral refactors so the diff is easy to review.

7. **Apply the "Over-Architecture Findings" deletions/demotions** listed above: remove the listed interfaces, inline single-implementation classes, remove `di.xml` preferences that only ever pointed one interface to one class with no other consumer, delete `ModuleDependencyBoundaryTest.php`.

8. **Apply the "SOLID / KISS Findings" refactors** listed above: extract `RequestProcessor` from the consumer, extract `Request/Publisher` and `Request/Lifecycle`, route `MassRequeue` and cron requeue through `Lifecycle::requeue()` instead of mutating fields directly, decide on `CourierOrderProcessor\Model\Config` (real `system.xml` fields or fixed constants).

9. **Explicitly resolve the global email patch.**
   Before deleting `DisableOrderConfirmationEmail`, confirm with the store owner/PM whether store-wide new-order email suppression is still required. If yes, move it to a general configuration change (admin config `sales_email/order/enabled=0`) or a separate, clearly-named module/patch — not inside the courier order module. Do not silently drop this behavior.

10. **Do not uninstall the old modules yet.** Leave `app/code/Uho/CourierOrderApi` and `app/code/Uho/CourierOrderProcessor` in place (or disabled) until the new module's `db_schema.xml` declares identical tables/columns and all checks in the "Verification Checklist" below pass. Only delete the old module directories after a successful production-equivalent verification.

11. **Regenerate and upgrade** (via Warden, per repo conventions):
    ```
    warden env exec -T php-fpm bin/magento setup:upgrade
    warden env exec -T php-fpm bin/magento setup:di:compile
    warden env exec -T php-fpm bin/magento cache:flush
    ```

12. **Run the Verification Checklist below.** Only after all items pass, remove the old module directories, run `setup:upgrade` again to clear their `module.xml` registration from `setup_module`, and clean up ACL/config duplication planned in step 5.

## Verification Checklist (must pass before deleting old modules)

- [ ] `setup:upgrade` runs clean with no schema errors.
- [ ] `setup:di:compile` succeeds with no missing-class errors.
- [ ] `POST /V1/courier-orders` accepts a valid test payload and returns the expected accept result.
- [ ] The MQ consumer picks up the published message and processes it end-to-end (quote → order → invoice → shipment).
- [ ] Admin grid (`uho_courier_order_request` listing) loads and displays existing + new rows.
- [ ] Admin "Mass Requeue" action correctly requeues a failed/stuck request via the lifecycle service (not direct field mutation).
- [ ] Cron jobs `ReclaimStuckRequests` and `RequeueRetryEligibleRequests` run without error and behave as before.
- [ ] Reconciliation adjustment total appears correctly on the resulting sales order (frontend + admin order view, guest and logged-in flows).
- [ ] Existing admin roles/integration tokens still have access (ACL resource IDs unchanged).
- [ ] No PHP code anywhere in `app/code` still references `Uho\CourierOrderApi\*` or `Uho\CourierOrderProcessor\*` namespaces.
- [ ] PHPCS/PHPStan run clean (or with no new warnings beyond pre-existing baseline).

## Risks / Things To Double-Check Before Deleting Anything

- **In-flight queue messages** published under the old message DTO FQCN before the cutover may fail to deserialize if the message class moves namespace. Either drain the queue before cutover or keep a compatibility alias class for one release.
- **ACL/resource ID changes** can silently strip admin permissions from existing roles — keep old resource ID strings (Step 5).
- **Config path changes** (if any config paths are renamed) will orphan previously stored admin config values — avoid renaming existing config paths in this pass.
- **Global email-disable behavior** must be explicitly decided, not silently dropped (Step 9).
- **Schema safety**: the merged module must declare the exact same tables/columns as today before the old modules are removed, so `declarative schema` diffing doesn't attempt to drop/recreate `uho_courier_order_request`.
- **External (off-repo) consumers**: no third custom module in this repo references `Uho\CourierOrderApi\*`, but if any external system (ERP, integration) references the PHP FQCNs directly (unlikely for FQCNs but possible for the HTTP route), keep the HTTP route and payload contract unchanged.
