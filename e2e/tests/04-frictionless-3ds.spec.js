import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

/**
 * 3DS is active but the shopper's card is the ordinary approving PAN
 * (4111111111111111), not Cardinal's documented challenge PAN. This should
 * be a frictionless flow: the gateway resolves 3DS without a step-up, and
 * checkout sails straight through to confirmation — no ACS iframe ever
 * appears.
 *
 * 03-3ds.spec.js already covers the challenge path with the challenge PAN;
 * this is its frictionless counterpart, confirming the two PANs actually
 * produce different behaviour under the same gateway setting.
 */
test.describe('Frictionless 3DS', () => {
  test.afterEach(async ({ page }) => {
    await admin.loginAdmin(page);
    await admin.set3dsEnabled(page, false);
  });

  test('3DS active, frictionless PAN: no challenge, order confirms', async ({ page }) => {
    const shot = ck.shotter('04-frictionless-3ds');

    await admin.loginAdmin(page);
    await admin.set3dsEnabled(page, true);
    await shot(page, 'admin-3ds-enabled');

    // Watch for the ACS iframe ever attaching, across the whole checkout —
    // not just a point-in-time check after placing the order.
    let acsFrameSeen = false;
    page.on('frameattached', (frame) => {
      const url = frame.url();
      if (/creq|cardinal|centinelapi/i.test(url)) {
        acsFrameSeen = true;
      }
    });

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.goToCheckout(page);
    await ck.fillBilling(page);
    await ck.selectInovio(page);
    await ck.fillCard(page, ck.CARDS.frictionless);
    await shot(page, 'frictionless-pan-entered');

    await ck.placeOrder(page);

    const orderId = await ck.expectConfirmed(page);
    expect(orderId).toBeTruthy();
    await shot(page, 'confirmed-no-challenge');
    console.log('FRICTIONLESS_3DS_ORDER=' + orderId);

    // The named challenge iframe must never have become visible.
    const challengeIframe = page.locator('iframe[name="inovio-3ds-challenge"]');
    const challengeCount = await challengeIframe.count();
    if (challengeCount > 0) {
      await expect(challengeIframe).not.toBeVisible();
    }
    expect(acsFrameSeen, 'no Cardinal ACS frame should ever have attached').toBe(false);
  });
});
