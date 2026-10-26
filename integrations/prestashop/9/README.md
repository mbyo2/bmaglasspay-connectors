# BMaGlass Pay for PrestaShop 9

Payment module for **PrestaShop 9.x** (9.2 current; Symfony 6.4 LTS
core; PHP 8.1–8.5). For PrestaShop 1.7 or 8 use the matching
folder — see [`../README.md`](../README.md) and the repo's
[`VERSIONS.md`](../../../VERSIONS.md).

## Install (PrestaShop 9.x)

1. Copy `bmaglasspay/` into `modules/`, or zip the folder and use
   Module Manager → Upload a module.
2. Install **BMaGlass Pay** and open its configuration:
   - **API key** — create one in BMaGlass Pay (Business settings or
     the API page) with the `wallets:read`, `payment_links:read`
     and `payment_links:write` scopes. A **live key** is required
     for checkout; a test key only passes the connection check.
   - Register the shown webhook URL in BMaGlass Pay and paste the
     **webhook signing secret** (shown once).
3. Use **Test connection** (shows your wallets) and, after real
   orders, **Check pending orders** if a webhook delivery was
   missed.
4. Set the shop currency to **ZMW or USD** — the payment option
   only appears for currencies BMaGlass wallets hold. The module
   works with PrestaShop 9's one-page checkout: it integrates
   through the standard `paymentOptions` hook, which is the
   integration point 9.x documents for payment modules.

## How it works — and what differs from the 1.7/8 builds

Checkout and settlement are identical to the other variants: one
payment link per order, hosted BMaGlass page, signed
`payment_link.paid` webhook completing the order immediately
(API-confirmed), reconciliation fallback, exactly-once.

The genuine 9.x differences:

- `ps_versions_compliancy` declares **9.0.0.0 – 9.9999.9999**.
- **PHP >= 8.1 floor**, declared in the module's `composer.json`
  (`type: prestashop-module`) — PrestaShop 9 dropped the PHP 7
  platform (Symfony 6.4 core), so this build ships no PHP 7
  compatibility shims and is not installable on a PHP 7 stack.
- The payment API used (`paymentOptions` hook,
  `ModuleFrontController`, `validateOrder`) is the integration
  surface PrestaShop 9 documents for payment modules; it did not
  change from 8.x, so the logic is shared with the other builds
  rather than rewritten.

## Verification status (honest boundary)

`php -l` clean on every file; the vendored client in
`classes/sdk/` is byte-identical to the PHP SDK
(`integrations/check-vendored-clients.sh`), whose own live-API
protocol proof passed. **No PrestaShop runtime exists in the build
environment**, so install-level behaviour is written to the
documented 9.x module API and not install-proven — smoke-test on a
staging shop first.
