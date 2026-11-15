<?php
/**
 * Standalone tests for the PHP SDK (sdk/php). Uses a minimal PSR-4
 * autoloader (no Composer binary needed) and a stub transport that
 * mirrors the developer-api contract, like the TS/Python SDK tests.
 *
 * Run: php sdk/php/tests/run-tests.php
 */

spl_autoload_register(function ($class) {
    $prefix = 'BMaGlass\\Sdk\\';
    if (strpos($class, $prefix) === 0) {
        $file = dirname(__DIR__) . '/src/' . substr($class, strlen($prefix)) . '.php';
        if (file_exists($file)) require $file;
    }
});

use BMaGlass\Sdk\ApiException;
use BMaGlass\Sdk\Client;

$failures = 0;
function check($label, $cond)
{
    global $failures;
    if ($cond) { echo "PASS  $label\n"; } else { $failures++; echo "FAIL  $label\n"; }
}
function eq($label, $a, $b) { check($label . ' (got ' . var_export($a, true) . ')', $a === $b); }

$calls = array();
$transport = function ($method, $url, $headers, $body) use (&$calls) {
    $calls[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
    $flat = implode(' ', $headers);
    if (strpos($flat, 'bmp_test_contract') === false && strpos($flat, 'bmp_live_contract') === false
        && strpos($flat, 'bmg_sandbox_contract') === false && strpos($flat, 'bmg_live_contract') === false) {
        return array(401, json_encode(array('error' => 'invalid_api_key', 'message' => 'nope')));
    }
    if (str_ends_with($url, '/connector/config')) {
        $sandbox = strpos($flat, 'sandbox') !== false || strpos($flat, 'bmp_test_') !== false;
        return array(200, json_encode(array('data' => array(
            'environment' => $sandbox ? 'sandbox' : 'production', 'livemode' => !$sandbox,
            'key' => array('key_id' => 'k1', 'scopes' => array('config:read'), 'status' => 'active'),
            'business' => array('display_name' => 'QA Store', 'country' => 'ZM', 'currency' => 'ZMW', 'status' => 'pending'),
            'features' => array('mobile_money' => array('status' => 'disabled', 'reason_code' => null)),
            'settlement' => array('type' => 'wallet', 'currency' => 'ZMW'),
            'config_version' => 'abc123',
        ), 'mode' => $sandbox ? 'test' : 'live')));
    }
    if (str_ends_with($url, '/invoices') && $method === 'POST') {
        $req = json_decode($body, true);
        return array(201, json_encode(array('data' => array('id' => 'inv1', 'amount' => $req['amount'], 'currency' => $req['currency'] ?? 'ZMW', 'status' => 'open', 'environment' => 'sandbox'), 'mode' => 'test')));
    }
    if (str_ends_with($url, '/invoices/inv1/simulate-payment')) {
        return array(200, json_encode(array('data' => array('id' => 'inv1', 'status' => 'paid', 'environment' => 'sandbox'), 'mode' => 'test', 'simulated' => true)));
    }
    if (str_ends_with($url, '/wallets')) {
        return array(200, json_encode(array('data' => array(array('id' => 'w1', 'currency' => 'ZMW', 'balance' => 42)), 'mode' => 'test')));
    }
    if (str_ends_with($url, '/payment-links') && $method === 'POST') {
        // Sandbox keys CAN create payment links (settlement is simulation-only);
        // only transfers/webhook registration are live-only. The stub echoes the
        // per-link fee_payer + redirect_url and the resolved fee maths, as live does.
        $req = json_decode($body, true);
        $feePayer = isset($req['fee_payer']) ? $req['fee_payer'] : 'seller';
        $fee = round(($req['amount'] ?? 0) * 0.025, 2);
        $amount = $req['amount'] ?? 0;
        return array(201, json_encode(array('data' => array('id' => 'pl1', 'title' => $req['title'], 'amount' => $amount, 'currency' => $req['currency'], 'link_code' => 'ABC123', 'status' => 'active', 'fee_payer' => $feePayer, 'redirect_url' => isset($req['redirect_url']) ? $req['redirect_url'] : null, 'payment_count' => 0, 'total_collected' => 0, 'fee_amount' => $fee, 'buyer_total' => $feePayer === 'buyer' ? $amount + $fee : $amount, 'seller_receives' => $feePayer === 'seller' ? $amount - $fee : $amount), 'mode' => 'test')));
    }
    if (str_ends_with($url, '/transfers')) {
        if (strpos($flat, 'bmp_test_') !== false || strpos($flat, 'bmg_sandbox_') !== false) {
            return array(403, json_encode(array('error' => 'test_mode_read_only', 'message' => 'Test keys are read-only')));
        }
        return array(201, json_encode(array('data' => array('transaction_id' => 'tx1'), 'mode' => 'live')));
    }
    if (str_ends_with($url, '/settings')) {
        return array(200, json_encode(array('data' => array('fee_payer_default' => 'seller', 'platform_fee_percent' => 2.5, 'paypal_platform_fee_percent' => 1, 'note' => 'n'), 'mode' => 'test')));
    }
    if (str_ends_with($url, '/payment-links/link1/attempts')) {
        return array(200, json_encode(array('data' => array(array('id' => 'att1', 'status' => 'waiting')), 'mode' => 'test')));
    }
    if (str_ends_with($url, '/payment-links/link1')) {
        return array(200, json_encode(array('data' => array('id' => 'pl1', 'title' => 'Order #8', 'amount' => 10, 'currency' => 'ZMW', 'link_code' => 'ABC123', 'status' => 'active', 'fee_payer' => 'seller', 'fee_amount' => 0.25, 'buyer_total' => 10, 'seller_receives' => 9.75, 'payment_count' => 0, 'total_collected' => 0), 'mode' => 'test')));
    }
    if (str_ends_with($url, '/mobile-money/collections') && $method === 'POST') {
        $req = json_decode($body, true);
        return array(201, json_encode(array('data' => array('reference' => 'BMG-C1', 'merchant_reference' => $req['reference'] ?? null, 'amount' => $req['amount'], 'base_amount' => $req['amount'], 'service_charge' => round(($req['amount'] ?? 0) * 0.01, 2), 'fee_bearer' => 'seller', 'status' => 'pending'), 'mode' => 'test')));
    }
    if (str_ends_with($url, '/bitcoin/quote') && $method === 'POST') {
        $req = json_decode($body, true);
        return array(201, json_encode(array('data' => array('id' => 'q1', 'side' => $req['side'] ?? 'buy'), 'mode' => 'test')));
    }
    return array(404, json_encode(array('error' => 'not_found', 'message' => 'no route')));
};

$test = new Client('bmp_test_contract', 'http://local.test', $transport);
eq('test key mode', $test->mode(), 'test');
eq('live key mode', (new Client('bmp_live_contract', 'http://local.test', $transport))->mode(), 'live');
eq('bmg sandbox environment', (new Client('bmg_sandbox_contract', 'http://local.test', $transport))->environment(), 'sandbox');
eq('bmg live environment', (new Client('bmg_live_contract', 'http://local.test', $transport))->environment(), 'production');
eq('legacy test environment', $test->environment(), 'sandbox');

$sandbox = new Client('bmg_sandbox_contract', 'http://local.test', $transport);
$config = $sandbox->connectorConfig();
eq('config environment', $config['environment'], 'sandbox');
eq('config business country', $config['business']['country'], 'ZM');
eq('config mobile money state', $config['features']['mobile_money']['status'], 'disabled');
$inv = $sandbox->createInvoice(250, 'ZMW', 'Order 9');
eq('invoice created open', $inv['status'], 'open');
$paidInv = $sandbox->simulateInvoicePayment('inv1');
eq('invoice simulated paid', $paidInv['status'], 'paid');
try {
    new Client('sk_live_nope', 'http://local.test', $transport);
    check('non-bmaglass key rejected', false);
} catch (ApiException $e) {
    eq('non-bmaglass key rejected', $e->errorCode, 'invalid_api_key');
}

$wallets = $test->wallets();
eq('wallets balance', $wallets[0]['balance'], 42);
$last = end($calls);
check('X-Api-Key header sent', strpos(implode(' ', $last['headers']), 'X-Api-Key: bmp_test_contract') !== false);

$settings = $test->settings();
eq('settings fee default', $settings['fee_payer_default'], 'seller');
eq('settings link rate', $settings['platform_fee_percent'], 2.5);
eq('settings paypal rate', $settings['paypal_platform_fee_percent'], 1);
$attempts = $test->paymentLinkAttempts('link1');
eq('attempts status', $attempts[0]['status'], 'waiting');

$live = new Client('bmp_live_contract', 'http://local.test', $transport);
$link = $live->createPaymentLink('Order #7', 10.5, 'zmw');
eq('link code', $link['link_code'], 'ABC123');
eq('link URL', Client::paymentLinkUrl('ABC123'), 'https://bmaglasspay.online/pay/ABC123');

// Sandbox keys CAN create payment links (live API, proven 2026-11-14);
// only transfers/webhook registration are live-only.
$sandboxLink = $test->createPaymentLink('Order #8', 10, 'ZMW', null, null, 'buyer', 'https://example.invalid/return');
eq('sandbox link fee_payer', $sandboxLink['fee_payer'], 'buyer');
eq('sandbox link redirect_url', $sandboxLink['redirect_url'], 'https://example.invalid/return');
eq('sandbox link fee_amount', $sandboxLink['fee_amount'], 0.25);
eq('sandbox link buyer_total', $sandboxLink['buyer_total'], 10.25);
eq('sandbox link seller_receives', $sandboxLink['seller_receives'], 10);
$sellerLink = $test->createPaymentLink('Order #9', 10, 'ZMW');
eq('default link fee_payer', $sellerLink['fee_payer'], 'seller');
eq('default link seller_receives', $sellerLink['seller_receives'], 9.75);
$last = end($calls);
$sent = json_decode($last['body'], true);
eq('fee_payer sent', $sent['fee_payer'] ?? 'seller', 'seller');

// One-link read (GET /payment-links/{id}) — the reconciler's escape
// from the 100-link list cap.
$one = $test->getPaymentLink('link1');
eq('get one link code', $one['link_code'], 'ABC123');
eq('get one link count', $one['payment_count'], 0);

try {
    $test->createPaymentLink('Bad', 10, 'ZMW', null, null, 'nobody');
    check('bad fee_payer refused', false);
} catch (ApiException $e) {
    eq('bad fee_payer code', $e->errorCode, 'invalid_fee_payer');
}

try {
    $test->createTransfer('user-2', 25, 'ZMW');
    check('test-key transfer throws', false);
} catch (ApiException $e) {
    eq('test-key transfer code', $e->errorCode, 'test_mode_read_only');
    check('userMessage honest', strpos($e->userMessage(), 'sandbox key') !== false);
}

try {
    $live->createPaymentLink('Euro', 5, 'EUR');
    check('EUR refused', false);
} catch (ApiException $e) {
    eq('EUR code', $e->errorCode, 'unsupported_currency');
}

$live->createTransfer('user-2', 25, 'ZMW', null, null, 'idem-7');
$last = end($calls);
check('Idempotency-Key header', strpos(implode(' ', $last['headers']), 'Idempotency-Key: idem-7') !== false);

// ── ext-hunt fixes ──
// Invoices are NOT currency-guarded: the API accepts any 3-letter
// invoice currency, so a EUR invoice must reach the API (it used to be
// refused client-side with zero requests sent).
$callsBefore = count($calls);
$eurInv = $test->createInvoice(10, 'EUR');
$last = end($calls);
$sent = json_decode($last['body'], true);
eq('EUR invoice sent', $sent['currency'], 'EUR');
eq('EUR invoice created', $eurInv['currency'], 'EUR');
check('EUR invoice made a request', count($calls) > $callsBefore);
// Metadata rides on invoice creation (parity with TS/Python/RN).
$test->createInvoice(10, 'ZMW', null, null, array('order' => '42'));
$last = end($calls);
$sent = json_decode($last['body'], true);
eq('invoice metadata sent', $sent['metadata'], array('order' => '42'));
// A bad quote side is refused client-side: it used to be silently
// coerced to a buy quote, flipping the money direction on a typo.
try {
    $test->bitcoinQuote('bogus', 100);
    check('bad side refused', false);
} catch (ApiException $e) {
    eq('bad side code', $e->errorCode, 'invalid_side');
}
try {
    $test->bitcoinQuote('selll', null, 5000);
    check('typo side refused', false);
} catch (ApiException $e) {
    eq('typo side code', $e->errorCode, 'invalid_side');
}
$quote = $test->bitcoinQuote('sell', null, 5000);
eq('sell quote side', $quote['side'], 'sell');
// Mobile-money create accepts an explicit idempotency key (parity
// with TS/Python/RN); the merchant reference still doubles as the key
// when no explicit key is given.
$coll = $test->createMobileMoneyCollection(100, '0961111111', 'mtn', 'order-42', 'idem-99');
$last = end($calls);
$sent = json_decode($last['body'], true);
eq('momo idempotency_key sent', $sent['idempotency_key'], 'idem-99');
eq('momo reference sent', $sent['reference'], 'order-42');
// Live momo shape: service_charge is 1% of base_amount in BOTH modes
// (the seller funds it in seller mode; it is NOT 0).
check('momo service_charge is 1% of base', $coll['service_charge'] == 1.0);
eq('momo fee_bearer', $coll['fee_bearer'], 'seller');

// Shared cross-SDK HMAC known-answer vector.
$raw = '{"id":"evt_1","type":"webhook.test","created":"2026-10-26T00:00:00.000Z","data":{"ping":true}}';
$sig = 'sha256=ff522c97ada5bfd4f6c09a0457823f89546896708e8e1f5bbb72d2ad59d68535';
check('KAT verifies', Client::verifyWebhookSignature($raw, $sig, 'whsec_testsecret'));
check('KAT wrong secret fails', !Client::verifyWebhookSignature($raw, $sig, 'whsec_wrong'));
$event = Client::parseWebhookEvent($raw, $sig, 'whsec_testsecret');
eq('KAT event type', $event['type'], 'webhook.test');

echo $failures === 0 ? "\nALL PHP SDK TESTS PASSED\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
