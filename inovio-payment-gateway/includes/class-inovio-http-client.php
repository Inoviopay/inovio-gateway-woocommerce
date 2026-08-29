<?php
/**
 * WordPress HTTP transport for the Inovio SDK.
 *
 * The SDK ships a cURL client but takes an injected HttpClient precisely so a
 * host platform can supply its own. WordPress sites route outbound HTTP through
 * the WP HTTP API — proxy constants (WP_PROXY_HOST), the `http_request_args`
 * filters, and host-level request blocking all live there. A gateway that
 * opened its own cURL socket would bypass every one of them and break on any
 * site behind a corporate proxy.
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Inovio\Gateway\Transport\HttpClient;
use Inovio\Gateway\Transport\HttpResponse;
use Inovio\Gateway\Transport\TimeoutSignal;

/**
 * Adapts wp_remote_post() to the SDK's HttpClient interface.
 */
class Inovio_Http_Client implements HttpClient {

	/**
	 * POST a form-encoded body to the gateway.
	 *
	 * @param string                $url       Endpoint URL.
	 * @param string                $body      Pre-encoded request body.
	 * @param array<string, string> $headers   Request headers.
	 * @param int                   $timeoutMs Timeout in milliseconds.
	 * @return HttpResponse
	 *
	 * @throws TimeoutSignal      When the request times out — the SDK converts this into a
	 *                            GatewayTimeoutException carrying the idempotency key, which is
	 *                            what makes timeout reconciliation possible instead of a blind retry.
	 * @throws RuntimeException   On any other transport failure.
	 */
	public function post( string $url, string $body, array $headers, int $timeoutMs ): HttpResponse {
		// wp_remote_post takes whole seconds; round up so a sub-second timeout
		// never collapses to 0 (which WordPress would read as "no timeout").
		$timeout_seconds = max( 1, (int) ceil( $timeoutMs / 1000 ) );

		$response = wp_remote_post(
			$url,
			array(
				'method'      => 'POST',
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout_seconds,
				'redirection' => 0,
				// The gateway answers form-encoded/JSON text; never let WordPress
				// try to interpret it.
				'sslverify'   => true,
				'user-agent'  => 'InovioWooCommerce/' . INOVIO_WC_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			$code    = $response->get_error_code();
			$message = $response->get_error_message();

			/*
			 * A timeout must be distinguishable from every other failure: on a
			 * timeout the transaction state is UNKNOWN (it may have been
			 * approved), so the SDK has to raise GatewayTimeoutException and the
			 * gateway class reconciles via status() instead of failing outright.
			 * WordPress reports timeouts as a cURL 28 wrapped in WP_Error, so
			 * detect it here and re-raise as the SDK's signal type.
			 */
			if ( 'http_request_failed' === $code && preg_match( '/timed?\s*out/i', $message ) ) {
				throw new TimeoutSignal( 'request timed out: ' . $message );
			}

			throw new RuntimeException( 'WordPress HTTP error: ' . $message );
		}

		return new HttpResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response )
		);
	}
}
