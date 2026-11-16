# BMaGlass Pay SDK for Flutter

A thin Dart client over the BMaGlass Pay developer API — the same
surface as the TypeScript, Python, PHP and React Native SDKs:
wallets, transactions, transfers, payment links, Bitcoin and
webhooks, against your own BMaGlass Pay account.

## Install

This package lives in the BMaGlass Pay connectors repository
([bmaglasspay-connectors](https://github.com/mbyo2/bmaglasspay-connectors))
under `sdk/flutter`. It is **not published on pub.dev** — depend on it
from the repo (path/git dependency) or copy `lib/` into your app.

```yaml
dependencies:
  bmaglass_sdk:
    path: ../bmaglasspay-connectors/sdk/flutter   # adjust to your checkout
```

## Use

```dart
import 'package:bmaglass_sdk/bmaglass_sdk.dart';

final client = BMaGlass(apiKey: const String.fromEnvironment('BMGLASS_API_KEY'));

final wallets = await client.wallets();
final link = await client.createPaymentLink(title: 'Order #7', amount: 250, currency: 'ZMW');
final url = BMaGlass.paymentLinkUrl(link.linkCode); // open for the buyer

try {
  await client.createTransfer(toUserId: uid, amount: 100, currency: 'ZMW', idempotencyKey: 'key-1');
} on BMaGlassException catch (e) {
  showMessage(e.userMessage()); // plain language, safe to display
}

// Server-side / isolate webhook verification:
final event = parseWebhookEvent(rawBody, signatureHeader, webhookSecret);
```

## Honest limits & security

- **Sandbox keys use simulated money**: reads work, sandbox invoices
  and payment links can be created and settled via simulation; real-money
  writes throw `test_mode_read_only` with a plain-language message.
- **Currencies:** transfers and payment links are ZMW and USD only
  (the currencies BMaGlass wallets hold); anything else throws
  `unsupported_currency` — no FX rate is ever invented. Invoices are
  not guarded: the API accepts any 3-letter invoice currency (a
  payment request, not a wallet move).
- **API keys are secrets.** A production key (`bmg_live_`, legacy `bmp_live_`) moves real money. In a
  consumer app, prefer your own backend holding the key server-side;
  embed a key only for the key owner's own account use.

## Verification status

**Verified 2026-11-16** on Dart SDK 3.14.0 (stable, linux x64 — cached
at `~/.cache/bmaglass-dart-sdk/dart-sdk` on this VM): `dart analyze`
reports no issues and `dart test` passes **24/24**. The earlier
"unrunnable here" note was wrong — the cached Dart toolchain runs the
whole suite; no Flutter SDK install is needed. This is a pure-Dart package
(no Flutter SDK dependency in `pubspec.yaml`), so the Dart toolchain
alone runs the whole suite. The tests in `test/bmaglass_sdk_test.dart` use
`package:http`'s MockClient (no network) and the same shared
cross-SDK HMAC known-answer vector as the other SDK suites:

```bash
cd sdk/flutter && dart test   # or: flutter test
```

## Retries

Transient 502/503/504 (and transport failures) are retried automatically: up to 3 attempts total,
exponential backoff with full jitter (base 500 ms, see `maxRetries` / `retryBaseDelay` /
`sleeper`). Only idempotent requests are retried — every GET/DELETE, and POSTs carrying an
`Idempotency-Key`. Payment-link, invoice and transfer creates always send one key per logical call
(pass your own `idempotencyKey`, or let the SDK generate one) and reuse it across retries, so a
retried create replays the original object instead of creating a second one. `bitcoinQuote`,
`simulatePaymentLinkPayment` / `simulateInvoicePayment` and webhook create/test are never retried
(no idempotency key). Disable with `maxRetries: 0`.

The client code itself is a line-for-line port of the proven Python
SDK structure (same endpoints, headers and error mapping).
