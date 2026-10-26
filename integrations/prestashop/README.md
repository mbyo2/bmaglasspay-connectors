# BMaGlass Pay for PrestaShop — per-version modules

Accept BMaGlass Pay in PrestaShop. Buyers pay on BMaGlass's hosted
payment page from their BMaGlass wallet; the money lands in the
**same BMaGlass account and wallets**. The API client is the PHP
SDK (`sdk/php`), vendored **verbatim** in each variant's
`bmaglasspay/classes/sdk/` (byte-identical — verified by
`integrations/check-vendored-clients.sh`).

One build per PrestaShop major — pick yours:

| Folder | PrestaShop | PHP | Upstream status (Oct 2026) |
|---|---|---|---|
| [`1.7/`](1.7/) | 1.7.x | 7.2–7.4 | EOL Feb 2026; very large installed base |
| [`8/`](8/) | 8.x (8.2.x) | 7.2.5–8.1 | Security fixes until PrestaShop 10 |
| [`9/`](9/) | 9.x (9.2) | 8.1–8.5 | Current (Symfony 6.4 core) |

Full landscape and sources: [`VERSIONS.md`](../../VERSIONS.md).

## Shared behaviour (every variant)

- Checkout (live key + ZMW/USD store currency only; otherwise the
  option hides rather than faking a sandbox or inventing an FX
  rate): the order is created in "Awaiting BMaGlass payment", one
  payment link is created for the exact total, and the buyer is
  redirected to the hosted page.
- Settlement is **push-first**: the HMAC-verified webhook settles
  immediately on `payment_link.paid` (the paid link's order is
  confirmed via the API and marked paid); the module configuration
  page's "Check pending orders" reconciliation is the fallback.
  Orders complete **exactly once**.
- Wallet-to-wallet only (buyers need a BMaGlass account). Test
  keys are read-only by design: connection checks only.
- PrestaShop has no built-in cron: for hands-off operation, hit
  the module's reconcile action from your server cron, or rely on
  webhooks + normal admin visits.

## What actually differs between variants

The payment API used (`paymentOptions` hook,
`ModuleFrontController`, `validateOrder`) is the documented
integration surface on all three majors — the logic is shared.
What genuinely splits is the **declared platform**: each build
declares its own `ps_versions_compliancy` (previously undeclared),
its PHP floor, and the 9.x build adds a module `composer.json`
with a PHP >= 8.1 requirement matching PrestaShop 9's Symfony 6.4
platform. Per-variant details are in each folder's README.

## Verification status

`php -l` clean on every file in every variant; vendored clients
byte-identical to the PHP SDK client, whose live-API protocol
proof passed in the original programme. **Install-tested 2026-10-26: PASS on PrestaShop 9.0.0, 8.1.7 and 1.7.8.11** — genuine guest checkouts → hosted pay page → signed webhook settlement to "Payment accepted" with exactly one payment booked per order.
