# BMaGlass Pay SDK for React Native / Expo

A thin typed client over the BMaGlass Pay developer API — the same
surface as the TypeScript, Python and PHP SDKs, adapted for React
Native:

- `fetch`-based (global fetch; injectable for tests).
- Webhook verification uses a **bundled pure-TS HMAC-SHA256**
  (`src/sha256.ts`) because Hermes has no WebCrypto `subtle`. It is
  pinned by the shared cross-SDK known-answer vector and
  cross-checked against Node's crypto in the tests.

## Install

This package lives in the BMaGlass Pay repository under
`sdk/react-native`. It is **not published on npm** — add it from the
repo (e.g. as a file:/git dependency) or copy `src/` into your app.

## Use

```ts
import { BMaGlass, BMaGlassError, parseWebhookEvent, paymentLinkUrl } from "@bmaglass/sdk-react-native";

const client = new BMaGlass({ apiKey: process.env.BMGLASS_API_KEY! }); // bmg_live_... / bmg_sandbox_... (legacy bmp_ keys work)

const wallets = await client.wallets.list();
const link = await client.paymentLinks.create({ title: "Order #7", amount: 250, currency: "ZMW" });
const url = paymentLinkUrl(link.link_code); // open in a browser / WebView for the buyer

try {
  await client.transfers.create({ to_user_id, amount: 100, currency: "ZMW" }, "idempotency-key-1");
} catch (e) {
  if (e instanceof BMaGlassError) show(e.userMessage()); // plain language, safe to display
}
```

## Honest limits & security

- **Sandbox keys use simulated money**: reads, sandbox object
  creation + simulation work (webhook registration works too —
  sandbox endpoints receive sandbox events); real-money
  writes throw `test_mode_read_only`.
- **Currencies:** transfers and payment links are ZMW and USD only
  (the currencies BMaGlass wallets hold); anything else throws
  `unsupported_currency` client-side — no FX rate is ever invented.
  Invoices are not guarded: the API accepts any 3-letter invoice
  currency (a payment request, not a wallet move).
- **API keys are secrets.** A production key (`bmg_live_`, legacy `bmp_live_`) moves real money. In a
  consumer app, prefer calling your own backend with the key held
  server-side; ship a key in an app binary only for the key owner's
  own account use (e.g. the merchant's own device).

## Test

```bash
node sdk/react-native/test/run-tests.mjs
```

(Node ≥ 22 runs the TypeScript sources via type stripping; 50 checks:
SHA-256/HMAC vs `node:crypto`, shared webhook KAT, stub-fetch client
behaviour, currency + test-mode guards, transport timeouts and id
encoding, plus the transient-retry pins.)

## Retries

Transient 502/503/504 (and transport failures) are retried automatically: up to 3 attempts total,
exponential backoff with full jitter (base 500 ms, see `maxRetries` / `retryBaseDelayMs` in
`BMaGlassOptions`). Only idempotent requests are retried — every GET/DELETE, and POSTs carrying an
`Idempotency-Key`. Payment-link, invoice and transfer creates always send one key per logical call
(pass your own as the second argument, or let the SDK generate one) and reuse it across retries, so
a retried create replays the original object instead of creating a second one. `bitcoin/quote`,
`simulate-payment` and webhook create/test are never retried (no idempotency key). Disable with
`maxRetries: 0`.
