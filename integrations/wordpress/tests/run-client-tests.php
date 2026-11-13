<?php
/**
 * Standalone tests for the BMaGlass PHP API client (the class the
 * WooCommerce plugin ships). No WordPress, no network: a stub
 * transport mirrors the developer-api contract, exactly like the
 * TS/Python SDK contract tests.
 *
 * Run: php integrations/wordpress/tests/run-client-tests.php
 */

define('BMAGLASS_CLIENT_STANDALONE', true);
require_once dirname(__DIR__) . '/bmaglass-pay/includes/class-bmaglass-api-client.php';

$failures = 0;
function check($label, $cond)
{
    global $failures;
    if ($cond) {
        echo "PASS  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label\n";
    }
}
function eq($label, $a, $b)
{
    check($label . ' (got ' . var_export($a, true) . ')', $a === $b);
}

// Stub transport mirroring the developer-api contract.
$calls = array();
$transport = function ($method, $url, $headers, $body) use (&$calls) {
    $calls[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
    $flat = implode(' ', $headers);
    if (strpos($flat, 'bmp_test_contract') === false && strpos($flat, 'bmp_live_contract') === false) {
        return array(401, json_encode(array('error' => 'invalid_api_key', 'message' => 'nope')));
    }
    if (str_ends_with($url, '/wallets')) {
        return array(200, json_encode(array('data' => array(array('id' => 'w1', 'currency' => 'ZMW', 'balance' => 42)), 'mode' => 'test')));
    }
    if (str_ends_with($url, '/payment-links') && $method === 'GET') {
        return array(200, json_encode(array('data' => array(array('id' => 'pl1', 'link_code' => 'ABC123', 'payment_count' => 1, 'total_collected' => 5)), 'mode' => 'live')));
    }
    if (str_ends_with($url, '/payment-links') && $method === 'POST') {
        $req = json_decode($body, true);
        if (strpos($flat, 'bmp_test_') !== false) {
            return array(403, json_encode(array('error' => 'test_mode_read_only', 'message' => 'Test keys are read-only')));
        }
        return array(201, json_encode(array('data' => array('id' => 'pl1', 'title' => $req['title'], 'amount' => $req['amount'], 'currency' => $req['currency'], 'link_code' => 'ABC123'), 'mode' => 'live')));
    }
    if (str_ends_with($url, '/transfers')) {
        return array(201, json_encode(array('data' => array('transaction_id' => 'tx1'), 'mode' => 'live')));
    }
    if (str_ends_with($url, '/webhooks') && $method === 'POST') {
        return array(201, json_encode(array('data' => array('id' => 'wh1', 'secret' => 'whsec_testsecret'), 'mode' => 'live')));
    }
    if (str_ends_with($url, '/settings')) {
        return array(200, json_encode(array('data' => array('fee_payer_default' => 'seller', 'platform_fee_percent' => 2.5, 'paypal_platform_fee_percent' => 1, 'note' => 'n'), 'mode' => 'test')));
    }
    if (str_ends_with($url, '/payment-links/link1/attempts')) {
        return array(200, json_encode(array('data' => array(array('id' => 'att1', 'status' => 'waiting')), 'mode' => 'test')));
    }
    if (strpos($url, '/mobile-money/collections') !== false && $method === 'POST') {
        $req = json_decode($body, true);
        return array(201, json_encode(array('data' => array(
            'reference' => 'BMG-TESTCOLL1', 'merchant_reference' => isset($req['reference']) ? $req['reference'] : null,
            'collection_id' => 'col1', 'transaction_id' => null, 'amount' => $req['amount'],
            'currency' => 'ZMW', 'operator' => isset($req['operator']) ? $req['operator'] : 'mtn',
            'phone' => $req['phone'], 'status' => 'pending', 'failure_reason' => null,
            'environment' => 'production', 'livemode' => true, 'wallet_balance' => null,
            'settled_at' => null, 'created_at' => '2026-11-12T00:00:00Z',
        ), 'mode' => 'live')));
    }
    if (strpos($url, '/mobile-money/collections/') !== false && $method === 'GET') {
        return array(200, json_encode(array('data' => array(
            'reference' => 'BMG-TESTCOLL1', 'merchant_reference' => 'woo-order-7',
            'collection_id' => 'col1', 'transaction_id' => 'tx-momo-1', 'amount' => 100,
            'currency' => 'ZMW', 'operator' => 'mtn', 'phone' => '0961111111',
            'status' => 'successful', 'failure_reason' => null, 'environment' => 'production',
            'livemode' => true, 'wallet_balance' => 142.5, 'settled_at' => '2026-11-12T00:01:00Z',
            'created_at' => '2026-11-12T00:00:00Z',
        ), 'mode' => 'live')));
    }
    return array(404, json_encode(array('error' => 'not_found', 'message' => 'no route')));
};

// ── Construction / mode ──────────────────────────────────────────────
$test = new BMaGlassClient('bmp_test_contract', 'http://local.test', $transport);
eq('test key mode', $test->mode(), 'test');
eq('live key mode', (new BMaGlassClient('bmp_live_contract', 'http://local.test', $transport))->mode(), 'live');
eq('bmg sandbox environment', (new BMaGlassClient('bmg_sandbox_contract', 'http://local.test', $transport))->environment(), 'sandbox');
eq('bmg live environment', (new BMaGlassClient('bmg_live_contract', 'http://local.test', $transport))->environment(), 'production');
try {
    new BMaGlassClient('sk_live_nope', 'http://local.test', $transport);
    check('non-bmaglass key rejected', false);
} catch (BMaGlassApiException $e) {
    eq('non-bmaglass key rejected', $e->errorCode, 'invalid_api_key');
}

// ── Wallets: header + data unwrap ────────────────────────────────────
$wallets = $test->wallets();
eq('wallets balance', $wallets[0]['balance'], 42);
$settings = $test->settings();
eq('settings fee default', $settings['fee_payer_default'], 'seller');
eq('settings paypal rate', $settings['paypal_platform_fee_percent'], 1);
$attempts = $test->paymentLinkAttempts('link1');
eq('attempts status', $attempts[0]['status'], 'waiting');
$last = end($calls);
check('X-Api-Key header sent', strpos(implode(' ', $last['headers']), 'X-Api-Key: bmp_test_contract') !== false);

// ── Payment link create: shaping + test-mode error mapping ──────────
$live = new BMaGlassClient('bmp_live_contract', 'http://local.test', $transport);
$link = $live->createPaymentLink('Order #7 — Shop', 10.5, 'zmw', 'WooCommerce order key_abc');
eq('link code returned', $link['link_code'], 'ABC123');
$last = end($calls);
$sentBody = json_decode($last['body'], true);
eq('currency upper-cased', $sentBody['currency'], 'ZMW');
eq('amount shaped', $sentBody['amount'], 10.5);
eq('hosted link URL', BMaGlassClient::paymentLinkUrl('ABC123'), 'https://bmaglasspay.online/pay/ABC123');

try {
    $test->createPaymentLink('Nope', 5, 'ZMW');
    check('test-key write throws', false);
} catch (BMaGlassApiException $e) {
    eq('test-key write status', $e->status, 403);
    eq('test-key write code', $e->errorCode, 'test_mode_read_only');
    check('isTestModeReadOnly', $e->isTestModeReadOnly());
    check('userMessage is honest about test keys', strpos($e->userMessage(), 'sandbox key') !== false);
}

// ── Currency guard (no transport call allowed) ───────────────────────
$before = count($calls);
try {
    $live->createPaymentLink('Euro order', 5, 'EUR');
    check('EUR refused', false);
} catch (BMaGlassApiException $e) {
    eq('EUR error code', $e->errorCode, 'unsupported_currency');
    check('EUR message names the problem', strpos($e->userMessage(), 'EUR') !== false && strpos($e->userMessage(), 'no exchange rate') !== false);
}
eq('guard fired before transport', count($calls), $before);

// ── Transfer idempotency header ──────────────────────────────────────
$live->createTransfer('user-2', 25, 'ZMW', 'desc', null, 'idem-42');
$last = end($calls);
check('Idempotency-Key header sent', strpos(implode(' ', $last['headers']), 'Idempotency-Key: idem-42') !== false);

// ── Mobile money collections ─────────────────────────────────────────
$collection = $live->createMobileMoneyCollection(100, '0961111111', null, 'woo-order-7');
eq('collection reference returned', $collection['reference'], 'BMG-TESTCOLL1');
eq('collection starts pending', $collection['status'], 'pending');
$last = end($calls);
check('collection POST path', str_ends_with($last['url'], '/mobile-money/collections') && $last['method'] === 'POST');
$sentBody = json_decode($last['body'], true);
eq('collection amount shaped', (float) $sentBody['amount'], 100.0);
eq('collection phone sent', $sentBody['phone'], '0961111111');
eq('collection merchant reference sent', $sentBody['reference'], 'woo-order-7');
check('operator omitted when null (API detects it)', !isset($sentBody['operator']));

$settled = $live->getMobileMoneyCollection('BMG-TESTCOLL1');
eq('collection settles on poll', $settled['status'], 'successful');
eq('collection wallet balance reported', $settled['wallet_balance'], 142.5);
$last = end($calls);
check('collection GET path', str_ends_with($last['url'], '/mobile-money/collections/BMG-TESTCOLL1') && $last['method'] === 'GET');

// ── Webhook create returns one-time secret ───────────────────────────
$hook = $live->createWebhook('https://example.com/hook', array('transaction.completed'));
eq('webhook secret surfaced once', $hook['secret'], 'whsec_testsecret');

// ── HMAC known-answer vector (shared with the TS + Python SDKs) ──────
$raw = '{"id":"evt_1","type":"webhook.test","created":"2026-10-26T00:00:00.000Z","data":{"ping":true}}';
$sig = 'sha256=ff522c97ada5bfd4f6c09a0457823f89546896708e8e1f5bbb72d2ad59d68535';
check('KAT verifies', BMaGlassClient::verifyWebhookSignature($raw, $sig, 'whsec_testsecret'));
check('KAT wrong secret fails', !BMaGlassClient::verifyWebhookSignature($raw, $sig, 'whsec_wrong'));
check('KAT tampered body fails', !BMaGlassClient::verifyWebhookSignature($raw . ' ', $sig, 'whsec_testsecret'));
$event = BMaGlassClient::parseWebhookEvent($raw, $sig, 'whsec_testsecret');
eq('KAT event type', $event['type'], 'webhook.test');
eq('bad signature parses to null', BMaGlassClient::parseWebhookEvent($raw, 'sha256=deadbeef', 'whsec_testsecret'), null);

echo $failures === 0 ? "\nALL PHP CLIENT TESTS PASSED\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
