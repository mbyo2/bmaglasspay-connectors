// Contract tests for @bmaglass/sdk against a faithful local harness that
// implements the developer-api contract (same routes, headers, error
// shapes as supabase/functions/developer-api). Run: npm test in
// sdk/typescript (builds first), or node sdk/tests/ts-sdk.contract.mjs after
// building sdk/typescript.
import assert from "node:assert";
import { createServer } from "node:http";
import { BMaGlass, BMaGlassError, verifyWebhookSignature, parseWebhookEvent } from "../typescript/dist/index.js";

const seen = [];
const server = createServer((req, res) => {
  let body = "";
  req.on("data", (c) => (body += c));
  req.on("end", () => {
    seen.push({ method: req.method, url: req.url, key: req.headers["x-api-key"], idem: req.headers["idempotency-key"], body });
    const reply = (status, obj) => { res.writeHead(status, { "Content-Type": "application/json" }); res.end(JSON.stringify(obj)); };
    if (!["bmp_test_contract", "bmg_sandbox_contract"].includes(req.headers["x-api-key"])) return reply(401, { error: "invalid_api_key", message: "nope" });
    if (req.url === "/connector/config") return reply(200, { data: { environment: "sandbox", livemode: false, config_version: "abc123", key: { key_id: "k1", scopes: ["config:read"], status: "active" }, business: { display_name: "QA Store", country: "ZM", currency: "ZMW", status: "pending" }, features: { invoices: { status: "enabled", reason_code: null }, payment_links: { status: "enabled", reason_code: null }, bitcoin: { status: "enabled", reason_code: null }, transfers: { status: "disabled", reason_code: "sandbox_simulation_only" }, mobile_money: { status: "disabled", reason_code: null, connected: false, fee_bearer: "seller" } }, settlement: { type: "wallet", currency: "ZMW" } }, mode: "test" });
    if (req.url === "/invoices" && req.method === "POST") return reply(201, { data: { id: "inv1", amount: 250, currency: "ZMW", description: null, customer_email: null, status: "open", environment: "sandbox", livemode: false, metadata: {}, paid_at: null, created_at: "t" }, mode: "test" });
    if (req.url === "/invoices/inv1/simulate-payment") return reply(200, { data: { id: "inv1", amount: 250, currency: "ZMW", description: null, customer_email: null, status: "paid", environment: "sandbox", livemode: false, metadata: {}, paid_at: "t", created_at: "t" }, mode: "test", simulated: true });
    if (req.url === "/wallets") return reply(200, { data: [{ id: "w1", currency: "ZMW", balance: 42, status: "active", created_at: "x", updated_at: "y" }], mode: "test" });
    if (req.url?.startsWith("/transactions")) return reply(200, { data: [], mode: "test" });
    if (req.url === "/transfers") return reply(403, { error: "test_mode_read_only", message: "Test keys are read-only" });
    if (req.url === "/payment-links" && req.method === "GET") return reply(200, { data: [], mode: "test" });
    if (req.url === "/payment-links" && req.method === "POST") {
      // Sandbox keys CAN create payment links (settlement is simulation-only);
      // only transfers/webhook registration are live-only. The stub echoes the
      // per-link fee_payer + redirect_url and the resolved fee maths, as live does.
      // Like live, the create response omits description / payment_count /
      // total_collected / expires_at (the create select has no such columns).
      const b = JSON.parse(body || "{}");
      const feePayer = b.fee_payer ?? "seller";
      const fee = Math.round((b.amount ?? 0) * 0.025 * 100) / 100;
      return reply(201, { data: { id: "pl1", title: b.title, amount: b.amount ?? null, currency: b.currency ?? "ZMW", link_code: "ABC123", status: "active", fee_payer: feePayer, redirect_url: b.redirect_url ?? null, created_at: "t", fee_amount: fee, buyer_total: feePayer === "buyer" ? (b.amount ?? 0) + fee : (b.amount ?? 0), seller_receives: feePayer === "seller" ? (b.amount ?? 0) - fee : (b.amount ?? 0) }, mode: "test" });
    }
    if (req.url === "/bitcoin/balance") return reply(200, { data: [{ asset: "BTC", balance_sats: 1000, available_sats: 1000, updated_at: "z" }], mode: "test" });
    if (req.url === "/webhooks" && req.method === "POST") return reply(201, { data: { id: "wh1", url: "https://example.com/hook", events: ["webhook.test"], is_active: true, created_at: "t", secret: "whsec_testsecret" }, mode: "test" });
    if (req.url === "/settings") return reply(200, { data: { fee_payer_default: "seller", platform_fee_percent: 2.5, paypal_platform_fee_percent: 1, momo_fee_bearer: "seller", note: "n" }, mode: "test" });
    if (req.url === "/payment-links/link1/attempts") return reply(200, { data: [{ id: "att1", status: "waiting" }], mode: "test" });
    if (req.url === "/mobile-money/collections" && req.method === "POST") { const b = JSON.parse(body || "{}"); return reply(201, { data: { reference: "BMG-C1", merchant_reference: b.reference ?? null, collection_id: "col1", transaction_id: null, amount: b.amount, base_amount: b.amount, service_charge: Math.round((b.amount ?? 0) * 0.01 * 100) / 100, fee_bearer: "seller", currency: "ZMW", operator: b.operator ?? "mtn", phone: b.phone, status: "pending", failure_reason: null, environment: "sandbox", livemode: false, wallet_balance: null, settled_at: null, created_at: "t" }, mode: "test" }); }
    if (req.url === "/mobile-money/collections/BMG-C1" && req.method === "GET") return reply(200, { data: { reference: "BMG-C1", merchant_reference: "order-42", collection_id: "col1", transaction_id: "tx1", amount: 100, base_amount: 100, service_charge: 1, fee_bearer: "seller", currency: "ZMW", operator: "mtn", phone: "0961111111", status: "successful", failure_reason: null, environment: "sandbox", livemode: false, wallet_balance: 99, settled_at: "t", created_at: "t" }, mode: "test" });
    return reply(404, { error: "not_found", message: "no route" });
  });
});
await new Promise((r) => server.listen(0, r));
const base = `http://127.0.0.1:${server.address().port}`;

