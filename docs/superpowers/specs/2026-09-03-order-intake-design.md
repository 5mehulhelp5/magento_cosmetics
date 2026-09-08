# Order Intake — External Order Ingestion API & Cron

**Status:** Design approved, not yet implemented.
**Date:** 2026-09-03
**New module:** `Uho_OrderIntake` (`app/code/Uho/OrderIntake/`)
**Relates to:** [[project_prorostok_seed_store]] context (store `pr_ua`), and the Nova Poshta
carrier/checkout guard in `app/code/Uho/NovaposhtaCheckout/Observer/SalesModelServiceQuoteSubmitBefore.php`
(see §5 for why this feature deliberately does not go through that guard).

## 1. Problem

An external system needs to hand off orders to Magento in bulk, using a payload shaped like:

```json
{
  "storeCode": "pr_ua",
  "total": 350,
  "customerName": "Іванова Марія",
  "phone": "+380671234567",
  "city": "Київ",
  "deliveryMethod": "самовивіз",
  "trackingNumber": "20450123456789"
}
```

These need to become real Magento orders — shipped via Nova Poshta pickup, paid cash-on-delivery,
invoiced, shipped, and moved to `complete` status — without the external system having to know
anything about Magento's checkout internals (quotes, shipping rate collection, catalog product
IDs). The two systems only need to agree on this flat JSON shape and a REST endpoint.

The endpoint accepts and stores rows synchronously; a cron job processes them in the background and
performs the actual Magento order creation.

## 2. Explicit non-goals for this iteration

Two pieces of real business logic are intentionally stubbed out and left for future work — both are
called out inline in the code with `@todo`-style docblocks, not silently guessed at:

- **Real product mapping.** `GetOrderProductInterface::execute()` should eventually look up real
  catalog products for a store that sum to the order total. For now it returns `[]`, and the cron
  falls back to two placeholder line items (§6).
- **Real Nova Poshta warehouse/address resolution.** The payload only carries a city name, not a
  street, warehouse ref, or postcode. A future service should call the Nova Poshta API to resolve
  these from `city` (and possibly `trackingNumber`). For now, the order's address uses fixed
  placeholder values (§6).

## 3. Data layer

New table `uho_order_intake`:

| Column | Type | Notes |
|---|---|---|
| `entity_id` | int, PK, auto-increment | |
| `store_code` | varchar(32) | e.g. `pr_ua` |
| `total` | decimal(12,4) | order total from payload |
| `customer_name` | varchar(255) | |
| `phone` | varchar(32) | |
| `city` | varchar(255) | |
| `delivery_method` | varchar(64), nullable | stored for reference; not used to branch logic yet — the cron always creates a Nova Poshta pickup order regardless of this value |
| `tracking_number` | varchar(64), **unique** | dedup key at the endpoint; also becomes the shipment track number the cron creates |
| `status` | varchar(16) | `pending` \| `complete` \| `error` |
| `order_id` | int unsigned, nullable | set once the Magento order is created |
| `error_message` | text, nullable | set when `status = error` |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

`orderNumber` and `items` from the reference payload used in the original request are **not**
part of this schema:
- `orderNumber` is dropped — the real reference payload (confirmed with the requester) omits it,
  and `trackingNumber` is the actual dedup key.
- `items` is dropped per explicit instruction — real product data is out of scope for this
  iteration (§2).

No admin grid UI. Rows are inspected via direct DB query when needed; a grid can be added later if
this becomes a routine operational need.

## 4. API endpoint

- **Route:** `POST /V1/order-intake/orders`
- **Auth:** New ACL resource `Uho_OrderIntake::orders`. Requires an admin-issued integration token
  (server-to-server, trusted caller) — not anonymous.
