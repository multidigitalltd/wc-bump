/**
 * Sticky Add-to-Cart bar.
 *
 * Reveals the bar once the real add-to-cart button scrolls out of view
 * (IntersectionObserver). The bar's button proxies the real add-to-cart, or
 * scrolls up to the options when a variation still needs selecting. The price
 * follows the selected variation via WooCommerce's jQuery variation events.
 */
( function () {
	'use strict';

	var bar = document.querySelector( '.wcse-sticky-atc' );
	if ( ! bar ) {
		return;
	}

	var form    = document.querySelector( 'form.cart' );
	var mainBtn = ( form || document ).querySelector( '.single_add_to_cart_button' );
	var target  = mainBtn || form;
	if ( ! target ) {
		return;
	}

	function show() {
		bar.hidden = false;
		bar.setAttribute( 'aria-hidden', 'false' );
	}
	function hide() {
		bar.hidden = true;
		bar.setAttribute( 'aria-hidden', 'true' );
	}

	if ( 'IntersectionObserver' in window ) {
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					hide();
				} else {
					show();
				}
			} );
		}, { threshold: 0 } );
		io.observe( target );
	} else {
		window.addEventListener( 'scroll', function () {
			if ( window.pageYOffset > 500 ) {
				show();
			} else {
				hide();
			}
		} );
	}

	var stickyBtn = bar.querySelector( '.wcse-sticky-btn' );
	if ( stickyBtn ) {
		stickyBtn.addEventListener( 'click', function () {
			var blocked = mainBtn && ( mainBtn.disabled || mainBtn.classList.contains( 'disabled' ) );
			if ( mainBtn && ! blocked ) {
				mainBtn.click();
			} else {
				( form || target ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
			}
		} );
	}

	// Price follows the selected variation (WooCommerce jQuery events).
	if ( window.jQuery && form ) {
		var $      = window.jQuery;
		var $form  = $( form );
		var $price = $( bar ).find( '.wcse-sticky-price' );
		var initial = $price.html();

		$form.on( 'found_variation', function ( e, variation ) {
			if ( variation && variation.price_html ) {
				$price.html( variation.price_html );
			}
		} );
		$form.on( 'reset_data hide_variation', function () {
			$price.html( initial );
		} );
	}
}() );
