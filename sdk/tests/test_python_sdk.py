"""Contract tests for the Python SDK using a stub transport that mirrors
the developer-api contract. Run: python3 -m unittest discover sdk/tests -v
(from the repo root, with sdk/python on PYTHONPATH)."""
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "python"))

from bmaglass_sdk import BMaGlass, BMaGlassError, verify_webhook_signature, parse_webhook_event


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
        self.calls.append({"method": method, "url": url, "headers": headers, "data": data})
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

    def test_settings_and_attempts(self):
        settings = self.client.settings.get()
        self.assertEqual(settings["fee_payer_default"], "seller")
        self.assertEqual(settings["platform_fee_percent"], 2.5)
        self.assertEqual(settings["paypal_platform_fee_percent"], 1)
        self.assertEqual(self.client.payment_links.attempts("link1")[0]["status"], "waiting")


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
