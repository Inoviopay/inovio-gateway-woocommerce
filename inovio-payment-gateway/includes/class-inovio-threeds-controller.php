<?php
/**
 * The two 3-D Secure browser-facing endpoints.
 *
 *  - PREPARE (wp-ajax): opens a 3DS session for a BIN and returns the
 *    device-data-collection JWT/URL the browser posts into a hidden iframe.
 *  - RETURN (public URL): where the ACS POSTs the challenge outcome after the
 *    cardholder finishes authentication; runs the completion leg.
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Inovio\Gateway\Errors\GatewayTimeoutException;
use Inovio\Gateway\Model\ThreeDSChallengeResult;
use Inovio\Gateway\Result\TransactionResult;
use Inovio\Gateway\ThreeDSPrepare;

/**
 * 3DS prepare and ACS-return handling.
 */
class Inovio_ThreeDS_Controller {

	/**
	 * AJAX action for the DDC prepare call.
	 */
	const PREPARE_ACTION = 'inovio_threeds_prepare';

	/**
	 * Nonce action for the prepare call.
	 */
	const NONCE_ACTION = 'inovio_threeds_nonce';

	/**
	 * Query var marking a request as the ACS return.
	 */
	const RETURN_VAR = 'inovio_3ds_return';

	/**
	 * WooCommerce session key holding the prepare rate-limit hit timestamps.
	 *
	 * Deliberately distinct from Inovio_Signature_Endpoint::RATE_KEY — the two
	 * endpoints are rate limited independently.
	 */
	const RATE_KEY = 'inovio_3ds_prepare_hits';

	/**
	 * Rate-limit window, seconds.
	 */
	const RATE_WINDOW = 60;

	/**
	 * Maximum prepare calls per window, per session.
	 *
	 * prepare() is heavier than the signature endpoint's signing call (it opens
	 * a full 3DS session with the gateway), so this is deliberately tighter
	 * than Inovio_Signature_Endpoint::RATE_MAX's 12/60s — a normal checkout
	 * calls prepare once per attempt, at most a couple of times across retries.
	 */
	const RATE_MAX = 8;

	/**
	 * Register the endpoints.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::PREPARE_ACTION, array( __CLASS__, 'handle_prepare' ) );
		add_action( 'wp_ajax_nopriv_' . self::PREPARE_ACTION, array( __CLASS__, 'handle_prepare' ) );

		// The ACS return is a plain cross-site POST to the site root carrying
		// our query var; it cannot be an admin-ajax call because some ACS
		// implementations strip query strings from the return URL's path.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_return' ) );
	}

	/**
	 * The URL the ACS POSTs the challenge outcome to.
	 *
	 * The order id and a per-order key ride on the URL because this is a
	 * cross-site POST that may arrive with no session cookie at all.
	 *
	 * @param WC_Order $order Order being authenticated.
	 * @return string
	 */
	public static function return_url( $order ) {
		return add_query_arg(
			array(
				self::RETURN_VAR => '1',
				'order_id'       => $order->get_id(),
				'key'            => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	// ------------------------------------------------------------- prepare

	/**
	 * Start a 3DS session for a BIN and return what the DDC iframe needs.
	 *
	 * Only the first 6 digits of the card reach this endpoint. A BIN is not
	 * cardholder data and cannot be used to reconstruct a PAN.
	 *
	 * @return void
	 */
	public static function handle_prepare() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			/*
			 * Log it: a silent refusal here means the 3DS block never gets
			 * attached and the transaction quietly proceeds WITHOUT 3DS. That
			 * exact failure — a swallowed 403 — is why this is logged at error
			 * level rather than debug.
			 */
			Inovio_Logger::error( '3DS prepare refused: invalid_nonce' );
			wp_send_json( array( 'error' => 'invalid_nonce' ), 403 );
		}

		if ( ! self::within_rate_limit() ) {
			Inovio_Logger::error( '3DS prepare refused: rate_limited' );
			wp_send_json( array( 'error' => 'rate_limited' ), 403 );
		}

		$bin = isset( $_POST['bin'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['bin'] ) ) ) : '';
		if ( strlen( (string) $bin ) < 6 ) {
			Inovio_Logger::error( '3DS prepare refused: bin too short' );
			wp_send_json( array( 'error' => 'bad_bin' ), 400 );
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			Inovio_Logger::error( '3DS prepare refused: no_cart' );
			wp_send_json( array( 'error' => 'no_cart' ), 403 );
		}

		// prepare() needs the transaction currency and the billing country to
		// resolve merchant-account distribution.
		$currency = get_woocommerce_currency();
		$country  = WC()->customer ? WC()->customer->get_billing_country() : '';
		if ( '' === $country ) {
			$country = WC()->countries ? WC()->countries->get_base_country() : 'US';
		}

		$merch_acct = trim( Inovio_Gateway_Client::setting( 'merch_acct_id' ) );

		try {
			$ddc = Inovio_Gateway_Client::client()->threeDSecure()->prepare(
				ThreeDSPrepare::bin(
					substr( (string) $bin, 0, 6 ),
					$currency,
					$country,
					'' !== $merch_acct ? $merch_acct : null
				)
			);
		} catch ( \Throwable $e ) {
			/*
			 * A prepare failure is surfaced, not swallowed. The browser treats
			 * a non-2xx here as fatal and stops checkout rather than silently
			 * placing an unauthenticated order: with 3DS switched on, quietly
			 * dropping authentication changes the liability position of the
			 * transaction, so it must never happen without the merchant seeing it.
			 */
			Inovio_Logger::error( '3DS prepare failed: ' . $e->getMessage() );
			wp_send_json( array( 'error' => 'prepare_failed' ), 502 );
		}

		wp_send_json(
			array(
				'jwt'            => $ddc->jwt,
				'ddcUrl'         => $ddc->ddcUrl,
				'ddcReferenceId' => $ddc->ddcReferenceId,
			)
		);
	}

