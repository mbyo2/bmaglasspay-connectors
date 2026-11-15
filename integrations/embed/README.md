# BMaGlass Pay button — universal embed

A tiny, framework-free script that puts a **Pay with BMaGlass Pay**
button on any website — including closed platforms like Wix,
Squarespace and Webflow — bound to a BMaGlass **payment link**.

## Why a payment link (and never an API key)

The button needs something public to point at. A payment link is
exactly that: a public hosted payment page
(`https://bmaglasspay.online/pay/<code>`) where the buyer pays from
their own BMaGlass wallet. Your **API key is a secret** and must
never appear in a web page — the script actively refuses to render
if it detects a `bmg_...` / `bmp_...` API key in its configuration.

## Steps

1. **Create a payment link** — in the BMaGlass Pay app (Payment
   Links) or via the developer API (`POST /payment-links`). A
   production key makes a live link; a sandbox key makes a sandbox
   link (the hosted pay page refuses sandbox links — they can only
   be settled through the API's simulate-payment endpoint, so use
   them to test the button, never to take a real payment). Set the
   exact amount and currency — ZMW or USD only.
2. **Add the placeholder** where the button should appear:

   ```html
   <div class="bmaglass-pay-button"
        data-link="https://bmaglasspay.online/pay/YOUR_LINK_CODE"></div>
   ```

   Options: `data-code="YOUR_LINK_CODE"` instead of `data-link`,
   `data-label="Pay K250 with BMaGlass"`, `data-target="_blank"`.
3. **Include the script once per page:**

   ```html
   <script src="bmaglass-pay-button.js" async></script>
   ```

   Host `bmaglass-pay-button.js` anywhere static (your own site,
   a CDN, the platform's file upload) and use its URL as `src`.

## Per platform

- **Wix:** add an "Embed HTML" element, paste the placeholder div +
  script tag (point `src` at the hosted file URL).
- **Squarespace:** add a Code block with the same snippet.
- **Webflow:** add an Embed element with the same snippet.
- **WordPress:** a Custom HTML block, or the WooCommerce plugin in
  `integrations/wordpress` for full checkout integration.
- **Static sites:** paste both snippets into the HTML.

## What the buyer experiences

Click → the hosted BMaGlass payment page → they sign in (or sign up)
and pay wallet-to-wallet → the money lands in the same BMaGlass
wallet the link belongs to. For order confirmation on platforms
with webhooks/cron, use the developer API's reconciliation pattern
(see the WooCommerce/Odoo connectors); on closed platforms, the
app's payment-link history and notifications are your record.

## If no button appears

The script renders nothing for a bad placeholder — by design it
never renders a broken button — so check the browser console: an
invalid `data-link` / `data-code` logs an error there, and an API
key anywhere in the configuration is refused with an error too.
Common causes: a non-`https://bmaglasspay.online/pay/<code>` URL,
a sandbox link's code pasted by mistake where a live link was
meant (the button still renders — the hosted page itself refuses
sandbox links), or the script tag pointing at a file that is not
served (the script must be reachable at its `src` URL).

## Verification in this repo

`node integrations/embed/test-embed.mjs` — minimal-DOM tests:
renders for valid links, `data-code`/label/target handling, and
the two refusals (API-key-in-config, foreign URL). `demo.html` is a
runnable example page.
