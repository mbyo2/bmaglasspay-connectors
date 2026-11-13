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
//
// Covers: config fetch + rendering inputs, environment derivation and
// segregation, the mobile-money gate matrix, sandbox simulated
// settlement (invoice + payment link), live mobile-money collections
// through the developer API (create, settle, idempotent replay,
// merchant-reference lookup), and refusal cases. Exits non-zero on
// any failure. QA accounts only — never run this with a real
// merchant's keys: it creates sandbox objects and one production
// invoice on the key's own account.
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
const mmCreated = await zmSandbox.mobileMoney.createCollection({
  amount: 100, phone: "0961111111", reference: "conformance-momo-1",
});
check("sandbox mobile money collection created pending",
  mmCreated.status === "pending" && mmCreated.merchant_reference === "conformance-momo-1" && mmCreated.environment === "sandbox");
const mmSettled = await pollCollection(zmSandbox, mmCreated.reference);
check("sandbox mobile money collection settles on poll", mmSettled.status === "successful");
check("settled collection carries its ledger transaction", typeof mmSettled.transaction_id === "string" && mmSettled.transaction_id.length > 0);
const mmReplay = await zmSandbox.mobileMoney.createCollection({
  amount: 100, phone: "0961111111", reference: "conformance-momo-1",
});
check("create replay returns the same collection (idempotent)", mmReplay.reference === mmCreated.reference);
const mmByRef = await zmSandbox.mobileMoney.getCollection("conformance-momo-1");
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