- **Service contract:** `Uho\OrderIntake\Api\OrderIntakeManagementInterface::place(...)`, taking
  the payload fields as scalar arguments (exact signature finalized during implementation, matching
  Magento's webapi.xml → service contract conventions).

Validation on save:
- Required: `storeCode`, `total`, `customerName`, `phone`, `city`, `trackingNumber`.
- `deliveryMethod` optional, stored as-is if present.
- `storeCode` must resolve to an existing store view, else the request is rejected with a
  validation exception.
- `trackingNumber` must not already exist in `uho_order_intake`. A duplicate is rejected with a
  validation exception and **nothing is saved** — this is the dedup point, not the cron.

On success: a row is saved with `status = pending`, `order_id = null`. The endpoint does not create
the Magento order synchronously.

## 5. Order creation strategy — direct Order construction, not Quote submission

This is the key architectural decision, and it's driven by an existing safeguard in this codebase.

`Uho\NovaposhtaCheckout\Observer\SalesModelServiceQuoteSubmitBefore` is a fail-closed guard on the
`sales_model_service_quote_submit_before` event: if a quote's shipping address uses the
`uho_novaposhta` carrier but has no `uho_np_warehouse_ref`, it throws and blocks order placement.
Its own docblock names exactly this scenario as a risk it's watching for: *"a future integration
that bypasses the webapi service contracts."*

We don't have a real warehouse ref yet (§2), so going through the normal
`Quote` → `QuoteManagement::submit()` pipeline would always trip this guard and never place an
order.

**Decision:** the cron builds the Sales Order **directly** — `OrderFactory` + `OrderRepository` (or
equivalent), never constructing or submitting a `Quote`. This is a deliberate, explicit bypass of
the checkout pipeline for this batch-import path specifically, not an accidental gap:
`sales_model_service_quote_submit_before` only fires on quote submission, so it structurally cannot
fire here. This keeps the guard fully intact for its actual purpose — protecting real customer
checkout and admin order creation, both of which do go through a `Quote`.

This also means none of the standard checkout-time plugins/observers that operate on the quote
(e.g. `Uho\NovaposhtaCheckout\Plugin`) run for these orders. That's expected: those exist to compose
a real warehouse address from customer-selected data that doesn't exist in this payload yet.

## 6. Cron job

- **Job code:** `uho_orderintake_process_orders`
- **Schedule:** every 5 minutes
- **Batch size:** up to 50 `pending` rows per run

Per row:

1. Call `GetOrderProductInterface::execute($storeCode, (int) $total)`.
2. Since this returns `[]` today, fall back to two placeholder line items:
   - Two dedicated placeholder products, `order-misc-1` and `order-misc-2` — new simple products
     created via a data patch, disabled from catalog search and visibility (not purchasable through
     the storefront).
   - `total` is split into two whole-currency-unit parts, each `> 0`, summing exactly to `total`
     (e.g. a random cut point between 1 and `total - 1`). Qty 1 each; item price = each part.
   - If `GetOrderProductInterface::execute()` ever returns a non-empty list in the future, that list
     is used instead and this fallback is skipped — the fallback only exists to keep this cron
     functional while that service is unimplemented.
3. Build the order directly (§5):
   - Guest customer. Email synthesized from phone, e.g. `380671234567@pr-ua.orders`. First/last
     name split from `customerName` on the first space (whole string as first name if no space).
   - Billing = shipping address: `country_id = UA`, `city` from the payload, placeholder
     `street`/`postcode`/`region` values (flagged inline as pending the future NP address-lookup
     service from §2), `telephone` from the payload.
   - `shipping_method`: the Nova Poshta manual carrier's pickup code
     (`Uho\NovaposhtaShipping\Model\Carrier\NovaposhtaManual::CARRIER_CODE . '_' . NovaposhtaManual::METHOD_CODE`),
     `shipping_amount = 0`.
   - Payment method `cashondelivery`.
   - `subtotal` = `grand_total` = sum of the line items = `total` (no tax, no shipping charge
     added).
4. Create an **invoice** (offline, appropriate for `cashondelivery`) covering all items.
5. Create a **shipment** with one track: carrier `uho_novaposhta`, track number = the row's
   `tracking_number`.
6. Explicitly set the order's state and status to `complete` and save — not relying on implicit
   recalculation, since this path bypasses the pipeline that normally drives that transition.
7. Update the intake row: `status = complete`, `order_id = <new order id>`.

**On failure** (any exception in steps 3–6): the row is set to `status = error` with
`error_message` populated from the exception, and the cron continues to the next row in the batch.
No automatic retry — a failed row stays `error` until someone investigates and requeues it manually
(e.g. by resetting its status directly in the DB).

## 7. Testing

- Unit tests for: payload validation (missing fields, duplicate `trackingNumber`, unknown
  `storeCode`), the random total-split fallback (always sums to total, both parts `> 0`, whole
  units), and the cron's per-row success/failure bookkeeping.
- No storefront/UI testing needed — this is a pure backend integration surface.
