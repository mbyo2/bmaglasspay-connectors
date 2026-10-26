# Version landscape — what exists, what we build for, and why

Verified **October 2026** against official vendor sources (release
notes, versioned developer references, lifecycle/system-requirement
pages — cited per stack). This matrix is the repo's contract: a
version is listed as supported only when a build for it exists in
this repository, adapted to that version's documented framework
APIs. Anything excluded says why.

**Proof boundary (updated 26 Oct 2026 — install-level testing):**
the real stacks were installed and the connectors run through
genuine shop checkouts paid via the live BMaGlass API (hosted pay
page handoff, signed webhook settlement, exactly-once on duplicate
delivery, truthful refusals). **Install-tested PASS:** WordPress
7.1.3 + WooCommerce 11.2.1 (HPOS; Blocks + classic checkouts);
Odoo 16.0 / 17.0 / 18.0 / 19.0 / 20.0 (20.0 proven on both the
webhook-queue and reconcile settlement paths); PrestaShop 9.0.0 /
8.1.7 / 1.7.8.11; OpenCart 4.1.0.4 / 3.0.5.1; Magento 2.4.8.
25 connector bugs were found by the installs and fixed in this
repo (B1–B25 in the mission buglog) — several were fatal on real
installs (a repo-wide webhook-signature header typo, an encrypted
Magento API key never decrypted at runtime, PrestaShop payment
double-booking, Odoo 20's missing payment method + write-guard
settlement). Versions inside a declared range that are NOT in the
list above (e.g. Magento 2.4.6/2.4.7/2.4.9, PrestaShop 9.2, 8.2.x)
remain documentation-adapted and syntax/unit-proven only —
install proof is claimed for exactly the versions named here.

## Odoo — one module per major: `integrations/odoo/<version>/`

Sources: odoo.com release notes (20.0, 24 Sep 2026), the versioned
developer references at odoo.com/documentation/{16.0,17.0,18.0,19.0,
master} (`payment_provider` / `payment_transaction` API pages), and
Odoo's supported-versions policy (three most recent majors).

| Odoo | Released | Upstream support | Build | Genuine adaptation |
|---|---|---|---|---|
| 20.0 | Sep 2026 | Current | ✅ `20.0/` | Provider state rework: no `state` reads/writes — live-key rule enforced at link creation, form anchored after `code`, data record takes the version's state default; no `_log_message_on_transaction` (→ `message_post`) |
| 19.0 | Sep 2025 | ~Sep 2028 | ✅ `19.0/` | Processing rework: notification API renamed (`_process` / `_search_by_reference` / `_apply_updates`); `_log_message_on_transaction` dropped → `message_post`; `_get_supported_currencies` + `state` gate retained (both documented in 19.0) |
| 18.0 | Oct 2024 | LTS → Oct 2027 | ✅ `18.0/` | Documented payment API identical to 17.0 for the hooks used (verified page-by-page) — variant differs in manifest/install docs, honestly, not invented code |
| 17.0 | Nov 2023 | Maintenance ending | ✅ `17.0/` | The original build: `_get_supported_currencies`, `_log_message_on_transaction`, `state` live-key constraint |
| 16.0 | Oct 2022 | **EOL** (ended autumn 2025) | ✅ `16.0/` | No `_get_supported_currencies` and no `_log_message_on_transaction` in the 16.0 documented API — currency allowlist enforced at transaction creation; notes via `message_post`. Built despite EOL: the installed base is large |
| ≤ 15 | — | EOL | ❌ | The `payment.acquirer` era (the provider rename landed in 16.0); long EOL — upgrade first |

The vendored API client is byte-identical across all five builds
(`integrations/odoo/check-clients.sh`).

