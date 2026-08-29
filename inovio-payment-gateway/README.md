# Inovio Payment Gateway — WooCommerce

Tokenized direct-post card checkout for WooCommerce, on the Inovio gateway.
**The card number never reaches the WordPress server.**

Ported from the PrestaShop module (`repos/inovio-gateway-prestashop`), which
carries the full design rationale in
[`docs/prestashop-integration-design.md`](../../../docs/prestashop-integration-design.md).

## Requirements

| | |
|---|---|
| WordPress | **6.0+** |
| WooCommerce | **7.0+** (Block Checkout support needs **8.3+**) |
| PHP | **8.0+** |
| PHP extensions | **`bcmath`** (required), `curl`, `json` |
| From Inovio | API username, API password, Site ID, **Site Key**, Gateway Product ID |

`bcmath` is required, not optional. The SDK's `Money` type does every decimal
calculation through it so amounts never touch a binary float. Installation
fails without it.

---

## What you get

| Capability | Support |
|---|---|
| Sale | Authorize and capture in one step at checkout. |
| Authorize only | Reserve funds, capture later from the order screen. |
| Capture | Admin order action when the payment action is authorize-only. |
| Void | Reverse an uncaptured authorization, from the order screen. |
| Refund | Full **and partial**, through WooCommerce's own admin refund UI (`process_refund()`). Settlement-aware — see below. |
| Saved cards | Native `WC_Payment_Tokens` — WooCommerce's own vault. Unlike PrestaShop and OpenCart, Woo has a token API, so saved cards appear in the shopper's account and at checkout with no custom UI. |
| 3-D Secure | Device-data collection and challenge, two-token flow. |
| Checkout | **Both** the classic `[woocommerce_checkout]` shortcode **and** the Block Checkout (`woocommerce/checkout`) — a default WooCommerce install works out of the box. |

One gap to know about up front: a shopper can **use** an existing saved card on
either checkout type, but can only **save a new** card on the classic checkout.
See "Known gaps" at the end.

---

## Install

1. Copy the `inovio-payment-gateway/` directory into `wp-content/plugins/`, or
   upload the ZIP through **Plugins → Add New → Upload Plugin**.
2. Activate it:

   ```bash
   wp plugin activate inovio-payment-gateway
   ```

   or through **Plugins** in wp-admin.
3. Configure it under **WooCommerce → Settings → Payments → Inovio**.

---

## Configuration

### Gateway credentials

| Setting | Required | Meaning |
|---|---|---|
| **Enable/Disable** | Yes | Turns the payment method on. |
| **Title** | No | The payment method name shoppers see at checkout. Defaults to "Credit card". |
| **Description** | No | Shown beneath the payment method name at checkout. |
| **API Username** | Yes | Gateway `REQ_USERNAME`. |
| **API Password** | Yes | Gateway `REQ_PASSWORD`. |
| **Site ID** | Yes | Gateway `SITE_ID`. |
| **Merchant Account ID** | No | `MERCH_ACCT_ID`. Leave empty to let the gateway distribute by currency/country. |
| **Site Key** | Yes | See below. Not the API password. |
| **Gateway Product ID** | Yes | See below. Not a WooCommerce SKU. |
| **Gateway Endpoint** | No | The `pmt_service.cfm` transaction URL. The tokenization and 3-D Secure endpoints are **derived from it**, exactly as the SDK derives them, so the three cannot drift apart. Leave it at the default for production. |

**Site Key** is a **separate per-site HMAC secret issued by Inovio support**.
It is **not** the API password, and it is not something you can generate. It is
used only to sign the browser's tokenization request. Without it the browser
cannot tokenize and checkout fails with **error 121**.

**Gateway Product ID** (`LI_PROD_ID`) is a product registered on the *gateway*,
not a product or SKU from your WooCommerce catalogue. The whole order bills as
one line item under it.

Until API Username, API Password, Site ID, Site Key and Gateway Product ID are
all filled in, `is_configured()` returns false and the method hides itself at
checkout rather than presenting a card form it cannot process.

