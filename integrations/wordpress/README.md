# BMaGlass Pay for WooCommerce

A WooCommerce payment gateway that connects a WordPress store to the
merchant's existing BMaGlass Pay account — **same account, same
wallets (ZMW / USD)** — through the BMaGlass Pay developer API.

**One plugin covers the current WooCommerce range** — that is the
industry pattern for WooCommerce, and here the compatibility work
behind the claim is real (below). Declared range: **WordPress 6.0+,
WooCommerce 7.0 – 11.2** (11.2.1 is the current release, Oct 2026),
**PHP 7.4+**. Both order storages and both checkouts are supported:

- **HPOS and legacy order storage.** The gateway and reconciler use
  only WooCommerce CRUD order APIs and order meta (`wc_get_order`,
  `wc_get_orders` with meta queries, `get_meta` / `update_meta_data`,
  `payment_complete`) — the HPOS-safe surface, no direct post or
  postmeta access — and the plugin declares `custom_order_tables`
  compatibility via `FeaturesUtil` on `before_woocommerce_init`.
- **Checkout Blocks and classic checkout.** Since WooCommerce 10.6
  the Cart/Checkout blocks are the default checkout, and a classic
  gateway alone is invisible there — so the plugin also registers a
  Blocks payment method type
  (`includes/class-bmaglass-blocks-integration.php` +
  `assets/js/blocks-integration.js`) and declares
  `cart_checkout_blocks` compatibility. The block collects no fields
  (hosted-page flow); WooCommerce routes the submission to the same
  `process_payment()` server-side, so every refusal and guard lives
  in one place for both checkouts.

## Layout

```
bmaglass-pay/
  bmaglass-pay.php                       bootstrap: gateway registration, compatibility declarations, Blocks registration, webhook route, cron, admin actions
  includes/class-bmaglass-api-client.php framework-free developer-API client (seed of sdk/php)
  includes/class-wc-gateway-bmaglass.php WC_Payment_Gateway implementation (hosted payment links)
  includes/class-wc-gateway-bmaglass-momo.php WC_Payment_Gateway implementation (mobile money: MTN / Airtel / Zamtel)
  includes/class-bmaglass-reconciler.php settlement reconciliation (cron / thank-you / order action)
  includes/class-bmaglass-blocks-integration.php  Checkout Blocks payment method type (hosted links)
  includes/class-bmaglass-momo-blocks-integration.php  Checkout Blocks payment method type (mobile money)
  assets/js/blocks-integration.js        Blocks registration script (no build step)
  assets/js/momo-blocks-integration.js   Blocks registration script, mobile money (no build step)
  uninstall.php                          removes both gateways' settings (API key + webhook secret), retires the webhook, clears the cron schedule
  readme.txt                             WordPress plugin readme
tests/
  run-client-tests.php                   standalone PHP tests for the API client (no WordPress needed)
  run-reconciler-tests.php               settlement logic tests (webhook path + polling fallback + 100-link cap)
  run-blocks-tests.php                   Blocks integration tests (stubbed Blocks base class)
  run-momo-gateway-tests.php             Mobile Money gateway fee-bearer tests (stubbed WP/WC + API)
  run-gateway-tests.php                  both gateways' process_payment paths (sandbox rules, link reuse/expiry, MoMo retry, phone normalisation)
  run-webhook-tests.php                  the real webhook endpoint: HMAC, duplicates, unknown types, out-of-order delivery
  run-uninstall-tests.php                uninstall: webhook retirement, both settings options, transient, cron
```

## Install

1. Copy `bmaglass-pay/` into `wp-content/plugins/` (or zip the folder
   and upload it under Plugins → Add New → Upload), then activate.
2. WooCommerce → Settings → Payments → **BMaGlass Pay**: paste an API
   key created in BMaGlass Pay (Business settings or the API page)
   with scopes `wallets:read`, `payment_links:read`,
   `payment_links:write`, `webhooks:write` (plus `mobile_money:create`,
   `mobile_money:read`, `config:read` for the Mobile Money gateway);
   enable and save.
3. **Test connection** — shows the account's wallets and whether the
   key is test or live.
4. **Register webhook** — registers this site's endpoint
   (`/wp-json/bmaglass/v1/webhook`) with BMaGlass, stores the
   one-time signing secret, and sends a signed `webhook.test` event.
5. Store currency must be **ZMW or USD**.

## How checkout and settlement work

- `process_payment` creates **one payment link per order** for the
  exact total (reused on retries — never duplicated) and redirects
  the buyer to the hosted page `https://bmaglasspay.online/pay/<code>`,
  where they pay wallet-to-wallet from their BMaGlass Pay account.
