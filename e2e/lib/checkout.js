/**
 * Shared storefront driver.
 *
 * Everything here goes through the real UI — click the product, click through
 * checkout, type into the card fields, click Place Order. No database writes,
 * no direct calls into the plugin, no POSTing to our own endpoints. If a step
 * can't be done as a shopper, the test fails.
 *
 * Platform notes (WooCommerce, verified live on this stack):
 *  - The storefront theme is Twenty Twenty-Five (a block theme). The Cart
 *    page is still the Cart BLOCK (`[wp:woocommerce/cart]`) — its DOM only
 *    has real content once client-side hydration finishes, so cart actions
 *    wait for that rather than assuming classic `tr.cart_item` markup.
 *  - The Checkout page was switched from the Cart/Checkout Block to the
 *    classic `[woocommerce_checkout]` shortcode, because the plugin itself
 *    declares checkout-block incompatibility:
 *      FeaturesUtil::declare_compatibility('cart_checkout_blocks', ..., false)
 *    So checkout.js only ever needs to know the classic checkout DOM.
 *  - The gateway id is "inovio" (Inovio_Payment_Gateway::GATEWAY_ID), so the
 *    payment radio is #payment_method_inovio, the saved-card token radios are
 *    input[name="wc-inovio-payment-token"], and the save-card checkbox is
 *    #wc-inovio-new-payment-method (WooCommerce's own token-gateway markup).
 *  - Card/expiry/CVV inputs are deliberately unnamed
 *    (includes/class-inovio-payment-gateway.php) so a PAN can never be
 *    serialized into the WordPress POST even by accident; they are addressed
 *    by id (#inovio-card-number / #inovio-exp-month / #inovio-exp-year /
 *    #inovio-cvv) here, exactly as the browser DOM exposes them.
 */
import { expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

export const SHOPPER = {
  username: 'shopper',
  email: 'shopper@inovio.local',
  password: 'ShopTest123!',
};

export const BILLING = {
  first_name: 'Woo',
  last_name: 'Shopper',
  address_1: '123 Test St',
  city: 'Las Vegas',
  state: 'NV',
  postcode: '89101',
  phone: '7025551234',
};

export const ADMIN = {
  username: 'admin',
  password: 'InovioTest123!',
};

export const CARDS = {
  // Approves without a challenge.
  frictionless: '4111111111111111',
  // Cardinal's documented challenge PAN — triggers the ACS step-up.
  challenge: '4000000000002503',
};

const EVIDENCE = path.resolve('evidence');

/** Numbered, per-case screenshots so the run reads as a story. */
export function shotter(caseName) {
  const dir = path.join(EVIDENCE, caseName);
  fs.mkdirSync(dir, { recursive: true });
  let n = 0;

  return async (page, label) => {
    n += 1;
    const file = path.join(dir, `${String(n).padStart(2, '0')}-${label}.png`);
    /*
     * Full-page, but capped.
     *
     * wp-admin (and some checkout error states) can render unexpectedly
     * tall pages; clip to a generous viewport-multiple so a screenshot never
     * turns into an unreadable multi-thousand-pixel sliver. Mirrors the
     * PrestaShop suite's own MAX_SHOT_HEIGHT convention.
     */
    const MAX_SHOT_HEIGHT = 4000;
    const height = await page.evaluate(
      () => document.documentElement.scrollHeight
    ).catch(() => MAX_SHOT_HEIGHT);

    if (height > MAX_SHOT_HEIGHT) {
      const width = page.viewportSize()?.width ?? 1280;
      await page.screenshot({
        path: file,
        clip: { x: 0, y: 0, width, height: MAX_SHOT_HEIGHT },
      });
    } else {
      await page.screenshot({ path: file, fullPage: true });
    }

    return file;
  };
}

/** Log the shopper in via the classic My Account login form. */
export async function login(page) {
  await page.goto('/my-account/');
  await page.waitForLoadState('networkidle');

  // Already signed in? Calling this twice in one spec must stay harmless.
  if (!(await page.locator('#username').count())) {
    return;
  }

  await page.fill('#username', SHOPPER.username);
  await page.fill('#password', SHOPPER.password);
  await page.click('button[name="login"]');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('body')).toContainText(/Hello|My account|Log out/i, { timeout: 20000 });
}

/**
 * Empty the cart so each case starts from a known state.
 *
 * The Cart page is the Cart BLOCK — it renders skeleton placeholders and
 * hydrates its real DOM (including the accessible "Remove ... from cart"
 * buttons) asynchronously after the page "load" event, so this polls for
 * that hydration on every pass rather than assuming a fixed classic
 * cart-row selector.
 */
export async function emptyCart(page) {
  for (let attempt = 0; attempt < 15; attempt++) {
    await page.goto('/cart/');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500); // block hydration

    const removeBtn = page.getByRole('button', { name: /remove .* from cart/i }).first();
    if (!(await removeBtn.count())) {
      return;
    }

    await removeBtn.click();
    await page.waitForTimeout(1500);
  }

  throw new Error('emptyCart: cart still had items after 15 removal attempts');
}

