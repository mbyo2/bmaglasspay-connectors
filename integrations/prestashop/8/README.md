# BMaGlass Pay for PrestaShop 8

Payment module for **PrestaShop 8.x** (8.2.x current; PHP 7.2.5–8.1,
8.1 recommended). For PrestaShop 1.7 or 9 use the matching folder —
see [`../README.md`](../README.md) and the repo's
[`VERSIONS.md`](../../../VERSIONS.md).

## Install (PrestaShop 8.x)

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
   only appears for currencies BMaGlass wallets hold.

## How it works — and what differs from the 1.7 build

Checkout and settlement are identical to the other variants: one
payment link per order, hosted BMaGlass page, signed
`payment_link.paid` webhook completing the order immediately
(API-confirmed), reconciliation fallback, exactly-once.

The genuine 8.x differences are the platform declarations:
PrestaShop 8 enforces `ps_versions_compliancy` — this build
declares **8.0.0.0 – 8.9999.9999** (the 1.7 build declares 1.7
only) — and it targets the 8.x PHP platform (7.2.5–8.1). The
payment API used (`paymentOptions` hook, `ModuleFrontController`,
`validateOrder`) is unchanged between 1.7 and 8, so no logic was
rewritten for the sake of looking different.

## Verification status (honest boundary)

`php -l` clean on every file; the vendored client in
`classes/sdk/` is byte-identical to the PHP SDK
(`integrations/check-vendored-clients.sh`), whose own live-API
protocol proof passed. **Install-tested 2026-10-26 on PrestaShop 8.1.7: PASS** — genuine guest checkout → hosted pay page → signed webhook settlement, exactly one payment booked. (8.2.x receives security fixes until
PrestaShop 10.)
