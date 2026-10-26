# BMaGlass Pay for PrestaShop 1.7

Payment module for **PrestaShop 1.7.x** (PHP 7.2–7.4). For
PrestaShop 8 or 9 use the matching folder — see
[`../README.md`](../README.md) and the repo's
[`VERSIONS.md`](../../../VERSIONS.md).

> **Upstream status:** PrestaShop ended 1.7 maintenance in February
> 2026 (the 1.7.8.x branches were retired). The 1.7 installed base
> is still very large, so this variant is maintained here — but
> plan your migration to 8.x/9.x; this module's data and behaviour
> carry over unchanged.

## Install (PrestaShop 1.7)

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

## How it works

At checkout the buyer picks BMaGlass Pay, the module creates the
order in its *Awaiting BMaGlass payment* state plus **one payment
link** for the exact total, and redirects to the hosted BMaGlass
page. When the link is paid, the signed `payment_link.paid`
webhook completes the order immediately (the link is re-confirmed
via the API first); "Check pending orders" reconciliation is the
fallback. Exactly-once per order.

The module declares `ps_versions_compliancy` **1.7 only** and uses
the 1.7-era payment API (`paymentOptions` hook,
`ModuleFrontController`) — the same API 8.x/9.x kept, which is why
the three builds share their logic and differ in declared platform
(see the 8 and 9 READMEs).

## Verification status (honest boundary)

`php -l` clean on every file; the vendored client in
`classes/sdk/` is byte-identical to the PHP SDK
(`integrations/check-vendored-clients.sh`), whose own live-API
protocol proof passed. **Install-tested 2026-10-26 on PrestaShop 1.7.8.11: PASS** — genuine guest checkout → hosted pay page → signed webhook settlement, exactly one payment booked.
