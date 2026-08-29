/**
 * Inovio direct-post checkout (WooCommerce, Block Checkout).
 *
 * Same PAN-safety contract as the classic checkout script
 * (assets/js/inovio-checkout.js): the card fields live only in this page and
 * in the browser-direct POST to Inovio's token service. WordPress never sees
 * a card number — only the single-use TOKEN_GUID(s) this script mints.
 *
 * Why this is a SEPARATE file rather than a shared module: the Block
 * Checkout and the classic checkout are different JS runtimes with different
 * script-dependency graphs (this one depends on `wp-element` and the
 * `wc-blocks-registry`/`wc-blocks-checkout-events` globals; the classic file
 * depends on jQuery and WooCommerce's `checkout_place_order` event). There is
 * no bundler in this plugin to share ES modules between the two enqueued
 * scripts, so the tokenize/validate/3DS helper functions below are
 * deliberately mirrored from inovio-checkout.js rather than imported. Keep
 * behavior identical between the two files if either changes.
 *
 * Blocks wiring, in one paragraph: `registerPaymentMethod` takes a `content`
 * component that Blocks renders inside the payment step. That component's
 * props include `eventRegistration.onPaymentSetup` — a hook to subscribe a
 * handler that runs when the shopper clicks Place Order. The handler
 * tokenizes the card and resolves with `{ type: SUCCESS, meta: {
 * paymentMethodData } }`; WooCommerce's Store API then puts those key/value
 * pairs into `$_POST` server-side before calling
 * `Inovio_Payment_Gateway::process_payment()` — see
 * `Automattic\WooCommerce\StoreApi\Legacy::process_legacy_payment()`. That is
 * the ENTIRE integration surface with the server: the field names below
 * (`inovio_token_guid`, etc.) must match what `collect_payment_data()` reads,
 * and nothing else needs to change server-side.
 */
