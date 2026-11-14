// Live conformance runner for the API key environments build (2026-11-09).
//
// Runs the SHIPPED TypeScript SDK against the DEPLOYED developer-api,
// using real QA keys supplied via environment variables:
//
//   BMG_QA_ZM_SANDBOX   sandbox key for a Zambian QA business
//   BMG_QA_ZM_LIVE      production key for the same business
//   BMG_QA_KE_SANDBOX   sandbox key for a non-Zambian QA business
//   BMG_QA_KE_REVOKED   a revoked key (must fail closed, 401)
//   BMG_QA_ZM_TOGGLE    "on" if the Zambian business has mobile money on
//   BMG_QA_ZM_NOCONN    (optional) live key for a Zambian QA business
//                       with mobile money on but NO connection saved
//   BMG_QA_ZM_MOMO_LIVE (optional) "on" when the Zambian business's
//                       PRODUCTION mobile-money connection is backed
//                       by the sandbox gateway (api_base_url override),
//                       making a production-key collection safe to run
//   BMG_QA_WEBHOOK_URL  (optional) a real HTTPS listener URL for the
//                       webhook pins. When set, the test delivery is
//                       asserted delivered end-to-end (the listener
//                       must answer 200); otherwise the pins assert
//                       registration + listing only, against
//                       https://example.com (never asserted delivered).
//   BMG_QA_ZM_JWT       (optional) a user JWT for the Zambian QA
//                       business, enabling the key-rotation pins
//                       (rotate-api-key: new key works, old key works
//                       inside the 24h grace window). The grace-expiry
//                       half (old key → 401 api_key_expired past the
//                       window) is proven by direct expiry in the
//                       gate14 runbook, not here — the runner cannot
//                       fast-forward 24 hours.
//
// Covers: config fetch + rendering inputs, environment derivation and
// segregation, the mobile-money gate matrix, sandbox simulated
// settlement (invoice + payment link), live mobile-money collections
// through the developer API (create, settle, idempotent replay,
// merchant-reference lookup), fee-bearer totals (payment links:
// buyer / seller / default; momo: collection fee_bearer matches GET
// /settings and the arithmetic holds in the merchant's current mode),
// webhook registration + test delivery with a SANDBOX key (gate14:
// the pre-fix API refused both with 403 test_mode_read_only), and
// refusal cases. Exits non-zero on any failure. QA accounts only —
// never run this with a real merchant's keys: it creates sandbox
// objects and one production invoice on the key's own account.
import assert from "node:assert";
import { BMaGlass, BMaGlassError } from "../typescript/dist/index.js";

const env = (name) => {
  const v = process.env[name];
  if (!v) { console.error(`missing env ${name}`); process.exit(2); }
  return v;
};

let failures = 0;
const check = (label, cond) => {
  console.log(`${cond ? "PASS" : "FAIL"}  ${label}`);
  if (!cond) failures++;
};

const zmSandbox = new BMaGlass({ apiKey: env("BMG_QA_ZM_SANDBOX") });
const zmLive = new BMaGlass({ apiKey: env("BMG_QA_ZM_LIVE") });
const keSandbox = new BMaGlass({ apiKey: env("BMG_QA_KE_SANDBOX") });

// ── Environment discipline: from the key alone ──
check("SDK derives sandbox from bmg_sandbox_ key", zmSandbox.environment === "sandbox" && zmSandbox.mode === "test");
check("SDK derives production from bmg_live_ key", zmLive.environment === "production" && zmLive.mode === "live");

// ── Config fetch + rendering inputs ──
const cfg = await zmSandbox.connectorConfig();
check("config environment is sandbox", cfg.environment === "sandbox" && cfg.livemode === false);
check("config business is the key's own (ZM/ZMW)", cfg.business.country === "ZM" && cfg.business.currency === "ZMW");
check("config key_id present", typeof cfg.key.key_id === "string" && cfg.key.key_id.length > 0);
check("config carries config_version", typeof cfg.config_version === "string" && cfg.config_version.length > 0);
check("config settlement is type-only", cfg.settlement.type === "wallet" && !("balance" in cfg.settlement));
check("sandbox transfers disabled in config", cfg.features.transfers.status === "disabled" && cfg.features.transfers.reason_code === "sandbox_simulation_only");
const expectedMm = process.env.BMG_QA_ZM_TOGGLE === "on" ? "enabled" : "disabled";
check(`config mobile_money for ZM business (${expectedMm})`, cfg.features.mobile_money.status === expectedMm);

