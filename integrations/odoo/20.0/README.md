# BMaGlass Pay for Odoo 20.0

Payment provider module for **Odoo 20.0** (released 24 September
2026 — the current Odoo major). For other Odoo versions use the
matching folder — see [`../README.md`](../README.md) and the repo's
[`VERSIONS.md`](../../../VERSIONS.md).

## Install (Odoo 20.0)

1. Copy `payment_bmaglass/` into your Odoo 20 addons path, update the
   apps list, and install **Payment Provider: BMaGlass Pay**. Odoo
   20 requires Python 3.12+; the module's vendored client is
   standard-library only.
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
5. Enable / publish the provider for live payments — a **live key is
   required to take payments**; with a test key the provider can be
   configured and connection-checked, but payment-link creation is
   refused with the explanation (see below).

## What genuinely differs from the 17.0–19.0 variants

Verified against the official Odoo master (20.0) developer reference
and the Odoo 20 release notes:

- **Provider state rework.** Odoo 20 reworked the payment provider's
  state handling (the release notes record the provider views being
  "refined and simplified"; upgrade guides report the legacy State
  field replaced by Published / Live / Archive). The 16.0–19.0
  variants gate enabling on `@api.constrains("state", ...)` and
  anchor the form view after the `state` field — neither is a stable
  anchor in 20.0, so this variant:
  - constrains only the **API key format** (`bmp_live_` / `bmp_test_`
    prefix) at configuration time;
  - enforces the honest test-mode rule **at payment-link creation**
    (`_bmaglass_create_link` refuses a non-live key with the
    test-mode explanation before any link exists — the API also
    independently refuses test-key writes);
  - anchors the provider form fields after the **`code`** field;
  - sets **no state value** on the provider data record — it takes
    Odoo 20's own default and starts unpublished until enabled.
- **No `_log_message_on_transaction`** (as in 19.0): settlement
  notes are posted with `mail.thread`'s `message_post()`.
- The 19.0 processing API (`_search_by_reference` / `_process` /
  `_apply_updates`) carries into 20; this module settles through its
  own controller + cron and is unaffected, as in 19.0.
- `_get_supported_currencies` is documented in the 20 reference and
  is used as in 17.0–19.0 (ZMW/USD intersection).

Behaviour is otherwise identical: one payment link per transaction,
`payment_link.paid` push settlement, 5-minute reconciliation
fallback, exactly-once settlement.

## Verification status (honest boundary)

- `python3 -m compileall` clean; `tests/test_bmaglass_client.py`
  (this folder) — 15 tests green against a stub transport.
- The vendored client is byte-identical across all five Odoo
  variants (`../check-clients.sh`) and was live-proven against the
  production developer API in the original programme.
- **Install-tested 2026-10-26 on Odoo 20.0: PASS** — genuine
  website checkout → hosted pay page → settlement proven on BOTH
  the webhook path (core's notification queue) and the reconcile
  cron path; duplicates idempotent, bad signatures refused. The
  state-handling adaptation is deliberately defensive (nothing in
  this variant reads or writes a provider `state` value).
