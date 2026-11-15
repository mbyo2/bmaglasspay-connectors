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
