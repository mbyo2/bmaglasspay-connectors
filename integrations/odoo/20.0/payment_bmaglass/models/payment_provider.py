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
             "wallets:read, payment_links:read and payment_links:write scopes. "
             "A live key (bmp_live_...) is required to take payments.",
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

    def _bmaglass_webhook_url(self):
        # Odoo 20 replaced ir.config_parameter.get_param/set_param with
        # typed accessors (get_str/set_str) — install-test finding B8.
        base = self.env["ir.config_parameter"].sudo().get_str("web.base.url", "")
        return "%s/payment/bmaglass/webhook" % base.rstrip("/")

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
        return ["bmaglass"]

    @api.constrains("bmaglass_api_key")
    def _check_bmaglass_api_key_format(self):
        """Key-format gate (Odoo 20).

        The 16.0–19.0 variants constrain on the provider's `state`
        field ('enabled' demands a live key). Odoo 20 reworked the
        payment provider's state handling — the 20.0 release notes
        record the provider views being 'refined and simplified', and
        upgrade guides report the legacy State field replaced by
        Published / Live / Archive — so `state` is no longer a stable
        anchor and this variant does not constrain on it. The honest
        test-mode rule itself is unchanged; it is enforced where it
        cannot be bypassed, at payment-link creation in
        payment_transaction._bmaglass_create_link(), which refuses a
        non-live key with TEST_MODE_MESSAGE before any link exists
        (and the API independently refuses test-key writes with
        `test_mode_read_only`).
        """
        for provider in self:
            if provider.code != "bmaglass":
                continue
            key = (provider.bmaglass_api_key or "").strip()
            if key and not key.startswith("bmp_"):
                raise ValidationError(
                    "That doesn't look like a BMaGlass API key — BMaGlass "
                    "keys start with bmp_live_ or bmp_test_.")

    # -- settings actions -------------------------------------------------
    def action_bmaglass_test_connection(self):
        """Read the account's wallets with the configured key."""
        self.ensure_one()
        client = self._bmaglass_client()
        try:
            wallets = client.wallets()
        except BMaGlassError as e:
            raise UserError(e.user_message())
        parts = ["%s %s" % (w.get("currency"), "{:,.2f}".format(float(w.get("balance", 0))))
                 for w in wallets]
        message = "Connected to BMaGlass Pay (%s key). Wallets: %s." % (
            client.mode, " · ".join(parts) or "none")
        if client.mode == "test":
            message += " " + TEST_MODE_MESSAGE
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
                ["transaction.completed", "payment_link.created", "payment_link.paid", "webhook.test"],
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
