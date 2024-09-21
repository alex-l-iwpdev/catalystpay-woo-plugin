/* global jQuery, wcCatalystpayFix */

/**
 * @param wcCatalystpayFix.ajaxurl
 * @param wcCatalystpayFix.actionGetOderId
 * @param wcCatalystpayFix.nonceGetOderId
 */

var ready = false,
	wpwlOptions = {
		style: 'card',
		brandDetection: true,
		brandDetectionType: 'binlist',
		registrations: { requireCvv: true },
		maskCvv: true,
		onError: function( error ) {
			console.log( error );
		},
		onReady: function() {
			var createRegistrationHtml = '<div class="main-box" style="display:flex; gap:5px;"><div class="customLabel">Store payment details?</div><div class="customInput"><input type="checkbox" name="createRegistration" value="true" /></div></div>';
			jQuery( 'form.wpwl-form-card' ).find( '.wpwl-button' ).before( createRegistrationHtml );


			var createButton = '<button class="wpwl-button wpwl-button-pay"  aria-label="fastcheckout">Saved Cards</button>';

			jQuery( 'form.wpwl-form-card' ).find( '.wpwl-button' ).before( createButton );
			ready = true;
		},
		onChangeBrand: function() {
			if ( ! ready ) {
				return;
			}
			document.querySelector( '.wpwl-brand-card' ).style.visibility = 'visible';
			document.querySelector( '.wpwl-brand-card' ).style.display = 'block';
		}
	};
// cfw-primary-btn cfw-next-tab validate cfw-button-loading
document.addEventListener( 'DOMContentLoaded', function() {
	jQuery( function( $ ) {
		const checkoutForm = $( 'form.checkout' ),
			paymentMethod = 'catalystpay_gateway';

		checkoutForm.on( 'checkout_place_order_success', function( event, data ) {
			if ( checkoutForm.find( 'input[name="payment_method"]:checked' ).val() !== paymentMethod ) {
				return true;
			}
			const settings = JSON.parse( data.settings );

			return paymentModal( settings, data.redirect, data.message );
		} );

		$( '#order_review .cfw-secondary-btn' ).click( function( e ) {
			e.preventDefault();

			const button = $( this );

			const paramURL = $( this ).attr( 'href' );

			const parts = paramURL.split( '/' );

			const orderId = parts[ parts.length - 2 ];

			const data = {
				action: wcCatalystpayFix.actionGetOderId,
				nonce: wcCatalystpayFix.nonceGetOderId,
				orderID: orderId
			};

			$.ajax( {
				type: 'POST',
				url: wcCatalystpayFix.ajaxurl,
				data: data,
				beforeSend: function() {
					button.addClass( 'cfw-next-tab validate cfw-button-loading' );
				},
				success: function( res ) {
					if ( res.success ) {
						const settings = JSON.parse( res.data.object.settings );
						button.removeClass( 'cfw-next-tab validate cfw-button-loading' );
						paymentModal( settings, res.data.redirect, res.data.message );
					}
				},
				error: function( xhr, ajaxOptions, thrownError ) {
					console.log( 'error...', xhr );
					//error logging
				}
			} );
		} );
	} );
} );

/**
 * Get payment modal iframe.
 *
 * @param {JSON} settings Payment Settings.
 * @return {boolean}
 */
function paymentModal( settings, redirect, message ) {
	const $modal = jQuery( '#ccModal' );

	try {

		wpwlOptions.locale = settings.locale;
		if ( true === settings.gpay ) {
			wpwlOptions.googlePay = {
				gatewayMerchantId: settings.api_merchant
			};
		}

		if ( true === settings.apple_pay ) {
			wpwlOptions.applePay = {
				version: 3,
				merchantIdentifier: settings.api_merchant,
				total: { amount: settings.amount },
				currencyCode: settings.currency,
				style: 'white-with-line'
			};
		}

		jQuery.ajaxSetup( {
			cache: true
		} );

		jQuery.getScript( settings.script )
			.done( function( script, textStatus ) {
				$modal.find( 'form' ).attr( 'action', redirect );
				$modal.modal( {
					fadeDuration: 250
				} );
			} );
		messages = '<div class="catalystpay-payment-notice"></div>';
	} catch ( e ) {
		return true;
	}

	return false;
}

document.addEventListener( 'DOMContentLoaded', function() {
	var subscriptionCheckbox = document.getElementById( 'woocommerce_catalystpay_gateway_subscription' );
	var preauthorizeCheckbox = document.getElementById( 'woocommerce_catalystpay_gateway_preauthorize' );

	// Check if elements are found in the DOM
	if ( subscriptionCheckbox && preauthorizeCheckbox ) {

		// Function to toggle checkboxes based on subscription checkbox
		function toggleCheckboxes() {
			if ( subscriptionCheckbox.checked ) {
				preauthorizeCheckbox.disabled = true;
				preauthorizeCheckbox.checked = false;
			} else {
				preauthorizeCheckbox.disabled = false;
			}
		}

		// Initial check
		toggleCheckboxes();

		// Add event listener for checkbox change
		subscriptionCheckbox.addEventListener( 'change', function() {
			toggleCheckboxes();
		} );
	} else {
		console.error( 'One or more checkboxes not found in the DOM' );
	}
} );