const client = new BMaGlass({ apiKey: "bmp_test_contract", baseUrl: base });
assert.equal(client.mode, "test");
assert.equal(client.environment, "sandbox");
assert.equal(new BMaGlass({ apiKey: "bmp_live_x", baseUrl: base }).mode, "live");
assert.equal(new BMaGlass({ apiKey: "bmp_live_x", baseUrl: base }).environment, "production");
assert.equal(new BMaGlass({ apiKey: "bmg_sandbox_x", baseUrl: base }).environment, "sandbox");
assert.equal(new BMaGlass({ apiKey: "bmg_live_x", baseUrl: base }).environment, "production");

const sandboxClient = new BMaGlass({ apiKey: "bmg_sandbox_contract", baseUrl: base });
const cfg = await sandboxClient.connectorConfig();
assert.equal(cfg.environment, "sandbox");
assert.equal(cfg.business.country, "ZM");
assert.equal(cfg.features.mobile_money.status, "disabled");
assert.equal(cfg.features.transfers.reason_code, "sandbox_simulation_only");
const inv = await sandboxClient.invoices.create({ amount: 250, currency: "ZMW" });
assert.equal(inv.status, "open");
const paidInv = await sandboxClient.invoices.simulatePayment("inv1");
assert.equal(paidInv.status, "paid");
assert.throws(() => new BMaGlass({ apiKey: "sk_live_nope" }), BMaGlassError);

const wallets = await client.wallets.list();
assert.equal(wallets[0].balance, 42);
assert.equal(seen.at(-1).key, "bmp_test_contract");
await client.transactions.list(5);
assert.ok(seen.at(-1).url.includes("limit=5"));
await assert.rejects(() => client.transfers.create({ to_user_id: "u2", amount: 10 }, "idem-1"),
  (e) => e instanceof BMaGlassError && e.status === 403 && e.code === "test_mode_read_only");
