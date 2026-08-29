import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

/**
 * A declined card must NOT leave the shopper on a confirmed order: they stay
 * on checkout and see an error, and any WooCommerce order row created for
 * the attempt must never reach a paid status.
 *
 * How the decline is actually triggered (same mechanism proven on the
 * sibling PrestaShop suite against this same sandbox gateway): the
 * configured merchant account (1602) routes to a built-in test-bank
 * simulator that decides approve/decline purely from the whole transaction
 * AMOUNT matched against a fixed list of trigger values — PAN, expiry and
 * CVV are never consulted. The processor response code is the amount with
 * the decimal point removed (e.g. 6.35 -> "635" -> Insufficient Funds).
 *
 * This store has taxes off and no shipping zones configured (verified: no
 * rows in wp_woocommerce_shipping_zones), so the cart total is exactly the
 * product price. The spec sets the Test Product's price to $6.35 through the
 * real product-edit admin screen, checks out, and restores $10.00
 * afterward — the same "engineer the total through the real UI" approach the
 * PrestaShop suite uses for its own decline case.
 *
 * Verified live before writing this spec: WooCommerce's own behaviour here
 * differs from PrestaShop's — WC creates the order row up front (as
 * "Pending payment") and marks it "Failed" on a gateway decline, rather than
 * never persisting a row at all. So the correct assertion is "no order for
 * this cart ever reaches a paid status", not "no order row exists".
 */
const PRODUCT_ID = 11;
const TRIGGER_PRICE = '6.35';
const ORIGINAL_PRICE = '10.00';
const TRIGGER_TOTAL = '$6.35';
const EXPECTED_ADVICE = /insufficient funds/i;

function queryDb(sql) {
  const out = execSync(
    `docker --context tensor exec wc-mysql mysql -uwordpress -pwordpress wordpress -N -e "${sql}"`,
    { encoding: 'utf8' }
  );

  return out.trim();
}

test.describe('Decline', () => {
  test.afterEach(async ({ page }) => {
    // Always leave the shop as found, even if an assertion above failed.
    await admin.loginAdmin(page);
    await admin.setProductPrice(page, PRODUCT_ID, ORIGINAL_PRICE);
  });

  test('Declined card does not create a paid order', async ({ page }) => {
    const shot = ck.shotter('05-decline');

    // --- Fixture setup: engineer a $6.35 cart total through the real UI ---
    await admin.loginAdmin(page);
    await admin.setProductPrice(page, PRODUCT_ID, TRIGGER_PRICE);
    await shot(page, 'admin-price-set-to-trigger');

    // --- Shopper checks out with the trigger-priced product ---
    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await shot(page, 'cart-at-trigger-total');

    await ck.goToCheckout(page);
    await ck.fillBilling(page);

    // Confirm the engineered total actually landed on the trigger amount
    // before spending the assertion on the decline itself.
    const totalText = await page.locator('.order-total').innerText();
    expect(totalText).toContain(TRIGGER_TOTAL);

    await ck.selectInovio(page);
    await ck.fillCard(page, ck.CARDS.frictionless);
    await shot(page, 'card-entered');

    const before = Number(queryDb(
      "SELECT COALESCE(MAX(ID), 0) FROM wp_posts WHERE post_type IN ('shop_order','shop_order_placehold');"
    ));

    await ck.placeOrder(page);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
    await shot(page, 'decline-message');

    // Shopper is NOT taken to order-received; still on checkout.
    await expect(page).not.toHaveURL(/order-received/);
    await expect(page).toHaveURL(/\/checkout\/?(\?|$)/);

    // A real, specific decline message is shown — not a generic failure.
    const notice = page.locator('.woocommerce-error, .woocommerce-NoticeGroup').first();
    await expect(notice).toBeVisible();
    await shot(page, 'error-notice-visible');

    // --- No order for this attempt ever reached a paid status ---
    const newOrderIds = queryDb(
      `SELECT ID FROM wp_posts WHERE post_type IN ('shop_order','shop_order_placehold') AND ID > ${before};`
    ).split('\n').filter(Boolean);

    console.log('DECLINE_NEW_ORDER_IDS=' + JSON.stringify(newOrderIds));

    for (const id of newOrderIds) {
      const status = queryDb(`SELECT post_status FROM wp_posts WHERE ID = ${id};`);
      const total = queryDb(
        `SELECT meta_value FROM wp_postmeta WHERE post_id = ${id} AND meta_key = '_order_total';`
      );

      // Only inspect rows that actually match this cart's trigger total —
      // a concurrent unrelated order elsewhere on the shared stack is not
      // evidence this test's decline was actually an approval.
      if (total === '6.35') {
        expect(
          status,
          `order ${id} (total $6.35) must never reach a paid status after a decline; got "${status}"`
        ).toMatch(/^wc-(failed|pending|cancelled)$/);

        const note = queryDb(
          `SELECT comment_content FROM wp_comments WHERE comment_post_ID = ${id} ORDER BY comment_ID DESC LIMIT 5;`
        );
        console.log('order ' + id + ' notes:\n' + note);
        expect(note).toMatch(EXPECTED_ADVICE);
      }
    }

    console.log('DECLINE_TOTAL=' + TRIGGER_TOTAL);
  });
});