- The order sits **on-hold** until it is settled. Settlement is
  **push-first**: the developer API fires a signed `payment_link.paid`
  webhook the moment the link is paid, and this plugin's
  HMAC-verified endpoint completes the matching order immediately.
  `payment_complete()` runs exactly once with the link code as the
  reference.
- **Reconciliation is the fallback**: cron (5 min), the thank-you
  page and the per-order action still poll the link's
  `payment_count` / `total_collected` and complete any order whose
  webhook delivery was missed. Both paths share the same
  settled-flag + status guard, so they cannot double-complete.

**History:** this plugin originally confirmed orders by
reconciliation alone, because the API had no "payment link paid"
event. The event (`payment_link.paid`, exactly once per settlement)
has since shipped on the platform; the webhook handler now settles
from it directly and reconciliation remains as the safety net.

## Honest limits

- **Sandbox keys use simulated money** (platform-wide design).
  Settings + connection checks work with a sandbox key, and the
  connection check renders your connector configuration from BMaGlass
  (environment, business country, mobile-money availability for
  businesses registered in Zambia). The hosted-link gateway refuses
  sandbox checkout — the hosted pay page refuses sandbox links — but
  mobile-money checkout runs end to end with a sandbox key against
  the sandbox connection (test numbers; no real phone is ever
  charged), including retries after a failed collection.
- **Currencies:** ZMW and USD only — the currencies BMaGlass wallets
  hold. Other store currencies are refused at checkout with an
  explanation; no exchange rate is ever invented. Mobile money is
  ZMW-only.
- Buyers need a BMaGlass Pay wallet to pay the hosted link (guest card checkout is
  not a BMaGlass feature today).
- **Reconciliation reads the 100 newest payment links.** `GET
  /payment-links` returns max 100, newest first, and the API has no
  single-link read — so on an account with more than 100 links the
  polling fallback cannot confirm an older order's link, and the
  order is flagged once instead of silently standing down. The
  signed `payment_link.paid` webhook stays the primary settlement
  path and is unaffected. A single-link read on the API side would
  close this gap for good.

## Verification in this repo

- `php -l` clean on all PHP files; the Blocks scripts pass
  `node --check`.
- Seven mocked-WP suites, 239 checks, all passing (`php
  integrations/wordpress/tests/run-*.php`, no WordPress or network):
  `run-client-tests.php` (61: mode detection, header/body shaping,
  typed error mapping incl. `test_mode_read_only`, revoked/expired/
  under-scoped keys, non-JSON error bodies, currency guard, and the
  shared cross-SDK HMAC known-answer vector);
  `run-reconciler-tests.php` (24: the `payment_link.paid` webhook
  path and the polling fallback, incl. no-double-complete races, the
  mobile-money paths, the 100-link list cap, and the cron sweep with
  a sandbox key);
  `run-blocks-tests.php` (30: the Blocks integration — name,
  availability mirroring the classic gateway, payment-method data,
  script handle — plus source pins for the HPOS/Blocks
  compatibility declarations in the bootstrap);
  `run-momo-gateway-tests.php` (30: the Mobile Money gateway's
  fee-bearer <redacted> — buyer K100 → K101 charged / seller nets
  K100.00, seller K100 → K100.00 charged);
  `run-gateway-tests.php` (47: both gateways' `process_payment`
  paths — sandbox rules, link reuse/expiry, missing link code,
  MoMo retry after failure, phone normalisation, feature-cache
  behaviour);
  `run-webhook-tests.php` (36: the real webhook endpoint — HMAC
  verification, duplicates, tampered/missing signatures, malformed
  payloads, unknown types, out-of-order delivery);
  `run-uninstall-tests.php` (11: webhook retirement, both settings
  options, feature transient, cron).
- Live protocol proof: the plugin's own client class was run from
  PHP CLI against the production developer API (QA merchant account,
  live key): wallets read, payment link created and re-fetched,
  webhook registered and test event delivered (see the connectors
  addendum in the completion-sweep goal for the dated record).
  The 2026-11-15 extensions hunt could NOT re-run live sandbox
  collections: the developer API answered 429 `rate_limited` ("Too
  many failed authentication attempts") to every QA key, including
  the first call — a shared-bucket limit tripped before this
  workstream's calls, treated as a hard stop, so the live sandbox
  fee-bearer E2E (buyer K100 → K101 / seller nets K100.00; seller
  K100 → K99 net) is still owed and must be re-run once the limiter
  clears, with the fee-bearer QA merchant (qa-feebearer-zm) and the
  sandbox test numbers.
- **Install-tested 2026-10-26 on WordPress 7.1.3 + WooCommerce
  11.2.1 (HPOS): PASS** — Blocks and classic checkouts both paid
  end-to-end via signed webhook push; duplicate deliveries settle
  exactly once; an unsupported currency is refused truthfully.
