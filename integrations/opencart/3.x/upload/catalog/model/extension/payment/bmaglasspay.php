<?php
/**
 * BMaGlass Pay — OpenCart 3.x catalog payment model.
 *
 * Checkout availability: enabled + LIVE key + a currency that has a
 * BMaGlass wallet (ZMW/USD). A test key never unlocks checkout and
 * no exchange rate is ever invented. (OpenCart 3.x model signature:
 * getMethod($address, $total) — the 4.x build uses getMethods().)
 */
class ModelExtensionPaymentBmaglasspay extends Model
{
    public function getMethod($address, $total)
    {
        if (!$this->config->get('payment_bmaglasspay_status')) {
            return array();
        }
        $key = (string) $this->config->get('payment_bmaglasspay_api_key');
        if (strpos($key, 'bmp_live_') !== 0) {
            return array();
        }
        $currency = strtoupper((string) $this->session->data['currency']);
        if (!in_array($currency, array('ZMW', 'USD'), true)) {
            return array();
        }
        $this->load->language('extension/payment/bmaglasspay');
        return array(
            'code' => 'bmaglasspay',
            'title' => $this->language->get('text_title'),
            'terms' => '',
            'sort_order' => (int) $this->config->get('payment_bmaglasspay_sort_order'),
        );
    }
}