### Payment behaviour

| Setting | Required | Meaning |
|---|---|---|
| **Payment Action** | Yes | *Sale* charges at checkout. *Authorize only* reserves the funds and leaves the order on hold until you capture it from the order screen. |
| **3-D Secure** | No | Enables 3-D Secure authentication. Requires a 3DS-configured merchant account. Off by default. |
| **Saved Cards** | No | Lets logged-in shoppers save cards for reuse. On by default. Stores the gateway's own card references only — never a card number. |
| **Statement Descriptor** | No | `PMT_DESCRIPTOR` — what appears on the cardholder's statement. **See the warning below.** |
| **Descriptor Phone** | No | `PMT_DESCRIPTOR_PHONE` — support number shown alongside the descriptor. |
| **Debug Logging** | No | Logs gateway activity to **WooCommerce → Status → Logs**. Declines and errors are always logged. Card numbers are never logged — they never reach this server. |

> ### ⚠️ The statement descriptor must not contain a space, underscore or slash
>
> The gateway rejects the **entire transaction** with `Invalid Data` if
> `PMT_DESCRIPTOR` contains a **space**, an **underscore** or a **forward
> slash**. Every sale fails, not just the descriptor.
>
> | | |
> |---|---|
> | `ACME STORE` | ❌ rejected |
> | `ACME_STORE` | ❌ rejected |
> | `ACME/STORE` | ❌ rejected |
> | `ACME-STORE` | ✅ |
> | `ACMESTORE` | ✅ |
> | `ACME.STORE` | ✅ |
>
> The full allowed set is `A-Z`, `a-z`, `0-9`, and `.` `-` `*` `+` `&` `@`.
> This was mapped empirically by sending each candidate character in an
> otherwise-identical approved request; the SDK now rejects an invalid
> descriptor rather than letting the gateway kill the sale. If you are unsure,
> leave the field empty.

---

## Security and PCI posture

**The card number never reaches your server.**

Card fields live in the checkout page's DOM. The shipped JavaScript reads them,
exchanges the PAN for a single-use `TOKEN_GUID` by POSTing **directly to
Inovio**, and only that token is submitted to WordPress. Nothing in the payment
path on your server ever sees a card number — and this holds identically on
both checkout types, because both scripts tokenize the same way and both hand
the same field names to the same `process_payment()`.

Posture ≈ **SAQ A-EP**, and **no Inovio-hosted infrastructure is required** —
there is no hosted payment page and no iframe you have to redirect to.

Saved cards store the gateway's own references (`CUST_ID`, `PMT_ID`) plus the
card brand and last four digits — display-only fields explicitly permitted
under PCI DSS. No PAN is ever stored. Orders store only gateway references
(`_inovio_po_id`, `_inovio_trans_id`, `_inovio_req_id`).

**This was verified, not assumed:** a full `mysqldump` of the WordPress database
taken after a live test run contains **zero** occurrences of either test card
number, in any representation (plain, spaced, dashed).

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| No payment method appears at checkout | One of the five required credential fields is empty, so the method hides itself. | Fill in API Username, API Password, Site ID, **Site Key** and Gateway Product ID. |
| Still no payment method, and credentials are complete | The checkout page is neither the classic `[woocommerce_checkout]` shortcode nor the `woocommerce/checkout` block — e.g. a page builder's own checkout. | Both supported checkout types are listed above. Put the checkout page on one of them. |
| **Error 121** at checkout, or the card form never tokenizes | Site Key missing or wrong. | Enter the per-site HMAC Site Key from Inovio support. It is not the API password. |
| **`Invalid Data`** returned on every transaction | The statement descriptor contains a space, underscore or forward slash. | Remove them — see the descriptor warning above, or clear the field. |
| No "save this card" control on the Block Checkout | Known gap, see below — vaulting a *new* card is classic-checkout-only. | Reusing an already-saved card works on both. |
| Refund fails with `SERVICE 536` | The order has not settled; a credit is the wrong verb. | Handled automatically — the client retries as a reversal. |
| A test transaction declines with **Insufficient Funds** | The Inovio test gateway decides from the order total, not the card. | See Testing below — change the order total. |