(function () {
	'use strict';

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var checkoutEvents = window.wc.blocksCheckoutEvents.checkoutEvents;
	var getPaymentMethodData = window.wc.wcSettings.getPaymentMethodData;
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var __ = window.wp.i18n.__;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;

	var GATEWAY_ID = 'inovio';

	/**
	 * The PHP-injected config from Inovio_Blocks_Support::get_payment_method_data().
	 * Read once at module scope: it cannot change without a page reload, and
	 * every helper below wants the same object.
	 * @returns {Object}
	 */
	function cfg() {
		return getPaymentMethodData( GATEWAY_ID, {} ) || {};
	}

	/**
	 * @param {string} key Translation key.
	 * @param {string} fallback Text used when the key is absent.
	 * @returns {string}
	 */
	function translate( key, fallback ) {
		var t = cfg().translations || {};

		return t[ key ] || fallback;
	}

	// ------------------------------------------------------------------
	// Card helpers (mirrored from inovio-checkout.js — see file docblock)
	// ------------------------------------------------------------------

	function normalizePan( raw ) {
		return String( raw || '' ).replace( /[\s-]/g, '' );
	}

	function cardBrand( pan ) {
		if ( /^4/.test( pan ) ) { return 'VI'; }
		if ( /^(5[1-5]|2[2-7])/.test( pan ) ) { return 'MC'; }
		if ( /^3[47]/.test( pan ) ) { return 'AE'; }
		if ( /^(6011|64[4-9]|65)/.test( pan ) ) { return 'DI'; }
		if ( /^35/.test( pan ) ) { return 'JCB'; }
		if ( /^3(0[0-5]|[68])/.test( pan ) ) { return 'DN'; }

		return '';
	}

	function luhnValid( pan ) {
		var sum = 0, dbl = false, i, d;

		if ( ! /^[0-9]+$/.test( pan ) ) {
			return false;
		}

		for ( i = pan.length - 1; i >= 0; i-- ) {
			d = parseInt( pan.charAt( i ), 10 );

			if ( dbl ) {
				d *= 2;

				if ( d > 9 ) { d -= 9; }
			}
			sum += d;
			dbl = ! dbl;
		}

		return pan.length >= 12 && sum % 10 === 0;
	}

	/**
	 * @param {{pan: string, month: string, year: string, cvv: string}} fields
	 * @returns {string|null} An error message, or null if valid.
	 */
	function validate( fields ) {
		var now = new Date(),
			month = parseInt( fields.month, 10 ),
			year = parseInt( fields.year, 10 );

		if ( ! luhnValid( fields.pan ) ) {
			return translate( 'invalidCard', 'Please enter a valid card number.' );
		}

		if ( ! fields.month || ! fields.year || isNaN( month ) || isNaN( year ) ||
			year < now.getFullYear() ||
			( year === now.getFullYear() && month < now.getMonth() + 1 )
		) {
			return translate( 'invalidExpiry', 'Please enter a valid expiration date.' );
		}

		if ( ! /^[0-9]{3,4}$/.test( String( fields.cvv ) ) ) {
			return translate( 'invalidCvv', 'Please enter a valid security code.' );
		}

		return null;
	}

	/**
	 * @param {number} length Number of hex characters (even).
	 * @returns {string}
	 */
	function randomHex( length ) {
		var bytes = new Uint8Array( length / 2 );

		window.crypto.getRandomValues( bytes );

		return Array.prototype.map.call( bytes, function ( b ) {
			return ( '0' + b.toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	/**
	 * @returns {Object}
	 */
	function collectBrowserData() {
		return {
			language: navigator.language || 'en-US',
			userAgent: navigator.userAgent,
			header: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			javaEnabled: typeof navigator.javaEnabled === 'function' ? navigator.javaEnabled() : false,
			colorDepth: window.screen.colorDepth,
			screenHeight: window.screen.height,
			screenWidth: window.screen.width,
			timeZoneOffset: Math.abs( new Date().getTimezoneOffset() )
		};
	}

	// ------------------------------------------------------------------
	// Tokenization / 3DS (mirrored from inovio-checkout.js)
	// ------------------------------------------------------------------

	/**
	 * @param {string} pan
	 * @param {string} cvv
	 * @returns {Promise<string>}
	 */
	function mintToken( pan, cvv ) {
		var uid = randomHex( 32 ),
			body = new URLSearchParams();

		body.append( 'action', cfg().signatureAction );
		body.append( 'nonce', cfg().signatureNonce );
		body.append( 'uniqueId', uid );

		return fetch( cfg().ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
			body: body.toString()
		} ).then( function ( resp ) {
			if ( ! resp.ok ) {
				throw new Error( 'signature' );
			}

			return resp.json();
		} ).catch( function () {
			throw translate( 'signFailed', 'Payment signing failed. Please refresh and try again.' );
		} ).then( function ( sig ) {
			var tokenBody = new URLSearchParams();

			// The ONLY place the card number appears in any network call, and
			// it goes straight to the gateway — not to WordPress.
			tokenBody.append( 'card_pan', pan );
			tokenBody.append( 'card_cvv', String( cvv ) );
			tokenBody.append( 'request_response_format', 'json' );
			tokenBody.append( 'request_api_version', '4.14' );
			tokenBody.append( 'site_id', sig.siteId );
			tokenBody.append( 'unique_id', uid );

			return fetch( sig.tokenUrl || cfg().tokenUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-timestamp': sig.timestamp,
					'X-signature': sig.signature
				},
				body: tokenBody.toString()
			} ).then( function ( resp ) {
				return resp.json();
			} ).then( function ( token ) {
				if ( ! token.TOKEN_GUID ) {
					throw token.ERROR_MESSAGE ||
						translate( 'tokenizeFailed', 'Card could not be processed. Please try again.' );
				}

				return token.TOKEN_GUID;
			} ).catch( function ( err ) {
				if ( typeof err === 'string' ) {
					throw err;
				}

				throw translate( 'unreachable', 'Could not reach the payment service. Please try again.' );
			} );
		} );
	}

	/**
	 * Full tokenize + (optional) 3DS-prepare chain. See inovio-checkout.js for
	 * why two tokens are minted when 3DS is on: TOKEN_GUIDs are single-use and
	 * the enrollment leg consumes the first one.
	 *
	 * @param {{pan: string, cvv: string}} card
	 * @returns {Promise<Object>}
	 */
	function tokenizeFlow( card ) {
		var needsSecond = !! cfg().threeDsActive,
			result = {
				tokenGuid: null,
				tokenGuidCompletion: null,
				ddcReferenceId: null,
				browserData: collectBrowserData()
			};

		return mintToken( card.pan, card.cvv ).then( function ( guid ) {
			result.tokenGuid = guid;

			if ( ! needsSecond ) {
				return result;
			}

			return mintToken( card.pan, card.cvv ).then( function ( guid2 ) {
				result.tokenGuidCompletion = guid2;

				return prepareThreeDs( card.pan.slice( 0, 6 ) ).then( function ( ddcReferenceId ) {
					result.ddcReferenceId = ddcReferenceId;

					return result;
				} );
			} );
		} );
	}

	/**
	 * @param {string} bin First 6 digits of the PAN — not cardholder data.
	 * @returns {Promise<string>} ddcReferenceId
	 */
	function prepareThreeDs( bin ) {
		var body = new URLSearchParams();

		body.append( 'action', cfg().prepareAction );
		body.append( 'nonce', cfg().prepareNonce );
		body.append( 'bin', bin );

		return fetch( cfg().ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
			body: body.toString()
		} ).then( function ( resp ) {
			if ( ! resp.ok ) {
				throw new Error( 'prepare' );
			}

			return resp.json();
		} ).then( function ( ddc ) {
			if ( ! ddc || ! ddc.jwt || ! ddc.ddcUrl || ! ddc.ddcReferenceId ) {
				throw new Error( 'prepare' );
			}

			return runHiddenDdc( ddc ).then( function () {
				return ddc.ddcReferenceId;
			} );
		} ).catch( function () {
			throw translate( 'threeDsFailed', 'Card authentication could not be started. Please try again.' );
		} );
	}

	/**
	 * @param {{jwt: string, ddcUrl: string}} ddc
	 * @returns {Promise<void>}
	 */
	function runHiddenDdc( ddc ) {
		return new Promise( function ( resolve ) {
			var iframe = document.createElement( 'iframe' ),
				form = document.createElement( 'form' ),
				input = document.createElement( 'input' ),
				finished = false,
				finish = function () {
					if ( ! finished ) {
						finished = true;
						window.removeEventListener( 'message', onMessage );
						iframe.remove();
						form.remove();
						resolve();
					}
				},
				onMessage = function ( e ) {
					var data;

					try {
						data = typeof e.data === 'string' ? JSON.parse( e.data ) : e.data;
					} catch ( err ) {
						return;
					}

					if ( data && data.MessageType === 'profile.completed' ) {
						finish();
					}
				};

			iframe.name = 'inovio-ddc-blocks';
			iframe.style.display = 'none';
			form.method = 'POST';
			form.action = ddc.ddcUrl;
			form.target = 'inovio-ddc-blocks';
			input.type = 'hidden';
			input.name = 'JWT';
			input.value = ddc.jwt;
			form.appendChild( input );
			document.body.appendChild( iframe );
			document.body.appendChild( form );
			window.addEventListener( 'message', onMessage );
			form.submit();
			setTimeout( finish, 8000 );
		} );
	}

	/**
	 * Visible ACS challenge iframe — same overlay markup and postMessage
	 * contract as the classic checkout's runChallenge(). The Block Checkout
	 * version resolves/rejects a promise instead of driving a redirect
	 * directly, because the caller (onCheckoutSuccess, below) needs to keep
	 * the Store API's own success/redirect flow paused until the challenge is
	 * done.
	 *
	 * @param {{redirectUrl: string, jwt: string}} challenge
	 * @returns {Promise<{success: boolean, message: string}>}
	 */
	function runChallenge( challenge ) {
		return new Promise( function ( resolve ) {
			var overlay = document.createElement( 'div' ),
				iframe = document.createElement( 'iframe' ),
				form = document.createElement( 'form' ),
				input = document.createElement( 'input' ),
				onMessage = function ( e ) {
					if ( e.origin !== window.location.origin || ! e.data || e.data.inovio3ds !== 'complete' ) {
						return;
					}
					window.removeEventListener( 'message', onMessage );
					overlay.remove();
					form.remove();

					resolve( {
						success: !! e.data.success,
						message: e.data.message || translate( 'authFailed', 'Payment authentication failed.' )
					} );
				};

			overlay.className = 'inovio-3ds-overlay';
			iframe.name = 'inovio-3ds-challenge-blocks';
			iframe.className = 'inovio-3ds-frame';
			overlay.appendChild( iframe );
			form.method = 'POST';
			form.action = challenge.redirectUrl;
			form.target = 'inovio-3ds-challenge-blocks';
			input.type = 'hidden';
			input.name = 'JWT';
			input.value = challenge.jwt;
			form.appendChild( input );
			document.body.appendChild( overlay );
			document.body.appendChild( form );
			window.addEventListener( 'message', onMessage );
			form.submit();
		} );
	}

	// ------------------------------------------------------------------
	// 3DS hand-off
	// ------------------------------------------------------------------

	/**
	 * Find one key's value out of the checkout store's `paymentDetails`.
	 *
	 * On the wire, the Store API's `payment_result.payment_details` is an
	 * array of `{key, value}` objects — every `value` typed as a plain
	 * `string` by the response schema
	 * (`CheckoutSchema::get_item_schema()`,
	 * `payment_result.payment_details.items.properties.value`), which is why
	 * `Inovio_Payment_Gateway::begin_challenge()` sends `inovio_3ds` as a JSON
	 * string rather than a nested object — a nested array there gets coerced
	 * by WordPress's REST schema sanitizer via PHP's array-to-string cast and
	 * arrives as the literal string "Array" (verified empirically: order 66
	 * during development logged exactly that). By the time it reaches the
	 * `onCheckoutSuccess` payload here, though, the checkout data store has
	 * already reduced that `{key,value}[]` wire shape into a plain
	 * `{[key]: value}` object (also verified empirically) — so both shapes
	 * are handled rather than betting on which one a given WooCommerce
	 * version hands the event.
	 *
	 * @param {Array<{key:string,value:string}>|Object<string,string>} details
	 * @param {string} key
	 * @returns {string|null}
	 */
	function paymentDetail( details, key ) {
		var i;

		if ( Array.isArray( details ) ) {
			for ( i = 0; i < details.length; i++ ) {
				if ( details[ i ] && details[ i ].key === key ) {
					return details[ i ].value;
				}
			}

			return null;
		}

		if ( details && typeof details === 'object' && key in details ) {
			return details[ key ];
		}

		return null;
	}

	/*
	 * `onCheckoutSuccess` fires once the order is placed successfully — before
	 * the Block Checkout navigates to the order-received page. Returning a
	 * promise from the handler holds that navigation open until it resolves
	 * (the store awaits every subscriber via `emitWithAbort` before marking
	 * checkout complete), which is exactly what running the challenge here
	 * needs: the shopper must authenticate before landing on a "thank you"
	 * page for a payment that has not actually completed yet. If the
	 * challenge fails, the handler resolves with an ERROR response instead of
	 * SUCCESS, which keeps the shopper on checkout with an error notice — the
	 * order itself was already left `pending` server-side by
	 * begin_challenge(), so there is nothing to undo.
	 *
	 * The challenge data lives at `response.processingResponse.paymentDetails`
	 * — NOT `response.paymentDetails`, which does not exist on this event's
	 * payload. `processingResponse` is the payment store's own
	 * `getPaymentResult()` state, set verbatim from the Store API's checkout
	 * response (verified empirically the same way as the value-typing note
	 * above).
	 *
	 * @param {Object} response Store API checkout event payload.
	 * @returns {Promise<Object>|undefined}
	 */
	function onCheckoutSuccessHandler( response ) {
		var processing = ( response && response.processingResponse ) || {},
			raw = paymentDetail( processing.paymentDetails, 'inovio_3ds' ),
			challenge = null;

		if ( raw ) {
			try {
				challenge = JSON.parse( raw );
			} catch ( err ) {
				challenge = null;
			}
		}

		// No challenge required (frictionless approval, or a non-Inovio
		// gateway's own success event on the same shared bus): do nothing and
		// let the Store API's default redirect proceed. Per the checkout
		// events emitter's contract, a handler that returns undefined is
		// simply not counted as a response — see
		// checkoutEventsEmitter.emit()'s isObserverResponse() filter.
		if ( ! challenge || ! challenge.redirectUrl ) {
			return;
		}

		return runChallenge( challenge ).then( function ( outcome ) {
			if ( outcome.success ) {
				// Let the default redirect (to the order-received page) proceed.
				return { type: 'success' };
			}

			return {
				type: 'error',
				message: outcome.message
			};
		} );
	}

	checkoutEvents.onCheckoutSuccess( onCheckoutSuccessHandler );

	// ------------------------------------------------------------------
	// Card fields component
	// ------------------------------------------------------------------

	/**
	 * Years shown in the expiry select — this year through +11, matching the
	 * classic checkout's field.
	 * @returns {number[]}
	 */
	function expiryYears() {
		var years = [], start = new Date().getFullYear(), y;

		for ( y = start; y <= start + 11; y++ ) {
			years.push( y );
		}

		return years;
	}

	/**
	 * The card-fields React component rendered inside the Block Checkout's
	 * payment step. Uncontrolled inputs (no onChange/useState wiring per
	 * keystroke) are deliberate: the PAN must never enter React state, which
	 * would put it in the component tree and any devtools inspecting it —
	 * values are read directly off the DOM at submit time instead, exactly
	 * like the classic checkout's plain `document.getElementById(...).value`.
	 *
	 * @param {Object} props Injected by the Blocks payment-method registry.
	 * @returns {*}
	 */
	function InovioContent( props ) {
		var emitResponse = props.emitResponse;
		var onPaymentSetup = props.eventRegistration.onPaymentSetup;
		var config = cfg();
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		useEffect( function () {
			var unsubscribe = onPaymentSetup( function () {
				var pan = normalizePan( fieldValue( 'inovio-card-number-blocks' ) ),
					month = fieldValue( 'inovio-exp-month-blocks' ),
					year = fieldValue( 'inovio-exp-year-blocks' ),
					cvv = fieldValue( 'inovio-cvv-blocks' ),
					validationError = validate( { pan: pan, month: month, year: year, cvv: cvv } );

				if ( validationError ) {
					setError( validationError );

					return {
						type: emitResponse.responseTypes.ERROR,
						message: validationError
					};
				}

				setError( '' );

				return tokenizeFlow( { pan: pan, cvv: cvv } ).then( function ( result ) {
					// Clear the PAN/CVV out of the DOM immediately — belt and
					// braces alongside the fact that these inputs are never
					// serialized into any WordPress request in the first place.
					setFieldValue( 'inovio-card-number-blocks', '' );
					setFieldValue( 'inovio-cvv-blocks', '' );

					return {
						type: emitResponse.responseTypes.SUCCESS,
						meta: {
							paymentMethodData: {
								inovio_token_guid: result.tokenGuid || '',
								inovio_token_guid_completion: result.tokenGuidCompletion || '',
								inovio_pmt_expiry: month + year,
								inovio_cc_brand: cardBrand( pan ),
								inovio_cc_last4: pan.slice( -4 ),
								inovio_ddc_reference_id: result.ddcReferenceId || '',
								inovio_browser: result.browserData ? JSON.stringify( result.browserData ) : ''
							}
						}
					};
				} ).catch( function ( message ) {
					var text = String( message );

					setError( text );

					return {
						type: emitResponse.responseTypes.ERROR,
						message: text
					};
				} );
			} );

			return unsubscribe;
			// eslint-disable-next-line react-hooks/exhaustive-deps -- onPaymentSetup identity is stable per Blocks' own contract; re-subscribing per render would leak listeners.
		}, [ onPaymentSetup, emitResponse ] );

		return el(
			'div',
			{ id: 'inovio-payment-fields', className: 'inovio-payment-fields' },
			config.description
				? el( 'p', null, decodeEntities( config.description ) )
				: null,
			error
				? el( 'div', { className: 'inovio-errors', role: 'alert' }, error )
				: null,
			el(
				'div',
				{ className: 'inovio-new-card' },
				el(
					'p',
					{ className: 'form-row form-row-wide' },
					el( 'label', { htmlFor: 'inovio-card-number-blocks' }, translate( 'cardNumber', 'Card number' ), ' ', el( 'span', { className: 'required' }, '*' ) ),
					el( 'input', {
						id: 'inovio-card-number-blocks',
						className: 'input-text',
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'cc-number',
						maxLength: 24,
						placeholder: '•••• •••• •••• ••••'
					} )
				),
				el(
					'p',
					{ className: 'form-row form-row-first' },
					el( 'label', { htmlFor: 'inovio-exp-month-blocks' }, translate( 'expiry', 'Expiry' ), ' ', el( 'span', { className: 'required' }, '*' ) ),
					el(
						'select',
						{ id: 'inovio-exp-month-blocks', className: 'inovio-exp', defaultValue: '' },
						el( 'option', { value: '' }, __( 'MM', 'inovio-payment-gateway' ) ),
						monthOptions()
					),
					' ',
					el(
						'select',
						{ id: 'inovio-exp-year-blocks', className: 'inovio-exp', defaultValue: '' },
						el( 'option', { value: '' }, __( 'YYYY', 'inovio-payment-gateway' ) ),
						expiryYears().map( function ( y ) {
							return el( 'option', { key: y, value: String( y ) }, String( y ) );
						} )
					)
				),
				el(
					'p',
					{ className: 'form-row form-row-last' },
					el( 'label', { htmlFor: 'inovio-cvv-blocks' }, translate( 'securityCode', 'Security code' ), ' ', el( 'span', { className: 'required' }, '*' ) ),
					el( 'input', {
						id: 'inovio-cvv-blocks',
						className: 'input-text',
						type: 'text',
						inputMode: 'numeric',
						autoComplete: 'cc-csc',
						maxLength: 4,
						placeholder: '•••'
					} )
				),
				el( 'div', { className: 'clear' } )
			)
		);
	}

	/**
	 * @param {number} n Two-digit month.
	 * @returns {*}
	 */
	function monthOption( n ) {
		var value = ( n < 10 ? '0' : '' ) + n;

		return el( 'option', { key: value, value: value }, value );
	}

	function monthOptions() {
		var options = [], m;

		for ( m = 1; m <= 12; m++ ) {
			options.push( monthOption( m ) );
		}

		return options;
	}

	/**
	 * @param {string} id Element id.
	 * @returns {string}
	 */
	function fieldValue( id ) {
		var el = document.getElementById( id );

		return el ? el.value : '';
	}

	/**
	 * @param {string} id Element id.
	 * @param {string} value
	 */
	function setFieldValue( id, value ) {
		var el = document.getElementById( id );

		if ( el ) {
			el.value = value;
		}
	}

	var config = cfg();

	registerPaymentMethod( {
		name: GATEWAY_ID,
		label: decodeEntities( config.title || __( 'Credit card', 'inovio-payment-gateway' ) ),
		ariaLabel: decodeEntities( config.title || __( 'Credit card', 'inovio-payment-gateway' ) ),
		content: el( InovioContent ),
		edit: el( InovioContent ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: config.supports || [ 'products' ]
		}
	} );
}());
