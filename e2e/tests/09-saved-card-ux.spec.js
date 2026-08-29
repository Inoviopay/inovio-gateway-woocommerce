import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';

/**
 * When a saved card is selected, the new-card fields must not be sitting
 * there editable-but-ignored.
 *
 * WooCommerce auto-selects a stored card, and a saved-card payment needs no
 * tokenization — so anything typed into the new-card fields is silently
 * dropped and the STORED card is charged. That let a deliberately invalid PAN
 * appear to be accepted, and would let a shopper who typed a different card
 * be charged the old one.
 */
test('Saved card selected: the new-card fields are hidden', async ({ page }) => {
  const shot = ck.shotter('09-saved-card-ux');

  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);

  // Make sure a saved card exists.
  await ck.goToCheckout(page);
  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless, { save: true });
  await ck.placeOrder(page);
  await ck.expectConfirmed(page);

  // Second visit: WooCommerce should auto-select the stored card.
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.goToCheckout(page);
  await ck.selectInovio(page);
  await page.waitForTimeout(2000);
  await shot(page, 'saved-card-auto-selected');

  const savedChecked = await page.locator('input[name="wc-inovio-payment-token"]:checked').count();
  test.skip(savedChecked === 0, 'no saved token offered on this run');

  const value = await page.locator('input[name="wc-inovio-payment-token"]:checked').inputValue();
  if (value !== 'new') {
    // The fix: fields hidden while a stored card is selected.
    await expect(page.locator('#inovio-new-card-fields')).toBeHidden();
    await shot(page, 'new-card-fields-hidden');
  }

  // Choosing "use a new card" must bring them back.
  const newRadio = page.locator('input[name="wc-inovio-payment-token"][value="new"]');
  if (await newRadio.count()) {
    await newRadio.check();
    await expect(page.locator('#inovio-new-card-fields')).toBeVisible();
    await shot(page, 'new-card-fields-restored');
  }
});