const keCfg = await keSandbox.connectorConfig();
check("non-ZM config country is KE", keCfg.business.country === "KE");
check("non-ZM mobile_money not_entitled/country_not_eligible",
  keCfg.features.mobile_money.status === "not_entitled" && keCfg.features.mobile_money.reason_code === "country_not_eligible");

// ── Sandbox simulated settlement via the SDK ──
const inv = await zmSandbox.invoices.create({ amount: 33, currency: "ZMW", description: "conformance invoice" });
check("sandbox invoice created open", inv.status === "open" && inv.environment === "sandbox");
const paid = await zmSandbox.invoices.simulatePayment(inv.id);
check("sandbox invoice simulated to paid", paid.status === "paid" && paid.paid_at !== null);

const link = await zmSandbox.paymentLinks.create({ title: "conformance link", amount: 21, currency: "ZMW" });
const linkPaid = await zmSandbox.paymentLinks.simulatePayment(link.id);
check("sandbox link simulated payment counted", linkPaid.payment_count === 1 && Number(linkPaid.total_collected) === 21);

// ── Mobile money collections through the API (SDK) ──
// The Zambian business is connected (sandbox connection, gateway
// test numbers — no real phone is charged). MTN 0961111111 is the
// gateway's always-succeeds test number.
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const pollCollection = async (client, reference) => {
  let c = await client.mobileMoney.getCollection(reference);
  for (let i = 0; i < 40 && c.status === "pending"; i++) {
    await sleep(3000);
    c = await client.mobileMoney.getCollection(reference);
  }
  return c;
};
const mmRef = `conformance-momo-${Date.now()}`;
const mmCreated = await zmSandbox.mobileMoney.createCollection({
  amount: 10, phone: "0961111111", reference: mmRef,
});
check("sandbox mobile money collection created pending",
  mmCreated.status === "pending" && mmCreated.merchant_reference === mmRef && mmCreated.environment === "sandbox");
const mmSettled = await pollCollection(zmSandbox, mmCreated.reference);
check("sandbox mobile money collection settles on poll", mmSettled.status === "successful");
check("settled collection carries its ledger transaction", typeof mmSettled.transaction_id === "string" && mmSettled.transaction_id.length > 0);
const mmReplay = await zmSandbox.mobileMoney.createCollection({
  amount: 10, phone: "0961111111", reference: mmRef,
});
check("create replay returns the same collection (idempotent)", mmReplay.reference === mmCreated.reference);
const mmByRef = await zmSandbox.mobileMoney.getCollection(mmRef);
check("collection readable by merchant reference", mmByRef.reference === mmCreated.reference && mmByRef.status === "successful");
await assert.rejects(() => keSandbox.mobileMoney.createCollection({ amount: 100, phone: "0961111111", reference: "conformance-momo-ke" }),
  (e) => e instanceof BMaGlassError && e.status === 403 && e.code === "not_entitled");
check("non-ZM create refused (403 not_entitled)", true);
if (process.env.BMG_QA_ZM_NOCONN) {
  const noconn = new BMaGlass({ apiKey: process.env.BMG_QA_ZM_NOCONN });
  await assert.rejects(() => noconn.mobileMoney.createCollection({ amount: 100, phone: "0961111111", reference: "conformance-momo-nc" }),
    (e) => e instanceof BMaGlassError && e.status === 400 && e.code === "not_configured");
  check("unconnected merchant create refused (400 not_configured)", true);
}
if (process.env.BMG_QA_ZM_MOMO_LIVE === "on") {
  const mmLive = await zmLive.mobileMoney.createCollection({
    amount: 100, phone: "0961111111", reference: "conformance-momo-live-1",
  });
  const mmLiveSettled = await pollCollection(zmLive, mmLive.reference);
  check("production-key collection settles (sandbox-backed connection)",
    mmLive.environment === "production" && mmLiveSettled.status === "successful");
}

