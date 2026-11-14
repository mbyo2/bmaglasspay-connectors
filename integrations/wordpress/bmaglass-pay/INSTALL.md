# BMaGlass Pay for WooCommerce — Install

Accept BMaGlass Pay payments in WooCommerce. Buyers pay wallet-to-wallet
on a hosted BMaGlass payment page, and the money lands in the same
BMaGlass wallets (ZMW / USD) as your BMaGlass Pay app account. When the
payment link is paid, a signed webhook completes the order, with API
reconciliation as the fallback. Stores whose BMaGlass account has mobile
money connected also get a Mobile Money gateway (MTN / Airtel / Zamtel).

Requirements: WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+. Store currency
must be ZMW or USD.

## Install

1. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**.
2. Choose this zip file (`bmaglasspay-woocommerce-1.0.0.zip`) and click
   **Install Now**, then **Activate Plugin**.
3. In BMaGlass Pay, go to **Business → API keys** and create an API key
   with the `wallets:read`, `payment_links:read` and
   `payment_links:write` scopes. Copy the key.
4. In WordPress, go to **WooCommerce → Settings → Payments →
   BMaGlass Pay**, paste the API key, enable the gateway, and save.
5. Click **Test connection** — you should see your account's wallets
   and balances.
6. Click **Register webhook** so BMaGlass can tell this site the moment
   a payment link is paid.

That's it — checkout will offer BMaGlass Pay, and paid orders complete
automatically.

## Notes

- Test keys (`bmp_test_...`) are read-only by design across BMaGlass Pay
  and can check the connection and read wallets, but can never take a
  payment. Use a live key and a small real order to test checkout.
- Buyers pay from a BMaGlass Pay wallet on the hosted payment page;
  guest card checkout is not offered.
- Payment-link settlement follows your BMaGlass Pay account's standard
  link settlement terms (shown in the app). The plugin adds no charges
  of its own.
- Uninstalling removes the gateway settings (which hold the API key and
  webhook secret). Order records are kept — they are the store's own
  payment audit trail.

Support: https://bmaglasspay.online/developers
