# BMaGlass Pay for Odoo — per-version modules

An Odoo payment provider that connects an Odoo instance to the
merchant's existing BMaGlass Pay account — **same account, same
wallets (ZMW / USD)** — through the BMaGlass Pay developer API.

One module per Odoo major version, in OCA-style version folders.
Pick the folder that matches your Odoo — the payment framework's
API genuinely differs across these versions, and each variant is
adapted to (and documented against) its own version's official
reference:

| Folder | Odoo | Upstream status (Oct 2026) | README |
|---|---|---|---|
| [`16.0/`](16.0/) | 16.0 | End-of-life (support ended autumn 2025); large installed base | [`16.0/README.md`](16.0/README.md) |
| [`17.0/`](17.0/) | 17.0 | Maintenance ending | [`17.0/README.md`](17.0/README.md) |
| [`18.0/`](18.0/) | 18.0 | LTS, supported through Oct 2027 | [`18.0/README.md`](18.0/README.md) |
| [`19.0/`](19.0/) | 19.0 | Supported (~Sep 2028) | [`19.0/README.md`](19.0/README.md) |
| [`20.0/`](20.0/) | 20.0 | Current (released 24 Sep 2026) | [`20.0/README.md`](20.0/README.md) |

Full landscape and sources: [`VERSIONS.md`](../../VERSIONS.md).

## Shared behaviour (every variant)

- Connect by API key (`bmp_live_…` / `bmp_test_…`) with **Test
  connection** and one-click **Register webhook** tools.
- One hosted payment link per transaction; the buyer pays
  wallet-to-wallet on `https://bmaglasspay.online/pay/<code>`.
- Settlement is **push-first**: the signed `payment_link.paid`
  webhook settles immediately (payload cross-checked before
  `_set_done()`); a 5-minute cron reconciliation is the fallback.
  Exactly-once on both paths.
- **ZMW / USD only** and **live key required to take payments** —
  test keys are read-only platform-wide (no test-mode ledger), and
  no exchange rate is ever invented.
- The vendored API client (`models/bmaglass_api.py`) is
  **byte-identical across all five variants** — verified by
  [`check-clients.sh`](check-clients.sh) — and mirrors the Python
  SDK's surface, error codes and HMAC scheme (shared known-answer
  test vector).

## What actually differs between variants

- **16.0**: no `_get_supported_currencies` and no
  `_log_message_on_transaction` in the documented API — the
  currency allowlist is enforced at transaction creation.
- **17.0 / 18.0**: the documented payment API this module uses is
  the same in both (verified page-by-page); the variants differ in
  manifest version and install docs, honestly, rather than in
  invented code changes.
- **19.0**: the payment-processing rework (notification API renamed
  to `_search_by_reference` / `_process` / `_apply_updates`); the
  module's own controller/cron settlement is unaffected by the
  rename.
- **20.0**: the provider state rework — nothing in the variant
  reads/writes a provider `state` value: the live-key rule is
  enforced at payment-link creation, the form anchors after `code`,
  and the data record takes the version's own state default.
  Default-method activation uses core's `setup_provider()` from a
  `post_init_hook` (B11 — 20 has no state transition to trigger it),
  config parameters use the typed `get_str`/`set_str` accessors
  (B8), and access rules ship as `security/ir.access.csv` (B6).
- **All variants**: settlement notes go through one crash-proof
  `_bmaglass_log` helper (`_log_message_on_linked_documents` →
  `message_post` → server log; logging can never block settlement —
  B5/B5b), and the supported-currency search ignores active flags
  so a currency activated after install is still declared (B10).

## Verification (all variants)

`python3 -m compileall` clean per module;
`<version>/tests/test_bmaglass_client.py` — 14 tests green per
variant (stub transport; client behaviour, HMAC known-answer
vector, `payment_link.paid` semantic guard). The client was
additionally live-proven against the production developer API in
the original programme (QA merchant, live key).

**Boundary:** no Odoo runtime (PostgreSQL) exists in the build
environment, so install-level behaviour is docs-verified against
each version's official reference — not install-proven. Smoke-test
on staging first.
