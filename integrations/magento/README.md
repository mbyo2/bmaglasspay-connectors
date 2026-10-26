# BMaGlass Pay for Magento 2

Accept BMaGlass Pay payments in Magento 2 (Open Source / Adobe
Commerce). Buyers pay on BMaGlass's hosted payment page from their
BMaGlass wallet; the money lands in the **same BMaGlass account and
wallets** as everything else. Built on the PHP SDK
(`sdk/php`) — the API client is vendored at
`BMaGlass/Pay/Model/Api/` (identical to the SDK except the namespace;
verified by `integrations/check-vendored-clients.sh`).

## Version coverage — one module for 2.4.6–2.4.9, honestly

Magento's own pattern for the 2.4.x line is **one module per
codebase**, with the supported range declared in Composer — separate
per-minor builds would be copies, not adaptations, because nothing
in the API surface this module uses changes across 2.4.6–2.4.9
(verified against Adobe's system-requirements and lifecycle pages,
Oct 2026):

| Magento | Framework | PHP (Adobe-supported) | Adobe support |
|---|---|---|---|
| 2.4.9 (current, May 2026) | 103.0.9 | 8.4 / 8.5 | to May 2029 |
| 2.4.8 | 103.0.8 | 8.3 / 8.4 | to May 2028 |
| 2.4.7 | 103.0.7 | 8.2 / 8.3 | to May 2027 |
| 2.4.6 | 103.0.6 | 8.1 / 8.2 | extended to Aug 2027 |

`BMaGlass/Pay/composer.json` declares `magento/framework: 103.0.*`
(the framework series spanning all four minors) and `php: ^8.1`
(the union of their PHP ranges). Composer resolves the exact
framework build against the store's Magento version, and Adobe's
own platform requirements enforce the per-minor PHP pairing — the
module adds no constraint that could fight either. What the module
uses is stable across the whole range: `AbstractMethod` redirect
gateway, frontend controllers behind `CsrfAwareActionInterface`
(the webhook), cron, and `system.xml` config — no GraphQL schema,
no checkout-API or CSP surface that differs between these minors.

**Not covered:** Magento 2.4.5 and earlier — Adobe ended 2.4.5
support in August 2026 (security-only before that) and it is
PHP 8.1-only; upgrade the store rather than adding a payment
method to an unsupported platform.

## How it works

- Checkout: the order is placed as `pending_payment`, a payment link
  is created for the exact total (order currency, **ZMW/USD only**),
  and the buyer is redirected to the hosted page.
- Settlement: BMaGlass webhooks are HMAC-verified at
  `https://YOUR-STORE/bmaglass/webhook`. A `payment_link.paid` event
  reconciles its order immediately (confirmed via the API); a cron
  job (every 5 minutes) also polls the API's payment-link list as
  the fallback. Captures register **exactly once**.

## Install

1. Copy `BMaGlass/Pay` into `app/code/BMaGlass/Pay` (or require the
   package with Composer from this repo).
2. `bin/magento module:enable BMaGlass_Pay && bin/magento setup:upgrade && bin/magento cache:flush`
3. **Stores → Configuration → Sales → Payment Methods → BMaGlass Pay:**
   enable, paste your API key (live key required for checkout —
   the method hides itself for test keys, because test keys are
   read-only by design), register the webhook URL above in BMaGlass
   Pay and paste the signing secret shown once.
4. Set your store currency to ZMW or USD for the store view where
   BMaGlass should appear (other currencies hide the method — no
   exchange rate is ever invented).

## Honest limits

- Wallet-to-wallet only: buyers need a BMaGlass account (guest
  checkout is disabled on BMaGlass Pay by decision).
- Test keys = connection/read checks only; there is no test-mode
  ledger to fake a sandbox checkout with, so checkout needs a live
  key.

## Verification status

`php -l` clean on every file; `composer.json` parses and carries
the range above; the vendored client is the SDK client whose own
live-API protocol proof passed in the connectors mission (see the
mission addendum). **Install-tested 2026-10-26 on Magento 2.4.8: PASS** (MySQL 8 +
OpenSearch 2.19) — module enables, the method appears at checkout
once a live key is saved, a genuine guest checkout hands off to the
hosted pay page for the exact total, and a signed webhook delivery
captures the order to `processing` exactly once. The install test
found and fixed seven real defects in this module (encrypted-key
decryption, two Magento-1-era helper calls, the payment_review
settlement state handling, and the CSRF interface signatures) —
see VERSIONS.md.
