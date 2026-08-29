<?php
/**
 * WooCommerce logging for the Inovio gateway.
 *
 * Every gateway refusal, decline and error goes through here. Nothing is
 * swallowed: a failure the shopper sees as "payment declined" must always have
 * a corresponding line in the log naming the reason, or the merchant has no way
 * to tell a decline from a broken integration.
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over wc_get_logger() with a fixed channel.
 */
class Inovio_Logger {

	/**
	 * WooCommerce log channel — surfaces at WooCommerce > Status > Logs.
	 */
	const SOURCE = 'inovio';

	/**
	 * Whether the merchant enabled debug logging.
	 *
	 * @var bool|null
	 */
	private static $debug = null;

	/**
	 * Read the gateway's debug setting.
	 *
	 * Read straight from the options row rather than instantiating the gateway:
	 * the logger is used from inside the gateway's own constructor path, and
	 * building a gateway to decide whether to log would recurse.
	 *
	 * @return bool
	 */
	private static function debug_enabled() {
		if ( null === self::$debug ) {
			$settings    = get_option( 'woocommerce_inovio_settings', array() );
			self::$debug = is_array( $settings ) && isset( $settings['debug'] ) && 'yes' === $settings['debug'];
		}

		return self::$debug;
	}

	/**
	 * Log a routine message. Written only when debug logging is enabled.
	 *
	 * @param string $message Message to log. Never card data — a PAN never reaches this server.
	 * @return void
	 */
	public static function debug( $message ) {
		if ( self::debug_enabled() ) {
			self::write( 'debug', $message );
		}
	}

	/**
	 * Log a message that must always be recorded regardless of the debug
	 * setting: declines, refusals, gateway errors.
	 *
	 * These are never gated behind the debug flag. A merchant who has debug
	 * logging off still needs to see why a payment failed.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	public static function error( $message ) {
		self::write( 'error', $message );
	}

	/**
	 * Write to the WooCommerce log.
	 *
	 * @param string $level   WC_Log_Levels level.
	 * @param string $message Message to log.
	 * @return void
	 */
	private static function write( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
