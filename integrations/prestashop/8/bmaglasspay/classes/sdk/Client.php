<?php

namespace BMaGlass\Sdk;

// Standalone use (no Composer autoloader): the client throws ApiException
// on every error path, so load it here. require_once is a no-op when
// Composer already autoloaded the class.
require_once __DIR__ . '/ApiException.php';

/**
 * BMaGlass Pay Developer API client (PHP).
 *
 * Lifted unchanged in behaviour from the WooCommerce plugin's client
 * (integrations/wordpress) — the same surface the TypeScript and
 * Python SDKs wrap.
 *
 * Auth: an API key created in the BMaGlass Pay app, sent in the
 * X-Api-Key header. Keys carry their environment: bmg_live_ (legacy
 * bmp_live_) keys move real money; bmg_sandbox_ (legacy bmp_test_)
 * keys run on mainnet formats with simulated money — sandbox invoices
 * and payment links settle only through the simulate-payment
 * endpoints. Real-money write attempts with a sandbox key fail with
 * a typed 403 `test_mode_read_only` (see ApiException::userMessage()).
 *
 * This package lives in the BMaGlass Pay repository (sdk/php). It is
 * not published on Packagist — install it from the repo (see README).
 */
class Client
{
    const DEFAULT_BASE = 'https://hhxwwozpvgktuqutfkhc.supabase.co/functions/v1/developer-api';
    const SITE_BASE = 'https://bmaglasspay.online';
    /** Currencies that have wallets. Anything else is refused — never converted silently. */
    const SUPPORTED_CURRENCIES = array('ZMW', 'USD');

    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    private $transport;
    /** @var int Retries after a transient failure (default 2 = 3 attempts total). */
    private $maxRetries;
    /** @var int Base backoff in milliseconds (default 500). */
    private $retryBaseDelayMs;
    /** @var callable|null fn(int $ms): void — sleep override (tests). */
    private $sleepFn;

    /**
     * @param string        $apiKey    bmg_live_..., bmg_sandbox_... or legacy bmp_ key.
     * @param string|null   $baseUrl   API base override (tests).
     * @param callable|null $transport HTTP transport override (tests).
     * @param array         $options   Retry options: 'max_retries' (default 2 — 3
     *                                 attempts total; 0 disables retries),
     *                                 'retry_base_delay_ms' (default 500),
     *                                 'sleep' (callable fn(int $ms): void, tests).
     *                                 Only idempotent requests are ever
     *                                 retried: every GET/DELETE, and POSTs
     *                                 that carry an Idempotency-Key
     *                                 (payment-link / invoice / transfer
     *                                 creates — the SDK sends one per
     *                                 logical call — and mobile-money
     *                                 collections, keyed by reference /
     *                                 idempotency_key). A POST with no
     *                                 idempotency key (bitcoin/quote,
     *                                 simulate-payment, webhook
     *                                 create/test) is never retried: the
     *                                 server could not tell a retry from
     *                                 a second call. Retried failures are
     *                                 HTTP 502/503/504 and transport
     *                                 failures (including timeouts).
     *                                 Backoff is exponential with full
     *                                 jitter: before retry N the client
     *                                 sleeps a uniform random delay in
     *                                 [0, min(4000, base * 2^(N-1))] ms.
     */
    public function __construct($apiKey, $baseUrl = null, $transport = null, $options = array())
    {
        if (!is_string($apiKey) || (strpos($apiKey, 'bmg_') !== 0 && strpos($apiKey, 'bmp_') !== 0)) {
            throw new ApiException(0, 'invalid_api_key',
                'api_key must be a BMaGlass Pay API key (bmg_live_..., bmg_sandbox_..., or a legacy bmp_ key).');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ? $baseUrl : self::DEFAULT_BASE, '/');
        $this->transport = $transport;
        $this->maxRetries = isset($options['max_retries']) ? (int) $options['max_retries'] : 2;
        $this->retryBaseDelayMs = isset($options['retry_base_delay_ms']) ? (int) $options['retry_base_delay_ms'] : 500;
        $this->sleepFn = isset($options['sleep']) && is_callable($options['sleep']) ? $options['sleep'] : null;
    }

