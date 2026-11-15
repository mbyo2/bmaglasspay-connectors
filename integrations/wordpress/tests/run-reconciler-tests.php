<?php
/**
 * Standalone tests for the BMaGlass WooCommerce reconciler — the
 * settlement logic (payment_link.paid webhook path + polling
 * fallback). No WordPress: ABSPATH is defined here and the small
 * WP/WC surface the reconciler touches (wc_get_orders + the order
 * object) is stubbed with the same semantics WooCommerce has —
 * notably payment_complete() only transitioning an unpaid order.
 *
 * Run: php integrations/wordpress/tests/run-reconciler-tests.php
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
error_reporting(E_ALL & ~E_DEPRECATED);

require_once dirname(__DIR__) . '/bmaglass-pay/includes/class-bmaglass-api-client.php';
require_once dirname(__DIR__) . '/bmaglass-pay/includes/class-bmaglass-reconciler.php';

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

// ── Stubs ────────────────────────────────────────────────────────────
class FakeOrder
{
    public $meta = array();
    public $status;
    public $total;
    public $currency;
    public $complete_calls = 0;
    public $notes = array();

    public function __construct($status, $total, $currency, $meta)
    {
        $this->status = $status;
        $this->total = $total;
        $this->currency = $currency;
        $this->meta = $meta;
    }
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function update_status($s, $note = '') { $this->status = $s; if ($note) { $this->notes[] = $note; } }
    public function get_status() { return $this->status; }
    public function get_total() { return $this->total; }
    public function get_currency() { return $this->currency; }
    public function add_order_note($n) { $this->notes[] = $n; }
    public function save() {}
    /** Mirrors WC: only an unpaid order transitions (and counts). */
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

$GLOBALS['orders'] = array();
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
            // Cron sweeps use EXISTS / NOT EXISTS comparisons.
            if (isset($clause['key']) && isset($clause['compare'])) {
                if ($clause['compare'] === 'EXISTS') {
                    $wantExists[] = $clause['key'];
                } elseif ($clause['compare'] === 'NOT EXISTS') {
                    $wantNotExists[] = $clause['key'];
                }
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
        $skip = false;
        foreach (isset($wantExists) ? $wantExists : array() as $k) {
            if ($order->get_meta($k) === '') {
                $skip = true;
            }
        }
        foreach (isset($wantNotExists) ? $wantNotExists : array() as $k) {
            if ($order->get_meta($k) !== '') {
                $skip = true;
            }
        }
        if ($skip) {
            continue;
        }
        $out[] = $order;
    }
    if (!empty($args['limit'])) {
        $out = array_slice($out, 0, $args['limit']);
    }
    return $out;
}

/** Client whose transport reports one paid link: LK1, count 1, K5 collected. */
function paid_link_client()
{
    $transport = function ($method, $url, $headers, $body) {
        if (str_ends_with($url, '/payment-links/LK1') && $method === 'GET') {
            return array(200, json_encode(array('data' => array(
                'id' => 'pl1', 'link_code' => 'LK1', 'payment_count' => 1, 'total_collected' => 5,
            ), 'mode' => 'live')));
        }
        if (str_ends_with($url, '/payment-links') && $method === 'GET') {
            return array(200, json_encode(array('data' => array(
                array('id' => 'pl1', 'link_code' => 'LK1', 'payment_count' => 1, 'total_collected' => 5),
            ), 'mode' => 'live')));
        }
        return array(404, json_encode(array('error' => 'not_found', 'message' => 'no route')));
    };
    return new BMaGlassClient('bmp_live_reconciler_test', null, $transport);
}

function fresh_order()
{
    $order = new FakeOrder('on-hold', 5.00, 'ZMW', array('_bmaglass_link_code' => 'LK1'));
    $GLOBALS['orders'] = array($order);
    return $order;
}

// ── payment_link.paid webhook path ───────────────────────────────────
$order = fresh_order();
check('paid event completes the matching order',
    BMaGlass_Reconciler::complete_order_from_event('LK1', 5, 'ZMW') === true
    && $order->complete_calls === 1
    && $order->get_meta('_bmaglass_settled') === 'yes'
    && strpos(implode(' ', $order->notes), 'payment_link.paid') !== false);

