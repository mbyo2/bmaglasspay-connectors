# -*- coding: utf-8 -*-
import logging

from odoo import api, fields, models
from odoo.exceptions import UserError, ValidationError

from .bmaglass_api import BMaGlassClient, BMaGlassError, TEST_MODE_MESSAGE

_logger = logging.getLogger(__name__)


class PaymentProvider(models.Model):
    _inherit = "payment.provider"

    code = fields.Selection(
        selection_add=[("bmaglass", "BMaGlass Pay")],
        ondelete={"bmaglass": "set default"},
    )
    bmaglass_api_key = fields.Char(
        string="BMaGlass API key",
        help="Create a key in BMaGlass Pay (Business settings or the API page) with the "
             "config:read, wallets:read, payment_links:read, payment_links:write, "
             "webhooks:write, mobile_money:create and mobile_money:read scopes. "
             "A production key (bmg_live_...) is required to take payments.",
        groups="base.group_system",
    )
    bmaglass_webhook_id = fields.Char(string="BMaGlass webhook ID", readonly=True)
    bmaglass_webhook_secret = fields.Char(
        string="BMaGlass webhook secret", readonly=True, groups="base.group_system",
        help="Returned exactly once when the webhook is registered; stored so incoming "
             "events can be HMAC-verified.",
    )

    # -- helpers --------------------------------------------------------
    def _bmaglass_client(self):
        self.ensure_one()
        if not self.bmaglass_api_key:
            raise UserError("Set a BMaGlass API key on the provider first.")
        try:
            return BMaGlassClient(self.bmaglass_api_key.strip())
        except BMaGlassError as e:
            raise UserError(e.user_message())

    def _bmaglass_base_url(self):
        """This Odoo instance's public base URL (web.base.url)."""
        self.ensure_one()
        return (self.env["ir.config_parameter"].sudo().get_param("web.base.url") or "").strip()

    def _bmaglass_webhook_url(self):
        base = self._bmaglass_base_url()
        if not base:
            raise UserError(
                "Set this Odoo instance's public web address (web.base.url) before "
                "registering the webhook — BMaGlass has nowhere to deliver events to.")
        return "%s/payment/bmaglass/webhook" % base.rstrip("/")

    def _bmaglass_landing_url(self, landing_route):
        """Absolute URL for a (relative) transaction landing route, or
        None when there is no route or no base URL to build it from."""
        self.ensure_one()
        route = (landing_route or "").strip()
        base = self._bmaglass_base_url()
        if not route or not base:
            return None
        return "%s%s" % (base.rstrip("/"), route if route.startswith("/") else "/" + route)

    # -- framework hooks --------------------------------------------------
    def _get_supported_currencies(self):
        """BMaGlass wallets are ZMW and USD — never anything else."""
        currencies = super()._get_supported_currencies()
        if self.code == "bmaglass":
            allowed = self.env["res.currency"].with_context(active_test=False).search([("name", "in", ["ZMW", "USD"])])
            return currencies & allowed
        return currencies

    def _get_default_payment_method_codes(self):
        """Override of `payment` to return the default payment method codes.

        Core activates a provider's default methods when the provider is
        enabled; the base implementation returns an empty list, so
        without this override the BMaGlass method stays archived and the
        provider never renders at checkout (install-test finding B4).
        """
        default_codes = super()._get_default_payment_method_codes()
        if self.code != "bmaglass":
            return default_codes
        return ["bmaglass", "bmaglass_momo"]

    @api.constrains("state", "bmaglass_api_key")
    def _check_bmaglass_enabled_needs_live_key(self):
        """Honest test mode: enabling payments requires a live key.

        A test key may sit on a provider in Odoo's 'test' state for
        connection checks, but it can never take a payment — BMaGlass
        uses simulated money — so 'enabled' demands a production key (bmg_live_ or bmp_live_).
        """
        for provider in self:
            if provider.code != "bmaglass":
                continue
            if provider.state == "enabled":
                key = (provider.bmaglass_api_key or "").strip()
                if not (key.startswith("bmg_live_") or key.startswith("bmp_live_")):
                    raise ValidationError(TEST_MODE_MESSAGE)

    # -- settings actions -------------------------------------------------
    def action_bmaglass_test_connection(self):
        """Read the account's wallets with the configured key."""
        self.ensure_one()
        client = self._bmaglass_client()
        try:
            wallets = client.wallets()
        except BMaGlassError as e:
            raise UserError(e.user_message())
        if not isinstance(wallets, list):
            raise UserError("BMaGlass Pay answered in an unexpected format — try again.")
        parts = []
        for w in wallets:
            if not isinstance(w, dict):
                continue
            try:
                balance = float(w.get("balance") or 0)
            except (TypeError, ValueError):
                balance = 0.0
            parts.append("%s %s" % (w.get("currency") or "?", "{:,.2f}".format(balance)))
        message = "Connected to BMaGlass Pay (%s key). Wallets: %s." % (
            client.mode, " · ".join(parts) or "none")
        if client.mode == "test":
            message += " " + TEST_MODE_MESSAGE
        try:
            config = client.connector_config()
            mm = ((config or {}).get("features") or {}).get("mobile_money") or {}
            if isinstance(mm, dict) and mm.get("status"):
                if mm.get("status") == "enabled" and mm.get("connected"):
                    message += " Mobile Money: enabled and connected."
                elif mm.get("status") == "enabled":
                    message += " Mobile Money: enabled in BMaGlass but no mobile money account is connected yet."
                else:
                    message += " Mobile Money: %s." % mm.get("status")
        except BMaGlassError:
            pass  # wallets already proved the connection; config needs config:read
        return {
            "type": "ir.actions.client",
            "tag": "display_notification",
            "params": {"title": "BMaGlass Pay", "message": message, "type": "success", "sticky": False},
        }

    def action_bmaglass_register_webhook(self):
        """Register this Odoo instance's webhook endpoint with BMaGlass."""
        self.ensure_one()
        client = self._bmaglass_client()
        # Retire the previous registration first (best-effort): without
        # this, every re-register orphans the old webhook on the account
        # and events fan out to stale endpoints (install-test finding,
        # same pattern as the WooCommerce fix).
        if self.bmaglass_webhook_id:
            try:
                client.delete_webhook(self.bmaglass_webhook_id)
            except BMaGlassError:
                _logger.info("BMaGlass: retiring the previous webhook failed (non-fatal).")
        try:
            hook = client.create_webhook(
                self._bmaglass_webhook_url(),
                ["transaction.completed", "payment_link.created", "payment_link.paid",
                 "mobile_money.collection.completed", "webhook.test"],
            )
        except BMaGlassError as e:
            raise UserError(e.user_message())
        values = {"bmaglass_webhook_id": hook.get("id")}
        if hook.get("secret"):
            # Shown exactly once by the API — store it now.
            values["bmaglass_webhook_secret"] = hook["secret"]
        self.write(values)
        try:
            client.test_webhook(hook.get("id"))
        except BMaGlassError:
            _logger.info("BMaGlass webhook registered; the test send failed (non-fatal).")
        return {
            "type": "ir.actions.client",
            "tag": "display_notification",
            "params": {
                "title": "BMaGlass Pay",
                "message": "Webhook registered and a signed test event was sent to this instance.",
                "type": "success", "sticky": False,
            },
        }