    /** One fresh idempotency key per logical create. The same key is
     *  reused across that call's retries, so the server replays the
     *  original object instead of creating a second one. */
    public static function newIdempotencyKey()
    {
        return bin2hex(random_bytes(16));
    }

    /** 'live' or 'test', from the key prefix — same rule as the other SDKs. */
    /** Legacy mode name, from the key alone — same rule as the other SDKs. */
    public function mode()
    {
        return strpos($this->apiKey, '_live_') !== false ? 'live' : 'test';
    }

    /** 'production' or 'sandbox', derived ONLY from the key — never from a setting. */
    public function environment()
    {
        return $this->mode() === 'live' ? 'production' : 'sandbox';
    }

    public function isLive()
    {
        return $this->mode() === 'live';
    }

    /** Hosted payment page URL for a link code. */
    public static function paymentLinkUrl($linkCode)
    {
        return self::SITE_BASE . '/pay/' . rawurlencode((string) $linkCode);
    }

    /**
     * Guard: only currencies with wallets may be used. Throws a
     * crafted unsupported_currency error — the client never invents
     * an exchange rate.
     */
    public static function assertSupportedCurrency($currency)
    {
        $c = strtoupper((string) $currency);
        if (!in_array($c, self::SUPPORTED_CURRENCIES, true)) {
            throw new ApiException(0, 'unsupported_currency',
                'BMaGlass Pay wallets are ZMW and USD. This store is set to ' . $c
                . ', which has no BMaGlass wallet, so BMaGlass checkout can\'t run — '
                . 'no exchange rate has been applied. Set the store currency to ZMW or USD to use BMaGlass Pay.');
        }
        return $c;
    }

    // ── Endpoints ────────────────────────────────────────────────

    /**
     * GET /connector/config — everything a connector renders from:
     * this key's environment, the business's country/currency/status,
     * feature states (mobile_money is enabled only for businesses
     * registered in Zambia with the feature switched on) and the
     * settlement type. Authenticated by the key alone.
     */
    public function connectorConfig()
    {
        return $this->request('GET', 'connector/config');
    }

    /** GET /invoices — invoices in the key's environment only. */
    public function listInvoices()
    {
        return $this->request('GET', 'invoices');
    }

    /** GET /invoices/{id} (404 across environments, by design). */
    public function getInvoice($invoiceId)
    {
        return $this->request('GET', 'invoices/' . rawurlencode((string) $invoiceId));
    }

    /** POST /invoices — create an invoice in the key's environment.
     *  Invoices are NOT currency-guarded: the API accepts any 3-letter
     *  invoice currency (a payment request, not a wallet move), so
     *  refusing EUR/GBP here would block valid API calls. Retried
     *  safely: one Idempotency-Key per logical call (caller-supplied,
     *  or auto-generated) is reused across retries, and the server
     *  replays the original invoice instead of creating a second one. */
    public function createInvoice($amount, $currency = 'ZMW', $description = null, $customerEmail = null, $metadata = null, $idempotencyKey = null)
    {
        $body = array('amount' => $amount, 'currency' => $currency);
        if ($description !== null) $body['description'] = $description;
        if ($customerEmail !== null) $body['customer_email'] = $customerEmail;
        if ($metadata !== null) $body['metadata'] = $metadata;
        return $this->request('POST', 'invoices', $body,
            array('Idempotency-Key' => $idempotencyKey !== null ? $idempotencyKey : self::newIdempotencyKey()));
    }

    /**
     * POST /invoices/{id}/simulate-payment — sandbox only: drive a
     * sandbox invoice to paid (fires invoice.paid, flagged simulated).
     */
    public function simulateInvoicePayment($invoiceId)
    {
        return $this->request('POST', 'invoices/' . rawurlencode((string) $invoiceId) . '/simulate-payment');
    }

    /**
     * POST /payment-links/{id}/simulate-payment — sandbox only: mark
     * one simulated payment on a sandbox link (fires payment_link.paid,
     * flagged simulated).
     */
    public function simulatePaymentLinkPayment($linkId)
    {
        return $this->request('POST', 'payment-links/' . rawurlencode((string) $linkId) . '/simulate-payment');
    }

    /** GET /wallets — the key owner's wallets with balances. */
    public function wallets()
    {
        return $this->request('GET', 'wallets');
    }

