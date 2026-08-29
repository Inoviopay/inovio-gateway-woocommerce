<?php
/**
 * Vaulting on top of WooCommerce's native payment-token API.
 *
 * The PrestaShop module had to build its own storage table, UI and IDOR guards
 * because PrestaShop has no payment-token API. WooCommerce DOES have one
 * (WC_Payment_Tokens), which already provides per-customer storage, the
 * "Saved methods" account page, deletion with nonce + ownership checks, and the
 * saved-card radio list at checkout. So this class keeps the PrestaShop
 * module's LOGIC — what is stored, and when — and drops its storage layer.
 *
 * SECURITY: a token here MUST NEVER hold a PAN or CVV. On an approved
 * sale/authorize where the shopper opted in, the gateway returns CUST_ID (the
 * vaulted customer) and PMT_ID (the vaulted payment method) — those gateway
 * references ARE the stored card. Everything else stored here (brand, last 4,
 * expiry) is display-only metadata explicitly permitted under PCI DSS.
 *
 * Any change to this class that adds a full card number / CVV field is a
 * defect, not a feature.
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Inovio\Gateway\Result\TransactionResult;

/**
 * Persists and reads Inovio saved cards as WooCommerce payment tokens.
 */
class Inovio_Vault {

	/**
	 * Token meta keys holding the gateway's own references.
	 *
	 * These two values, together, are the stored card. Nothing else is needed
	 * to charge it again, and nothing else may be stored.
	 */
	const META_CUST_ID = 'inovio_cust_id';
	const META_PMT_ID  = 'inovio_pmt_id';

	/**
	 * Persist a saved card from an approved sale/authorize result.
	 *
	 * This is deliberately the ONLY write path into the vault. It must be
	 * called ONLY when the shopper explicitly opted in to saving the card — an
	 * unchecked "save this card" box must never reach this method.
	 *
	 * Returns null (does nothing) when:
	 *  - the gateway did not return a savedCardRef/customerRef (not every
	 *    transaction vaults — e.g. the processor/account is not vault-capable), or
	 *  - a token for this PMT_ID already exists for this customer (avoids
	 *    duplicates when the same card is reused across opted-in checkouts).
	 *
	 * @param int               $customer_id  WordPress user id. Guests cannot vault.
	 * @param TransactionResult $result       Result from sale()/authorize().
	 * @param string            $expiry_mmyyyy Card expiry as MMYYYY (the wire format used throughout).
	 * @param string            $brand        Card brand code, e.g. 'VI' — display only.
	 * @param string            $last4        Last 4 digits of the PAN — display only.
	 * @return WC_Payment_Token_CC|null The stored token, or null when nothing was stored.
	 */
	public static function save_from_result( $customer_id, TransactionResult $result, $expiry_mmyyyy, $brand, $last4 ) {
		$customer_id = (int) $customer_id;
		if ( $customer_id <= 0 ) {
			return null;
		}

		$saved_card_ref = $result->savedCardRef;
		$customer_ref   = $result->customerRef;

		if ( null === $saved_card_ref || null === $customer_ref ) {
			Inovio_Logger::debug( 'vault: gateway returned no PMT_ID/CUST_ID; nothing stored' );

			return null;
		}

		$pmt_id  = (string) $saved_card_ref->pmtId();
		$cust_id = (string) $customer_ref->custId();

		if ( '' === $pmt_id || '' === $cust_id ) {
			return null;
		}

		if ( null !== self::find_by_pmt_id( $customer_id, $pmt_id ) ) {
			Inovio_Logger::debug( 'vault: PMT_ID already stored for customer ' . $customer_id );

			return null;
		}

		list( $exp_month, $exp_year ) = self::split_expiry( $expiry_mmyyyy );
		if ( '' === $exp_month || '' === $exp_year ) {
			// WC_Payment_Token_CC validation requires both, and a token that
			// fails validation is silently not saved — so refuse loudly here
			// instead of appearing to have stored something.
			Inovio_Logger::error( 'vault: refusing to store a token with an unparseable expiry' );

			return null;
		}

		$token = new WC_Payment_Token_CC();
		$token->set_token( $pmt_id );
		$token->set_gateway_id( Inovio_Payment_Gateway::GATEWAY_ID );
		$token->set_user_id( $customer_id );
		$token->set_card_type( self::brand_to_wc_type( $brand ) );
		$token->set_last4( $last4 );
		$token->set_expiry_month( $exp_month );
		$token->set_expiry_year( $exp_year );

		// The gateway references — the actual credential for a later charge.
		$token->add_meta_data( self::META_CUST_ID, $cust_id, true );
		$token->add_meta_data( self::META_PMT_ID, $pmt_id, true );

		if ( ! $token->save() ) {
			Inovio_Logger::error( 'vault: WC_Payment_Token_CC::save() failed for customer ' . $customer_id );

			return null;
		}

		Inovio_Logger::debug( 'vault: stored token ' . $token->get_id() . ' for customer ' . $customer_id );

		return $token;
	}

