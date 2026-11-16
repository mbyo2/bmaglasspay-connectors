"""Contract tests for the Python SDK using a stub transport that mirrors
the developer-api contract. Run: python3 -m unittest discover sdk/tests -v
(from the repo root, with sdk/python on PYTHONPATH)."""
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "python"))

from bmaglass_sdk import (BMaGlass, BMaGlassError, assert_supported_currency,
                          payment_link_url, verify_webhook_signature, parse_webhook_event)
import requests


class FakeResponse:
    def __init__(self, status, payload):
        self.status_code = status
        self.ok = 200 <= status < 300
        self._payload = payload
        self.text = json.dumps(payload)

    def json(self):
        return self._payload


class FakeSession:
    def __init__(self):
        self.calls = []

    def request(self, method, url, headers=None, data=None, timeout=None):
        self.calls.append({"method": method, "url": url, "headers": headers, "data": data, "timeout": timeout})
        if headers.get("X-Api-Key") not in ("bmp_test_contract", "bmg_sandbox_contract"):
            return FakeResponse(401, {"error": "invalid_api_key", "message": "nope"})
        if url.endswith("/connector/config"):
            return FakeResponse(200, {"data": {
                "environment": "sandbox", "livemode": False, "config_version": "abc123",
                "key": {"key_id": "k1", "scopes": ["config:read"], "status": "active"},
                "business": {"display_name": "QA Store", "country": "ZM", "currency": "ZMW", "status": "pending"},
                "features": {"mobile_money": {"status": "disabled", "reason_code": None}},
                "settlement": {"type": "wallet", "currency": "ZMW"}}, "mode": "test"})
        if url.endswith("/invoices") and method == "POST":
            return FakeResponse(201, {"data": {"id": "inv1", "amount": 250, "currency": "ZMW", "status": "open", "environment": "sandbox"}, "mode": "test"})
        if url.endswith("/invoices/inv1/simulate-payment"):
            return FakeResponse(200, {"data": {"id": "inv1", "status": "paid", "environment": "sandbox"}, "mode": "test", "simulated": True})
        if url.endswith("/wallets"):
            return FakeResponse(200, {"data": [{"id": "w1", "currency": "ZMW", "balance": 42}], "mode": "test"})
        if "/transactions" in url:
            return FakeResponse(200, {"data": [], "mode": "test"})
        if url.endswith("/transfers"):
            return FakeResponse(403, {"error": "test_mode_read_only", "message": "Test keys are read-only"})
        if url.endswith("/bitcoin/balance"):
            return FakeResponse(200, {"data": [{"asset": "BTC", "balance_sats": 1000}], "mode": "test"})
        if url.endswith("/webhooks") and method == "POST":
            return FakeResponse(201, {"data": {"id": "wh1", "secret": "whsec_testsecret"}, "mode": "test"})
        if url.endswith("/settings"):
            return FakeResponse(200, {"data": {"fee_payer_default": "seller", "platform_fee_percent": 2.5, "paypal_platform_fee_percent": 1, "note": "n"}, "mode": "test"})
        if url.endswith("/payment-links/link1/attempts"):
            return FakeResponse(200, {"data": [{"id": "att1", "status": "waiting"}], "mode": "test"})
        if url.endswith("/payment-links/link1"):
            return FakeResponse(200, {"data": {"id": "pl1", "title": "Order #8", "amount": 10, "currency": "ZMW", "link_code": "ABC123", "status": "active", "fee_payer": "seller", "payment_count": 0, "total_collected": 0}, "mode": "test"})
        if url.endswith("/payment-links") and method == "POST":
            # Sandbox keys CAN create payment links (settlement is simulation-only);
            # only transfers/webhook registration are live-only. The stub echoes
            # the per-link fee_payer + redirect_url and the resolved fee maths.
            req = json.loads(data or "{}")
            fee_payer = req.get("fee_payer", "seller")
            fee = round((req.get("amount") or 0) * 0.025, 2)
            amount = req.get("amount") or 0
            return FakeResponse(201, {"data": {"id": "pl1", "title": req.get("title"), "amount": amount,
                                               "currency": req.get("currency", "ZMW"), "link_code": "ABC123",
                                               "status": "active", "fee_payer": fee_payer,
                                               "redirect_url": req.get("redirect_url"),
                                               "payment_count": 0, "total_collected": 0,
                                               "fee_amount": fee,
                                               "buyer_total": amount + fee if fee_payer == "buyer" else amount,
                                               "seller_receives": amount - fee if fee_payer == "seller" else amount},
                                        "mode": "test"})
        if url.endswith("/mobile-money/collections") and method == "POST":
            req = json.loads(data or "{}")
            return FakeResponse(201, {"data": {"reference": "BMG-C1", "merchant_reference": req.get("reference"),
                                               "amount": req.get("amount"), "phone": req.get("phone"),
                                               "operator": req.get("operator", "mtn"), "status": "pending",
                                               "environment": "sandbox"}, "mode": "test"})
        if url.endswith("/mobile-money/collections/BMG-C1") and method == "GET":
            return FakeResponse(200, {"data": {"reference": "BMG-C1", "merchant_reference": "order-42",
                                               "status": "successful", "wallet_balance": 99}, "mode": "test"})
        return FakeResponse(404, {"error": "not_found", "message": "no route"})


