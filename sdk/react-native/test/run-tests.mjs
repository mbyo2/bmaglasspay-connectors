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
    if (url.endsWith('/payment-links/link1')) return { status: 200, body: { data: { id: 'pl1', title: 'Order #8', amount: 10, currency: 'ZMW', link_code: 'ABC123', status: 'active', fee_payer: 'seller', fee_amount: 0.25, buyer_total: 10, seller_receives: 9.75, payment_count: 0, total_collected: 0, created_at: 't' }, mode: 'test' } };
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
  check('get one link', (await client.paymentLinks.get('link1')).link_code === 'ABC123');
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

// --- ext-hunt fixes: transport, encoding, client-side validation ---
{
  // Transport failures are typed BMaGlassErrors, never raw TypeErrors.
  const throwing = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: async () => { throw new TypeError('fetch failed'); } });
  try { await throwing.wallets.list(); check('network throw typed', false); }
  catch (e) { check('network throw typed', e instanceof BMaGlassError && e.status === 0 && e.code === 'network_error'); }

  // A request that never settles fails as network_error after timeoutMs.
  const hanging = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', timeoutMs: 50, fetchImpl: () => new Promise(() => {}) });
  const timedOut = await Promise.race([
    hanging.wallets.list().then(() => 'RESOLVED?!').catch((e) => (e instanceof BMaGlassError && e.code === 'network_error' ? 'TIMEOUT-TYPED' : `WRONG-ERROR ${e}`)),
    new Promise((r) => setTimeout(() => r('GUARD'), 3000)),
  ]);
  check('hanging request times out typed', timedOut === 'TIMEOUT-TYPED');

  const fetchImpl = stubFetch((url, init) => {
    if (url.endsWith('/invoices') && init.method === 'POST') return { status: 201, body: { data: { id: 'inv1', status: 'open' }, mode: 'test' } };
    return { status: 404, body: { error: 'not_found', message: 'no route' } };
  });
  const client = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl });
  // Ids are URL-encoded (an id containing "/" used to split the path).
  await client.invoices.get('a/b').catch(() => {});
  check('invoice id encoded', fetchImpl.calls.at(-1).url === 'http://local.test/invoices/a%2Fb');
  await client.paymentLinks.attempts('x/y').catch(() => {});
  check('attempts id encoded', fetchImpl.calls.at(-1).url === 'http://local.test/payment-links/x%2Fy/attempts');
  await client.webhooks.test('w/z').catch(() => {});
  check('webhook id encoded', fetchImpl.calls.at(-1).url === 'http://local.test/webhooks/w%2Fz/test');
  // Client-side refusals send no request at all.
  const before = fetchImpl.calls.length;
  try { await client.paymentLinks.create({ title: 'Bad', amount: 10, fee_payer: 'nobody' }); check('bad fee_payer refused', false); }
  catch (e) { check('bad fee_payer refused', e.code === 'invalid_fee_payer'); }
  try { await client.bitcoin.quote({ side: 'bogus', amount_zmw: 100 }); check('bad side refused', false); }
  catch (e) { check('bad side refused', e.code === 'invalid_side'); }
  try { await client.paymentLinks.create({ title: 'Euro', amount: 10, currency: 'EUR' }); check('EUR link refused', false); }
  catch (e) { check('EUR link refused', e.code === 'unsupported_currency'); }
  check('refused calls sent nothing', fetchImpl.calls.length === before);
  // Invoices are NOT currency-guarded: the API accepts any 3-letter
  // invoice currency, so the EUR invoice must reach the API.
  const eurInvoice = await client.invoices.create({ amount: 10, currency: 'EUR' });
  check('EUR invoice sent', eurInvoice.status === 'open' && JSON.parse(fetchImpl.calls.at(-1).init.body).currency === 'EUR');
  // Non-JSON error bodies still map to a typed error.
  const html502 = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: async () => ({ ok: false, status: 502, text: async () => '<html>Bad Gateway</html>' }) });
  try { await html502.wallets.list(); check('html 502 typed', false); }
  catch (e) { check('html 502 typed', e instanceof BMaGlassError && e.status === 502 && e.code === 'request_failed'); }
}

