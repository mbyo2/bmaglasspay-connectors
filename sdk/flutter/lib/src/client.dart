import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:http/http.dart' as http;

import 'errors.dart';
import 'models.dart';

/// BMaGlass Pay developer API client.
class BMaGlass {
  static const String defaultBase =
      'https://hhxwwozpvgktuqutfkhc.supabase.co/functions/v1/developer-api';
  static const String siteBase = 'https://bmaglasspay.online';

  /// Currencies that have wallets. Anything else is refused — never
  /// converted silently.
  static const List<String> supportedCurrencies = ['ZMW', 'USD'];

  final String _apiKey;
  final String _baseUrl;
  final http.Client _http;

  /// Per-request timeout (default 30s). A request that does not settle
  /// in time fails as a BMaGlassException with code "network_error"
  /// instead of hanging forever.
  final Duration timeout;

  /// Retries after a transient failure (default 2 — 3 attempts total).
  /// Only idempotent requests are ever retried: every GET/DELETE, and
  /// POSTs that carry an Idempotency-Key (payment-link / invoice /
  /// transfer creates — the SDK sends one per logical call — and
  /// mobile-money collections, keyed by reference / idempotency_key).
  /// A POST with no idempotency key (bitcoin/quote, simulate-payment,
  /// webhook create/test) is never retried: the server could not tell
  /// a retry from a second call. Retried failures are HTTP 502/503/504
  /// and transport failures (including timeouts). Backoff is
  /// exponential with full jitter: before retry N the client sleeps a
  /// uniform random delay in [0, min(4000, retryBaseDelay * 2^(N-1))].
  /// Set [maxRetries] to 0 to disable retries entirely.
  final int maxRetries;

  /// Base backoff (default 500ms). See [maxRetries].
  final Duration retryBaseDelay;

  /// Sleep implementation (tests). Defaults to [Future.delayed].
  final Future<void> Function(Duration)? sleeper;

  /// Legacy mode name, from the key alone — same rule as the other SDKs.
  final String mode;

  /// "production" or "sandbox", derived ONLY from the key.
  final String environment;

  BMaGlass({required String apiKey, String? baseUrl, http.Client? httpClient, Duration? timeout, this.maxRetries = 2, this.retryBaseDelay = const Duration(milliseconds: 500), this.sleeper})
      : _apiKey = apiKey,
        _baseUrl = (baseUrl ?? defaultBase).replaceAll(RegExp(r'/$'), ''),
        _http = httpClient ?? http.Client(),
        timeout = timeout ?? const Duration(seconds: 30),
        mode = apiKey.contains('_live_') ? 'live' : 'test',
        environment = apiKey.contains('_live_') ? 'production' : 'sandbox' {
    if (!apiKey.startsWith('bmg_') && !apiKey.startsWith('bmp_')) {
      throw const BMaGlassException(
          0, 'invalid_api_key', 'apiKey must be a BMaGlass Pay API key (bmg_live_..., bmg_sandbox_..., or a legacy bmp_ key).');
    }
  }

  /// One fresh idempotency key per logical create. The same key is
  /// reused across that call's retries, so the server replays the
  /// original object instead of creating a second one.
  static String newIdempotencyKey() {
    final r = Random.secure();
    final bytes = List<int>.generate(16, (_) => r.nextInt(256));
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }

  bool get isLive => mode == 'live';

  /// Hosted payment page URL for a link code.
  static String paymentLinkUrl(String linkCode) =>
      '$siteBase/pay/${Uri.encodeComponent(linkCode)}';

  /// Only currencies with wallets may be used; never invent an FX rate.
  static String assertSupportedCurrency(String currency) {
    final c = currency.toUpperCase();
    if (!supportedCurrencies.contains(c)) {
      throw BMaGlassException(0, 'unsupported_currency',
          "BMaGlass Pay wallets are ZMW and USD. This is $c, which has no BMaGlass wallet — no exchange rate has been applied. Use ZMW or USD with BMaGlass Pay.");
    }
    return c;
  }