check('duplicate paid event does NOT complete twice',
    BMaGlass_Reconciler::complete_order_from_event('LK1', 5, 'ZMW') === false
    && $order->complete_calls === 1);

$order = fresh_order();
check('paid event with too-small amount is declined',
    BMaGlass_Reconciler::complete_order_from_event('LK1', 2, 'ZMW') === false
    && $order->complete_calls === 0);

$order = fresh_order();
check('paid event with wrong currency is declined',
    BMaGlass_Reconciler::complete_order_from_event('LK1', 5, 'USD') === false
    && $order->complete_calls === 0);

check('paid event for an unknown link is declined',
    BMaGlass_Reconciler::complete_order_from_event('NOPE', 5, 'ZMW') === false);

// ── Polling fallback still works, and cannot double-complete ─────────
$order = fresh_order();
$client = paid_link_client();
check('polling reconciliation completes a paid order',
    BMaGlass_Reconciler::reconcile_order($order, $client) === true
    && $order->complete_calls === 1);
check('polling after completion is a no-op (settled flag)',
    BMaGlass_Reconciler::reconcile_order($order, $client) === true
    && $order->complete_calls === 1);

$order = fresh_order();
check('webhook settles first...',
    BMaGlass_Reconciler::complete_order_from_event('LK1', 5, 'ZMW') === true);
check('...then polling stands down (no double-complete)',
    BMaGlass_Reconciler::reconcile_order($order, $client) === true
    && $order->complete_calls === 1);

// ── Mobile money: webhook path + polling fallback ────────────────────
function momo_client($collectionStatus)
{
    $transport = function ($method, $url, $headers, $body) use ($collectionStatus) {
        if (strpos($url, '/mobile-money/collections/') !== false && $method === 'GET') {
            return array(200, json_encode(array('data' => array(
                'reference' => 'BMG-MOMO1', 'merchant_reference' => 'woo-order-9',
                'amount' => 100, 'currency' => 'ZMW', 'operator' => 'mtn', 'phone' => '0961111111',
                'status' => $collectionStatus,
                'failure_reason' => $collectionStatus === 'failed' ? 'Insufficient funds in the mobile money account.' : null,
            ), 'mode' => 'live')));
        }
        return array(404, json_encode(array('error' => 'not_found', 'message' => 'no route')));
    };
    return new BMaGlassClient('bmp_live_reconciler_test', null, $transport);
}

function fresh_momo_order()
{
    $order = new FakeOrder('on-hold', 100.00, 'ZMW', array(
        '_bmaglass_momo_reference' => 'BMG-MOMO1',
        '_bmaglass_momo_merchant_reference' => 'woo-order-9',
    ));
    $GLOBALS['orders'] = array($order);
    return $order;
}

$order = fresh_momo_order();
check('momo completed event completes the matching order',
    BMaGlass_Reconciler::complete_momo_order_from_event('woo-order-9', 100, 'ZMW') === true
    && $order->complete_calls === 1
    && $order->get_meta('_bmaglass_settled') === 'yes'
    && strpos(implode(' ', $order->notes), 'mobile_money.collection.completed') !== false);

check('duplicate momo event does NOT complete twice',
    BMaGlass_Reconciler::complete_momo_order_from_event('woo-order-9', 100, 'ZMW') === false
    && $order->complete_calls === 1);

$order = fresh_momo_order();
check('momo event with too-small amount is declined',
    BMaGlass_Reconciler::complete_momo_order_from_event('woo-order-9', 50, 'ZMW') === false
    && $order->complete_calls === 0);

$order = fresh_momo_order();
check('momo event with wrong currency is declined',
    BMaGlass_Reconciler::complete_momo_order_from_event('woo-order-9', 100, 'USD') === false
    && $order->complete_calls === 0);

check('momo event for an unknown reference is declined',
    BMaGlass_Reconciler::complete_momo_order_from_event('woo-order-404', 100, 'ZMW') === false);

$order = fresh_momo_order();
$settledClient = momo_client('successful');
check('momo polling reconciliation completes a settled order',
    BMaGlass_Reconciler::reconcile_momo_order($order, $settledClient) === true
    && $order->complete_calls === 1);
