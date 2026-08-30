<?php
/**
 * Gateway service: builds SDK requests from WooCommerce orders and runs the
 * transaction verbs.
 *
 * Everything gateway-facing goes through the SDK's typed objects — never raw
 * REQUEST_ACTION wire fields. The wire format is the SDK's problem; this class
 * only ever speaks in TransactionRequest / TransactionResult.
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Inovio\Gateway\Credentials;
use Inovio\Gateway\Errors\GatewayTimeoutException;
use Inovio\Gateway\InovioClient;
use Inovio\Gateway\Model\Address as SdkAddress;
use Inovio\Gateway\Model\BrowserData;
use Inovio\Gateway\Model\Customer as SdkCustomer;
use Inovio\Gateway\Model\Descriptor;
use Inovio\Gateway\Model\LineItem;
use Inovio\Gateway\Model\Money;
use Inovio\Gateway\Model\PaymentMethod;
use Inovio\Gateway\Model\PaymentMethods;
use Inovio\Gateway\Model\ThreeDS;
use Inovio\Gateway\Refs\OrderRef;
use Inovio\Gateway\Refs\Refs;
use Inovio\Gateway\Request\TransactionRequest;
use Inovio\Gateway\Result\TransactionResult;

/**
 * Builds and runs Inovio gateway transactions for WooCommerce orders.
 */
class Inovio_Gateway_Client {

	/**
	 * Gateway service code for "Order not settled: Please reverse".
	 *
	 * @see self::refund_order() for why this matters.
	 */
	const SERVICE_NOT_SETTLED = 536;

	/**
	 * Order meta keys holding gateway references.
	 *
	 * Stored as order meta (via the CRUD API, so HPOS-safe) because every later
	 * leg — capture, void, refund, 3DS completion — is keyed on the gateway's
	 * PO_ID, which WooCommerce itself knows nothing about.
	 */
	const META_PO_ID           = '_inovio_po_id';
	const META_TRANS_ID        = '_inovio_trans_id';
	const META_REQ_ID          = '_inovio_req_id';
	const META_ECI             = '_inovio_eci';
	const META_CHALLENGE       = '_inovio_challenge';
	const META_TOKEN_COMPLETE  = '_inovio_token_completion';
	const META_PMT_EXPIRY      = '_inovio_pmt_expiry';
	const META_PAYMENT_ACTION  = '_inovio_payment_action';
	const META_CAPTURED        = '_inovio_captured';
	const META_SAVE_CARD       = '_inovio_save_card';
	const META_CC_BRAND        = '_inovio_cc_brand';
	const META_CC_LAST4        = '_inovio_cc_last4';

	/**
	 * Leg-specific reference meta keys.
	 *
	 * record_references() writes META_TRANS_ID/META_REQ_ID for the ORIGINAL
	 * payment leg only (checkout, 3DS completion). Refund/capture/void legs
	 * write into their own keys instead, so a later leg's TRANS_ID/REQ_ID never
	 * overwrites the original payment's references.
	 */
	const META_LAST_REFUND_TRANS_ID  = '_inovio_last_refund_trans_id';
	const META_LAST_REFUND_REQ_ID    = '_inovio_last_refund_req_id';
	const META_LAST_CAPTURE_TRANS_ID = '_inovio_last_capture_trans_id';
	const META_LAST_CAPTURE_REQ_ID   = '_inovio_last_capture_req_id';
	const META_LAST_VOID_TRANS_ID    = '_inovio_last_void_trans_id';
	const META_LAST_VOID_REQ_ID      = '_inovio_last_void_req_id';

	/**
	 * The gateway settings array, cached per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $settings = null;

	/**
	 * Read the gateway's saved settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings() {
		if ( null === self::$settings ) {
			$settings       = get_option( 'woocommerce_inovio_settings', array() );
			self::$settings = is_array( $settings ) ? $settings : array();
		}

		return self::$settings;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param string $default Value when unset.
	 * @return string
	 */
	public static function setting( $key, $default = '' ) {
		$settings = self::settings();

		return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : $default;
	}

