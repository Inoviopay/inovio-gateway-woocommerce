import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

/**
 * 3D Secure with Cardinal's documented challenge PAN.
 *
 * 3DS is off by default on this store, so this spec turns it on through the
 * real gateway settings screen first (WooCommerce > Settings > Payments >
 * Inovio Payment Gateway > "3-D Secure"), and always restores it afterward
 * regardless of outcome.
 *
 * The ACS step-up renders as a NESTED cross-origin iframe: the plugin's own
 * overlay iframe (name="inovio-3ds-challenge") loads Cardinal's
 * Cruise/StepUp page, which itself embeds the real ACS `creq` frame holding
 * the OTP field. Playwright can drive a cross-origin frame directly, so the
 * OTP is typed there for real — no POSTing to our own return endpoint, no
 * DB writes.
 */
test.describe('3DS challenge', () => {
  test.afterEach(async ({ page }) => {
    await admin.loginAdmin(page);
    await admin.set3dsEnabled(page, false);
  });

  test('3DS: challenge is presented and completed by the shopper', async ({ page }) => {
    const shot = ck.shotter('03-3ds');

    await admin.loginAdmin(page);
    await admin.set3dsEnabled(page, true);
    await shot(page, 'admin-3ds-enabled');

    await ck.login(page);
    await ck.emptyCart(page);
    await ck.addProduct(page);
    await ck.goToCheckout(page);
    await ck.fillBilling(page);
    await ck.selectInovio(page);
    await ck.fillCard(page, ck.CARDS.challenge);
    await shot(page, 'challenge-pan-entered');

    await ck.placeOrder(page);

    // The challenge host page renders, then the ACS iframe appears.
    await page.waitForTimeout(6000);
    await shot(page, 'after-place-order');

    const frames = page.frames().map((f) => f.url());
    console.log('FRAMES=' + JSON.stringify(frames, null, 1));

    /*
     * Cardinal nests the real challenge: the plugin's overlay iframe loads
     * Cruise/StepUp, which itself embeds the ACS `creq` frame holding the
     * OTP field.
     */
    const acsFrame = page.frames().find((f) => /creq/.test(f.url()))
      || page.frames().find((f) => /cardinal/i.test(f.url()));
    expect(acsFrame, 'Cardinal ACS frame should be present').toBeTruthy();

    const otpField = acsFrame.locator(
      'input[type="text"], input[type="tel"], input[type="password"], input[name*="challenge" i]'
    ).first();
    await otpField.waitFor({ state: 'visible', timeout: 45000 });
    await shot(page, 'acs-challenge-visible');

    // Cardinal's sandbox OTP.
    await otpField.fill('1234');
    await shot(page, 'otp-entered');

    await acsFrame.locator('input[type="submit"], button[type="submit"], #submitButton').first().click();

    const orderId = await ck.expectConfirmed(page);
    expect(orderId).toBeTruthy();
    await shot(page, 'confirmed-after-challenge');

    console.log('THREEDS_CHALLENGE_ORDER=' + orderId);
  });
});
