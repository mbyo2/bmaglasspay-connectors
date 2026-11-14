<?php
/**
 * WooCommerce payment gateway: BMaGlass Pay.
 *
 * Checkout flow (honest about how the API works today):
 *  1. The gateway creates ONE BMaGlass payment link for the exact
 *     order total (live key required) and redirects the buyer to the
 *     hosted BMaGlass payment page, where they pay wallet-to-wallet
 *     from their BMaGlass Pay account.
 *  2. The order is confirmed the moment the link is paid: the
 *     developer API's signed payment_link.paid webhook hits this
 *     plugin's HMAC-verified endpoint, which completes the order
 *     immediately and exactly once.
 *  3. Reconciliation is the fallback: a WP-Cron job (plus an immediate
 *     check on the thank-you page and a "Check BMaGlass payment"
 *     order action) polls the API for the link's payment_count /
 *     total_collected and completes any order whose webhook delivery
 *     was missed — guarded so it can never double-complete.
 *
 * @package BMaGlassPay
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_BMaGlass extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'bmaglass';
        $this->method_title = 'BMaGlass Pay';
        $this->method_description = 'Accept payments into your BMaGlass Pay wallets (ZMW / USD) via hosted BMaGlass payment links. '
            . 'Uses the developer API key from your BMaGlass Pay account — the same account and wallets as the app.';
        $this->has_fields = false;
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable BMaGlass Pay',
                'default' => 'no',
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Shown to the buyer at checkout.',
                'default' => 'BMaGlass Pay',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Shown to the buyer at checkout.',
                'default' => 'Pay with your BMaGlass Pay wallet. You\'ll be taken to BMaGlass Pay to complete the payment; '
                    . 'your order is confirmed automatically once the payment lands.',
            ),
            'api_key' => array(
                'title' => 'API key',
                'type' => 'password',
                'description' => 'Create a key in BMaGlass Pay (Business settings or the API page) with the payment_links:write, '
                    . 'wallets:read and webhooks:write scopes (webhooks:write is what lets this plugin register its webhook). '
                    . 'A production key (bmg_live_...) is required to take payments. A sandbox key '
                    . '(bmg_sandbox_...) runs the integration with simulated money — it can never move real money. '
                    . 'Older bmp_ keys keep working. The plugin configures itself from the key: paste it, test the '
                    . 'connection, and your environment and available features are read from your BMaGlass account.',
            ),
            'webhook_id' => array(
                'title' => 'Webhook ID',
                'type' => 'text',
                'description' => 'Filled automatically by "Register webhook" below. When a payment link is paid, '
                    . 'the signed payment_link.paid webhook completes the order immediately; automatic '
                    . 'reconciliation runs as the fallback (see the plugin README).',
                'custom_attributes' => array('readonly' => 'readonly'),
            ),
            'webhook_secret' => array(
                'title' => 'Webhook secret',
                'type' => 'password',
                'description' => 'The webhook signing secret (whsec_...), shown once by BMaGlass when the webhook is '
                    . 'registered and stored here so incoming events can be verified.',
                'custom_attributes' => array('readonly' => 'readonly'),
            ),
        );
    }

    /** The configured client, or null when no key is set. */
    public function client()
    {
        $key = trim((string) $this->get_option('api_key'));
        if ($key === '') {
            return null;
        }
        try {
            return new BMaGlassClient($key);
        } catch (BMaGlassApiException $e) {
            return null;
        }
    }

    public function is_available()
    {
        if (!parent::is_available()) {
            return false;
        }
        return $this->client() !== null;
    }

    /**
     * Settings screen + connection tools (Test connection / Register
     * webhook), handled by admin-post actions in the main plugin file.
     */
    public function admin_options()
    {
        parent::admin_options();
        $webhookUrl = rest_url('bmaglass/v1/webhook');
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">Connection tools</th>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                        <?php wp_nonce_field('bmaglass_test_connection'); ?>
                        <input type="hidden" name="action" value="bmaglass_test_connection" />
                        <button type="submit" class="button">Test connection</button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                        <?php wp_nonce_field('bmaglass_register_webhook'); ?>
                        <input type="hidden" name="action" value="bmaglass_register_webhook" />
                        <button type="submit" class="button">Register webhook</button>
                    </form>
                    <p class="description">
                        Save your API key first, then test the connection — it also reads your connector
                        configuration from BMaGlass (environment, business country and feature availability,
                        including mobile money for businesses registered in Zambia). Register webhook points BMaGlass at
                        <code><?php echo esc_html($webhookUrl); ?></code> and stores the signing secret here.
                        With a sandbox key the connection check works and payments can be simulated, but checkout
                        stays disabled for real payments — sandbox keys use simulated money by design.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Create (or reuse) a payment link for the order and redirect the
     * buyer to the hosted BMaGlass payment page.
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array('result' => 'failure');
        }
        $client = $this->client();
        if ($client === null) {
            wc_add_notice('BMaGlass Pay isn\'t configured on this store yet. Please choose another payment method.', 'error');
            return array('result' => 'failure');
        }

        // Currency guard: ZMW/USD wallets only — never a silent conversion.
        try {
            $currency = BMaGlassClient::assertSupportedCurrency($order->get_currency());
        } catch (BMaGlassApiException $e) {
            wc_add_notice($e->userMessage(), 'error');
            return array('result' => 'failure');
        }

        // Honest sandbox refusal, before touching the API: a sandbox
        // key (from the key itself — no setting can override it) runs
        // on simulated money, so real checkout can never run with it.
        if (!$client->isLive()) {
            wc_add_notice(
                'This store\'s BMaGlass key is a sandbox key, which can\'t take real payments. The store owner needs to '
                . 'switch to a production BMaGlass key before checkout can run.',
                'error'
            );
            return array('result' => 'failure');
        }

        // Reuse an existing active link for this order (retries, double-clicks).
        $existingCode = (string) $order->get_meta('_bmaglass_link_code');
        if ($existingCode !== '') {
            return array(
                'result' => 'success',
                'redirect' => BMaGlassClient::paymentLinkUrl($existingCode),
            );
        }

        $total = round((float) $order->get_total(), 2);
        try {
            $link = $client->createPaymentLink(
                sprintf('Order #%s — %s', $order->get_order_number(), get_bloginfo('name')),
                $total,
                $currency,
                sprintf('WooCommerce order %s', $order->get_order_key()),
                gmdate('c', time() + 7 * DAY_IN_SECONDS),
                null,
                // "Return to store" on the hosted pay page after payment.
                $this->get_return_url($order)
            );
        } catch (BMaGlassApiException $e) {
            wc_add_notice($e->userMessage(), 'error');
            $order->add_order_note('BMaGlass: payment link creation failed — ' . $e->getMessage());
            return array('result' => 'failure');
        }

        $order->update_meta_data('_bmaglass_link_id', isset($link['id']) ? $link['id'] : '');
        $order->update_meta_data('_bmaglass_link_code', isset($link['link_code']) ? $link['link_code'] : '');
        $order->update_meta_data('_bmaglass_payment_count_seen', 0);
        $order->update_status('on-hold', 'Awaiting BMaGlass Pay payment — link ' . (isset($link['link_code']) ? $link['link_code'] : '') . '.');
        $order->save();

        WC()->cart->empty_cart();

        return array(
            'result' => 'success',
            'redirect' => BMaGlassClient::paymentLinkUrl($link['link_code']),
        );
    }
}