	/**
	 * Sliding-window rate limit, per WooCommerce session.
	 *
	 * Same algorithm as Inovio_Signature_Endpoint::within_rate_limit(), copied
	 * rather than shared because that class's rate limiter is not to be
	 * modified or depended on from here.
	 *
	 * @return bool True when this request is within the limit.
	 */
	private static function within_rate_limit() {
		$session = WC()->session;

		if ( ! $session ) {
			// No session means no cart, which the caller already refuses
			// separately; but if the session layer is unavailable we refuse
			// rather than allowing an unlimited, unattributable stream of
			// prepare calls.
			return false;
		}

		$now  = time();
		$hits = $session->get( self::RATE_KEY, array() );
		$hits = is_array( $hits ) ? $hits : array();

		// Drop everything outside the window, then test what remains.
		$hits = array_values(
			array_filter(
				$hits,
				static function ( $t ) use ( $now ) {
					return is_numeric( $t ) && ( $now - (int) $t ) < self::RATE_WINDOW;
				}
			)
		);

		if ( count( $hits ) >= self::RATE_MAX ) {
			return false;
		}

		$hits[] = $now;
		$session->set( self::RATE_KEY, $hits );

		return true;
	}

	// -------------------------------------------------------------- return

	/**
	 * Handle the ACS return POST, if this request is one.
	 *
	 * This is a cross-site POST from the ACS origin, rendered inside the
	 * challenge iframe, and may arrive without the session cookie — so it
	 * cannot use a WP nonce. It is instead bound to a legitimate order by:
	 *   (a) the order id + WooCommerce order key on the URL, and
	 *   (b) a constant-time comparison of the ACS TransactionId against the
	 *       procTransId stored at the enrollment leg.
	 *
	 * @return void
	 */
	public static function maybe_handle_return() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Cross-site ACS POST; authenticated by order key + procTransId below.
		if ( ! isset( $_GET[ self::RETURN_VAR ] ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// The ACS POSTs TransactionId / Response (the PARes — legitimately
		// possibly empty; an empty PARes is NOT an error, per spec §15.1.4).
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$acs_trans_id = isset( $_POST['TransactionId'] ) ? sanitize_text_field( wp_unslash( $_POST['TransactionId'] ) ) : '';
		$pares        = isset( $_POST['Response'] ) ? wp_unslash( $_POST['Response'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$pares        = is_string( $pares ) ? $pares : '';

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			Inovio_Logger::error( '3DS return: order not found or key mismatch (order ' . $order_id . ')' );
			self::respond( false, __( 'Order not found.', 'inovio-payment-gateway' ) );
		}

		$raw       = (string) $order->get_meta( Inovio_Gateway_Client::META_CHALLENGE );
		$challenge = '' !== $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $challenge ) || empty( $challenge['procTransId'] ) ) {
			Inovio_Logger::error( '3DS return: no pending authentication on order ' . $order->get_id() );
			self::respond( false, __( 'No pending authentication for this order.', 'inovio-payment-gateway' ) );
		}

		if ( '' === $acs_trans_id || ! hash_equals( (string) $challenge['procTransId'], $acs_trans_id ) ) {
			Inovio_Logger::error( '3DS return TransactionId mismatch on order ' . $order->get_id() );
			self::respond( false, __( 'Authentication reference mismatch.', 'inovio-payment-gateway' ) );
		}

		// Replay guard: a completed order must not be completed twice.
		if ( ! $order->has_status( 'pending' ) ) {
			Inovio_Logger::error( '3DS return: order ' . $order->get_id() . ' is already ' . $order->get_status() );
			self::respond( false, __( 'This authentication has already been processed.', 'inovio-payment-gateway' ) );
		}

		try {
			$result = self::complete( $order, $acs_trans_id, $pares );
		} catch ( GatewayTimeoutException $e ) {
			// The completion leg's outcome is UNKNOWN — reconcile via status()
			// before failing the order, or a genuinely approved payment would
			// be told to the shopper as failed. The completion leg shares the
			// same idempotency key (xtl_order_id()) as the original enrollment,
			// so it reconciles against the same order/leg lookup used
			// elsewhere in this codebase (see xtl_order_id() usage throughout
			// build_request()).
			$recovered = Inovio_Gateway_Client::reconcile_timeout( $order, $e, array( 'CCAUTHCAP', 'CCAUTHORIZE' ) );

			if ( null === $recovered ) {
				$order->update_status(
					'failed',
					__( '3-D Secure completion did not respond in time. Payment status is unknown — check the order in the Inovio portal before retrying.', 'inovio-payment-gateway' )
				);
				self::respond( false, __( 'Your payment status could not be confirmed. Please check your order status before retrying.', 'inovio-payment-gateway' ) );
			}

			$result = $recovered;
		} catch ( \Throwable $e ) {
			Inovio_Logger::error( '3DS completion failed on order ' . $order->get_id() . ': ' . $e->getMessage() );
			$order->update_status( 'failed', __( '3-D Secure completion failed.', 'inovio-payment-gateway' ) );
			self::respond( false, __( 'Payment authentication failed.', 'inovio-payment-gateway' ) );
		}

		if ( ! Inovio_Gateway_Client::is_approved( $result ) ) {
			$advice = Inovio_Gateway_Client::advice( $result );
			Inovio_Logger::error(
				'3DS completion not approved on order ' . $order->get_id() . ': ' . $result->status .
				' — ' . ( $advice ? $advice : 'no advice' )
			);
			$order->update_status(
				'failed',
				sprintf(
					/* translators: %s: gateway decline advice. */
					__( 'Inovio 3-D Secure completion declined: %s', 'inovio-payment-gateway' ),
					$advice ? $advice : $result->status
				)
			);
			self::respond( false, $advice ? $advice : __( 'Payment authentication failed.', 'inovio-payment-gateway' ) );
		}

		self::succeed( $order, $result );
		self::respond( true, __( 'Payment approved.', 'inovio-payment-gateway' ) );
	}

