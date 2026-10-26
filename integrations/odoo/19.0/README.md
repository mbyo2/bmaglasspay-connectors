# BMaGlass Pay for Odoo 19.0

Payment provider module for **Odoo 19.0**. For other Odoo versions
use the matching folder — see [`../README.md`](../README.md) and the
repo's [`VERSIONS.md`](../../../VERSIONS.md).

## Install (Odoo 19.0)

1. Copy `payment_bmaglass/` into your Odoo 19 addons path, update the
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

## What genuinely differs from the 17.0/18.0 variants

Verified against the official Odoo 19.0 developer reference:

- **The 19.0 payment-processing rework.** 19.0 replaces the
  notification API — `_get_tx_from_notification_data` /
  `_handle_notification_data` are gone from the documented
  `payment.transaction` API, replaced by `_search_by_reference`,
  `_process` and `_apply_updates`. This module never settled through
  the notification API (settlement is driven by its own
  HMAC-verified controller + the reconciliation cron), so the flow
  is unaffected — but the variant is written and documented against
  the 19.0 API, not assumed compatible.
- **No `_log_message_on_transaction`.** Dropped from the 19.0
  documented API (present in 17.0/18.0), so settlement notes are
  posted with `mail.thread`'s `message_post()` (`_bmaglass_log()`).
- `_set_done` / `_set_error` in 19.0 take keyword-only
  `state_message` plus `extra_allowed_states`; this module calls
  them without arguments / with the message only, which is
  compatible with both the 19.0 and earlier signatures.
- `_get_supported_currencies` **is** documented in 19.0 and is used
  exactly as in 17.0/18.0 (ZMW/USD intersection); the provider
  **State** field also remains in 19.0, so the enable-time live-key
  constraint is unchanged.

Behaviour is otherwise identical: one payment link per transaction,
`payment_link.paid` push settlement, 5-minute reconciliation
fallback, exactly-once settlement.

## Verification status (honest boundary)

- `python3 -m compileall` clean; `tests/test_bmaglass_client.py`
  (this folder) — 14 tests green against a stub transport.
- The vendored client is byte-identical across all five Odoo
  variants (`../check-clients.sh`) and was live-proven against the
  production developer API in the original programme.
- **Install-tested 2026-10-26 on Odoo 19.0: PASS** — genuine website checkout → hosted pay page → signed webhook settlement (tx done), duplicates idempotent, bad signatures refused. Sale-order confirmation after `done` is Odoo core post-processing.
