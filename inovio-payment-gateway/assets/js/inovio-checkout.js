/**
 * Inovio direct-post checkout (WooCommerce, classic checkout).
 *
 * The card number lives only in this page and in the browser-direct POST to
 * Inovio's token service — it is never sent to the WordPress server. The order
 * is placed carrying the single-use TOKEN_GUID(s) instead.
 *
 * The card fields deliberately have NO `name` attribute, so WooCommerce's own
 * form serialization cannot pick them up even if this script fails to run.
 * That is a structural guarantee, not a convention: an unnamed input is not
 * part of the submitted form data.
 *
 * WooCommerce-specific wiring note. Unlike PrestaShop (no order-submission JS
 * event at all), WooCommerce publishes `checkout_place_order` — a jQuery event
 * on the checkout form whose handler can return false to cancel submission.
 * That is the correct interception point: return false, run the async
 * tokenize/3DS chain, write the results into the hidden inputs, then trigger
 * the form's submit again with a guard flag set.
 */
(function ($) {
    'use strict';

    /**
     * Read the PHP-injected config defensively — a theme may load this script
     * before wp_localize_script has run, or omit fields.
     * @returns {Object}
     */
    function cfg() {
        return window.inovioConfig || {};
    }

    /**
     * @param {string} key Translation key.
     * @param {string} fallback Text used when the key is absent.
     * @returns {string}
     */
    function translate(key, fallback) {
        var t = cfg().translations || {};

        return t[key] || fallback;
    }

    // ------------------------------------------------------------------
    // Card helpers
    // ------------------------------------------------------------------

    /**
     * Strip whitespace/dashes from a raw card number input.
     * @param {string} raw
     * @returns {string}
     */
    function normalizePan(raw) {
        return String(raw || '').replace(/[\s-]/g, '');
    }

    /**
     * Detect the card brand from the PAN prefix.
     * @param {string} pan
     * @returns {string} One of VI/MC/AE/DI/JCB/DN, or '' if unrecognized.
     */
    function cardBrand(pan) {
        if (/^4/.test(pan)) { return 'VI'; }
        if (/^(5[1-5]|2[2-7])/.test(pan)) { return 'MC'; }
        if (/^3[47]/.test(pan)) { return 'AE'; }
        if (/^(6011|64[4-9]|65)/.test(pan)) { return 'DI'; }
        if (/^35/.test(pan)) { return 'JCB'; }
        if (/^3(0[0-5]|[68])/.test(pan)) { return 'DN'; }

        return '';
    }

    /**
     * Standard Luhn checksum.
     * @param {string} pan
     * @returns {boolean}
     */
    function luhnValid(pan) {
        var sum = 0, dbl = false, i, d;

        if (!/^[0-9]+$/.test(pan)) {
            return false;
        }

        for (i = pan.length - 1; i >= 0; i--) {
            d = parseInt(pan.charAt(i), 10);

            if (dbl) {
                d *= 2;

                if (d > 9) { d -= 9; }
            }
            sum += d;
            dbl = !dbl;
        }

        return pan.length >= 12 && sum % 10 === 0;
    }

    /**
     * Validate PAN, expiry and CVV before touching the network — a bad card
     * should cost the shopper a message, not a round trip.
     * @param {{pan: string, month: string, year: string, cvv: string}} fields
     * @returns {string|null} An error message, or null if valid.
     */
    function validate(fields) {
        var now = new Date(),
            month = parseInt(fields.month, 10),
            year = parseInt(fields.year, 10);

        if (!luhnValid(fields.pan)) {
            return translate('invalidCard', 'Please enter a valid card number.');
        }

        if (!fields.month || !fields.year || isNaN(month) || isNaN(year) ||
            year < now.getFullYear() ||
            (year === now.getFullYear() && month < now.getMonth() + 1)
        ) {
            return translate('invalidExpiry', 'Please enter a valid expiration date.');
        }

        if (!/^[0-9]{3,4}$/.test(String(fields.cvv))) {
            return translate('invalidCvv', 'Please enter a valid security code.');
        }

        return null;
    }

    /**
     * Cryptographically random lowercase hex string.
     * @param {number} length Number of hex characters (even).
     * @returns {string}
     */
    function randomHex(length) {
        var bytes = new Uint8Array(length / 2);

        window.crypto.getRandomValues(bytes);

        return Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }

    /**
     * Snapshot the browser fields the 3DS DDC/challenge legs require.
     *
     * The gateway silently disables 3DS when language, userAgent or header is
     * missing, so all three are always sent.
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
            timeZoneOffset: new Date().getTimezoneOffset()
        };
    }

    // ------------------------------------------------------------------
    // Error UI
    // ------------------------------------------------------------------

    /**
     * Show a message in the gateway's own error box.
     *
     * Never pass raw gateway response bodies here — only translated or
     * hand-written user-facing strings.
     * @param {string} message
     */
    function showError(message) {
        var box = document.getElementById('inovio-errors');

        if (!box) {
            window.alert(message);

            return;
        }
        box.textContent = message;
        box.style.display = 'block';
        box.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    function clearError() {
        var box = document.getElementById('inovio-errors');

        if (box) {
            box.style.display = 'none';
            box.textContent = '';
        }
    }

    // ------------------------------------------------------------------
    // Tokenization / 3DS
    // ------------------------------------------------------------------

    /**
     * Ask our own signing endpoint for a timestamp/signature/site id, then POST
     * the PAN straight to the Inovio token service. The PAN goes browser-direct
     * and never touches WordPress. Resolves with a single-use TOKEN_GUID.
     *
     * @param {string} pan
     * @param {string} cvv
     * @returns {Promise<string>}
     */
    function mintToken(pan, cvv) {
        var uid = randomHex(32),
            body = new URLSearchParams();

        body.append('action', cfg().signatureAction);
        body.append('nonce', cfg().signatureNonce);
        body.append('uniqueId', uid);

        return fetch(cfg().ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (resp) {
            if (!resp.ok) {
                throw new Error('signature');
            }

            return resp.json();
        }).catch(function () {
            // Any failure of our own endpoint is a signing failure — the
            // shopper is told to retry, and the server has already logged
            // which of the three gates refused.
            throw translate('signFailed', 'Payment signing failed. Please refresh and try again.');
        }).then(function (sig) {
            var tokenBody = new URLSearchParams();

            // The ONLY place the card number appears in any network call, and
            // it goes straight to the gateway — not to WordPress.
            tokenBody.append('card_pan', pan);
            tokenBody.append('card_cvv', String(cvv));
            tokenBody.append('request_response_format', 'json');
            tokenBody.append('request_api_version', '4.14');
            tokenBody.append('site_id', sig.siteId);
            tokenBody.append('unique_id', uid);

            return fetch(sig.tokenUrl || cfg().tokenUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    // hex(hmac_sha256(timestamp + uniqueId + siteId, siteKey)),
                    // computed server-side; the site key never reaches the browser.
                    'X-timestamp': sig.timestamp,
                    'X-signature': sig.signature
                },
                body: tokenBody.toString()
            }).then(function (resp) {
                return resp.json();
            }).then(function (token) {
                if (!token.TOKEN_GUID) {
                    throw token.ERROR_MESSAGE ||
                        translate('tokenizeFailed', 'Card could not be processed. Please try again.');
                }

                return token.TOKEN_GUID;
            }).catch(function (err) {
                // Re-throw string messages (from the TOKEN_GUID check above)
                // as-is; wrap network/parse failures in a generic message so a
                // raw gateway/network error is never surfaced to the shopper.
                if (typeof err === 'string') {
                    throw err;
                }

                throw translate('unreachable', 'Could not reach the payment service. Please try again.');
            });
        });
    }

    /**
     * Full tokenize + (optional) 3DS-prepare chain.
     *
     * Gateway tokens are SINGLE-USE: the 3DS enrollment leg consumes the token
     * that triggers the challenge, so a second token is minted here for the
     * completion leg. Both resolve to the same card at the gateway, keeping the
     * 3DS authentication valid. (Verified against the gateway's "API 401
     * Invalid TOKEN_GUID" rejection on token reuse — do not collapse this back
     * into a single mintToken() call.)
     *
     * @param {{pan: string, cvv: string}} card
     * @returns {Promise<Object>} {tokenGuid, tokenGuidCompletion, ddcReferenceId, browserData}
     */
    function tokenizeFlow(card) {
        var needsSecond = !!cfg().threeDsActive,
            result = {
                tokenGuid: null,
                tokenGuidCompletion: null,
                ddcReferenceId: null,
                browserData: collectBrowserData()
            };

        return mintToken(card.pan, card.cvv).then(function (guid) {
            result.tokenGuid = guid;

            if (!needsSecond) {
                return result;
            }

            // Token B, for the post-challenge completion leg.
            return mintToken(card.pan, card.cvv).then(function (guid2) {
                result.tokenGuidCompletion = guid2;

                return prepareThreeDs(card.pan.slice(0, 6)).then(function (ddcReferenceId) {
                    result.ddcReferenceId = ddcReferenceId;

                    return result;
                });
            });
        });
    }

    /**
     * Start a 3DS session for this BIN and run the hidden device-data-
     * collection iframe.
     *
     * This REJECTS on failure rather than resolving with null. With 3DS
     * switched on, silently proceeding without authentication would change the
     * transaction's liability position without anyone noticing — exactly the
     * swallowed-failure mode this integration must not have.
     *
     * @param {string} bin First 6 digits of the PAN — not cardholder data.
     * @returns {Promise<string>} ddcReferenceId
     */
    function prepareThreeDs(bin) {
        var body = new URLSearchParams();

        body.append('action', cfg().prepareAction);
        body.append('nonce', cfg().prepareNonce);
        body.append('bin', bin);

        return fetch(cfg().ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (resp) {
            if (!resp.ok) {
                throw new Error('prepare');
            }

            return resp.json();
        }).then(function (ddc) {
            if (!ddc || !ddc.jwt || !ddc.ddcUrl || !ddc.ddcReferenceId) {
                throw new Error('prepare');
            }

            return runHiddenDdc(ddc).then(function () {
                return ddc.ddcReferenceId;
            });
        }).catch(function () {
            throw translate('threeDsFailed', 'Card authentication could not be started. Please try again.');
        });
    }

    /**
     * POST the JWT to the DDC url in a hidden iframe and wait for the
     * device-fingerprinting page to postMessage completion, OR an 8-second
     * timeout — whichever comes first. Checkout must never hang waiting on a
     * DDC iframe that never responds.
     *
     * A DDC timeout is not a failure: the device data is best-effort input to
     * the risk decision, and the enrollment leg proceeds with the reference id
     * either way.
     *
     * @param {{jwt: string, ddcUrl: string}} ddc
     * @returns {Promise<void>}
     */
    function runHiddenDdc(ddc) {
        return new Promise(function (resolve) {
            var iframe = document.createElement('iframe'),
                form = document.createElement('form'),
                input = document.createElement('input'),
                finished = false,
                finish = function () {
                    if (!finished) {
                        finished = true;
                        window.removeEventListener('message', onMessage);
                        iframe.remove();
                        form.remove();
                        resolve();
                    }
                },
                onMessage = function (e) {
                    var data;

                    try {
                        data = typeof e.data === 'string' ? JSON.parse(e.data) : e.data;
                    } catch (err) {
                        return;
                    }

                    if (data && data.MessageType === 'profile.completed') {
                        finish();
                    }
                };

            iframe.name = 'inovio-ddc';
            iframe.style.display = 'none';
            form.method = 'POST';
            form.action = ddc.ddcUrl;
            form.target = 'inovio-ddc';
            input.type = 'hidden';
            input.name = 'JWT';
            input.value = ddc.jwt;
            form.appendChild(input);
            document.body.appendChild(iframe);
            document.body.appendChild(form);
            window.addEventListener('message', onMessage);
            form.submit();
            setTimeout(finish, 8000);
        });
    }

    /**
     * Visible ACS challenge iframe. POSTs the challenge JWT to redirectUrl in
     * an overlay iframe; the server-rendered return page postMessages the
     * outcome back to this window as {inovio3ds: 'complete', success, message}.
     *
     * @param {{redirectUrl: string, jwt: string}} challenge
     * @param {string} redirect Where to go once authentication succeeds.
     */
    function runChallenge(challenge, redirect) {
        var overlay = document.createElement('div'),
            iframe = document.createElement('iframe'),
            form = document.createElement('form'),
            input = document.createElement('input'),
            onMessage = function (e) {
                // Only trust postMessages from our own origin — the return page
                // is same-origin with us even though the ACS challenge itself
                // is cross-origin inside the iframe.
                if (e.origin !== window.location.origin || !e.data || e.data.inovio3ds !== 'complete') {
                    return;
                }
                window.removeEventListener('message', onMessage);
                overlay.remove();
                form.remove();

                if (e.data.success) {
                    window.location.href = redirect;
                } else {
                    // The tokens minted for this attempt were single-use and
                    // are now consumed by the enrollment/completion legs — a
                    // retry must mint fresh ones, not replay these. Reset the
                    // guard flag so the next checkout_place_order tokenizes
                    // again instead of short-circuiting past it, and clear
                    // every hidden field tokenization wrote so a retry can
                    // never resubmit stale, already-consumed values.
                    tokenized = false;
                    setHidden('inovio-token-guid', '');
                    setHidden('inovio-token-guid-completion', '');
                    setHidden('inovio-pmt-expiry', '');
                    setHidden('inovio-cc-brand', '');
                    setHidden('inovio-cc-last4', '');
                    setHidden('inovio-ddc-reference-id', '');
                    setHidden('inovio-browser', '');

                    unblock();
                    showError(e.data.message || translate('authFailed', 'Payment authentication failed.'));
                }
            };

        overlay.className = 'inovio-3ds-overlay';
        iframe.name = 'inovio-3ds-challenge';
        iframe.className = 'inovio-3ds-frame';
        overlay.appendChild(iframe);
        form.method = 'POST';
        form.action = challenge.redirectUrl;
        form.target = 'inovio-3ds-challenge';
        input.type = 'hidden';
        input.name = 'JWT';
        input.value = challenge.jwt;
        form.appendChild(input);
        document.body.appendChild(overlay);
        document.body.appendChild(form);
        window.addEventListener('message', onMessage);
        form.submit();
    }

    // ------------------------------------------------------------------
    // Checkout wiring
    // ------------------------------------------------------------------

    /**
     * @returns {jQuery} The checkout form, or an empty jQuery set when it is
     *      not on the page (e.g. the order-pay page, which is unsupported —
     *      see Inovio_Payment_Gateway::is_available()). No fallback to
     *      `form#order_review`: WooCommerce core never triggers
     *      `checkout_place_order` on that form, so tokenizing against it can
     *      never actually run — every `.length` guard on this return value
     *      elsewhere in this file already treats an empty set safely.
     */
    function checkoutForm() {
        return $('form.checkout');
    }

    /**
     * Whether our payment method is the selected one.
     * @returns {boolean}
     */
    function isSelected() {
        return $('input[name="payment_method"]:checked').val() === 'inovio';
    }

    /**
     * Whether the shopper picked a saved card rather than entering a new one.
     * A saved card needs no tokenization: the gateway's own references are the
     * credential, so the PAN never enters the picture at all.
     * @returns {boolean}
     */
    function usingSavedCard() {
        var $selected = $('input[name="wc-inovio-payment-token"]:checked');

        return $selected.length > 0 && $selected.val() !== 'new';
    }

    /**
     * Show the new-card fields only when a new card is actually being used.
     *
     * Clears the PAN/CVV on the way out so a stale value can never linger in
     * the DOM behind a hidden panel.
     */
    function syncSavedCardFields() {
        var $fields = $('#inovio-new-card-fields'),
            saved = usingSavedCard();

        if (!$fields.length) {
            return;
        }

        $fields.toggle(!saved);

        if (saved) {
            $('#inovio-card-number, #inovio-cvv').val('');
        }
    }

    /**
     * @param {string} id Element id.
     * @returns {string} The element's value, or ''.
     */
    function val(id) {
        var el = document.getElementById(id);

        return el ? el.value : '';
    }

    /**
     * Write a value into one of the named hidden inputs.
     * @param {string} id Element id.
     * @param {string} value
     */
    function setHidden(id, value) {
        var el = document.getElementById(id);

        if (el) {
            el.value = (value === null || value === undefined) ? '' : value;
        }
    }

    function block() {
        var $form = checkoutForm();

        if ($form.length && $.blockUI) {
            $form.addClass('processing').block({
                message: null,
                overlayCSS: {background: '#fff', opacity: 0.6}
            });
        }
    }

    function unblock() {
        var $form = checkoutForm();

        if ($form.length) {
            $form.removeClass('processing');

            if ($.unblockUI) {
                $form.unblock();
            }
        }
    }

    /**
     * Guard flag: set once tokenization has completed so the re-submitted form
     * passes straight through to WooCommerce instead of re-entering this
     * handler.
     * @type {boolean}
     */
    var tokenized = false;

    /**
     * Intercept order submission, tokenize, then resubmit.
     *
     * `checkout_place_order` is WooCommerce's own pre-submit event: returning
     * false cancels the submission. That makes it the right hook — no need for
     * the native-vs-jQuery submit gymnastics the PrestaShop port required.
     *
     * @returns {boolean} false to cancel this submission, true to let it through.
     */
    function onPlaceOrder() {
        if (!isSelected()) {
            return true;
        }

        // Second pass, after tokenization: let it through untouched.
        if (tokenized) {
            return true;
        }

        // A saved card carries no PAN, so there is nothing to tokenize.
        if (usingSavedCard()) {
            return true;
        }

        clearError();

        var pan = normalizePan(val('inovio-card-number')),
            month = val('inovio-exp-month'),
            year = val('inovio-exp-year'),
            cvv = val('inovio-cvv'),
            validationError = validate({pan: pan, month: month, year: year, cvv: cvv});

        if (validationError) {
            showError(validationError);

            return false;
        }

        block();

        tokenizeFlow({pan: pan, cvv: cvv}).then(function (result) {
            setHidden('inovio-token-guid', result.tokenGuid);
            setHidden('inovio-token-guid-completion', result.tokenGuidCompletion);
            setHidden('inovio-pmt-expiry', month + year);
            setHidden('inovio-cc-brand', cardBrand(pan));
            setHidden('inovio-cc-last4', pan.slice(-4));
            setHidden('inovio-ddc-reference-id', result.ddcReferenceId);
            setHidden('inovio-browser', result.browserData ? JSON.stringify(result.browserData) : '');

            // The PAN and CVV must never reach our server: clear them from the
            // DOM before the form is posted. (They have no name attribute and
            // so would not be serialized anyway — this is belt and braces.)
            setHidden('inovio-card-number', '');
            setHidden('inovio-cvv', '');

            tokenized = true;

            /*
             * Clear the blocking state BEFORE resubmitting.
             *
             * WooCommerce core's own checkout submit handler starts with:
             *
             *     if ( $form.is( '.processing' ) ) { return false; }
             *
             * so a form still carrying `.processing` — which block() added
             * when the shopper first clicked Place Order — makes WC silently
             * no-op. The result is a checkout that spins forever: the token
             * is minted, the hidden fields are filled, and then nothing is
             * ever POSTed and process_payment() never runs.
             *
             * unblock() removes the class and the overlay, so the resubmit
             * below reaches WC's AJAX path as a fresh submission.
             */
            unblock();
            checkoutForm().trigger('submit');
        }).catch(function (message) {
            unblock();
            showError(String(message));
        });

        // Cancel this submission; the .then() above resubmits.
        return false;
    }

    /*
     * A failed order attempt re-renders the checkout fragment and clears the
     * hidden inputs, so the next attempt must mint fresh tokens — the previous
     * ones are consumed and would fail with "Invalid TOKEN_GUID".
     */
    $(document.body).on('checkout_error updated_checkout', function () {
        tokenized = false;
    });

    /*
     * 3DS challenge hand-off.
     *
     * WooCommerce fires `checkout_place_order_success` on the FORM (via
     * triggerHandler, not on document.body) with the decoded JSON response, and
     * — read from woocommerce/assets/js/frontend/checkout.js — it follows
     * `result.redirect` only when that handler does NOT return false. So
     * returning false here suppresses the navigation and lets the ACS challenge
     * iframe open over the checkout page; the challenge's postMessage result
     * then drives the redirect itself.
     *
     * NOTE: BOTH `checkout_place_order` and `checkout_place_order_success` are
     * fired with triggerHandler() on the FORM, and triggerHandler does NOT
     * bubble — so both must be bound directly to the form element. A delegated
     * handler on document.body never fires for either. (Read from
     * woocommerce/assets/js/frontend/checkout.js, lines ~905 and ~975.)
     * The form element itself is not replaced when checkout fragments refresh
     * (only its contents are), so binding once holds.
     */
    function bindFormHandlers() {
        var $form = checkoutForm();

        if (!$form.length || $form.data('inovioBound')) {
            return;
        }

        $form.data('inovioBound', true);

        /*
         * Keep the new-card fields in step with the saved-card choice.
         *
         * WooCommerce auto-selects a stored card when the shopper has one, and
         * a saved-card payment needs no tokenization — so anything typed into
         * the new-card fields is silently ignored and the STORED card is
         * charged. Left visible, that reads as "I typed a different card and
         * it charged the old one", and a deliberately invalid PAN appears to
         * be accepted. Hiding the fields (and clearing them) makes the form
         * show what will actually be charged.
         */
        syncSavedCardFields();
        $(document.body).on(
            'change',
            'input[name="wc-inovio-payment-token"]',
            syncSavedCardFields
        );
        $form.on('updated_checkout payment_method_selected', syncSavedCardFields);

        $form.on('checkout_place_order', onPlaceOrder);

        $form.on('checkout_place_order_success', function (e, result) {
            // inovio_3ds travels as a JSON STRING, not a nested object — see
            // Inovio_Payment_Gateway::begin_challenge() for why: the Block
            // Checkout's Store API response schema types every payment_details
            // value as a plain string, so the server encodes this once for
            // both checkout types rather than carrying two different shapes.
            var challenge = null;

            if (result && typeof result.inovio_3ds === 'string' && result.inovio_3ds) {
                try {
                    challenge = JSON.parse(result.inovio_3ds);
                } catch (err) {
                    challenge = null;
                }
            }

            if (challenge && challenge.redirectUrl) {
                runChallenge(challenge, result.redirect);

                // Suppress WooCommerce's own redirect; the challenge outcome
                // drives navigation instead.
                return false;
            }

            return true;
        });
    }

    $(function () {
        bindFormHandlers();
    });

    // Some themes render the checkout form late.
    $(document.body).on('updated_checkout init_checkout', bindFormHandlers);
}(jQuery));
