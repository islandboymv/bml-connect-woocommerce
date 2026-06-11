# BML Connect for WooCommerce

A clean, dependency-free WooCommerce payment gateway for **Bank of Maldives (BML) Connect**. Brand-neutral and reusable — drop it into any WooCommerce project and configure per-merchant in the settings.

> ⚠️ This is an independent, third-party plugin. It is not affiliated with or endorsed by Bank of Maldives.

## Features

- **Redirect-based payments** — Card (Visa/Mastercard), BML MobilePay, and other BML methods
- **Signature-verified webhooks** — `sha256(nonce + timestamp + apiKey)`, compared with a timing-safe `hash_equals()`
- **Trustworthy confirmation** — the buyer-return handler re-queries BML for the authoritative state instead of trusting the redirect
- **Idempotent** — webhook and return paths can't double-complete an order
- **Correct money handling** — amounts in laari (integer), no floating-point
- **HPOS compatible** — High-Performance Order Storage (custom order tables)
- **Blocks compatible** — works with the WooCommerce Checkout block and classic shortcode checkout
- **No external dependencies** — built on the WordPress HTTP API (no Guzzle/Composer)
- **Built-in connection test** — a "Test BML connection" button in settings validates your credentials before going live
- **Self-updating** — installs updates straight from GitHub Releases via the WordPress Plugins screen

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |
| WooCommerce | 7.0 |

## Installation

1. Copy the `bml-connect-woocommerce` folder into `wp-content/plugins/` (or upload the zip via **Plugins → Add New → Upload**).
2. Activate **BML Connect for WooCommerce**.
3. Go to **WooCommerce → Settings → Payments → BML Connect**.

## Configuration

1. Enter your **Application ID** and secret **API Key** from the BML merchant dashboard.
2. Leave **Sandbox mode** ON for development; turn it OFF (with production keys) to go live.
3. Copy the **Webhook URL** shown on the settings screen into your app's webhook setting in the BML merchant dashboard:

   ```
   https://your-site.com/?wc-api=bmlc_webhook
   ```

## How it works

```
Checkout ──> process_payment() ──> create BML transaction ──> redirect buyer to BML
                                                                     │
   ┌─────────────────────────────────────────────────────────────────┘
   │
   ├─ Buyer returns ──> handle_return() ──> re-query BML ──> CONFIRMED? ──> complete order
   │
   └─ BML webhook ───> handle_webhook() ──> verify signature ──> CONFIRMED? ──> complete order
                                                                     (idempotent)
```

The webhook is the reliable confirmation path — it fires even if the buyer closes the browser before being redirected back.

## Environments

| Environment | Base URL |
|---|---|
| Sandbox (UAT) | `https://api.uat.merchants.bankofmaldives.com.mv/public` |
| Production | `https://api.merchants.bankofmaldives.com.mv/public` |

## Notes

- **MVR only.** MIB (Maldives Islamic Bank) cards cannot pay through BML Connect — offer a manual bank-transfer option alongside if you serve MIB customers.
- **Local development** needs a public HTTPS webhook URL. Use a tunnel (Cloudflare Tunnel, ngrok, etc.) or test confirmations through the BML sandbox.
- Enable **Debug logging** in settings to trace API calls and webhooks under **WooCommerce → Status → Logs** (source: `bml-connect`). The raw API key is never logged.

## License

GPL-2.0-or-later.
