<?php
/**
 * End-to-end order harness.
 *
 * Places a REAL WooCommerce order through the plugin's own gateway class. No
 * browser is involved, so this script performs exactly the two steps the
 * checkout JavaScript would perform in the shopper's browser:
 *
 *   1. call the plugin's own signing endpoint logic to get a signature, then
 *   2. POST the PAN directly to token_service.cfm to obtain a TOKEN_GUID
 *
 * ...and then hands ONLY that TOKEN_GUID to the gateway via $_POST, exactly as
 * the browser would, and calls Inovio_Payment_Gateway::process_payment().
 *
 * The PAN is a local variable in THIS script only. It is never written to the
 * database, never passed to the gateway class, and never logged — which is the
 * property the accompanying mysqldump scan verifies.
 *
 * Run with:
 *   wp --allow-root --path=/var/www/html eval-file \
 *     wp-content/plugins/inovio-payment-gateway/tests/e2e-order.php [pan] [amount]
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Inovio\Gateway\Tokenize;

/**
 * Mint a TOKEN_GUID the way the browser does.
 *
 * Signing goes through the SDK's Tokenize helper — the same code path the
 * plugin's AJAX signing endpoint uses — so this exercises the real signature
 * construction rather than a re-implementation of it.
 *
 * @param string $pan Card number. Local to this function; never persisted.
 * @param string $cvv Card security code.
 * @return string TOKEN_GUID.
 *
 * @throws RuntimeException When the token service refuses.
 */
function inovio_e2e_mint_token( $pan, $cvv ) {
	$site_id  = Inovio_Gateway_Client::setting( 'site_id' );
	$site_key = Inovio_Gateway_Client::setting( 'site_key' );

	$unique_id = bin2hex( random_bytes( 16 ) );
	$timestamp = Tokenize::timestamp();
	$signature = Tokenize::signRequest( $site_key, $timestamp, $unique_id, $site_id );

	$response = wp_remote_post(
		Inovio_Gateway_Client::token_endpoint(),
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'X-timestamp'  => $timestamp,
				'X-signature'  => $signature,
			),
			'body'    => array(
				'card_pan'                => $pan,
				'card_cvv'                => $cvv,
				'request_response_format' => 'json',
				'request_api_version'     => '4.14',
				'site_id'                 => $site_id,
				'unique_id'               => $unique_id,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( 'token service unreachable: ' . $response->get_error_message() );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['TOKEN_GUID'] ) ) {
		throw new RuntimeException( 'token service returned no TOKEN_GUID: ' . wp_remote_retrieve_body( $response ) );
	}

	return (string) $body['TOKEN_GUID'];
}

// ---------------------------------------------------------------------------

$args   = isset( $args ) && is_array( $args ) ? $args : array();
$pan    = isset( $args[0] ) ? (string) $args[0] : '4111111111111111';
$amount = isset( $args[1] ) ? (string) $args[1] : '';
$cvv    = '123';
$expiry = '122030';

echo "=== Inovio WooCommerce e2e order ===\n";

$gateway = WC()->payment_gateways()->payment_gateways()['inovio'];
printf( "gateway available: %s | action: %s | 3DS: %s\n", $gateway->is_available() ? 'yes' : 'no', $gateway->payment_action(), $gateway->threeds_active() ? 'on' : 'off' );

// --- Build a real order, as checkout would ---------------------------------
$product_id = (int) wc_get_products(
	array(
		'limit'  => 1,
		'status' => 'publish',
		'return' => 'ids',
	)
)[0];
$product    = wc_get_product( $product_id );

$order = wc_create_order();
$order->add_product( $product, 1 );
$order->set_address(
	array(
		'first_name' => 'Woo',
		'last_name'  => 'Tester',
		'email'      => 'wc-e2e-' . time() . '@inovio.local',
		'phone'      => '7025551234',
		'address_1'  => '123 Test St',
		'city'       => 'Las Vegas',
		'state'      => 'NV',
		'postcode'   => '89101',
		'country'    => 'US',
	),
	'billing'
);
$order->set_payment_method( $gateway );
$order->set_customer_ip_address( '127.0.0.1' );

// An explicit amount overrides the product price — used to drive the decline
// simulator, which keys only off the exact order total.
if ( '' !== $amount ) {
	foreach ( $order->get_items() as $item ) {
		$item->set_total( (float) $amount );
		$item->set_subtotal( (float) $amount );
		$item->save();
	}
}

$order->calculate_totals();
$order->save();

printf( "order %d created, total %s %s\n", $order->get_id(), $order->get_total(), $order->get_currency() );

// --- Tokenize exactly as the browser does ----------------------------------
$token_guid = inovio_e2e_mint_token( $pan, $cvv );
printf( "TOKEN_GUID minted: %s\n", $token_guid );

// A second token, so the two-token 3DS path is exercised when 3DS is on. The
// enrollment leg consumes the first; the completion leg needs the second.
$token_guid_completion = $gateway->threeds_active() ? inovio_e2e_mint_token( $pan, $cvv ) : '';
if ( '' !== $token_guid_completion ) {
	printf( "TOKEN_GUID (completion) minted: %s\n", $token_guid_completion );
}

// --- Hand the gateway what the browser would POST --------------------------
// NOTE: no PAN and no CVV appear here. This is the whole payment payload.
$_POST = array(
	'inovio_token_guid'            => $token_guid,
	'inovio_token_guid_completion' => $token_guid_completion,
	'inovio_pmt_expiry'            => $expiry,
	'inovio_cc_brand'              => 'VI',
	'inovio_cc_last4'              => substr( $pan, -4 ),
	'inovio_ddc_reference_id'      => '',
	'inovio_browser'               => wp_json_encode(
		array(
			'language'  => 'en-US',
			'userAgent' => 'Mozilla/5.0 (e2e harness)',
			'header'    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
		)
	),
);

echo "--- calling Inovio_Payment_Gateway::process_payment() ---\n";
$result = $gateway->process_payment( $order->get_id() );

printf( "process_payment result: %s\n", isset( $result['result'] ) ? $result['result'] : '(none)' );
if ( isset( $result['redirect'] ) ) {
	printf( "redirect: %s\n", $result['redirect'] );
}
if ( isset( $result['inovio_3ds'] ) ) {
	printf( "3DS challenge issued: redirectUrl=%s\n", $result['inovio_3ds']['redirectUrl'] );
}

// --- Report what was persisted ---------------------------------------------
$order = wc_get_order( $order->get_id() );
printf( "order status: %s\n", $order->get_status() );
printf( "order total paid: %s\n", $order->get_total() );
printf( "PO_ID: %s\n", $order->get_meta( Inovio_Gateway_Client::META_PO_ID ) ?: '-' );
printf( "TRANS_ID: %s\n", $order->get_meta( Inovio_Gateway_Client::META_TRANS_ID ) ?: '-' );
printf( "REQ_ID: %s\n", $order->get_meta( Inovio_Gateway_Client::META_REQ_ID ) ?: '-' );
printf( "transaction_id on order: %s\n", $order->get_transaction_id() ?: '-' );

foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
	printf( "note: %s\n", trim( $note->content ) );
}

printf( "E2E_ORDER_ID=%d\n", $order->get_id() );
