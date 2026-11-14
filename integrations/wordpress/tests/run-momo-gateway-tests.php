<?php
/**
 * Standalone tests for the BMaGlass WooCommerce Mobile Money gateway
 * — the fee-bearer <redacted> (gate14). No WordPress, no network: the small
 * WP/WC surface the gateway touches is stubbed, and the API client
 * runs on a stub transport mirroring the developer-api contract.
 *
 * What is pinned:
 *   - momo_fee_bearer is read from the connector config
 *     (features.mobile_money.fee_bearer), never a plugin setting.
 *   - Buyer mode: the buyer is charged product + 1% — payment_fields
 *     shows the true total (K101.00 for a K100 order), and the order
 *     note records the amount actually charged, never the order total.
 *   - Seller mode: no charge line, charged amount == order total.
 *   - The collection is started with the PRODUCT amount (the server
 *     grosses it up); the gateway never adds the charge itself.
 *   - Blocks checkout data carries fee_bearer for the same disclosure.
 *
 * Run: php integrations/wordpress/tests/run-momo-gateway-tests.php
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
error_reporting(E_ALL & ~E_DEPRECATED);

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

// ── WP/WC stubs ──────────────────────────────────────────────────────
$GLOBALS['wp_options'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['wp_currency'] = 'ZMW';
$GLOBALS['cart_total'] = 100.00;
$GLOBALS['cart_emptied'] = false;
$GLOBALS['notices'] = array();
$GLOBALS['test_order'] = null;
$GLOBALS['test_momo_feature'] = array('status' => 'enabled', 'reason_code' => null, 'connected' => true, 'fee_bearer' => 'seller');
$GLOBALS['test_collection_status'] = 'pending';
$GLOBALS['api_calls'] = array();

function get_option($k, $default = array())
{
    return isset($GLOBALS['wp_options'][$k]) ? $GLOBALS['wp_options'][$k] : $default;
}
function get_transient($k)
{
    return isset($GLOBALS['transients'][$k]) ? $GLOBALS['transients'][$k] : false;
}
function set_transient($k, $v, $exp = 0)
{
    $GLOBALS['transients'][$k] = $v;
}
function add_action($hook, $cb) {}
function get_woocommerce_currency() { return $GLOBALS['wp_currency']; }
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
    public function get_total($ctx = 'view') { return $GLOBALS['cart_total']; }
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
    public function __construct($total) { $this->total = $total; }
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function update_status($s, $note = '') { $this->status = $s; if ($note) $this->notes[] = $note; }
    public function get_status() { return $this->status; }
    public function get_total() { return $this->total; }
    public function get_currency() { return $this->currency; }
    public function add_order_note($n) { $this->notes[] = $n; }
    public function save() {}
    public function payment_complete($txn = '') { $this->complete_calls++; $this->status = 'completed'; }
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
    return new BMaGlassClient($key, null, $GLOBALS['test_transport']);
}

// Stub transport mirroring the developer-api contract: the posted
// amount is the PRODUCT amount; the server grosses it up by the 1%
// service charge in buyer mode and returns amount/base_amount/
// service_charge/fee_bearer on the collection.
$GLOBALS['test_transport'] = function ($method, $url, $headers, $body) {
    $GLOBALS['api_calls'][] = array('method' => $method, 'url' => $url, 'body' => $body);
    if (strpos($url, '/connector/config') !== false) {
        $mm = $GLOBALS['test_momo_feature'];
        return array(200, json_encode(array('data' => array(
            'environment' => 'production', 'livemode' => true, 'config_version' => 'x',
            'key' => array('key_id' => 'k1', 'scopes' => array('mobile_money:create'), 'status' => 'active'),
            'business' => array('display_name' => 'QA Store', 'country' => 'ZM', 'currency' => 'ZMW', 'status' => 'active'),
            'features' => array('mobile_money' => $mm),
            'settlement' => array('type' => 'wallet', 'currency' => 'ZMW'),
        ), 'mode' => 'live')));
    }
    if (strpos($url, '/mobile-money/collections') !== false && $method === 'POST') {
        $b = json_decode($body, true);
        $base = round((float) $b['amount'], 2);
        $bearer = $GLOBALS['test_momo_feature']['fee_bearer'] ?? 'seller';
        $charge = $bearer === 'buyer' ? round($base * 0.01, 2) : 0;
        return array(201, json_encode(array('data' => array(
            'reference' => 'BMG-T1', 'merchant_reference' => $b['reference'] ?? null,
            'collection_id' => 'col1', 'transaction_id' => null,
            'amount' => round($base + $charge, 2), 'base_amount' => $base,
            'service_charge' => $charge, 'fee_bearer' => $bearer,
            'currency' => 'ZMW', 'operator' => $b['operator'] ?? 'mtn', 'phone' => $b['phone'],
            'status' => $GLOBALS['test_collection_status'], 'failure_reason' => null,
            'environment' => 'production', 'livemode' => true, 'wallet_balance' => null,
            'settled_at' => null, 'created_at' => 't',
        ), 'mode' => 'live')));
    }
    return array(404, json_encode(array('error' => 'not_found', 'message' => 'no route')));
};

require_once dirname(__DIR__) . '/bmaglass-pay/includes/class-wc-gateway-bmaglass-momo.php';

function gateway_with($feeBearer, $connected = true, $status = 'enabled')
{
    $GLOBALS['test_momo_feature'] = array('status' => $status, 'reason_code' => null, 'connected' => $connected, 'fee_bearer' => $feeBearer);
    $GLOBALS['transients'] = array();
    $GLOBALS['wp_options'] = array(
        'woocommerce_bmaglass_settings' => array('api_key' => 'bmg_live_momo_test'),
        'woocommerce_bmaglass_momo_settings' => array('enabled' => 'yes', 'title' => 'Mobile money', 'description' => 'Pay with mobile money.'),
    );
    $gw = new WC_Gateway_BMaGlass_Mobile_Money();
    return $gw;
}

// ── Charge maths (mirrors the server's platformChargeFor) ────────────
eq('service charge on K100 is K1.00', WC_Gateway_BMaGlass_Mobile_Money::momo_service_charge(100), 1.0);
eq('service charge on K10 is K0.10', WC_Gateway_BMaGlass_Mobile_Money::momo_service_charge(10), 0.1);
eq('service charge on K99.99 is K1.00', WC_Gateway_BMaGlass_Mobile_Money::momo_service_charge(99.99), 1.0);
eq('service charge on K0.05 is K0.00', WC_Gateway_BMaGlass_Mobile_Money::momo_service_charge(0.05), 0.0);

// ── Availability + fee-bearer from the config ────────────────────────
$gw = gateway_with('seller');
check('available when enabled + connected', $gw->feature_available() === true);
check('seller mode by default', $gw->momo_fee_bearer() === 'seller');
eq('seller buyer-total equals the product amount', $gw->momo_buyer_total(100), 100.0);

$gw = gateway_with('buyer');
check('buyer mode read from the config', $gw->momo_fee_bearer() === 'buyer');
eq('buyer total is product + 1% (K100 -> K101.00)', $gw->momo_buyer_total(100), 101.0);
eq('buyer total on K10 is K10.10', $gw->momo_buyer_total(10), 10.1);

$gw = gateway_with('buyer', false);
check('unavailable when not connected', $gw->feature_available() === false);
$gw = gateway_with('buyer', true, 'disabled');
check('unavailable when the feature is disabled', $gw->momo_feature() !== null && $gw->feature_available() === false);

$gw = gateway_with('seller');
// fee_bearer absent from the config defaults to seller, never errors.
$GLOBALS['test_momo_feature'] = array('status' => 'enabled', 'reason_code' => null, 'connected' => true);
$GLOBALS['transients'] = array();
check('missing fee_bearer defaults to seller', $gw->momo_fee_bearer() === 'seller');

// ── Checkout disclosure (classic) ────────────────────────────────────
$gw = gateway_with('buyer');
$GLOBALS['cart_total'] = 100.00;
ob_start();
$gw->payment_fields();
$fields = ob_get_clean();
check('buyer mode shows the true K101.00 total', strpos($fields, '101.00') !== false);
check('buyer mode shows the K100.00 product amount', strpos($fields, '100.00') !== false);
check('buyer mode shows the K1.00 service charge', strpos($fields, '1.00') !== false);

$gw = gateway_with('seller');
ob_start();
$gw->payment_fields();
$fields = ob_get_clean();
check('seller mode shows no service-charge line', strpos($fields, 'service charge') === false);

// ── process_payment: the charged amount is the collection's ──────────
function run_payment($feeBearer, $orderTotal, $collectionStatus = 'pending')
{
    $gw = gateway_with($feeBearer);
    $GLOBALS['test_collection_status'] = $collectionStatus;
    $GLOBALS['api_calls'] = array();
    $GLOBALS['notices'] = array();
    $GLOBALS['cart_emptied'] = false;
    $order = new FakeOrder($orderTotal);
    $GLOBALS['test_order'] = $order;
    $_POST['bmaglass_momo_phone'] = '0977123456';
    $_POST['bmaglass_momo_operator'] = '';
    $result = $gw->process_payment(42);
    return array($gw, $order, $result);
}

list($gw, $order, $result) = run_payment('buyer', 100);
eq('buyer checkout succeeds', $result['result'], 'success');
check('order parked on-hold while the buyer approves', $order->status === 'on-hold');
$posted = json_decode(end($GLOBALS['api_calls'])['body'], true);
eq('collection started with the PRODUCT amount (K100, not K101)', $posted['amount'], 100);
$note = implode("\n", $order->notes);
check('on-hold note quotes the charged K101.00, not the order total', strpos($note, '101.00') !== false);
$notice = implode("\n", array_map(function ($n) { return $n[1]; }, $GLOBALS['notices']));
check('buyer notice quotes the charged K101.00', strpos($notice, '101.00') !== false);
check('cart emptied', $GLOBALS['cart_emptied'] === true);
eq('order reference stored', $order->get_meta('_bmaglass_momo_reference'), 'BMG-T1');

list($gw, $order, $result) = run_payment('seller', 100);
$note = implode("\n", $order->notes);
check('seller on-hold note quotes K100.00', strpos($note, '100.00') !== false);
check('seller note does not quote K101.00', strpos($note, '101.00') === false);

list($gw, $order, $result) = run_payment('buyer', 100, 'successful');
check('synchronous settlement completes the order', $order->status === 'completed' && $order->complete_calls === 1);
$note = implode("\n", $order->notes);
check('confirmed note quotes the charged K101.00', strpos($note, '101.00') !== false);

// ── Blocks checkout carries fee_bearer ───────────────────────────────
if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
    eval('namespace Automattic\\WooCommerce\\Blocks\\Payments\\Integrations; class AbstractPaymentMethodType { protected $name; public $settings = array(); public function get_name() { return $this->name; } }');
}
require_once dirname(__DIR__) . '/bmaglass-pay/includes/class-bmaglass-momo-blocks-integration.php';
$GLOBALS['wp_options'] = array(
    'woocommerce_bmaglass_momo_settings' => array('enabled' => 'yes', 'title' => 'Mobile money', 'description' => 'Pay with mobile money.'),
    'woocommerce_bmaglass_settings' => array('api_key' => 'bmg_live_momo_test'),
);
$GLOBALS['test_momo_feature'] = array('status' => 'enabled', 'reason_code' => null, 'connected' => true, 'fee_bearer' => 'buyer');
$GLOBALS['transients'] = array();
$bi = new BMaGlass_Momo_Blocks_Integration();
$bi->initialize();
$data = $bi->get_payment_method_data();
check('blocks data carries fee_bearer buyer', ($data['fee_bearer'] ?? null) === 'buyer');

$GLOBALS['test_momo_feature'] = array('status' => 'enabled', 'reason_code' => null, 'connected' => true, 'fee_bearer' => 'seller');
$GLOBALS['transients'] = array();
$bi = new BMaGlass_Momo_Blocks_Integration();
$bi->initialize();
$data = $bi->get_payment_method_data();
check('blocks data carries fee_bearer seller', ($data['fee_bearer'] ?? null) === 'seller');

if ($failures > 0) {
    echo "\nFAILURES: $failures\n";
    exit(1);
}
echo "MoMo gateway fee-bearer <redacted> ALL PASS\n";