// ── Fee-bearer totals (payment links) ──
// The link rate is 2.5%: buyer-pays adds it on top (seller receives
// the full price); seller-pays takes it out of what the seller
// receives. An omitted fee_payer resolves to the merchant default.
const settings = await zmSandbox.settings.get();
check("settings exposes the link fee-bearer default", settings.fee_payer_default === "seller" || settings.fee_payer_default === "buyer");
check("settings exposes the momo fee-bearer choice", settings.momo_fee_bearer === "seller" || settings.momo_fee_bearer === "buyer");
const rate = settings.platform_fee_percent;
const linkBuyer = await zmSandbox.paymentLinks.create({ title: "conformance buyer link", amount: 100, currency: "ZMW", fee_payer: "buyer" });
check("buyer link totals: fee on top, seller receives full price",
  linkBuyer.fee_payer === "buyer" && Number(linkBuyer.fee_amount) === Number((100 * rate / 100).toFixed(2)) &&
  Number(linkBuyer.buyer_total) === 100 + Number(linkBuyer.fee_amount) && Number(linkBuyer.seller_receives) === 100);
const linkSeller = await zmSandbox.paymentLinks.create({ title: "conformance seller link", amount: 100, currency: "ZMW", fee_payer: "seller" });
check("seller link totals: fee out of seller proceeds, buyer pays price",
  linkSeller.fee_payer === "seller" && Number(linkSeller.buyer_total) === 100 &&
  Number(linkSeller.seller_receives) === 100 - Number(linkSeller.fee_amount));
const linkDefault = await zmSandbox.paymentLinks.create({ title: "conformance default link", amount: 100, currency: "ZMW" });
check("default link resolves fee_payer from the merchant setting",
  linkDefault.fee_payer === settings.fee_payer_default);

// ── Fee-bearer consistency (mobile money) ──
// The sandbox collection settled above (mmSettled) must agree with
// GET /settings' momo_fee_bearer, and the arithmetic must hold in
// whichever mode the merchant is in: seller → amount == base;
// buyer → amount == base + 1% service charge, seller nets base.
// (Flipping the merchant's mode is a dashboard setting, exercised
// both ways in the gate14 runbook; this pin asserts consistency in
// the mode the merchant is currently in.)
check("momo collection fee_bearer matches GET /settings",
  mmSettled.fee_bearer === settings.momo_fee_bearer);
check("momo seller/buyer arithmetic holds for the settled collection",
  (mmSettled.fee_bearer === "seller" && Number(mmSettled.amount) === Number(mmSettled.base_amount)) ||
  (mmSettled.fee_bearer === "buyer" && Math.abs(Number(mmSettled.amount) - Number(mmSettled.base_amount) - Number(mmSettled.service_charge)) < 0.005));

// ── Webhooks with a SANDBOX key (gate14) ──
// A sandbox key registers an endpoint stamped environment 'test':
// it can only ever receive sandbox events, so registration must
// not be refused as a money move. The endpoint is removed again
// at the end of this section.
const hookUrl = process.env.BMG_QA_WEBHOOK_URL || "https://example.com/gate14-conformance-hook";
const hook = await zmSandbox.webhooks.create({ url: hookUrl, events: ["mobile_money.collection.completed", "payment_link.paid", "invoice.paid", "webhook.test"] });
check("sandbox key registers a webhook (201 + secret shown once)",
  typeof hook.id === "string" && typeof hook.secret === "string" && hook.secret.startsWith("whsec_") &&
  hook.environment === "sandbox" && hook.livemode === false);
const sbHooks = await zmSandbox.webhooks.list();
check("sandbox webhook list contains the new endpoint",
  sbHooks.some((h) => h.id === hook.id && h.url === hookUrl));
