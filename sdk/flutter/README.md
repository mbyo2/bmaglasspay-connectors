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

- **Test keys are read-only** (no test-mode ledger): reads work;
  writes throw `test_mode_read_only` with a plain-language message.
- **Currencies:** ZMW and USD only; anything else throws
  `unsupported_currency` — no FX rate is ever invented.
- **API keys are secrets.** A `bmp_live_` key moves real money. In a
  consumer app, prefer your own backend holding the key server-side;
  embed a key only for the key owner's own account use.

## Verification status

**Verified 2026-10-27** on Dart SDK 3.13.7 (stable, linux x64 — the
Dart version bundled with Flutter 3.47.8): `dart analyze` reports no
issues and `dart test` passes **9/9**. This is a pure-Dart package
(no Flutter SDK dependency in `pubspec.yaml`), so the Dart toolchain
alone runs the whole suite. The tests in `test/bmaglass_sdk_test.dart` use
`package:http`'s MockClient (no network) and the same shared
cross-SDK HMAC known-answer vector as the other SDK suites:

```bash
cd sdk/flutter && dart test   # or: flutter test
```

The client code itself is a line-for-line port of the proven Python
SDK structure (same endpoints, headers and error mapping).
