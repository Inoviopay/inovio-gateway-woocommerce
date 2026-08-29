<?php
/**
 * WooCommerce Checkout Blocks integration.
 *
 * A default WooCommerce install (8.3+) puts the Block Checkout
 * (`woocommerce/checkout`) on the checkout page, not the classic
 * `[woocommerce_checkout]` shortcode. Blocks payment methods are a SEPARATE
 * registration system from `WC_Payment_Gateway` — declaring a class that
 * extends `WC_Payment_Gateway` is not enough for the gateway to appear there.
 * Without this class the plugin is invisible on a default install and the
 * shopper sees "There are no payment methods available."
 *
 * This class only wires the Blocks *registration* (which script to load, and
 * what data to hand it). It deliberately does not re-implement any payment
 * logic: the JS it enqueues performs the same direct-post tokenization as the
 * classic checkout JS, and the resulting `paymentMethodData` is delivered to
 * `Inovio_Payment_Gateway::process_payment()` unchanged — see
 * `Automattic\WooCommerce\StoreApi\Legacy::process_legacy_payment()`, which
 * sets `$_POST = $context->payment_data` before calling `process_payment()`.
 * That is why `collect_payment_data()` in the gateway class needed no changes
 * at all: it already reads token fields out of `$_POST` by name, and Blocks
 * delivers those same field names there.
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the Inovio payment method with WooCommerce Blocks.
 */
class Inovio_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Must match Inovio_Payment_Gateway::GATEWAY_ID — this is the name Blocks
	 * uses to match the payment method against the gateway instance server-side
	 * (`PaymentContext::get_payment_method_instance()` looks it up by this id),
	 * and it is also the `name` the JS payment method registers under.
	 *
	 * @var string
	 */
	protected $name = Inovio_Payment_Gateway::GATEWAY_ID;

	/**
	 * Load the gateway's saved settings.
	 *
	 * Blocks instantiates this class on the `init` hook (see
	 * `PaymentMethodRegistry::initialize()`), independently of the classic
	 * `Inovio_Payment_Gateway` instance WooCommerce also constructs for the
	 * shortcode checkout — so settings are read directly from the option
	 * rather than borrowed from that other instance.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_inovio_settings', array() );
	}

	/**
	 * Whether the payment method should be offered on the Block Checkout.
	 *
	 * Mirrors `Inovio_Payment_Gateway::is_available()` exactly — same enabled
	 * flag, same "fully configured" requirement, so a merchant never sees the
	 * gateway available on one checkout type but not the other.
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( 'yes' !== $this->get_setting( 'enabled', 'no' ) ) {
			return false;
		}

		foreach ( array( 'req_username', 'req_password', 'site_id', 'site_key', 'product_id' ) as $key ) {
			if ( '' === trim( (string) $this->get_setting( $key, '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Register and return the script handle(s) the Block Checkout should load.
	 *
	 * `wp_register_script()` (not the block-editor `Api::register_script()`
	 * helper core payment methods use) because this is a third-party plugin
	 * asset living in its own plugin directory, not a file inside the
	 * WooCommerce Blocks package. The dependency list is exactly what the
	 * script needs: the payment-method registry to register into, the
	 * checkout-events bus (`onPaymentSetup`/`onCheckoutSuccess`) for the
	 * tokenize hook and the 3DS hand-off, the settings store for
	 * `getPaymentMethodData()`, and `wp-element` for the field markup.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'inovio-checkout-blocks',
			INOVIO_WC_PLUGIN_URL . 'assets/js/inovio-checkout-blocks.js',
			array(
				'wc-blocks-registry',
				'wc-blocks-checkout-events',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			INOVIO_WC_VERSION,
			true
		);

		wp_register_style(
			'inovio-checkout-blocks',
			INOVIO_WC_PLUGIN_URL . 'assets/css/inovio-checkout.css',
			array(),
			INOVIO_WC_VERSION
		);
		wp_enqueue_style( 'inovio-checkout-blocks' );

		return array( 'inovio-checkout-blocks' );
	}

	/**
	 * Data made available to the JS via `wc.wcSettings.getPaymentMethodData('inovio')`.
	 *
	 * Deliberately the same shape as the `inovioConfig` object
	 * `Inovio_Payment_Gateway::payment_scripts()` localizes for the classic
	 * checkout, so the Blocks JS can share its tokenize/3DS logic against
	 * either config object without a translation layer. Note what stays
	 * absent, same as the classic path: no card data of any kind, no site key
	 * (the HMAC signature is minted server-side, per request, by
	 * Inovio_Signature_Endpoint — the key itself never leaves this server).
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		return array(
			'title'           => $this->get_setting( 'title', __( 'Credit card', 'inovio-payment-gateway' ) ),
			'description'     => $this->get_setting( 'description', '' ),
			'supports'        => $this->get_supported_features(),
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'signatureAction' => Inovio_Signature_Endpoint::ACTION,
			'signatureNonce'  => wp_create_nonce( Inovio_Signature_Endpoint::NONCE_ACTION ),
			'prepareAction'   => Inovio_ThreeDS_Controller::PREPARE_ACTION,
			'prepareNonce'    => wp_create_nonce( Inovio_ThreeDS_Controller::NONCE_ACTION ),
			'tokenUrl'        => Inovio_Gateway_Client::token_endpoint(),
			'threeDsActive'   => Inovio_Gateway_Client::setting_enabled( 'threeds_active' ),
			'vaultActive'     => Inovio_Gateway_Client::setting_enabled( 'vault_active' ) && is_user_logged_in(),
			'translations'    => array(
				'invalidCard'    => __( 'Please enter a valid card number.', 'inovio-payment-gateway' ),
				'invalidExpiry'  => __( 'Please enter a valid expiration date.', 'inovio-payment-gateway' ),
				'invalidCvv'     => __( 'Please enter a valid security code.', 'inovio-payment-gateway' ),
				'tokenizeFailed' => __( 'Card could not be processed. Please try again.', 'inovio-payment-gateway' ),
				'unreachable'    => __( 'Could not reach the payment service. Please try again.', 'inovio-payment-gateway' ),
				'signFailed'     => __( 'Payment signing failed. Please refresh and try again.', 'inovio-payment-gateway' ),
				'threeDsFailed'  => __( 'Card authentication could not be started. Please try again.', 'inovio-payment-gateway' ),
				'authFailed'     => __( 'Payment authentication failed.', 'inovio-payment-gateway' ),
				'cardNumber'     => __( 'Card number', 'inovio-payment-gateway' ),
				'expiry'         => __( 'Expiry', 'inovio-payment-gateway' ),
				'securityCode'   => __( 'Security code', 'inovio-payment-gateway' ),
				'saveCard'       => __( 'Save this card for future purchases', 'inovio-payment-gateway' ),
			),
		);
	}

	/**
	 * Features shown as supported to the Block Checkout.
	 *
	 * 'products' is required for any method to appear at all. Saved cards in
	 * Blocks are NOT wired up in this pass (see the JS file's header
	 * docblock) so 'tokenization' is deliberately left out here even though
	 * the classic `WC_Payment_Gateway::$supports` includes it — advertising it
	 * on the Blocks side would draw a "use saved card" UI this integration
	 * cannot yet honor.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		return array( 'products' );
	}
}
