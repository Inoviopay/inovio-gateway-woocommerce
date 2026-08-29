# Inovio Payment Gateway — WooCommerce

Tokenized direct-post card checkout for WooCommerce, on the Inovio gateway.
**The card number never reaches the WordPress server.**

Ported from the PrestaShop module (`repos/inovio-gateway-prestashop`), which
carries the full design rationale in
[`docs/prestashop-integration-design.md`](../../../docs/prestashop-integration-design.md).

## Requirements

- WordPress 6.x, WooCommerce 8+
- PHP **8.0+** with `bcmath`, `curl`, `json`

`bcmath` is required, not optional: the SDK's `Money` does decimal arithmetic
through it so amounts never touch a binary float.

## What it does

| | |
|---|---|
| Sale / Authorize | configurable payment action |
| Capture / Void | admin order actions when authorize-only |
| Refund | `process_refund()` — WooCommerce's native admin refund UI |
| Saved cards | native `WC_Payment_Tokens` (unlike PrestaShop, Woo has a token API) |
| 3D Secure | DDC + challenge, two-token flow |
| Checkout | **both** the classic `[woocommerce_checkout]` shortcode and the Block Checkout (`woocommerce/checkout`) — a default WooCommerce install works out of the box |

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

### PCI posture

Card fields live in the checkout DOM; the shipped JS exchanges the PAN for a
single-use token **directly against Inovio** and only the token reaches this
server. Posture ≈ **SAQ A-EP**, with no Inovio-side infrastructure.

*Verified on a live order:* a full `mysqldump` of the WordPress database
contains **zero** occurrences of the submitted PAN. The order stores only
gateway references (`_inovio_po_id`, `_inovio_trans_id`, `_inovio_req_id`).

### Two things that are easy to get wrong

**Two-token 3DS.** Gateway `TOKEN_GUID`s are single-use and the enrollment leg
consumes one, so checkout mints **two** tokens from the same PAN when 3DS is
enabled — A drives enrollment, B completes after the challenge. Reusing A
returns `API 401 Invalid TOKEN_GUID`.

**Settlement-aware refunds.** The gateway rejects a refund on an unsettled
order with `SERVICE 536 "Order not settled: Please reverse"`. Before
settlement the correct undo is a reversal, not a credit; the client checks
settlement and picks the right verb.

## Install

1. Copy `inovio-payment-gateway/` into `wp-content/plugins/`.
2. `wp plugin activate inovio-payment-gateway`
3. Configure under **WooCommerce → Settings → Payments → Inovio**.

### Configuration

Required: API username, API password, Site ID, **Site Key**, Gateway Product ID.

The **Site Key** is a per-site HMAC secret issued by Inovio support — not the
API password. Browser tokenization fails with error 121 without it. The
**Gateway Product ID** (`LI_PROD_ID`) is a gateway-registered product, not a
WooCommerce SKU; the order bills as one line item under it.

> ⚠️ **The descriptor must not contain spaces.** The gateway rejects the whole
> transaction with `Invalid Data` if `PMT_DESCRIPTOR` contains a space —
> `INOVIO TEST` fails, `INOVIOTEST` approves. Verified by bisection against an
> otherwise-identical approved request. Leave it empty if unsure.

## Testing

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

## Known gaps

- **Saving a new card is classic-checkout-only.** See "Checkout Blocks" above
  — reusing an already-saved card works on the Block Checkout with no extra
  code, but there is no "save this card" control on the Block Checkout for a
  newly-entered card, so a shopper can only build up saved cards through the
  classic checkout today.
