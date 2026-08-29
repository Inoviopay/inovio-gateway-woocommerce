<?php
/**
 * The WooCommerce payment gateway.
 *
 * Receives the single-use token minted in the browser — never a PAN — runs the
 * gateway transaction, and puts the order into the state the outcome implies.
 *
 * @package Inovio_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Inovio\Gateway\Errors\GatewayTimeoutException;
use Inovio\Gateway\Result\TransactionResult;

/**
 * Inovio credit-card gateway for WooCommerce.
 */
class Inovio_Payment_Gateway extends WC_Payment_Gateway {

	/**
	 * The gateway id. Also the key of the settings option
	 * (`woocommerce_inovio_settings`) and the value stored on orders as the
	 * payment method, so it must never change.
	 */
	const GATEWAY_ID = 'inovio';

	/**
	 * Set up the gateway, its settings and its hooks.
	 */
	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'Inovio Payment Gateway', 'inovio-payment-gateway' );
		$this->method_description = __(
			'Accept credit cards through the Inovio gateway. The card number is tokenized in the browser and never reaches this server.',
			'inovio-payment-gateway'
		);
		$this->has_fields         = true;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Credit card', 'inovio-payment-gateway' ) );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		/*
		 * `products` is what enables WooCommerce's own saved-card UI: the
		 * checkout token list, the "Saved payment methods" account page, and
		 * token deletion (which WooCommerce guards with its own nonce and
		 * ownership checks). `refunds` enables the admin Refund button.
		 */
		$this->supports = array( 'products', 'refunds' );
		if ( $this->vault_active() ) {
			$this->supports[] = 'tokenization';
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
	}

	// --------------------------------------------------------------- config

	/**
	 * The settings form shown in WooCommerce > Settings > Payments > Inovio.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'inovio-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable the Inovio payment gateway', 'inovio-payment-gateway' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Title', 'inovio-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'The payment method name shown to shoppers at checkout.', 'inovio-payment-gateway' ),
				'default'     => __( 'Credit card', 'inovio-payment-gateway' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description', 'inovio-payment-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Shown beneath the payment method name at checkout.', 'inovio-payment-gateway' ),
				'default'     => __( 'Pay securely by credit card.', 'inovio-payment-gateway' ),
			),
			'req_username'     => array(
				'title'       => __( 'API Username', 'inovio-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'REQ_USERNAME issued by Inovio.', 'inovio-payment-gateway' ),
				'desc_tip'    => true,
			),
			'req_password'     => array(
				'title'       => __( 'API Password', 'inovio-payment-gateway' ),
				'type'        => 'password',
				'description' => __( 'REQ_PASSWORD issued by Inovio.', 'inovio-payment-gateway' ),
				'desc_tip'    => true,
			),
			'site_id'          => array(
				'title'       => __( 'Site ID', 'inovio-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'SITE_ID issued by Inovio.', 'inovio-payment-gateway' ),
				'desc_tip'    => true,
			),
			'merch_acct_id'    => array(
				'title'       => __( 'Merchant Account ID', 'inovio-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Optional. Leave empty to let the gateway distribute by currency/country.', 'inovio-payment-gateway' ),
				'desc_tip'    => true,
			),
			'site_key'         => array(
				'title'       => __( 'Site Key', 'inovio-payment-gateway' ),
				'type'        => 'password',
				'description' => __( 'Per-site HMAC secret for browser tokenization, issued by Inovio support. This is NOT the API password.', 'inovio-payment-gateway' ),
			),
			'product_id'       => array(
				'title'       => __( 'Gateway Product ID', 'inovio-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'The Inovio product (LI_PROD_ID) orders are billed under.', 'inovio-payment-gateway' ),
				'desc_tip'    => true,
			),
			'endpoint'         => array(
				'title'       => __( 'Gateway Endpoint', 'inovio-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'The pmt_service.cfm URL. The token and 3DS endpoints are derived from it.', 'inovio-payment-gateway' ),
				'default'     => 'https://api.inoviopay.com/payment/pmt_service.cfm',
			),
			'payment_action'   => array(
				'title'       => __( 'Payment Action', 'inovio-payment-gateway' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Authorize only leaves the order on hold until you capture it from the order screen.', 'inovio-payment-gateway' ),
				'default'     => 'sale',
				'desc_tip'    => true,
				'options'     => array(
					'sale'      => __( 'Sale (authorize and capture)', 'inovio-payment-gateway' ),
					'authorize' => __( 'Authorize only', 'inovio-payment-gateway' ),
				),
			),
			'threeds_active'   => array(
				'title'       => __( '3-D Secure', 'inovio-payment-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable 3-D Secure authentication', 'inovio-payment-gateway' ),
				'description' => __( 'Requires a 3DS-configured merchant account.', 'inovio-payment-gateway' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'vault_active'     => array(
				'title'       => __( 'Saved Cards', 'inovio-payment-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Let logged-in shoppers save cards for reuse', 'inovio-payment-gateway' ),
				'description' => __( 'Stores the gateway\'s own card references only — never a card number.', 'inovio-payment-gateway' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'descriptor'       => array(
				'title'       => __( 'Statement Descriptor', 'inovio-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'What appears on the cardholder\'s statement.', 'inovio-payment-gateway' ),
				'desc_tip'    => true,
			),
			'descriptor_phone' => array(
				'title'       => __( 'Descriptor Phone', 'inovio-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Support phone number shown alongside the descriptor.', 'inovio-payment-gateway' ),
				'desc_tip'    => true,
			),
			'debug'            => array(
				'title'       => __( 'Debug Logging', 'inovio-payment-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log gateway activity to WooCommerce > Status > Logs', 'inovio-payment-gateway' ),
				'description' => __( 'Declines and errors are always logged. Card numbers are never logged — they never reach this server.', 'inovio-payment-gateway' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * Whether every credential the gateway needs is present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		foreach ( array( 'req_username', 'req_password', 'site_id', 'site_key', 'product_id' ) as $key ) {
			if ( '' === trim( (string) $this->get_option( $key ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The gateway is only offered when it is fully configured — an
	 * unconfigured gateway at checkout can only produce failed orders.
	 *
	 * @return bool
	 */
	public function is_available() {
		return 'yes' === $this->enabled && $this->is_configured();
	}

	/**
	 * Whether 3-D Secure is switched on.
	 *
	 * @return bool
	 */
	public function threeds_active() {
		return 'yes' === $this->get_option( 'threeds_active', 'no' );
	}

	/**
	 * Whether vaulting is switched on.
	 *
	 * @return bool
	 */
	public function vault_active() {
		return 'yes' === $this->get_option( 'vault_active', 'yes' );
	}

	/**
	 * The configured payment action.
	 *
	 * @return string 'sale' or 'authorize'.
	 */
	public function payment_action() {
		return 'authorize' === $this->get_option( 'payment_action', 'sale' ) ? 'authorize' : 'sale';
	}

	// ------------------------------------------------------------- checkout

	/**
	 * Load the checkout assets.
	 *
	 * @return void
	 */
	public function payment_scripts() {
		if ( ! is_checkout() && ! is_checkout_pay_page() ) {
			return;
		}

		if ( ! $this->is_available() ) {
			return;
		}

		wp_enqueue_style(
			'inovio-checkout',
			INOVIO_WC_PLUGIN_URL . 'assets/css/inovio-checkout.css',
			array(),
			INOVIO_WC_VERSION
		);

		wp_enqueue_script(
			'inovio-checkout',
			INOVIO_WC_PLUGIN_URL . 'assets/js/inovio-checkout.js',
			array( 'jquery' ),
			INOVIO_WC_VERSION,
			true
		);

		wp_localize_script(
			'inovio-checkout',
			'inovioConfig',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'signatureAction' => Inovio_Signature_Endpoint::ACTION,
				'signatureNonce'  => wp_create_nonce( Inovio_Signature_Endpoint::NONCE_ACTION ),
				'prepareAction'   => Inovio_ThreeDS_Controller::PREPARE_ACTION,
				'prepareNonce'    => wp_create_nonce( Inovio_ThreeDS_Controller::NONCE_ACTION ),
				'tokenUrl'        => Inovio_Gateway_Client::token_endpoint(),
				'threeDsActive'   => $this->threeds_active(),
				'translations'    => array(
					'invalidCard'   => __( 'Please enter a valid card number.', 'inovio-payment-gateway' ),
					'invalidExpiry' => __( 'Please enter a valid expiration date.', 'inovio-payment-gateway' ),
					'invalidCvv'    => __( 'Please enter a valid security code.', 'inovio-payment-gateway' ),
					'tokenizeFailed'=> __( 'Card could not be processed. Please try again.', 'inovio-payment-gateway' ),
					'unreachable'   => __( 'Could not reach the payment service. Please try again.', 'inovio-payment-gateway' ),
					'signFailed'    => __( 'Payment signing failed. Please refresh and try again.', 'inovio-payment-gateway' ),
					'threeDsFailed' => __( 'Card authentication could not be started. Please try again.', 'inovio-payment-gateway' ),
					'processing'    => __( 'Processing your card…', 'inovio-payment-gateway' ),
					'authFailed'    => __( 'Payment authentication failed.', 'inovio-payment-gateway' ),
				),
			)
		);
	}

	/**
	 * Render the card fields at checkout.
	 *
	 * CRITICAL: the card number, CVV and expiry inputs deliberately carry NO
	 * `name` attribute. WooCommerce serializes the checkout form and POSTs it
	 * to WordPress; an unnamed input is not serialized, so the PAN cannot
	 * reach this server even accidentally. Only the hidden token fields
	 * written by the JS have names.
	 *
	 * @return void
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo '<p>' . wp_kses_post( wpautop( wptexturize( $this->description ) ) ) . '</p>';
		}

		echo '<div id="inovio-payment-fields" class="inovio-payment-fields">';

		// WooCommerce renders the saved-card radio list itself when the
		// gateway supports tokenization, including the "use a new card" option.
		if ( $this->vault_active() && is_user_logged_in() ) {
			$this->saved_payment_methods();
		}

		echo '<div id="inovio-errors" class="inovio-errors" style="display:none;" role="alert"></div>';

		echo '<div class="inovio-new-card">';

		echo '<p class="form-row form-row-wide">';
		echo '<label for="inovio-card-number">' . esc_html__( 'Card number', 'inovio-payment-gateway' ) . ' <span class="required">*</span></label>';
		// No name attribute — see the docblock. inputmode/autocomplete give the
		// browser what it needs without WordPress ever seeing the value.
		echo '<input id="inovio-card-number" class="input-text" type="text" inputmode="numeric" autocomplete="cc-number" maxlength="24" placeholder="•••• •••• •••• ••••" />';
		echo '</p>';

		echo '<p class="form-row form-row-first">';
		echo '<label for="inovio-exp-month">' . esc_html__( 'Expiry', 'inovio-payment-gateway' ) . ' <span class="required">*</span></label>';
		echo '<select id="inovio-exp-month" class="inovio-exp">';
		echo '<option value="">' . esc_html__( 'MM', 'inovio-payment-gateway' ) . '</option>';
		for ( $m = 1; $m <= 12; $m++ ) {
			$value = str_pad( (string) $m, 2, '0', STR_PAD_LEFT );
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $value ) . '</option>';
		}
		echo '</select> ';
		echo '<select id="inovio-exp-year" class="inovio-exp">';
		echo '<option value="">' . esc_html__( 'YYYY', 'inovio-payment-gateway' ) . '</option>';
		$this_year = (int) gmdate( 'Y' );
		for ( $y = $this_year; $y <= $this_year + 11; $y++ ) {
			echo '<option value="' . esc_attr( (string) $y ) . '">' . esc_html( (string) $y ) . '</option>';
		}
		echo '</select>';
		echo '</p>';

		echo '<p class="form-row form-row-last">';
		echo '<label for="inovio-cvv">' . esc_html__( 'Security code', 'inovio-payment-gateway' ) . ' <span class="required">*</span></label>';
		echo '<input id="inovio-cvv" class="input-text" type="text" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="•••" />';
		echo '</p>';

		echo '<div class="clear"></div>';
		echo '</div>';

		// The hidden fields the JS populates. These ARE named: they are the
		// only payment data WordPress is ever meant to receive.
		echo '<input type="hidden" name="inovio_token_guid" id="inovio-token-guid" value="" />';
		echo '<input type="hidden" name="inovio_token_guid_completion" id="inovio-token-guid-completion" value="" />';
		echo '<input type="hidden" name="inovio_pmt_expiry" id="inovio-pmt-expiry" value="" />';
		echo '<input type="hidden" name="inovio_cc_brand" id="inovio-cc-brand" value="" />';
		echo '<input type="hidden" name="inovio_cc_last4" id="inovio-cc-last4" value="" />';
		echo '<input type="hidden" name="inovio_ddc_reference_id" id="inovio-ddc-reference-id" value="" />';
		echo '<input type="hidden" name="inovio_browser" id="inovio-browser" value="" />';

		if ( $this->vault_active() && is_user_logged_in() ) {
			$this->save_payment_method_checkbox();
		}

		echo '</div>';
	}

	// -------------------------------------------------------------- payment

	/**
	 * Run the transaction and tell WooCommerce what to do next.
	 *
	 * @param int $order_id Order being paid.
	 * @return array<string, string> WooCommerce process_payment result.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return $this->fail( null, 'Order ' . (int) $order_id . ' could not be loaded.', __( 'This order could not be loaded.', 'inovio-payment-gateway' ) );
		}

		$payment = $this->collect_payment_data( $order );

		if ( is_wp_error( $payment ) ) {
			return $this->fail( $order, 'checkout refused: ' . $payment->get_error_code(), $payment->get_error_message() );
		}

		// Remember which action produced this order: the 3DS completion leg and
		// the admin capture/void actions both need to know, and the merchant may
		// change the setting between order placement and capture.
		$order->update_meta_data( Inovio_Gateway_Client::META_PAYMENT_ACTION, $this->payment_action() );
		$order->save();

		try {
			$result = $this->run_transaction( $order, $payment );
		} catch ( GatewayTimeoutException $e ) {
			// The transaction state is UNKNOWN — reconcile before failing, or
			// a blind retry could double-charge.
			$recovered = Inovio_Gateway_Client::reconcile_timeout( $order, $e );

			if ( null === $recovered ) {
				return $this->fail(
					$order,
					'gateway timeout, unreconciled: ' . $e->getMessage(),
					__( 'The payment service did not respond in time. Please check your order status before retrying.', 'inovio-payment-gateway' )
				);
			}

			$result = $recovered;
		} catch ( \Throwable $e ) {
			return $this->fail(
				$order,
				'transaction error: ' . $e->getMessage(),
				__( 'Your payment could not be processed. Please check your details and try again.', 'inovio-payment-gateway' )
			);
		}

		return $this->finalize( $order, $result, $payment );
	}

	/**
	 * Everything the browser sent.
	 *
	 * Note what is absent: no PAN, no CVV. The CVV goes browser-direct to the
	 * token service alongside the card number and is stored nowhere.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array<string, mixed>|WP_Error
	 */
	private function collect_payment_data( $order ) {
		// The checkout nonce is verified by WooCommerce core before
		// process_payment() runs; these reads are of an already-validated POST.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$field = static function ( $key ) use ( $post ) {
			return isset( $post[ $key ] ) ? sanitize_text_field( (string) $post[ $key ] ) : '';
		};

		// A saved card: no tokenization leg at all, so no token is expected.
		$token_id = isset( $post[ 'wc-' . $this->id . '-payment-token' ] )
			? sanitize_text_field( (string) $post[ 'wc-' . $this->id . '-payment-token' ] )
			: '';

		if ( '' !== $token_id && 'new' !== $token_id ) {
			if ( ! $this->vault_active() ) {
				return new WP_Error( 'vault_disabled', __( 'Saved cards are not available.', 'inovio-payment-gateway' ) );
			}

			// Ownership-checked load — the IDOR guard. A shopper must never be
			// able to charge another shopper's saved card by guessing an id.
			$token = Inovio_Vault::find_for_customer( (int) $token_id, get_current_user_id() );

			if ( null === $token ) {
				return new WP_Error( 'saved_card_not_found', __( 'That saved card is no longer available.', 'inovio-payment-gateway' ) );
			}

			$refs = Inovio_Vault::references( $token );

			if ( '' === $refs['pmt_id'] || '' === $refs['cust_id'] ) {
				return new WP_Error( 'saved_card_incomplete', __( 'That saved card is no longer usable.', 'inovio-payment-gateway' ) );
			}

			return array(
				'cust_id'   => $refs['cust_id'],
				'pmt_id'    => $refs['pmt_id'],
				'browser'   => $field( 'inovio_browser' ),
				'save_card' => false,
			);
		}

		$token_guid = $field( 'inovio_token_guid' );
		$pmt_expiry = $field( 'inovio_pmt_expiry' );

		if ( '' === $token_guid || '' === $pmt_expiry ) {
			// The JS did not complete tokenization. This is never a reason to
			// fall back to anything — there is no other way to charge a card
			// here, and pretending otherwise would mean accepting a PAN.
			return new WP_Error(
				'not_tokenized',
				__( 'Your card was not processed by the payment service. Please re-enter your card details and try again.', 'inovio-payment-gateway' )
			);
		}

		return array(
			'token_guid'            => $token_guid,
			'token_guid_completion' => $field( 'inovio_token_guid_completion' ),
			'pmt_expiry'            => $pmt_expiry,
			'cc_brand'              => $field( 'inovio_cc_brand' ),
			'cc_last4'              => $field( 'inovio_cc_last4' ),
			'ddc_reference_id'      => $field( 'inovio_ddc_reference_id' ),
			'browser'               => $field( 'inovio_browser' ),
			'save_card'             => $this->wants_to_save_card( $post ),
		);
	}

	/**
	 * Whether the shopper explicitly ticked "save this card".
	 *
	 * An unticked box must never reach the vault, so this is an explicit
	 * affirmative test rather than a truthiness check on a possibly-absent key.
	 *
	 * @param array<string, mixed> $post Unslashed POST data.
	 * @return bool
	 */
	private function wants_to_save_card( array $post ) {
		if ( ! $this->vault_active() || ! is_user_logged_in() ) {
			return false;
		}

		$key = 'wc-' . $this->id . '-new-payment-method';

		if ( ! isset( $post[ $key ] ) ) {
			return false;
		}

		return in_array( (string) $post[ $key ], array( 'true', '1', 'yes', 'on' ), true );
	}

	/**
	 * Run sale() or authorize() per the configured payment action.
	 *
	 * @param WC_Order             $order   Order.
	 * @param array<string, mixed> $payment Checkout payload.
	 * @return TransactionResult
	 */
	private function run_transaction( $order, array $payment ) {
		$req    = Inovio_Gateway_Client::build_request( $order, $payment );
		$client = Inovio_Gateway_Client::client();

		Inovio_Logger::debug( sprintf( '%s order %d for %s %s', $this->payment_action(), $order->get_id(), $order->get_total(), $order->get_currency() ) );

		return 'authorize' === $this->payment_action()
			? $client->authorize( $req )
			: $client->sale( $req );
	}

	/**
	 * Act on the gateway's answer.
	 *
	 * @param WC_Order             $order   Order.
	 * @param TransactionResult    $result  Gateway result.
	 * @param array<string, mixed> $payment Checkout payload.
	 * @return array<string, string>
	 */
	private function finalize( $order, TransactionResult $result, array $payment ) {
		Inovio_Gateway_Client::record_references( $order, $result );

		$po_id = $result->orderRef ? $result->orderRef->poId() : '-';

		// A 3DS challenge: the order stays pending until the ACS return leg
		// completes it. PENDING is emphatically not a failure.
		if ( Inovio_Gateway_Client::is_challenge( $result ) ) {
			return $this->begin_challenge( $order, $result, $payment );
		}

		if ( ! Inovio_Gateway_Client::is_approved( $result ) ) {
			$advice = Inovio_Gateway_Client::advice( $result );

			return $this->fail(
				$order,
				sprintf(
					'declined order %d (PO_ID %s): status=%s service=%s advice=%s',
					$order->get_id(),
					$po_id,
					$result->status,
					(string) ( $result->outcome->service->code ?? '-' ),
					$advice ? $advice : 'none'
				),
				$advice ? $advice : __( 'Your payment was declined. Please try a different card.', 'inovio-payment-gateway' )
			);
		}

		$transaction_id = $result->transactionId ? $result->transactionId->value() : '';

		if ( 'authorize' === $this->payment_action() ) {
			// WooCommerce has no authorize/capture split, so on-hold is the
			// awaiting-capture state: stock is reserved, the order is not paid.
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: %s: gateway PO_ID. */
					__( 'Inovio: authorized, awaiting capture (PO_ID %s).', 'inovio-payment-gateway' ),
					$po_id
				)
			);
			$order->set_transaction_id( $transaction_id );
			wc_reduce_stock_levels( $order->get_id() );
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: 1: gateway PO_ID, 2: gateway transaction id. */
					__( 'Inovio payment approved (PO_ID %1$s, TRANS_ID %2$s).', 'inovio-payment-gateway' ),
					$po_id,
					$transaction_id ? $transaction_id : '-'
				)
			);
			// payment_complete() sets the paid status, reduces stock and
			// records the transaction id in one step.
			$order->payment_complete( $transaction_id );
		}

		$order->save();

		$this->maybe_vault( $order, $result, $payment );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		Inovio_Logger::debug( 'order ' . $order->get_id() . ' approved, PO_ID ' . $po_id );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Park the order pending 3DS and hand the challenge back to the browser.
	 *
	 * The order is created and left `pending` — unpaid, no stock reduction —
	 * until the ACS return leg approves it. The challenge data is stored on the
	 * order because the ACS return may arrive without any session at all.
	 *
	 * @param WC_Order             $order   Order.
	 * @param TransactionResult    $result  Enrollment result carrying nextAction.
	 * @param array<string, mixed> $payment Checkout payload.
	 * @return array<string, string>
	 */
	private function begin_challenge( $order, TransactionResult $result, array $payment ) {
		$completion_token = isset( $payment['token_guid_completion'] ) ? (string) $payment['token_guid_completion'] : '';

		if ( '' === $completion_token ) {
			/*
			 * Without the second token the completion leg cannot run — the
			 * first token was just consumed by this enrollment leg. Fail here
			 * rather than opening a challenge the shopper can never finish.
			 */
			return $this->fail(
				$order,
				'3DS challenge required but no completion token was minted for order ' . $order->get_id(),
				__( 'Card authentication could not be completed. Please try again.', 'inovio-payment-gateway' )
			);
		}

		$order->update_meta_data(
			Inovio_Gateway_Client::META_CHALLENGE,
			(string) wp_json_encode(
				array(
					'procTransId' => $result->nextAction->procTransId ?? '',
					'redirectUrl' => $result->nextAction->redirectUrl ?? '',
					'jwt'         => $result->nextAction->jwt ?? '',
				)
			)
		);
		$order->update_meta_data( Inovio_Gateway_Client::META_TOKEN_COMPLETE, $completion_token );
		$order->update_meta_data( Inovio_Gateway_Client::META_PMT_EXPIRY, (string) $payment['pmt_expiry'] );
		$order->update_status( 'pending', __( 'Inovio: awaiting 3-D Secure authentication.', 'inovio-payment-gateway' ) );
		$order->save();

		Inovio_Logger::debug( '3DS challenge opened for order ' . $order->get_id() );

		/*
		 * `inovio_3ds` is a JSON STRING, not a nested array, even though both
		 * checkout JS files immediately JSON.parse() it back into an object.
		 * This looks redundant but is load-bearing for the Block Checkout: the
		 * Store API's response schema
		 * (Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema
		 * 'payment_result.payment_details.items.properties.value') types every
		 * payment_details value as a plain `string`. A nested array/object
		 * value there gets silently coerced by WordPress's REST schema
		 * sanitizer via PHP's array-to-string cast, arriving in the browser as
		 * the literal string "Array" — verified empirically against a live
		 * Block Checkout run (order 66: `"inovio_3ds":"Array"`), which is a
		 * fully swallowed failure with no error anywhere. The classic
		 * checkout's own AJAX response has no such schema and would have
		 * accepted a nested array, but it reads the same field, so it is
		 * encoded here too rather than carrying two different shapes for the
		 * one server implementation this class is trying to keep single.
		 */
		return array(
			'result'     => 'success',
			'redirect'   => $this->get_return_url( $order ),
			'inovio_3ds' => wp_json_encode(
				array(
					'redirectUrl' => $result->nextAction->redirectUrl ?? '',
					'jwt'         => $result->nextAction->jwt ?? '',
				)
			),
		);
	}

	/**
	 * Store the card if — and only if — the shopper opted in.
	 *
	 * @param WC_Order             $order   Order.
	 * @param TransactionResult    $result  Approved gateway result.
	 * @param array<string, mixed> $payment Checkout payload.
	 * @return void
	 */
	private function maybe_vault( $order, TransactionResult $result, array $payment ) {
		if ( empty( $payment['save_card'] ) || ! $this->vault_active() ) {
			return;
		}

		$token = Inovio_Vault::save_from_result(
			$order->get_customer_id(),
			$result,
			isset( $payment['pmt_expiry'] ) ? (string) $payment['pmt_expiry'] : '',
			isset( $payment['cc_brand'] ) ? (string) $payment['cc_brand'] : '',
			isset( $payment['cc_last4'] ) ? (string) $payment['cc_last4'] : ''
		);

		if ( null !== $token ) {
			$order->add_payment_token( $token );
			$order->save();
		}
	}

	/**
	 * Fail the checkout: log the real reason, show the shopper a usable one.
	 *
	 * Nothing is ever swallowed here — every path that returns a failure to
	 * WooCommerce writes a log line first, so a merchant can always tell a
	 * genuine decline from a broken integration.
	 *
	 * @param WC_Order|null $order       Order, when one exists.
	 * @param string        $log_message Internal detail for the log.
	 * @param string        $notice      Message shown to the shopper.
	 * @return array<string, string>
	 */
	private function fail( $order, $log_message, $notice ) {
		Inovio_Logger::error( $log_message );

		if ( $order ) {
			$order->update_status( 'failed', $notice );
		}

		wc_add_notice( $notice, 'error' );

		return array(
			'result'   => 'failure',
			'messages' => wc_print_notices( true ),
		);
	}

	// --------------------------------------------------------------- refund

	/**
	 * Refund from the WooCommerce admin Refund button.
	 *
	 * Settlement-aware by necessity — see Inovio_Gateway_Client::refund_order().
	 * A refund on an unsettled order is refused by the gateway with SERVICE 536
	 * "Order not settled: Please reverse", so the correct verb depends on
	 * whether the processor batch has settled.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount to refund, or null for the full amount.
	 * @param string     $reason   Merchant-supplied reason.
	 * @return bool|WP_Error True on success, WP_Error with the gateway's reason otherwise.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'inovio_refund_error', __( 'Order not found.', 'inovio-payment-gateway' ) );
		}

		$amount_string = ( null === $amount ) ? null : Inovio_Gateway_Client::money_string( $amount );

		try {
			$result = Inovio_Gateway_Client::refund_order( $order, $amount_string );
		} catch ( \Throwable $e ) {
			// Surfaced to the merchant as a failed refund, never silently
			// treated as successful — WooCommerce would otherwise record a
			// refund that the gateway never performed.
			Inovio_Logger::error( 'refund failed for order ' . $order->get_id() . ': ' . $e->getMessage() );

			return new WP_Error( 'inovio_refund_error', $e->getMessage() );
		}

		if ( ! Inovio_Gateway_Client::is_approved( $result ) ) {
			$advice = Inovio_Gateway_Client::advice( $result );
			Inovio_Logger::error(
				'refund not approved for order ' . $order->get_id() . ': ' . $result->status . ' — ' . ( $advice ? $advice : 'no advice' )
			);

			return new WP_Error(
				'inovio_refund_declined',
				$advice ? $advice : __( 'The gateway refused the refund.', 'inovio-payment-gateway' )
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: refunded amount, 2: gateway action, 3: merchant reason. */
				__( 'Inovio refund succeeded (%1$s via %2$s). Reason: %3$s', 'inovio-payment-gateway' ),
				null === $amount_string ? __( 'full amount', 'inovio-payment-gateway' ) : $amount_string,
				$result->action,
				'' !== $reason ? $reason : __( 'none given', 'inovio-payment-gateway' )
			)
		);

		return true;
	}
}
