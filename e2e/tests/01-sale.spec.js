import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';

/**
 * The baseline path: add to cart, check out, pay with a fresh card, land on
 * the order-received page.
 */
test('Sale: add to cart, pay with a new card, order confirms', async ({ page }) => {
  const shot = ck.shotter('01-sale');

  await ck.login(page);
  await shot(page, 'logged-in');

  await ck.emptyCart(page);
  await ck.addProduct(page);
  await shot(page, 'product-added-to-cart');

  await ck.goToCheckout(page);
  await ck.fillBilling(page);
  await shot(page, 'billing-filled');

  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless);
  await shot(page, 'card-entered');

  await ck.placeOrder(page);
  await shot(page, 'order-submitted');

  const orderId = await ck.expectConfirmed(page);
  expect(orderId).toBeTruthy();
  await shot(page, 'order-confirmed');

  console.log('SALE_ORDER_ID=' + orderId);
});
