// Tests for the BMaGlass Flutter/Dart SDK.
//
// Run where a Dart/Flutter toolchain exists:
//   cd sdk/flutter && dart test        (or: flutter test)
//
// Uses http's MockClient (package:http/testing.dart) — no network.
// The webhook checks use the SHARED cross-SDK known-answer vector,
// identical in the TS, Python, PHP, Odoo, WooCommerce and React
// Native test suites.
import 'dart:async';
import 'dart:convert';

import 'package:bmaglass_sdk/bmaglass_sdk.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:test/test.dart';

http.Client stubClient() {
  return MockClient((req) async {
    final key = req.headers['x-api-key'] ?? '';
    if (!key.startsWith('bmp_') && !key.startsWith('bmg_')) {
      return http.Response(jsonEncode({'error': 'invalid_api_key', 'message': 'nope'}), 401);
    }
    if (req.url.path.endsWith('/settings')) {
      return http.Response(
          jsonEncode({
            'data': {'fee_payer_default': 'seller', 'platform_fee_percent': 2.5, 'paypal_platform_fee_percent': 1, 'note': 'n'},
            'mode': 'test'
          }),
          200);
    }
    if (req.url.path.endsWith('/payment-links/link1/attempts')) {
      return http.Response(
          jsonEncode({
            'data': [
              {'id': 'att1', 'status': 'waiting'}
            ],
            'mode': 'test'
          }),
          200);
    }
    if (req.url.path.endsWith('/payment-links/link1')) {
      return http.Response(
          jsonEncode({
            'data': {
              'id': 'pl1',
              'title': 'Order #8',
              'amount': 10,
              'currency': 'ZMW',
              'link_code': 'ABC123',
              'status': 'active',
              'fee_payer': 'seller',
              'fee_amount': 0.25,
              'buyer_total': 10,
              'seller_receives': 9.75,
              'payment_count': 0,
              'total_collected': 0,
              'created_at': 't'
            },
            'mode': 'test'
          }),
          200);
    }
    if (req.url.path.endsWith('/wallets')) {
      return http.Response(
          jsonEncode({
            'data': [
              {'id': 'w1', 'currency': 'ZMW', 'balance': 42, 'status': 'active'}
            ],
            'mode': 'test'
          }),
          200);
    }
    if (req.url.path.endsWith('/invoices/inv1') && req.method == 'GET') {
      return http.Response(
          jsonEncode({
            'data': {'id': 'inv1', 'amount': 250, 'currency': 'ZMW', 'status': 'open', 'environment': 'sandbox'},
            'mode': 'test'
          }),
          200);
    }
    if (req.url.path.endsWith('/invoices') && req.method == 'POST') {
      final body = jsonDecode(req.body) as Map<String, dynamic>;
      return http.Response(
          jsonEncode({
            'data': {'id': 'inv1', 'amount': body['amount'], 'currency': body['currency'] ?? 'ZMW', 'status': 'open', 'environment': 'sandbox'},
            'mode': 'test'
          }),
          201);
    }
    if (req.url.path.endsWith('/mobile-money/collections') && req.method == 'POST') {
      final body = jsonDecode(req.body) as Map<String, dynamic>;
      final amount = (body['amount'] as num?)?.toDouble() ?? 0;
      return http.Response(
          jsonEncode({
            'data': {
              'reference': 'BMG-C1',
              'merchant_reference': body['reference'],
              'collection_id': 'col1',
              'transaction_id': null,
              'amount': body['amount'],
              'base_amount': body['amount'],
              // Live shape: service_charge is 1% of base_amount in BOTH
              // modes (the seller funds it in seller mode; it is NOT 0).
              'service_charge': (amount * 0.01 * 100).round() / 100,
              'fee_bearer': 'seller',
              'currency': 'ZMW',
              'operator': body['operator'] ?? 'mtn',
              'phone': body['phone'],
              'status': 'pending',
              'failure_reason': null,
              'environment': 'sandbox',
              'livemode': false,
              'wallet_balance': null,
              'settled_at': null,
              'created_at': 't'
            },
            'mode': 'test'
          }),
          201);
    }
    if (req.url.path.endsWith('/bitcoin/quote') && req.method == 'POST') {
      final body = jsonDecode(req.body) as Map<String, dynamic>;
      return http.Response(
          jsonEncode({
            'data': {'id': 'q1', 'side': body['side']},
            'mode': 'test'
          }),
          201);
    }
    if (req.url.path.endsWith('/payment-links') && req.method == 'POST') {
      // Sandbox keys CAN create payment links (settlement is simulation-only);
      // only transfers/webhook registration are live-only. The stub echoes the
      // per-link fee_payer + redirect_url and the resolved fee maths, as live does.
      final body = jsonDecode(req.body) as Map<String, dynamic>;
      final feePayer = (body['fee_payer'] as String?) ?? 'seller';
      final amount = (body['amount'] as num?)?.toDouble() ?? 0;
      final fee = (amount * 0.025 * 100).round() / 100;
      return http.Response(
          jsonEncode({
            'data': {
              'id': 'pl1',
              'title': body['title'],
              'amount': body['amount'],
              'currency': body['currency'],
              'link_code': 'ABC123',
              'status': 'active',
              'fee_payer': feePayer,
              'redirect_url': body['redirect_url'],
              'payment_count': 0,
              'total_collected': 0,
              'fee_amount': fee,
              'buyer_total': feePayer == 'buyer' ? amount + fee : amount,
              'seller_receives': feePayer == 'seller' ? amount - fee : amount,
            },
            'mode': 'test'
          }),
          201);
    }
    if (req.url.path.endsWith('/transfers') && req.method == 'POST') {
      if (key.startsWith('bmp_test_') || key.startsWith('bmg_sandbox_')) {
        return http.Response(
            jsonEncode({'error': 'test_mode_read_only', 'message': 'Test keys are read-only'}), 403);
      }
      return http.Response(
          jsonEncode({'data': {'transaction_id': 'tx1'}, 'mode': 'live'}), 201);
    }
    return http.Response(jsonEncode({'error': 'not_found', 'message': 'no route'}), 404);
  });
}

