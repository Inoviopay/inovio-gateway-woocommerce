/**
 * Restores the checkout page to the Block Checkout after this suite runs —
 * the counterpart to global-setup.js. Runs regardless of whether the suite
 * passed or failed (Playwright always runs globalTeardown once globalSetup
 * has completed), so a failed run never leaves the store on the classic
 * shortcode by accident.
 */
import { BLOCK_CONTENT, setCheckoutPageContent } from './global-setup.js';

/**
 * @returns {Promise<void>}
 */
export default async function globalTeardown() {
	setCheckoutPageContent( BLOCK_CONTENT );
}
