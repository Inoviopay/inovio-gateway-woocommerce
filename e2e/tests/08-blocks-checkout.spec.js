import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import { BLOCK_CONTENT, CLASSIC_CONTENT, setCheckoutPageContent } from '../global-setup.js';

/**
 * Block Checkout — the default on a modern WooCommerce install.
 *
 * This is the case that made the plugin look broken on a fresh install: with
 * `cart_checkout_blocks` declared incompatible, Inovio never appeared and the
 * shopper saw "There are no payment methods available". This spec proves a
 * real order completes through the actual Block Checkout DOM (not the
 * classic shortcode), placed by the SAME server-side
 * `Inovio_Payment_Gateway::process_payment()` the classic specs (01-07)
 * exercise — see inovio-payment-gateway/README.md, "Checkout Blocks", for how
 * the two checkout types share that one server implementation.
 *
 * Every other spec in this suite needs the classic checkout's DOM, so
 * playwright.config.js's globalSetup switches the whole run to the classic
 * shortcode up front and globalTeardown restores the Block Checkout at the
 * end. This spec needs the OPPOSITE for its own duration, so it switches the
 * page to Blocks itself in beforeAll and switches it back to classic in
 * afterAll — self-contained regardless of where 08 falls in run order or
 * whether it's run in isolation (`npx playwright test tests/08-*`, which
 * skips the global hooks' classic setup entirely, as it did during
 * development).
 */
test.describe('Block Checkout', () => {
  test.beforeAll(async () => {
    setCheckoutPageContent(BLOCK_CONTENT);
  });

  test.afterAll(async () => {
    setCheckoutPageContent(CLASSIC_CONTENT);
  });

  test('Block Checkout: add to cart, pay with a new card, order confirms', async ({ page }) => {
    const shot = ck.shotter('08-blocks-checkout');

    await ck.login(page);
    await shot(page, 'logged-in');

    await ck.emptyCart(page);
    await ck.addProduct(page);
    await shot(page, 'product-added-to-cart');

    await ck.goToBlockCheckout(page);

    // Confirm this really is the block checkout and not a silent fallback to
    // classic markup — the exact regression this whole feature exists to fix.
    const markers = await page.evaluate(() => ({
      hasBlockDiv: !!document.querySelector('.wp-block-woocommerce-checkout'),
      hydrated: !!document.querySelector('.wp-block-woocommerce-checkout:not(.is-loading)'),
      wcBlocksScriptLoaded: [...document.querySelectorAll('script[src]')]
        .some((s) => /wc-blocks-registry/.test(s.src)),
    }));
    expect(markers.hasBlockDiv, 'block checkout div should be present').toBe(true);
    expect(markers.hydrated, 'block checkout should be hydrated').toBe(true);
    expect(markers.wcBlocksScriptLoaded, 'wc-blocks-registry script should be loaded').toBe(true);
    await shot(page, 'block-checkout-confirmed');

    await ck.selectInovioBlock(page);
    await ck.fillCardBlock(page, ck.CARDS.frictionless);
    await shot(page, 'card-entered');

    await ck.placeOrderBlock(page);
    await shot(page, 'order-submitted');

    const orderId = await ck.expectConfirmed(page);
    expect(orderId).toBeTruthy();
    await shot(page, 'order-confirmed');

    console.log('BLOCKS_ORDER_ID=' + orderId);
  });
});
