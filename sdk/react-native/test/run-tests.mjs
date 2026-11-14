/**
 * Tests for the React Native SDK (sdk/react-native), run in Node
 * (Node 24 runs the TypeScript sources directly via type stripping).
 * The pure-TS SHA-256/HMAC is cross-checked against node:crypto on
 * deterministic pseudo-random inputs, and pinned by the shared
 * cross-SDK known-answer webhook vector.
 *
 * Run: node sdk/react-native/test/run-tests.mjs
 */
import { createHmac, randomBytes } from 'node:crypto';
import { BMaGlass, BMaGlassError, verifyWebhookSignature, parseWebhookEvent, paymentLinkUrl } from '../src/index.ts';
import { sha256, hmacSha256, toHex } from '../src/sha256.ts';
import { createHash } from 'node:crypto';

let failures = 0;
const check = (label, cond) => { console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}`); if (!cond) failures++; };

// --- SHA-256 / HMAC cross-checks vs node:crypto ---
let rngState = 42;
const nextByte = () => (rngState = (rngState * 1103515245 + 12345) & 0x7fffffff) % 256;
for (const len of [0, 1, 55, 56, 64, 65, 200]) {
  const data = new Uint8Array(len);
  for (let i = 0; i < len; i++) data[i] = nextByte();
  const mine = toHex(sha256(data));
  const real = createHash('sha256').update(data).digest('hex');
  check(`sha256 len=${len}`, mine === real);
}
{
  const key = new Uint8Array(100).map(nextByte); // > block size, exercises key hashing
  const data = new TextEncoder().encode('bmaglass hmac cross-check');
  const mine = toHex(hmacSha256(key, data));
  const real = createHmac('sha256', key).update(data).digest('hex');
  check('hmac long-key matches node:crypto', mine === real);
}

// --- Shared cross-SDK known-answer vector ---
const RAW = '{"id":"evt_1","type":"webhook.test","created":"2026-10-26T00:00:00.000Z","data":{"ping":true}}';
const SIG = 'sha256=ff522c97ada5bfd4f6c09a0457823f89546896708e8e1f5bbb72d2ad59d68535';
check('KAT verifies', verifyWebhookSignature(RAW, SIG, 'whsec_testsecret') === true);
check('KAT wrong secret fails', verifyWebhookSignature(RAW, SIG, 'whsec_wrong') === false);
check('parseWebhookEvent type', parseWebhookEvent(RAW, SIG, 'whsec_testsecret')?.type === 'webhook.test');
check('paymentLinkUrl', paymentLinkUrl('ABC123') === 'https://bmaglasspay.online/pay/ABC123');

// --- Client behaviour with a stub fetch ---
function stubFetch(handler) {
  const calls = [];
  const fn = async (url, init) => {
    calls.push({ url, init });
    const res = handler(url, init);
    return { ok: res.status >= 200 && res.status < 300, status: res.status, text: async () => JSON.stringify(res.body) };
  };
  fn.calls = calls;
  return fn;
}

{
  const fetchImpl = stubFetch((url, init) => {
    if (!String(init.headers['X-Api-Key']).startsWith('bmp_')) return { status: 401, body: { error: 'invalid_api_key', message: 'nope' } };
    if (url.endsWith('/wallets')) return { status: 200, body: { data: [{ id: 'w1', currency: 'ZMW', balance: 42 }], mode: 'test' } };
    if (url.endsWith('/settings')) return { status: 200, body: { data: { fee_payer_default: 'seller', platform_fee_percent: 2.5, paypal_platform_fee_percent: 1, note: 'n' }, mode: 'test' } };
    if (url.endsWith('/payment-links/link1/attempts')) return { status: 200, body: { data: [{ id: 'att1', status: 'waiting' }], mode: 'test' } };
    if (url.endsWith('/payment-links') && init.method === 'POST') {
      // Sandbox keys CAN create payment links (settlement is simulation-only);
      // only transfers/webhook registration are live-only. The stub echoes the
      // per-link fee_payer + redirect_url and the resolved fee maths, as live does.
      const b = JSON.parse(init.body || '{}');
      const feePayer = b.fee_payer ?? 'seller';
      const amount = b.amount ?? 0;
      const fee = Math.round(amount * 0.025 * 100) / 100;
      return { status: 201, body: { data: { id: 'pl1', title: b.title, amount, currency: b.currency ?? 'ZMW', link_code: 'ABC123', status: 'active', fee_payer: feePayer, redirect_url: b.redirect_url ?? null, payment_count: 0, total_collected: 0, fee_amount: fee, buyer_total: feePayer === 'buyer' ? amount + fee : amount, seller_receives: feePayer === 'seller' ? amount - fee : amount }, mode: 'test' } };
    }
    if (url.endsWith('/transfers') && init.method === 'POST') return { status: 403, body: { error: 'test_mode_read_only', message: 'Test keys are read-only' } };
    return { status: 404, body: { error: 'not_found', message: 'no route' } };
  });
  const client = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl });
  check('mode is test', client.mode === 'test');
  check('environment is sandbox', client.environment === 'sandbox');
  check('bmg live environment is production', new BMaGlass({ apiKey: 'bmg_live_x', baseUrl: 'http://local.test', fetchImpl }).environment === 'production');
  const wallets = await client.wallets.list();
  check('wallets balance', wallets[0].balance === 42);
  const settings = await client.settings.get();
  check('settings fee default', settings.fee_payer_default === 'seller' && settings.platform_fee_percent === 2.5 && settings.paypal_platform_fee_percent === 1);
  check('attempts', (await client.paymentLinks.attempts('link1'))[0].status === 'waiting');
  // Sandbox keys CAN create payment links (live API, proven 2026-11-14);
  // only transfers/webhook registration are live-only.
  const feeLink = await client.paymentLinks.create({ title: 'Order #8', amount: 10, currency: 'ZMW', fee_payer: 'buyer', redirect_url: 'https://example.invalid/return' });
  check('sandbox link fee_payer', feeLink.fee_payer === 'buyer' && feeLink.fee_amount === 0.25 && feeLink.buyer_total === 10.25 && feeLink.seller_receives === 10);
  check('sandbox link redirect_url', feeLink.redirect_url === 'https://example.invalid/return');
  const sellerLink = await client.paymentLinks.create({ title: 'Order #9', amount: 10, currency: 'ZMW' });
  check('default link fee_payer', sellerLink.fee_payer === 'seller' && sellerLink.seller_receives === 9.75);
  try {
    await client.transfers.create({ to_user_id: 'u2', amount: 5, currency: 'ZMW' });
    check('test-key transfer throws', false);
  } catch (e) {
    check('test-key transfer throws typed error', e instanceof BMaGlassError && e.code === 'test_mode_read_only' && e.isTestModeReadOnly);
    check('userMessage honest', e.userMessage().includes('sandbox key'));
  }
  try {
    await client.transfers.create({ to_user_id: 'u2', amount: 1, currency: 'EUR' });
    check('EUR refused', false);
  } catch (e) {
    check('EUR refused client-side', e.code === 'unsupported_currency');
  }
  try {
    new BMaGlass({ apiKey: 'sk_live_nope', fetchImpl });
    check('foreign key rejected', false);
  } catch (e) {
    check('foreign key rejected', e.code === 'invalid_api_key');
  }
}

console.log(failures === 0 ? '\nALL REACT NATIVE SDK TESTS PASSED' : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