class SdkContractTest(unittest.TestCase):
    def setUp(self):
        self.session = FakeSession()
        self.client = BMaGlass("bmp_test_contract", base_url="http://local.test", session=self.session)

    def test_mode_detection(self):
        self.assertEqual(self.client.mode, "test")
        self.assertEqual(self.client.environment, "sandbox")
        self.assertEqual(BMaGlass("bmp_live_x", session=FakeSession()).mode, "live")
        self.assertEqual(BMaGlass("bmp_live_x", session=FakeSession()).environment, "production")
        self.assertEqual(BMaGlass("bmg_sandbox_x", session=FakeSession()).environment, "sandbox")
        self.assertEqual(BMaGlass("bmg_live_x", session=FakeSession()).environment, "production")
        with self.assertRaises(BMaGlassError):
            BMaGlass("sk_live_nope")

    def test_connector_config_and_sandbox_invoices(self):
        client = BMaGlass("bmg_sandbox_contract", base_url="http://local.test", session=self.session)
        config = client.connector.config()
        self.assertEqual(config["environment"], "sandbox")
        self.assertEqual(config["business"]["country"], "ZM")
        self.assertEqual(config["features"]["mobile_money"]["status"], "disabled")
        inv = client.invoices.create(250, currency="ZMW", description="Order 9")
        self.assertEqual(inv["status"], "open")
        paid = client.invoices.simulate_payment("inv1")
        self.assertEqual(paid["status"], "paid")

    def test_wallets_sends_key_header(self):
        wallets = self.client.wallets.list()
        self.assertEqual(wallets[0]["balance"], 42)
        self.assertEqual(self.session.calls[-1]["headers"]["X-Api-Key"], "bmp_test_contract")

    def test_transfer_test_mode_error_is_typed(self):
        with self.assertRaises(BMaGlassError) as ctx:
            self.client.transfers.create(to_user_id="u2", amount=10, idempotency_key="idem-1")
        self.assertEqual(ctx.exception.status, 403)
        self.assertEqual(ctx.exception.code, "test_mode_read_only")
        self.assertEqual(self.session.calls[-1]["headers"]["Idempotency-Key"], "idem-1")

    def test_bitcoin_and_webhooks(self):
        self.assertEqual(self.client.bitcoin.balance()[0]["balance_sats"], 1000)
        hook = self.client.webhooks.create("https://example.com/hook")
        self.assertEqual(hook["secret"], "whsec_testsecret")

    def test_mobile_money_collections(self):
        coll = self.client.mobile_money.create_collection(100, "0961111111", reference="order-42")
        self.assertEqual(coll["status"], "pending")
        self.assertEqual(coll["reference"], "BMG-C1")
        self.assertEqual(coll["merchant_reference"], "order-42")
        sent = json.loads(self.session.calls[-1]["data"])
        self.assertEqual(sent, {"amount": 100, "phone": "0961111111", "reference": "order-42"})
        settled = self.client.mobile_money.get_collection("BMG-C1")
        self.assertEqual(settled["status"], "successful")
        self.assertEqual(settled["wallet_balance"], 99)

    def test_payment_link_fee_payer_override(self):
        # Per-link fee_payer override + redirect_url (live API, proven 2026-11-14):
        # a sandbox key creates the link; the resolved fee maths ride on the row.
        link = self.client.payment_links.create("Order #8", amount=10, currency="ZMW",
                                                fee_payer="buyer", redirect_url="https://example.invalid/return")
        self.assertEqual(link["fee_payer"], "buyer")
        self.assertEqual(link["redirect_url"], "https://example.invalid/return")
        self.assertEqual(link["fee_amount"], 0.25)
        self.assertEqual(link["buyer_total"], 10.25)
        self.assertEqual(link["seller_receives"], 10)
        sent = json.loads(self.session.calls[-1]["data"])
        self.assertEqual(sent["fee_payer"], "buyer")
        self.assertEqual(sent["redirect_url"], "https://example.invalid/return")
        seller_link = self.client.payment_links.create("Order #9", amount=10, currency="ZMW")
        self.assertEqual(seller_link["fee_payer"], "seller")
        self.assertEqual(seller_link["seller_receives"], 9.75)
        with self.assertRaises(BMaGlassError):
            self.client.payment_links.create("Bad", amount=10, fee_payer="nobody")

    def test_settings_and_attempts(self):
        settings = self.client.settings.get()
        self.assertEqual(settings["fee_payer_default"], "seller")
        self.assertEqual(settings["platform_fee_percent"], 2.5)
        self.assertEqual(settings["paypal_platform_fee_percent"], 1)
        self.assertEqual(self.client.payment_links.attempts("link1")[0]["status"], "waiting")
        self.assertEqual(self.client.payment_links.get("link1")["link_code"], "ABC123")


