# BMaGlass Pay for Odoo 18.0

Payment provider module for **Odoo 18.0** (the LTS release, supported
through October 2027). For other Odoo versions use the matching
folder — see [`../README.md`](../README.md) and the repo's
[`VERSIONS.md`](../../../VERSIONS.md).

## Install (Odoo 18.0)

1. Copy `payment_bmaglass/` into your Odoo 18 addons path, update the
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
   and publish it like any Odoo provider. Odoo 18 requires Python
   3.10+; the module's vendored client is standard-library only.

## What genuinely differs from the 17.0 variant

Compared page-by-page, the Odoo 18.0 payment-framework reference
documents the **same provider/transaction API surface this module
uses** as 17.0: `_get_supported_currencies`, the `_get_specific_*`
hooks, `_set_done` / `_set_error`, and `_log_message_on_transaction`
are all present with the same signatures. Where the framework did
not change, this variant truthfully does not invent differences:
the module code is the 17.0 implementation, with the 18.0 manifest
version, this README, and 18.0 install steps. (The real framework
breaks are at 16.0 — missing hooks — and 19.0 — the processing
rework; see those folders.)

Behaviour is identical to the other variants: one payment link per
transaction, `payment_link.paid` push settlement, 5-minute
reconciliation fallback, ZMW/USD only, live key required to enable,
exactly-once settlement.

## Verification status (honest boundary)

- `python3 -m compileall` clean; `tests/test_bmaglass_client.py`
  (this folder) — 14 tests green against a stub transport.
- The vendored client is byte-identical across all five Odoo
  variants (`../check-clients.sh`) and was live-proven against the
  production developer API in the original programme.
- **Install-tested 2026-10-26 on Odoo 18.0: PASS** — genuine website checkout → hosted pay page → signed webhook settlement (tx done), duplicates idempotent, bad signatures refused. Sale-order confirmation after `done` is Odoo core post-processing.