check('momo polling after completion is a no-op (settled flag)',
    BMaGlass_Reconciler::reconcile_momo_order($order, $settledClient) === true
    && $order->complete_calls === 1);

$order = fresh_momo_order();
check('momo pending collection leaves the order on-hold',
    BMaGlass_Reconciler::reconcile_momo_order($order, momo_client('pending')) === false
    && $order->complete_calls === 0
    && $order->get_status() === 'on-hold');

$order = fresh_momo_order();
check('momo failed collection fails the order with the reason',
    BMaGlass_Reconciler::reconcile_momo_order($order, momo_client('failed')) === false
    && $order->complete_calls === 0
    && $order->get_status() === 'failed'
    && strpos(implode(' ', $order->notes), 'Insufficient funds') !== false);

// ── Link-list cap: GET /payment-links returns max 100, newest ───────
// first. A store with >100 links can have the order's link beyond
// the list — reconciliation must say so (once) instead of silently
// standing down forever.
function capped_link_client($includePaidLK1)
{
    $transport = function ($method, $url, $headers, $body) use ($includePaidLK1) {
        if (str_ends_with($url, '/payment-links/LK1') && $method === 'GET') {
            // Direct read unavailable (older API): the reconciler falls
            // back to the list, which is where the cap bites.
            return array(500, json_encode(array('error' => 'request_failed', 'message' => 'boom')));
        }
        if (str_ends_with($url, '/payment-links') && $method === 'GET') {
            $links = array();
            if ($includePaidLK1) {
                $links[] = array('id' => 'pl1', 'link_code' => 'LK1', 'payment_count' => 1, 'total_collected' => 5);
            }
            // Exactly 100 rows either way: LK1 absent (cap hit, link
            // beyond it) or LK1 present (cap hit, link inside it).
            for ($i = 2; $i <= ($includePaidLK1 ? 100 : 101); $i++) {
                $links[] = array('id' => 'pl' . $i, 'link_code' => 'LK' . $i, 'payment_count' => 0, 'total_collected' => 0);
            }
            if (!$includePaidLK1) {
                $links = array_slice($links, 0, 100);
            }
            return array(200, json_encode(array('data' => $links, 'mode' => 'live')));
        }
        return array(404, json_encode(array('error' => 'not_found', 'message' => 'no route')));
    };
    return new BMaGlassClient('bmp_live_reconciler_test', null, $transport);
}

$order = fresh_order();
$client = capped_link_client(false);
check('capped list: order is NOT completed from beyond the cap',
    BMaGlass_Reconciler::reconcile_order($order, $client) === false
    && $order->complete_calls === 0
    && $order->get_status() === 'on-hold');
check('capped list: the order is told verification was inconclusive',
    strpos(implode(' ', $order->notes), '100') !== false);
$noteCount = count($order->notes);
BMaGlass_Reconciler::reconcile_order($order, $client);
check('capped list: the warning is noted only once',
    count($order->notes) === $noteCount);

$order = fresh_order();
check('capped list: a paid link inside the 100 still completes',
    BMaGlass_Reconciler::reconcile_order($order, capped_link_client(true)) === true
    && $order->complete_calls === 1);

// ── Direct single-link read: GET /payment-links/{id} ────────────────
// A link older than the 100 newest is verifiable directly, so the cap
// no longer blocks reconciliation when the endpoint answers.
function direct_link_client()
{
    $transport = function ($method, $url, $headers, $body) {
        if (str_ends_with($url, '/payment-links/LK1') && $method === 'GET') {
            return array(200, json_encode(array('data' => array(
                'id' => 'pl1', 'link_code' => 'LK1', 'payment_count' => 1, 'total_collected' => 5,
            ), 'mode' => 'live')));
        }
        if (str_ends_with($url, '/payment-links') && $method === 'GET') {
            // 100 newest rows, LK1 absent — the old code could never
            // verify this order from the list alone.
            $links = array();
            for ($i = 2; $i <= 101; $i++) {
                $links[] = array('id' => 'pl' . $i, 'link_code' => 'LK' . $i, 'payment_count' => 0, 'total_collected' => 0);
            }
            return array(200, json_encode(array('data' => $links, 'mode' => 'live')));
        }
        return array(404, json_encode(array('error' => 'not_found', 'message' => 'no route')));
    };
    return new BMaGlassClient('bmp_live_reconciler_test', null, $transport);
}

