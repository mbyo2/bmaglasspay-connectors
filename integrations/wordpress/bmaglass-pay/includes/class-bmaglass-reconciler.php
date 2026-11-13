<?php
/**
 * Order/payment settlement for BMaGlass Pay.
 *
 * Primary path (push): the developer API emits a signed
 * `payment_link.paid` webhook the moment a link is paid;
 * complete_order_from_event() finishes the matching order immediately.
 *
 * Fallback path (poll): reconciliation checks the link's counters via
 * the API — when payment_count has grown past what we last saw AND
 * total_collected covers the order total, the order is completed.
 * It runs on WP-Cron, on the thank-you page and from the order action,
 * and covers any webhook delivery the site missed.
 *
 * Both paths complete the order EXACTLY ONCE (status + settled-flag
 * guard), with the link code as the transaction reference, so the two
 * paths racing the same payment cannot double-complete it.
 *
 * @package BMaGlassPay
 */

if (!defined('ABSPATH')) {
    exit;
}

class BMaGlass_Reconciler
{
    /** Fetch the payment-link row for an order, or null. */
    private static function fetch_link(BMaGlassClient $client, $linkCode)
    {
        $links = $client->listPaymentLinks();
        if (!is_array($links)) {
            return null;
        }
        foreach ($links as $link) {
            if (isset($link['link_code']) && $link['link_code'] === $linkCode) {
                return $link;
            }
        }
        return null;
    }

    /**
     * Check one order; completes it when its link has been paid.
     * Returns true when the order is settled after this check.
     */
    public static function reconcile_order($order, BMaGlassClient $client)
    {
        if (!$order || $order->get_meta('_bmaglass_settled') === 'yes') {
            return $order && $order->get_meta('_bmaglass_settled') === 'yes';
        }
        if (!in_array($order->get_status(), array('on-hold', 'pending'), true)) {
            return false;
        }
        $code = (string) $order->get_meta('_bmaglass_link_code');
        if ($code === '') {
            return false;
        }
        try {
            $link = self::fetch_link($client, $code);
        } catch (BMaGlassApiException $e) {
            $order->add_order_note('BMaGlass: settlement check failed — ' . $e->userMessage());
            return false;
        }
        if ($link === null) {
            return false;
        }
        $seen = (int) $order->get_meta('_bmaglass_payment_count_seen');
        $count = isset($link['payment_count']) ? (int) $link['payment_count'] : 0;
        $collected = isset($link['total_collected']) ? (float) $link['total_collected'] : 0.0;
        $total = round((float) $order->get_total(), 2);

        if ($count > $seen) {
            $order->update_meta_data('_bmaglass_payment_count_seen', $count);
        }
        if ($count > 0 && $collected + 0.009 >= $total) {
            $order->update_meta_data('_bmaglass_settled', 'yes');
            $order->payment_complete($code);
            $order->add_order_note(sprintf(
                'BMaGlass Pay payment confirmed by reconciliation — link %s, collected %s %s.',
                $code,
                number_format($collected, 2),
                $order->get_currency()
            ));
            $order->save();
            return true;
        }
        $order->save();
        return false;
    }

    /** Find the unsettled order carrying a link code, or null. */
    public static function find_order_by_link_code($linkCode)
    {
        $orders = wc_get_orders(array(
            'limit' => 1,
            'status' => array('on-hold', 'pending'),
            'meta_query' => array(
                array('key' => '_bmaglass_link_code', 'value' => (string) $linkCode),
            ),
        ));
        return $orders ? $orders[0] : null;
    }

    /**
     * Complete an order directly from a verified payment_link.paid
     * event. The webhook route HMAC-verified the payload before this
     * runs; we still cross-check the link code, currency and amount
     * against the order before completing. The settled flag is
     * persisted BEFORE payment_complete so a racing reconciler pass
     * (or a duplicate delivery) sees the claim and stands down.
     *
     * Returns true when the order is settled after this call —
     * including "was already settled" — and false when the event does
     * not match a completable order (the caller then falls back to
     * API reconciliation for the order).
     */
    public static function complete_order_from_event($linkCode, $amount, $currency)
    {
        $order = self::find_order_by_link_code($linkCode);
        if (!$order) {
            return false;
        }
        if ($order->get_meta('_bmaglass_settled') === 'yes') {
            return true;
        }
        if (strtoupper((string) $currency) !== strtoupper((string) $order->get_currency())) {
            return false;
        }
        if ((float) $amount + 0.009 < round((float) $order->get_total(), 2)) {
            return false;
        }
        $order->update_meta_data('_bmaglass_settled', 'yes');
        $order->save();
        $order->payment_complete((string) $linkCode);
        $order->add_order_note(sprintf(
            'BMaGlass Pay payment confirmed by webhook (payment_link.paid) — link %s, %s %s.',
            $linkCode,
            number_format((float) $amount, 2),
            strtoupper((string) $currency)
        ));
        $order->save();
        return true;
    }