/** Add the store's one Test Product to the cart from its product page. */
export async function addProduct(page, productUrl = '/product/test-product/') {
  await page.goto(productUrl);
  await page.waitForLoadState('networkidle');

  const addBtn = page.locator('button.single_add_to_cart_button, button:has-text("Add to cart")').first();
  await expect(addBtn).toBeVisible({ timeout: 15000 });
  await addBtn.click();
  // The theme's own "<Product> has been added to your cart." notice, or the
  // add-to-cart button's own "added" state — wait for either rather than a
  // fixed sleep.
  await Promise.race([
    page.locator('.woocommerce-message, .added_to_cart').first().waitFor({ state: 'visible', timeout: 15000 }),
    page.waitForTimeout(3000),
  ]).catch(() => {});
  await page.waitForTimeout(1000);
}

/**
 * Navigate to checkout and wait for the classic checkout form (and its
 * billing fields) to actually be present. Returns once the form is ready to
 * fill.
 */
export async function goToCheckout(page) {
  await page.goto('/checkout/');
  await page.waitForLoadState('networkidle');
  await page.waitForSelector('#billing_first_name', { timeout: 20000 });
  await page.waitForTimeout(800);
}

/**
 * Fill the billing address fields. WooCommerce does not reliably prefill a
 * logged-in shopper's saved address into the classic checkout form's raw
 * inputs on first render (verified live: fields render blank even for an
 * account with a saved billing profile), so every spec fills them for real,
 * exactly as a shopper would.
 */
export async function fillBilling(page, billing = BILLING) {
  const first = page.locator('#billing_first_name');
  if ((await first.inputValue()) === '') {
    await first.fill(billing.first_name);
  }
  const last = page.locator('#billing_last_name');
  if ((await last.inputValue()) === '') {
    await last.fill(billing.last_name);
  }
  const addr = page.locator('#billing_address_1');
  if ((await addr.inputValue()) === '') {
    await addr.fill(billing.address_1);
  }
  const city = page.locator('#billing_city');
  if ((await city.inputValue()) === '') {
    await city.fill(billing.city);
  }
  const zip = page.locator('#billing_postcode');
  if ((await zip.inputValue()) === '') {
    await zip.fill(billing.postcode);
  }
}

/**
 * Select the Inovio payment option.
 *
 * WooCommerce hides a payment method's radio input entirely
 * (`style="display: none"`) whenever it is the only method available and
 * auto-checks it — verified live on this store, which has just the one
 * gateway configured. So this only clicks the radio when it's actually
 * visible (a real, present choice among methods); otherwise it trusts core's
 * own `checked="checked"` and simply waits for the card fields to render.
 */
export async function selectInovio(page) {
  const radio = page.locator('#payment_method_inovio');
  await expect(radio).toBeAttached({ timeout: 20000 });

  if (await radio.isVisible()) {
    await radio.check();
  } else {
    await expect(radio).toBeChecked();
  }

  await expect(page.locator('#inovio-payment-fields')).toBeVisible({ timeout: 10000 });
}

/**
 * Type the card exactly as a shopper does.
 *
 * If this shopper already has a saved card, WooCommerce's own saved-payment
 * list auto-selects it by default (verified live) — the plugin's own JS
 * (usingSavedCard()) then treats the submission as a stored-card payment and
 * skips tokenizing whatever is typed into the new-card fields entirely. So a
 * real shopper who wants to use a different card must click "Use a new
 * payment method" first; this does the same before typing.
 */
export async function fillCard(page, pan, { save = false, month = '12', year = '2030', cvv = '123' } = {}) {
  const useNew = page.locator('#wc-inovio-payment-token-new');
  if ((await useNew.count()) && !(await useNew.isChecked())) {
    await useNew.check();
  }

  await page.fill('#inovio-card-number', pan);
  await page.locator('#inovio-exp-month').selectOption(month);
  await page.locator('#inovio-exp-year').selectOption(year);
  await page.fill('#inovio-cvv', cvv);

  if (save) {
    const checkbox = page.locator('#wc-inovio-new-payment-method');
    await expect(checkbox).toBeVisible();
    await checkbox.check();
  }
}

/**
 * Select a previously vaulted card by radio — no PAN re-entry.
 *
 * WooCommerce's own saved-payment-methods list (rendered by
 * $this->saved_payment_methods() in the gateway) auto-checks the most
 * recently saved token by default — verified live — so this asserts that
 * state rather than needing a click, but still clicks explicitly if for any
 * reason a different token or "Use a new payment method" ended up selected.
 */
export async function selectSavedCard(page) {
  const radio = page.locator('input[name="wc-inovio-payment-token"]:not([value="new"])').first();
  await expect(radio).toBeVisible({ timeout: 15000 });

  if (!(await radio.isChecked())) {
    await radio.check();
  }

  return radio;
}

/** Click Place Order. */
export async function placeOrder(page) {
  const btn = page.locator('#place_order');
  await expect(btn).toBeVisible();
  await expect(btn).toBeEnabled();
  await btn.click();
}

/**
 * Assert we landed on WooCommerce's real order-received page, and return the
 * order id parsed from the URL
 * (/checkout/order-received/{id}/?key=wc_order_...).
 */
export async function expectConfirmed(page) {
  await expect(page).toHaveURL(/order-received/, { timeout: 60000 });
  await expect(page.locator('body')).toContainText(/order.*received|thank you/i, { timeout: 20000 });

  const m = page.url().match(/order-received\/(\d+)/);

  return m ? m[1] : null;
}
