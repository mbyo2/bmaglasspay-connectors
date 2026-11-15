=== BMaGlass Pay for WooCommerce ===
Contributors: bmaglasspay
Tags: payments, woocommerce, bmaglass, zambia, kwacha
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Accept BMaGlass Pay payments in WooCommerce. Buyers pay wallet-to-wallet on a hosted BMaGlass payment page; money lands in the same BMaGlass wallets (ZMW / USD) as your BMaGlass Pay app account.

== Description ==

BMaGlass Pay for WooCommerce connects your store to your existing BMaGlass Pay account through the BMaGlass Pay developer API — same account, same wallets, no separate merchant balance.

How it works:

1. At checkout the plugin creates a BMaGlass payment link for the exact order total and sends the buyer to the hosted BMaGlass payment page.
2. The buyer pays from their BMaGlass Pay wallet.
3. When the link is paid, BMaGlass sends a signed payment_link.paid webhook and the plugin marks the order paid immediately — exactly once. (Reconciliation also runs every 5 minutes, plus an immediate check when the buyer returns, plus a per-order "Check BMaGlass payment" action, as a fallback for any missed delivery.)

Requirements and honest limits:

* Store currency must be ZMW or USD — those are the currencies BMaGlass wallets hold. The plugin refuses other currencies plainly; it never invents an exchange rate.
* A live API key (bmg_live_...) is required for the hosted-link gateway: the hosted pay page refuses sandbox links, so a sandbox key can check the connection, read wallets, create links and simulate payments, but can never take a real payment that way. Mobile money is different — a sandbox key (bmg_sandbox_...) runs mobile-money checkout end to end against the sandbox connection (the gateway's test numbers; no real phone is ever charged), so test the Mobile Money gateway with a sandbox key before switching to a production key.
* Buyers pay from a BMaGlass Pay wallet on the hosted payment page; guest card checkout is not offered.

Webhooks: the plugin registers an HMAC-SHA256-verified webhook endpoint with BMaGlass (one click in the gateway settings). Signed webhook.test events prove connectivity, and every signed event is logged. The payment_link.paid event completes orders the moment a link is paid; API reconciliation remains as the fallback.

Compatibility (declared in the plugin the documented way): HPOS (High-Performance Order Storage) is supported — the plugin uses only WooCommerce's CRUD order APIs and order meta, never direct post/postmeta access — and the gateway registers for the Cart/Checkout Blocks (the default checkout since WooCommerce 10.6) as well as classic checkout. Declared range: WordPress 6.0+, WooCommerce 7.0 – 11.2, PHP 7.4+.

== Installation ==

1. Copy the `bmaglass-pay` folder into `wp-content/plugins/` (or zip it and use Plugins → Add New → Upload).
2. Activate "BMaGlass Pay for WooCommerce".
3. Go to WooCommerce → Settings → Payments → BMaGlass Pay.
4. Create an API key in BMaGlass Pay (Business settings or the API page) with the `wallets:read`, `payment_links:read` and `payment_links:write` scopes, paste it in, enable the gateway, and save.
5. Click "Test connection" — you should see your account's wallets and balances.
6. Click "Register webhook" to point BMaGlass at this site and store the signing secret.
7. Set your store currency to ZMW or USD and place a real order to see the flow end to end.

== Frequently Asked Questions ==

= Do I need a separate BMaGlass merchant account? =
No. The API key belongs to your normal BMaGlass Pay account; payments settle into the same ZMW/USD wallets you see in the app.

= Why can't I test the hosted checkout with a test key? =
BMaGlass Pay sandbox keys run on simulated money: they can create payment links and drive them to paid through the simulate-payment endpoints, but the hosted pay page refuses sandbox links, so a sandbox key can never take a payment from a buyer there. Use a live key — and a small real order — to test the hosted checkout.

= Can I test mobile money checkout with a test key? =
Yes. A sandbox key runs mobile-money checkout end to end against your sandbox mobile-money connection (the gateway's test numbers: MTN 0961111111 succeeds, 0962222222 has insufficient funds; Airtel 0971111111 succeeds, 0972222222 has the wrong PIN). No real phone is ever charged. A failed collection can be retried from the same order — the retry starts a fresh collection, so the buyer is never charged twice for one order.

= How does the plugin know an order was paid? =
BMaGlass tells it: the signed payment_link.paid webhook fires when the payment link is paid, and the plugin completes the order immediately, exactly once, with the link code as the reference. As a fallback it also reconciles — every 5 minutes (and on the buyer's return, and on demand from the order screen) it checks the order's payment link via the API and completes any order whose delivery was missed.

= Which fees apply? =
Payment-link settlement follows your BMaGlass Pay account's standard link settlement terms (shown in the app). The plugin adds no fees of its own.

== Changelog ==

= 1.2.0 =
* New: Mobile Money gateway — MTN / Airtel / Zamtel collections on the merchant's own connected mobile-money account, offered only when the merchant's BMaGlass account reports it available. Buyer-pays fee disclosure (order total + 1% service charge) on classic and Blocks checkout; the order note always quotes the amount actually charged.
* New: mobile-money checkout works with a sandbox key (sandbox connection + test numbers, no real phone charged). The hosted-link gateway still needs a live key — the hosted pay page refuses sandbox links.
* Fix: a failed mobile-money collection can now be retried from the same order — the retry starts a fresh collection under a fresh reference, so it is never blocked by the failed one. (Before, the order stayed failed with no way to re-pay it.)
* Fix: retrying an order whose payment link expired (7 days) now creates a fresh link instead of sending the buyer to the dead one; the link expiry is stored on the order.
* Fix: a payment-link create that returns no link code now fails checkout instead of redirecting the buyer to an empty hosted page.
* Fix: the reconciler now says so on the order (once) when the account has more than 100 payment links and the API's 100-newest list cannot confirm the order's link, instead of silently standing down.
* Fix: uninstall now retires the registered webhook on the BMaGlass account and removes both gateways' settings (the mobile-money settings were left behind).
* Fix: a failed mobile-money availability read is no longer cached for 5 minutes; the next checkout retries it.
* Fix: buyer-typed phone numbers are normalised (spaces and the +260 prefix) before the collection is started.
* Fix: a network failure no longer shows the raw connection error to the buyer.

= 1.1.1 =
* Fix: re-registering the webhook now retires the previous registration first, so a site migration no longer leaves the old webhook active on the BMaGlass account (every event would otherwise be delivered to the stale URL as well). Found in install-level testing (WordPress 7.1 / WooCommerce 11.2).

= 1.1.0 =
* Checkout Blocks support: the gateway now registers a Blocks payment method type, so it appears in the Cart/Checkout block checkout (default since WooCommerce 10.6) as well as classic checkout. Server-side processing is unchanged — the block routes to the same process_payment().
* HPOS + cart_checkout_blocks compatibility declared via FeaturesUtil (the plugin already used only CRUD order APIs).
* Declared tested range updated: WooCommerce up to 11.2, WordPress up to 7.1.

= 1.0.0 =
* First release: gateway, hosted payment-link checkout, reconciliation, HMAC-verified webhook endpoint, connection tools.
