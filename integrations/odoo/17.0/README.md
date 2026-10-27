# BMaGlass Pay for Odoo 17.0

Payment provider module for **Odoo 17.0**. For other Odoo versions use
the matching folder — see [`../README.md`](../README.md) and the
repo's [`VERSIONS.md`](../../../VERSIONS.md).

## Install (Odoo 17.0)

1. Copy `payment_bmaglass/` into your Odoo 17 addons path, update the
   apps list, and install **Payment Provider: BMaGlass Pay**.
2. Invoicing/Accounting → Configuration → Payment Providers →
   **BMaGlass Pay**: paste an API key created in BMaGlass Pay
   (Business settings or the API page) with scopes `wallets:read`,
   `payment_links:read`, `payment_links:write`.
3. **Test connection** — shows the account's wallets and whether the
   key is test or live.
4. **Register webhook** — registers
   `https://<your-odoo>/payment/bmaglass/webhook` with BMaGlass,
   stores the one-time signing secret, and sends a signed
   `webhook.test` event.
5. Set the provider **State** to **Enabled** — this requires a live
   key (a `ValidationError` explains why if the key is a test key),
   and publish it like any Odoo provider.

## How it works

- Each payment transaction creates **one BMaGlass payment link** for
  the exact amount; the buyer is redirected to the hosted page
  `https://bmaglasspay.online/pay/<code>` and pays wallet-to-wallet.
- Settlement is **push-first**: the signed `payment_link.paid`
  webhook settles the matching transaction immediately (payload
  cross-checked — link code, currency, amount — before `_set_done()`).
- A **5-minute cron reconciliation** is the fallback (link
  `payment_count` / `total_collected` covering the amount). Both
  paths are exactly-once (`_set_done()` is state-guarded).
- Currencies: **ZMW and USD only** — `_get_supported_currencies()` is
  intersected with the BMaGlass wallet currencies, and the vendored
  client refuses anything else rather than inventing a rate.
- Settlement notes are written with `_log_message_on_transaction()`,
  part of the documented 17.0 `payment.transaction` API.

## Verification status (honest boundary)

- `python3 -m compileall` clean; `tests/test_bmaglass_client.py`
  (this folder) — 15 tests green against a stub transport: client
  behaviour, the shared HMAC known-answer vector, and the
  `payment_link.paid` semantic guard.
- The vendored client (`models/bmaglass_api.py`) is byte-identical
  across all five Odoo variants — checked by
  `../check-clients.sh`.
- The same client was run against the **live** developer API in the
  original 17.0 proof (QA merchant, live key: wallets read, link
  created, webhook delivered).
- **Install-tested 2026-10-26 on Odoo 17.0: PASS** — genuine website checkout → hosted pay page → signed webhook settlement (tx done), duplicates idempotent, bad signatures refused. Sale-order confirmation after `done` is Odoo core post-processing.