---

## Testing

These test cards and amounts apply to the **Inovio test gateway** only.

| Card | Behaviour |
|---|---|
| `4111111111111111` | Approves (no 3-D Secure challenge). |
| `4000000000002503` | Triggers a 3-D Secure challenge. Sandbox OTP: **`1234`**. |

Any future expiry date and any CVV are accepted.

### Forcing a decline

The sandbox decides declines from the **whole order total**, matched exactly.
The PAN, expiry and CVV are not consulted at all.

| Order total | Result |
|---|---|
| `6.35` | Declined — Insufficient Funds |
| `5.06` | Declined — Fraud |

The total must land on the trigger amount exactly, including tax and shipping —
not the product price. This is also why a test order can decline unexpectedly:
check the total before assuming the card or the credentials are at fault.

### Developer test scripts

`tests/e2e-order.php` places a real order through the plugin's own gateway
class, performing exactly the two steps the checkout JS does in the browser
(sign, then POST the PAN direct to the token service) and handing only the
resulting token to `process_payment()`:

```bash
docker --context tensor exec wordpress bash -c \
  'cd /var/www/html && wp --allow-root eval-file \
   wp-content/plugins/inovio-payment-gateway/tests/e2e-order.php'
```

Last verified run: order 14, status `processing`, $10.00 paid,
`PO_ID 18193486`, `TRANS_ID 2001994747`, PAN scan clean.

`e2e/` has a 10-spec Playwright suite that drives the classic checkout
end-to-end (sale, vault, 3DS challenge, frictionless 3DS, decline, refund,
invalid-card-input) with screenshots and videos:

```bash
cd e2e && npx playwright test
```

It requires the checkout page to be on the classic `[woocommerce_checkout]`
shortcode (see below).

**Block Checkout was verified live**, not just unit-tested, with the checkout
page switched to the Block Checkout (`<!-- wp:woocommerce/checkout -->`):

- New card, no 3DS: order 82, status `processing`, $50.00, `PO_ID 18193587`,
  `TRANS_ID 2001994863`, zero browser console errors, genuine
  `.wp-block-woocommerce-checkout` DOM confirmed (not a fallback render).
- New card, 3DS challenge (Cardinal step-up OTP, entered for real through the
  nested cross-origin ACS iframe): order 71, status `processing`,
  `PO_ID 18193577`, `TRANS_ID 2001994852`, `ECI 05`, order note confirms
  `Inovio 3-D Secure authentication completed`.
- Full `mysqldump` taken after these runs: **zero** occurrences of either test
  PAN (`4111111111111111`, `4000000000002503`) in any representation
  (plain, spaced, dashed).

