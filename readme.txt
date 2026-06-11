=== BML Connect for WooCommerce ===
Contributors: islandboy
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 10.7
Stable tag: 1.0.0
License: GPLv2 or later

First-party WooCommerce payment gateway for Bank of Maldives (BML) Connect.

== Description ==

A clean, dependency-free BML Connect gateway for WooCommerce. Reusable across
projects — no merchant-specific branding or hardcoding.

Features:
* Redirect-based payments (Card / Visa / Mastercard, BML MobilePay, etc.)
* Signature-verified webhooks (sha256, timing-safe) — the reliable confirmation path
* Buyer-return handler re-queries BML before completing (never trusts the redirect)
* Idempotent order completion (webhook + return can't double-process)
* Amounts handled in laari (integer) — no floating-point money
* HPOS (High-Performance Order Storage) compatible
* WooCommerce Blocks (block checkout) compatible
* Built on the WordPress HTTP API — no Guzzle/Composer

== Configuration ==

1. WooCommerce → Settings → Payments → BML Connect.
2. Enter your Application ID and secret API Key.
3. Leave "Sandbox mode" ON for development; turn OFF (with production keys) to go live.
4. Copy the shown Webhook URL into your app's webhook setting in the BML
   merchant dashboard (dashboard.merchants.bankofmaldives.com.mv).

Webhook URL format:
  https://your-site.com/?wc-api=bmlc_webhook

== Notes ==

* MVR only. MIB (Maldives Islamic Bank) cards cannot pay via BML Connect — offer a
  manual bank-transfer option alongside if you serve MIB customers.
* Local development needs a public HTTPS webhook URL (use a tunnel like Cloudflare
  Tunnel / ngrok) or test confirmations via the BML sandbox.

== Changelog ==

= 1.0.0 =
* Initial release.
