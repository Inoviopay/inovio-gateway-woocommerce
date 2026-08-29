import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as ck from '../lib/checkout.js';

/**
 * Client-side validation at checkout, before the card is ever tokenized.
 *
 * validate() in assets/js/inovio-checkout.js runs synchronously on submit,
 * ahead of any network call:
 *   - Luhn check on the PAN (also rejects on length < 12 before the checksum
 *     even runs)
 *   - expiry must not be in the past (current year + past month)
 *   - CVV must match ^[0-9]{3,4}$
 *
 * A failure here must never reach the gateway or create an order — it's a
 * pure client-side reject. Each case fills the real form, clicks the real
 * "Place order" button, and asserts (a) the shopper sees the right message
 * in #inovio-errors, (b) the page stays on checkout (no order-received
 * navigation), and (c) no new WooCommerce order appears for this cart's
 * total (read-only DB verification only — nothing here writes to the
 * database or calls the plugin directly).
 *
 * IMPORTANT (discovered live while writing this spec): this shopper account
 * has a saved card from 02-vault.spec.js. WooCommerce auto-selects that
 * saved card by default on later checkouts, and the plugin's own JS treats a
 * saved-card submission as needing no tokenization at all — so typing into
 * the new-card fields without first clicking "Use a new payment method"
 * silently charges the SAVED card instead of validating what was typed.
 * ck.fillCard() now clicks "Use a new payment method" first when it's
 * present, which is what a real shopper wanting to use a different card
 * would do; this file relies on that.
 *
 * This stack may also be touched by other tooling/operators between runs, so
 * a bare "no new order row at all" check would be racy. Following the same
 * pattern already proven on the sibling PrestaShop suite:
 * checkNoOrderForOurTotal() takes the "before" max order id right before the
 * submit click and, if newer rows exist, only fails on one matching this
 * cart's own total — a new row for a different total is someone else's
 * order, not evidence of a validation bypass here.
 */

/** Read-only: highest order id currently in the DB. */
function maxOrderId() {
  const out = execSync(
    'docker --context tensor exec wc-mysql mysql -uwordpress -pwordpress wordpress ' +
    '-N -e "SELECT COALESCE(MAX(ID), 0) FROM wp_posts WHERE post_type IN (\'shop_order\',\'shop_order_placehold\');"'
  ).toString().trim();

  return parseInt(out, 10);
}

/** Read-only: id/status/total for every order row strictly after `afterId`. */
function ordersAfter(afterId) {
  const ids = execSync(
    'docker --context tensor exec wc-mysql mysql -uwordpress -pwordpress wordpress ' +
    `-N -e "SELECT ID FROM wp_posts WHERE post_type IN ('shop_order','shop_order_placehold') AND ID > ${afterId};"`
  ).toString().trim();

  if (!ids) {
    return [];
  }

  return ids.split('\n').filter(Boolean).map((id) => {
    const status = execSync(
      `docker --context tensor exec wc-mysql mysql -uwordpress -pwordpress wordpress -N -e "SELECT post_status FROM wp_posts WHERE ID = ${id};"`
    ).toString().trim();
    const total = execSync(
      `docker --context tensor exec wc-mysql mysql -uwordpress -pwordpress wordpress -N -e "SELECT meta_value FROM wp_postmeta WHERE post_id = ${id} AND meta_key = '_order_total';"`
    ).toString().trim();

    return { id, status, total: parseFloat(total || '0') };
  });
}

/**
 * Assert no order matching THIS test's cart total was created, tolerating a
 * concurrently-created, unrelated order elsewhere on the shared stack.
 */
function assertNoOrderCreated(before, ourTotal) {
  const newOrders = ordersAfter(before);
  const ours = newOrders.filter((o) => Math.abs(o.total - ourTotal) < 0.01);

  if (newOrders.length && !ours.length) {
    console.log(
      'Note: ' + newOrders.length + ' new order row(s) appeared after this test\'s "before" ' +
      'snapshot, but none match this cart\'s total ($' + ourTotal.toFixed(2) + ') — ' +
      JSON.stringify(newOrders) + '. Treating as unrelated activity on the shared stack, not ' +
      'evidence this test\'s invalid card was accepted.'
    );
  }

  expect(
    ours,
    'A new order matching this test\'s cart total ($' + ourTotal.toFixed(2) + ') was created ' +
    'despite invalid client-side input: ' + JSON.stringify(ours)
  ).toEqual([]);
}

