# BMaGlass Pay — SDKs, plugins & integrations

Official SDKs, plugins and integrations for the **BMaGlass Pay developer API**.
This repository is the public home for all BMaGlass Pay developer artefacts —
outside developers build against **this** repo, not the BMaGlass Pay app itself.

Every artefact here connects a store, app or automation to the merchant's
existing BMaGlass Pay account — the same account and the same wallets
(ZMW / USD / BTC) — using an API key (`bmp_live_…` / `bmp_test_…`) issued
from the BMaGlass Pay dashboard.

- Developer docs: https://bmaglasspay.online/developers
- Live API contract (OpenAPI): https://bmaglasspay.online/openapi.json
  (a copy also lives at [`sdk/openapi.json`](sdk/openapi.json))

## SDKs — [`sdk/`](sdk/)

| SDK | Path | Status |
|---|---|---|
| TypeScript / JavaScript — `@bmaglass/sdk` | [`sdk/typescript`](sdk/typescript) | Contract tests pass |
| Python — `bmaglass-sdk` | [`sdk/python`](sdk/python) | Contract tests pass |
| PHP — `bmaglass/bmaglass-sdk` | [`sdk/php`](sdk/php) | Tests pass |
| React Native / Expo — `@bmaglass/sdk-react-native` | [`sdk/react-native`](sdk/react-native) | Tests pass |
| Flutter (Dart) — `bmaglass_sdk` | [`sdk/flutter`](sdk/flutter) | Code + tests written; **tests not yet run** — no Dart toolchain was available in the build environment. Run them where a toolchain exists before relying on it. |

Shared contract tests live in [`sdk/tests`](sdk/tests/). See
[`sdk/README.md`](sdk/README.md) for per-SDK install and test commands.

## Integrations — [`integrations/`](integrations/)

Every stack connector ships **per-version builds** where the
platform genuinely splits, and an honestly-declared range where it
does not. The full verified landscape — versions, support windows,
what was adapted per version, and what was excluded and why — is in
[**VERSIONS.md**](VERSIONS.md).

| Stack | Versions supported | Path | Status |
|---|---|---|---|
| WordPress / WooCommerce | WP 6.0+, WooCommerce 7.0 – 11.2 (HPOS + legacy storage; Checkout Blocks + classic) — one plugin | [`integrations/wordpress`](integrations/wordpress) | Unit tests (client, reconciler, Blocks integration) + live-API protocol proof passed. No WP/WC runtime in the build environment — smoke-test on staging first. |
| Odoo | **16.0, 17.0, 18.0, 19.0, 20.0** — one module per major | [`integrations/odoo`](integrations/odoo) | 14 client tests green per variant; clients byte-identical across variants; live-API protocol proof passed (17.0). No Odoo runtime in the build environment — smoke-test on staging first. |
| Magento 2 | **2.4.6 – 2.4.9** — one module, Composer-ranged (`magento/framework 103.0.*`, PHP ^8.1) | [`integrations/magento`](integrations/magento) | `php -l` clean; vendored client proven identical to the PHP SDK. Runtime smoke test still owed — staging first. |
| PrestaShop | **1.7, 8.x, 9.x** — one build per major, each declaring its own compliancy | [`integrations/prestashop`](integrations/prestashop) | `php -l` clean per variant; vendored clients proven identical to the PHP SDK. Runtime smoke test still owed — staging first. |
| OpenCart | **3.0.x and 4.x** — separate builds (different platform conventions) | [`integrations/opencart`](integrations/opencart) | `php -l` clean per variant; vendored clients proven identical to the PHP SDK. Runtime smoke test still owed — staging first. |
| Universal embed button (any website, incl. Wix / Squarespace / Webflow) | — | [`integrations/embed`](integrations/embed) | DOM tests pass. Binds to a public payment link only — the script refuses raw API keys. |
| Zapier / Make automation pack | — | [`integrations/automation`](integrations/automation) | Webhook-first recipes using the platforms' native modules. No app-store listing exists. The Zapier app definition included is a **draft, unsubmitted**. |
| Shopify | — | [`integrations/shopify/ASSESSMENT.md`](integrations/shopify/ASSESSMENT.md) | **Assessment only — no plugin.** Shopify checkout payments are gated by Shopify's payments partner programme. Payment links and the embed button work in Shopify today; the document explains the routes honestly. |

Settlement behaviour: shop connectors complete orders instantly from the
signed `payment_link.paid` webhook event, and keep polling reconciliation
as a fallback. [`integrations/check-vendored-clients.sh`](integrations/check-vendored-clients.sh)
proves every PHP connector variant's vendored API client is identical to the PHP SDK.

## Honest limits

- Test keys (`bmp_test_…`) are **read-only by design** — BMaGlass Pay has no
  test-mode ledger, so test keys can read wallets/transactions and manage
  webhooks, but can never move money. Live keys move real money.
- The SDKs are distributed from this repository only; they are **not yet
  published** to npm, PyPI, Packagist or pub.dev.
- Amounts and currencies are never silently converted: a connector refuses a
  store currency that has no matching BMaGlass Pay wallet (ZMW / USD) instead
  of improvising an exchange rate.
- Connector proofs are lint + unit tests + documentation-verified
  adaptation; no stack runtime exists in the build environment, so no
  install-level proof is claimed (see VERSIONS.md).

## Licence

MIT — see [`LICENSE`](LICENSE).
