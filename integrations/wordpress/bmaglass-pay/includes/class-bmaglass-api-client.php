<?php
/**
 * BMaGlass Pay Developer API client (PHP).
 *
 * A small, framework-free client for the BMaGlass Pay developer API —
 * the same surface the TypeScript and Python SDKs wrap. It has no
 * WordPress dependencies so it can be lifted out unchanged (it is the
 * seed of the standalone PHP SDK in sdk/php).
 *
 * Auth: an API key created in the BMaGlass Pay app, sent in the
 * X-Api-Key header. Keys carry their environment: bmg_live_ (legacy
 * bmp_live_) keys move real money; bmg_sandbox_ (legacy bmp_test_)
 * keys run on mainnet formats with simulated money — sandbox invoices
 * and payment links settle only through the simulate-payment
 * endpoints. Real-money write attempts with a sandbox key fail with a
 * typed 403 `test_mode_read_only`, which this client maps to a
 * plain-language message (see BMaGlassApiException::userMessage()).
 *
 * @package BMaGlassPay
 */

if (!defined('ABSPATH') && !defined('BMAGLASS_CLIENT_STANDALONE')) {
    // Allow CLI/test loading outside WordPress.
    if (php_sapi_name() !== 'cli') {
        exit;
    }
}

if (!class_exists('BMaGlassApiException')) {
    /**
     * Typed API error: HTTP status + the API's stable error code.
     */
    class BMaGlassApiException extends Exception
    {
        /** @var int HTTP status (0 for client-side validation). */
        public $status;
        /** @var string Stable API error code, e.g. test_mode_read_only. */
        public $errorCode;

        public function __construct($status, $errorCode, $message)
        {
            parent::__construct($message);
            $this->status = (int) $status;
            $this->errorCode = (string) $errorCode;
        }

        public function isTestModeReadOnly()
        {
            return $this->errorCode === 'test_mode_read_only';
        }

        /**
         * Plain-language message safe to show a merchant or buyer —
         * never a raw system response.
         */
        public function userMessage()
        {
            switch ($this->errorCode) {
                case 'test_mode_read_only':
                    return 'This BMaGlass API key is a sandbox key. Sandbox keys can check the '
                        . 'connection, read wallets and run simulated payments, but they can\'t '
                        . 'move real money. Use a production key (bmg_live_...) to accept payments.';
                case 'invalid_api_key':
                case 'api_key_revoked':
                case 'api_key_expired':
                    return 'That BMaGlass API key wasn\'t accepted. Check the key in your '
                        . 'BMaGlass Pay account (Business settings or the API page) and try again.';
                case 'insufficient_scope':
                    return 'This BMaGlass API key doesn\'t have permission for that action. '
                        . 'Create a key with the needed scopes in BMaGlass Pay and try again.';
                case 'unsupported_currency':
                    return $this->getMessage();
                default:
                    $msg = $this->getMessage();
                    return $msg !== '' ? $msg
                        : 'BMaGlass Pay couldn\'t complete that request (HTTP ' . $this->status . '). Please try again.';
            }
        }
    }
}