// --- Retries on transient 502/503/504 (CEO decision 2026-11-15) ---
// Policy: max 3 attempts total; only idempotent requests are
// retried (GET/DELETE, or POSTs carrying an Idempotency-Key);
// backoff is exponential with full jitter.
{
  const attemptsFor = (statuses, body = { data: [{ id: 'w1', currency: 'ZMW', balance: 42 }], mode: 'test' }) => {
    let n = 0;
    const seenKeys = [];
    const fetchImpl = stubFetch((url, init) => {
      n++;
      seenKeys.push(init?.headers?.['Idempotency-Key']);
      const status = statuses[Math.min(n - 1, statuses.length - 1)];
      return { status, body: status >= 400 ? { error: 'unavailable', message: 'try again' } : body };
    });
    return { fetchImpl, count: () => n, seenKeys };
  };

  // (a) A read that 503s twice then answers succeeds on attempt 3.
  {
    const stub = attemptsFor([503, 503, 200]);
    const delays = [];
    const c = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: stub.fetchImpl, retryBaseDelayMs: 100, sleepImpl: async (ms) => { delays.push(ms); } });
    const wallets = await c.wallets.list();
    check('retry: read 503x2 then 200 succeeds', wallets[0].balance === 42);
    check('retry: read took 3 attempts', stub.count() === 3);
    check('retry: two backoffs, in range', delays.length === 2 && delays[0] >= 0 && delays[0] <= 100 && delays[1] >= 0 && delays[1] <= 200);
  }
  // (b) A payment-link create retried after a 503 reuses the SAME
  // idempotency key — the server replays the original link.
  {
    const stub = attemptsFor([503, 201], { data: { id: 'pl1', title: 'Retry Link', amount: 10, currency: 'ZMW', link_code: 'ABC123', status: 'active' }, mode: 'test' });
    const c = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: stub.fetchImpl, retryBaseDelayMs: 0, sleepImpl: async () => {} });
    const link = await c.paymentLinks.create({ title: 'Retry Link', amount: 10, currency: 'ZMW' });
    check('retry: keyed create succeeds after 503', link.link_code === 'ABC123');
    check('retry: keyed create took 2 attempts', stub.count() === 2);
    check('retry: same auto key on both attempts', !!stub.seenKeys[0] && stub.seenKeys[0] === stub.seenKeys[1]);
    const stub2 = attemptsFor([503, 201], { data: { id: 'pl1', link_code: 'ABC123' }, mode: 'test' });
    const c2 = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: stub2.fetchImpl, retryBaseDelayMs: 0, sleepImpl: async () => {} });
    await c2.paymentLinks.create({ title: 'Retry Link', amount: 10 }, 'caller-key-123');
    check('retry: explicit caller key honoured', stub2.seenKeys[0] === 'caller-key-123' && stub2.seenKeys[1] === 'caller-key-123');
  }
  // (c) A 400 is never retried.
  {
    const stub = attemptsFor([400]);
    const c = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: stub.fetchImpl, retryBaseDelayMs: 0, sleepImpl: async () => {} });
    try { await c.wallets.list(); check('retry: 400 not retried', false); }
    catch (e) { check('retry: 400 not retried', e.status === 400 && stub.count() === 1); }
  }
  // (d) simulate-payment has no idempotency key: a 503 is NOT retried.
  {
    const stub = attemptsFor([503]);
    const c = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: stub.fetchImpl, retryBaseDelayMs: 0, sleepImpl: async () => {} });
    try { await c.paymentLinks.simulatePayment('link1'); check('retry: simulate-payment not retried', false); }
    catch (e) { check('retry: simulate-payment not retried', e.status === 503 && stub.count() === 1); }
  }
  // (e) bitcoin/quote has no idempotency key: a 503 is NOT retried.
  {
    const stub = attemptsFor([503]);
    const c = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: stub.fetchImpl, retryBaseDelayMs: 0, sleepImpl: async () => {} });
    try { await c.bitcoin.quote({ side: 'buy', amount_zmw: 100 }); check('retry: bitcoin/quote not retried', false); }
    catch (e) { check('retry: bitcoin/quote not retried', e.status === 503 && stub.count() === 1); }
  }
  // (f) Retries off (maxRetries 0): a 503 fails on the first attempt.
  {
    const stub = attemptsFor([503, 200]);
    const c = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: stub.fetchImpl, maxRetries: 0, retryBaseDelayMs: 0, sleepImpl: async () => {} });
    try { await c.wallets.list(); check('retry: maxRetries 0 means one attempt', false); }
    catch (e) { check('retry: maxRetries 0 means one attempt', e.status === 503 && stub.count() === 1); }
  }
  // (g) A transport failure on a read is retried, then surfaces typed.
  {
    let n = 0;
    const c = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', retryBaseDelayMs: 0, sleepImpl: async () => {}, fetchImpl: async () => { n++; throw new TypeError('fetch failed'); } });
    try { await c.wallets.list(); check('retry: transport failure retried', false); }
    catch (e) { check('retry: transport failure retried', e instanceof BMaGlassError && e.code === 'network_error' && n === 3); }
  }
  // (h) Invoices also carry an auto key on every create.
  {
    const stub = attemptsFor([201], { data: { id: 'inv1', status: 'open' }, mode: 'test' });
    const c = new BMaGlass({ apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', fetchImpl: stub.fetchImpl, retryBaseDelayMs: 0, sleepImpl: async () => {} });
    await c.invoices.create({ amount: 10, currency: 'ZMW' });
    check('retry: invoice create carries an auto key', !!stub.seenKeys[0]);
  }
}

console.log(failures === 0 ? '\nALL REACT NATIVE SDK TESTS PASSED' : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
