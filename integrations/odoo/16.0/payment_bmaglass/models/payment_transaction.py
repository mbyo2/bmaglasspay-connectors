# -*- coding: utf-8 -*-
import logging
from datetime import datetime, timedelta, timezone
from odoo import fields, models
from odoo.exceptions import UserError

from .bmaglass_api import BMaGlassError, paid_event_matches, payment_link_url

_logger = logging.getLogger(__name__)


class PaymentTransaction(models.Model):
    _inherit = "payment.transaction"

    bmaglass_link_id = fields.Char(string="BMaGlass payment link ID", readonly=True)
    bmaglass_link_code = fields.Char(string="BMaGlass payment link code", readonly=True)
    # -- helpers ----------------------------------------------------------
    def _bmaglass_link_title(self):
        self.ensure_one()
        return "Order %s — %s" % (self.reference, self.provider_id.company_id.name or "Odoo")

    def _bmaglass_redirect_url(self):
        """Absolute buyer return URL for the hosted pay page.

        The transaction's landing_route (set by core's payment route,
        e.g. /payment/confirmation?tx_id=..&access_token=..) is where
        Odoo expects the buyer back; the hosted page offers it as the
        way back to the store. None when no landing route was set (the
        link is then created without a return URL, as before).
        """
        self.ensure_one()
        return self.provider_id._bmaglass_landing_url(self.landing_route)

    def _bmaglass_create_link(self):
        """Create the per-transaction payment link via the developer API.

        One link per transaction: a stored link code is reused, so a
        re-rendered payment page (or a retried checkout step) never
        mints a second payable link for the same order — a payment on
        an orphaned earlier link could never settle, because the
        webhook matches on the transaction's current link code.
        """
        self.ensure_one()
        if self.bmaglass_link_code:
            return {"id": self.bmaglass_link_id, "link_code": self.bmaglass_link_code}
        provider = self.provider_id
        client = provider._bmaglass_client()
        try:
            link = client.create_payment_link(
                title=self._bmaglass_link_title(),
                amount=self.amount,
                currency=self.currency_id.name,
                description="Odoo payment %s" % self.reference,
                expires_at=(datetime.now(timezone.utc) + timedelta(days=7)).isoformat(),
                redirect_url=self._bmaglass_redirect_url(),
            )
        except BMaGlassError as e:
            if e.code != "network_error":
                raise
            # The response was cut — the link may already exist
            # server-side (a POST whose response never arrives still
            # settles on BMaGlass; proven live: a cut simulate-payment
            # still counted). Recover it by title instead of minting
            # a second payable link for the same order.
            link = self._bmaglass_recover_link(client)
            if not link:
                raise
            _logger.info("BMaGlass: recovered link %s for %s after a cut response.",
                         link.get("link_code"), self.reference)
        self.write({
            "bmaglass_link_id": link.get("id"),
            "bmaglass_link_code": link.get("link_code"),
            "provider_reference": link.get("link_code"),
        })
        return link

    def _bmaglass_recover_link(self, client):
        """Find the link this transaction's cut create call already
        made: same title, same amount, same currency. None when there
        is no such link (or the list call itself fails)."""
        self.ensure_one()
        try:
            links = client.list_payment_links()
        except BMaGlassError as e:
            _logger.warning("BMaGlass link recovery failed for %s: %s",
                            self.reference, e.user_message())
            return None
        for link in links or []:
            if link.get("title") != self._bmaglass_link_title():
                continue
            if str(link.get("currency") or "").upper() != self.currency_id.name:
                continue
            try:
                if round(float(link.get("amount")), 2) != round(float(self.amount), 2):
                    continue
            except (TypeError, ValueError):
                continue
            return link
        return None

    def _get_specific_rendering_values(self, processing_values):
        """Redirect flow: send the buyer to the hosted BMaGlass page.

        NOTE (16.0): this variant has no Mobile Money method — Odoo
        16.0 predates the payment.method framework, so there is no
        second method to offer. MoMo is available on the 17.0–20.0
        variants; 16.0 takes wallet-link payments only.
        """
        res = super()._get_specific_rendering_values(processing_values)
        if self.provider_code != "bmaglass":
            return res
        try:
            link = self._bmaglass_create_link()
        except (BMaGlassError, UserError) as e:
            # Plain language for the buyer — never a raw API response
            # and never a checkout crash (a provider with no key, or a
            # refused key, lands here instead of 500ing the route).
            message = e.user_message() if isinstance(e, BMaGlassError) else str(e)
            self._set_error(message)
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

    # -- settlement: webhook first, reconciliation as fallback --------------
    def _bmaglass_settle_from_paid_event(self, data):
        """Settle this transaction from a verified payment_link.paid event.

        The controller HMAC-verified the delivery; paid_event_matches
        cross-checks link code, currency and amount before _set_done()
        runs. _set_done() is state-guarded, so a duplicate delivery —
        or the reconciliation cron racing the same payment — can never
        settle twice. Returns True when this transaction is settled
        after the call; False declines the event (the controller then
        falls back to _bmaglass_check_paid()).
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
        """Settle this transaction if its link shows the money landed.

        Settlement rule, same as the other connectors: payment_count
        has grown AND total_collected covers the transaction amount.
        _set_done() is state-guarded, so repeat runs can't double-settle.
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
                self._set_done()
                self._bmaglass_log(
                    "BMaGlass Pay payment confirmed by reconciliation — link %s, collected %s %s."
                    % (self.bmaglass_link_code, "{:,.2f}".format(collected), self.currency_id.name)
                )
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
