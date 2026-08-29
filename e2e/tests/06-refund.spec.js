import { test, expect } from '@playwright/test';
import * as ck from '../lib/checkout.js';
import * as admin from '../lib/admin.js';

/**
 * Place a normal Sale order, then refund it through WooCommerce's own
 * back-office Refund UI on the order edit screen — the gateway-backed
 * "Refund $X via Inovio Payment Gateway" button, which calls the plugin's
 * process_refund() through WooCommerce's own AJAX refund handler. This is
 * NOT the "manually" bookkeeping-only refund button that sits beside it.
 *
 * Verified live before writing this spec: refunding an order placed only
 * moments earlier (unsettled at the processor) routes through the gateway's
 * own reverseCapture() fallback
 * (Inovio_Gateway_Client::refund_order — refund() first, then reverseCapture
 * on the gateway's SERVICE 536 "not settled" response), and produces a real
 * order note like "Inovio refund succeeded (10.00 via CCREVERSECAP)".
 */
test('Refund a Sale order from the WooCommerce admin order screen', async ({ page }) => {
  const shot = ck.shotter('06-refund');

  // Shopper places a normal Sale order.
  await ck.login(page);
  await ck.emptyCart(page);
  await ck.addProduct(page);
  await ck.goToCheckout(page);
  await ck.fillBilling(page);
  await ck.selectInovio(page);
  await ck.fillCard(page, ck.CARDS.frictionless);
  await ck.placeOrder(page);
  const orderId = await ck.expectConfirmed(page);
  expect(orderId).toBeTruthy();
  await shot(page, 'order-placed');
  console.log('REFUND_ORDER=' + orderId);

  // Merchant opens the order in wp-admin.
  await admin.loginAdmin(page);
  await admin.openOrder(page, orderId);
  await shot(page, 'admin-order-detail');

  // The plugin's own "Inovio references" panel on the order screen — real
  // gateway references (PO_ID/TRANS_ID), not placeholders.
  await expect(page.locator('body')).toContainText(/PO_ID:\s*\d+/);
  await expect(page.locator('body')).toContainText(/TRANS_ID:\s*\d+/);

  /*
   * Enter refund mode and refund the full order total via the gateway.
   *
   * refundOrderViaGateway() owns the whole open-panel-then-submit sequence
   * itself (it clicks button.refund-items to open the panel, then
   * button.do-api-refund to submit) — WooCommerce hides the "Refund"
   * trigger once the panel is open, so it must only ever be clicked once
   * per refund attempt.
   */
  await admin.refundOrderViaGateway(page, {
    amount: '10.00',
    reason: 'e2e refund test',
    onPanelOpen: () => shot(page, 'refund-mode-opened'),
  });
  await shot(page, 'after-refund-submit');

  await expect(page.locator('body')).not.toContainText(/error occurred|refund failed/i);

  // WooCommerce's own, UI-visible record of the refund: the order status
  // flips to Refunded, a "Refund #..." line item appears with the negative
  // amount, and Net Payment reflects it.
  await expect(page.locator('#order_status')).toHaveValue('wc-refunded');
  await expect(page.locator('body')).toContainText(/Refund #\d+/);
  await expect(page.locator('body')).toContainText('-$10.00');
  await shot(page, 'refund-recorded');

  // The plugin's own order note names the real gateway action used.
  await expect(page.locator('#woocommerce-order-notes')).toContainText(
    /Inovio refund succeeded/i,
    { timeout: 20000 }
  );
  await shot(page, 'refund-note-visible');
});
