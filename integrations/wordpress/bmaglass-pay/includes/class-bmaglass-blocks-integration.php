<?php
/**
 * WooCommerce Blocks integration for the BMaGlass Pay gateway.
 *
 * Since WooCommerce 10.6 the Cart/Checkout blocks are the default
 * checkout for new stores, and block checkouts only offer payment
 * methods that register a Blocks payment method type — a classic
 * WC_Payment_Gateway alone is invisible there. This class is that
 * registration (Automattic\WooCommerce\Blocks\Payments\Integrations\
 * AbstractPaymentMethodType, the documented Blocks payment API).
 *
 * The block collects no payment fields for BMaGlass (hosted-page
 * flow): when the buyer places the order, WooCommerce converts the
 * block submission into the gateway's classic process_payment()
 * call (the Blocks "legacy" server-side handling), so link
 * creation, the currency guard and the honest test-key refusal all
 * live in exactly one place — class-wc-gateway-bmaglass.php.
 *
 * @package BMaGlassPay
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class BMaGlass_Blocks_Integration extends AbstractPaymentMethodType
{
    /** Must match the gateway id (WC_Gateway_BMaGlass::$id). */
    protected $name = 'bmaglass';

    /** @var array Stored gateway settings (woocommerce_bmaglass_settings). */
    private $bmaglass_settings = array();

    public function initialize()
    {
        $settings = get_option('woocommerce_bmaglass_settings', array());
        $this->bmaglass_settings = is_array($settings) ? $settings : array();
        $this->settings = $this->bmaglass_settings;
    }

    /**
     * Mirror the classic gateway's availability rule: the gateway is
     * enabled and an API key is configured. (The hard refusals —
     * test key, unsupported currency — are enforced in
     * process_payment() with plain-language notices, exactly as on
     * classic checkout.)
     */
    public function is_active()
    {
        $enabled = isset($this->bmaglass_settings['enabled']) ? $this->bmaglass_settings['enabled'] : 'no';
        $key = isset($this->bmaglass_settings['api_key']) ? trim((string) $this->bmaglass_settings['api_key']) : '';
        return $enabled === 'yes' && strpos($key, 'bmp_') === 0;
    }

    public function get_payment_method_script_handles()
    {
        return array('bmaglass-blocks-integration');
    }

    public function get_payment_method_data()
    {
        return array(
            'title' => isset($this->bmaglass_settings['title']) && $this->bmaglass_settings['title'] !== ''
                ? $this->bmaglass_settings['title'] : 'BMaGlass Pay',
            'description' => isset($this->bmaglass_settings['description'])
                ? $this->bmaglass_settings['description'] : '',
            'supports' => array('products'),
        );
    }
}
