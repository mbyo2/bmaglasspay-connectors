// Tests for the BMaGlass Flutter/Dart SDK.
//
// Run where a Dart/Flutter toolchain exists:
//   cd sdk/flutter && dart test        (or: flutter test)
//
// Uses http's MockClient (package:http/testing.dart) — no network.
// The webhook checks use the SHARED cross-SDK known-answer vector,
// identical in the TS, Python, PHP, Odoo, WooCommerce and React
// Native test suites.
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
}
