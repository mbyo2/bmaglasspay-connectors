<?php
/**
 * Standalone tests for the WooCommerce Blocks integration class —
 * the registration surface that makes the gateway appear in the
 * Cart/Checkout block checkout. No WordPress: ABSPATH is defined
 * here, and the small surface the class touches
 * (AbstractPaymentMethodType + get_option) is stubbed with the same
 * semantics the real Blocks classes have.
 *
 * Run: php integrations/wordpress/tests/run-blocks-tests.php
 */

// ── Stubs ────────────────────────────────────────────────────────────
// The real class lives in the Automattic\WooCommerce\Blocks
// namespace and is abstract with exactly these members used here.
namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
    abstract class AbstractPaymentMethodType
    {
        protected $name = '';
        protected $settings = array();
        abstract public function initialize();
        abstract public function is_active();
        abstract public function get_payment_method_script_handles();
        abstract public function get_payment_method_data();
        public function get_name() { return $this->name; }
    }
}

namespace {
    define('ABSPATH', __DIR__ . '/');
    error_reporting(E_ALL & ~E_DEPRECATED);
    $GLOBALS['wp_options'] = array();
    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$name] : $default;
    }

    require_once dirname(__DIR__) . '/bmaglass-pay/includes/class-bmaglass-api-client.php';
    require_once dirname(__DIR__) . '/bmaglass-pay/includes/class-bmaglass-blocks-integration.php';

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

    function integration_with($settings)
    {
        $GLOBALS['wp_options'] = array('woocommerce_bmaglass_settings' => $settings);
        $i = new BMaGlass_Blocks_Integration();
        $i->initialize();
        return $i;
    }

    $base = array(
        'enabled' => 'yes',
        'title' => 'BMaGlass Pay',
        'description' => 'Pay with your BMaGlass Pay wallet.',
        'api_key' => 'bmp_live_blocks_test',
    );

    $i = integration_with($base);
    check('name matches the gateway id', $i->get_name() === 'bmaglass');
    check('active when enabled with a live key', $i->is_active() === true);

    $data = $i->get_payment_method_data();
    check('data carries the configured title', $data['title'] === 'BMaGlass Pay');
    check('data carries the configured description', $data['description'] === 'Pay with your BMaGlass Pay wallet.');
    check('data declares product support', $data['supports'] === array('products'));
    check('script handle matches the registered handle',
        $i->get_payment_method_script_handles() === array('bmaglass-blocks-integration'));

    $testKey = $base; $testKey['api_key'] = 'bmp_test_blocks_test';
    check('a test key still shows in Blocks (checkout refuses it in process_payment, as on classic)',
        integration_with($testKey)->is_active() === true);

    $off = $base; $off['enabled'] = 'no';
    check('inactive when the gateway is disabled', integration_with($off)->is_active() === false);

    $noKey = $base; $noKey['api_key'] = '';
    check('inactive with no API key configured', integration_with($noKey)->is_active() === false);

    $badKey = $base; $badKey['api_key'] = 'sk_live_not_a_bmaglass_key';
    check('inactive with a non-BMaGlass key', integration_with($badKey)->is_active() === false);

    $GLOBALS['wp_options'] = array();
    $i = new BMaGlass_Blocks_Integration();
    $i->initialize();
    check('inactive when settings are absent entirely', $i->is_active() === false);
    check('title falls back to the gateway name with no settings',
        $i->get_payment_method_data()['title'] === 'BMaGlass Pay');

    // The plugin bootstrap declares both compatibilities and hooks
    // the Blocks registration — pinned as source checks so a future
    // edit cannot silently drop them.
    $bootstrap = file_get_contents(dirname(__DIR__) . '/bmaglass-pay/bmaglass-pay.php');
    check('bootstrap declares HPOS (custom_order_tables) compatibility',
        strpos($bootstrap, "declare_compatibility('custom_order_tables'") !== false);
    check('bootstrap declares cart_checkout_blocks compatibility',
        strpos($bootstrap, "declare_compatibility('cart_checkout_blocks'") !== false);
    check('bootstrap hooks the Blocks payment-method registration',
        strpos($bootstrap, 'woocommerce_blocks_payment_method_type_registration') !== false);
    check('bootstrap registers the Blocks script handle',
        strpos($bootstrap, "'bmaglass-blocks-integration'") !== false);

    echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
    exit($failures === 0 ? 0 : 1);
}