	/**
	 * Whether a yes/no setting is enabled.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function setting_enabled( $key ) {
		return 'yes' === self::setting( $key, 'no' );
	}

	/**
	 * Build a configured SDK client.
	 *
	 * @return InovioClient
	 */
	public static function client() {
		$endpoint = self::endpoint();

		/*
		 * NOTE: the SDK's 2nd parameter is the environment NAME; the explicit
		 * URL belongs in $endpoint. Passing the URL positionally silently
		 * leaves the client pointed at the default sandbox host.
		 */
		return new InovioClient(
			new Credentials(
				self::setting( 'req_username' ),
				self::setting( 'req_password' ),
				self::setting( 'site_id' )
			),
			'PRODUCTION',
			$endpoint,
			new Inovio_Http_Client(),
			null,
			120000,
			null,
			self::setting( 'site_key' ) ?: null
		);
	}

	/**
	 * The configured pmt_service.cfm endpoint.
	 *
	 * @return string
	 */
	public static function endpoint() {
		$endpoint = trim( self::setting( 'endpoint' ) );

		return '' !== $endpoint ? $endpoint : 'https://api.inoviopay.com/payment/pmt_service.cfm';
	}

	/**
	 * Where the browser POSTs the PAN — derived exactly as the SDK derives it,
	 * so the token endpoint can never drift from the transaction endpoint.
	 *
	 * @return string
	 */
	public static function token_endpoint() {
		return (string) preg_replace( '/pmt_service\.cfm$/', 'token_service.cfm', self::endpoint() );
	}

	/**
	 * Stable external order id used for idempotency and status reconcile.
	 *
	 * The WooCommerce order id is a stable per-order reference, so a retried
	 * request returns the original result rather than charging twice.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function xtl_order_id( $order ) {
		return 'WC-' . $order->get_id();
	}

	/**
	 * Build a transaction request from a WooCommerce order.
	 *
	 * @param WC_Order             $order         Order being paid.
	 * @param array<string, mixed> $payment       Tokens + metadata collected in checkout (never a PAN).
	 * @param bool                 $for_completion True when building the 3DS completion leg.
	 * @return TransactionRequest
	 *
	 * @throws RuntimeException When the gateway product id is not configured, or the
	 *                          required token is missing.
	 */
	public static function build_request( $order, array $payment, $for_completion = false ) {
		$product_id = trim( self::setting( 'product_id' ) );
		if ( '' === $product_id ) {
			throw new RuntimeException( 'Inovio gateway product ID is not configured.' );
		}

		$currency = $order->get_currency();

		// WooCommerce totals are floats; Money requires a decimal string,
		// because binary floats cannot represent decimal amounts exactly.
		$amount = self::money_string( $order->get_total(), $currency );

		$req = new TransactionRequest(
			self::payment_method( $payment, $for_completion ),
			array( new LineItem( $product_id, 1, Money::of( $amount, $currency ) ) )
		);

		$req->withIdempotency( self::xtl_order_id( $order ) );

		$merch_acct        = trim( self::setting( 'merch_acct_id' ) );
		$req->merchAcctId  = '' !== $merch_acct ? $merch_acct : null;

		$customer            = new SdkCustomer();
		$customer->firstName = $order->get_billing_first_name();
		$customer->lastName  = $order->get_billing_last_name();
		$customer->email     = $order->get_billing_email();
		$customer->phone     = $order->get_billing_phone() ?: null;
		$customer->ip        = $order->get_customer_ip_address() ?: null;
		$req->customer       = $customer;

		$req->billingAddress = self::billing_address( $order );
		if ( $order->has_shipping_address() ) {
			$req->shippingAddress = self::shipping_address( $order );
		}

		$descriptor_name = trim( self::setting( 'descriptor' ) );
		if ( '' !== $descriptor_name ) {
			$descriptor        = new Descriptor( $descriptor_name );
			$descriptor_phone  = trim( self::setting( 'descriptor_phone' ) );
			$descriptor->phone = '' !== $descriptor_phone ? $descriptor_phone : null;
			$req->descriptor   = $descriptor;
		}

		$browser = self::browser_data( $payment, $order );
		if ( null !== $browser ) {
			$req->browser = $browser;
		}

		$ddc_ref = isset( $payment['ddc_reference_id'] ) ? (string) $payment['ddc_reference_id'] : '';
		if ( '' !== $ddc_ref && null !== $browser && self::setting_enabled( 'threeds_active' ) ) {
			/*
			 * The ACS return is a cross-site POST that may arrive without the
			 * session cookie, so the order reference rides on the URL. The
			 * return endpoint re-authenticates it by comparing the ACS
			 * TransactionId against the procTransId stored at enrollment.
			 */
			$req->threeDS = new ThreeDS( $ddc_ref, Inovio_ThreeDS_Controller::return_url( $order ) );
		}

		return $req;
	}