    /** GET /transactions?limit= */
    public function transactions($limit = 25)
    {
        $limit = max(1, min(100, (int) $limit));
        return $this->request('GET', 'transactions?limit=' . $limit);
    }

    /**
     * POST /transfers — wallet-to-wallet transfer (production keys only;
     * 1.5% fee charged on top by the API, exactly like the app).
     * Retried safely: one Idempotency-Key per logical call
     * (caller-supplied, or auto-generated) is reused across retries,
     * and the server replays the original transfer.
     */
    public function createTransfer($toUserId, $amount, $currency = 'ZMW', $description = null, $recipientName = null, $idempotencyKey = null)
    {
        $body = array(
            'to_user_id' => $toUserId,
            'amount' => $amount,
            'currency' => self::assertSupportedCurrency($currency),
        );
        if ($description !== null) $body['description'] = $description;
        if ($recipientName !== null) $body['recipient_name'] = $recipientName;
        $headers = array('Idempotency-Key' => $idempotencyKey !== null ? $idempotencyKey : self::newIdempotencyKey());
        return $this->request('POST', 'transfers', $body, $headers);
    }

    /** GET /payment-links */
    public function listPaymentLinks()
    {
        return $this->request('GET', 'payment-links');
    }

    /**
     * GET /payment-links/{id} — one link by UUID id or link_code.
     * The list endpoint caps at 100 (newest first); this read is how
     * a shop reconciler verifies an older link. Throws on 404 when
     * the link does not belong to this key's owner/environment.
     */
    public function getPaymentLink($idOrCode)
    {
        return $this->request('GET', 'payment-links/' . rawurlencode((string) $idOrCode));
    }

    /**
     * POST /payment-links — create a hosted payment link in the
     * key's environment (sandbox links settle only by simulation).
     * Returns the row including link_code; the buyer-facing
     * URL is self::paymentLinkUrl($row['link_code']).
     * $feePayer ('seller'|'buyer') overrides the merchant default
     * from settings() for this link only: 'seller' — the fee comes
     * out of what the seller receives; 'buyer' — the fee is added on
     * top and the seller receives the full price. $redirectUrl is the
     * buyer return URL the hosted pay page offers after payment.
     * The returned row carries the resolved fee_payer plus
     * fee_amount / buyer_total / seller_receives. Retried safely:
     * one Idempotency-Key per logical call (caller-supplied, or
     * auto-generated) is reused across retries, and the server
     * replays the original link instead of creating a second one.
     */
    public function createPaymentLink($title, $amount = null, $currency = 'ZMW', $description = null, $expiresAt = null, $feePayer = null, $redirectUrl = null, $idempotencyKey = null)
    {
        $body = array('title' => (string) $title, 'currency' => self::assertSupportedCurrency($currency));
        if ($amount !== null) $body['amount'] = round((float) $amount, 2);
        if ($description !== null) $body['description'] = $description;
        if ($expiresAt !== null) $body['expires_at'] = $expiresAt;
        if ($feePayer !== null) {
            if ($feePayer !== 'seller' && $feePayer !== 'buyer') {
                throw new ApiException(0, 'invalid_fee_payer', "fee_payer must be 'seller' or 'buyer'.");
            }
            $body['fee_payer'] = $feePayer;
        }
        if ($redirectUrl !== null) $body['redirect_url'] = (string) $redirectUrl;
        return $this->request('POST', 'payment-links', $body,
            array('Idempotency-Key' => $idempotencyKey !== null ? $idempotencyKey : self::newIdempotencyKey()));
    }

    /** GET /payment-links/{id}/attempts — a link's Bitcoin payment attempts. */
    public function paymentLinkAttempts($linkId)
    {
        return $this->request('GET', 'payment-links/' . rawurlencode((string) $linkId) . '/attempts');
    }

