<?php

namespace Opencart\Catalog\Model\Extension\Bmaglasspay\Payment;

/**
 * Checkout availability: enabled + LIVE key + a currency that has a
 * BMaGlass wallet (ZMW/USD). A test key never unlocks checkout and
 * no exchange rate is ever invented.
 */
class Bmaglasspay extends \Opencart\System\Engine\Model
{
    public function getMethod(array $address = []): array
    {
        return $this->getMethods($address);
    }

    public function getMethods(array $address = []): array
    {
        if (!$this->config->get('payment_bmaglasspay_status')) {
            return [];
        }
        $key = (string) $this->config->get('payment_bmaglasspay_api_key');
        if (strpos($key, 'bmp_live_') !== 0) {
            return [];
        }
        $currency = strtoupper((string) $this->session->data['currency']);
        if (!in_array($currency, ['ZMW', 'USD'], true)) {
            return [];
        }
        $this->load->language('extension/bmaglasspay/payment/bmaglasspay');
        $name = $this->language->get('text_title');
        // OC 4.1 checkout contract (mirrors core's own payment models,
        // e.g. COD): ONE method array carrying an `option` map. The
        // checkout's payment_method.save explodes the buyer's selection
        // on '.' and requires payment_methods[code]['option'][subcode]
        // to exist — a method without options is listed but can never
        // be selected ("Payment method required!"). 'title' is kept
        // alongside 'name' for 4.0.0-era cores.
        return [
            'code' => 'bmaglasspay',
            'name' => $name,
            'title' => $name,
            'option' => [
                'bmaglasspay' => [
                    'code' => 'bmaglasspay.bmaglasspay',
                    'name' => $name,
                ],
            ],
            'sort_order' => (int) $this->config->get('payment_bmaglasspay_sort_order'),
        ];
    }
}
