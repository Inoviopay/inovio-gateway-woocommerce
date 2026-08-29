import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';

/**
 * Vaulting: save a card at checkout, then pay with it on a second order
 * WITHOUT re-entering the PAN. The second order must never re-tokenize.
 *
 * Verified live: WooCommerce's own saved-payment-methods UI
 * (WC_Payment_Tokens, rendered by the gateway's saved_payment_methods() call)
 * auto-checks the most recently saved token by default on a later checkout —
 * this is core behaviour, not something the spec forces.
 */
test('Vault: save a card, then reuse it on a later order', async ({ page }) => {
  const shot = ck.shotter('02-vault');

  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.goToCheckout(page);
  await ck.fillBilling(page);
  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless, { save: true });
  await shot(page, 'save-card-checked');

  await ck.placeOrder(page);
  const first = await ck.expectConfirmed(page);
  expect(first).toBeTruthy();
  await shot(page, 'first-order-confirmed');
  console.log('VAULT_FIRST_ORDER=' + first);

  // Second order: pay with the stored card, no PAN re-entry.
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.goToCheckout(page);
  await ck.fillBilling(page);
  await ck.selectInovio(page);

  const saved = await ck.selectSavedCard(page);
  await expect(saved).toBeChecked();
  await shot(page, 'saved-card-selected');

  // The "new card" fields must be empty — nothing was typed for this order.
  await expect(page.locator('#inovio-card-number')).toHaveValue('');

  await ck.placeOrder(page);
  const second = await ck.expectConfirmed(page);
  await shot(page, 'reuse-confirmed');

  expect(second).toBeTruthy();
  expect(second).not.toBe(first);
  console.log('VAULT_ORDERS=' + first + ',' + second);
});