    /**
     * POST /mobile-money/collections — start a mobile money
     * collection on the business's own connected mobile money
     * account (Zambia, ZMW): the customer approves the prompt on
     * their phone (MTN, Airtel or Zamtel). The key's environment
     * selects which connection is used — a sandbox key runs against
     * the sandbox connection (gateway test numbers; no real phone is
     * ever charged), a production key against the production
     * connection. $reference is your own reference for the collection
     * and doubles as the idempotency key: repeating a create with the
     * same reference returns the original collection instead of
     * charging the customer twice. Gate errors: 400
     * business_not_set_up / not_configured, 403 not_entitled /
     * business_inactive. Requires the mobile_money:create scope.
     * Retried safely: the effective key is the caller's
     * idempotency_key, else their reference (which doubles as the key
     * server-side), else a fresh key sent as idempotency_key — the
     * same key rides every retry of this logical call.
     */
    public function createMobileMoneyCollection($amount, $phone, $operator = null, $reference = null, $idempotencyKey = null)
    {
        $body = array('amount' => round((float) $amount, 2), 'phone' => (string) $phone);
        if ($operator !== null) $body['operator'] = $operator;
        if ($reference !== null) $body['reference'] = $reference;
        if ($idempotencyKey !== null) $body['idempotency_key'] = $idempotencyKey;
        $key = $idempotencyKey !== null ? $idempotencyKey : ($reference !== null ? $reference : self::newIdempotencyKey());
        if ($idempotencyKey === null && $reference === null) $body['idempotency_key'] = $key;
        return $this->request('POST', 'mobile-money/collections', $body, array('Idempotency-Key' => $key));
    }

    /**
     * GET /mobile-money/collections/{reference} — a collection's
     * current state, by platform reference (BMG-...) or your own
     * reference. Polling refreshes from the rail: a settled
     * collection credits the business's ZMW wallet in full and fires
     * mobile_money.collection.completed. Requires the
     * mobile_money:read scope.
     */
    public function getMobileMoneyCollection($reference)
    {
        return $this->request('GET', 'mobile-money/collections/' . rawurlencode((string) $reference));
    }

    /**
     * GET /settings — the merchant's fee-bearer default
     * (fee_payer_default) and the platform's current fee rates
     * (platform_fee_percent, paypal_platform_fee_percent). Also
     * returns the merchant's mobile-money fee-bearer
     * (momo_fee_bearer: who funds the 1% momo service charge).
     * Read-only; available to any valid key. The defaults
     * themselves are set in the BMaGlass Pay dashboard — clients
     * inherit them, they do not set them.
     */
    public function settings()
    {
        return $this->request('GET', 'settings');
    }

    /** GET /bitcoin/balance */
    public function bitcoinBalance()
    {
        return $this->request('GET', 'bitcoin/balance');
    }

    /** GET /bitcoin/deposit-address (404 no_deposit_address if none issued). */
    public function bitcoinDepositAddress()
    {
        return $this->request('GET', 'bitcoin/deposit-address');
    }

    /** POST /bitcoin/quote — quote only; execution stays in the app.
     *  `side` must be "buy" or "sell": a typo here used to be silently
     *  treated as a buy quote, which flips the money direction. Not
     *  retried: each call mints a quote row (no idempotency key), so a
     *  retry after a 503 would mint a second quote. */
    public function bitcoinQuote($side, $amountZmw = null, $amountSats = null)
    {
        if ($side !== 'buy' && $side !== 'sell') {
            throw new ApiException(0, 'invalid_side', "side must be 'buy' or 'sell'.");
        }
        $body = array('side' => $side);
        if ($amountZmw !== null) $body['amount_zmw'] = $amountZmw;
        if ($amountSats !== null) $body['amount_sats'] = $amountSats;
        return $this->request('POST', 'bitcoin/quote', $body);
    }

    /** GET /webhooks (secrets are never returned). */
    public function listWebhooks()
    {
        return $this->request('GET', 'webhooks');
    }

    /**
     * POST /webhooks — register an endpoint. The response includes
     * the signing secret EXACTLY ONCE; store it immediately.
     */
    public function createWebhook($url, $events = null)
    {
        $body = array('url' => $url);
        if ($events !== null) $body['events'] = array_values($events);
        return $this->request('POST', 'webhooks', $body);
    }

    /** DELETE /webhooks/{id} */
    public function deleteWebhook($webhookId)
    {
        return $this->request('DELETE', 'webhooks/' . rawurlencode((string) $webhookId));
    }

    /** POST /webhooks/{id}/test — send a signed webhook.test event. */
    public function testWebhook($webhookId)
    {
        return $this->request('POST', 'webhooks/' . rawurlencode((string) $webhookId) . '/test');
    }