document.addEventListener( 'DOMContentLoaded', function() {
	var gpayCheckbox = document.getElementById( 'woocommerce_catalystpay_gateway_gpay' );
	var gpayMerchantIdField = document.getElementById( 'woocommerce_catalystpay_gateway_gpay_merchant_id' );
	if ( gpayMerchantIdField ) {
		var tr = gpayMerchantIdField.closest( 'tr' );
	}

	// Check if elements are found in the DOM
	if ( gpayCheckbox && gpayMerchantIdField ) {

		// Function to toggle gpay_merchant_id field and its title based on gpay checkbox
		function toggleGpayMerchantIdField() {
			if ( gpayCheckbox.checked ) {
				gpayMerchantIdField.style.display = 'block'; // Show the field
				tr.style.display = '';
				gpayMerchantIdField.removeAttribute( 'disabled' ); // Enable the field
				var labels = document.querySelectorAll( 'label[for="woocommerce_catalystpay_gateway_gpay_merchant_id"]' );
				labels.forEach( function( label ) {
					label.style.display = 'block'; // Show the label
				} );
			} else {
				gpayMerchantIdField.style.display = 'none'; // Hide the field
				tr.style.display = 'none';
				gpayMerchantIdField.value = '';
				gpayMerchantIdField.setAttribute( 'disabled', 'disabled' ); // Disable the field
				var labels = document.querySelectorAll( 'label[for="woocommerce_catalystpay_gateway_gpay_merchant_id"]' );
				labels.forEach( function( label ) {
					label.style.display = 'none'; // Hide the label
				} );
			}
		}

		// Initial check
		toggleGpayMerchantIdField();

		// Add event listener for checkbox change
		gpayCheckbox.addEventListener( 'change', function() {
			toggleGpayMerchantIdField();
		} );
	} else {
		console.error( 'Google Pay checkbox or Merchant ID field not found in the DOM' );
	}
} );

document.addEventListener( 'DOMContentLoaded', function() {
	var orderListTable = document.querySelector( '.wp-list-table.orders' );

	if ( orderListTable ) {
		var orderRows = orderListTable.querySelectorAll( 'tbody tr' );

		orderRows.forEach( function( row ) {
			var statusColumn = row.querySelector( '.column-order_status' );

			if ( statusColumn && statusColumn.textContent.trim() === 'Pending payment' ) {
				var orderNumber = row.querySelector( '.order_number' ).textContent.trim();
				var orderId = extractOrderIdFromOrderNumber( orderNumber );
				var captureButton = document.createElement( 'button' );
				captureButton.className = 'button capture-order-button';
				captureButton.dataset.orderId = orderId;
				captureButton.textContent = 'Capture';
				statusColumn.appendChild( captureButton );
			}
		} );
	}
} );

function extractOrderIdFromOrderNumber( orderNumber ) {
	// Extract numerical part from the order number using regex
	var orderId = orderNumber.match( /\d+/ );
	return orderId ? orderId[ 0 ] : ''; // Return the first match or an empty string if not found
}

document.addEventListener( 'click', function( event ) {
	if ( event.target.classList.contains( 'capture-order-button' ) ) {
		var orderId = event.target.dataset.orderId;
		// Perform AJAX request to capture and complete the order
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', ajaxurl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8' );
		xhr.onload = function() {
			if ( xhr.status >= 200 && xhr.status < 400 ) {
				var response = xhr.responseText;
				alert( response ); // Show response message
				// You can also update the order status in the UI if needed
			} else {
				console.error( 'Error capturing and completing order.' );
			}
		};
		xhr.onerror = function() {
			console.error( 'Error capturing and completing order.' );
		};
		xhr.send( 'action=capture_complete_order_action&order_id=' + orderId );
	}
} );

function hideForm() {
	var form = document.querySelector( 'form[data-action="submit-registration"]' );

	if ( form ) {
		form.style.display = 'none'; // Hides the form
	} else {
		console.error( 'Form with data-action="submit-registration" not found' );
	}
}

function showForm() {
	var form = document.querySelector( 'form[data-action="submit-registration"]' );
	var card = document.querySelector( '.wpwl-form-card' );
	var regi = document.querySelector( '.wpwl-clearfix' );

	var OtherPaymentButton = document.querySelector( 'button[aria-label="Show other payment methods"]' );


	if ( form ) {
		form.style.display = 'block';
		card.style.display = 'none';
		if ( OtherPaymentButton ) {
			OtherPaymentButton.style.setProperty( 'display', 'block', 'important' );
			regi.style.setProperty( 'display', 'block', 'important' );

		} else {
			console.error( 'Button with aria-label="Show other payment methods" not found' );
		}

	} else {
		console.error( 'Form with data-action="submit-registration" not found' );
	}
}

function setupButtonHandler() {
	var showOtherPaymentButton = document.querySelector( 'button[aria-label="Show other payment methods"]' );

	if ( showOtherPaymentButton ) {
		showOtherPaymentButton.removeEventListener( 'click', hideForm );
		showOtherPaymentButton.addEventListener( 'click', hideForm );
	} else {
		console.error( 'Button with aria-label="Show other payment methods" not found' );
	}
}

function setupFastCheckoutButtonHandler() {
	var fastCheckoutButton = document.querySelector( 'button[aria-label="fastcheckout"]' );

	if ( fastCheckoutButton ) {
		fastCheckoutButton.removeEventListener( 'click', showForm );
		fastCheckoutButton.addEventListener( 'click', showForm );
	} else {
		console.error( 'Button with aria-label="fastcheckout" not found' );
	}
}


var interval = setInterval( function() {
	var showOtherPaymentButtonExists = document.querySelector( 'button[aria-label="Show other payment methods"]' );
	var fastCheckoutButtonExists = document.querySelector( 'button[aria-label="fastcheckout"]' );

	if ( showOtherPaymentButtonExists && fastCheckoutButtonExists ) {
		setupButtonHandler();
		setupFastCheckoutButtonHandler();
		clearInterval( interval );
	}
}, 1000 );
