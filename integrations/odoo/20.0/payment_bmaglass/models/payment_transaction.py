# -*- coding: utf-8 -*-
import logging
from datetime import datetime, timedelta, timezone
from urllib.parse import parse_qs, urlparse

from odoo import fields, models
from odoo.exceptions import UserError

from .bmaglass_api import (
    BMaGlassError, TEST_MODE_MESSAGE, momo_event_matches, paid_event_matches,
    payment_link_url,
)

_logger = logging.getLogger(__name__)


class PaymentTransaction(models.Model):
    _inherit = "payment.transaction"

    bmaglass_link_id = fields.Char(string="BMaGlass payment link ID", readonly=True)
    bmaglass_link_code = fields.Char(string="BMaGlass payment link code", readonly=True)
    bmaglass_momo_reference = fields.Char(
        string="BMaGlass mobile money reference", readonly=True,
        help="Platform reference (BMG-...) of the mobile money collection started "
             "for this transaction, if it was paid by mobile money.")

    # -- helpers ----------------------------------------------------------
    def _bmaglass_is_momo(self):
        """True when the buyer picked the Mobile Money method."""
        self.ensure_one()
        return self.payment_method_id.code == "bmaglass_momo"

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

    def _bmaglass_access_token(self):
        """The access token core attached to this transaction's landing
        route (parsed out of it), falling back to recomputing it from
        the same values the payment route used. Used to authenticate
        the mobile-money start/status routes for this transaction."""
        self.ensure_one()
        try:
            query = parse_qs(urlparse(self.landing_route or "").query)
            token = (query.get("access_token") or [None])[0]
            if token:
                return token
        except Exception:
            pass
        try:
            from odoo.addons.payment import utils as payment_utils
            try:
                return payment_utils.generate_access_token(
                    self.partner_id.id, self.amount, self.currency_id.id, env=self.env)
            except TypeError:
                return payment_utils.generate_access_token(
                    self.partner_id.id, self.amount, self.currency_id.id)
        except Exception:
            _logger.warning("BMaGlass: could not derive an access token for %s", self.reference)
            return None

    def _bmaglass_momo_payload(self, data):
        """The payment.data payload for a mobile money outcome: the
        transaction's own amount (never the link/collection lifetime
        total) so core's _validate_amount compares like with like."""
        self.ensure_one()
        return {
            "merchant_reference": self.reference,
            "reference": (data or {}).get("reference") or self.bmaglass_momo_reference,
            "status": str((data or {}).get("status") or "").lower(),
            "failure_reason": (data or {}).get("failure_reason"),
            "amount": float(self.amount),
            "base_amount": float(self.amount),
            "currency": self.currency_id.name,
            "source": "momo",
        }

    # -- checkout: wallet link ---------------------------------------------
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
        if not client.is_live:
            # Honest test mode, enforced at the point of no return
            # (see payment_provider.py for why 20.0 gates here rather
            # than on the provider's state): a test key may check the
            # connection and read wallets, but it can never take a
            # payment — sandbox keys use simulated money.
            raise BMaGlassError(0, "test_mode_read_only", TEST_MODE_MESSAGE)
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
        """Redirect flow: send the buyer to the hosted BMaGlass page
        (wallet method) or show the mobile-money phone form."""
        res = super()._get_specific_rendering_values(processing_values)
        if self.provider_code != "bmaglass":
            return res
        if self._bmaglass_is_momo():
            res.update({
                "bmaglass_momo": True,
                "bmaglass_tx_id": self.id,
                "bmaglass_access_token": self._bmaglass_access_token(),
                "bmaglass_amount": "{:,.2f}".format(float(self.amount)),
                "bmaglass_currency": self.currency_id.name,
                "bmaglass_phone": (self.partner_id.phone or "").strip(),
                "bmaglass_landing_route": self.landing_route or "/payment/confirmation",
            })
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
            # base_amount is the product amount; amount alone is the
            # total charged, which in buyer-pays mode includes the fee
            # and would fail core's exact-amount validation.
            raw = data.get("base_amount", data.get("amount"))
            return {
                "amount": float(raw),
                "currency_code": data.get("currency"),
            }
        except (TypeError, ValueError):
            return None

    def _apply_updates(self, payment_data):
        super()._apply_updates(payment_data)
        if self.provider_code != "bmaglass":
            return
        data = payment_data or {}
        source = data.get("source")
        if source == "momo" or (source is None and data.get("merchant_reference")):
            # Mobile money payload: a queued reconcile/status record,
            # or a webhook delivery. A failed collection fails the
            # transaction here (the only write-safe place to do so);
            # anything else settles from the event, else confirms
            # against the API before anything is marked paid.
            if str(data.get("status") or "").lower() == "failed":
                if self.state not in ("done", "cancel", "error"):
                    reason = data.get("failure_reason") or "the payment didn't go through"
                    self._set_error("Mobile money payment failed — %s." % reason)
                return
            if self._bmaglass_momo_settle_from_event(data):
                return
            self._bmaglass_momo_check_status()
            return
        if source != "reconcile" and self._bmaglass_settle_from_paid_event(data):
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
        the TRANSACTION's own amount — never the link's lifetime
        total_collected, which on an overpaid link (two payments on
        one link) would make core's exact-amount validation flip the
        settled transaction to error. _apply_updates() re-confirms
        against the API at processing time before _set_done().
        Exactly-once is preserved: _set_done() is state-guarded and
        processing is locked per transaction.
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
                    "amount": float(self.amount),
                    "base_amount": float(self.amount),
                    "currency": self.currency_id.name,
                    "payment_count": count,
                    "total_collected": collected,
                    "source": "reconcile",
                })
                return True
            return False
        return False

    # -- mobile money -------------------------------------------------------
    def _bmaglass_momo_create(self, phone, operator=None):
        """Start a mobile money collection for this transaction.

        The collection's merchant reference IS the transaction
        reference, so a retried start (double-click, back button)
        returns the original collection instead of charging the buyer
        twice. A collection already started is never started again.
        Mobile money collects in ZMW only, and only on a live key —
        sandbox keys use simulated money and can never take a payment.
        On Odoo 20 the platform reference is stored with the
        write-safe context (this runs from the module's own route,
        outside the processing queue) and a synchronous success is
        queued through _record() instead of settling directly.
        Returns the collection.
        """
        self.ensure_one()
        if self.bmaglass_momo_reference:
            return {"reference": self.bmaglass_momo_reference, "status": "pending"}
        if str(self.currency_id.name).upper() != "ZMW":
            raise BMaGlassError(
                0, "unsupported_currency",
                "Mobile money collects in ZMW. This transaction is in %s, so pay "
                "it with the BMaGlass Pay wallet method instead." % self.currency_id.name)
        provider = self.provider_id
        client = provider._bmaglass_client()
        if not client.is_live:
            raise BMaGlassError(0, "test_mode_read_only",
                                "Mobile money needs a production key (bmg_live_...).")
        collection = client.create_mobile_money_collection(
            amount=self.amount, phone=phone, operator=operator,
            reference=self.reference)
        self.with_context(payment_safe_write=True).write(
            {"bmaglass_momo_reference": collection.get("reference")})
        if (collection.get("status") or "").lower() == "successful":
            self._record(self._bmaglass_momo_payload(collection))
        return collection

    def _bmaglass_momo_settle_from_event(self, data):
        """Settle this transaction from a verified
        mobile_money.collection.completed event.

        Same shape as the wallet path: the controller HMAC-verified
        the delivery; momo_event_matches cross-checks the merchant
        reference, ZMW and the product amount before _set_done().
        Returns True when settled after the call. On Odoo 20 this runs
        only inside _apply_updates(), under core's locked, write-safe
        processing context (B12).
        """
        self.ensure_one()
        if self.state == "done":
            return True
        if self.state in ("cancel", "error"):
            return False
        ok, reason = momo_event_matches(
            data, self.reference, self.amount, self.currency_id.name)
        if not ok:
            _logger.info(
                "BMaGlass mobile_money.collection.completed declined for %s: %s",
                self.reference, reason)
            return False
        self._set_done()
        self._bmaglass_log(
            "BMaGlass Pay mobile money payment confirmed by webhook "
            "(mobile_money.collection.completed) — reference %s, %s ZMW."
            % (data.get("reference"),
               "{:,.2f}".format(float(data.get("base_amount", data.get("amount") or 0) or 0)))
        )
        return True

    def _bmaglass_momo_check_status(self):
        """Poll the collection; queue the terminal outcome for
        processing. Successful/failed collections are _record()ed so
        _apply_updates() performs the state change under core's
        locked, write-safe context; a still-pending collection leaves
        the transaction alone. Returns True when a terminal outcome
        was queued."""
        self.ensure_one()
        if self.state in ("done", "cancel", "error") or not self.bmaglass_momo_reference:
            return False
        client = self.provider_id._bmaglass_client()
        try:
            collection = client.get_mobile_money_collection(self.bmaglass_momo_reference)
        except BMaGlassError as e:
            _logger.warning("BMaGlass MoMo status check failed for %s: %s",
                            self.reference, e.user_message())
            return False
        status = str((collection or {}).get("status") or "").lower()
        if status in ("successful", "failed"):
            self._record(self._bmaglass_momo_payload(collection))
            return True
        return False

    def _bmaglass_reconcile_pending(self):
        """Cron entry point: sweep unsettled BMaGlass transactions.

        Settlement normally arrives instantly via webhook
        (payment_link.paid / mobile_money.collection.completed); this
        scheduled reconciliation is the fallback that settles any
        transaction whose delivery was missed.
        """
        providers = self.env["payment.provider"].search([("code", "=", "bmaglass")])
        txs = self.search([
            ("provider_id", "in", providers.ids),
            ("state", "in", ("pending", "draft")),
            ("create_date", ">", fields.Datetime.now() - timedelta(days=7)),
        ], limit=100)
        for tx in txs:
            try:
                if tx.bmaglass_momo_reference:
                    tx._bmaglass_momo_check_status()
                elif tx.bmaglass_link_code:
                    tx._bmaglass_check_paid()
            except Exception:  # one bad tx must not stop the sweep
                _logger.exception("BMaGlass reconcile failed for tx %s", tx.reference)