class ExtHuntFixesTest(unittest.TestCase):
    """ext-hunt (2026-11-15): transport, encoding, client-side validation."""

    def setUp(self):
        self.session = FakeSession()
        self.client = BMaGlass("bmp_test_contract", base_url="http://local.test", session=self.session)

    def test_network_failure_is_typed(self):
        class Boom(requests.Session):
            def request(self, *a, **k):
                raise requests.ConnectionError("boom")
        client = BMaGlass("bmg_sandbox_x", base_url="http://local.test", session=Boom())
        with self.assertRaises(BMaGlassError) as ctx:
            client.wallets.list()
        self.assertEqual((ctx.exception.status, ctx.exception.code), (0, "network_error"))

    def test_timeout_is_sent_and_configurable(self):
        self.client.wallets.list()
        self.assertEqual(self.session.calls[-1].get("timeout"), 30)
        fast = BMaGlass("bmg_sandbox_contract", base_url="http://local.test", session=self.session, timeout=5)
        fast.wallets.list()
        self.assertEqual(self.session.calls[-1].get("timeout"), 5)

    def test_ids_are_url_encoded(self):
        with self.assertRaises(BMaGlassError):
            self.client.invoices.get("a/b")
        self.assertTrue(self.session.calls[-1]["url"].endswith("/invoices/a%2Fb"))
        with self.assertRaises(BMaGlassError):
            self.client.payment_links.attempts("x/y")
        self.assertTrue(self.session.calls[-1]["url"].endswith("/payment-links/x%2Fy/attempts"))
        with self.assertRaises(BMaGlassError):
            self.client.webhooks.test("w/z")
        self.assertTrue(self.session.calls[-1]["url"].endswith("/webhooks/w%2Fz/test"))

    def test_currency_guard_on_money_moves_only(self):
        before = len(self.session.calls)
        with self.assertRaises(BMaGlassError) as ctx:
            self.client.transfers.create(to_user_id="u2", amount=10, currency="EUR")
        self.assertEqual(ctx.exception.code, "unsupported_currency")
        with self.assertRaises(BMaGlassError) as ctx:
            self.client.payment_links.create("Euro", amount=10, currency="EUR")
        self.assertEqual(ctx.exception.code, "unsupported_currency")
        self.assertEqual(len(self.session.calls), before, "refused calls must not hit the network")
        # Invoices are NOT guarded: the API accepts any 3-letter invoice currency.
        self.client.invoices.create(10, currency="EUR")
        self.assertEqual(json.loads(self.session.calls[-1]["data"])["currency"], "EUR")

    def test_bad_side_is_refused(self):
        with self.assertRaises(BMaGlassError) as ctx:
            self.client.bitcoin.quote("bogus", amount_zmw=100)
        self.assertEqual(ctx.exception.code, "invalid_side")
        with self.assertRaises(BMaGlassError):
            self.client.bitcoin.quote("selll", amount_sats=5000)

    def test_transactions_limit_is_clamped(self):
        self.client.transactions.list(0)
        self.assertTrue(self.session.calls[-1]["url"].endswith("transactions?limit=1"))
        self.client.transactions.list(500)
        self.assertTrue(self.session.calls[-1]["url"].endswith("transactions?limit=100"))

    def test_payment_link_url_helper(self):
        self.assertEqual(payment_link_url("ABC123"), "https://bmaglasspay.online/pay/ABC123")
        self.assertEqual(payment_link_url("a/b"), "https://bmaglasspay.online/pay/a%2Fb")
        self.assertEqual(assert_supported_currency("zmw"), "ZMW")
        with self.assertRaises(BMaGlassError):
            assert_supported_currency("EUR")