assert.equal(seen.at(-1).idem, "idem-1");
assert.deepEqual(await client.paymentLinks.list(), []);
// Per-link fee_payer override + redirect_url (live API, proven 2026-11-14):
// a sandbox key creates the link; the resolved fee maths ride on the row.
const feeLink = await client.paymentLinks.create({ title: "Order #8", amount: 10, currency: "ZMW", fee_payer: "buyer", redirect_url: "https://example.invalid/return" });
assert.equal(feeLink.fee_payer, "buyer");
assert.equal(feeLink.redirect_url, "https://example.invalid/return");
assert.equal(feeLink.fee_amount, 0.25);
assert.equal(feeLink.buyer_total, 10.25);
assert.equal(feeLink.seller_receives, 10);
assert.deepEqual(JSON.parse(seen.at(-1).body), { title: "Order #8", amount: 10, currency: "ZMW", fee_payer: "buyer", redirect_url: "https://example.invalid/return" });
const sellerLink = await client.paymentLinks.create({ title: "Order #9", amount: 10, currency: "ZMW" });
assert.equal(sellerLink.fee_payer, "seller");
assert.equal(sellerLink.seller_receives, 9.75);
assert.equal((await client.bitcoin.balance())[0].balance_sats, 1000);
const hook = await client.webhooks.create({ url: "https://example.com/hook" });
assert.equal(hook.secret, "whsec_testsecret");
const settings = await client.settings.get();
assert.equal(settings.fee_payer_default, "seller");
assert.equal(settings.platform_fee_percent, 2.5);
assert.equal(settings.paypal_platform_fee_percent, 1);
// Live GET /settings also returns the momo fee-bearer (gate14-api-webhook-a1).
assert.equal(settings.momo_fee_bearer, "seller");
assert.equal((await client.paymentLinks.attempts("link1"))[0].status, "waiting");

const coll = await client.mobileMoney.createCollection({ amount: 100, phone: "0961111111", reference: "order-42" });
assert.equal(coll.status, "pending");
assert.equal(coll.reference, "BMG-C1");
assert.equal(coll.merchant_reference, "order-42");
// Live collection shape: amount is the total charged; base_amount is the
// product amount, service_charge the 1% on it — in BOTH modes (the
// seller funds it in seller mode; it is NOT 0 — the old stub said 0).
assert.equal(coll.base_amount, 100);
assert.equal(coll.service_charge, 1);
assert.equal(coll.fee_bearer, "seller");
assert.equal(cfg.features.mobile_money.connected, false);
assert.equal(cfg.features.mobile_money.fee_bearer, "seller");
assert.equal(seen.at(-1).url, "/mobile-money/collections");
assert.deepEqual(JSON.parse(seen.at(-1).body), { amount: 100, phone: "0961111111", reference: "order-42" });
const collStatus = await client.mobileMoney.getCollection("BMG-C1");
assert.equal(collStatus.status, "successful");
assert.equal(collStatus.wallet_balance, 99);
assert.equal(collStatus.base_amount, 100);
assert.equal(collStatus.service_charge, 1);
assert.equal(collStatus.fee_bearer, "seller");
const collByMerchantRef = await client.mobileMoney.getCollection("order-42").catch((e) => e);
assert.ok(collByMerchantRef instanceof BMaGlassError && collByMerchantRef.status === 404); // stub only knows the platform reference

// Webhook verification: known-answer vector (HMAC-SHA256, computed independently).
const raw = '{"id":"evt_1","type":"webhook.test","created":"2026-10-26T00:00:00.000Z","data":{"ping":true}}';
const sig = "sha256=ff522c97ada5bfd4f6c09a0457823f89546896708e8e1f5bbb72d2ad59d68535";
assert.equal(verifyWebhookSignature(raw, sig, "whsec_testsecret"), true);
assert.equal(verifyWebhookSignature(raw, sig, "whsec_wrong"), false);
assert.equal(verifyWebhookSignature(raw + " ", sig, "whsec_testsecret"), false);
assert.equal(parseWebhookEvent(raw, sig, "whsec_testsecret").type, "webhook.test");
assert.equal(parseWebhookEvent(raw, "sha256=deadbeef", "whsec_testsecret"), null);

// Live create responses omit description / payment_count /
// total_collected / expires_at (the create select has no such columns):
// the PaymentLink type keeps them optional, never required.
assert.equal(feeLink.description, undefined);
assert.equal(feeLink.payment_count, undefined);
assert.equal(feeLink.total_collected, undefined);
assert.equal(feeLink.expires_at, undefined);