if (!class_exists('BMaGlassClient')) {
    class BMaGlassClient
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

        /**
         * @param string        $apiKey    bmg_live_..., bmg_sandbox_... or legacy bmp_ key.
         * @param string|null   $baseUrl   API base override (tests).
         * @param callable|null $transport HTTP transport override (tests).
         */
        public function __construct($apiKey, $baseUrl = null, $transport = null)
        {
            if (!is_string($apiKey) || (strpos($apiKey, 'bmg_') !== 0 && strpos($apiKey, 'bmp_') !== 0)) {
                throw new BMaGlassApiException(0, 'invalid_api_key',
                    'api_key must be a BMaGlass Pay API key (bmg_live_..., bmg_sandbox_..., or a legacy bmp_ key).');
            }
            $this->apiKey = $apiKey;
            $this->baseUrl = rtrim($baseUrl ? $baseUrl : self::DEFAULT_BASE, '/');
            $this->transport = $transport;
        }

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
                throw new BMaGlassApiException(0, 'unsupported_currency',
                    'BMaGlass Pay wallets are ZMW and USD. This store is set to ' . $c
                    . ', which has no BMaGlass wallet, so BMaGlass checkout can\'t run — '
                    . 'no exchange rate has been applied. Set the store currency to ZMW or USD to use BMaGlass Pay.');
            }
            return $c;
        }

        // ── Endpoints ────────────────────────────────────────────────

        /** GET /wallets — the key owner's wallets with balances. */
        /**
         * GET /connector/config — everything a connector renders from:
         * this key's environment, the business's country/currency/
         * status, feature states (mobile_money is enabled only for
         * businesses registered in Zambia with the feature switched
         * on) and the settlement type. Authenticated by the key alone.
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

        /** POST /invoices — create an invoice in the key's environment. */
        public function createInvoice($amount, $currency = 'ZMW', $description = null, $customerEmail = null)
        {
            $body = array('amount' => $amount, 'currency' => self::assertSupportedCurrency($currency));
            if ($description !== null) $body['description'] = $description;
            if ($customerEmail !== null) $body['customer_email'] = $customerEmail;
            return $this->request('POST', 'invoices', $body);
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
         * POST /payment-links/{id}/simulate-payment — sandbox only:
         * mark one simulated payment on a sandbox link (fires
         * payment_link.paid, flagged simulated).
         */
        public function simulatePaymentLinkPayment($linkId)
        {
            return $this->request('POST', 'payment-links/' . rawurlencode((string) $linkId) . '/simulate-payment');
        }

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
         * POST /transfers — wallet-to-wallet transfer (live keys only;
         * 1.5% fee charged on top by the API, exactly like the app).
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
            $headers = $idempotencyKey ? array('Idempotency-Key' => $idempotencyKey) : array();
            return $this->request('POST', 'transfers', $body, $headers);
        }

        /** GET /payment-links */
        public function listPaymentLinks()
        {
            return $this->request('GET', 'payment-links');
        }

        /**
         * POST /payment-links — create a hosted payment link (live keys
         * only). Returns the row including link_code; the buyer-facing
         * URL is self::paymentLinkUrl($row['link_code']).
         */
        public function createPaymentLink($title, $amount = null, $currency = 'ZMW', $description = null, $expiresAt = null)
        {
            $body = array('title' => (string) $title, 'currency' => self::assertSupportedCurrency($currency));
            if ($amount !== null) $body['amount'] = round((float) $amount, 2);
            if ($description !== null) $body['description'] = $description;
            if ($expiresAt !== null) $body['expires_at'] = $expiresAt;
            return $this->request('POST', 'payment-links', $body);
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
         * selects which connection is used — a sandbox key runs
         * against the sandbox connection (gateway test numbers; no
         * real phone is ever charged), a production key against the
         * production connection. $reference is your own reference for
         * the collection and doubles as the idempotency key:
         * repeating a create with the same reference returns the
         * original collection instead of charging the customer twice.
         * Gate errors: 400 business_not_set_up / not_configured, 403
         * not_entitled / business_inactive. Requires the
         * mobile_money:create scope.
         */
        public function createMobileMoneyCollection($amount, $phone, $operator = null, $reference = null)
        {
            $body = array('amount' => round((float) $amount, 2), 'phone' => (string) $phone);
            if ($operator !== null) $body['operator'] = $operator;
            if ($reference !== null) $body['reference'] = $reference;
            return $this->request('POST', 'mobile-money/collections', $body);
        }

        /**
         * GET /mobile-money/collections/{reference} — a collection's
         * current state, by platform reference (BMG-...) or your own
         * reference. Polling refreshes from the rail: a settled
         * collection credits the business's ZMW wallet in full and
         * fires mobile_money.collection.completed. Requires the
         * mobile_money:read scope.
         */
        public function getMobileMoneyCollection($reference)
        {
            return $this->request('GET', 'mobile-money/collections/' . rawurlencode((string) $reference));
        }

        /**
         * GET /settings — the merchant's fee-bearer default
         * (fee_payer_default) and the platform's current fee rates
         * (platform_fee_percent, paypal_platform_fee_percent). Read-only;
         * available to any valid key. The default itself is set in the
         * BMaGlass Pay dashboard — clients inherit it, they do not set it.
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

        /** POST /bitcoin/quote — quote only; execution stays in the app. */
        public function bitcoinQuote($side, $amountZmw = null, $amountSats = null)
        {
            $body = array('side' => $side === 'sell' ? 'sell' : 'buy');
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
                throw new BMaGlassApiException($status, $code, $msg);
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
                throw new BMaGlassApiException(0, 'network_error',
                    'Couldn\'t reach BMaGlass Pay. Check the connection and try again. (' . $err . ')');
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return array($status, $text);
        }
    }
}