  /// Backoff before retry [attempt] (1-based): uniform in [0, cap],
  /// cap doubling from [retryBaseDelay] up to 4000 ms.
  Duration _retryDelay(int attempt) {
    var capMs = retryBaseDelay.inMilliseconds * (1 << (attempt - 1));
    if (capMs > 4000) capMs = 4000;
    if (capMs < 0) capMs = 0;
    return Duration(milliseconds: Random().nextInt(capMs + 1));
  }

  Future<void> _sleep(Duration d) async {
    if (sleeper != null) {
      await sleeper!(d);
    } else {
      await Future.delayed(d);
    }
  }

  Future<dynamic> _request(String method, String path,
      {Map<String, dynamic>? body, Map<String, String>? headers}) async {
    // Retryable iff the request is provably safe to repeat: a read, a
    // delete, or a write carrying an Idempotency-Key (the server
    // replays the original object for a repeated key). Anything else
    // gets exactly one attempt — a blind retry could double-settle.
    final retryable = method == 'GET' || method == 'DELETE' || (headers != null && headers.containsKey('Idempotency-Key'));
    final maxAttempts = retryable ? (maxRetries + 1 < 1 ? 1 : maxRetries + 1) : 1;
    var attempt = 0;
    while (true) {
      attempt++;
      try {
        return await _requestOnce(method, path, body: body, headers: headers);
      } on BMaGlassException catch (e) {
        final transient = e.code == 'network_error' || e.status == 502 || e.status == 503 || e.status == 504;
        if (!transient || attempt >= maxAttempts) rethrow;
        await _sleep(_retryDelay(attempt));
      }
    }
  }

  Future<dynamic> _requestOnce(String method, String path,
      {Map<String, dynamic>? body, Map<String, String>? headers}) async {
    final uri = Uri.parse('$_baseUrl/$path');
    final allHeaders = <String, String>{
      'X-Api-Key': _apiKey,
      'Content-Type': 'application/json',
      ...?headers,
    };
    http.Response res;
    try {
      final Future<http.Response> call;
      switch (method) {
        case 'GET':
          call = _http.get(uri, headers: allHeaders);
          break;
        case 'DELETE':
          call = _http.delete(uri, headers: allHeaders);
          break;
        case 'POST':
          call = _http.post(uri, headers: allHeaders, body: body == null ? null : jsonEncode(body));
          break;
        default:
          throw BMaGlassException(0, 'request_failed', 'Unsupported method $method');
      }
      res = await call.timeout(timeout);
    } on BMaGlassException {
      rethrow;
    } on TimeoutException {
      throw BMaGlassException(0, 'network_error',
          "BMaGlass Pay did not answer within ${timeout.inMilliseconds}ms. Check the connection and try again.");
    } catch (e) {
      throw BMaGlassException(0, 'network_error', "Couldn't reach BMaGlass Pay. Check the connection and try again. ($e)");
    }
    dynamic parsed = <String, dynamic>{};
    if (res.body.isNotEmpty) {
      try {
        parsed = jsonDecode(res.body);
      } catch (_) {
        parsed = <String, dynamic>{};
      }
    }
    if (res.statusCode < 200 || res.statusCode >= 300) {
      final map = parsed is Map<String, dynamic> ? parsed : <String, dynamic>{};
      throw BMaGlassException(
        res.statusCode,
        (map['error'] ?? 'request_failed').toString(),
        (map['message'] ?? 'Request failed with HTTP ${res.statusCode}').toString(),
      );
    }
    if (parsed is Map<String, dynamic> && parsed.containsKey('data')) return parsed['data'];
    return parsed;
  }

  /// GET /connector/config — the environment, business country/
  /// currency/status, feature states (mobile_money — Zambia) and
  /// settlement type for this key's business. Key-only auth.
  Future<Map<String, dynamic>> connectorConfig() async {
    return await _request('GET', 'connector/config') as Map<String, dynamic>;
  }

