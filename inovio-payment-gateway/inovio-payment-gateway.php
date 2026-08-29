<?php
/**
 * Plugin Name: Inovio Payment Gateway for WooCommerce
 * Plugin URI:  https://www.inoviopay.com/
 * Description: Accept credit cards through the Inovio gateway. The card number never touches this server — the browser tokenizes it directly with Inovio and WordPress only ever sees a single-use token.
 * Version:     1.0.0
 * Author:      Inovio Payments
 * Author URI:  https://www.inoviopay.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: inovio-payment-gateway
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * WC tested up to: 11.0
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

define( 'INOVIO_WC_VERSION', '1.0.0' );
define( 'INOVIO_WC_PLUGIN_FILE', __FILE__ );
define( 'INOVIO_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'INOVIO_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The vendored SDK's classmap autoloader.
 *
 * The SDK is vendored rather than required from Packagist because it is not
 * published there, and a WordPress plugin must install from a self-contained
 * ZIP with no `composer install` step on the target site. The SDK has zero
 * Composer dependencies, so the small classmap loader is all it needs.
 */
require_once INOVIO_WC_PLUGIN_DIR . 'vendor/inovio/autoload.php';

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 *
 * The plugin reads and writes order data exclusively through the CRUD API
 * (`$order->get_meta()` / `update_meta_data()`), never through direct post-meta
 * SQL, so it is HPOS-safe. Without this declaration WooCommerce shows the
 * plugin as incompatible and refuses to enable HPOS.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', INOVIO_WC_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', INOVIO_WC_PLUGIN_FILE, false );
		}
	}
);

/**
 * Boot the plugin once WooCommerce itself is loaded.
 *
 * WC_Payment_Gateway does not exist until WooCommerce has loaded, so every
 * class that extends it must be required from here rather than at file scope.
 */
add_action( 'plugins_loaded', 'inovio_wc_init', 11 );

/**
 * Load the plugin classes and register hooks.
 *
 * Refuses to load — loudly, via an admin notice — when a hard requirement is
 * missing. It deliberately does NOT degrade to a partial mode: a payment
 * gateway that half-works is worse than one that is visibly absent.
 *
 * @return void
 */
function inovio_wc_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'inovio_wc_notice_no_woocommerce' );
		return;
	}

	// Money amounts are computed with bcmath so they never touch a binary
	// float. The SDK's Money type requires it; there is no fallback path.
	if ( ! extension_loaded( 'bcmath' ) ) {
		add_action( 'admin_notices', 'inovio_wc_notice_no_bcmath' );
		return;
	}

	require_once INOVIO_WC_PLUGIN_DIR . 'includes/class-inovio-logger.php';
	require_once INOVIO_WC_PLUGIN_DIR . 'includes/class-inovio-http-client.php';
	require_once INOVIO_WC_PLUGIN_DIR . 'includes/class-inovio-gateway-client.php';
	require_once INOVIO_WC_PLUGIN_DIR . 'includes/class-inovio-vault.php';
	require_once INOVIO_WC_PLUGIN_DIR . 'includes/class-inovio-signature-endpoint.php';
	require_once INOVIO_WC_PLUGIN_DIR . 'includes/class-inovio-threeds-controller.php';
	require_once INOVIO_WC_PLUGIN_DIR . 'includes/class-inovio-admin-order-actions.php';
	require_once INOVIO_WC_PLUGIN_DIR . 'includes/class-inovio-payment-gateway.php';

	Inovio_Signature_Endpoint::init();
	Inovio_ThreeDS_Controller::init();
	Inovio_Admin_Order_Actions::init();

	add_filter( 'woocommerce_payment_gateways', 'inovio_wc_register_gateway' );
	add_filter( 'plugin_action_links_' . plugin_basename( INOVIO_WC_PLUGIN_FILE ), 'inovio_wc_settings_link' );
}

/**
 * Register the gateway with WooCommerce.
 *
 * @param array $gateways Registered gateway class names.
 * @return array
 */
function inovio_wc_register_gateway( $gateways ) {
	$gateways[] = 'Inovio_Payment_Gateway';

	return $gateways;
}

/**
 * Add a "Settings" link on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function inovio_wc_settings_link( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=inovio' );

	array_unshift(
		$links,
		'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'inovio-payment-gateway' ) . '</a>'
	);

	return $links;
}

/**
 * Admin notice: WooCommerce is not active.
 *
 * @return void
 */
function inovio_wc_notice_no_woocommerce() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Inovio Payment Gateway requires WooCommerce to be installed and active.', 'inovio-payment-gateway' );
	echo '</p></div>';
}

/**
 * Admin notice: the bcmath extension is missing.
 *
 * @return void
 */
function inovio_wc_notice_no_bcmath() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Inovio Payment Gateway requires the PHP bcmath extension (payment amounts are computed without binary floats).', 'inovio-payment-gateway' );
	echo '</p></div>';
}

/**
 * Activation guard.
 *
 * Checked at activation as well as on every load, so a site missing bcmath
 * fails at the moment the merchant clicks Activate rather than silently at
 * the first checkout.
 *
 * @return void
 */
function inovio_wc_activate() {
	if ( ! extension_loaded( 'bcmath' ) ) {
		deactivate_plugins( plugin_basename( INOVIO_WC_PLUGIN_FILE ) );
		wp_die(
			esc_html__( 'Inovio Payment Gateway requires the PHP bcmath extension.', 'inovio-payment-gateway' ),
			esc_html__( 'Plugin activation failed', 'inovio-payment-gateway' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'inovio_wc_activate' );
