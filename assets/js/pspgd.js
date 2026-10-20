( function () {
	'use strict';

	// Mirrors PSPGD_Form::MIN_FILL_SECONDS and FORM_TTL.
	var MIN_FILL_SECONDS = 3;
	var FORM_TTL_SECONDS = 2 * 60 * 60;

	// Called by Cloudflare's script once it has loaded (the onload= in
	// PSPGD_Form::TURNSTILE_SCRIPT), for a donor who reached the review step
	// before it did.
	var turnstileWaiting = [];
	window.pspgdTurnstileReady = function () {
		turnstileWaiting.forEach( function ( render ) {
			render();
		} );
	};

	function initForm( form ) {
		var presets = {};
		var minimums = {};
		var maximums = {};
		try {
			presets = JSON.parse( form.dataset.amountPresets || '{}' );
			minimums = JSON.parse( form.dataset.minAmounts || '{}' );
			maximums = JSON.parse( form.dataset.maxAmounts || '{}' );
		} catch ( error ) {
			return;
		}

		var labels = window.pspgdDonation || {};
		var currentStep = 1;
		var panels = form.querySelectorAll( '[data-step-panel]' );
		var amountInput = form.querySelector( 'input[name="amount"]' );
		var customAmount = form.querySelector( '[data-role="custom-amount"]' );
		var amountButtons = form.querySelector( '[data-role="amount-buttons"]' );
		var errorRegion = form.querySelector( '[data-role="error"]' );
		var turnstileBox = form.querySelector( '[data-role="turnstile"]' );
		var turnstileWidget = null;

		function selectedValue( name ) {
			var input = form.querySelector( 'input[name="' + name + '"]:checked' );
			if ( input ) {
				return input.value;
			}
			input = form.querySelector( 'input[name="' + name + '"]' );
			return input ? input.value : '';
		}

		// Mirrors PSPGD_I18n::display_decimals(): the list of currencies shown
		// without a minor unit comes from the server.
		var zeroDecimal = Array.isArray( labels.zeroDecimal ) ? labels.zeroDecimal : [];

		function formatAmount( amount, currency ) {
			return new Intl.NumberFormat( undefined, {
				maximumFractionDigits: zeroDecimal.indexOf( currency ) !== -1 ? 0 : 2
			} ).format( Number( amount ) ) + ' ' + currency;
		}

		function showError( message ) {
			if ( ! errorRegion ) {
				return;
			}
			errorRegion.textContent = message;
			errorRegion.hidden = ! message;
		}

		function clearError() {
			showError( '' );
		}

		function renderAmounts() {
			var currency = selectedValue( 'currency' );
			var amounts = presets[ currency ] || [];
			// A typed amount belongs to the donor, not to the currency, so
			// switching currency must not silently replace it.
			var typed = customAmount.value !== '';

			amountButtons.innerHTML = '';
			amounts.forEach( function ( amount, index ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.textContent = formatAmount( amount, currency );
				button.dataset.amount = amount;
				button.className = 'pspgd-donation-form__amount-button';
				if ( index === 1 && ! typed ) {
					button.classList.add( 'is-selected' );
					amountInput.value = amount;
				}
				button.addEventListener( 'click', function () {
					amountInput.value = amount;
					customAmount.value = '';
					clearError();
					amountButtons.querySelectorAll( 'button' ).forEach( function ( item ) {
						item.classList.toggle( 'is-selected', item === button );
					} );
				} );
				amountButtons.appendChild( button );
			} );

			if ( typed ) {
				amountInput.value = customAmount.value;
			}

			applyAmountLimits( currency );
		}

		function applyAmountLimits( currency ) {
			var minimum = Number( minimums[ currency ] || 0 );
			var maximum = Number( maximums[ currency ] || 0 );

			if ( minimum > 0 ) {
				customAmount.min = minimum;
			}
			if ( maximum > 0 ) {
				customAmount.max = maximum;
			}
		}

		function amountProblem() {
			var currency = selectedValue( 'currency' );
			var amount = Number( amountInput.value );
			var minimum = Number( minimums[ currency ] || 0 );
			var maximum = Number( maximums[ currency ] || 0 );

			if ( ! amount || amount <= 0 ) {
				return labels.errorAmount || 'Choose or enter a donation amount.';
			}
			if ( minimum > 0 && amount < minimum ) {
				return ( labels.errorMinimum || 'The smallest donation we can accept is %s.' )
					.replace( '%s', formatAmount( minimum, currency ) );
			}
			if ( maximum > 0 && amount > maximum ) {
				return ( labels.errorMaximum || 'The largest donation we can accept online is %s.' )
					.replace( '%s', formatAmount( maximum, currency ) );
			}
			return '';
		}

		function scrollToFormTop() {
			var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			var offset = 24;
			var adminBar = document.getElementById( 'wpadminbar' );
			if ( adminBar ) {
				offset += adminBar.offsetHeight;
			}

			window.requestAnimationFrame( function () {
				window.scrollTo( {
					top: Math.max( form.getBoundingClientRect().top + window.pageYOffset - offset, 0 ),
					behavior: reduceMotion ? 'auto' : 'smooth'
				} );
			} );
		}

		// Rendered when the review step first appears rather than with the
		// page: a check solved on arrival expires after five minutes, while the
		// donor may still be choosing an amount, and Cloudflare advises against
		// rendering into a hidden element.
		function renderTurnstile() {
			if ( ! turnstileBox || null !== turnstileWidget || currentStep !== 3 || ! window.turnstile ) {
				return;
			}
			turnstileWidget = window.turnstile.render( turnstileBox, {
				sitekey: turnstileBox.dataset.sitekey,
				theme: turnstileBox.dataset.theme || 'auto',
				'response-field-name': 'cf-turnstile-response',
				callback: clearError
			} );
		}

		function turnstileSolved() {
			if ( ! turnstileBox ) {
				return true;
			}
			return null !== turnstileWidget && !! window.turnstile && '' !== String( window.turnstile.getResponse( turnstileWidget ) || '' );
		}

		function updateStep( step ) {
			currentStep = step;
			panels.forEach( function ( panel ) {
				panel.hidden = Number( panel.dataset.stepPanel ) !== currentStep;
			} );
			form.querySelectorAll( '[data-step-indicator]' ).forEach( function ( indicator ) {
				indicator.classList.toggle( 'is-active', Number( indicator.dataset.stepIndicator ) === currentStep );
				indicator.classList.toggle( 'is-complete', Number( indicator.dataset.stepIndicator ) < currentStep );
			} );
			if ( currentStep === 3 ) {
				form.querySelector( '[data-review="amount"]' ).textContent = formatAmount( amountInput.value, selectedValue( 'currency' ) );

				var type = selectedValue( 'donation_type' );
				var reviewEyebrow = form.querySelector( '[data-role="review-eyebrow"]' );
				var supportLabel = form.dataset.campaignSupportLabel || '';
				// Written into the review step's own eyebrow. It used to
				// overwrite the one in step 1, which then stayed changed when
				// the donor went back.
				if ( reviewEyebrow ) {
					reviewEyebrow.textContent = supportLabel;
					reviewEyebrow.hidden = ! supportLabel;
				}

				form.querySelector( '[data-review="type"]' ).textContent = form.dataset.recurringEnabled === '1' ? ( type === 'monthly' ? ( labels.monthlyLabel || 'Monthly' ) : ( type === 'weekly' ? ( labels.weeklyLabel || 'Weekly' ) : ( labels.oneTimeLabel || 'One-time' ) ) ) : '';

				renderTurnstile();
			}
		}

		function validateCurrentStep() {
			if ( currentStep === 1 ) {
				var problem = amountProblem();
				if ( problem ) {
					showError( problem );
					customAmount.setCustomValidity( problem );
					customAmount.reportValidity();
					customAmount.setCustomValidity( '' );
					return false;
				}
			}

			var required = form.querySelectorAll( '[data-step-panel="' + currentStep + '"] [required]' );
			for ( var index = 0; index < required.length; index++ ) {
				if ( ! required[ index ].checkValidity() ) {
					required[ index ].reportValidity();
					return false;
				}
			}

			clearError();
			return true;
		}

		function restorePreviousAttempt() {
			var currency = form.dataset.restoreCurrency || '';
			var type = form.dataset.restoreType || '';
			var amount = form.dataset.restoreAmount || '';

			if ( currency ) {
				var currencyInput = form.querySelector( 'input[name="currency"][value="' + currency + '"]' );
				if ( currencyInput ) {
					currencyInput.checked = true;
				}
			}
			if ( type ) {
				var typeInput = form.querySelector( 'input[name="donation_type"][value="' + type + '"]' );
				if ( typeInput ) {
					typeInput.checked = true;
				}
			}
			if ( amount && Number( amount ) > 0 ) {
				customAmount.value = amount;
				amountInput.value = amount;
			}
		}

		form.querySelectorAll( 'input[name="currency"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', renderAmounts );
		} );
		customAmount.addEventListener( 'input', function () {
			amountInput.value = customAmount.value;
			clearError();
			amountButtons.querySelectorAll( 'button' ).forEach( function ( button ) {
				button.classList.remove( 'is-selected' );
			} );
		} );
		form.querySelectorAll( '[data-action="next"]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( validateCurrentStep() ) {
					updateStep( currentStep + 1 );
					scrollToFormTop();
				}
			} );
		} );
		form.querySelectorAll( '[data-action="previous"]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				clearError();
				updateStep( currentStep - 1 );
				scrollToFormTop();
			} );
		} );

		form.addEventListener( 'submit', function ( event ) {
			// Enter in an earlier step submits the form natively. Treat it as
			// "next", so nobody skips the review step.
			if ( currentStep !== 3 ) {
				event.preventDefault();
				if ( validateCurrentStep() ) {
					updateStep( currentStep + 1 );
					scrollToFormTop();
				}
				return;
			}

			// The server refuses a donation without a solved check, so say so
			// here instead of sending the donor to a refusal and back.
			if ( ! turnstileSolved() ) {
				event.preventDefault();
				showError( labels.errorTurnstile || 'Please complete the security check before donating.' );
			}
		} );

		if ( turnstileBox ) {
			turnstileWaiting.push( renderTurnstile );

			// A token is good for one submission. A donor who comes back with
			// the back button has already spent it.
			window.addEventListener( 'pageshow', function ( event ) {
				if ( event.persisted && null !== turnstileWidget && window.turnstile ) {
					window.turnstile.reset( turnstileWidget );
				}
			} );
		}

		restorePreviousAttempt();
		renderAmounts();
		initTokens( form, labels, showError );
	}

	/**
	 * Collect the nonce and the signed render timestamp from an endpoint no
	 * cache is allowed to store.
	 *
	 * They used to be printed into the form. Printed, they became part of
	 * whatever copy of the page a cache was holding, so every donor submitted
	 * the same pair -- and once that copy was a day old the nonce no longer
	 * verified and the first attempt was always refused. Fetched here, they
	 * are issued to this donor, at the moment this page appeared on screen.
	 */
	function initTokens( form, labels, showError ) {
		var nonceField = form.querySelector( 'input[name="pspgd_donation_nonce"]' );
		var timeField = form.querySelector( 'input[name="pspgd_form_time"]' );
		var submit = form.querySelector( 'button[type="submit"]' );
		if ( ! nonceField || ! timeField ) {
			return;
		}

		function setBusy( busy ) {
			if ( submit ) {
				submit.disabled = busy;
			}
		}

		// The token's own issue time, read off the front of it. The signature
		// is the server's business; this is only used to decide whether the
		// timestamp already on the form is worth keeping.
		function issuedSecondsAgo( token ) {
			var issued = Number( String( token || '' ).split( '.' )[ 0 ] );
			if ( ! issued ) {
				return null;
			}
			return Math.floor( Date.now() / 1000 ) - issued;
		}

		function apply( data ) {
			if ( ! data || ! data.nonce || ! data.form_time ) {
				return false;
			}

			// How long the donor spent on the form is the one thing a new
			// timestamp would destroy: replacing it on a back-button restore
			// would make a donor who returns and submits look like a script
			// that filled everything in under three seconds. So the timestamp
			// already on the form is kept while it is still usable, and only
			// the nonce is refreshed.
			var age = issuedSecondsAgo( timeField.value );
			var keepExisting = null !== age && age >= MIN_FILL_SECONDS && age < FORM_TTL_SECONDS;

			nonceField.value = data.nonce;
			if ( ! keepExisting ) {
				timeField.value = data.form_time;
			}
			return true;
		}

		function request( url ) {
			return fetch( url, { credentials: 'same-origin', cache: 'no-store' } ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'token request failed' );
				}
				return response.json();
			} );
		}

		function load() {
			setBusy( true );

			var bust = ( labels.tokenEndpoint || '' ).indexOf( '?' ) === -1 ? '?' : '&';

			return request( labels.tokenEndpoint + bust + '_=' + Date.now() )
				.catch( function () {
					// Some sites close the REST API to anonymous visitors.
					// admin-ajax is never cached and always reachable.
					return request( labels.ajaxEndpoint + '?action=pspgd_donation_token&_=' + Date.now() );
				} )
				.then( function ( data ) {
					if ( ! apply( data ) ) {
						throw new Error( 'token response incomplete' );
					}
					setBusy( false );
				} )
				.catch( function () {
					// Leaving the button disabled would strand a donor with no
					// way forward, so say what happened and let them try.
					setBusy( false );
					showError( labels.errorToken || 'We could not prepare the donation form. Please reload the page and try again.' );
				} );
		}

		load();

		// A donor who reaches the form with the back button gets the page as
		// the browser froze it, tokens and all. Those are as old as that visit,
		// so they are replaced rather than submitted.
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				load();
			}
		} );
	}

	/**
	 * The campaign progress figures, restated in another currency.
	 *
	 * Every currency's wording is rendered server-side into one data
	 * attribute, so switching is a text swap and never a request. The bar
	 * itself does not move: the total and the goal are converted at the same
	 * rate, so the percentage is the same whichever currency is on screen.
	 */
	function initCampaignCurrency( progress ) {
		var figures = {};
		try {
			figures = JSON.parse( progress.dataset.pspgdCampaignCurrencies || '{}' );
		} catch ( error ) {
			return;
		}

		var group = progress.querySelector( '.pspgd-campaign__currency' );
		var raised = progress.querySelector( '[data-pspgd-campaign-raised]' );
		var goal = progress.querySelector( '[data-pspgd-campaign-goal]' );
		if ( ! group || ! raised || ! goal ) {
			return;
		}

		var options = group.querySelectorAll( '[data-pspgd-currency]' );
		if ( options.length < 2 ) {
			return;
		}

		function show( currency ) {
			var figure = figures[ currency ];
			if ( ! figure ) {
				return;
			}

			raised.textContent = figure.raised;
			goal.textContent = figure.goal;
			options.forEach( function ( option ) {
				option.setAttribute( 'aria-pressed', option.dataset.pspgdCurrency === currency ? 'true' : 'false' );
			} );
		}

		options.forEach( function ( option ) {
			option.addEventListener( 'click', function () {
				show( option.dataset.pspgdCurrency );
			} );
		} );

		// Only reveal the switch once it works, so a visitor without
		// JavaScript is never offered buttons that do nothing.
		group.classList.add( 'is-ready' );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.pspgd-donation-form' ).forEach( initForm );
		document.querySelectorAll( '[data-pspgd-campaign-currencies]' ).forEach( initCampaignCurrency );
	} );
}() );
