# Module Registry

> Central index of documented custom modules (`app/code/Uho/`). Keep this file updated when adding
> or significantly changing a module's documentation.

Last updated: 2026-09-03

## Modules

| Module | Version | Last Updated | Description | Author | Environments |
|--------|---------|---------------|--------------|--------|---------------|
| [Uho_NovaposhtaCheckout](../../app/code/Uho/NovaposhtaCheckout/docs/novaposhta-checkout-architecture.md) | @dev | 2026-09-01 | Nova Poshta-only checkout — Nova Poshta is the sole shipping method, auto-selected, with server-composed addresses from customer-selected city/warehouse | Uho | All |
| [Uho_OrderIntake](../../app/code/Uho/OrderIntake/docs/README.md) | 1.0.0 | 2026-09-03 | REST intake endpoint + cron that turns external order payloads into complete, invoiced, shipped Magento orders without going through the Quote pipeline | Uho | All (currently exercised on `pr_ua`) |

## Other custom modules without dedicated documentation

`app/code/Uho/Catalog`, `app/code/Uho/HomeContent`, `app/code/Uho/NovaposhtaShipping`, and
`app/code/Uho/Store` do not yet have a `docs/` directory. Add an entry above when one is created.

**Rules for the registry:**
- Sort alphabetically by module name
- Link to the module's documentation using a relative path from this file
- Version from `composer.json` when the module has one, otherwise `@dev`/an internal version number
- Last Updated = date of the last significant documentation change (from git log or current date)
- Description = one line summarizing the module's purpose
- Author = team/vendor name
- Environments = which environments/stores use this module (`All`, or comma-separated codes)