$order = fresh_order();
check('direct read: a paid link beyond the 100-list completes',
    BMaGlass_Reconciler::reconcile_order($order, direct_link_client()) === true
    && $order->complete_calls === 1);

function direct_404_client()
{
    $transport = function ($method, $url, $headers, $body) {
        // Direct read 404s (link does not belong to this key); the
        // list fallback is never reached for a 404, so no cap warning.
        if (str_ends_with($url, '/payment-links') && $method === 'GET') {
            $links = array();
            for ($i = 2; $i <= 101; $i++) {
                $links[] = array('id' => 'pl' . $i, 'link_code' => 'LK' . $i, 'payment_count' => 0, 'total_collected' => 0);
            }
            return array(200, json_encode(array('data' => $links, 'mode' => 'live')));
        }
        return array(404, json_encode(array('error' => 'not_found', 'message' => 'no route')));
    };
    return new BMaGlassClient('bmp_live_reconciler_test', null, $transport);
}

$order = fresh_order();
check('direct read 404: order is NOT completed and gets no cap warning',
    BMaGlass_Reconciler::reconcile_order($order, direct_404_client()) === false
    && $order->complete_calls === 0
    && strpos(implode(' ', $order->notes), '100') === false);

// ── Cron sweep: no environment gate ──────────────────────────────────
// reconcile_pending() runs for the key's own environment: a sandbox
// key reconciles its sandbox mobile-money orders (whose checkout
// works with sandbox keys); a live key reconciles live ones.
class FakeGatewaysCollection
{
    public function payment_gateways() { return $GLOBALS['test_gateways']; }
}
class FakeWCForCron
{
    public function payment_gateways() { return new FakeGatewaysCollection(); }
}
function WC() { return new FakeWCForCron(); }
class FakeBmaglassGateway
{
    public function client() { return $GLOBALS['test_client']; }
}

function sandbox_cron_client()
{
    $transport = function ($method, $url, $headers, $body) {
        if (str_ends_with($url, '/payment-links/LK1') && $method === 'GET') {
            return array(200, json_encode(array('data' => array(
                'id' => 'pl1', 'link_code' => 'LK1', 'payment_count' => 1, 'total_collected' => 5,
            ), 'mode' => 'test')));
        }
        if (str_ends_with($url, '/payment-links') && $method === 'GET') {
            return array(200, json_encode(array('data' => array(
                array('id' => 'pl1', 'link_code' => 'LK1', 'payment_count' => 1, 'total_collected' => 5),
            ), 'mode' => 'test')));
        }
        if (strpos($url, '/mobile-money/collections/') !== false && $method === 'GET') {
            return array(200, json_encode(array('data' => array(
                'reference' => 'BMG-MOMO1', 'merchant_reference' => 'woo-order-9',
                'amount' => 100, 'currency' => 'ZMW', 'operator' => 'mtn', 'phone' => '0961111111',
                'status' => 'successful', 'failure_reason' => null,
            ), 'mode' => 'test')));
        }
        return array(404, json_encode(array('error' => 'not_found', 'message' => 'no route')));
    };
    // A sandbox key: mode() is 'test', isLive() false.
    return new BMaGlassClient('bmp_test_reconciler_test', null, $transport);
}

$linkOrder = fresh_order();
$momoOrder = fresh_momo_order();
$GLOBALS['orders'] = array($linkOrder, $momoOrder);
$GLOBALS['test_gateways'] = array('bmaglass' => new FakeBmaglassGateway());
$GLOBALS['test_client'] = sandbox_cron_client();
BMaGlass_Reconciler::reconcile_pending();
check('cron with a sandbox key reconciles the paid link order',
    $linkOrder->complete_calls === 1 && $linkOrder->get_meta('_bmaglass_settled') === 'yes');
check('cron with a sandbox key reconciles the settled MoMo order',
    $momoOrder->complete_calls === 1 && $momoOrder->get_meta('_bmaglass_settled') === 'yes');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