    /** GET /webhook-deliveries */
    public function webhookDeliveries()
    {
        return $this->request('GET', 'webhook-deliveries');
    }

    // ── Webhook verification ─────────────────────────────────────

    /**
     * Verify an X-BMaGlass-Signature header ("sha256=<hex>") over
     * the exact raw request body. Same scheme as the TS/Python SDKs.
     */
    public static function verifyWebhookSignature($rawBody, $signatureHeader, $secret)
    {
        if (!is_string($signatureHeader) || strpos($signatureHeader, 'sha256=') !== 0 || !$secret) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', (string) $rawBody, (string) $secret);
        return hash_equals($expected, $signatureHeader);
    }

    /** Verify + decode a webhook payload; null when invalid. */
    public static function parseWebhookEvent($rawBody, $signatureHeader, $secret)
    {
        if (!self::verifyWebhookSignature($rawBody, $signatureHeader, $secret)) {
            return null;
        }
        $decoded = json_decode((string) $rawBody, true);
        return is_array($decoded) ? $decoded : null;
    }

    // ── Transport ────────────────────────────────────────────────

    /** Backoff before retry $attempt (1-based): uniform in [0, cap]
     *  ms, cap doubling from retryBaseDelayMs up to 4000 ms. */
    private function retryDelayMs($attempt)
    {
        $cap = min(4000, $this->retryBaseDelayMs * (int) pow(2, $attempt - 1));
        return random_int(0, $cap);
    }

    private function sleepMs($ms)
    {
        if ($this->sleepFn !== null) {
            call_user_func($this->sleepFn, $ms);
        } else {
            usleep($ms * 1000);
        }
    }

    private function request($method, $path, $body = null, $headers = array())
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        // Normalise headers to "Name: value" strings — callers may
        // pass either full strings or name => value pairs.
        $allHeaders = array('X-Api-Key: ' . $this->apiKey, 'Content-Type: application/json');
        foreach ($headers as $name => $value) {
            $allHeaders[] = is_int($name) ? (string) $value : ($name . ': ' . $value);
        }
        $rawBody = $body !== null ? json_encode($body) : null;

        // Retryable iff the request is provably safe to repeat: a
        // read, a delete, or a write carrying an Idempotency-Key (the
        // server replays the original object for a repeated key).
        // Anything else gets exactly one attempt — a blind retry
        // could double-settle.
        $retryable = $method === 'GET' || $method === 'DELETE';
        if (!$retryable) {
            foreach ($allHeaders as $h) {
                if (strpos($h, 'Idempotency-Key: ') === 0) { $retryable = true; break; }
            }
        }
        $maxAttempts = $retryable ? max(1, $this->maxRetries + 1) : 1;
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->requestOnce($method, $url, $allHeaders, $rawBody);
            } catch (ApiException $e) {
                $transient = $e->errorCode === 'network_error'
                    || in_array($e->status, array(502, 503, 504), true);
                if (!$transient || $attempt >= $maxAttempts) throw $e;
                $this->sleepMs($this->retryDelayMs($attempt));
            }
        }
    }

    private function requestOnce($method, $url, $allHeaders, $rawBody)
    {
        if ($this->transport !== null) {
            list($status, $text) = call_user_func($this->transport, $method, $url, $allHeaders, $rawBody);
        } else {
            list($status, $text) = $this->curlRequest($method, $url, $allHeaders, $rawBody);
        }

        $parsed = $text !== '' && $text !== null ? json_decode($text, true) : array();
        if (!is_array($parsed)) $parsed = array();
        if ($status < 200 || $status >= 300) {
            $code = isset($parsed['error']) ? $parsed['error'] : 'request_failed';
            $msg = isset($parsed['message']) ? $parsed['message'] : ('Request failed with HTTP ' . $status);
            throw new ApiException($status, $code, $msg);
        }
        return array_key_exists('data', $parsed) ? $parsed['data'] : $parsed;
    }

    private function curlRequest($method, $url, $headers, $rawBody)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($rawBody !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        $text = curl_exec($ch);
        if ($text === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ApiException(0, 'network_error',
                'Couldn\'t reach BMaGlass Pay. Check the connection and try again. (' . $err . ')');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return array($status, $text);
    }
}