import { paymentLinkUrl, assertSupportedCurrency } from "../typescript/dist/index.js";
assert.equal(paymentLinkUrl("ABC123"), "https://bmaglasspay.online/pay/ABC123");
assert.equal(paymentLinkUrl("a/b"), "https://bmaglasspay.online/pay/a%2Fb");
assert.equal(assertSupportedCurrency("zmw"), "ZMW");
assert.throws(() => assertSupportedCurrency("EUR"), (e) => e.code === "unsupported_currency");

// ── ext-hunt fixes: client-side validation + encoding + transport ──
const callsBefore = seen.length;
// Currency guard: transfers + payment links refuse non-wallet currencies
// before any request is sent; invoices are NOT guarded (the API accepts
// any 3-letter invoice currency).
await assert.rejects(async () => client.transfers.create({ to_user_id: "u2", amount: 10, currency: "EUR" }),
  (e) => e instanceof BMaGlassError && e.code === "unsupported_currency");
await assert.rejects(async () => client.paymentLinks.create({ title: "Euro", amount: 10, currency: "EUR" }),
  (e) => e instanceof BMaGlassError && e.code === "unsupported_currency");
assert.equal(seen.length, callsBefore, "refused calls must not hit the network");
const eurInvoice = await client.invoices.create({ amount: 10, currency: "EUR" });
assert.equal(eurInvoice.status, "open");
assert.equal(JSON.parse(seen.at(-1).body).currency, "EUR", "EUR invoice currency must reach the API (it accepts any invoice currency)");
// fee_payer validation (the API 400s it; the SDK refuses it first).
await assert.rejects(async () => client.paymentLinks.create({ title: "Bad", amount: 10, fee_payer: "nobody" }),
  (e) => e instanceof BMaGlassError && e.code === "invalid_fee_payer");
// side validation (a typo used to silently become a buy quote).
await assert.rejects(async () => client.bitcoin.quote({ side: "bogus", amount_zmw: 100 }),
  (e) => e instanceof BMaGlassError && e.code === "invalid_side");
// Ids are URL-encoded (an id containing "/" used to split the path).
// The stub 404s these unknown ids — the assertion is the requested URL.
await client.invoices.get("a/b").catch(() => {});
assert.equal(seen.at(-1).url, "/invoices/a%2Fb");
await client.paymentLinks.attempts("x/y").catch(() => {});
assert.equal(seen.at(-1).url, "/payment-links/x%2Fy/attempts");
// transactions.limit is clamped to 1..100 (the server clamps too, but the
// URL the SDK sends should say what it means).
await client.transactions.list(0);
assert.ok(seen.at(-1).url.includes("limit=1"));
await client.transactions.list(500);
assert.ok(seen.at(-1).url.includes("limit=100"));
// Transport failures are typed BMaGlassErrors, never raw TypeErrors.
const throwing = new BMaGlass({ apiKey: "bmp_test_contract", baseUrl: base, fetchImpl: async () => { throw new TypeError("fetch failed"); } });
await assert.rejects(() => throwing.wallets.list(),
  (e) => e instanceof BMaGlassError && e.status === 0 && e.code === "network_error");
// A request that never settles fails as network_error after timeoutMs.
const hanging = new BMaGlass({ apiKey: "bmp_test_contract", baseUrl: base, timeoutMs: 50, fetchImpl: () => new Promise(() => {}) });
const timedOut = await Promise.race([
  hanging.wallets.list().then(() => "RESOLVED?!").catch((e) => (e instanceof BMaGlassError && e.code === "network_error" ? "TIMEOUT-TYPED" : `WRONG-ERROR ${e}`)),
  new Promise((r) => setTimeout(() => r("GUARD"), 3000)),
]);
assert.equal(timedOut, "TIMEOUT-TYPED");
// Non-JSON error bodies still map to a typed error (no JSON.parse throw).
const html502 = new BMaGlass({ apiKey: "bmp_test_contract", baseUrl: base, fetchImpl: async () => ({ ok: false, status: 502, text: async () => "<html>Bad Gateway</html>" }) });
await assert.rejects(() => html502.wallets.list(),
  (e) => e instanceof BMaGlassError && e.status === 502 && e.code === "request_failed");

server.close();
console.log("TS SDK contract tests: ALL PASS");