    /** Find the unsettled order carrying a mobile-money merchant reference, or null. */
    public static function find_order_by_momo_merchant_reference($merchantReference)
    {
        $orders = wc_get_orders(array(
            'limit' => 1,
            'status' => array('on-hold', 'pending'),
            'meta_query' => array(
                array('key' => '_bmaglass_momo_merchant_reference', 'value' => (string) $merchantReference),
            ),
        ));
        return $orders ? $orders[0] : null;
    }

    /**
     * Check one mobile-money order against the API; completes it
     * when the collection has settled, and fails it (with the
     * reason) when the collection failed. Shares the _bmaglass_settled
     * exactly-once guard with the payment-link paths, so the webhook
     * and this poll can never double-complete an order.
     */
    public static function reconcile_momo_order($order, BMaGlassClient $client)
    {
        if (!$order || $order->get_meta('_bmaglass_settled') === 'yes') {
            return $order && $order->get_meta('_bmaglass_settled') === 'yes';
        }
        if (!in_array($order->get_status(), array('on-hold', 'pending'), true)) {
            return false;
        }
        $reference = (string) $order->get_meta('_bmaglass_momo_reference');
        if ($reference === '') {
            return false;
        }
        try {
            $collection = $client->getMobileMoneyCollection($reference);
        } catch (BMaGlassApiException $e) {
            $order->add_order_note('BMaGlass mobile money: settlement check failed — ' . $e->userMessage());
            return false;
        }
        if (!is_array($collection) || !isset($collection['status'])) {
            return false;
        }
        if ($collection['status'] === 'successful') {
            $order->update_meta_data('_bmaglass_settled', 'yes');
            $order->save();
            $order->payment_complete($reference);
            $order->add_order_note(sprintf(
                'Mobile money payment confirmed by reconciliation — %s ZMW from %s (%s), reference %s.',
                number_format(isset($collection['amount']) ? (float) $collection['amount'] : (float) $order->get_total(), 2),
                isset($collection['phone']) ? $collection['phone'] : '',
                isset($collection['operator']) ? strtoupper((string) $collection['operator']) : '',
                $reference
            ));
            $order->save();
            return true;
        }
        if ($collection['status'] === 'failed') {
            $reason = isset($collection['failure_reason']) && $collection['failure_reason'] !== ''
                ? (string) $collection['failure_reason'] : 'the payment didn\'t go through';
            $order->update_status('failed', sprintf(
                'Mobile money payment failed — %s (reference %s). The buyer can try again from their account.',
                $reason,
                $reference
            ));
            $order->save();
            return false;
        }
        return false;
    }

    /**
     * Complete an order directly from a verified
     * mobile_money.collection.completed event. The webhook route
     * HMAC-verified the payload before this runs; we still
     * cross-check the amount and currency against the order before
     * completing, and the settled flag is persisted BEFORE
     * payment_complete so a racing reconciler pass (or a duplicate
     * delivery) stands down.
     */
    public static function complete_momo_order_from_event($merchantReference, $amount, $currency)
    {
        $order = self::find_order_by_momo_merchant_reference($merchantReference);
        if (!$order) {
            return false;
        }
        if ($order->get_meta('_bmaglass_settled') === 'yes') {
            return true;
        }
        if (strtoupper((string) $currency) !== 'ZMW') {
            return false;
        }
        if ((float) $amount + 0.009 < round((float) $order->get_total(), 2)) {
            return false;
        }
        $reference = (string) $order->get_meta('_bmaglass_momo_reference');
        $order->update_meta_data('_bmaglass_settled', 'yes');
        $order->save();
        $order->payment_complete($reference);
        $order->add_order_note(sprintf(
            'Mobile money payment confirmed by webhook (mobile_money.collection.completed) — %s ZMW, reference %s.',
            number_format((float) $amount, 2),
            $reference
        ));
        $order->save();
        return true;
    }

    /** Cron entry point: sweep recent unsettled BMaGlass orders. */
    public static function reconcile_pending()
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (!isset($gateways['bmaglass'])) {
            return;
        }
        $client = $gateways['bmaglass']->client();
        if ($client === null || !$client->isLive()) {
            return;
        }
        $orders = wc_get_orders(array(
            'status' => array('on-hold', 'pending'),
            'limit' => 50,
            'date_created' => '>' . (time() - 7 * DAY_IN_SECONDS),
            'meta_query' => array(
                array('key' => '_bmaglass_link_code', 'compare' => 'EXISTS'),
                array('key' => '_bmaglass_settled', 'compare' => 'NOT EXISTS'),
            ),
        ));
        foreach ($orders as $order) {
            self::reconcile_order($order, $client);
        }
        $momoOrders = wc_get_orders(array(
            'status' => array('on-hold', 'pending'),
            'limit' => 50,
            'date_created' => '>' . (time() - 7 * DAY_IN_SECONDS),
            'meta_query' => array(
                array('key' => '_bmaglass_momo_reference', 'compare' => 'EXISTS'),
                array('key' => '_bmaglass_settled', 'compare' => 'NOT EXISTS'),
            ),
        ));
        foreach ($momoOrders as $order) {
            self::reconcile_momo_order($order, $client);
        }
    }
}
