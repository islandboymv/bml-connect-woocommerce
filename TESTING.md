# Testing

This documents the verified end-to-end test of the gateway against the **BML
Connect sandbox (UAT)** environment. Every path below was exercised against the
live sandbox and confirmed working.

## Prerequisites

- Plugin installed and activated on a WooCommerce site reachable over public HTTPS.
- **WooCommerce → Settings → Payments → BML Connect** configured with:
  - Sandbox mode **ON**
  - A valid sandbox **Application ID** and secret **API Key**
- Webhook URL set in the BML merchant dashboard (sandbox app):

  ```
  https://your-site.com/?wc-api=bmlc_webhook
  ```

## UAT test cards

Issued by BML for the sandbox environment (no real money is moved):

| Card | Number | Expiry | CVV | 3DS |
|------|--------|--------|-----|-----|
| Mastercard | 5506 9001 4010 0107 | 01/39 | 100 | Frictionless |
| Visa | 4440 0000 0990 0010 | 10/30 | 467 | 3DS challenge |

On the 3DS challenge page (Mastercard ACS emulator), choose
**`AUTHENTICATED` — (Y) Authentication/Account Verification Successful** and submit.

## Verified scenarios

| # | Scenario | Expected result | Status |
|---|----------|-----------------|--------|
| 1 | Create transaction (`process_payment`) | Order gets a BML transaction id; buyer is redirected to BML | ✅ |
| 2 | Money conversion | MVR 200.00 sent to BML as `20000` laari | ✅ |
| 3 | BML hosted page | Correct merchant + amount shown | ✅ |
| 4 | Frictionless card (Mastercard) | Charge → redirect back → order `processing` / paid | ✅ |
| 5 | 3DS card (Visa) | ACS challenge → `AUTHENTICATED` → redirect back → order `processing` / paid | ✅ |
| 6 | Return handler (`bmlc_return`) | Re-queries BML, never trusts the redirect; completes only on `CONFIRMED` | ✅ |
| 7 | Webhook — valid signature | `200 ok`; order completed | ✅ |
| 8 | Webhook — bad signature | `401`; order left untouched | ✅ |
| 9 | Idempotency | Return handler **and** live webhook both fire → exactly one completion, one order note | ✅ |

## Manual smoke test

1. Add a product to the cart and check out, selecting **Card / BML MobilePay**.
2. On BML's hosted page: accept the terms, choose **Card**, enter a UAT test card.
3. If a 3DS page appears, select `AUTHENTICATED` and submit.
4. Confirm you land on **"Thank you. Your order has been received."**
5. In **WooCommerce → Orders**, the order should be **Processing** with an order
   note: `BML payment confirmed (transaction <id>)`.

## Simulating the webhook directly

Confirms the server-to-server path without a browser. The signature is
`sha256(nonce + timestamp + apiKey)`; the endpoint rejects anything else with `401`.

```bash
NONCE="n-$(date +%s)"
TS="$(date +%s)"
APIKEY="<your sandbox api key>"
SIG="$(printf '%s' "${NONCE}${TS}${APIKEY}" | shasum -a 256 | cut -d' ' -f1)"

curl -i -X POST "https://your-site.com/?wc-api=bmlc_webhook" \
  -H "Content-Type: application/json" \
  -H "X-Signature-Nonce: ${NONCE}" \
  -H "X-Signature-Timestamp: ${TS}" \
  -H "X-Signature: ${SIG}" \
  -d '{"state":"CONFIRMED","transactionId":"<a real sandbox transaction id>"}'
```

A correct request returns `200 ok` and completes the matching order. A tampered
signature returns `401` and leaves the order unchanged.

## Going live

1. Replace the Application ID + API Key with **production** credentials.
2. Turn **OFF** Sandbox mode.
3. Set the webhook URL on your **production** BML app.
4. Run one small real transaction to confirm before announcing.
