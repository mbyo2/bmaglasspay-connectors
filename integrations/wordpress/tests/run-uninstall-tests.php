<?php
/**
 * Standalone tests for the plugin uninstall (uninstall.php). No
 * WordPress, no network: the WP surface is stubbed and the API
 * client is a stub (its deleteWebhook is scripted); the file is
 * included once per case with fresh globals.
 *
 * What is pinned:
 *   - Uninstall retires the registered webhook on the BMaGlass
 *     account (best effort) before the key is deleted.
 *   - BOTH gateways' settings are deleted — the mobile-money
 *     gateway's own option (woocommerce_bmaglass_momo_settings)
 *     must not survive uninstall.
 *   - The cached mobile-money feature transient is dropped.
 *   - The reconciliation cron is cleared.
 *   - A webhook retirement that fails (revoked key, network) never
 *     blocks the uninstall; neither do missing settings.
 *
 * Run: php integrations/wordpress/tests/run-uninstall-tests.php
 */

define('ABSPATH', __DIR__ . '/');
define('WP_UNINSTALL_PLUGIN', true);
error_reporting(E_ALL & ~E_DEPRECATED);

// Stub client: uninstall.php requires the real file only when this
// class is absent, so the stub also proves the call shape.
class BMaGlassClient
{
    public function __construct($apiKey)
    {
        if (!is_string($apiKey) || (strpos($apiKey, 'bmg_') !== 0 && strpos($apiKey, 'bmp_') !== 0)) {
            throw new Exception('api_key must be a BMaGlass Pay API key.');
        }
    }
    public function deleteWebhook($webhookId)
    {
        $GLOBALS['webhook_deletes'][] = $webhookId;
        $script = isset($GLOBALS['webhook_delete_script']) ? $GLOBALS['webhook_delete_script'] : null;
        if (is_callable($script)) {
            call_user_func($script, $webhookId);
        }
        return array();
    }
}

$GLOBALS['wp_options'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['cleared_hooks'] = array();
$GLOBALS['webhook_deletes'] = array();
$GLOBALS['webhook_delete_script'] = null;

function get_option($k, $default = array())
{
    return isset($GLOBALS['wp_options'][$k]) ? $GLOBALS['wp_options'][$k] : $default;
}
function delete_option($k)
{
    unset($GLOBALS['wp_options'][$k]);
    $GLOBALS['deleted_options'][] = $k;
}
function delete_transient($k)
{
    unset($GLOBALS['transients'][$k]);
    $GLOBALS['deleted_transients'][] = $k;
}
function wp_clear_scheduled_hook($hook)
{
    $GLOBALS['cleared_hooks'][] = $hook;
}
function plugin_dir_path($file)
{
    return dirname(__DIR__) . '/bmaglass-pay/';
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

function run_uninstall($mainSettings, $hasMomoSettings = true)
{
    $GLOBALS['wp_options'] = array();
    $GLOBALS['transients'] = array();
    $GLOBALS['cleared_hooks'] = array();
    $GLOBALS['webhook_deletes'] = array();
    $GLOBALS['deleted_options'] = array();
    $GLOBALS['deleted_transients'] = array();
    if ($mainSettings !== null) {
        $GLOBALS['wp_options']['woocommerce_bmaglass_settings'] = $mainSettings;
    }
    if ($hasMomoSettings) {
        $GLOBALS['wp_options']['woocommerce_bmaglass_momo_settings'] = array('enabled' => 'yes', 'title' => 'Mobile money');
    }
    if (is_array($mainSettings) && !empty($mainSettings['api_key'])) {
        $GLOBALS['transients']['bmaglass_momo_feature_' . substr(md5((string) $mainSettings['api_key']), 0, 12)] = array('status' => 'enabled');
    }
    include dirname(__DIR__) . '/bmaglass-pay/uninstall.php';
}

// ── Full settings: webhook retired, both options + transient gone ────
$GLOBALS['webhook_delete_script'] = null;
run_uninstall(array('api_key' => 'bmg_live_test', 'webhook_id' => 'wh-1', 'webhook_secret' => 'whsec_x'));
check('webhook retired with the stored id', $GLOBALS['webhook_deletes'] === array('wh-1'));
check('main settings deleted', !isset($GLOBALS['wp_options']['woocommerce_bmaglass_settings']));
check('momo settings deleted', !isset($GLOBALS['wp_options']['woocommerce_bmaglass_momo_settings']));
check('feature transient deleted', $GLOBALS['deleted_transients'] === array('bmaglass_momo_feature_' . substr(md5('bmg_live_test'), 0, 12)));
check('reconciliation cron cleared', $GLOBALS['cleared_hooks'] === array('bmaglass_reconcile_orders'));

// ── A failing webhook retirement never blocks the uninstall ──────────
$GLOBALS['webhook_delete_script'] = function ($id) {
    throw new Exception('403 revoked');
};
run_uninstall(array('api_key' => 'bmg_live_test', 'webhook_id' => 'wh-2', 'webhook_secret' => 'whsec_x'));
check('failed retirement still deletes the settings', !isset($GLOBALS['wp_options']['woocommerce_bmaglass_settings'])
    && !isset($GLOBALS['wp_options']['woocommerce_bmaglass_momo_settings']));
check('failed retirement still clears the cron', $GLOBALS['cleared_hooks'] === array('bmaglass_reconcile_orders'));
$GLOBALS['webhook_delete_script'] = null;

// ── No webhook registered: no delete call, settings still removed ────
run_uninstall(array('api_key' => 'bmg_live_test'));
check('no webhook id means no delete call', $GLOBALS['webhook_deletes'] === array());
check('settings deleted without a webhook', !isset($GLOBALS['wp_options']['woocommerce_bmaglass_settings'])
    && !isset($GLOBALS['wp_options']['woocommerce_bmaglass_momo_settings']));

// ── No settings at all: no crash ─────────────────────────────────────
run_uninstall(null, false);
check('missing settings uninstall cleanly', $GLOBALS['cleared_hooks'] === array('bmaglass_reconcile_orders')
    && $GLOBALS['webhook_deletes'] === array());

// ── Corrupted (non-array) settings: no crash ─────────────────────────
run_uninstall('not-an-array', false);
check('corrupted settings uninstall cleanly', $GLOBALS['cleared_hooks'] === array('bmaglass_reconcile_orders'));

echo $failures === 0 ? "\nALL UNINSTALL TESTS PASSED\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