void main() {
  group('webhook verification (shared known-answer vector)', () {
    const raw = '{"id":"evt_1","type":"webhook.test","created":"2026-10-26T00:00:00.000Z","data":{"ping":true}}';
    const sig = 'sha256=ff522c97ada5bfd4f6c09a0457823f89546896708e8e1f5bbb72d2ad59d68535';
    test('valid signature verifies', () {
      expect(verifyWebhookSignature(raw, sig, 'whsec_testsecret'), isTrue);
    });
    test('wrong secret fails', () {
      expect(verifyWebhookSignature(raw, sig, 'whsec_wrong'), isFalse);
    });
    test('parseWebhookEvent returns the event', () {
      expect(parseWebhookEvent(raw, sig, 'whsec_testsecret')?['type'], 'webhook.test');
    });
  });

  group('client', () {
    test('mode from prefix', () {
      expect(BMaGlass(apiKey: 'bmp_test_contract', httpClient: stubClient()).mode, 'test');
      expect(BMaGlass(apiKey: 'bmp_live_contract', httpClient: stubClient()).mode, 'live');
      expect(BMaGlass(apiKey: 'bmg_sandbox_contract', httpClient: stubClient()).environment, 'sandbox');
      expect(BMaGlass(apiKey: 'bmg_live_contract', httpClient: stubClient()).environment, 'production');
    });
    test('foreign key rejected', () {
      expect(() => BMaGlass(apiKey: 'sk_live_nope', httpClient: stubClient()),
          throwsA(isA<BMaGlassException>()));
    });
    test('wallets parse', () async {
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      final wallets = await client.wallets();
      expect(wallets.single.balance, 42);
      expect(wallets.single.currency, 'ZMW');
    });
    test('settings and attempts', () async {
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      final settings = await client.settings();
      expect(settings['fee_payer_default'], 'seller');
      expect(settings['platform_fee_percent'], 2.5);
      expect(settings['paypal_platform_fee_percent'], 1);
      final attempts = await client.paymentLinkAttempts('link1');
      expect(attempts.single['status'], 'waiting');
    });
    test('payment link create (live key)', () async {
      final client = BMaGlass(apiKey: 'bmp_live_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      final link = await client.createPaymentLink(title: 'Order #7', amount: 10.5, currency: 'zmw');
      expect(link.linkCode, 'ABC123');
      expect(BMaGlass.paymentLinkUrl('ABC123'), 'https://bmaglasspay.online/pay/ABC123');
    });
    test('sandbox key creates payment links (live API, proven 2026-11-14)', () async {
      // Only transfers/webhook registration are live-only; sandbox links
      // settle via simulation.
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      final link = await client.createPaymentLink(
          title: 'Order #8', amount: 10, currency: 'ZMW', feePayer: 'buyer', redirectUrl: 'https://example.invalid/return');
      expect(link.feePayer, 'buyer');
      expect(link.redirectUrl, 'https://example.invalid/return');
      expect(link.feeAmount, 0.25);
      expect(link.buyerTotal, 10.25);
      expect(link.sellerReceives, 10);
      final sellerLink = await client.createPaymentLink(title: 'Order #9', amount: 10, currency: 'ZMW');
      expect(sellerLink.feePayer, 'seller');
      expect(sellerLink.sellerReceives, 9.75);
      expect(() async => client.createPaymentLink(title: 'Bad', amount: 10, feePayer: 'nobody'),
          throwsA(predicate((e) => e is BMaGlassException && e.code == 'invalid_fee_payer')));
    });
    test('test-key transfer maps to crafted message', () async {
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      try {
        await client.createTransfer(toUserId: 'u2', amount: 5);
        fail('should have thrown');
      } on BMaGlassException catch (e) {
        expect(e.code, 'test_mode_read_only');
        expect(e.isTestModeReadOnly, isTrue);
        expect(e.userMessage(), contains('sandbox key'));
      }
    });
    test('unsupported currency refused client-side', () async {
      final client = BMaGlass(apiKey: 'bmp_live_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      expect(() async => client.createPaymentLink(title: 'Euro', amount: 5, currency: 'EUR'),
          throwsA(predicate((e) => e is BMaGlassException && e.code == 'unsupported_currency')));
    });
  });

  group('ext-hunt fixes', () {
    test('getInvoice reads one invoice', () async {
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      final inv = await client.getInvoice('inv1');
      expect(inv['status'], 'open');
      expect(inv['amount'], 250);
    });

    test('getPaymentLink reads one link by code', () async {
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      final link = await client.getPaymentLink('link1');
      expect(link.linkCode, 'ABC123');
      expect(link.paymentCount, 0);
    });

    test('payment link create accepts int amounts', () async {
      // Regression: amount was `double?`, so an int variable did not
      // compile ("The argument type 'int' can't be assigned to the
      // parameter type 'double?'"). Sibling methods all take `num`.
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      final int amount = 10;
      final link = await client.createPaymentLink(title: 'Order #10', amount: amount, currency: 'ZMW');
      expect(link.amount, 10);
      expect(link.linkCode, 'ABC123');
    });

    test('bad quote side is refused, not silently bought', () async {
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      expect(() async => client.bitcoinQuote(side: 'bogus', amountZmw: 100),
          throwsA(predicate((e) => e is BMaGlassException && e.code == 'invalid_side')));
      expect(() async => client.bitcoinQuote(side: 'selll', amountSats: 5000),
          throwsA(predicate((e) => e is BMaGlassException && e.code == 'invalid_side')));
      final q = await client.bitcoinQuote(side: 'sell', amountSats: 5000);
      expect(q['side'], 'sell');
    });

    test('hanging request times out as network_error', () async {
      // A future that never completes and sets no timer: the client's
      // timeout must settle the call on its own.
      final hanging = MockClient((req) => Completer<http.Response>().future);
      final client = BMaGlass(
          apiKey: 'bmp_test_contract',
          baseUrl: 'http://local.test',
          httpClient: hanging,
          timeout: const Duration(milliseconds: 50));
      try {
        await client.wallets();
        fail('should have thrown');
      } on BMaGlassException catch (e) {
        expect(e.code, 'network_error');
        expect(e.status, 0);
      }
    });

    test('invoices are not currency-guarded', () async {
      // The API accepts any 3-letter invoice currency; the client used
      // to refuse EUR invoices before any request was sent.
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      final inv = await client.createInvoice(amount: 10, currency: 'EUR', metadata: {'order': '42'});
      expect(inv['currency'], 'EUR');
    });

    test('mobile-money create sends idempotency key; charge is 1% of base', () async {
      final client = BMaGlass(apiKey: 'bmp_test_contract', baseUrl: 'http://local.test', httpClient: stubClient());
      final coll = await client.createMobileMoneyCollection(
          amount: 100, phone: '0961111111', reference: 'order-42', idempotencyKey: 'idem-99');
      expect(coll['merchant_reference'], 'order-42');
      // Live shape: service_charge is 1% of base_amount in BOTH modes.
      expect(coll['service_charge'], 1);
      expect(coll['fee_bearer'], 'seller');
    });
  });

  group('retries on transient 502/503/504 (CEO decision 2026-11-15)', () {
    // Policy: max 3 attempts total; only idempotent requests are
    // retried (GET/DELETE, or POSTs carrying an Idempotency-Key);
    // backoff is exponential with full jitter. Each test scripts its
    // own MockClient and counts attempts from the closure.
    test('read 503 twice then 200 succeeds on the third attempt', () async {
      var n = 0;
      final delays = <Duration>[];
      final mock = MockClient((req) async {
        n++;
        if (n <= 2) return http.Response(jsonEncode({'error': 'unavailable', 'message': 'try again'}), 503);
        return http.Response(
            jsonEncode({'data': [{'id': 'w1', 'currency': 'ZMW', 'balance': 42}], 'mode': 'test'}), 200);
      });
      final client = BMaGlass(
          apiKey: 'bmp_test_contract',
          baseUrl: 'http://local.test',
          httpClient: mock,
          retryBaseDelay: const Duration(milliseconds: 100),
          sleeper: (d) async { delays.add(d); });
      final wallets = await client.wallets();
      expect(wallets.single.balance, 42);
      expect(n, 3);
      expect(delays.length, 2);
      expect(delays[0].inMilliseconds, inInclusiveRange(0, 100));
      expect(delays[1].inMilliseconds, inInclusiveRange(0, 200));
    });

    test('keyed create reuses the same idempotency key across retries', () async {
      var n = 0;
      final keys = <String?>[];
      final mock = MockClient((req) async {
        n++;
        keys.add(req.headers['idempotency-key']);
        if (n == 1) return http.Response(jsonEncode({'error': 'unavailable', 'message': 'try again'}), 503);
        return http.Response(
            jsonEncode({
              'data': {
                'id': 'pl1', 'title': 'Retry Link', 'amount': 10, 'currency': 'ZMW',
                'link_code': 'ABC123', 'status': 'active', 'fee_payer': 'seller',
                'redirect_url': null, 'payment_count': 0, 'total_collected': 0,
                'fee_amount': 0.25, 'buyer_total': 10, 'seller_receives': 9.75,
              },
              'mode': 'test'
            }),
            201);
      });
      final client = BMaGlass(
          apiKey: 'bmp_test_contract',
          baseUrl: 'http://local.test',
          httpClient: mock,
          retryBaseDelay: Duration.zero,
          sleeper: (d) async {});
      final link = await client.createPaymentLink(title: 'Retry Link', amount: 10, currency: 'ZMW');
      expect(link.linkCode, 'ABC123');
      expect(n, 2);
      expect(keys[0], isNotNull);
      expect(keys[0], keys[1], reason: 'same key on the retry');
      // An explicit caller key is honoured verbatim, not replaced.
      var n2 = 0;
      final keys2 = <String?>[];
      final mock2 = MockClient((req) async {
        n2++;
        keys2.add(req.headers['idempotency-key']);
        if (n2 == 1) return http.Response(jsonEncode({'error': 'unavailable', 'message': 'try again'}), 503);
        return http.Response(
            jsonEncode({
              'data': {
                'id': 'pl1', 'title': 'Retry Link', 'amount': 10, 'currency': 'ZMW',
                'link_code': 'ABC123', 'status': 'active', 'fee_payer': 'seller',
                'redirect_url': null, 'payment_count': 0, 'total_collected': 0,
                'fee_amount': 0.25, 'buyer_total': 10, 'seller_receives': 9.75,
              },
              'mode': 'test'
            }),
            201);
      });
      final client2 = BMaGlass(
          apiKey: 'bmp_test_contract',
          baseUrl: 'http://local.test',
          httpClient: mock2,
          retryBaseDelay: Duration.zero,
          sleeper: (d) async {});
      await client2.createPaymentLink(title: 'Retry Link', amount: 10, idempotencyKey: 'caller-key-123');
      expect(keys2, ['caller-key-123', 'caller-key-123']);
    });

    test('a 400 is never retried', () async {
      var n = 0;
      final mock = MockClient((req) async {
        n++;
        return http.Response(jsonEncode({'error': 'invalid_request', 'message': 'bad'}), 400);
      });
      final client = BMaGlass(
          apiKey: 'bmp_test_contract',
          baseUrl: 'http://local.test',
          httpClient: mock,
          retryBaseDelay: Duration.zero,
          sleeper: (d) async {});
      try {
        await client.wallets();
        fail('should have thrown');
      } on BMaGlassException catch (e) {
        expect(e.status, 400);
        expect(n, 1);
      }
    });

    test('simulate-payment and quote are not retried (no idempotency key)', () async {
      // simulate-payment would double-count; quote would mint two rows.
      var n = 0;
      final mock = MockClient((req) async {
        n++;
        return http.Response(jsonEncode({'error': 'unavailable', 'message': 'try again'}), 503);
      });
      final client = BMaGlass(
          apiKey: 'bmp_test_contract',
          baseUrl: 'http://local.test',
          httpClient: mock,
          retryBaseDelay: Duration.zero,
          sleeper: (d) async {});
      try {
        await client.simulatePaymentLinkPayment('link1');
        fail('should have thrown');
      } on BMaGlassException catch (e) {
        expect(e.status, 503);
        expect(n, 1);
      }
      n = 0;
      try {
        await client.bitcoinQuote(side: 'buy', amountZmw: 100);
        fail('should have thrown');
      } on BMaGlassException catch (e) {
        expect(e.status, 503);
        expect(n, 1);
      }
    });

    test('maxRetries 0 means one attempt', () async {
      var n = 0;
      final mock = MockClient((req) async {
        n++;
        if (n == 1) return http.Response(jsonEncode({'error': 'unavailable', 'message': 'try again'}), 503);
        return http.Response(
            jsonEncode({'data': [{'id': 'w1', 'currency': 'ZMW', 'balance': 42}], 'mode': 'test'}), 200);
      });
      final client = BMaGlass(
          apiKey: 'bmp_test_contract',
          baseUrl: 'http://local.test',
          httpClient: mock,
          maxRetries: 0,
          retryBaseDelay: Duration.zero,
          sleeper: (d) async {});
      try {
        await client.wallets();
        fail('should have thrown');
      } on BMaGlassException catch (e) {
        expect(e.status, 503);
        expect(n, 1);
      }
    });

    test('invoice create carries an auto idempotency key', () async {
      String? key;
      final mock = MockClient((req) async {
        key = req.headers['idempotency-key'];
        return http.Response(
            jsonEncode({'data': {'id': 'inv1', 'status': 'open'}, 'mode': 'test'}), 201);
      });
      final client = BMaGlass(
          apiKey: 'bmp_test_contract',
          baseUrl: 'http://local.test',
          httpClient: mock,
          retryBaseDelay: Duration.zero,
          sleeper: (d) async {});
      await client.createInvoice(amount: 10, currency: 'ZMW');
      expect(key, isNotNull);
    });
  });
}
