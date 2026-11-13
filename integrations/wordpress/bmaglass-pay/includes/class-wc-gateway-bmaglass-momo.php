<?php
/**
 * WooCommerce payment gateway: BMaGlass Pay — Mobile Money.
 *
 * A second gateway alongside the hosted-link gateway, offered only
 * when the merchant's own BMaGlass account says mobile money is
 * available: the connector config for the store's API key must
 * report features.mobile_money = enabled AND connected (business
 * registered in Zambia, mobile-money toggle on, and a mobile-money
 * connection saved for the key's environment). The gateway reads
 * that config from the API — there is no plugin setting that can
 * fake availability.
 *
 * Checkout flow:
 *  1. The buyer enters their mobile money number (and optionally
 *     picks MTN / Airtel / Zamtel — otherwise the network is
 *     detected from the number) and places the order.
 *  2. process_payment() starts ONE collection through the developer
 *     API on the merchant's own connected mobile-money account,
 *     with the order's own reference ("woo-order-<id>") as the
 *     idempotency key, and parks the order on-hold: the buyer
 *     approves the prompt on their phone.
 *  3. The order completes the moment the collection settles: the
 *     signed mobile_money.collection.completed webhook completes it
 *     immediately, and the reconciler (WP-Cron, thank-you page,
 *     order action) polls the collection status as the fallback —
 *     both paths complete exactly once.
 *
 * The API key is shared with the main BMaGlass Pay gateway (its
 * settings hold the key and the webhook secret); this gateway adds
 * only its own title/description/enabled settings.
 *
 * @package BMaGlassPay
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_BMaGlass_Mobile_Money extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'bmaglass_momo';
        $this->method_title = 'BMaGlass Pay — Mobile Money';
        $this->method_description = 'Accept mobile money (MTN, Airtel, Zamtel) into your BMaGlass Pay ZMW wallet. '
            . 'Uses the API key from the main BMaGlass Pay gateway settings and the mobile-money connection on '
            . 'your BMaGlass account. Shown at checkout only when your BMaGlass account reports mobile money '
            . 'available for the key\'s environment.';
        $this->has_fields = true;
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
                'label' => 'Enable Mobile Money (BMaGlass Pay)',
                'default' => 'yes',
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Shown to the buyer at checkout.',
                'default' => 'Mobile money',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Shown to the buyer at checkout.',
                'default' => 'Pay with mobile money — MTN, Airtel or Zamtel. We\'ll send a payment prompt to '
                    . 'your phone; approve it with your PIN and your order is confirmed.',
            ),
        );
    }

    /** The shared client (main gateway's API key), or null. */
    public function client()
    {
        return bmaglass_client_from_settings();
    }

    /**
     * Whether the merchant's BMaGlass account reports mobile money
     * available for this key's environment (enabled + connected).
     * Cached for 5 minutes — availability is a fact about the
     * merchant's account, read from the connector config, never a
     * plugin setting.
     */
    public function feature_available()
    {
        $client = $this->client();
        if ($client === null) {
            return false;
        }
        $settings = bmaglass_settings();
        $key = isset($settings['api_key']) ? (string) $settings['api_key'] : '';
        $cacheKey = 'bmaglass_momo_avail_' . substr(md5($key), 0, 12);
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached === 'yes';
        }
        try {
            $config = $client->connectorConfig();
            $mm = isset($config['features']['mobile_money']) && is_array($config['features']['mobile_money'])
                ? $config['features']['mobile_money'] : array();
            $ok = (isset($mm['status']) && $mm['status'] === 'enabled') && !empty($mm['connected']);
        } catch (BMaGlassApiException $e) {
            $ok = false;
        }
        set_transient($cacheKey, $ok ? 'yes' : 'no', 5 * MINUTE_IN_SECONDS);
        return $ok;
    }

    public function is_available()
    {
        if (!parent::is_available()) {
            return false;
        }
        // Mobile money is ZMW-only: never offer it on a store whose
        // currency it cannot settle in.
        if (get_woocommerce_currency() !== 'ZMW') {
            return false;
        }
        return $this->feature_available();
    }

    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        ?>
        <fieldset id="wc-<?php echo esc_attr($this->id); ?>-form" class="wc-payment-form" style="background:transparent;">
            <p class="form-row form-row-wide">
                <label for="bmaglass_momo_phone"><?php esc_html_e('Mobile money number', 'bmaglass-pay'); ?> <span class="required">*</span></label>
                <input id="bmaglass_momo_phone" name="bmaglass_momo_phone" type="tel" autocomplete="tel"
                    placeholder="0977 123 456" />
            </p>
            <p class="form-row form-row-wide">
                <label for="bmaglass_momo_operator"><?php esc_html_e('Network', 'bmaglass-pay'); ?></label>
                <select id="bmaglass_momo_operator" name="bmaglass_momo_operator">
                    <option value=""><?php esc_html_e('Detect from my number', 'bmaglass-pay'); ?></option>
                    <option value="mtn">MTN</option>
                    <option value="airtel">Airtel</option>
                    <option value="zamtel">Zamtel</option>
                </select>
            </p>
        </fieldset>
        <?php
    }

    public function validate_fields()
    {
        $phone = isset($_POST['bmaglass_momo_phone']) ? sanitize_text_field(wp_unslash($_POST['bmaglass_momo_phone'])) : '';
        $digits = preg_replace('/\D+/', '', $phone);
        if ($phone === '' || strlen($digits) < 9) {
            wc_add_notice('Enter the mobile money number to charge — for example 0977 123 456.', 'error');
            return false;
        }
        return true;
    }

    /** The order's own collection reference (doubles as the API idempotency key). */
    public static function merchant_reference($order_id)
    {
        return 'woo-order-' . $order_id;
    }

    /**
     * Start the collection for the order and park it on-hold until
     * the buyer approves the prompt on their phone.
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array('result' => 'failure');
        }
        $client = $this->client();
        if ($client === null) {
            wc_add_notice('Mobile money isn\'t configured on this store yet. Please choose another payment method.', 'error');
            return array('result' => 'failure');
        }
        if (strtoupper($order->get_currency()) !== 'ZMW') {
            wc_add_notice('Mobile money payments are in Zambian kwacha (ZMW). Please choose another payment method for this order.', 'error');
            return array('result' => 'failure');
        }
        // Honest sandbox refusal, mirroring the main gateway: a
        // sandbox key runs on simulated money and can never charge a
        // real phone, so real checkout refuses up front.
        if (!$client->isLive()) {
            wc_add_notice(
                'This store\'s BMaGlass key is a sandbox key, which can\'t take real payments. The store owner needs to '
                . 'switch to a production BMaGlass key before checkout can run.',
                'error'
            );
            return array('result' => 'failure');
        }

        $phone = isset($_POST['bmaglass_momo_phone']) ? sanitize_text_field(wp_unslash($_POST['bmaglass_momo_phone'])) : '';
        $operator = isset($_POST['bmaglass_momo_operator']) ? sanitize_text_field(wp_unslash($_POST['bmaglass_momo_operator'])) : '';
        if (!in_array($operator, array('mtn', 'airtel', 'zamtel'), true)) {
            $operator = null;
        }

        // Retry / double-click guard: a collection already started
        // for this order is never started twice — the buyer just
        // goes back to the thank-you page to approve it.
        if ((string) $order->get_meta('_bmaglass_momo_reference') !== '') {
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        $total = round((float) $order->get_total(), 2);
        try {
            $collection = $client->createMobileMoneyCollection(
                $total,
                $phone,
                $operator,
                self::merchant_reference($order_id)
            );
        } catch (BMaGlassApiException $e) {
            wc_add_notice($e->userMessage(), 'error');
            $order->add_order_note('BMaGlass mobile money: collection start failed — ' . $e->getMessage());
            return array('result' => 'failure');
        }

        $reference = isset($collection['reference']) ? (string) $collection['reference'] : '';
        $order->update_meta_data('_bmaglass_momo_reference', $reference);
        $order->update_meta_data('_bmaglass_momo_merchant_reference', self::merchant_reference($order_id));
        $order->update_meta_data('_bmaglass_momo_phone', $phone);

        if (isset($collection['status']) && $collection['status'] === 'successful') {
            // Settled synchronously (rare): complete straight away,
            // under the reconciler's exactly-once guard.
            $order->update_meta_data('_bmaglass_settled', 'yes');
            $order->save();
            $order->payment_complete($reference);
            $order->add_order_note(sprintf(
                'Mobile money payment confirmed — %s ZMW, reference %s.',
                number_format($total, 2),
                $reference
            ));
            $order->save();
        } else {
            $order->update_status('on-hold', sprintf(
                'Mobile money: payment prompt sent to %s — waiting for the buyer to approve it (reference %s).',
                $phone,
                $reference
            ));
            $order->save();
            wc_add_notice(
                sprintf('We\'ve sent a payment prompt to %s. Approve it with your mobile money PIN — your order confirms automatically once the payment lands.', $phone),
                'success'
            );
        }

        WC()->cart->empty_cart();

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }
}