class RetryTest(unittest.TestCase):
    """Retries on transient 502/503/504 (CEO decision 2026-11-15):
    max 3 attempts total; only idempotent requests are retried
    (GET/DELETE, or POSTs carrying an Idempotency-Key); backoff is
    exponential with full jitter."""

    class ScriptedSession:
        """Returns scripted statuses in order, then repeats the last."""
        def __init__(self, statuses, payload=None):
            self.statuses = statuses
            self.payload = payload or {"data": [{"id": "w1", "currency": "ZMW", "balance": 42}]}
            self.calls = []

        def request(self, method, url, headers=None, data=None, timeout=None):
            self.calls.append({"method": method, "url": url, "headers": headers, "data": data})
            n = len(self.calls)
            status = self.statuses[min(n - 1, len(self.statuses) - 1)]
            if status >= 400:
                return FakeResponse(status, {"error": "unavailable", "message": "try again"})
            return FakeResponse(status, self.payload)

    def setUp(self):
        self.delays = []

    def _client(self, session, **kw):
        kw.setdefault("retry_base_delay", 0)
        kw.setdefault("sleep", self.delays.append)
        return BMaGlass("bmp_test_contract", base_url="http://local.test", session=session, **kw)

    def test_read_503_twice_then_200_succeeds_on_third_attempt(self):
        session = self.ScriptedSession([503, 503, 200])
        client = self._client(session, retry_base_delay=0.1)
        wallets = client.wallets.list()
        self.assertEqual(wallets[0]["balance"], 42)
        self.assertEqual(len(session.calls), 3)
        self.assertEqual(len(self.delays), 2)
        self.assertTrue(0 <= self.delays[0] <= 0.1)
        self.assertTrue(0 <= self.delays[1] <= 0.2)

    def test_keyed_create_reuses_same_idempotency_key(self):
        payload = {"data": {"id": "pl1", "title": "Retry Link", "amount": 10,
                            "currency": "ZMW", "link_code": "ABC123", "status": "active"}}
        session = self.ScriptedSession([503, 201], payload)
        client = self._client(session)
        link = client.payment_links.create("Retry Link", amount=10, currency="ZMW")
        self.assertEqual(link["link_code"], "ABC123")
        self.assertEqual(len(session.calls), 2)
        keys = [c["headers"]["Idempotency-Key"] for c in session.calls]
        self.assertTrue(keys[0])
        self.assertEqual(keys[0], keys[1], "same key on the retry")
        # An explicit caller key is honoured verbatim, not replaced.
        session2 = self.ScriptedSession([503, 201], payload)
        client2 = self._client(session2)
        client2.payment_links.create("Retry Link", amount=10, idempotency_key="caller-key-123")
        self.assertEqual([c["headers"]["Idempotency-Key"] for c in session2.calls],
                         ["caller-key-123", "caller-key-123"])

    def test_400_is_not_retried(self):
        session = self.ScriptedSession([400])
        client = self._client(session)
        with self.assertRaises(BMaGlassError) as ctx:
            client.wallets.list()
        self.assertEqual(ctx.exception.status, 400)
        self.assertEqual(len(session.calls), 1)

    def test_simulate_payment_and_quote_are_not_retried(self):
        # Neither carries an idempotency key: a 503 must not be retried
        # (simulate-payment would double-count; quote would mint two rows).
        session = self.ScriptedSession([503])
        client = self._client(session)
        with self.assertRaises(BMaGlassError):
            client.payment_links.simulate_payment("link1")
        self.assertEqual(len(session.calls), 1)
        session2 = self.ScriptedSession([503])
        client2 = self._client(session2)
        with self.assertRaises(BMaGlassError):
            client2.bitcoin.quote("buy", amount_zmw=100)
        self.assertEqual(len(session2.calls), 1)

    def test_max_retries_zero_means_one_attempt(self):
        session = self.ScriptedSession([503, 200])
        client = self._client(session, max_retries=0)
        with self.assertRaises(BMaGlassError) as ctx:
            client.wallets.list()
        self.assertEqual(ctx.exception.status, 503)
        self.assertEqual(len(session.calls), 1)

    def test_transport_failure_on_read_is_retried(self):
        class Boom(requests.Session):
            def __init__(self):
                self.calls = 0
            def request(self, *a, **k):
                self.calls += 1
                raise requests.ConnectionError("boom")
        boom = Boom()
        client = self._client(boom)
        with self.assertRaises(BMaGlassError) as ctx:
            client.wallets.list()
        self.assertEqual(ctx.exception.code, "network_error")
        self.assertEqual(boom.calls, 3)

    def test_invoice_create_carries_auto_key(self):
        payload = {"data": {"id": "inv1", "status": "open"}}
        session = self.ScriptedSession([201], payload)
        client = self._client(session)
        client.invoices.create(10, currency="ZMW")
        self.assertTrue(session.calls[0]["headers"]["Idempotency-Key"])


class WebhookVerifyTest(unittest.TestCase):
    RAW = b'{"id":"evt_1","type":"webhook.test","created":"2026-10-26T00:00:00.000Z","data":{"ping":true}}'
    SIG = "sha256=ff522c97ada5bfd4f6c09a0457823f89546896708e8e1f5bbb72d2ad59d68535"

    def test_known_answer_vector(self):
        self.assertTrue(verify_webhook_signature(self.RAW, self.SIG, "whsec_testsecret"))
        self.assertFalse(verify_webhook_signature(self.RAW, self.SIG, "whsec_wrong"))
        self.assertFalse(verify_webhook_signature(self.RAW + b" ", self.SIG, "whsec_testsecret"))
        event = parse_webhook_event(self.RAW, self.SIG, "whsec_testsecret")
        self.assertEqual(event["type"], "webhook.test")
        self.assertIsNone(parse_webhook_event(self.RAW, "sha256=deadbeef", "whsec_testsecret"))


if __name__ == "__main__":
    unittest.main()