	/**
	 * ISO 4217 minor-unit exceptions: currencies whose decimal exponent is not 2.
	 *
	 * A short, well-known list — zero-decimal currencies, and the three-decimal
	 * "third-decimal" currencies. Everything not listed here uses the default
	 * of 2 decimals.
	 *
	 * @var array<string, int>
	 */
	const CURRENCY_DECIMALS = array(
		// Zero-decimal currencies.
		'BIF' => 0,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'ISK' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KRW' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'UGX' => 0,
		'UYI' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		// Three-decimal currencies.
		'BHD' => 3,
		'IQD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
	);

	/**
	 * Format a WooCommerce float total as the decimal string Money requires.
	 *
	 * The decimal exponent is looked up per ISO 4217 currency, not per store —
	 * `wc_get_price_decimals()` reflects the store's display setting, which is
	 * not authoritative for a specific order's actual currency (e.g. a
	 * multi-currency store, or a store default that doesn't match the order).
	 *
	 * @param float|string $amount   Amount.
	 * @param string       $currency ISO 4217 currency code for this amount — required; no fallback.
	 * @return string
	 */
	public static function money_string( $amount, $currency ) {
		$decimals = self::CURRENCY_DECIMALS[ strtoupper( (string) $currency ) ] ?? 2;

		return number_format( (float) $amount, $decimals, '.', '' );
	}

	/**
	 * Resolve the payment method for this transaction.
	 *
	 * @param array<string, mixed> $payment       Checkout payload.
	 * @param bool                 $for_completion True for the 3DS completion leg.
	 * @return PaymentMethod
	 *
	 * @throws RuntimeException When the required token is absent.
	 */
	private static function payment_method( array $payment, $for_completion ) {
		// A vaulted card needs no token at all: the gateway's own CUST_ID /
		// PMT_ID references ARE the card, so the direct-post tokenization step
		// is bypassed entirely.
		$cust_id = isset( $payment['cust_id'] ) ? (string) $payment['cust_id'] : '';
		$pmt_id  = isset( $payment['pmt_id'] ) ? (string) $payment['pmt_id'] : '';
		if ( '' !== $pmt_id && '' !== $cust_id ) {
			return PaymentMethods::savedCard( $pmt_id, null, $cust_id );
		}

		/*
		 * Gateway TOKEN_GUIDs are single-use: the 3DS enrollment leg consumes
		 * the first token, so the completion leg must use the second. Both
		 * resolve to the same card at the gateway. Reusing the first token on
		 * the completion leg returns "API 401 Invalid TOKEN_GUID" — verified
		 * against the live gateway, so do not collapse these into one token.
		 */
		$key    = $for_completion ? 'token_guid_completion' : 'token_guid';
		$guid   = isset( $payment[ $key ] ) ? (string) $payment[ $key ] : '';
		$expiry = isset( $payment['pmt_expiry'] ) ? (string) $payment['pmt_expiry'] : '';

		if ( '' === $guid || '' === $expiry ) {
			throw new RuntimeException(
				$for_completion
					? '3DS completion token is missing — checkout did not mint the second token.'
					: 'Payment token is missing — the card was not tokenized in checkout.'
			);
		}

		// The token replaces the PAN only; the transaction still needs the
		// expiry, or the gateway answers API 110 on REF_FIELD=pmt_expiry.
		return PaymentMethods::token( $guid, $expiry );
	}

	/**
	 * Billing address from the order.
	 *
	 * @param WC_Order $order Order.
	 * @return SdkAddress
	 */
	private static function billing_address( $order ) {
		$address          = new SdkAddress();
		$address->line1   = $order->get_billing_address_1() ?: null;
		$address->line2   = $order->get_billing_address_2() ?: null;
		$address->city    = $order->get_billing_city() ?: null;
		$address->state   = $order->get_billing_state() ?: null;
		$address->zip     = $order->get_billing_postcode() ?: null;
		$address->country = $order->get_billing_country() ?: null;

		return $address;
	}

	/**
	 * Shipping address from the order.
	 *
	 * @param WC_Order $order Order.
	 * @return SdkAddress
	 */
	private static function shipping_address( $order ) {
		$address          = new SdkAddress();
		$address->line1   = $order->get_shipping_address_1() ?: null;
		$address->line2   = $order->get_shipping_address_2() ?: null;
		$address->city    = $order->get_shipping_city() ?: null;
		$address->state   = $order->get_shipping_state() ?: null;
		$address->zip     = $order->get_shipping_postcode() ?: null;
		$address->country = $order->get_shipping_country() ?: null;

		return $address;
	}

