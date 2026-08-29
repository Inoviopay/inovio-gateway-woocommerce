/**
 * Back-office (wp-admin) driver. Same rule as the storefront: real clicks
 * only.
 *
 * This store uses legacy post-based WooCommerce orders (no wp_wc_orders
 * table — HPOS is not enabled here), so order edit pages are the classic
 * `post.php?post={id}&action=edit` screen and the order list is
 * `edit.php?post_type=shop_order`. Both are verified live on this stack.
 */
import { expect } from '@playwright/test';

export const ADMIN = {
  username: 'admin',
  password: 'InovioTest123!',
};

export async function loginAdmin(page) {
  await page.goto('/wp-login.php');

  // Already signed in? Calling this twice in one spec must stay harmless.
  if (!(await page.locator('#user_login').count())) {
    return;
  }

  await page.fill('#user_login', ADMIN.username);
  await page.fill('#user_pass', ADMIN.password);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('body')).toContainText(/Dashboard|Howdy/i, { timeout: 30000 });
}

/** Open an order's edit screen directly by id (the classic post editor). */
export async function openOrder(page, orderId) {
  await page.goto(`/wp-admin/post.php?post=${orderId}&action=edit`);
  await page.waitForLoadState('networkidle');
  await expect(page.locator('body')).toContainText(`Order #${orderId}`, { timeout: 20000 });
}

/** Open the orders list and find an order via the real search box. */
export async function findOrder(page, searchTerm) {
  await page.goto('/wp-admin/edit.php?post_type=shop_order');
  await page.waitForLoadState('networkidle');

  await page.fill('#post-search-input, input[name="s"]', searchTerm);
  await page.locator('#search-submit, input#search-submit').click();
  await page.waitForLoadState('networkidle');
}

/**
 * Refund an order's full amount through the real "Refund" button on the
 * order edit screen, using the plugin's own gateway-backed refund action
 * (`button.do-api-refund`, which calls process_refund() through
 * WooCommerce's own AJAX refund handler — NOT a "manual" bookkeeping-only
 * refund).
 */
export async function refundOrderViaGateway(page, { amount, reason = '', onPanelOpen } = {}) {
  const refundTrigger = page.locator('button.refund-items');
  await expect(refundTrigger).toBeVisible();
  await refundTrigger.click();
  await page.waitForTimeout(500);

  if (amount) {
    await page.locator('#refund_amount').fill(String(amount));
  }
  if (reason) {
    await page.locator('#refund_reason').fill(reason);
  }
  await page.waitForTimeout(300);

  // Optional hook (e.g. a screenshot) once the panel is open and filled,
  // before the trigger button disappears for the rest of this refund.
  if (onPanelOpen) {
    await onPanelOpen();
  }

  page.once('dialog', (dialog) => dialog.accept());
  const gatewayRefundBtn = page.locator('button.do-api-refund');
  await expect(gatewayRefundBtn).toBeVisible();
  await expect(gatewayRefundBtn).toBeEnabled();
  await gatewayRefundBtn.click();

  // The AJAX refund reloads the order-items panel in place.
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
}

const GATEWAY_SETTINGS_URL = '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=inovio';

/** Read the current 3-D Secure setting from the gateway's own settings screen. */
export async function get3dsEnabled(page) {
  await page.goto(GATEWAY_SETTINGS_URL);
  await page.waitForLoadState('networkidle');

  return page.locator('#woocommerce_inovio_threeds_active').isChecked();
}

/** Toggle 3-D Secure on/off through the real gateway settings screen and save. */
export async function set3dsEnabled(page, enabled) {
  await page.goto(GATEWAY_SETTINGS_URL);
  await page.waitForLoadState('networkidle');

  const checkbox = page.locator('#woocommerce_inovio_threeds_active');
  const isChecked = await checkbox.isChecked();

  if (isChecked !== enabled) {
    if (enabled) {
      await checkbox.check();
    } else {
      await checkbox.uncheck();
    }
    await page.locator('button.woocommerce-save-button, button[name="save"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText(/settings have been saved/i, { timeout: 20000 });
  }
}

/**
 * Set the Test Product's regular price through the real product edit screen
 * — the same UI a merchant would use. Used only to engineer an exact cart
 * total for the decline simulator (which keys off the whole order amount);
 * never to advance a checkout flow directly.
 */
export async function setProductPrice(page, productId, price) {
  await page.goto(`/wp-admin/post.php?post=${productId}&action=edit`);
  await page.waitForLoadState('networkidle');

  const priceField = page.locator('#_regular_price');
  await expect(priceField).toBeVisible({ timeout: 15000 });
  await priceField.fill(String(price));
  await page.locator('#publish').click();
  await page.waitForLoadState('networkidle');
  await expect(page.locator('#_regular_price')).toHaveValue(String(price), { timeout: 20000 });
}
