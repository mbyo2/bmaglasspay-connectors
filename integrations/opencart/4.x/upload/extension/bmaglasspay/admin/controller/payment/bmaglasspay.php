<?php

namespace Opencart\Admin\Controller\Extension\Bmaglasspay\Payment;

use BMaGlass\Sdk\ApiException;
use BMaGlass\Sdk\Client;

/**
 * Admin: BMaGlass Pay settings + connection test + install.
 * The API client is the PHP SDK, vendored verbatim inside the
 * extension folder (extension/bmaglasspay/system/library/bmaglass/,
 * BMaGlass\Sdk namespace); older manual copies may still have it
 * at system/library/bmaglass/ — loadSdk() honours both.
 */
class Bmaglasspay extends \Opencart\System\Engine\Controller
{
    /** Load the vendored SDK; see the catalog controller for why. */
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

    public function index(): void
    {
        $this->load->language('extension/bmaglasspay/payment/bmaglasspay');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        $data['action'] = $this->url->link('extension/bmaglasspay/payment/bmaglasspay.save', 'user_token=' . $this->session->data['user_token']);
        $data['test_action'] = $this->url->link('extension/bmaglasspay/payment/bmaglasspay.test', 'user_token=' . $this->session->data['user_token']);
        $data['webhook_url'] = HTTP_CATALOG . 'index.php?route=extension/bmaglasspay/payment/bmaglasspay.callback';
        $data['payment_bmaglasspay_status'] = $this->config->get('payment_bmaglasspay_status');
        $data['payment_bmaglasspay_api_key'] = $this->config->get('payment_bmaglasspay_api_key');
        $data['payment_bmaglasspay_webhook_secret'] = $this->config->get('payment_bmaglasspay_webhook_secret');
        $data['payment_bmaglasspay_order_status_id'] = $this->config->get('payment_bmaglasspay_order_status_id') ?: 2;
        $data['payment_bmaglasspay_sort_order'] = $this->config->get('payment_bmaglasspay_sort_order');

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/bmaglasspay/payment/bmaglasspay', $data));
    }

    public function save(): void
    {
        $this->load->language('extension/bmaglasspay/payment/bmaglasspay');
        $json = [];
        if (!$this->user->hasPermission('modify', 'extension/bmaglasspay/payment/bmaglasspay')) {
            $json['error'] = $this->language->get('error_permission');
        }
        if (!$json) {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_bmaglasspay', $this->request->post);
            $json['success'] = $this->language->get('text_success');
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /** Connection check: GET /wallets with the configured key. */
    public function test(): void
    {
        $this->load->language('extension/bmaglasspay/payment/bmaglasspay');
        $json = [];
        $this->loadSdk();
        try {
            $client = new Client((string) $this->config->get('payment_bmaglasspay_api_key'));
            $parts = [];
            foreach ((array) $client->wallets() as $w) {
                $parts[] = $w['currency'] . ' ' . number_format((float) $w['balance'], 2);
            }
            $json['success'] = $this->language->get('text_connected') . ' ' . implode(' · ', $parts)
                . (!$client->isLive() ? ' ' . $this->language->get('text_test_key_notice') : '');
        } catch (ApiException $e) {
            $json['error'] = $e->userMessage();
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function install(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'bmaglasspay_orders` (
            `order_id` INT NOT NULL PRIMARY KEY,
            `link_code` VARCHAR(64) NOT NULL,
            `paid` TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // Register the extension itself (oc_extension_install) so the
        // admin/catalog startup can resolve this extension's
        // controllers. OpenCart 4.x only creates that row via the
        // Extensions -> Installer upload (which needs install.json);
        // a manual file copy + this payment install would otherwise
        // leave the settings page at "Page Not Found!" because the
        // admin startup never registers the extension's namespaces.
        // Best-effort: a failure here must not break the install.
        try {
            $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "extension_install`
                (`extension_id`, `extension_download_id`, `name`, `description`, `code`, `version`, `author`, `link`, `status`, `date_added`)
                VALUES (0, 0, 'BMaGlass Pay', 'BMaGlass Pay — Bitcoin wallet payments for OpenCart 4.x', 'bmaglasspay', '1.0.0', 'BMaGlass Pay', 'https://bmaglasspay.online/developers', 1, NOW())");
        } catch (\Throwable $e) {
            // Registration row is best-effort; the orders table above
            // is the install's critical part.
        }
    }

    public function uninstall(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . DB_PREFIX . 'bmaglasspay_orders`');
    }
}
