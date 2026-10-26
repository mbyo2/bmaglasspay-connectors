# BMaGlass Pay for OpenCart 4.x

Payment extension for **OpenCart 4.x** (4.1.0.4 current). For
OpenCart 3.x use [`../3.x/`](../3.x/) — a separate build, not this
one renamed. Landscape: [`VERSIONS.md`](../../../VERSIONS.md).

## Install

1. Copy the contents of `upload/` over your OpenCart root
   (or zip `upload/` as an extension archive and install via
   **Extensions → Installer**).
2. **Extensions → Extensions → Payments → BMaGlass Pay → Install**,
   then Edit: enable, paste your API key, use **Test connection**
   to see your wallets, register the shown webhook URL in
   BMaGlass Pay and paste the signing secret shown once, and pick
   the paid order status.
3. Keep the store currency ZMW or USD where BMaGlass should appear
   (other currencies hide the method — no invented FX rate).

## Platform notes (4.x)

Namespaced extension layout (`extension/bmaglasspay/...`),
`getMethods()` checkout API, JSON settings save endpoint, and
Twig templates in the 4.x admin style. Settlement: the
HMAC-verified callback settles on `payment_link.paid`
immediately; polling reconciliation is the fallback; orders
complete exactly once.

## Verification status (honest boundary)

`php -l` clean on every file; the vendored client is
byte-identical to the PHP SDK client whose live-API protocol
proof passed in the original programme. **Install-tested 2026-10-26 on OpenCart 4.1.0.4: PASS** — genuine guest checkout → hosted pay page → signed webhook settlement to the configured complete status, exactly once.
