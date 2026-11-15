# -*- coding: utf-8 -*-
import json
import logging

from odoo import http
from odoo.http import request
from odoo.addons.payment import utils as payment_utils

from odoo.exceptions import UserError

from ..models.bmaglass_api import BMaGlassError, parse_webhook_event

_logger = logging.getLogger(__name__)


class BMaGlassPayController(http.Controller):
    """Signed webhook receiver for BMaGlass developer webhooks, plus the
    mobile-money start/status routes for the BMaGlass Pay checkout.

    Webhook security is the HMAC-SHA256 signature (X-BMaGlass-Signature)
    over the exact raw body, checked against the secret stored on the
    provider when the webhook was registered. Events today:
    webhook.test (connectivity proof), payment_link.created,
    transaction.completed (the account's own API transfers — its payload
    carries no sale/order identity, so it cannot settle a payment
    transaction), payment_link.paid — the settlement event — and
    mobile_money.collection.completed, which settles the transaction
    whose reference the collection carries. Odoo 20 forbids writing a
    payment transaction from a controller (install-test finding B12):
    verified settlement payloads are queued through _record() and the
    state change happens in _apply_updates() under core's locked
    processing. The reconciliation cron stays the fallback for any
    missed delivery. Every verified event is logged. A delivery whose
    link code / merchant reference is missing is ignored, never matched
    against NULL-code transactions.

    The mobile-money routes are authenticated by the transaction's own
    access token — the same deterministic token core put on the landing
    route — so a buyer (even a guest) can start and poll their own
    collection and nobody else's.
    """

    # -- helpers --------------------------------------------------------
    def _json(self, payload, status=200):
        return request.make_response(
            json.dumps(payload),
            headers=[("Content-Type", "application/json")], status=status)

    def _momo_tx(self, tx_id, access_token):
        """Load a BMaGlass transaction and verify the buyer's access
        token. Returns the sudoed transaction, or None."""
        try:
            tx_id = int(tx_id or 0)
        except (TypeError, ValueError):
            return None
        tx = request.env["payment.transaction"].sudo().browse(tx_id)
        if not tx.exists() or tx.provider_code != "bmaglass":
            return None
        try:
            ok = payment_utils.check_access_token(
                access_token or "", tx.partner_id.id, tx.amount, tx.currency_id.id)
        except Exception:
            ok = False
        return tx if ok else None

    # -- webhook ----------------------------------------------------------
    @http.route("/payment/bmaglass/webhook", type="http", auth="public",
                methods=["POST"], csrf=False)
    def bmaglass_webhook(self):
        provider = request.env["payment.provider"].sudo().search(
            [("code", "=", "bmaglass")], limit=1)
        secret = provider.bmaglass_webhook_secret if provider else ""
        raw = request.httprequest.get_data(cache=False, as_text=False)
        signature = request.httprequest.headers.get("X-BMaGlass-Signature", "")

        event = parse_webhook_event(raw, signature, secret or "")
        if event is None:
            _logger.warning("BMaGlass webhook rejected: bad signature or payload.")
            return self._json({"error": "invalid_signature"}, status=401)

        etype = event.get("type")
        data = event.get("data") or {}
        if etype == "webhook.test":
            _logger.info("BMaGlass webhook.test received and verified.")
        elif etype == "payment_link.paid":
            link_code = data.get("link_code")
            if not link_code:
                _logger.warning("BMaGlass payment_link.paid without a link_code; ignored.")
            else:
                tx = request.env["payment.transaction"].sudo().search(
                    [("bmaglass_link_code", "=", link_code)], limit=1)
                if tx:
                    # Odoo 20 (B12): queue the verified payload; the
                    # cross-checks and the state change run in
                    # _apply_updates(), never here.
                    tx._record(dict(data))
                else:
                    _logger.info(
                        "BMaGlass payment_link.paid for unknown link %s.", link_code)
        elif etype == "mobile_money.collection.completed":
            merchant_reference = data.get("merchant_reference")
            if not merchant_reference:
                _logger.warning(
                    "BMaGlass mobile_money.collection.completed without a "
                    "merchant_reference; ignored.")
            else:
                tx = request.env["payment.transaction"].sudo().search(
                    [("reference", "=", merchant_reference),
                     ("provider_id.code", "=", "bmaglass")], limit=1)
                if tx:
                    # Odoo 20 (B12): queue the verified payload; the
                    # cross-checks and the state change run in
                    # _apply_updates(), never here.
                    tx._record(tx._bmaglass_momo_payload(data))
                else:
                    _logger.info(
                        "BMaGlass mobile_money.collection.completed for unknown "
                        "reference %s.", merchant_reference)
        elif etype == "payment_link.created":
            _logger.info("BMaGlass payment_link.created: link %s.", data.get("link_code"))
            link_code = data.get("link_code")
            if link_code:
                tx = request.env["payment.transaction"].sudo().search(
                    [("bmaglass_link_code", "=", link_code)], limit=1)
                if tx:
                    # Opportunistic fast-path: the link exists; the cron
                    # remains the settler, this just checks a beat early.
                    try:
                        tx._bmaglass_check_paid()
                    except (BMaGlassError, UserError):
                        pass  # the cron retries; never 500 a verified delivery
        else:
            _logger.info("BMaGlass webhook event received: %s.", etype)

        return self._json({"received": True})

    # -- mobile money -----------------------------------------------------
    @http.route("/payment/bmaglass/momo/start", type="http", auth="public",
                methods=["POST"], csrf=False)
    def bmaglass_momo_start(self, **kwargs):
        tx = self._momo_tx(kwargs.get("tx_id"), kwargs.get("access_token"))
        if not tx:
            return self._json({"error": "not_found"}, status=403)
        if tx.state not in ("draft", "pending"):
            return self._json(
                {"error": "already_processed", "state": tx.state}, status=409)
        if not tx._bmaglass_is_momo():
            return self._json({"error": "not_momo"}, status=400)
        phone = (kwargs.get("phone") or "").strip()
        operator = (kwargs.get("operator") or "").strip() or None
        if not phone:
            return self._json(
                {"error": "phone_required",
                 "message": "Enter the mobile money number to charge."}, status=400)
        try:
            collection = tx._bmaglass_momo_create(phone, operator)
        except BMaGlassError as e:
            return self._json({"error": e.code, "message": e.user_message()}, status=400)
        except UserError as e:
            # No API key on the provider (or a malformed one): plain
            # language for the buyer, never a 500.
            return self._json({"error": "no_api_key", "message": str(e)}, status=400)
        return self._json({
            "reference": collection.get("reference"),
            "status": (collection.get("status") or "").lower(),
            "state": tx.state,
            "landing_route": tx.landing_route or "/payment/confirmation",
        })

    @http.route("/payment/bmaglass/momo/status", type="http", auth="public",
                methods=["GET"], csrf=False)
    def bmaglass_momo_status(self, **kwargs):
        tx = self._momo_tx(kwargs.get("tx_id"), kwargs.get("access_token"))
        if not tx:
            return self._json({"error": "not_found"}, status=403)
        if tx.state == "pending" and tx.bmaglass_momo_reference:
            # Poll-driven reconcile: the buyer is watching, so check the
            # rail now instead of waiting for the webhook or the cron.
            # A missing key / refused key just leaves the state as-is.
            try:
                tx._bmaglass_momo_check_status()
            except (BMaGlassError, UserError):
                pass
        return self._json({
            "state": tx.state,
            "state_message": tx.state_message or "",
            "landing_route": tx.landing_route or "/payment/confirmation",
        })