  /// GET /invoices — invoices in the key's environment only.
  Future<List<Map<String, dynamic>>> listInvoices() async {
    final data = await _request('GET', 'invoices') as List<dynamic>;
    return data.map((e) => e as Map<String, dynamic>).toList();
  }

  /// GET /invoices/{id} (404 across environments, by design).
  Future<Map<String, dynamic>> getInvoice(String invoiceId) async {
    return await _request('GET', 'invoices/${Uri.encodeComponent(invoiceId)}') as Map<String, dynamic>;
  }

  /// POST /invoices — create an invoice in the key's environment.
  /// Invoices are NOT currency-guarded: the API accepts any 3-letter
  /// invoice currency (a payment request, not a wallet move).
  /// Retried safely: one Idempotency-Key per logical call
  /// (caller-supplied, or auto-generated) is reused across retries,
  /// and the server replays the original invoice instead of creating
  /// a second one.
  Future<Map<String, dynamic>> createInvoice({
    required num amount,
    String currency = 'ZMW',
    String? description,
    String? customerEmail,
    Map<String, dynamic>? metadata,
    String? idempotencyKey,
  }) async {
    final body = <String, dynamic>{
      'amount': amount,
      'currency': currency,
    };
    if (description != null) body['description'] = description;
    if (customerEmail != null) body['customer_email'] = customerEmail;
    if (metadata != null) body['metadata'] = metadata;
    return await _request('POST', 'invoices',
        body: body, headers: {'Idempotency-Key': idempotencyKey ?? newIdempotencyKey()}) as Map<String, dynamic>;
  }

  /// POST /invoices/{id}/simulate-payment — sandbox only.
  Future<Map<String, dynamic>> simulateInvoicePayment(String invoiceId) async {
    return await _request('POST', 'invoices/${Uri.encodeComponent(invoiceId)}/simulate-payment') as Map<String, dynamic>;
  }

  /// POST /payment-links/{id}/simulate-payment — sandbox only.
  Future<Map<String, dynamic>> simulatePaymentLinkPayment(String linkId) async {
    return await _request('POST', 'payment-links/${Uri.encodeComponent(linkId)}/simulate-payment') as Map<String, dynamic>;
  }