/** Read-only: this cart's total (tax incl.), from the checkout page's own order table. */
async function cartTotal(page) {
  const text = await page.locator('.order-total').innerText();
  const m = text.match(/\$?([\d,.]+)/);

  if (!m) {
    throw new Error('Could not read cart total from the checkout order summary.');
  }

  return parseFloat(m[1].replace(/,/g, ''));
}

test.describe('Invalid card input: rejected client-side, no order created', () => {
  test('PAN failing the Luhn check is rejected', async ({ page }) => {
    const shot = ck.shotter('07-invalid-card-input-luhn');

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.goToCheckout(page);
    await ck.fillBilling(page);
    await ck.selectInovio(page);
    const ourTotal = await cartTotal(page);

    // Same length/prefix as the approving PAN but with the last digit bumped
    // by one, which breaks the Luhn checksum without breaking the format.
    const before = maxOrderId();
    await ck.fillCard(page, '4111111111111112');
    await ck.placeOrder(page);
    await page.waitForTimeout(1500);
    await shot(page, 'luhn-fail-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid card number/i);
    await shot(page, 'luhn-fail-error-shown');

    await expect(page).not.toHaveURL(/order-received/);
    assertNoOrderCreated(before, ourTotal);
  });

  test('PAN that is too short is rejected', async ({ page }) => {
    const shot = ck.shotter('07-invalid-card-input-short-pan');

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.goToCheckout(page);
    await ck.fillBilling(page);
    await ck.selectInovio(page);
    const ourTotal = await cartTotal(page);

    // luhnValid() requires pan.length >= 12 before it even runs the
    // checksum, so a 6-digit PAN fails on length alone.
    const before = maxOrderId();
    await ck.fillCard(page, '411111');
    await ck.placeOrder(page);
    await page.waitForTimeout(1500);
    await shot(page, 'short-pan-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid card number/i);
    await shot(page, 'short-pan-error-shown');

    await expect(page).not.toHaveURL(/order-received/);
    assertNoOrderCreated(before, ourTotal);
  });

  test('CVV that is too short is rejected', async ({ page }) => {
    const shot = ck.shotter('07-invalid-card-input-cvv');

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.goToCheckout(page);
    await ck.fillBilling(page);
    await ck.selectInovio(page);
    const ourTotal = await cartTotal(page);

    const before = maxOrderId();
    await ck.fillCard(page, ck.CARDS.frictionless, { cvv: '12' });
    await ck.placeOrder(page);
    await page.waitForTimeout(1500);
    await shot(page, 'short-cvv-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid security code/i);
    await shot(page, 'short-cvv-error-shown');

    await expect(page).not.toHaveURL(/order-received/);
    assertNoOrderCreated(before, ourTotal);
  });

  test('Expiry in the past (current year, past month) is rejected', async ({ page }) => {
    const shot = ck.shotter('07-invalid-card-input-expiry');

    /*
     * The year <select> is server-rendered from
     * range(gmdate('Y'), gmdate('Y') + 11) (class-inovio-payment-gateway.php)
     * — it never offers a past year, so a genuinely past YEAR cannot be
     * selected through the UI at all. That is not being skipped for
     * convenience; it structurally cannot be driven as a real shopper
     * action.
     *
     * validate()'s expiry check is independently sensitive to a past MONTH
     * within the CURRENT year, so this case picks the current year and the
     * month before the current one — unless the test is literally run in
     * January, in which case no past month exists in the current year and
     * the case is skipped with a clear reason rather than faked.
     */
    const now = new Date();
    const currentMonth = now.getMonth() + 1; // 1-12

    if (currentMonth === 1) {
      test.skip(true, 'No past month exists within the current year in January; a genuinely ' +
        'past year is not selectable in the UI (year <select> only offers current..current+11) ' +
        'so this case has no reachable expiry to pick.');
    }

    const pastMonth = String(currentMonth - 1).padStart(2, '0');
    const currentYear = String(now.getFullYear());

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.goToCheckout(page);
    await ck.fillBilling(page);
    await ck.selectInovio(page);
    const ourTotal = await cartTotal(page);

    const before = maxOrderId();
    await ck.fillCard(page, ck.CARDS.frictionless, { month: pastMonth, year: currentYear });
    await ck.placeOrder(page);
    await page.waitForTimeout(1500);
    await shot(page, 'past-expiry-submitted');

    const errors = page.locator('#inovio-errors');
    await expect(errors).toBeVisible();
    await expect(errors).toContainText(/valid expiration date/i);
    await shot(page, 'past-expiry-error-shown');

    await expect(page).not.toHaveURL(/order-received/);
    assertNoOrderCreated(before, ourTotal);
  });
});
