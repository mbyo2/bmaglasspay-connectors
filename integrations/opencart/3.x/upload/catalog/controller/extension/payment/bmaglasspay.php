<?php

use BMaGlass\Sdk\ApiException;
use BMaGlass\Sdk\Client;

/**
 * BMaGlass Pay — OpenCart 3.x checkout confirm + webhook callback.
 *
 * Settlement: the developer API fires a signed payment_link.paid
 * event when a link is paid; the HMAC-verified callback then
 * reconciles that link's order immediately (GET /payment-links
 * matched to the stored per-order link_code, confirmed via the
 * API). The polling reconciliation remains as the fallback for any
 * missed delivery. Orders complete exactly once.
 *
 * OpenCart 3.x conventions (this is a separate build from the 4.x
 * extension, not a rename): non-namespaced
 * ControllerExtensionPayment* classes under
 * catalog/controller/extension/payment/, the getMethod() model
 * signature, and `extension/payment/...` routes.
 */
class ControllerExtensionPaymentBmaglasspay extends Controller
{
    private function apiClient()
    {
        require_once DIR_SYSTEM . 'library/bmaglass/ApiException.php';
        require_once DIR_SYSTEM . 'library/bmaglass/Client.php';
        return new Client((string) $this->config->get('payment_bmaglasspay_api_key'));
    }

    public function index()
    {
        $this->load->language('extension/payment/bmaglasspay');
        $data['text_loading'] = $this->language->get('text_loading');
        $data['button_confirm'] = $this->language->get('button_confirm');
        return $this->load->view('extension/payment/bmaglasspay', $data);
    }

    /** Create (once) the payment link for this order and return its URL as JSON. */
    public function confirm()
    {
        $this->load->language('extension/payment/bmaglasspay');
        $json = array();
        $order_id = isset($this->session->data['order_id']) ? (int) $this->session->data['order_id'] : 0;
        $this->load->model('checkout/order');
        $order = $order_id ? $this->model_checkout_order->getOrder($order_id) : null;
        if (!$order) {
            $json['error'] = $this->language->get('error_order');
        } else {
            $row = $this->db->query('SELECT `link_code` FROM `' . DB_PREFIX . 'bmaglasspay_orders` WHERE `order_id` = ' . $order_id)->row;
            if ($row) {
                $json['redirect'] = Client::paymentLinkUrl($row['link_code']);
            } else {
                try {
                    $currency = Client::assertSupportedCurrency($order['currency_code']);
                    $link = $this->apiClient()->createPaymentLink(
                        'Order #' . $order_id,
                        (float) $order['total'],
                        $currency,
                        'OpenCart order ' . $order_id
                    );
                    $this->db->query('INSERT INTO `' . DB_PREFIX . 'bmaglasspay_orders` SET `order_id` = ' . $order_id
                        . ', `link_code` = \'' . $this->db->escape($link['link_code']) . '\', `paid` = 0');
                    $json['redirect'] = Client::paymentLinkUrl($link['link_code']);
                } catch (ApiException $e) {
                    $json['error'] = $e->userMessage();
                }
            }
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Webhook receiver: index.php?route=extension/payment/bmaglasspay/callback
     * HMAC-verified; a payment_link.paid event reconciles its own
     * order immediately, any other valid event nudges a full pass.
     */
    public function callback()
    {
        $raw = (string) file_get_contents('php://input');
        $signature = isset($this->request->server['HTTP_X_BMAGLASS_SIGNATURE'])
            ? (string) $this->request->server['HTTP_X_BMAGLASS_SIGNATURE'] : '';
        $secret = (string) $this->config->get('payment_bmaglasspay_webhook_secret');
        require_once DIR_SYSTEM . 'library/bmaglass/ApiException.php';
        require_once DIR_SYSTEM . 'library/bmaglass/Client.php';
        if ($secret === '' || !Client::verifyWebhookSignature($raw, $signature, $secret)) {
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
            $this->response->setOutput('invalid signature');
            return;
        }
        $event = json_decode($raw, true);
        if (is_array($event)
            && ($event['type'] ?? '') === 'payment_link.paid'
            && !empty($event['data']['link_code'])) {
            $this->reconcile((string) $event['data']['link_code']);
        } else {
            $this->reconcile();
        }
        $this->response->setOutput('ok');
    }

    /**
     * Complete orders whose link has been paid — all pending orders,
     * or just the one for $link_code (the webhook path). Exactly
     * once per order.
     */
    public function reconcile($link_code = null)
    {
        $filter = $link_code !== null
            ? ' AND `link_code` = \'' . $this->db->escape($link_code) . '\''
            : '';
        $rows = $this->db->query('SELECT `order_id`, `link_code` FROM `' . DB_PREFIX . 'bmaglasspay_orders` WHERE `paid` = 0' . $filter . ' LIMIT 50')->rows;
        if (!$rows) {
            return 0;
        }
        try {
            $links = $this->apiClient()->listPaymentLinks();
        } catch (ApiException $e) {
            return 0;
        }
        $counts = array();
        foreach ((array) $links as $link) {
            $counts[(string) $link['link_code']] = (int) $link['payment_count'];
        }
        $this->load->model('checkout/order');
        $completed = 0;
        foreach ($rows as $row) {
            if (($counts[$row['link_code']] ?? 0) < 1) {
                continue;
            }
            $this->model_checkout_order->addOrderHistory(
                (int) $row['order_id'],
                (int) $this->config->get('payment_bmaglasspay_order_status_id'),
                'BMaGlass Pay payment confirmed (link ' . $row['link_code'] . ').',
                true
            );
            $this->db->query('UPDATE `' . DB_PREFIX . 'bmaglasspay_orders` SET `paid` = 1 WHERE `order_id` = ' . (int) $row['order_id']);
            $completed++;
        }
        return $completed;
    }
}