**Install-tested 2026-10-26: all five versions PASS** — module
installs, provider activates, genuine website checkout creates the
payment link for the exact order total, signed webhook delivery
settles `payment_transaction.state = done`, duplicates are
idempotent, bad signatures get 401. 20.0 additionally proven via
the reconcile cron path. (Sale-order confirmation after `done` is
Odoo core post-processing, outside the connector's contract.)

## WooCommerce (WordPress) — one plugin, declared range: `integrations/wordpress/`

Sources: developer.woocommerce.com release notes (WooCommerce
**11.2.1**, 9 Oct 2026, is current; test stores on WordPress 7.1),
WooCommerce Blocks payment-method integration docs, HPOS docs.

A single plugin is the industry pattern for WooCommerce **when the
compatibility work is real** — and here it is, not just declared:

- **Declared range:** WordPress 6.0+, WooCommerce **7.0 – 11.2**,
  PHP 7.4+ (plugin header + readme.txt, `WC tested up to: 11.2`).
- **Both order storages:** HPOS (default for new installs since
  WC 8.2) and legacy — the code uses only CRUD order APIs + order
  meta, and declares `custom_order_tables` compatibility via
  `FeaturesUtil`.
- **Both checkouts:** classic and the Cart/Checkout **Blocks**
  (default for new stores since WC 10.6) — a real Blocks payment
  method type (`AbstractPaymentMethodType` + registration script)
  is implemented and unit-tested; the block routes server-side to
  the same `process_payment()`.
- **Install-tested 2026-10-26: PASS** on WordPress 7.1.3 +
  WooCommerce 11.2.1 (HPOS) — Blocks and classic checkouts both
  paid end-to-end via signed webhook push; duplicate deliveries
  settle exactly once; an unsupported currency (EUR) is refused
  truthfully with no payment link created.

## Magento Open Source / Adobe Commerce — one module, Composer-ranged: `integrations/magento/`

Sources: Adobe Commerce system requirements + release lifecycle
policy (Oct 2026).

| Magento | PHP | Adobe support | Build |
|---|---|---|---|
| 2.4.9 (May 2026) | 8.4 / 8.5 | → May 2029 | ✅ same module |
| 2.4.8 | 8.3 / 8.4 | → May 2028 | ✅ same module — **install-tested PASS 2026-10-26** (MySQL 8 + OpenSearch 2.19; genuine checkout → webhook capture → processing, exactly once) |
| 2.4.7 | 8.2 / 8.3 | → May 2027 | ✅ same module |
| 2.4.6 | 8.1 / 8.2 | extended → Aug 2027 | ✅ same module |
| 2.4.5 and earlier | 8.1 only | **ended Aug 2026** | ❌ unsupported platform — upgrade the store |

One module is the honest build for 2.4.x: the module's
`composer.json` declares `magento/framework 103.0.*` (the series
spanning 2.4.6–2.4.9) and `php ^8.1` (the union of Adobe's
per-minor PHP ranges; Adobe's own requirements enforce the exact
pairing per store). Nothing in the API surface used —
`AbstractMethod` redirect gateway, `CsrfAwareActionInterface`
webhook controller, cron, system.xml — differs across these
minors; there is no GraphQL/checkout-API/CSP surface in this
module that could differ. Per-minor copies would be label changes,
not adaptations.

## PrestaShop — one build per major: `integrations/prestashop/<major>/`

Sources: build.prestashop-project.org + PrestaShop roadmap/core
releases (9.2 current; 9.1 released Mar 2026; 8.2.x security-only
until PrestaShop 10; 1.7.8 branches retired Feb 2026).

| PrestaShop | PHP | Upstream status | Build |
|---|---|---|---|
| 9.x (9.2) | 8.1–8.5 (Symfony 6.4) | Current | ✅ `9/` — **install-tested PASS on 9.0.0** 2026-10-26 (9.2 doc-adapted) |
| 8.x (8.2.x) | 7.2.5–8.1 | Security fixes until PS 10 | ✅ `8/` — **install-tested PASS on 8.1.7** 2026-10-26 |
| 1.7.x | 7.2–7.4 | EOL Feb 2026; huge installed base | ✅ `1.7/` — **install-tested PASS on 1.7.8.11** 2026-10-26 |

The payment API used (`paymentOptions` hook,
`ModuleFrontController`, `validateOrder`) is the documented
integration surface on all three majors, so the logic is shared;
the split that genuinely exists — and is enforced by the platform
— is the declared `ps_versions_compliancy` (previously undeclared
anywhere) plus the per-major PHP platform. Each build declares
only its own major.

## OpenCart — one build per major line: `integrations/opencart/<line>/`

Sources: opencart.com downloads (4.1.0.4 and **3.0.5.1**, both
11 Aug 2026 — the 3.x line is still maintained).

| OpenCart | Build | Genuine adaptation |
|---|---|---|
| 4.x | ✅ `4.x/` — **install-tested PASS on 4.1.0.4** 2026-10-26 | Namespaced extension layout, `getMethods()`, JSON admin save, 4.x template style |
| 3.0.x | ✅ `3.x/` — **install-tested PASS on 3.0.5.1** 2026-10-26 | Classic `ControllerExtensionPayment*` classes, `getMethod($address, $total)`, `extension/payment/...` routes, form-POST admin save, 3.x (Bootstrap 3) templates, `install.json` package |

These are separate builds because the platforms genuinely differ
in class layout, routing, model signatures and admin conventions —
neither is a rename of the other.

## SDKs

The SDKs in `sdk/` are version-agnostic by design (they wrap one
HTTP API): TypeScript, Python, PHP (PHP ≥ 7.4 declared; the code
itself parses on PHP 7.2, which is what the legacy-platform
variants above rely on), React Native, Flutter. See `sdk/README.md`.