**The checkout page ships on the Block Checkout** (WooCommerce's own default).
The classic shortcode still works — both are fully supported, per the
"Checkout" row above — so the e2e suite (or any workflow that needs the
classic checkout specifically) can switch the page's content between the two
with `wp post update 8 <content-file>` or through the block editor.

---

## How it works (developer notes)

The rest of this document is implementation detail for anyone maintaining or
extending the plugin. Merchants do not need it.

### Checkout Blocks

A default WooCommerce install (8.3+) puts the Block Checkout on the checkout
page, not the classic shortcode. `WC_Payment_Gateway` classes are invisible to
it on their own — declaring one is not enough — so the plugin also registers
`Inovio_Blocks_Support`
(`includes/class-inovio-blocks-support.php`, extending
`Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType`)
on the `woocommerce_blocks_payment_method_type_registration` hook, and ships a
second checkout script, `assets/js/inovio-checkout-blocks.js`, registered via
`wc.wcBlocksRegistry.registerPaymentMethod`.

That script performs the **same** direct-post tokenization as the classic
`assets/js/inovio-checkout.js` — the tokenize/validate/3DS helper functions
are deliberately mirrored between the two files rather than shared, because
the Block Checkout and the classic checkout are different JS runtimes with
different script-dependency graphs and there is no bundler in this plugin to
share ES modules between them. Keep behavior identical between the two if
either changes.

Server-side there is still only **one** implementation:
`Inovio_Payment_Gateway::process_payment()` is unchanged. WooCommerce's
`Automattic\WooCommerce\StoreApi\Legacy::process_legacy_payment()` sets
`$_POST` from the Blocks `paymentMethodData` before calling it, so
`collect_payment_data()`'s existing `$_POST` reads pick up the Blocks-minted
tokens exactly as they pick up the classic form's hidden fields — same field
names, same code path, same PAN-safety guarantees.

**The gotcha that cost the most time building this:** the Store API's
`payment_result.payment_details` response schema types every value as a plain
`string` (`CheckoutSchema::get_item_schema()`). `begin_challenge()`'s 3DS
payload used to be a nested array (`inovio_3ds: {redirectUrl, jwt}`), which
worked for the classic checkout's unschemaed AJAX response but got silently
coerced by WordPress's REST schema sanitizer into the literal string
`"Array"` on the Blocks path — a fully swallowed failure with no error
anywhere, verified empirically. The fix (and now the shared contract for both
checkout types): `inovio_3ds` travels as a **JSON string**, `JSON.parse()`'d
back into an object by whichever checkout JS reads it.

The Blocks 3DS challenge overlay is driven from `onCheckoutSuccess`
(`window.wc.blocksCheckoutEvents.checkoutEvents`), which the Store API awaits
via `emitWithAbort` before marking checkout complete — returning a promise
from the handler holds the shopper on the checkout page (challenge overlaid
on top) until the ACS challenge resolves, instead of navigating to a "thank
you" page for a payment that has not actually completed. A failed challenge
resolves with an `ERROR` response instead of `SUCCESS`, which keeps the
shopper on checkout with a notice; the order itself was already left
`pending` server-side by `begin_challenge()`, so there is nothing to undo.

**Saved-card reuse works on the Block Checkout without any extra code.**
WooCommerce's own saved-payment-token UI renders there regardless of what a
payment method declares (it comes from `WC_Payment_Tokens`, not from
`get_supported_features()`), and it submits `wc-inovio-payment-token`
generically as part of the Store API's payment data — which
`collect_payment_data()` already reads, unchanged, on either checkout type.
Verified live: order 83 charged the shopper's existing saved Visa through the
Block Checkout with zero new code and zero new token created.

**Not wired up for Blocks in this pass: saving a *new* card.** The classic
checkout renders its own "Save this card for future purchases" checkbox
(`$this->save_payment_method_checkbox()`); the Blocks card-entry component
does not render an equivalent, and `Inovio_Blocks_Support::get_supported_features()`
deliberately omits `'tokenization'`, so WooCommerce Blocks' own built-in
save-option checkbox does not appear either. Verified live: entering a new
card on the Block Checkout shows no save-card control anywhere on the page.
A shopper can charge a new card there (tested, works, with and without 3DS)
but cannot opt to save it for later — vaulting stays classic-checkout-only
until this gets its own pass.

### Two things that are easy to get wrong

**Two-token 3DS.** Gateway `TOKEN_GUID`s are single-use and the enrollment leg
consumes one, so checkout mints **two** tokens from the same PAN when 3DS is
enabled — A drives enrollment, B completes after the challenge. Reusing A
returns `API 401 Invalid TOKEN_GUID`.

**Settlement-aware refunds.** The gateway rejects a refund on an unsettled
order with `SERVICE 536 "Order not settled: Please reverse"`. Before
settlement the correct undo is a reversal, not a credit; the client checks
settlement and picks the right verb.

## Known gaps

- **Saving a new card is classic-checkout-only.** See "Checkout Blocks" above
  — reusing an already-saved card works on the Block Checkout with no extra
  code, but there is no "save this card" control on the Block Checkout for a
  newly-entered card, so a shopper can only build up saved cards through the
  classic checkout today.
