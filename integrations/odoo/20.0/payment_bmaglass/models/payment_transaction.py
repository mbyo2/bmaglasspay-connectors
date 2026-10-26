# -*- coding: utf-8 -*-
import logging
from datetime import datetime, timedelta, timezone

from odoo import fields, models

from .bmaglass_api import (
    BMaGlassError, TEST_MODE_MESSAGE, paid_event_matches, payment_link_url,
)

_logger = logging.getLogger(__name__)


class PaymentTransaction(models.Model):
    _inherit = "payment.transaction"

    bmaglass_link_id = fields.Char(string="BMaGlass payment link ID", readonly=True)
    bmaglass_link_code = fields.Char(string="BMaGlass payment link code", readonly=True)

    # -- checkout ---------------------------------------------------------
    def _bmaglass_create_link(self):
        """Create the per-transaction payment link via the developer API."""
        self.ensure_one()
        provider = self.provider_id
        client = provider._bmaglass_client()
        if not client.is_live:
            # Honest test mode, enforced at the point of no return
            # (see payment_provider.py for why 20.0 gates here rather
            # than on the provider's state): a test key may check the
            # connection and read wallets, but it can never take a
            # payment — BMaGlass has no test-mode ledger.
            raise BMaGlassError(0, "test_mode_read_only", TEST_MODE_MESSAGE)
        expires = (datetime.now(timezone.utc) + timedelta(days=7)).isoformat()
        link = client.create_payment_link(
            title="Order %s — %s" % (self.reference, provider.company_id.name or "Odoo"),
            amount=self.amount,
            currency=self.currency_id.name,
            description="Odoo payment %s" % self.reference,
            expires_at=expires,
        )
        self.write({
            "bmaglass_link_id": link.get("id"),
            "bmaglass_link_code": link.get("link_code"),
            "provider_reference": link.get("link_code"),
        })
        return link

    def _get_specific_rendering_values(self, processing_values):
        """Redirect flow: send the buyer to the hosted BMaGlass page."""
        res = super()._get_specific_rendering_values(processing_values)
        if self.provider_code != "bmaglass":
            return res
        try:
            link = self._bmaglass_create_link()
        except BMaGlassError as e:
            # Plain language for the buyer — never a raw API response.
            self._set_error(e.user_message())
            return res
        res["api_url"] = payment_link_url(link.get("link_code"))
        return res

    def _get_specific_processing_values(self, processing_values):
        res = super()._get_specific_processing_values(processing_values)
        if self.provider_code == "bmaglass":
            res["bmaglass_link_code"] = self.bmaglass_link_code
        return res


    def _bmaglass_log(self, message):
        """Record a settlement note without ever breaking settlement.

        payment.transaction is not a mail.thread in 17.0-20.0 (no
        message_post) and has no _log_message_on_transaction in any of
        16.0-20.0 (install-test finding B5, proven live on 17.0). The
        core-supported channel is _log_message_on_linked_documents,
        which the sale module overrides to post on the linked sale
        order. The fallbacks keep the note in the server log if a
        version changes the API again — logging must never roll back
        a settlement.
        """
        self.ensure_one()
        try:
            log_on_docs = getattr(self, "_log_message_on_linked_documents", None)
            if callable(log_on_docs):
                log_on_docs(message)
            elif hasattr(self, "message_post"):
                self.message_post(body=message)
            else:
                _logger.info("BMaGlass: %s", message)
        except Exception:
            _logger.exception(
                "BMaGlass: failed to log the settlement note for %s", self.reference)

    # -- Odoo 20 payment-data pipeline (install-test finding B12) -----------
    # Odoo 20 forbids direct writes to payment transactions outside the
    # payment.data processing queue: controllers and crons must call
    # _record(payment_data), and the state change itself belongs in
    # _apply_updates(), which core invokes locked and with
    # payment_safe_write=True. Settlement therefore lives in
    # _apply_updates(); both entry points (webhook controller and the
    # reconciliation cron) only ever _record().
    def _extract_amount_data(self, payment_data):
        if self.provider_code != "bmaglass":
            return super()._extract_amount_data(payment_data)
        data = payment_data or {}
        try:
            return {
                "amount": float(data.get("amount")),
                "currency_code": data.get("currency"),
            }
        except (TypeError, ValueError):
            return None

    def _apply_updates(self, payment_data):
        super()._apply_updates(payment_data)
        if self.provider_code != "bmaglass":
            return
        data = payment_data or {}
        if data.get("source") != "reconcile" and self._bmaglass_settle_from_paid_event(data):
            return
        # The payload alone did not settle it (declined cross-check or a
        # reconciliation-queued record): confirm against the API before
        # anything is marked paid — the module's standing rule.
        self._bmaglass_confirm_via_api()

    def _bmaglass_confirm_via_api(self):
        """API-confirmed settlement, for use inside _apply_updates only.

        Returns True when the transaction is settled after the call.
        """
        self.ensure_one()
        if self.state in ("done", "cancel", "error") or not self.bmaglass_link_code:
            return self.state == "done"
        client = self.provider_id._bmaglass_client()
        try:
            links = client.list_payment_links()
        except BMaGlassError as e:
            _logger.warning(
                "BMaGlass API confirm failed for %s: %s", self.reference, e.user_message())
            return False
        for link in links or []:
            if link.get("link_code") != self.bmaglass_link_code:
                continue
            count = int(link.get("payment_count") or 0)
            collected = float(link.get("total_collected") or 0.0)
            if count > 0 and collected + 0.009 >= float(self.amount):
                self._set_done()
                self._bmaglass_log(
                    "BMaGlass Pay payment confirmed via the API — link %s, collected %s %s."
                    % (self.bmaglass_link_code, "{:,.2f}".format(collected),
                       self.currency_id.name)
                )
                return True
            return False
        return False

    # -- settlement: webhook first, reconciliation as fallback --------------
    def _bmaglass_settle_from_paid_event(self, data):
        """Settle this transaction from a verified payment_link.paid event.

        The controller HMAC-verified the delivery; paid_event_matches
        cross-checks link code, currency and amount before _set_done()
        runs. _set_done() is state-guarded, so a duplicate delivery —
        or the reconciliation cron racing the same payment — can never
        settle twice. Returns True when this transaction is settled
        after the call; False declines the event (the caller then
        confirms against the API instead). On Odoo 20 this runs only
        inside _apply_updates(), under core's locked, write-safe
        processing context (B12).
        """
        self.ensure_one()
        if self.state == "done":
            return True
        if self.state in ("cancel", "error") or not self.bmaglass_link_code:
            return False
        ok, reason = paid_event_matches(
            data, self.bmaglass_link_code, self.amount, self.currency_id.name)
        if not ok:
            _logger.info(
                "BMaGlass payment_link.paid declined for %s: %s", self.reference, reason)
            return False
        self._set_done()
        self._bmaglass_log(
            "BMaGlass Pay payment confirmed by webhook (payment_link.paid) — link %s, %s %s."
            % (self.bmaglass_link_code, "{:,.2f}".format(float(data.get("amount"))),
               self.currency_id.name)
        )
        return True

    def _bmaglass_check_paid(self):
        """Queue a settlement if this transaction's link shows paid.

        Settlement rule, same as the other connectors: payment_count
        has grown AND total_collected covers the transaction amount.
        On Odoo 20 the state change itself must go through the
        payment.data queue (B12), so a paid link is _record()ed with
        the API's own figures; _apply_updates() re-confirms against
        the API at processing time before _set_done(). Exactly-once
        is preserved: _set_done() is state-guarded and processing is
        locked per transaction.
        """
        self.ensure_one()
        if self.state in ("done", "cancel", "error") or not self.bmaglass_link_code:
            return False
        client = self.provider_id._bmaglass_client()
        try:
            links = client.list_payment_links()
        except BMaGlassError as e:
            _logger.warning("BMaGlass reconcile check failed for %s: %s", self.reference, e.user_message())
            return False
        for link in links or []:
            if link.get("link_code") != self.bmaglass_link_code:
                continue
            count = int(link.get("payment_count") or 0)
            collected = float(link.get("total_collected") or 0.0)
            if count > 0 and collected + 0.009 >= float(self.amount):
                self._record({
                    "link_code": self.bmaglass_link_code,
                    "amount": collected,
                    "currency": self.currency_id.name,
                    "payment_count": count,
                    "source": "reconcile",
                })
                return True
            return False
        return False

    def _bmaglass_reconcile_pending(self):
        """Cron entry point: sweep unsettled BMaGlass transactions.

        Settlement normally arrives instantly via the payment_link.paid
        webhook; this scheduled reconciliation is the fallback that
        settles any transaction whose delivery was missed.
        """
        providers = self.env["payment.provider"].search([("code", "=", "bmaglass")])
        txs = self.search([
            ("provider_id", "in", providers.ids),
            ("state", "in", ("pending", "draft")),
            ("bmaglass_link_code", "!=", False),
            ("create_date", ">", fields.Datetime.now() - timedelta(days=7)),
        ], limit=100)
        for tx in txs:
            try:
                tx._bmaglass_check_paid()
            except Exception:  # one bad tx must not stop the sweep
                _logger.exception("BMaGlass reconcile failed for tx %s", tx.reference)
