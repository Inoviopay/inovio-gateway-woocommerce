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

## Known gaps

- **Checkout Blocks are not supported** — only the classic checkout. Blocks
  require a separate integration, as the cart research flagged up front.
- No browser-driven e2e suite yet. The PrestaShop and Magento modules each
  have a Playwright suite with screenshots and videos; this plugin should get
  the same treatment.
