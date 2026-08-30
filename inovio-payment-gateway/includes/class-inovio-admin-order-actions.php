<?php
/**
 * Capture and Void as WooCommerce admin order actions.
 *
 * WooCommerce has no native authorize/capture split — an order is either paid
 * or it is not — so an authorize-only order sits in `on-hold` and the merchant
 * needs an explicit control to take or release the money. The order-actions
 * dropdown (Order screen > Order actions) is the platform's own extension point
 * for exactly this, and it comes with WooCommerce's nonce checks and the
 * `edit_shop_orders` capability already applied.
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Inovio\Gateway\Errors\GatewayTimeoutException;

/**
 * Adds Capture and Void to the order actions dropdown.
 */
class Inovio_Admin_Order_Actions {

	/**
	 * Order-action keys.
	 */
	const ACTION_CAPTURE = 'inovio_capture';
	const ACTION_VOID    = 'inovio_void';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'register_actions' ) );
		add_action( 'woocommerce_order_action_' . self::ACTION_CAPTURE, array( __CLASS__, 'handle_capture' ) );
		add_action( 'woocommerce_order_action_' . self::ACTION_VOID, array( __CLASS__, 'handle_void' ) );

		// Show the gateway references on the order screen — support cases are
		// worked from the PO_ID, so it must be visible without a log dive.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_references' ) );
	}

	/**
	 * Offer Capture/Void only when they can actually succeed.
	 *
	 * Both are offered exclusively for an Inovio order that was authorized (not
	 * already captured) and is still awaiting capture. Showing a Capture button
	 * on a completed sale would produce a guaranteed gateway error.
	 *
	 * @param array<string, string> $actions Existing order actions.
	 * @return array<string, string>
	 */
	public static function register_actions( $actions ) {
		global $theorder;

		$order = $theorder;

		if ( ! $order instanceof WC_Order || ! self::is_capturable( $order ) ) {
			return $actions;
		}

		$actions[ self::ACTION_CAPTURE ] = __( 'Inovio: capture authorized payment', 'inovio-payment-gateway' );
		$actions[ self::ACTION_VOID ]    = __( 'Inovio: void authorization', 'inovio-payment-gateway' );

		return $actions;
	}

	/**
	 * Whether the order is an Inovio authorization awaiting capture.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function is_capturable( $order ) {
		return Inovio_Payment_Gateway::GATEWAY_ID === $order->get_payment_method()
			&& 'authorize' === $order->get_meta( Inovio_Gateway_Client::META_PAYMENT_ACTION )
			&& 'yes' !== $order->get_meta( Inovio_Gateway_Client::META_CAPTURED )
			&& $order->has_status( 'on-hold' )
			&& '' !== (string) $order->get_meta( Inovio_Gateway_Client::META_PO_ID );
	}

	/**
	 * Capture the full authorized amount.
	 *
	 * Captures in full only: WooCommerce's order-actions dropdown has no
	 * amount field, and inventing a partial-capture UI here would be guesswork.
	 * Partial capture is noted as unimplemented in the README rather than
	 * half-built.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function handle_capture( $order ) {
		if ( ! self::is_capturable( $order ) ) {
			self::note( $order, __( 'Inovio: capture skipped — this order is not awaiting capture.', 'inovio-payment-gateway' ) );

			return;
		}

		try {
			$result = Inovio_Gateway_Client::capture_order( $order );
		} catch ( GatewayTimeoutException $e ) {
			// The capture's outcome is UNKNOWN — reconcile via status() before
			// reporting a failure, or a genuinely successful capture would be
			// told to the merchant as failed.
			$recovered = Inovio_Gateway_Client::reconcile_timeout( $order, $e, array( 'CCCAPTURE', 'CCAUTHCAP' ) );

			if ( null === $recovered ) {
				self::note(
					$order,
					__( 'Inovio capture did not respond in time. The capture may have succeeded — verify in the Inovio portal before retrying.', 'inovio-payment-gateway' )
				);

				return;
			}

			$result = $recovered;
		} catch ( \Throwable $e ) {
			Inovio_Logger::error( 'capture failed on order ' . $order->get_id() . ': ' . $e->getMessage() );
			self::note(
				$order,
				sprintf(
					/* translators: %s: error message. */
					__( 'Inovio capture failed: %s', 'inovio-payment-gateway' ),
					$e->getMessage()
				)
			);

			return;
		}

		if ( ! Inovio_Gateway_Client::is_approved( $result ) ) {
			$advice = Inovio_Gateway_Client::advice( $result );
			self::note(
				$order,
				sprintf(
					/* translators: %s: gateway advice. */
					__( 'Inovio capture refused by the gateway: %s', 'inovio-payment-gateway' ),
					$advice ? $advice : $result->status
				)
			);

			return;
		}

		$order->update_meta_data( Inovio_Gateway_Client::META_CAPTURED, 'yes' );
		$order->save();

		self::note(
			$order,
			sprintf(
				/* translators: %s: gateway PO_ID. */
				__( 'Inovio payment captured (PO_ID %s).', 'inovio-payment-gateway' ),
				$result->orderRef ? $result->orderRef->poId() : '-'
			)
		);

		// payment_complete() moves the order out of on-hold to processing /
		// completed and records it as paid.
		$order->payment_complete( $result->transactionId ? $result->transactionId->value() : '' );
	}

	/**
	 * Void (reverse) an authorization that has not been captured.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function handle_void( $order ) {
		if ( ! self::is_capturable( $order ) ) {
			self::note( $order, __( 'Inovio: void skipped — this order has no open authorization.', 'inovio-payment-gateway' ) );

			return;
		}

		try {
			$result = Inovio_Gateway_Client::void_order( $order );
		} catch ( GatewayTimeoutException $e ) {
			// The void's outcome is UNKNOWN — reconcile via status() before
			// reporting a failure, or a genuinely successful void would be
			// told to the merchant as failed.
			$recovered = Inovio_Gateway_Client::reconcile_timeout( $order, $e, array( 'CCREVERSE', 'CCREVERSECAP' ) );

			if ( null === $recovered ) {
				self::note(
					$order,
					__( 'Inovio void did not respond in time. The void may have succeeded — verify in the Inovio portal before retrying.', 'inovio-payment-gateway' )
				);

				return;
			}

			$result = $recovered;
		} catch ( \Throwable $e ) {
			Inovio_Logger::error( 'void failed on order ' . $order->get_id() . ': ' . $e->getMessage() );
			self::note(
				$order,
				sprintf(
					/* translators: %s: error message. */
					__( 'Inovio void failed: %s', 'inovio-payment-gateway' ),
					$e->getMessage()
				)
			);

			return;
		}

		if ( ! Inovio_Gateway_Client::is_approved( $result ) ) {
			$advice = Inovio_Gateway_Client::advice( $result );
			self::note(
				$order,
				sprintf(
					/* translators: %s: gateway advice. */
					__( 'Inovio void refused by the gateway: %s', 'inovio-payment-gateway' ),
					$advice ? $advice : $result->status
				)
			);

			return;
		}

		self::note( $order, __( 'Inovio authorization voided.', 'inovio-payment-gateway' ) );
		$order->update_status( 'cancelled', __( 'Inovio: authorization voided.', 'inovio-payment-gateway' ) );
	}

	/**
	 * Show the gateway references on the admin order screen.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function render_references( $order ) {
		if ( Inovio_Payment_Gateway::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		$po_id    = (string) $order->get_meta( Inovio_Gateway_Client::META_PO_ID );
		$trans_id = (string) $order->get_meta( Inovio_Gateway_Client::META_TRANS_ID );
		$eci      = (string) $order->get_meta( Inovio_Gateway_Client::META_ECI );

		if ( '' === $po_id && '' === $trans_id ) {
			return;
		}

		echo '<div class="address inovio-references">';
		echo '<p><strong>' . esc_html__( 'Inovio references', 'inovio-payment-gateway' ) . '</strong><br />';
		echo esc_html__( 'PO_ID:', 'inovio-payment-gateway' ) . ' ' . esc_html( $po_id ? $po_id : '-' ) . '<br />';
		echo esc_html__( 'TRANS_ID:', 'inovio-payment-gateway' ) . ' ' . esc_html( $trans_id ? $trans_id : '-' );

		if ( '' !== $eci ) {
			echo '<br />' . esc_html__( '3DS ECI:', 'inovio-payment-gateway' ) . ' ' . esc_html( $eci );
		}

		echo '</p></div>';
	}

	/**
	 * Add an order note and log it.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $message Note text.
	 * @return void
	 */
	private static function note( $order, $message ) {
		$order->add_order_note( $message );
		Inovio_Logger::error( 'order ' . $order->get_id() . ': ' . wp_strip_all_tags( $message ) );
	}
}
