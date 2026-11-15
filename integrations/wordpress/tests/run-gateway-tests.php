<?php
/**
 * Standalone tests for the two WooCommerce gateway process_payment()
 * paths (hosted-link + mobile money) — the checkout behaviour the
 * other suites do not cover. No WordPress, no network: the small
 * WP/WC surface the gateways touch is stubbed, and the API client is
 * a faithful stub (same public surface + validation as the real
 * BMaGlassClient; transport-level behaviour is pinned by
 * run-client-tests.php against the real client).
 *
 * What is pinned:
 *   - Hosted-link gateway: sandbox key refused (the hosted pay page
 *     refuses sandbox links, per the live API spec); EUR refused;
 *     no key fails; API failure fails with an order note; a created
 *     link with no link_code fails instead of redirecting to /pay/;
 *     an UNEXPIRED link is reused (no second create); an EXPIRED
 *     link is replaced with a fresh one (a retry after 7 days must
 *     not land the buyer on a dead link).
 *   - Mobile-money gateway: a sandbox key CAN check out (sandbox
 *     collections use the sandbox connection + test numbers and never
 *     charge a real phone); a FAILED collection can be retried with a
 *     FRESH merchant reference (the API's idempotency key is the
 *     reference, so replaying the failed one would return it); a
 *     pending/successful collection is never started twice; a
 *     collection the server no longer knows (404) is recreated under
 *     the original reference; a status poll that fails (network) never
 *     starts a second collection blindly; the phone is normalised
 *     before it is sent (the placeholder invites "0977 123 456").
 *
 * Run: php integrations/wordpress/tests/run-gateway-tests.php
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
error_reporting(E_ALL & ~E_DEPRECATED);

// ── Stub BMaGlass client (faithful copy of the real client's public
// surface + validation; see class-bmaglass-api-client.php). ─────────
class BMaGlassApiException extends Exception
{
    public $status;
    public $errorCode;
    public function __construct($status, $errorCode, $message)
    {
        parent::__construct($message);
        $this->status = (int) $status;
        $this->errorCode = (string) $errorCode;
    }
    public function userMessage()
    {
        switch ($this->errorCode) {
            case 'test_mode_read_only':
                return 'This BMaGlass API key is a sandbox key. Sandbox keys can check the '
                    . 'connection, read wallets and run simulated payments, but they can\'t '
                    . 'move real money. Use a production key (bmg_live_...) to accept payments.';
            case 'invalid_api_key':
            case 'api_key_revoked':
            case 'api_key_expired':
                return 'That BMaGlass API key wasn\'t accepted. Check the key in your '
                    . 'BMaGlass Pay account (Business settings or the API page) and try again.';
            case 'insufficient_scope':
                return 'This BMaGlass API key doesn\'t have permission for that action. '
                    . 'Create a key with the needed scopes in BMaGlass Pay and try again.';
            case 'unsupported_currency':
                return $this->getMessage();
            case 'network_error':
                return 'BMaGlass Pay couldn\'t be reached. Check the connection and try again.';
            default:
                $msg = $this->getMessage();
                return $msg !== '' ? $msg
                    : 'BMaGlass Pay couldn\'t complete that request (HTTP ' . $this->status . '). Please try again.';
        }
    }
}

class BMaGlassClient
{
    const SITE_BASE = 'https://bmaglasspay.online';
    const SUPPORTED_CURRENCIES = array('ZMW', 'USD');
    private $apiKey;
    public function __construct($apiKey, $baseUrl = null, $transport = null)
    {
        if (!is_string($apiKey) || (strpos($apiKey, 'bmg_') !== 0 && strpos($apiKey, 'bmp_') !== 0)) {
            throw new BMaGlassApiException(0, 'invalid_api_key',
                'api_key must be a BMaGlass Pay API key (bmg_live_..., bmg_sandbox_..., or a legacy bmp_ key).');
        }
        $this->apiKey = $apiKey;
    }
    public function mode()
    {
        return strpos($this->apiKey, '_live_') !== false ? 'live' : 'test';
    }
    public function environment()
    {
        return $this->mode() === 'live' ? 'production' : 'sandbox';
    }
    public function isLive()
    {
        return $this->mode() === 'live';
    }
    public static function paymentLinkUrl($linkCode)
    {
        return self::SITE_BASE . '/pay/' . rawurlencode((string) $linkCode);
    }
    public static function assertSupportedCurrency($currency)
    {
        $c = strtoupper((string) $currency);
        if (!in_array($c, self::SUPPORTED_CURRENCIES, true)) {
            throw new BMaGlassApiException(0, 'unsupported_currency',
                'BMaGlass Pay wallets are ZMW and USD. This store is set to ' . $c
                . ', which has no BMaGlass wallet, so BMaGlass checkout can\'t run — '
                . 'no exchange rate has been applied. Set the store currency to ZMW or USD to use BMaGlass Pay.');
        }
        return $c;
    }
    private function call($op, $arg)
    {
        $GLOBALS['api_calls'][] = array('op' => $op, 'arg' => $arg);
        $script = isset($GLOBALS['client_script']) ? $GLOBALS['client_script'] : null;
        if (!is_callable($script)) {
            throw new RuntimeException('no client script set for op ' . $op);
        }
        return call_user_func($script, $op, $arg);
    }
    public function createPaymentLink($title, $amount = null, $currency = 'ZMW', $description = null, $expiresAt = null, $feePayer = null, $redirectUrl = null)
    {
        $body = array('title' => (string) $title, 'currency' => self::assertSupportedCurrency($currency));
        if ($amount !== null) $body['amount'] = round((float) $amount, 2);
        if ($description !== null) $body['description'] = $description;
        if ($expiresAt !== null) $body['expires_at'] = $expiresAt;
        if ($feePayer !== null) {
            if ($feePayer !== 'seller' && $feePayer !== 'buyer') {
                throw new BMaGlassApiException(0, 'invalid_fee_payer', "fee_payer must be 'seller' or 'buyer'.");
            }
            $body['fee_payer'] = $feePayer;
        }
        if ($redirectUrl !== null) $body['redirect_url'] = (string) $redirectUrl;
        return $this->call('createPaymentLink', $body);
    }
    public function createMobileMoneyCollection($amount, $phone, $operator = null, $reference = null)
    {
        $body = array('amount' => round((float) $amount, 2), 'phone' => (string) $phone);
        if ($operator !== null) $body['operator'] = $operator;
        if ($reference !== null) $body['reference'] = $reference;
        return $this->call('createMobileMoneyCollection', $body);
    }
    public function getMobileMoneyCollection($reference)
    {
        return $this->call('getMobileMoneyCollection', $reference);
    }
    public function connectorConfig()
    {
        return $this->call('connectorConfig', null);
    }
    public function listPaymentLinks()
    {
        return $this->call('listPaymentLinks', null);
    }
}

// ── WP/WC stubs ──────────────────────────────────────────────────────
$GLOBALS['wp_options'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['wp_currency'] = 'ZMW';
$GLOBALS['cart_emptied'] = false;
$GLOBALS['notices'] = array();
$GLOBALS['test_order'] = null;
$GLOBALS['api_calls'] = array();
$GLOBALS['client_script'] = null;

function get_option($k, $default = array())
{
    return isset($GLOBALS['wp_options'][$k]) ? $GLOBALS['wp_options'][$k] : $default;
}
function update_option($k, $v)
{
    $GLOBALS['wp_options'][$k] = $v;
}
function get_transient($k)
{
    return isset($GLOBALS['transients'][$k]) ? $GLOBALS['transients'][$k] : false;
}
function set_transient($k, $v, $exp = 0)
{
    $GLOBALS['transients'][$k] = $v;
}
function delete_transient($k)
{
    unset($GLOBALS['transients'][$k]);
}
function add_action($hook, $cb) {}
function get_woocommerce_currency() { return $GLOBALS['wp_currency']; }
function get_bloginfo($k) { return 'Test Shop'; }
function wpautop($t) { return $t; }
function wptexturize($t) { return $t; }
function esc_html_e($t, $d = null) { echo $t; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_html__($t, $d = null) { return $t; }
function __($t, $d = null) { return $t; }
function sanitize_text_field($t) { return trim((string) $t); }
function wp_unslash($t) { return $t; }
function wc_add_notice($msg, $type = 'notice') { $GLOBALS['notices'][] = array($type, $msg); }
function wc_get_order($id) { return $GLOBALS['test_order']; }

class FakeCart
{
    public function get_total($ctx = 'view') { return 100.00; }
    public function empty_cart() { $GLOBALS['cart_emptied'] = true; }
}
class FakeWC { public $cart; public function __construct() { $this->cart = new FakeCart(); } }
function WC() { static $wc = null; if ($wc === null) $wc = new FakeWC(); return $wc; }

class FakeOrder
{
    public $meta = array();
    public $status = 'pending';
    public $total;
    public $currency = 'ZMW';
    public $notes = array();
    public $complete_calls = 0;
    public $method = 'bmaglass';
    public function __construct($total, $currency = 'ZMW', $meta = array(), $status = 'pending')
    {
        $this->total = $total;
        $this->currency = $currency;
        $this->meta = $meta;
        $this->status = $status;
    }
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function update_status($s, $note = '') { $this->status = $s; if ($note) $this->notes[] = $note; }
    public function get_status() { return $this->status; }
    public function get_total() { return $this->total; }
    public function get_currency() { return $this->currency; }
    public function get_payment_method() { return $this->method; }
    public function get_order_number() { return '42'; }
    public function get_order_key() { return 'wc_order_abc'; }
    public function add_order_note($n) { $this->notes[] = $n; }
    public function save() {}
    public function payment_complete($txn = '') { $this->complete_calls++; $this->status = 'processing'; }
}

class WC_Payment_Gateway
{
    public $id; public $method_title; public $method_description; public $has_fields;
    public $supports = array(); public $form_fields = array(); public $settings = array();
    public $enabled; public $title; public $description;
    public function init_form_fields() {}
    public function init_settings()
    {
        $this->settings = get_option('woocommerce_' . $this->id . '_settings', array());
    }
    public function get_option($k, $default = '') { return isset($this->settings[$k]) ? $this->settings[$k] : $default; }
    public function process_admin_options() {}
    public function is_available() { return true; }
    public function get_return_url($order = null) { return 'https://example.test/thank-you'; }
}

function bmaglass_settings()
{
    $s = get_option('woocommerce_bmaglass_settings', array());
    return is_array($s) ? $s : array();
}
function bmaglass_client_from_settings()
{
    $s = bmaglass_settings();
    $key = isset($s['api_key']) ? trim((string) $s['api_key']) : '';
    if ($key === '') return null;
    try {
        return new BMaGlassClient($key);
    } catch (BMaGlassApiException $e) {
        return null;
    }
}

require_once dirname(__DIR__) . '/bmaglass-pay/includes/class-wc-gateway-bmaglass.php';
require_once dirname(__DIR__) . '/bmaglass-pay/includes/class-wc-gateway-bmaglass-momo.php';

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
function reset_state()
{
    $GLOBALS['api_calls'] = array();
    $GLOBALS['notices'] = array();
    $GLOBALS['cart_emptied'] = false;
    $GLOBALS['transients'] = array();
    $GLOBALS['client_script'] = null;
    $_POST = array();
}

// ── Hosted-link gateway ──────────────────────────────────────────────
function main_gateway_with($apiKey)
{
    $GLOBALS['wp_options'] = array(
        'woocommerce_bmaglass_settings' => array(
            'enabled' => 'yes', 'title' => 'BMaGlass Pay', 'description' => 'Pay.',
            'api_key' => $apiKey,
        ),
    );
    return new WC_Gateway_BMaGlass();
}

function link_create_script($code = 'LK-NEW', $withCode = true)
{
    return function ($op, $arg) use ($code, $withCode) {
        if ($op !== 'createPaymentLink') {
            throw new RuntimeException('unexpected op ' . $op);
        }
        $row = array('id' => 'pl-1', 'title' => $arg['title'], 'amount' => $arg['amount'],
            'currency' => $arg['currency'], 'status' => 'active');
        if ($withCode) $row['link_code'] = $code;
        return $row;
    };
}

// Sandbox key refused: the hosted pay page refuses sandbox links, so
// checkout refuses up front and never touches the API.
reset_state();
$GLOBALS['client_script'] = link_create_script();
$gw = main_gateway_with('bmg_sandbox_test');
$GLOBALS['test_order'] = new FakeOrder(100.00);
$result = $gw->process_payment(42);
eq('sandbox key refuses checkout', $result['result'], 'failure');
check('sandbox refusal makes no API call', count($GLOBALS['api_calls']) === 0);
check('sandbox refusal explains itself', strpos(implode(' ', array_column($GLOBALS['notices'], 1)), 'sandbox key') !== false);

// Live key creates the link and stores the expiry for the reuse check.
reset_state();
$GLOBALS['client_script'] = link_create_script('LK-NEW');
$gw = main_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00);
$GLOBALS['test_order'] = $order;
$result = $gw->process_payment(42);
eq('live checkout succeeds', $result['result'], 'success');
eq('live checkout redirects to the hosted page', $result['redirect'], 'https://bmaglasspay.online/pay/LK-NEW');
eq('link code stored', $order->get_meta('_bmaglass_link_code'), 'LK-NEW');
eq('link id stored', $order->get_meta('_bmaglass_link_id'), 'pl-1');
eq('payment count seen starts at 0', $order->get_meta('_bmaglass_payment_count_seen'), 0);
check('link expiry stored (~7 days out)', abs((int) $order->get_meta('_bmaglass_link_expires') - (time() + 7 * DAY_IN_SECONDS)) < 5);
check('order parked on-hold', $order->status === 'on-hold');
check('cart emptied', $GLOBALS['cart_emptied'] === true);
$sent = $GLOBALS['api_calls'][0]['arg'];
eq('link created for the exact order total', $sent['amount'], 100.0);
eq('link created in the order currency', $sent['currency'], 'ZMW');
check('link carries the return URL', strpos($sent['redirect_url'], 'thank-you') !== false);

// Unexpired link is reused: no second create.
reset_state();
$GLOBALS['client_script'] = link_create_script('LK-OTHER');
$gw = main_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00, 'ZMW', array(
    '_bmaglass_link_code' => 'LK-OLD', '_bmaglass_link_expires' => time() + 6 * DAY_IN_SECONDS,
), 'on-hold');
$GLOBALS['test_order'] = $order;
$result = $gw->process_payment(42);
eq('unexpired link is reused', $result['redirect'], 'https://bmaglasspay.online/pay/LK-OLD');
check('reuse makes no create call', count($GLOBALS['api_calls']) === 0);

// Expired link is replaced with a fresh one.
reset_state();
$GLOBALS['client_script'] = link_create_script('LK-FRESH');
$gw = main_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00, 'ZMW', array(
    '_bmaglass_link_code' => 'LK-OLD', '_bmaglass_link_expires' => time() - 60,
), 'on-hold');
$GLOBALS['test_order'] = $order;
$result = $gw->process_payment(42);
eq('expired link is replaced, not reused', $result['redirect'], 'https://bmaglasspay.online/pay/LK-FRESH');
check('expired reuse creates a fresh link', count($GLOBALS['api_calls']) === 1);
eq('fresh link code stored over the expired one', $order->get_meta('_bmaglass_link_code'), 'LK-FRESH');

// A create response with no link_code fails instead of redirecting
// the buyer to https://bmaglasspay.online/pay/ (a dead page).
reset_state();
$GLOBALS['client_script'] = link_create_script('LK-NONE', false);
$gw = main_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00);
$GLOBALS['test_order'] = $order;
$result = $gw->process_payment(42);
eq('missing link_code fails the checkout', $result['result'], 'failure');
check('missing link_code leaves no dead redirect', !isset($result['redirect']) || strpos($result['redirect'], '/pay/') === false);
check('missing link_code is noted on the order', strpos(implode(' ', $order->notes), 'link_code') !== false || strpos(implode(' ', $order->notes), 'payment link') !== false);

// Unsupported currency refused before any API call.
reset_state();
$GLOBALS['client_script'] = link_create_script();
$gw = main_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00, 'EUR');
$GLOBALS['test_order'] = $order;
$result = $gw->process_payment(42);
eq('EUR refused', $result['result'], 'failure');
check('EUR refusal makes no API call', count($GLOBALS['api_calls']) === 0);

// No API key configured.
reset_state();
$gw = main_gateway_with('');
$order = new FakeOrder(100.00);
$GLOBALS['test_order'] = $order;
$result = $gw->process_payment(42);
eq('no key fails the checkout', $result['result'], 'failure');

// API failure (e.g. insufficient scope) fails with an order note.
reset_state();
$GLOBALS['client_script'] = function ($op, $arg) {
    throw new BMaGlassApiException(403, 'insufficient_scope', 'missing scope');
};
$gw = main_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00);
$GLOBALS['test_order'] = $order;
$result = $gw->process_payment(42);
eq('API failure fails the checkout', $result['result'], 'failure');
check('API failure is noted on the order', strpos(implode(' ', $order->notes), 'failed') !== false);

// ── Mobile-money gateway ─────────────────────────────────────────────
function momo_gateway_with($apiKey, $feeBearer = 'seller')
{
    $GLOBALS['wp_options'] = array(
        'woocommerce_bmaglass_settings' => array('api_key' => $apiKey),
        'woocommerce_bmaglass_momo_settings' => array('enabled' => 'yes', 'title' => 'Mobile money', 'description' => 'Pay.'),
    );
    $GLOBALS['test_momo_feature'] = array('status' => 'enabled', 'reason_code' => null, 'connected' => true, 'fee_bearer' => $feeBearer);
    $GLOBALS['transients'] = array();
    return new WC_Gateway_BMaGlass_Mobile_Money();
}

function momo_script($collectionStatus = 'pending', $amount = null)
{
    return function ($op, $arg) use ($collectionStatus, $amount) {
        if ($op === 'connectorConfig') {
            return array('features' => array('mobile_money' => $GLOBALS['test_momo_feature']));
        }
        if ($op === 'createMobileMoneyCollection') {
            $base = round((float) $arg['amount'], 2);
            $bearer = $GLOBALS['test_momo_feature']['fee_bearer'] ?? 'seller';
            $charge = $bearer === 'buyer' ? round($base * 0.01, 2) : 0;
            return array('reference' => 'BMG-NEW', 'merchant_reference' => $arg['reference'] ?? null,
                'amount' => $amount !== null ? $amount : round($base + $charge, 2), 'base_amount' => $base,
                'service_charge' => $charge, 'fee_bearer' => $bearer, 'currency' => 'ZMW',
                'operator' => $arg['operator'] ?? 'mtn', 'phone' => $arg['phone'], 'status' => $collectionStatus);
        }
        if ($op === 'getMobileMoneyCollection') {
            return $GLOBALS['test_existing_collection'];
        }
        throw new RuntimeException('unexpected op ' . $op);
    };
}

function run_momo_payment($apiKey, $orderTotal, $phone = '0977123456')
{
    $GLOBALS['api_calls'] = array();
    $GLOBALS['notices'] = array();
    $GLOBALS['cart_emptied'] = false;
    $order = new FakeOrder($orderTotal);
    $order->method = 'bmaglass_momo';
    $GLOBALS['test_order'] = $order;
    $_POST['bmaglass_momo_phone'] = $phone;
    $_POST['bmaglass_momo_operator'] = '';
    $gw = momo_gateway_with($apiKey);
    $result = $gw->process_payment(42);
    return array($gw, $order, $result);
}

// Sandbox key CAN check out by mobile money: the sandbox connection
// uses test numbers and never charges a real phone.
reset_state();
$GLOBALS['client_script'] = momo_script('pending');
list($gw, $order, $result) = run_momo_payment('bmg_sandbox_test', 100);
eq('sandbox MoMo checkout succeeds', $result['result'], 'success');
check('sandbox MoMo starts a collection', count(array_filter($GLOBALS['api_calls'], function ($c) { return $c['op'] === 'createMobileMoneyCollection'; })) === 1);
check('sandbox MoMo parks the order on-hold', $order->status === 'on-hold');

// Phone normalisation: the placeholder invites "0977 123 456".
reset_state();
$GLOBALS['client_script'] = momo_script('pending');
list($gw, $order, $result) = run_momo_payment('bmg_live_test', 100, '0977 123 456');
$created = null;
foreach ($GLOBALS['api_calls'] as $c) {
    if ($c['op'] === 'createMobileMoneyCollection') $created = $c['arg'];
}
eq('spaced phone is sent digit-only', $created['phone'], '0977123456');
eq('normalised phone stored on the order', $order->get_meta('_bmaglass_momo_phone'), '0977123456');

reset_state();
$GLOBALS['client_script'] = momo_script('pending');
list($gw, $order, $result) = run_momo_payment('bmg_live_test', 100, '+260 977 123 456');
$created = null;
foreach ($GLOBALS['api_calls'] as $c) {
    if ($c['op'] === 'createMobileMoneyCollection') $created = $c['arg'];
}
eq('+260 phone is sent as 0-prefixed', $created['phone'], '0977123456');

// Retry after a FAILED collection: a fresh collection under a FRESH
// merchant reference (the API's idempotency key is the reference).
reset_state();
$GLOBALS['test_existing_collection'] = array('reference' => 'BMG-OLD', 'merchant_reference' => 'woo-order-42',
    'amount' => 100, 'currency' => 'ZMW', 'status' => 'failed', 'failure_reason' => 'Insufficient funds in the mobile money account.');
$GLOBALS['client_script'] = momo_script('pending');
$gw = momo_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00, 'ZMW', array(
    '_bmaglass_momo_reference' => 'BMG-OLD',
    '_bmaglass_momo_merchant_reference' => 'woo-order-42',
    '_bmaglass_momo_attempts' => 1,
), 'failed');
$order->method = 'bmaglass_momo';
$GLOBALS['test_order'] = $order;
$_POST['bmaglass_momo_phone'] = '0977123456';
$_POST['bmaglass_momo_operator'] = '';
$GLOBALS['api_calls'] = array();
$result = $gw->process_payment(42);
eq('retry after failure succeeds', $result['result'], 'success');
$created = null;
foreach ($GLOBALS['api_calls'] as $c) {
    if ($c['op'] === 'createMobileMoneyCollection') $created = $c['arg'];
}
check('retry starts a NEW collection', $created !== null);
eq('retry uses a fresh merchant reference', $created !== null ? $created['reference'] : null, 'woo-order-42-retry-2');
eq('retry stores the new platform reference', $order->get_meta('_bmaglass_momo_reference'), 'BMG-NEW');
eq('retry stores the new merchant reference', $order->get_meta('_bmaglass_momo_merchant_reference'), 'woo-order-42-retry-2');
eq('retry bumps the attempt counter', $order->get_meta('_bmaglass_momo_attempts'), 2);
check('retry parks the order on-hold again', $order->status === 'on-hold');

// Retry while the first collection is still pending: no second charge.
reset_state();
$GLOBALS['test_existing_collection'] = array('reference' => 'BMG-OLD', 'merchant_reference' => 'woo-order-42',
    'amount' => 100, 'currency' => 'ZMW', 'status' => 'pending');
$GLOBALS['client_script'] = momo_script('pending');
$gw = momo_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00, 'ZMW', array(
    '_bmaglass_momo_reference' => 'BMG-OLD',
    '_bmaglass_momo_merchant_reference' => 'woo-order-42',
), 'on-hold');
$order->method = 'bmaglass_momo';
$GLOBALS['test_order'] = $order;
$_POST['bmaglass_momo_phone'] = '0977123456';
$_POST['bmaglass_momo_operator'] = '';
$GLOBALS['api_calls'] = array();
$result = $gw->process_payment(42);
eq('retry while pending redirects to thank-you', $result['result'], 'success');
check('pending retry starts no second collection',
    count(array_filter($GLOBALS['api_calls'], function ($c) { return $c['op'] === 'createMobileMoneyCollection'; })) === 0);

// Retry after the server no longer knows the reference (404): recreate
// under the ORIGINAL reference — there is nothing to conflict with.
reset_state();
$GLOBALS['client_script'] = function ($op, $arg) {
    if ($op === 'getMobileMoneyCollection') {
        throw new BMaGlassApiException(404, 'not_found', 'no such collection');
    }
    return call_user_func(momo_script('pending'), $op, $arg);
};
$gw = momo_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00, 'ZMW', array(
    '_bmaglass_momo_reference' => 'BMG-OLD',
    '_bmaglass_momo_merchant_reference' => 'woo-order-42',
), 'on-hold');
$order->method = 'bmaglass_momo';
$GLOBALS['test_order'] = $order;
$_POST['bmaglass_momo_phone'] = '0977123456';
$_POST['bmaglass_momo_operator'] = '';
$GLOBALS['api_calls'] = array();
$result = $gw->process_payment(42);
$created = null;
foreach ($GLOBALS['api_calls'] as $c) {
    if ($c['op'] === 'createMobileMoneyCollection') $created = $c['arg'];
}
eq('unknown reference recreates under the original reference', $created !== null ? $created['reference'] : null, 'woo-order-42');

// A status poll that fails (network) never starts a second collection.
reset_state();
$GLOBALS['client_script'] = function ($op, $arg) {
    if ($op === 'getMobileMoneyCollection') {
        throw new BMaGlassApiException(0, 'network_error', "Couldn't reach BMaGlass Pay. (Connection timed out)");
    }
    throw new RuntimeException('unexpected op ' . $op);
};
$gw = momo_gateway_with('bmg_live_test');
$order = new FakeOrder(100.00, 'ZMW', array(
    '_bmaglass_momo_reference' => 'BMG-OLD',
    '_bmaglass_momo_merchant_reference' => 'woo-order-42',
), 'on-hold');
$order->method = 'bmaglass_momo';
$GLOBALS['test_order'] = $order;
$_POST['bmaglass_momo_phone'] = '0977123456';
$_POST['bmaglass_momo_operator'] = '';
$GLOBALS['api_calls'] = array();
$result = $gw->process_payment(42);
eq('failed poll redirects without a second collection', $result['result'], 'success');
check('failed poll starts no collection',
    count(array_filter($GLOBALS['api_calls'], function ($c) { return $c['op'] === 'createMobileMoneyCollection'; })) === 0);

// A failed connector-config read is NOT cached: the next call retries.
reset_state();
$calls = 0;
$GLOBALS['client_script'] = function ($op, $arg) use (&$calls) {
    if ($op === 'connectorConfig') {
        $calls++;
        if ($calls === 1) {
            throw new BMaGlassApiException(0, 'network_error', 'down');
        }
        return array('features' => array('mobile_money' => $GLOBALS['test_momo_feature']));
    }
    throw new RuntimeException('unexpected op ' . $op);
};
$gw = momo_gateway_with('bmg_live_test');
$first = $gw->momo_feature();
check('failed feature read returns empty', $first === array());
$second = $gw->momo_feature();
check('failed feature read is retried, not served from cache', $calls === 2 && isset($second['status']) && $second['status'] === 'enabled');

echo $failures === 0 ? "\nALL GATEWAY TESTS PASSED\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
