# BMaGlass Pay for Odoo 16.0

Payment provider module for **Odoo 16.0**. For other Odoo versions use
the matching folder — see [`../README.md`](../README.md) and the
repo's [`VERSIONS.md`](../../../VERSIONS.md).

> **Upstream status:** Odoo 16.0 is end-of-life (Odoo supports the
> three most recent majors; 16.0 support ended in autumn 2025). This
> variant exists because a large installed base still runs 16.0 —
> prefer a supported Odoo version for new deployments.

## Install (Odoo 16.0)

1. Copy `payment_bmaglass/` into your Odoo 16 addons path, update the
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

## What genuinely differs from the 17.0 variant

Verified against the official Odoo 16.0 developer reference
(`payment_provider` / `payment_transaction` API pages):

- **No `_get_supported_currencies` hook.** It is absent from the
  16.0 documented `payment.provider` API (it first appears in the
  17.0 reference), so this variant does not override it. The ZMW/USD
  allowlist is enforced at transaction creation instead: the
  vendored client's `assert_supported_currency()` refuses any other
  currency before a payment link is created, and the transaction is
  set to `error` with that plain-language message. Nothing is ever
  converted at an invented rate.
- **No `_log_message_on_transaction`.** Also absent from the 16.0
  documented `payment.transaction` API, so settlement notes are
  posted with `mail.thread`'s `message_post()` (`_bmaglass_log()`).
- Everything else matches the other variants: `payment.provider`
  model (the acquirer→provider rename landed in 16.0, not 17.0),
  one payment link per transaction, `payment_link.paid` push
  settlement with the 5-minute reconciliation fallback, the
  live-key gate on enabling, exactly-once settlement.

## Verification status (honest boundary)

- `python3 -m compileall` clean; `tests/test_bmaglass_client.py`
  (this folder) — 15 tests green against a stub transport.
- The vendored client is byte-identical across all five Odoo
  variants (`../check-clients.sh`) and was live-proven against the
  production developer API in the original programme.
- **Install-tested 2026-10-26 on Odoo 16.0: PASS** — genuine website checkout → hosted pay page → signed webhook settlement (tx done), duplicates idempotent, bad signatures refused. Sale-order confirmation after `done` is Odoo core post-processing.
