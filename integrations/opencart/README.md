# BMaGlass Pay for OpenCart — per-version extensions

Accept BMaGlass Pay in OpenCart. Buyers pay on BMaGlass's hosted
payment page from their BMaGlass wallet; the money lands in the
**same BMaGlass account and wallets**. The API client is the PHP
SDK (`sdk/php`), vendored **verbatim** under each variant's
`upload/system/library/bmaglass/` (byte-identical — verified by
`integrations/check-vendored-clients.sh`).

OpenCart 3.x and 4.x are **genuinely different platforms** (3.x:
classic `ControllerExtensionPayment*` classes, `getMethod()` model
signature, `extension/payment/...` routes; 4.x: namespaced
extension modules, `getMethods()`, the extension-folder layout), so
each has its own build:

| Folder | OpenCart | Upstream status (Oct 2026) |
|---|---|---|
| [`3.x/`](3.x/) | 3.0.x (3.0.5.1) | Still maintained (3.0.5.1, Aug 2026) |
| [`4.x/`](4.x/) | 4.x (4.1.0.4) | Current |

Full landscape and sources: [`VERSIONS.md`](../../VERSIONS.md).

## Shared behaviour (both builds)

- Checkout (enabled + live key + ZMW/USD store currency only):
  confirm creates ONE payment link per order and redirects the
  buyer to the hosted page.
- Settlement is **push-first**: the HMAC-verified callback receives
  `payment_link.paid` and reconciles that order immediately
  (confirmed via the API); polling reconciliation is the fallback.
  Orders complete **exactly once**.
- Wallet-to-wallet only (buyers need a BMaGlass account); test
  keys are read-only by design — connection check only.

Per-version install steps and the exact platform differences are
in each folder's README.