	/**
	 * Browser/device data for the 3DS legs.
	 *
	 * Returns null unless the three fields the gateway actually requires are
	 * all present — the gateway SILENTLY skips 3DS when language, userAgent or
	 * header is missing, so a half-populated block is worse than none.
	 *
	 * @param array<string, mixed> $payment Checkout payload.
	 * @param WC_Order             $order   Order.
	 * @return BrowserData|null
	 */
	private static function browser_data( array $payment, $order ) {
		$raw = isset( $payment['browser'] ) ? $payment['browser'] : null;
		$b   = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );

		if ( ! is_array( $b ) || empty( $b['language'] ) || empty( $b['userAgent'] ) || empty( $b['header'] ) ) {
			return null;
		}

		return new BrowserData(
			(string) $b['language'],
			(string) $b['userAgent'],
			(string) $b['header'],
			isset( $b['javaEnabled'] ) ? (bool) $b['javaEnabled'] : null,
			true,
			isset( $b['colorDepth'] ) ? (int) $b['colorDepth'] : null,
			isset( $b['screenHeight'] ) ? (int) $b['screenHeight'] : null,
			isset( $b['screenWidth'] ) ? (int) $b['screenWidth'] : null,
			isset( $b['timeZoneOffset'] ) ? (int) $b['timeZoneOffset'] : null,
			null,
			$order->get_customer_ip_address() ?: null
		);
	}

	// ------------------------------------------------------------ order refs

	/**
	 * Persist the gateway references a later leg will need.
	 *
	 * META_PO_ID and META_ECI are always written to the shared/primary keys:
	 * PO_ID is the same order-level reference regardless of which leg produced
	 * it, and ECI does not apply to refund/capture/void legs. TRANS_ID/REQ_ID
	 * are the original-payment references by default (checkout leg, 3DS
	 * completion leg) — a caller acting on behalf of a later leg (refund,
	 * capture, void) must pass `$leg_keys` so that leg's own TRANS_ID/REQ_ID
	 * lands in its own meta keys instead of clobbering the original payment's.
	 *
	 * @param WC_Order          $order    Order.
	 * @param TransactionResult $result   Gateway result.
	 * @param array<string, string> $leg_keys Optional map with 'trans_id' and/or
	 *                                        'req_id' keys naming the meta keys
	 *                                        to write TRANS_ID/REQ_ID into
	 *                                        instead of the primary keys.
	 * @return void
	 */
	public static function record_references( $order, TransactionResult $result, array $leg_keys = array() ) {
		$trans_id_key = isset( $leg_keys['trans_id'] ) ? $leg_keys['trans_id'] : self::META_TRANS_ID;
		$req_id_key   = isset( $leg_keys['req_id'] ) ? $leg_keys['req_id'] : self::META_REQ_ID;

		$refs = array(
			self::META_PO_ID => $result->orderRef ? $result->orderRef->poId() : null,
			$trans_id_key     => $result->transactionId ? $result->transactionId->value() : null,
			$req_id_key       => $result->requestId ? $result->requestId->value() : null,
			self::META_ECI    => $result->threeDS ? $result->threeDS->eci : null,
		);

		$changed = false;
		foreach ( $refs as $key => $value ) {
			if ( null !== $value && '' !== $value ) {
				$order->update_meta_data( $key, $value );
				$changed = true;
			}
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * The gateway order reference needed by capture/void/refund/status.
	 *
	 * @param WC_Order $order Order.
	 * @return OrderRef
	 *
	 * @throws RuntimeException When the order carries no gateway reference.
	 */
	public static function order_ref( $order ) {
		$po_id = (string) $order->get_meta( self::META_PO_ID );

		if ( '' === $po_id ) {
			throw new RuntimeException( 'No Inovio gateway reference stored for order ' . $order->get_id() );
		}

		return Refs::order( $po_id );
	}

	// --------------------------------------------------------------- verbs

	/**
	 * Whether a refund of the given amount would be a full refund.
	 *
	 * By the time process_refund()/refund_order() runs, WooCommerce has
	 * already saved the in-flight refund's own `shop_order_refund` child, so
	 * `$order->get_total_refunded()` (on a freshly reloaded order) already
	 * includes THIS refund. The remaining refundable balance after this call
	 * is therefore `get_total() - get_total_refunded()`; the refund is full
	 * exactly when that remainder is zero — equivalently, when
	 * `get_total_refunded() === get_total()`. A null `$amount` (meaning "the
	 * full remaining amount") is also full by definition.
	 *
	 * Compared as decimal strings via bccomp — never binary floats — per the
	 * project's no-binary-float rule.
	 *
	 * @param WC_Order    $order  Order (already reloaded/saved with this refund's total).
	 * @param string|null $amount Decimal amount string for this refund, or null for "the full amount".
	 * @return bool
	 */
	public static function is_full_refund( $order, $amount ) {
		if ( null === $amount ) {
			return true;
		}

		$total          = number_format( (float) $order->get_total(), 4, '.', '' );
		$total_refunded = number_format( (float) $order->get_total_refunded(), 4, '.', '' );

		return 0 === bccomp( $total_refunded, $total, 4 );
	}

	/**
	 * Undo a captured sale, or credit a partial amount.
	 *
	 * A full refund is dispatched as reverseCapture() with CREDIT_ON_FAIL=1: the
	 * gateway itself decides whether to reverse the authorization/capture or,
	 * when the capture has already settled and can no longer be reversed,
	 * re-route to CCCREDIT on its own side (the response then comes back with
	 * action=CCCREDIT instead of CCREVERSECAP). This replaces a prior
	 * settlement pre-check that reversed the FULL original capture even on a
	 * partial-refund request — reverseCapture has no amount parameter, so it
	 * must never be used for a partial refund.
	 *
	 * A partial refund is dispatched as refund() (CCCREDIT) for the requested
	 * amount. The gateway refuses CCCREDIT on an order that has not settled
	 * yet with SERVICE 536 "Order not settled: Please reverse" — that DECLINED
	 * result is returned to the caller as-is (see
	 * Inovio_Payment_Gateway::process_refund(), which detects 536 and surfaces
	 * a distinct error rather than silently retrying with a full reversal).
	 *
	 * Full-vs-partial is determined by is_full_refund() — see its docblock for
	 * the exact arithmetic.
	 *
	 * @param WC_Order    $order  Order.
	 * @param string|null $amount Decimal amount string, or null for the full amount.
	 * @return TransactionResult
	 */
	public static function refund_order( $order, $amount = null ) {
		$currency = $order->get_currency();
		$ref      = self::order_ref( $order );
		$client   = self::client();
		$leg_keys = array(
			'trans_id' => self::META_LAST_REFUND_TRANS_ID,
			'req_id'   => self::META_LAST_REFUND_REQ_ID,
		);

		if ( self::is_full_refund( $order, $amount ) ) {
			$result = $client->reverseCapture( $ref, true );
			self::record_references( $order, $result, $leg_keys );

			if ( 'APPROVED' === $result->status ) {
				Inovio_Logger::debug( 'reverseCapture (full, creditOnFail) order ' . $order->get_id() . ' -> ' . $result->status . ' (' . $result->action . ')' );
			} else {
				Inovio_Logger::error( 'reverseCapture (full, creditOnFail) order ' . $order->get_id() . ' -> ' . $result->status );
			}

			return $result;
		}

		$money  = Money::of( $amount, $currency );
		$result = $client->refund( $ref, $money );
		self::record_references( $order, $result, $leg_keys );

		if ( 'APPROVED' === $result->status ) {
			Inovio_Logger::debug( 'refund (partial) order ' . $order->get_id() . ' -> ' . $result->status );
		} else {
			Inovio_Logger::error( 'refund (partial) order ' . $order->get_id() . ' -> ' . $result->status );
		}

		return $result;
	}

	/**
	 * Capture a previously authorized order.
	 *
	 * @param WC_Order    $order  Order.
	 * @param string|null $amount Decimal amount string, or null to capture in full.
	 * @return TransactionResult
	 */
	public static function capture_order( $order, $amount = null ) {
		$money    = null !== $amount ? Money::of( $amount, $order->get_currency() ) : null;
		$result   = self::client()->capture( self::order_ref( $order ), $money );
		$leg_keys = array(
			'trans_id' => self::META_LAST_CAPTURE_TRANS_ID,
			'req_id'   => self::META_LAST_CAPTURE_REQ_ID,
		);
		self::record_references( $order, $result, $leg_keys );

		if ( 'APPROVED' === $result->status ) {
			Inovio_Logger::debug( 'capture order ' . $order->get_id() . ' -> ' . $result->status );
		} else {
			Inovio_Logger::error( 'capture order ' . $order->get_id() . ' -> ' . $result->status );
		}

		return $result;
	}

	/**
	 * Void (reverse) an authorization that has not been captured.
	 *
	 * @param WC_Order $order Order.
	 * @return TransactionResult
	 */
	public static function void_order( $order ) {
		$result   = self::client()->reverse( self::order_ref( $order ) );
		$leg_keys = array(
			'trans_id' => self::META_LAST_VOID_TRANS_ID,
			'req_id'   => self::META_LAST_VOID_REQ_ID,
		);
		self::record_references( $order, $result, $leg_keys );

		if ( 'APPROVED' === $result->status ) {
			Inovio_Logger::debug( 'void order ' . $order->get_id() . ' -> ' . $result->status );
		} else {
			Inovio_Logger::error( 'void order ' . $order->get_id() . ' -> ' . $result->status );
		}

		return $result;
	}

	/**
	 * Reconcile a gateway timeout.
	 *
	 * On a timeout the transaction state is UNKNOWN — it may well have been
	 * approved. Blindly retrying would double-charge, so the SDK's recovery
	 * contract is to resolve the true state by idempotency key first.
	 *
	 * When `$expected_actions` is non-empty, a leg must also match one of those
	 * actions to be returned — otherwise the first APPROVED leg found on the
	 * order is returned, regardless of which verb produced it. Restricting by
	 * action matters for capture/void/refund reconciliation: e.g. a capture
	 * timeout must not be "reconciled" by finding an unrelated APPROVED
	 * CCAUTHORIZE leg from the original checkout.
	 *
	 * @param WC_Order                $order             Order.
	 * @param GatewayTimeoutException $e                 The timeout.
	 * @param string[]                $expected_actions  Optional. TransactionResult::$action values that
	 *                                                    count as a match (e.g. ['CCCREDIT', 'CCREVERSECAP']).
	 *                                                    Empty means any action.
	 * @return TransactionResult|null The matching approved leg if one exists, else null.
	 */
	public static function reconcile_timeout( $order, GatewayTimeoutException $e, array $expected_actions = array() ) {
		Inovio_Logger::error( 'gateway timeout on order ' . $order->get_id() . ': ' . $e->getMessage() );

		try {
			$status = self::client()->status( Refs::xtlOrder( self::xtl_order_id( $order ) ) );

			foreach ( $status->transactions as $leg ) {
				if ( 'APPROVED' !== $leg->status ) {
					continue;
				}

				if ( array() !== $expected_actions && ! in_array( $leg->action, $expected_actions, true ) ) {
					continue;
				}

				Inovio_Logger::error( 'timeout reconciled to APPROVED for order ' . $order->get_id() );

				return $leg;
			}
		} catch ( \Throwable $status_error ) {
			Inovio_Logger::error( 'timeout reconcile failed: ' . $status_error->getMessage() );
		}

		return null;
	}

	/**
	 * The most specific decline advice the gateway gave.
	 *
	 * The four response tiers are independent; the innermost one that spoke is
	 * the most informative.
	 *
	 * @param TransactionResult $result Gateway result.
	 * @return string|null
	 */
	public static function advice( TransactionResult $result ) {
		$advice = $result->outcome->processor->advice
			?? $result->outcome->service->advice
			?? $result->outcome->industry->advice
			?? $result->outcome->api->advice
			?? null;

		$advice = is_string( $advice ) ? trim( $advice ) : null;

		return ( null === $advice || '' === $advice ) ? null : $advice;
	}

	/**
	 * Whether the gateway approved the transaction.
	 *
	 * Deliberately an explicit status comparison, not a truthiness check: the
	 * SDK exposes no approved/declined booleans precisely so PENDING (a 3DS
	 * challenge) can never be silently treated as a failure.
	 *
	 * @param TransactionResult $result Gateway result.
	 * @return bool
	 */
	public static function is_approved( TransactionResult $result ) {
		return 'APPROVED' === $result->status;
	}

	/**
	 * Whether this result is a 3DS challenge awaiting cardholder interaction.
	 *
	 * @param TransactionResult $result Gateway result.
	 * @return bool
	 */
	public static function is_challenge( TransactionResult $result ) {
		return 'PENDING' === $result->status
			&& null !== $result->nextAction
			&& 'threeDSChallenge' === $result->nextAction->kind;
	}
}