  /// GET /wallets — the key owner's wallets with balances.
  Future<List<Wallet>> wallets() async {
    final data = await _request('GET', 'wallets') as List<dynamic>;
    return data.map((e) => Wallet.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// GET /transactions?limit=
  Future<List<Map<String, dynamic>>> transactions({int limit = 25}) async {
    final clamped = limit.clamp(1, 100);
    final data = await _request('GET', 'transactions?limit=$clamped') as List<dynamic>;
    return data.map((e) => e as Map<String, dynamic>).toList();
  }

  /// POST /transfers — wallet-to-wallet transfer (production keys only;
  /// 1.5% fee charged on top by the API, exactly like the app).
  /// Retried safely: one Idempotency-Key per logical call
  /// (caller-supplied, or auto-generated) is reused across retries,
  /// and the server replays the original transfer.
  Future<Map<String, dynamic>> createTransfer({
    required String toUserId,
    required num amount,
    String currency = 'ZMW',
    String? description,
    String? recipientName,
    String? idempotencyKey,
  }) async {
    final body = <String, dynamic>{
      'to_user_id': toUserId,
      'amount': amount,
      'currency': assertSupportedCurrency(currency),
      if (description != null) 'description': description,
      if (recipientName != null) 'recipient_name': recipientName,
    };
    final data = await _request('POST', 'transfers',
        body: body,
        headers: {'Idempotency-Key': idempotencyKey ?? newIdempotencyKey()});
    return data as Map<String, dynamic>;
  }

  /// GET /payment-links
  Future<List<PaymentLink>> listPaymentLinks() async {
    final data = await _request('GET', 'payment-links') as List<dynamic>;
    return data.map((e) => PaymentLink.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// GET /payment-links/{id} — one link by UUID id or link_code. The
  /// list caps at 100 (newest first); this read is how a shop
  /// reconciler verifies an older link.
  Future<PaymentLink> getPaymentLink(String idOrCode) async {
    final data = await _request('GET', 'payment-links/${Uri.encodeComponent(idOrCode)}') as Map<String, dynamic>;
    return PaymentLink.fromJson(data);
  }

  /// POST /payment-links — create a hosted payment link (sandbox keys
  /// create links too; only settlement is simulation-only).
  /// [feePayer] ("seller" | "buyer") overrides the merchant default
  /// from `settings()` for this link only: "seller" — the fee comes out
  /// of what the seller receives; "buyer" — the fee is added on top and
  /// the seller receives the full price. [redirectUrl] is the buyer
  /// return URL the hosted pay page offers after payment. Retried
  /// safely: one Idempotency-Key per logical call (caller-supplied, or
  /// auto-generated) is reused across retries, and the server replays
  /// the original link instead of creating a second one.
  Future<PaymentLink> createPaymentLink({
    required String title,
    num? amount,
    String currency = 'ZMW',
    String? description,
    String? expiresAt,
    String? feePayer,
    String? redirectUrl,
    String? idempotencyKey,
  }) async {
    if (feePayer != null && feePayer != 'seller' && feePayer != 'buyer') {
      throw const BMaGlassException(0, 'invalid_fee_payer', "feePayer must be 'seller' or 'buyer'.");
    }
    final body = <String, dynamic>{
      'title': title,
      'currency': assertSupportedCurrency(currency),
      if (amount != null) 'amount': amount,
      if (description != null) 'description': description,
      if (expiresAt != null) 'expires_at': expiresAt,
      if (feePayer != null) 'fee_payer': feePayer,
      if (redirectUrl != null) 'redirect_url': redirectUrl,
    };
    final data = await _request('POST', 'payment-links',
        body: body, headers: {'Idempotency-Key': idempotencyKey ?? newIdempotencyKey()}) as Map<String, dynamic>;
    return PaymentLink.fromJson(data);
  }

  /// GET /payment-links/{id}/attempts — a link's Bitcoin payment attempts.
  Future<List<Map<String, dynamic>>> paymentLinkAttempts(String linkId) async {
    final data = await _request('GET', 'payment-links/${Uri.encodeComponent(linkId)}/attempts') as List<dynamic>;
    return data.map((e) => e as Map<String, dynamic>).toList();
  }

  /// POST /mobile-money/collections — start a mobile money collection
  /// on the business's own connected mobile money account (Zambia,
  /// ZMW): the customer approves the prompt on their phone (MTN,
  /// Airtel or Zamtel). The key's environment selects which
  /// connection is used — a sandbox key runs against the sandbox
  /// connection (gateway test numbers; no real phone is ever
  /// charged), a production key against the production connection.
  /// [reference] is your own reference for the collection and doubles
  /// as the idempotency key: repeating a create with the same
  /// reference returns the original collection instead of charging
  /// the customer twice. Requires the mobile_money:create scope.
  /// Retried safely: the effective key is the caller's
  /// [idempotencyKey], else their [reference], else a fresh key sent
  /// as idempotency_key — the same key rides every retry of this
  /// logical call.
  Future<Map<String, dynamic>> createMobileMoneyCollection({
    required num amount,
    required String phone,
    String? operator,
    String? reference,
    String? idempotencyKey,
  }) async {
    final key = idempotencyKey ?? reference ?? newIdempotencyKey();
    final body = <String, dynamic>{
      'amount': amount,
      'phone': phone,
      if (operator != null) 'operator': operator,
      if (reference != null) 'reference': reference,
      if (idempotencyKey != null) 'idempotency_key': idempotencyKey,
      if (idempotencyKey == null && reference == null) 'idempotency_key': key,
    };
    return await _request('POST', 'mobile-money/collections',
        body: body, headers: {'Idempotency-Key': key}) as Map<String, dynamic>;
  }

  /// GET /mobile-money/collections/{reference} — a collection's
  /// current state, by platform reference (BMG-...) or your own
  /// reference. Polling refreshes from the rail: a settled collection
  /// credits the business's ZMW wallet in full and fires
  /// mobile_money.collection.completed. Requires the
  /// mobile_money:read scope.
  Future<Map<String, dynamic>> getMobileMoneyCollection(String reference) async {
    return await _request('GET', 'mobile-money/collections/${Uri.encodeComponent(reference)}') as Map<String, dynamic>;
  }

  /// GET /settings — the merchant's fee-bearer default
  /// (fee_payer_default) and the platform's current fee rates
  /// (platform_fee_percent, paypal_platform_fee_percent). Also
  /// returns the merchant's mobile-money fee-bearer
  /// (momo_fee_bearer: who funds the 1% momo service charge).
  /// Read-only; available to any valid key. The defaults
  /// themselves are set in the BMaGlass Pay dashboard — clients
  /// inherit them, they do not set them.
  Future<Map<String, dynamic>> settings() async {
    final data = await _request('GET', 'settings') as Map<String, dynamic>;
    return data;
  }

  /// GET /bitcoin/balance
  Future<List<Map<String, dynamic>>> bitcoinBalance() async {
    final data = await _request('GET', 'bitcoin/balance') as List<dynamic>;
    return data.map((e) => e as Map<String, dynamic>).toList();
  }

  /// GET /bitcoin/deposit-address (throws 404 no_deposit_address if none issued).
  Future<Map<String, dynamic>> bitcoinDepositAddress() async {
    final data = await _request('GET', 'bitcoin/deposit-address') as Map<String, dynamic>;
    return data;
  }

  /// POST /bitcoin/quote — quote only; execution stays in the app.
  /// [side] must be "buy" or "sell": a typo here used to be silently
  /// treated as a buy quote, which flips the money direction. Not
  /// retried: each call mints a quote row (no idempotency key), so a
  /// retry after a 503 would mint a second quote.
  Future<Map<String, dynamic>> bitcoinQuote({required String side, num? amountZmw, num? amountSats}) async {
    if (side != 'buy' && side != 'sell') {
      throw const BMaGlassException(0, 'invalid_side', "side must be 'buy' or 'sell'.");
    }
    final body = <String, dynamic>{
      'side': side,
      if (amountZmw != null) 'amount_zmw': amountZmw,
      if (amountSats != null) 'amount_sats': amountSats,
    };
    final data = await _request('POST', 'bitcoin/quote', body: body) as Map<String, dynamic>;
    return data;
  }

  /// GET /webhooks (secrets are never returned).
  Future<List<WebhookEndpoint>> listWebhooks() async {
    final data = await _request('GET', 'webhooks') as List<dynamic>;
    return data.map((e) => WebhookEndpoint.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// POST /webhooks — the response includes the signing [secret]
  /// exactly once; store it immediately.
  Future<WebhookEndpoint> createWebhook({required String url, List<String>? events}) async {
    final body = <String, dynamic>{
      'url': url,
      if (events != null) 'events': events,
    };
    final data = await _request('POST', 'webhooks', body: body) as Map<String, dynamic>;
    return WebhookEndpoint.fromJson(data);
  }

  /// DELETE /webhooks/{id}
  Future<Map<String, dynamic>> deleteWebhook(String webhookId) async {
    final data = await _request('DELETE', 'webhooks/${Uri.encodeComponent(webhookId)}') as Map<String, dynamic>;
    return data;
  }

  /// POST /webhooks/{id}/test — send a signed webhook.test event.
  Future<dynamic> testWebhook(String webhookId) =>
      _request('POST', 'webhooks/${Uri.encodeComponent(webhookId)}/test');

  /// GET /webhook-deliveries
  Future<List<Map<String, dynamic>>> webhookDeliveries() async {
    final data = await _request('GET', 'webhook-deliveries') as List<dynamic>;
    return data.map((e) => e as Map<String, dynamic>).toList();
  }
}
