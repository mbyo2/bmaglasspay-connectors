<?php

use BMaGlass\Sdk\ApiException;
use BMaGlass\Sdk\Client;

/**
 * BMaGlass Pay — OpenCart 3.x admin: settings + connection test +
 * install. Classic 3.x form POST save (the 4.x build saves via a
 * JSON endpoint instead). The API client is the PHP SDK, vendored
 * verbatim under system/library/bmaglass/ (BMaGlass\Sdk namespace).
 */
class ControllerExtensionPaymentBmaglasspay extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/payment/bmaglasspay');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_bmaglasspay', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_test'] = $this->language->get('text_test');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_api_key'] = $this->language->get('entry_api_key');
        $data['entry_webhook_secret'] = $this->language->get('entry_webhook_secret');
        $data['entry_order_status'] = $this->language->get('entry_order_status');
        $data['entry_sort_order'] = $this->language->get('entry_sort_order');
        $data['entry_webhook_url'] = $this->language->get('entry_webhook_url');
        $data['help_api_key'] = $this->language->get('help_api_key');
        $data['help_webhook_secret'] = $this->language->get('help_webhook_secret');
        $data['help_webhook_url'] = $this->language->get('help_webhook_url');
        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true),
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/bmaglasspay', 'user_token=' . $this->session->data['user_token'], true),
        );

        $data['action'] = $this->url->link('extension/payment/bmaglasspay', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
        $data['test_url'] = $this->url->link('extension/payment/bmaglasspay/test', 'user_token=' . $this->session->data['user_token'], true);
        $data['webhook_url'] = HTTP_CATALOG . 'index.php?route=extension/payment/bmaglasspay/callback';

        $fields = array('status', 'api_key', 'webhook_secret', 'order_status_id', 'sort_order');
        foreach ($fields as $field) {
            $key = 'payment_bmaglasspay_' . $field;
            if (isset($this->request->post[$key])) {
                $data[$key] = $this->request->post[$key];
            } else {
                $data[$key] = $this->config->get($key);
            }
        }
        if (!$data['payment_bmaglasspay_order_status_id']) {
            $data['payment_bmaglasspay_order_status_id'] = 2; // Processing
        }

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/bmaglasspay', $data));
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/bmaglasspay')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }

    /** Connection check: GET /wallets with the configured key (JSON). */
    public function test()
    {
        $this->load->language('extension/payment/bmaglasspay');
        $json = array();
        require_once DIR_SYSTEM . 'library/bmaglass/ApiException.php';
        require_once DIR_SYSTEM . 'library/bmaglass/Client.php';
        try {
            $client = new Client((string) $this->config->get('payment_bmaglasspay_api_key'));
            $parts = array();
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

    public function install()
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'bmaglasspay_orders` (
            `order_id` INT NOT NULL PRIMARY KEY,
            `link_code` VARCHAR(64) NOT NULL,
            `paid` TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function uninstall()
    {
        $this->db->query('DROP TABLE IF EXISTS `' . DB_PREFIX . 'bmaglasspay_orders`');
    }
}
