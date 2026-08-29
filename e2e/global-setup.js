/**
 * Switches the checkout page to the classic `[woocommerce_checkout]`
 * shortcode before this suite runs, and back to the Block Checkout
 * afterward.
 *
 * Why this exists: the plugin now supports BOTH checkout types (see
 * inovio-payment-gateway/README.md, "Checkout Blocks"), and the store's
 * checkout page (id 8) is left on the Block Checkout as the shipped default —
 * that's what a merchant gets on a fresh install. This suite's specs
 * (lib/checkout.js) drive the classic checkout's DOM specifically, so rather
 * than teaching every spec to handle either markup, the whole run brackets
 * itself around a one-time page-content swap: classic in, classic tests run,
 * Block Checkout restored on the way out — pass or fail.
 *
 * The store lives behind the `tensor` Docker context, not this test runner's
 * own filesystem, so the swap goes through the same `docker --context tensor
 * exec wordpress wp --allow-root post update 8 <file>` invocation used
 * throughout this project's manual verification — there is no HTTP admin API
 * for post content that's simpler than just asking WP-CLI to do it.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const CHECKOUT_PAGE_ID = 8;

const CLASSIC_CONTENT = '[woocommerce_checkout]';

// The default block markup WooCommerce scaffolds for a fresh Checkout page
// (Twenty Twenty-Five / WC 11's own template), reproduced here so
// globalTeardown can restore it without needing to have captured it first.
const BLOCK_CONTENT = `<!-- wp:woocommerce/checkout -->
<div class="wp-block-woocommerce-checkout is-loading" style="padding-top:var(--wp--preset--spacing--40);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)"><!-- wp:woocommerce/checkout-fields-block -->
<div class="wp-block-woocommerce-checkout-fields-block"><!-- wp:woocommerce/checkout-express-payment-block -->
<div class="wp-block-woocommerce-checkout-express-payment-block"></div>
<!-- /wp:woocommerce/checkout-express-payment-block -->

<!-- wp:woocommerce/checkout-contact-information-block -->
<div class="wp-block-woocommerce-checkout-contact-information-block"></div>
<!-- /wp:woocommerce/checkout-contact-information-block -->

<!-- wp:woocommerce/checkout-shipping-method-block -->
<div class="wp-block-woocommerce-checkout-shipping-method-block"></div>
<!-- /wp:woocommerce/checkout-shipping-method-block -->

<!-- wp:woocommerce/checkout-pickup-options-block -->
<div class="wp-block-woocommerce-checkout-pickup-options-block"></div>
<!-- /wp:woocommerce/checkout-pickup-options-block -->

<!-- wp:woocommerce/checkout-shipping-address-block -->
<div class="wp-block-woocommerce-checkout-shipping-address-block"></div>
<!-- /wp:woocommerce/checkout-shipping-address-block -->

<!-- wp:woocommerce/checkout-billing-address-block -->
<div class="wp-block-woocommerce-checkout-billing-address-block"></div>
<!-- /wp:woocommerce/checkout-billing-address-block -->

<!-- wp:woocommerce/checkout-shipping-methods-block -->
<div class="wp-block-woocommerce-checkout-shipping-methods-block"></div>
<!-- /wp:woocommerce/checkout-shipping-methods-block -->

<!-- wp:woocommerce/checkout-payment-block -->
<div class="wp-block-woocommerce-checkout-payment-block"></div>
<!-- /wp:woocommerce/checkout-payment-block -->

<!-- wp:woocommerce/checkout-additional-information-block -->
<div class="wp-block-woocommerce-checkout-additional-information-block"></div>
<!-- /wp:woocommerce/checkout-additional-information-block -->

<!-- wp:woocommerce/checkout-order-note-block -->
<div class="wp-block-woocommerce-checkout-order-note-block"></div>
<!-- /wp:woocommerce/checkout-order-note-block -->

<!-- wp:woocommerce/checkout-terms-block -->
<div class="wp-block-woocommerce-checkout-terms-block"></div>
<!-- /wp:woocommerce/checkout-terms-block -->

<!-- wp:woocommerce/checkout-actions-block -->
<div class="wp-block-woocommerce-checkout-actions-block"></div>
<!-- /wp:woocommerce/checkout-actions-block --></div>
<!-- /wp:woocommerce/checkout-fields-block -->

<!-- wp:woocommerce/checkout-totals-block -->
<div class="wp-block-woocommerce-checkout-totals-block"><!-- wp:woocommerce/checkout-order-summary-block -->
<div class="wp-block-woocommerce-checkout-order-summary-block"><!-- wp:woocommerce/checkout-order-summary-cart-items-block -->
<div class="wp-block-woocommerce-checkout-order-summary-cart-items-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-cart-items-block -->

<!-- wp:woocommerce/checkout-order-summary-coupon-form-block -->
<div class="wp-block-woocommerce-checkout-order-summary-coupon-form-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-coupon-form-block -->

<!-- wp:woocommerce/checkout-order-summary-subtotal-block -->
<div class="wp-block-woocommerce-checkout-order-summary-subtotal-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-subtotal-block -->

<!-- wp:woocommerce/checkout-order-summary-fee-block -->
<div class="wp-block-woocommerce-checkout-order-summary-fee-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-fee-block -->

<!-- wp:woocommerce/checkout-order-summary-discount-block -->
<div class="wp-block-woocommerce-checkout-order-summary-discount-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-discount-block -->

<!-- wp:woocommerce/checkout-order-summary-shipping-block -->
<div class="wp-block-woocommerce-checkout-order-summary-shipping-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-shipping-block -->

<!-- wp:woocommerce/checkout-order-summary-taxes-block -->
<div class="wp-block-woocommerce-checkout-order-summary-taxes-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-taxes-block --></div>
<!-- /wp:woocommerce/checkout-order-summary-block --></div>
<!-- /wp:woocommerce/checkout-totals-block --></div>
<!-- /wp:woocommerce/checkout -->
`;

/**
 * Set the checkout page's content through WP-CLI in the `wordpress`
 * container on the `tensor` Docker context.
 *
 * A file (not `--post_content=`) is used because the block markup contains
 * quotes and newlines that would not survive a single CLI argument cleanly;
 * `wp post update <id> <file>` reads the new content from disk instead.
 *
 * @param {string} content
 * @returns {void}
 */
function setCheckoutPageContent( content ) {
	const tmpFile = path.join( os.tmpdir(), `inovio-checkout-page-${Date.now()}.txt` );
	const containerFile = `/tmp/${ path.basename( tmpFile ) }`;

	fs.writeFileSync( tmpFile, content );

	try {
		execFileSync( 'docker', [ '--context', 'tensor', 'cp', tmpFile, `wordpress:${ containerFile }` ] );
		execFileSync( 'docker', [
			'--context', 'tensor', 'exec', 'wordpress', 'bash', '-c',
			`cd /var/www/html && wp --allow-root post update ${ CHECKOUT_PAGE_ID } ${ containerFile }`,
		] );
	} finally {
		fs.unlinkSync( tmpFile );
	}
}

/**
 * Playwright global setup: switch the checkout page to the classic shortcode
 * so this suite's classic-checkout-DOM specs can run against a default
 * install (which ships on the Block Checkout).
 *
 * @returns {Promise<void>}
 */
export default async function globalSetup() {
	setCheckoutPageContent( CLASSIC_CONTENT );
}

export { BLOCK_CONTENT, CLASSIC_CONTENT, setCheckoutPageContent };
