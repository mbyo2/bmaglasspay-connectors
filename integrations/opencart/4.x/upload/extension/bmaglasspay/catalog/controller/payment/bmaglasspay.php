<?php

namespace Opencart\Catalog\Controller\Extension\Bmaglasspay\Payment;

use BMaGlass\Sdk\ApiException;
use BMaGlass\Sdk\Client;

/**
 * Checkout confirm + webhook callback.
 *
 * Settlement: the developer API fires a signed payment_link.paid
 * event when a link is paid; the HMAC-verified callback then
 * reconciles that link's order immediately (GET /payment-links
 * matched to the stored per-order link_code, confirmed via the API).
 * The polling reconciliation remains as the fallback for any missed
 * delivery. Orders complete exactly once.
 */
class Bmaglasspay extends \Opencart\System\Engine\Controller
{
    /**
     * Load the vendored SDK (BMaGlass\Sdk). The SDK ships inside the
     * extension folder (extension/bmaglasspay/system/library/bmaglass/)
     * so the Extensions -> Installer path works; older manual copies
     * placed it at system/library/bmaglass/ (store root) — that
     * location is still honoured as a fallback. Either location
     * satisfies the load; a missing SDK in both places is a fatal
     * require, by design (the extension cannot work without it).
     */
    private function loadSdk(): void
    {
        $dir = '';
        if (defined('DIR_EXTENSION')
            && is_file(DIR_EXTENSION . 'bmaglasspay/system/library/bmaglass/Client.php')
        ) {
            $dir = DIR_EXTENSION . 'bmaglasspay/system/library/bmaglass/';
        }
        if ($dir === '') {
            $dir = DIR_SYSTEM . 'library/bmaglass/';
        }
        require_once $dir . 'ApiException.php';
        require_once $dir . 'Client.php';
    }

    private function apiClient(): Client
    {
        $this->loadSdk();
        return new Client((string) $this->config->get('payment_bmaglasspay_api_key'));
    }

    public function index(): string
    {
        $this->load->language('extension/bmaglasspay/payment/bmaglasspay');
        $data['text_confirm'] = $this->language->get('button_confirm');
        $data['text_loading'] = $this->language->get('text_loading');
        $data['confirm_url'] = $this->url->link('extension/bmaglasspay/payment/bmaglasspay.confirm');
        return $this->load->view('extension/bmaglasspay/payment/bmaglasspay', $data);
    }

    /** Create (once) the payment link for this order and return its URL. */
    public function confirm(): void
    {
        $this->load->language('extension/bmaglasspay/payment/bmaglasspay');
        $json = [];
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $this->load->model('checkout/order');
        $order = $orderId ? $this->model_checkout_order->getOrder($orderId) : null;
        if (!$order) {
            $json['error'] = $this->language->get('error_order');
        } else {
            try {
                // Load the SDK FIRST: the reuse path below calls
                // Client::paymentLinkUrl(), which fatals while the class
                // is unloaded — OpenCart's library autoloader cannot
                // resolve the namespaced SDK class to
                // system/library/bmaglass/Client.php, so nothing else
                // loads it for us.
                $client = $this->apiClient();
                $row = $this->db->query('SELECT `link_code` FROM `' . DB_PREFIX . 'bmaglasspay_orders` WHERE `order_id` = ' . $orderId)->row;
                if ($row) {
                    $json['redirect'] = Client::paymentLinkUrl($row['link_code']);
                } else {
                    $currency = Client::assertSupportedCurrency($order['currency_code']);
                    $link = $client->createPaymentLink(
                        'Order #' . $orderId,
                        (float) $order['total'],
                        $currency,
                        'OpenCart order ' . $orderId,
                        null,
                        null,
                        // "Return to store" on the hosted pay page after payment.
                        $this->url->link('checkout/success')
                    );
                    $this->db->query('INSERT INTO `' . DB_PREFIX . 'bmaglasspay_orders` SET `order_id` = ' . $orderId
                        . ', `link_code` = \'' . $this->db->escape($link['link_code']) . '\', `paid` = 0');
                    $json['redirect'] = Client::paymentLinkUrl($link['link_code']);
                }
            } catch (ApiException $e) {
                $json['error'] = $e->userMessage();
            } catch (\Exception $e) {
                // Lost a race with another confirm for this order
                // (double-click): the other request stored its link —
                // reuse it instead of failing the checkout with a 500.
                // (Any other failure lands here too, with no row stored.)
                try {
                    $retry = $this->db->query('SELECT `link_code` FROM `' . DB_PREFIX . 'bmaglasspay_orders` WHERE `order_id` = ' . $orderId)->row;
                } catch (\Exception $ignored) {
                    $retry = null;
                }
                if ($retry) {
                    $json['redirect'] = Client::paymentLinkUrl($retry['link_code']);
                } else {
                    $json['error'] = 'BMaGlass Pay couldn\'t start the payment. Please try again.';
                }
            }
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Webhook receiver: …?route=extension/bmaglasspay/payment/bmaglasspay.callback
     * HMAC-verified; a payment_link.paid event reconciles its own
     * order immediately, any other valid event nudges a full pass.
     */
    public function callback(): void
    {
        $raw = (string) file_get_contents('php://input');
        $signature = (string) ($this->request->server['HTTP_X_BMAGLASS_SIGNATURE'] ?? '');
        $secret = (string) $this->config->get('payment_bmaglasspay_webhook_secret');
        $this->loadSdk();
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
     * or just the one for $linkCode (the webhook path). Exactly once
     * per order. Internal only (protected): it must not be
     * reachable as a storefront route — only callback() calls it,
     * after verifying the webhook signature.
     */
    protected function reconcile(?string $linkCode = null): int
    {
        $filter = $linkCode !== null
            ? ' AND `link_code` = \'' . $this->db->escape($linkCode) . '\''
            : '';
        $rows = $this->db->query('SELECT `order_id`, `link_code` FROM `' . DB_PREFIX . 'bmaglasspay_orders` WHERE `paid` = 0' . $filter . ' ORDER BY `order_id` ASC LIMIT 50')->rows;
        if (!$rows) {
            return 0;
        }
        try {
            $links = $this->apiClient()->listPaymentLinks();
        } catch (ApiException $e) {
            return 0;
        }
        $counts = [];
        foreach ((array) $links as $link) {
            $counts[(string) $link['link_code']] = (int) $link['payment_count'];
        }
        $this->load->model('checkout/order');
        $completed = 0;
        foreach ($rows as $row) {
            if (($counts[$row['link_code']] ?? 0) < 1) {
                continue;
            }
            $this->model_checkout_order->addHistory(
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
