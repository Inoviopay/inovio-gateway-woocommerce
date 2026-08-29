<?php
/**
 * HMAC signing for browser tokenization.
 *
 * The browser POSTs the PAN directly to Inovio, but that call must be signed
 * with the per-site key — which never leaves this server. This endpoint mints
 * the signature only; it never sees a card number.
 *
 * It must stay tightly guarded: an open signing endpoint would let anyone mint
 * tokens against the merchant's site, so it is a minting oracle and is treated
 * as one. Three independent gates, all of which must pass:
 *
 *   1. a valid WP nonce (WooCommerce's checkout nonce equivalent for our AJAX
 *      action) — the same-origin check;
 *   2. a real, non-empty WooCommerce cart in session — a signature is only
 *      ever minted in service of an actual pending purchase;
 *   3. a per-session rate limit of 12 requests / 60 seconds.
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Inovio\Gateway\Tokenize;

/**
 * The wp-ajax endpoint that signs browser tokenization requests.
 */
class Inovio_Signature_Endpoint {

	/**
	 * AJAX action name, for both the logged-in and logged-out variants.
	 * Guests check out too, so `nopriv` is required — the cart and nonce
	 * checks, not authentication, are what guard this endpoint.
	 */
	const ACTION = 'inovio_signature';

	/**
	 * Nonce action for the signing request.
	 */
	const NONCE_ACTION = 'inovio_signature_nonce';

	/**
	 * WooCommerce session key holding the rate-limit hit timestamps.
	 */
	const RATE_KEY = 'inovio_sig_hits';

	/**
	 * Rate-limit window, seconds.
	 */
	const RATE_WINDOW = 60;

	/**
	 * Maximum signatures per window, per session.
	 *
	 * 12 is deliberately generous enough for a shopper who mistypes a card a
	 * few times (each attempt costs two signatures when 3DS is on) but low
	 * enough that the endpoint is useless as a bulk minting oracle.
	 */
	const RATE_MAX = 12;

	/**
	 * Register the AJAX handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Mint a signature for one browser-direct tokenization call.
	 *
	 * Responds with {siteId, timestamp, signature, tokenUrl}. Note what is
	 * absent from both the request and the response: anything card-related.
	 * This endpoint only ever sees a client-generated uniqueId.
	 *
	 * @return void
	 */
	public static function handle() {
		// (1) Same-origin: a valid nonce must accompany the call.
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			self::fail( 'invalid_nonce' );
		}

		// (2) There must be a real cart in session with something in it.
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			self::fail( 'no_cart' );
		}

		// (3) Rate limit per session — a signing endpoint is a minting oracle.
		if ( ! self::within_rate_limit() ) {
			self::fail( 'rate_limited' );
		}

		/*
		 * The uniqueId is generated in the browser and is part of the signed
		 * message. It is validated strictly as hex so it cannot be used to
		 * smuggle anything into the HMAC input, and its length is capped at
		 * the 32 characters the token service accepts.
		 */
		$unique_id = isset( $_POST['uniqueId'] ) ? sanitize_text_field( wp_unslash( $_POST['uniqueId'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{8,32}$/i', $unique_id ) ) {
			self::fail( 'bad_unique_id' );
		}

		$site_id  = Inovio_Gateway_Client::setting( 'site_id' );
		$site_key = Inovio_Gateway_Client::setting( 'site_key' );
		if ( '' === $site_id || '' === $site_key ) {
			self::fail( 'not_configured' );
		}

		$timestamp = Tokenize::timestamp();

		/*
		 * hex(hmac_sha256(timestamp . uniqueId . siteId, siteKey)).
		 *
		 * The PAN is NOT part of the signed message. The v4.14 PDF's §4.8.1.2
		 * says it is, and its worked example agrees, but the gateway does not:
		 * CRPT.TOKEN_PKG validates exactly the three fields above, and signing
		 * with the card number included fails with error 121. Signing is
		 * delegated to the SDK so this module cannot drift from that contract.
		 */
		$signature = Tokenize::signRequest( $site_key, $timestamp, $unique_id, $site_id );

		wp_send_json(
			array(
				'siteId'    => $site_id,
				'timestamp' => $timestamp,
				'signature' => $signature,
				'tokenUrl'  => Inovio_Gateway_Client::token_endpoint(),
			)
		);
	}

	/**
	 * Sliding-window rate limit, per WooCommerce session.
	 *
	 * @return bool True when this request is within the limit.
	 */
	private static function within_rate_limit() {
		$session = WC()->session;

		if ( ! $session ) {
			// No session means no cart, which gate (2) already refused; but if
			// the session layer is unavailable we refuse rather than allowing
			// an unlimited, unattributable stream of signing requests.
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

	/**
	 * Refuse the request, loudly.
	 *
	 * Always logged: a silent refusal here means checkout cannot tokenize and
	 * the shopper sees an unexplained failure, with nothing in the log to say
	 * which gate rejected it.
	 *
	 * @param string $reason Machine-readable refusal reason.
	 * @return void
	 */
	private static function fail( $reason ) {
		Inovio_Logger::error( 'signature refused: ' . $reason );

		wp_send_json( array( 'error' => $reason ), 403 );
	}
}
