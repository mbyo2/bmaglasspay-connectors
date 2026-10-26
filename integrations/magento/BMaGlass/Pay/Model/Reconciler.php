<?php

namespace BMaGlass\Pay\Model;

use BMaGlass\Pay\Model\Api\ApiException;
use BMaGlass\Pay\Model\Api\Client;
use BMaGlass\Pay\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Settlement confirmation, webhook-first with reconciliation as the
 * fallback. The developer API fires a signed payment_link.paid event
 * when a link is paid; the webhook controller then runs
 * reconcileLink() for just that link's order. The cron sweep
 * (reconcilePending) keeps polling GET /payment-links for any order
 * whose delivery was missed. Both paths confirm via the API and
 * complete EXACTLY ONCE (registerCapture + state change under the
 * order's current state check).
 */
class Reconciler
{
    private $scopeConfig;
    private $collectionFactory;
    private $orderRepository;
    private $logger;
    private $config;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        CollectionFactory $collectionFactory,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger,
        Config $config = null
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->collectionFactory = $collectionFactory;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->config = $config ?: \Magento\Framework\App\ObjectManager::getInstance()->get(Config::class);
    }

    public function client()
    {
        $key = $this->config->apiKey();
        return $key !== '' ? new Client($key) : null;
    }

    /** @return int number of orders completed in this pass */
    public function reconcilePending($limit = 50)
    {
        $client = $this->client();
        if ($client === null) {
            return 0;
        }
        // Placed redirect-gateway orders sit in payment_review (pending
        // transaction), not pending_payment — sweep both states, and let
        // the link-code join below be the real selector.
        $orders = $this->collectionFactory->create()
            ->addFieldToFilter('state', ['in' => [Order::STATE_PENDING_PAYMENT, Order::STATE_PAYMENT_REVIEW]])
            ->setPageSize($limit);
        $orders->getSelect()->joinInner(
            ['sop' => $orders->getTable('sales_order_payment')],
            'sop.parent_id = main_table.entity_id',
            []
        )->where("sop.method = 'bmaglasspay'");

        $pending = [];
        foreach ($orders as $order) {
            $code = $order->getPayment()->getAdditionalInformation('bmaglass_link_code');
            if ($code) {
                $pending[(string) $code] = $order;
            }
        }
        if (!$pending) {
            return 0;
        }
        try {
            $links = $client->listPaymentLinks();
        } catch (ApiException $e) {
            $this->logger->warning('BMaGlass reconcile: ' . $e->getMessage());
            return 0;
        }
        $completed = 0;
        foreach ((array) $links as $link) {
            $code = isset($link['link_code']) ? (string) $link['link_code'] : '';
            if (!isset($pending[$code]) || (int) ($link['payment_count'] ?? 0) < 1) {
                continue;
            }
            if ($this->completeOrder($pending[$code], $code)) {
                $completed++;
            }
        }
        return $completed;
    }

    /**
     * Targeted pass for ONE link code — the payment_link.paid webhook
     * path. Confirms the link via the API and completes its pending
     * order immediately. Returns 1 when the order was completed.
     */
    public function reconcileLink($linkCode)
    {
        $client = $this->client();
        if ($client === null || $linkCode === '') {
            return 0;
        }
        try {
            $links = $client->listPaymentLinks();
        } catch (ApiException $e) {
            $this->logger->warning('BMaGlass reconcileLink: ' . $e->getMessage());
            return 0;
        }
        $paid = false;
        foreach ((array) $links as $link) {
            if ((string) ($link['link_code'] ?? '') === (string) $linkCode
                && (int) ($link['payment_count'] ?? 0) >= 1) {
                $paid = true;
                break;
            }
        }
        if (!$paid) {
            return 0;
        }
        // Placed redirect-gateway orders sit in payment_review (pending
        // transaction), not pending_payment — sweep both states, and let
        // the link-code join below be the real selector.
        $orders = $this->collectionFactory->create()
            ->addFieldToFilter('state', ['in' => [Order::STATE_PENDING_PAYMENT, Order::STATE_PAYMENT_REVIEW]]);
        $orders->getSelect()->joinInner(
            ['sop' => $orders->getTable('sales_order_payment')],
            'sop.parent_id = main_table.entity_id',
            []
        )->where("sop.method = 'bmaglasspay'");
        foreach ($orders as $order) {
            $code = $order->getPayment()->getAdditionalInformation('bmaglass_link_code');
            if ((string) $code === (string) $linkCode) {
                return $this->completeOrder($order, (string) $linkCode) ? 1 : 0;
            }
        }
        return 0;
    }

    /** Complete one order for a paid link — exactly once (state-guarded). */
    private function completeOrder($order, $code)
    {
        // Exactly-once guard: only orders still WAITING for this payment
        // may complete. Placed redirect-gateway orders wait in
        // payment_review (pending transaction); pending_payment is the
        // other waiting state. Anything further along has already been
        // completed (or cancelled) and must not be captured twice.
        if (!in_array($order->getState(), [Order::STATE_PENDING_PAYMENT, Order::STATE_PAYMENT_REVIEW], true)) {
            return false;
        }
        // Already fully paid (e.g. captured by an earlier pass) — never
        // register a second capture.
        if ((float) $order->getGrandTotal() > 0
            && (float) $order->getTotalPaid() >= (float) $order->getGrandTotal()) {
            return false;
        }
        try {
            $payment = $order->getPayment();
            $payment->setIsTransactionPending(false);
            $payment->registerCaptureNotification((float) $order->getGrandTotal());
            // Core's capture-notification state command only advances
            // new/pending_payment orders; a payment_review order stays in
            // review until the GATEWAY moves it (that is the core
            // notification-handler contract). Settlement is confirmed, so
            // advance it — otherwise paid orders can never be fulfilled.
            if ($order->getState() === Order::STATE_PAYMENT_REVIEW
                && !$payment->getIsFraudDetected()) {
                $order->setState(Order::STATE_PROCESSING);
                $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
            }
            $order->addCommentToStatusHistory(
                'BMaGlass Pay payment confirmed (link ' . $code . ').'
            );
            $this->orderRepository->save($order);
            return true;
        } catch (\Exception $e) {
            $this->logger->warning('BMaGlass complete order ' . $order->getIncrementId() . ': ' . $e->getMessage());
            return false;
        }
    }
}
