# bmaglass-sdk (Python)

Python client for the BMaGlass Pay Developer API. Install: `pip install ./sdk/python`.

```python
from bmaglass_sdk import BMaGlass, verify_webhook_signature

client = BMaGlass("bmg_sandbox_...")  # sandbox keys use simulated money
print(client.wallets.list())
print(client.bitcoin.quote("buy", amount_zmw=100))

# Webhook handler:
ok = verify_webhook_signature(request.data, request.headers["X-BMaGlass-Signature"], webhook_secret)
```

Tests: `python3 -m unittest discover sdk/tests -v` from the repo root.
Errors raise `BMaGlassError` with `.status` and `.code` (e.g. `test_mode_read_only`).
Transport failures and requests that exceed the 30s timeout (overridable
via the `timeout` constructor argument) also raise `BMaGlassError`
(`network_error`). Transfers and payment links accept ZMW and USD only
(anything else raises `unsupported_currency`); invoices accept any
3-letter currency. Use `payment_link_url(link_code)` for the
buyer-facing hosted pay page URL.

## Retries

Transient 502/503/504 (and transport failures) are retried automatically: up to 3 attempts total,
exponential backoff with full jitter (base 0.5 s, see `max_retries` / `retry_base_delay` in the
constructor). Only idempotent requests are retried — every GET/DELETE, and POSTs carrying an
`Idempotency-Key`. Payment-link, invoice and transfer creates always send one key per logical call
(pass your own `idempotency_key`, or let the SDK generate one) and reuse it across retries, so a
retried create replays the original object instead of creating a second one. `bitcoin/quote`,
`simulate_payment` and webhook create/test are never retried (no idempotency key). Disable with
`BMaGlass(..., max_retries=0)`.