	/**
	 * Load a saved card the given customer owns.
	 *
	 * Ownership-checked — the IDOR guard. Returns null if the token does not
	 * exist, is not ours, OR does not belong to this customer; callers must
	 * never be able to distinguish those cases, so all three collapse here.
	 *
	 * @param int $token_id    Payment token id.
	 * @param int $customer_id WordPress user id.
	 * @return WC_Payment_Token_CC|null
	 */
	public static function find_for_customer( $token_id, $customer_id ) {
		$token_id    = (int) $token_id;
		$customer_id = (int) $customer_id;

		if ( $token_id <= 0 || $customer_id <= 0 ) {
			return null;
		}

		$token = WC_Payment_Tokens::get( $token_id );

		if ( ! $token instanceof WC_Payment_Token_CC
			|| Inovio_Payment_Gateway::GATEWAY_ID !== $token->get_gateway_id()
			|| (int) $token->get_user_id() !== $customer_id
		) {
			return null;
		}

		return $token;
	}

	/**
	 * The gateway references stored on a token, for a saved-card charge.
	 *
	 * @param WC_Payment_Token_CC $token Stored token.
	 * @return array{cust_id: string, pmt_id: string}
	 */
	public static function references( $token ) {
		return array(
			'cust_id' => (string) $token->get_meta( self::META_CUST_ID ),
			'pmt_id'  => (string) $token->get_meta( self::META_PMT_ID ),
		);
	}

	/**
	 * Whether this customer already has a token for a given gateway PMT_ID.
	 *
	 * @param int    $customer_id WordPress user id.
	 * @param string $pmt_id      Gateway PMT_ID.
	 * @return WC_Payment_Token_CC|null
	 */
	private static function find_by_pmt_id( $customer_id, $pmt_id ) {
		$tokens = WC_Payment_Tokens::get_customer_tokens( (int) $customer_id, Inovio_Payment_Gateway::GATEWAY_ID );

		foreach ( $tokens as $token ) {
			if ( $token instanceof WC_Payment_Token_CC && (string) $token->get_meta( self::META_PMT_ID ) === (string) $pmt_id ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Map an Inovio brand code to the slug WooCommerce uses for card icons.
	 *
	 * @param string $brand Inovio brand code, e.g. 'VI'.
	 * @return string
	 */
	private static function brand_to_wc_type( $brand ) {
		$map = array(
			'VI'  => 'visa',
			'MC'  => 'mastercard',
			'AE'  => 'amex',
			'DI'  => 'discover',
			'JCB' => 'jcb',
			'DN'  => 'diners',
		);

		$brand = strtoupper( trim( (string) $brand ) );

		return isset( $map[ $brand ] ) ? $map[ $brand ] : 'credit-card';
	}

	/**
	 * Split an MMYYYY expiry into [MM, YYYY].
	 *
	 * @param string $mm_yyyy Expiry in MMYYYY form.
	 * @return array{0: string, 1: string}
	 */
	private static function split_expiry( $mm_yyyy ) {
		$mm_yyyy = (string) $mm_yyyy;

		if ( 6 === strlen( $mm_yyyy ) && ctype_digit( $mm_yyyy ) ) {
			return array( substr( $mm_yyyy, 0, 2 ), substr( $mm_yyyy, 2 ) );
		}

		return array( '', '' );
	}
}
