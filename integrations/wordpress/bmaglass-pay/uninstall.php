<?php
/**
 * BMaGlass Pay for WooCommerce — uninstall.
 * Removes both gateways' settings (which hold the API key + webhook
 * secret) and the reconciliation schedule, and retires the registered
 * webhook on the BMaGlass account so it stops delivering signed
 * events to a URL that no longer exists. Order meta is kept: it is
 * the store's own payment audit trail.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('woocommerce_bmaglass_settings', array());
if (is_array($settings)) {
    // Retire the webhook first, while the key is still available.
    // Best effort only — uninstall must never fail on a network or
    // API error (the key may already be revoked).
    if (!empty($settings['webhook_id']) && !empty($settings['api_key'])) {
        try {
            if (!class_exists('BMaGlassClient')) {
                require_once plugin_dir_path(__FILE__) . 'includes/class-bmaglass-api-client.php';
            }
            $client = new BMaGlassClient(trim((string) $settings['api_key']));
            $client->deleteWebhook($settings['webhook_id']);
        } catch (Throwable $e) {
            // Uninstall continues regardless.
        }
    }
    // Drop the cached mobile-money feature block. The transient key
    // is derived from the stored key exactly as the gateway builds
    // it (untrimmed value, first 12 chars of its md5).
    if (!empty($settings['api_key'])) {
        delete_transient('bmaglass_momo_feature_' . substr(md5((string) $settings['api_key']), 0, 12));
    }
}

delete_option('woocommerce_bmaglass_settings');
delete_option('woocommerce_bmaglass_momo_settings');
wp_clear_scheduled_hook('bmaglass_reconcile_orders');
