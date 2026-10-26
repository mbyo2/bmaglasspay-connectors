# BMaGlass Pay for OpenCart 3.x

Payment extension for **OpenCart 3.0.x** (3.0.5.1 current — the 3.x
line is still maintained upstream). For OpenCart 4.x use
[`../4.x/`](../4.x/) — a separate build. Landscape:
[`VERSIONS.md`](../../../VERSIONS.md).

## Install

1. Zip the contents of this folder so the archive contains
   `install.json` and the `upload/` folder (the standard OpenCart
   3.x extension package layout), and install it via
   **Extensions → Installer** — or copy the contents of `upload/`
   over your OpenCart root manually.
2. **Extensions → Extensions → Payments → BMaGlass Pay → Install**,
   then Edit: enable, paste your API key, use **Test connection**
   to see your wallets, register the shown webhook URL in
   BMaGlass Pay and paste the signing secret shown once, and pick
   the paid order status.
3. Keep the store currency ZMW or USD where BMaGlass should appear
   (other currencies hide the method — no invented FX rate).
4. If you installed by copying files and your shop uses the
   modification cache, refresh it (**Extensions →
   Modifications → Refresh**) — this extension ships no OCMOD
   modifications, so a refresh is only about template caching.

## Platform notes (3.x) — how this build differs from 4.x

- Classic non-namespaced classes: `ControllerExtensionPayment
  Bmaglasspay`, `ModelExtensionPaymentBmaglasspay`, in
  `catalog|admin/{controller,model}/extension/payment/`.
- The 3.x payment-model signature `getMethod($address, $total)`
  (4.x uses `getMethods()`), returning the 3.x method array
  (`code` / `title` / `terms` / `sort_order`).
- Routes are `extension/payment/bmaglasspay...` (4.x:
  `extension/bmaglasspay/payment/bmaglasspay...`); the webhook
  callback is `index.php?route=extension/payment/bmaglasspay/callback`.
- Admin settings use the classic 3.x **form POST** save with a
  `validate()` permission check (4.x saves via a JSON endpoint),
  and templates use the 3.x (Bootstrap 3 / panel) admin markup and
  the default theme's jQuery confirm flow.
- Checkout/settlement behaviour is identical to 4.x: one payment
  link per order, hosted page, signed `payment_link.paid` push
  settlement with polling fallback, exactly-once.

## Verification status (honest boundary)

`php -l` clean on every file; the vendored client is
byte-identical to the PHP SDK client whose live-API protocol
proof passed in the original programme. Install-level behaviour
was written to the documented 3.0.x extension conventions and is
now **install-tested PASS on OpenCart 3.0.5.1 (2026-10-26)** —
genuine guest checkout → signed webhook settlement, exactly once.
