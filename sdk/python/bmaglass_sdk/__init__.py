"""BMaGlass Pay Developer API client (Python).

Auth: an API key created in the BMaGlass Pay app. Keys carry their
environment: ``bmg_live_`` (legacy ``bmp_live_``) = production, real
money; ``bmg_sandbox_`` (legacy ``bmp_test_``) = sandbox — mainnet
formats, simulated money; sandbox payments settle only through the
simulate-payment endpoints. The environment comes from the key alone
(``client.environment``); nothing else can override it.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import random
import time
import uuid
from typing import Any, Callable, Optional
from urllib.parse import quote

import requests

DEFAULT_BASE = "https://hhxwwozpvgktuqutfkhc.supabase.co/functions/v1/developer-api"
SITE_BASE = "https://bmaglasspay.online"
SUPPORTED_CURRENCIES = ("ZMW", "USD")


def payment_link_url(link_code: str) -> str:
    """Hosted payment page URL for a link code."""
    return f"{SITE_BASE}/pay/{quote(link_code, safe='')}"


def assert_supported_currency(currency: str) -> str:
    """Guard: only currencies with wallets may move money (transfers,
    payment links). Anything else is refused — never converted silently.
    Invoices are NOT guarded: the API accepts any 3-letter invoice
    currency (a payment request, not a wallet move)."""
    c = str(currency or "").upper()
    if c not in SUPPORTED_CURRENCIES:
        raise BMaGlassError(0, "unsupported_currency",
                            f"BMaGlass Pay wallets are ZMW and USD. This is {c or '?'}, which has no "
                            "BMaGlass wallet — no exchange rate has been applied. Use ZMW or USD with BMaGlass Pay.")
    return c


def new_idempotency_key() -> str:
    """One fresh idempotency key per logical create. The same key is
    reused across that call's retries, so the server replays the
    original object instead of creating a second one."""
    return str(uuid.uuid4())


class BMaGlassError(Exception):
    def __init__(self, status: int, code: str, message: str):
        super().__init__(message)
        self.status = status
        self.code = code
        self.message = message


class BMaGlass:
    def __init__(self, api_key: str, base_url: str = DEFAULT_BASE, session: Optional[requests.Session] = None,
                 timeout: float = 30, max_retries: int = 2, retry_base_delay: float = 0.5,
                 sleep: Optional[Callable[[float], None]] = None):
        """Retries after a transient failure (``max_retries`` 2 by
        default — 3 attempts total). Only idempotent requests are ever
        retried: every GET/DELETE, and POSTs that carry an
        Idempotency-Key (payment-link / invoice / transfer creates —
        the SDK sends one per logical call — and mobile-money
        collections, keyed by reference / idempotency_key). A POST
        with no idempotency key (bitcoin/quote, simulate-payment,
        webhook create/test) is never retried: the server could not
        tell a retry from a second call. Retried failures are HTTP
        502/503/504 and transport failures (including timeouts).
        Backoff is exponential with full jitter: before retry N the
        client sleeps a uniform random delay in [0, min(4,
        retry_base_delay * 2**(N-1))] seconds. ``max_retries=0``
        disables retries entirely. ``sleep`` is a test hook (defaults
        to time.sleep)."""
        if not api_key or not (api_key.startswith("bmg_") or api_key.startswith("bmp_")):
            raise BMaGlassError(0, "invalid_api_key", "api_key must be a BMaGlass Pay API key (bmg_live_..., bmg_sandbox_..., or a legacy bmp_ key).")
        self.api_key = api_key
        self.mode = "live" if "_live_" in api_key else "test"
        self.environment = "production" if self.mode == "live" else "sandbox"
        self.base_url = base_url.rstrip("/")
        self.session = session or requests.Session()
        # Per-request timeout in seconds (default 30). A request that
        # does not settle in time fails as a typed BMaGlassError with
        # code "network_error" instead of hanging forever.
        self.timeout = timeout
        self.max_retries = max_retries
        self.retry_base_delay = retry_base_delay
        self.sleep = sleep or time.sleep
        self.connector = _Connector(self)
        self.invoices = _Invoices(self)
        self.wallets = _Wallets(self)
        self.transactions = _Transactions(self)
        self.transfers = _Transfers(self)
        self.payment_links = _PaymentLinks(self)
        self.mobile_money = _MobileMoney(self)
        self.bitcoin = _Bitcoin(self)
        self.webhooks = _Webhooks(self)
        self.settings = _Settings(self)

    def _retry_delay(self, attempt: int) -> float:
        """Backoff before retry ``attempt`` (1-based): uniform in
        [0, cap] seconds, cap doubling from retry_base_delay up to 4s."""
        return random.uniform(0, min(4.0, self.retry_base_delay * 2 ** (attempt - 1)))

    def _request(self, method: str, path: str, body: Any = None, headers: Optional[dict] = None) -> Any:
        # Retryable iff the request is provably safe to repeat: a read,
        # a delete, or a write carrying an Idempotency-Key (the server
        # replays the original object for a repeated key). Anything
        # else gets exactly one attempt — a blind retry could
        # double-settle.
        retryable = method in ("GET", "DELETE") or (headers and "Idempotency-Key" in headers)
        max_attempts = max(1, self.max_retries + 1) if retryable else 1
        attempt = 0
        while True:
            attempt += 1
            try:
                return self._request_once(method, path, body, headers)
            except BMaGlassError as e:
                transient = e.code == "network_error" or e.status in (502, 503, 504)
                if not transient or attempt >= max_attempts:
                    raise
                self.sleep(self._retry_delay(attempt))

    def _request_once(self, method: str, path: str, body: Any = None, headers: Optional[dict] = None) -> Any:
        hdrs = {"X-Api-Key": self.api_key, "Content-Type": "application/json"}
        if headers:
            hdrs.update(headers)
        try:
            res = self.session.request(method, f"{self.base_url}/{path}", headers=hdrs,
                                       data=json.dumps(body) if body is not None else None, timeout=self.timeout)
        except requests.RequestException as e:
            # Transport failures (DNS, connection refused, timeout) used
            # to leak out as raw requests exceptions. Every failure from
            # this client is a typed BMaGlassError.
            raise BMaGlassError(0, "network_error",
                                f"Couldn't reach BMaGlass Pay. Check the connection and try again. ({e})") from e
        try:
            parsed = res.json() if res.text else {}
        except ValueError:
            parsed = {}
        if not res.ok:
            raise BMaGlassError(res.status_code, parsed.get("error", "request_failed"),
                                parsed.get("message", f"Request failed with HTTP {res.status_code}"))
        return parsed.get("data", parsed)


class _Connector:
    """Connector configuration, authenticated by the key alone.

    Returns the environment, the business's country/currency/status,
    feature states (mobile_money is enabled only for businesses
    registered in Zambia with the feature switched on) and the
    settlement type — what a plugin renders its settings from.
    """
    def __init__(self, c: BMaGlass): self.c = c
    def config(self): return self.c._request("GET", "connector/config")


class _Invoices:
    def __init__(self, c: BMaGlass): self.c = c
    def list(self): return self.c._request("GET", "invoices")
    def get(self, invoice_id: str): return self.c._request("GET", f"invoices/{quote(invoice_id, safe='')}")
    def create(self, amount: float, idempotency_key: Optional[str] = None, **kwargs):
        """Create an invoice. Retried safely: one Idempotency-Key per
        logical call (caller-supplied, or auto-generated) is reused
        across retries, and the server replays the original invoice
        instead of creating a second one."""
        key = idempotency_key or kwargs.get("idempotency_key") or new_idempotency_key()
        return self.c._request("POST", "invoices", {"amount": amount, **kwargs},
                               {"Idempotency-Key": key})
    def simulate_payment(self, invoice_id: str):
        """Sandbox only: drive a sandbox invoice to paid (fires invoice.paid, flagged simulated)."""
        return self.c._request("POST", f"invoices/{quote(invoice_id, safe='')}/simulate-payment")


class _Wallets:
    def __init__(self, c: BMaGlass): self.c = c
    def list(self): return self.c._request("GET", "wallets")


class _Transactions:
    def __init__(self, c: BMaGlass): self.c = c
    def list(self, limit: int = 25): return self.c._request("GET", f"transactions?limit={max(1, min(100, limit))}")


class _Transfers:
    def __init__(self, c: BMaGlass): self.c = c
    def create(self, to_user_id: str, amount: float, currency: str = "ZMW",
               description: Optional[str] = None, recipient_name: Optional[str] = None,
               idempotency_key: Optional[str] = None):
        """Retried safely: one Idempotency-Key per logical call
        (caller-supplied, or auto-generated) is reused across retries,
        and the server replays the original transfer."""
        body = {"to_user_id": to_user_id, "amount": amount, "currency": assert_supported_currency(currency)}
        if description is not None: body["description"] = description
        if recipient_name is not None: body["recipient_name"] = recipient_name
        return self.c._request("POST", "transfers", body,
                               {"Idempotency-Key": idempotency_key or new_idempotency_key()})


class _PaymentLinks:
    def __init__(self, c: BMaGlass): self.c = c
    def list(self): return self.c._request("GET", "payment-links")
    def get(self, link_id: str):
        """One link by UUID id or link_code (the list caps at 100, newest first)."""
        return self.c._request("GET", f"payment-links/{quote(link_id, safe='')}")
    def create(self, title: str, fee_payer: Optional[str] = None, redirect_url: Optional[str] = None,
               idempotency_key: Optional[str] = None, **kwargs):
        """Create a payment link. ``fee_payer`` ("seller" | "buyer") overrides
        the merchant default from ``settings.get()`` for this link only —
        "seller": the fee comes out of what the seller receives; "buyer":
        the fee is added on top and the seller receives the full price.
        ``redirect_url`` is the buyer return URL the hosted pay page offers
        after payment. Other keyword arguments pass through to the API.
        Retried safely: one Idempotency-Key per logical call
        (caller-supplied, or auto-generated) is reused across retries,
        and the server replays the original link instead of creating a
        second one."""
        body: dict[str, Any] = {"title": title, **kwargs}
        if fee_payer is not None:
            if fee_payer not in ("seller", "buyer"):
                raise BMaGlassError(0, "invalid_fee_payer", "fee_payer must be 'seller' or 'buyer'.")
            body["fee_payer"] = fee_payer
        if redirect_url is not None:
            body["redirect_url"] = redirect_url
        if "currency" in body:
            body["currency"] = assert_supported_currency(body["currency"])
        key = idempotency_key or body.get("idempotency_key") or new_idempotency_key()
        return self.c._request("POST", "payment-links", body, {"Idempotency-Key": key})
    def attempts(self, link_id: str): return self.c._request("GET", f"payment-links/{quote(link_id, safe='')}/attempts")
    def simulate_payment(self, link_id: str):
        """Sandbox only: mark one simulated payment on a sandbox link (fires payment_link.paid, flagged simulated)."""
        return self.c._request("POST", f"payment-links/{quote(link_id, safe='')}/simulate-payment")


class _MobileMoney:
    """Mobile money collections on the business's connected account.

    Zambia / ZMW only. The key's environment selects which of the
    business's connections is used — a sandbox key runs against the
    sandbox connection (gateway test numbers; no real phone is ever
    charged), a production key against the production connection.
    """
    def __init__(self, c: BMaGlass): self.c = c

    def create_collection(self, amount: float, phone: str, operator: Optional[str] = None,
                          reference: Optional[str] = None, idempotency_key: Optional[str] = None):
        """Start a collection: the customer approves the prompt on
        their phone (operator: "mtn" | "airtel" | "zamtel"; detected
        from the number when omitted). ``reference`` is your own
        reference for the collection and doubles as the idempotency
        key — repeating a create with the same reference returns the
        original collection instead of charging the customer twice.
        Requires the mobile_money:create scope. Retried safely: the
        effective key is the caller's idempotency_key, else their
        reference, else a fresh UUID sent as idempotency_key — the same
        key rides every retry of this logical call."""
        body: dict[str, Any] = {"amount": amount, "phone": phone}
        if operator is not None: body["operator"] = operator
        if reference is not None: body["reference"] = reference
        if idempotency_key is not None: body["idempotency_key"] = idempotency_key
        key = idempotency_key or reference or new_idempotency_key()
        if idempotency_key is None and reference is None: body["idempotency_key"] = key
        return self.c._request("POST", "mobile-money/collections", body, {"Idempotency-Key": key})

    def get_collection(self, reference: str):
        """A collection's current state, by platform reference
        (BMG-...) or your own reference. Polling refreshes from the
        rail; a settled collection credits the business's ZMW wallet
        in full and fires mobile_money.collection.completed.
        Requires the mobile_money:read scope."""
        return self.c._request("GET", f"mobile-money/collections/{quote(reference, safe='')}")


class _Settings:
    def __init__(self, c: BMaGlass): self.c = c
    def get(self): return self.c._request("GET", "settings")


class _Bitcoin:
    def __init__(self, c: BMaGlass): self.c = c
    def balance(self): return self.c._request("GET", "bitcoin/balance")
    def deposit_address(self): return self.c._request("GET", "bitcoin/deposit-address")
    def quote(self, side: str, amount_zmw: Optional[float] = None, amount_sats: Optional[float] = None):
        """Quote only — execution happens in the app. Not retried: each
        call mints a quote row (no idempotency key), so a retry after a
        503 would mint a second quote."""
        if side not in ("buy", "sell"):
            raise BMaGlassError(0, "invalid_side", "side must be 'buy' or 'sell'.")
        body: dict[str, Any] = {"side": side}
        if amount_zmw is not None: body["amount_zmw"] = amount_zmw
        if amount_sats is not None: body["amount_sats"] = amount_sats
        return self.c._request("POST", "bitcoin/quote", body)


class _Webhooks:
    def __init__(self, c: BMaGlass): self.c = c
    def list(self): return self.c._request("GET", "webhooks")
    def create(self, url: str, events: Optional[list] = None):
        body: dict[str, Any] = {"url": url}
        if events is not None: body["events"] = events
        return self.c._request("POST", "webhooks", body)
    def remove(self, webhook_id: str): return self.c._request("DELETE", f"webhooks/{quote(webhook_id, safe='')}")
    def test(self, webhook_id: str): return self.c._request("POST", f"webhooks/{quote(webhook_id, safe='')}/test")
    def deliveries(self): return self.c._request("GET", "webhook-deliveries")


def verify_webhook_signature(raw_body: bytes, signature_header: str, secret: str) -> bool:
    """Verify an X-BMaGlass-Signature header ("sha256=<hex>") over the exact raw body."""
    if not signature_header or not signature_header.startswith("sha256=") or not secret:
        return False
    expected = "sha256=" + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature_header)


def parse_webhook_event(raw_body: bytes, signature_header: str, secret: str) -> Optional[dict]:
    if not verify_webhook_signature(raw_body, signature_header, secret):
        return None
    try:
        return json.loads(raw_body.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        return None