	/**
	 * Run the completion leg with the challenge outcome.
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $acs_trans_id ACS TransactionId.
	 * @param string   $pares        ACS Response (PARes); may be ''.
	 * @return TransactionResult
	 */
	private static function complete( $order, $acs_trans_id, $pares ) {
		/*
		 * The enrollment leg consumed the first token; this leg uses the
		 * second one minted from the same card entry at checkout. Both resolve
		 * to the same card at the gateway — reusing the first would fail with
		 * "API 401 Invalid TOKEN_GUID".
		 */
		$payment = array(
			'token_guid_completion' => (string) $order->get_meta( Inovio_Gateway_Client::META_TOKEN_COMPLETE ),
			'pmt_expiry'            => (string) $order->get_meta( Inovio_Gateway_Client::META_PMT_EXPIRY ),
		);

		$req              = Inovio_Gateway_Client::build_request( $order, $payment, true );
		$challenge_result = new ThreeDSChallengeResult( $acs_trans_id, $pares );
		$client           = Inovio_Gateway_Client::client();

		return 'authorize' === $order->get_meta( Inovio_Gateway_Client::META_PAYMENT_ACTION )
			? $client->threeDSecure()->completeAuthorize( $req, $challenge_result )
			: $client->threeDSecure()->completeSale( $req, $challenge_result );
	}