const liveHooks = await zmLive.webhooks.list();
check("production webhook list excludes the sandbox endpoint",
  liveHooks.every((h) => h.id !== hook.id));
const testRun = await zmSandbox.webhooks.test(hook.id);
const testDeliveries = Array.isArray(testRun) ? testRun : testRun.data ?? [];
check("sandbox test delivery is attempted and logged",
  testDeliveries.length >= 1 && testDeliveries[0].webhook_id === hook.id);
if (process.env.BMG_QA_WEBHOOK_URL) {
  check("sandbox test delivery reached the listener (delivered)",
    testDeliveries[0].status === "delivered" && testDeliveries[0].response_status === 200);
}
await zmSandbox.webhooks.remove(hook.id);
const sbHooksAfter = await zmSandbox.webhooks.list();
check("removed sandbox webhook shows inactive",
  sbHooksAfter.find((h) => h.id === hook.id)?.is_active === false);

// ── Key rotation (JWT-gated) ──
// rotate-api-key mints a replacement and leaves the old key working
// for a 24h grace window. Needs the merchant's user JWT (the
// rotation endpoint is dashboard-authenticated, not key-authenticated).
if (process.env.BMG_QA_ZM_JWT) {
  const apiBase = "https://hhxwwozpvgktuqutfkhc.supabase.co/functions/v1";
  const anon = process.env.BMG_QA_ANON_KEY;
  const rotRes = await fetch(`${apiBase}/rotate-api-key`, {
    method: "POST",
    headers: { Authorization: `Bearer ${process.env.BMG_QA_ZM_JWT}`, apikey: anon ?? "", "Content-Type": "application/json" },
    body: JSON.stringify({ key_id: cfg.key.key_id }),
  });
  const rot = await rotRes.json();
  check("rotation mints a replacement sandbox key", rotRes.ok && typeof rot.key === "string" && rot.key.startsWith("bmg_sandbox_"));
  check("rotation reports a ~24h grace window", typeof rot.grace_until === "string" && (new Date(rot.grace_until).getTime() - Date.now()) > 23 * 3600 * 1000);
  if (rotRes.ok) {
    const rotated = new BMaGlass({ apiKey: rot.key });
    const rotCfg = await rotated.connectorConfig();
    check("rotated key works", rotCfg.environment === "sandbox");
    const graceCfg = await zmSandbox.connectorConfig();
    check("old key still works inside the grace window", graceCfg.environment === "sandbox");
  }
} else {
  console.log("SKIP  key rotation pins (set BMG_QA_ZM_JWT to enable)");
}

// ── Segregation through the SDK ──
await assert.rejects(() => zmLive.invoices.get(inv.id),
  (e) => e instanceof BMaGlassError && e.status === 404);
check("production SDK client cannot read the sandbox invoice (404)", true);
const liveList = await zmLive.invoices.list();
check("production invoice list excludes sandbox invoices", liveList.every((i) => i.id !== inv.id));

// ── Refusals ──
await assert.rejects(() => zmLive.invoices.simulatePayment(inv.id),
  (e) => e instanceof BMaGlassError && e.status === 403 && e.code === "simulation_not_available");
check("production key refused at simulate-payment (simulation_not_available)", true);
await assert.rejects(() => zmSandbox.transfers.create({ to_user_id: "00000000-0000-0000-0000-000000000000", amount: 1 }),
  (e) => e instanceof BMaGlassError && (e.code === "insufficient_scope" || e.code === "test_mode_read_only"));
check("sandbox key cannot transfer real money", true);
const revoked = new BMaGlass({ apiKey: env("BMG_QA_KE_REVOKED") });
await assert.rejects(() => revoked.connectorConfig(),
  (e) => e instanceof BMaGlassError && e.status === 401 && e.code === "api_key_revoked");
check("revoked key fails closed (401 api_key_revoked)", true);

console.log(failures === 0 ? "\nCONFORMANCE (LIVE, TS SDK): ALL PASS" : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
