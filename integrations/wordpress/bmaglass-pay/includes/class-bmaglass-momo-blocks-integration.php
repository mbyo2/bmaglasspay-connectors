<?php
/**
 * WooCommerce Blocks integration for the BMaGlass Pay Mobile Money
 * gateway.
 *
 * Registers the 'bmaglass_momo' gateway as a Blocks payment method
 * type so it appears in the block checkout. Unlike the hosted-link
 * gateway, this method collects fields: the block component
 * (assets/js/momo-blocks-integration.js) renders the mobile money
 * number + network inputs and hands them to the server as payment
 * method data; WooCommerce then routes the submission to the
 * gateway's classic process_payment(), where the collection is
 * started — one implementation for both checkouts.
 *
 * Availability mirrors the classic gateway's rules as far as they
 * can be known synchronously: this gateway enabled, a BMaGlass API
 * key configured on the main gateway, and a ZMW store currency.
 * The authoritative availability fact — the merchant's connector
 * config reporting mobile money enabled + connected — is enforced
 * by the classic gateway (is_available) and process_payment's own
 * API errors, exactly as the hard refusals are for the main gateway.
 *
 * @package BMaGlassPay
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class BMaGlass_Momo_Blocks_Integration extends AbstractPaymentMethodType
{
    /** Must match the gateway id (WC_Gateway_BMaGlass_Mobile_Money::$id). */
    protected $name = 'bmaglass_momo';

    /** @var array This gateway's settings (woocommerce_bmaglass_momo_settings). */
    private $momo_settings = array();

    /** @var array The main gateway's settings (woocommerce_bmaglass_settings) — holds the shared API key. */
    private $main_settings = array();

    public function initialize()
    {
        $settings = get_option('woocommerce_bmaglass_momo_settings', array());
        $this->momo_settings = is_array($settings) ? $settings : array();
        $this->settings = $this->momo_settings;
        $main = get_option('woocommerce_bmaglass_settings', array());
        $this->main_settings = is_array($main) ? $main : array();
    }

    public function is_active()
    {
        $enabled = isset($this->momo_settings['enabled']) ? $this->momo_settings['enabled'] : 'no';
        $key = isset($this->main_settings['api_key']) ? trim((string) $this->main_settings['api_key']) : '';
        $hasKey = strpos($key, 'bmg_') === 0 || strpos($key, 'bmp_') === 0;
        return $enabled === 'yes' && $hasKey && get_woocommerce_currency() === 'ZMW';
    }

    public function get_payment_method_script_handles()
    {
        return array('bmaglass-momo-blocks-integration');
    }

    public function get_payment_method_data()
    {
        return array(
            'title' => isset($this->momo_settings['title']) && $this->momo_settings['title'] !== ''
                ? $this->momo_settings['title'] : 'Mobile money',
            'description' => isset($this->momo_settings['description'])
                ? $this->momo_settings['description'] : '',
            'supports' => array('products'),
        );
    }
}