	/**
	 * Move the order to its post-authentication state.
	 *
	 * @param WC_Order          $order  Order.
	 * @param TransactionResult $result Approved completion result.
	 * @return void
	 */
	private static function succeed( $order, TransactionResult $result ) {
		Inovio_Gateway_Client::record_references( $order, $result );

		$eci = $result->threeDS ? $result->threeDS->eci : null;
		$order->add_order_note(
			sprintf(
				/* translators: 1: gateway PO_ID, 2: 3DS ECI value. */
				__( 'Inovio 3-D Secure authentication completed (PO_ID %1$s, ECI %2$s).', 'inovio-payment-gateway' ),
				$result->orderRef ? $result->orderRef->poId() : '-',
				$eci ? $eci : '-'
			)
		);

		if ( 'authorize' === $order->get_meta( Inovio_Gateway_Client::META_PAYMENT_ACTION ) ) {
			// Authorized but not captured: on-hold is WooCommerce's
			// "awaiting-capture" state — it reserves stock without marking the
			// order paid. Capture happens from the admin order action.
			$order->update_status( 'on-hold', __( 'Inovio: authorized, awaiting capture.', 'inovio-payment-gateway' ) );
		} else {
			$order->payment_complete( $result->transactionId ? $result->transactionId->value() : '' );
		}

		self::maybe_vault( $order, $result );

		// The challenge metas are single-use; clear them all so a replayed ACS
		// POST cannot find a pending authentication to act on, and so a stale
		// save-card opt-in can never be read by a later, unrelated challenge.
		$order->delete_meta_data( Inovio_Gateway_Client::META_CHALLENGE );
		$order->delete_meta_data( Inovio_Gateway_Client::META_TOKEN_COMPLETE );
		$order->delete_meta_data( Inovio_Gateway_Client::META_PMT_EXPIRY );
		$order->delete_meta_data( Inovio_Gateway_Client::META_SAVE_CARD );
		$order->delete_meta_data( Inovio_Gateway_Client::META_CC_BRAND );
		$order->delete_meta_data( Inovio_Gateway_Client::META_CC_LAST4 );
		$order->save();

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Store the card if — and only if — the shopper opted in before the
	 * challenge, mirroring Inovio_Payment_Gateway::maybe_vault() for the
	 * non-3DS path.
	 *
	 * The save-card opt-in and the display metadata needed to vault (card
	 * brand, last 4, expiry) were persisted on the order by
	 * Inovio_Payment_Gateway::begin_challenge() before the challenge opened,
	 * since the checkout payload itself does not survive the ACS round trip.
	 *
	 * @param WC_Order          $order  Order.
	 * @param TransactionResult $result Approved completion result.
	 * @return void
	 */
	private static function maybe_vault( $order, TransactionResult $result ) {
		if ( $order->get_customer_id() <= 0 ) {
			return;
		}

		if ( 'yes' !== $order->get_meta( Inovio_Gateway_Client::META_SAVE_CARD ) ) {
			return;
		}

		$token = Inovio_Vault::save_from_result(
			$order->get_customer_id(),
			$result,
			(string) $order->get_meta( Inovio_Gateway_Client::META_PMT_EXPIRY ),
			(string) $order->get_meta( Inovio_Gateway_Client::META_CC_BRAND ),
			(string) $order->get_meta( Inovio_Gateway_Client::META_CC_LAST4 )
		);

		if ( null !== $token ) {
			$order->add_payment_token( $token );
			$order->save();
		}
	}

	/**
	 * Minimal document rendered inside the ACS iframe, telling the parent
	 * window the outcome via postMessage.
	 *
	 * @param bool   $success Whether authentication and payment succeeded.
	 * @param string $message Human-readable outcome.
	 * @return void
	 */
	private static function respond( $success, $message ) {
		// Gateway-sourced text ($message may carry gateway decline advice) must
		// never be able to break out of the inline <script> context below.
		$payload = wp_json_encode(
			array(
				'inovio3ds' => 'complete',
				'success'   => (bool) $success,
				'message'   => $message,
			),
			JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
		);

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>3-D Secure</title></head><body>';
		echo '<script>window.parent.postMessage(' . $payload . ', window.location.origin);</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output, embedded as a JS literal.
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</body></html>';

		exit;
	}
}
