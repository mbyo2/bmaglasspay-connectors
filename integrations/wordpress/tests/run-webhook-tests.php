<?php
/**
 * Standalone tests for the plugin bootstrap's webhook endpoint
 * (bmaglass_handle_webhook in bmaglass-pay.php) — the HMAC-verified
 * settlement push path. No WordPress, no network: the WP/WC surface
 * the bootstrap touches is stubbed, the real bootstrap + real
 * reconciler are loaded, and the API client is a faithful stub (same
 * public surface as the real BMaGlassClient; transport-level
 * behaviour is pinned by run-client-tests.php against the real
 * client).
 *
 * What is pinned:
 *   - A valid signed payment_link.paid settles the matching order
 *     exactly once; a duplicate delivery does not complete twice.
 *   - A tampered body, a wrong secret, a missing secret, or a
 *     missing signature header all answer 401 and touch no order.
 *   - Malformed JSON / a payload with no type answers 400.
 *   - Unknown event types (e.g. invoice.paid) answer 200 and are
 *     logged, never fatal.
 *   - payment_link.paid arriving out of order (no prior
 *     payment_link.created) still settles — the handler does not
 *     depend on the created event.
 *   - A paid event for an unknown link, or with too small an amount,
 *     settles nothing (the API-reconciliation fallback runs for the
 *     matched order and also settles nothing when the link is unpaid).
 *   - mobile_money.collection.completed settles exactly once the
 *     same way; webhook.test answers 200.
 *
 * Run: php integrations/wordpress/tests/run-webhook-tests.php
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
error_reporting(E_ALL & ~E_DEPRECATED);

// ── Stub BMaGlass client (faithful copy of the real client's public
// surface; see class-bmaglass-api-client.php). ───────────────────────
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
                return 'This BMaGlass API key is a sandbox key.';
            case 'invalid_api_key':
            case 'api_key_revoked':
            case 'api_key_expired':
                return 'That BMaGlass API key wasn\'t accepted.';
            case 'insufficient_scope':
                return 'This BMaGlass API key doesn\'t have permission for that action.';
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
    private $apiKey;
    public function __construct($apiKey, $baseUrl = null, $transport = null)
    {
        if (!is_string($apiKey) || (strpos($apiKey, 'bmg_') !== 0 && strpos($apiKey, 'bmp_') !== 0)) {
            throw new BMaGlassApiException(0, 'invalid_api_key', 'api_key must be a BMaGlass Pay API key.');
        }
        $this->apiKey = $apiKey;
    }
    public function isLive()
    {
        return strpos($this->apiKey, '_live_') !== false;
    }
    public function environment()
    {
        return $this->isLive() ? 'production' : 'sandbox';
    }
    public static function verifyWebhookSignature($rawBody, $signatureHeader, $secret)
    {
        if (!is_string($signatureHeader) || strpos($signatureHeader, 'sha256=') !== 0 || !$secret) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', (string) $rawBody, (string) $secret);
        return hash_equals($expected, $signatureHeader);
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
    public function listPaymentLinks()
    {
        return $this->call('listPaymentLinks', null);
    }
    public function getMobileMoneyCollection($reference)
    {
        return $this->call('getMobileMoneyCollection', $reference);
    }
    public function wallets()
    {
        return $this->call('wallets', null);
    }
    public function connectorConfig()
    {
        return $this->call('connectorConfig', null);
    }
}

// ── WP/WC stubs ──────────────────────────────────────────────────────
$GLOBALS['wp_options'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['orders'] = array();
$GLOBALS['log'] = array();
$GLOBALS['api_calls'] = array();
$GLOBALS['client_script'] = null;
$GLOBALS['registered_routes'] = array();
$GLOBALS['hooks'] = array();

function plugin_dir_path($file) { return dirname(__DIR__) . '/bmaglass-pay/'; }
function plugins_url($path, $file) { return 'https://example.test/wp-content/plugins/bmaglass-pay/' . $path; }
function get_option($k, $default = array())
{
    return isset($GLOBALS['wp_options'][$k]) ? $GLOBALS['wp_options'][$k] : $default;
}
function update_option($k, $v) { $GLOBALS['wp_options'][$k] = $v; }
function delete_option($k) { unset($GLOBALS['wp_options'][$k]); }
function get_transient($k) { return isset($GLOBALS['transients'][$k]) ? $GLOBALS['transients'][$k] : false; }
function set_transient($k, $v, $exp = 0) { $GLOBALS['transients'][$k] = $v; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); }
function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function add_filter($hook, $cb, $prio = 10, $args = 1) {}
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}
function wp_next_scheduled($hook) { return false; }
function wp_schedule_event($ts, $rec, $hook) {}
function wp_clear_scheduled_hook($hook) {}
function wp_register_script($h, $src, $deps, $ver, $in_footer) {}
function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function get_current_user_id() { return 1; }
function check_admin_referer($action) { return true; }
function current_user_can($cap) { return true; }
function wp_die($msg) { throw new RuntimeException('wp_die: ' . $msg); }
function wp_safe_redirect($url) { $GLOBALS['redirected_to'] = $url; }
function get_bloginfo($k) { return 'Test Shop'; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return (string) $u; }
function esc_html__($t, $d = null) { return $t; }
function __($t, $d = null) { return $t; }
function wp_json_encode($v) { return json_encode($v); }
function register_rest_route($ns, $route, $args)
{
    $GLOBALS['registered_routes'][] = array($ns, $route, $args);
}
function __return_true() { return true; }

class WP_REST_Request
{
    private $body;
    private $headers;
    public function __construct($body, $headers = array())
    {
        $this->body = $body;
        $this->headers = array();
        foreach ($headers as $k => $v) {
            $this->headers[strtolower($k)] = $v;
        }
    }
    public function get_body() { return $this->body; }
    public function get_header($name) {
        $name = strtolower($name);
        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }
}
class WP_REST_Response
{
    private $data;
    private $status;
    public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}

class FakeLogger
{
    public function info($msg, $ctx = array()) { $GLOBALS['log'][] = $msg; }
}
function wc_get_logger() { return new FakeLogger(); }

class FakeOrder
{
    public $meta = array();
    public $status;
    public $total;
    public $currency;
    public $complete_calls = 0;
    public $notes = array();
    public $method = 'bmaglass';
    public function __construct($status, $total, $currency, $meta, $method = 'bmaglass')
    {
        $this->status = $status;
        $this->total = $total;
        $this->currency = $currency;
        $this->meta = $meta;
        $this->method = $method;
    }
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function update_status($s, $note = '') { $this->status = $s; if ($note) $this->notes[] = $note; }
    public function get_status() { return $this->status; }
    public function get_total() { return $this->total; }
    public function get_currency() { return $this->currency; }
    public function get_payment_method() { return $this->method; }
    public function add_order_note($n) { $this->notes[] = $n; }
    public function save() {}
    public function payment_complete($txn = '')
    {
        if (!in_array($this->status, array('on-hold', 'pending'), true)) {
            return false;
        }
        $this->status = 'processing';
        $this->complete_calls++;
        return true;
    }
}

function wc_get_order($id) { return isset($GLOBALS['orders'][$id]) ? $GLOBALS['orders'][$id] : null; }
function wc_get_orders($args)
{
    $out = array();
    $wantCode = null;
    $wantMomoRef = null;
    if (!empty($args['meta_query'])) {
        foreach ($args['meta_query'] as $clause) {
            if (isset($clause['key']) && $clause['key'] === '_bmaglass_link_code' && isset($clause['value'])) {
                $wantCode = $clause['value'];
            }
            if (isset($clause['key']) && $clause['key'] === '_bmaglass_momo_merchant_reference' && isset($clause['value'])) {
                $wantMomoRef = $clause['value'];
            }
        }
    }
    foreach ($GLOBALS['orders'] as $order) {
        if (isset($args['status']) && !in_array($order->get_status(), $args['status'], true)) {
            continue;
        }
        if ($wantCode !== null && $order->get_meta('_bmaglass_link_code') !== $wantCode) {
            continue;
        }
        if ($wantMomoRef !== null && $order->get_meta('_bmaglass_momo_merchant_reference') !== $wantMomoRef) {
            continue;
        }
        $out[] = $order;
    }
    if (!empty($args['limit'])) {
        $out = array_slice($out, 0, $args['limit']);
    }
    return $out;
}

// Load the real bootstrap (its client require is skipped: the stub
// class already exists) + the real reconciler. Fire rest_api_init the
// way WordPress does, so the webhook route is registered.
require_once dirname(__DIR__) . '/bmaglass-pay/bmaglass-pay.php';
foreach (isset($GLOBALS['hooks']['rest_api_init']) ? $GLOBALS['hooks']['rest_api_init'] : array() as $cb) {
    call_user_func($cb);
}

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

$SECRET = 'whsec_testsecret';
function sign($raw, $secret) {
    return 'sha256=' . hash_hmac('sha256', $raw, $secret);
}
function call_webhook($raw, $headers = array()) {
    global $SECRET;
    if (!isset($headers['X-BMaGlass-Signature'])) {
        $headers['X-BMaGlass-Signature'] = sign($raw, $SECRET);
    }
    $req = new WP_REST_Request($raw, $headers);
    return bmaglass_handle_webhook($req);
}
function fresh_link_order($status = 'on-hold') {
    $order = new FakeOrder($status, 5.00, 'ZMW', array('_bmaglass_link_code' => 'LK1'));
    $GLOBALS['orders'] = array(1 => $order);
    return $order;
}
function fresh_momo_order($status = 'on-hold') {
    $order = new FakeOrder($status, 100.00, 'ZMW', array(
        '_bmaglass_momo_reference' => 'BMG-MOMO1',
        '_bmaglass_momo_merchant_reference' => 'woo-order-9',
    ), 'bmaglass_momo');
    $GLOBALS['orders'] = array(1 => $order);
    return $order;
}
function setup($secret = 'whsec_testsecret') {
    $GLOBALS['wp_options'] = array(
        'woocommerce_bmaglass_settings' => array('api_key' => 'bmg_live_test', 'webhook_secret' => $secret),
    );
    $GLOBALS['log'] = array();
    $GLOBALS['api_calls'] = array();
    $GLOBALS['client_script'] = function ($op, $arg) {
        // Fallback reconciliation: the link is unpaid.
        if ($op === 'listPaymentLinks') {
            return array(array('id' => 'pl1', 'link_code' => 'LK1', 'payment_count' => 0, 'total_collected' => 0));
        }
        if ($op === 'getMobileMoneyCollection') {
            return array('reference' => 'BMG-MOMO1', 'status' => 'pending');
        }
        throw new RuntimeException('unexpected op ' . $op);
    };
}

// Bootstrap sanity: the webhook route was registered.
check('bootstrap registers POST bmaglass/v1/webhook', count(array_filter($GLOBALS['registered_routes'], function ($r) {
    return $r[0] === 'bmaglass/v1' && $r[1] === '/webhook' && $r[2]['methods'] === 'POST';
})) === 1);

// ── payment_link.paid ────────────────────────────────────────────────
setup();
$order = fresh_link_order();
$raw = json_encode(array('id' => 'evt_1', 'type' => 'payment_link.paid',
    'data' => array('link_code' => 'LK1', 'amount' => 5, 'currency' => 'ZMW')));
$res = call_webhook($raw);
check('paid event answers 200 received', $res->get_status() === 200 && $res->get_data() === array('received' => true));
check('paid event completes the matching order', $order->complete_calls === 1 && $order->get_meta('_bmaglass_settled') === 'yes');

$res = call_webhook($raw);
check('duplicate paid event answers 200', $res->get_status() === 200);
check('duplicate paid event does NOT complete twice', $order->complete_calls === 1);

// Tampered body: the signature no longer matches.
setup();
$order = fresh_link_order();
$res = call_webhook($raw . ' ', array('X-BMaGlass-Signature' => sign($raw, $SECRET)));
check('tampered body answers 401', $res->get_status() === 401);
check('tampered body touches no order', $order->complete_calls === 0);

// Wrong secret.
setup();
$order = fresh_link_order();
$res = call_webhook($raw, array('X-BMaGlass-Signature' => sign($raw, 'whsec_wrong')));
check('wrong secret answers 401', $res->get_status() === 401);
check('wrong secret touches no order', $order->complete_calls === 0);

// Missing signature header.
setup();
$order = fresh_link_order();
$req = new WP_REST_Request($raw, array());
$res = bmaglass_handle_webhook($req);
check('missing signature answers 401', $res->get_status() === 401);

// No secret configured: fail closed.
setup('');
$order = fresh_link_order();
$res = call_webhook($raw);
check('no stored secret answers 401', $res->get_status() === 401);
check('no stored secret touches no order', $order->complete_calls === 0);

// Malformed JSON (signed, so it reaches the payload check).
setup();
$order = fresh_link_order();
$res = call_webhook('{bad json');
check('malformed JSON answers 400', $res->get_status() === 400);
check('malformed JSON touches no order', $order->complete_calls === 0);

// Valid JSON with no type.
setup();
$order = fresh_link_order();
$res = call_webhook(json_encode(array('id' => 'evt_2', 'data' => array())));
check('payload with no type answers 400', $res->get_status() === 400);

// Unknown event type: 200, logged, no order change.
setup();
$order = fresh_link_order();
$res = call_webhook(json_encode(array('id' => 'evt_3', 'type' => 'invoice.paid',
    'data' => array('invoice_id' => 'inv1', 'amount' => 5, 'currency' => 'ZMW'))));
check('unknown type answers 200', $res->get_status() === 200);
check('unknown type touches no order', $order->complete_calls === 0);
check('unknown type is logged', count(array_filter($GLOBALS['log'], function ($m) {
    return strpos($m, 'unhandled type') !== false && strpos($m, 'invoice.paid') !== false;
})) === 1);

// Out-of-order: paid arrives with no prior created event.
setup();
$order = fresh_link_order('pending');
$raw = json_encode(array('id' => 'evt_4', 'type' => 'payment_link.paid',
    'data' => array('link_code' => 'LK1', 'amount' => 5, 'currency' => 'ZMW')));
$res = call_webhook($raw);
check('out-of-order paid event still settles a pending order', $res->get_status() === 200 && $order->complete_calls === 1);

// Paid event for an unknown link: 200, nothing settles.
setup();
$order = fresh_link_order();
$raw = json_encode(array('id' => 'evt_5', 'type' => 'payment_link.paid',
    'data' => array('link_code' => 'NOPE', 'amount' => 5, 'currency' => 'ZMW')));
$res = call_webhook($raw);
check('unknown link answers 200', $res->get_status() === 200);
check('unknown link settles nothing', $order->complete_calls === 0);

// Paid event with too small an amount: declined, fallback finds the
// link unpaid, order stays on-hold.
setup();
$order = fresh_link_order();
$raw = json_encode(array('id' => 'evt_6', 'type' => 'payment_link.paid',
    'data' => array('link_code' => 'LK1', 'amount' => 2, 'currency' => 'ZMW')));
$res = call_webhook($raw);
check('too-small amount answers 200', $res->get_status() === 200);
check('too-small amount settles nothing', $order->complete_calls === 0 && $order->get_status() === 'on-hold');

// Paid event with no link_code in the data: 200, no crash.
setup();
$order = fresh_link_order();
$raw = json_encode(array('id' => 'evt_7', 'type' => 'payment_link.paid',
    'data' => array('amount' => 5, 'currency' => 'ZMW')));
$res = call_webhook($raw);
check('paid event without a link_code answers 200', $res->get_status() === 200);
check('paid event without a link_code settles nothing', $order->complete_calls === 0);

// webhook.test + payment_link.created: 200, logged.
setup();
$res = call_webhook(json_encode(array('id' => 'evt_8', 'type' => 'webhook.test', 'data' => array('ping' => true))));
check('webhook.test answers 200', $res->get_status() === 200);
check('webhook.test is logged', count(array_filter($GLOBALS['log'], function ($m) {
    return strpos($m, 'webhook.test received and verified') !== false;
})) === 1);

setup();
$res = call_webhook(json_encode(array('id' => 'evt_9', 'type' => 'payment_link.created',
    'data' => array('link_code' => 'LK1', 'amount' => 5, 'currency' => 'ZMW'))));
check('payment_link.created answers 200', $res->get_status() === 200);

// ── mobile_money.collection.completed ────────────────────────────────
setup();
$order = fresh_momo_order();
$raw = json_encode(array('id' => 'evt_10', 'type' => 'mobile_money.collection.completed',
    'data' => array('merchant_reference' => 'woo-order-9', 'amount' => 100, 'currency' => 'ZMW')));
$res = call_webhook($raw);
check('momo completed answers 200', $res->get_status() === 200);
check('momo completed settles the matching order', $order->complete_calls === 1 && $order->get_meta('_bmaglass_settled') === 'yes');

$res = call_webhook($raw);
check('duplicate momo event does NOT complete twice', $order->complete_calls === 1);

// Momo event with too small an amount: declined, fallback poll finds
// the collection still pending, order stays on-hold.
setup();
$order = fresh_momo_order();
$raw = json_encode(array('id' => 'evt_11', 'type' => 'mobile_money.collection.completed',
    'data' => array('merchant_reference' => 'woo-order-9', 'amount' => 50, 'currency' => 'ZMW')));
$res = call_webhook($raw);
check('momo too-small amount settles nothing', $order->complete_calls === 0 && $order->get_status() === 'on-hold');

// Momo event for an unknown reference: 200, nothing settles.
setup();
$order = fresh_momo_order();
$raw = json_encode(array('id' => 'evt_12', 'type' => 'mobile_money.collection.completed',
    'data' => array('merchant_reference' => 'woo-order-404', 'amount' => 100, 'currency' => 'ZMW')));
$res = call_webhook($raw);
check('momo unknown reference answers 200', $res->get_status() === 200);
check('momo unknown reference settles nothing', $order->complete_calls === 0);

// transaction.completed: 200, logged, no order change.
setup();
$order = fresh_link_order();
$res = call_webhook(json_encode(array('id' => 'evt_13', 'type' => 'transaction.completed',
    'data' => array('transaction_id' => 'tx1', 'amount' => 5, 'currency' => 'ZMW'))));
check('transaction.completed answers 200', $res->get_status() === 200);
check('transaction.completed touches no order', $order->complete_calls === 0);

echo $failures === 0 ? "\nALL WEBHOOK TESTS PASSED\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
