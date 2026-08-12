/**
 * Buy Now — on variable products, mirror the add-to-cart button's disabled
 * state onto the "Buy Now" button so it can't submit before a variation is
 * chosen (WooCommerce fires jQuery variation events).
 */
( function () {
	'use strict';

	if ( typeof window.jQuery === 'undefined' ) {
		return;
	}
	var $ = window.jQuery;

	$( function () {
		var $form = $( '.variations_form' );
		if ( ! $form.length ) {
			return;
		}
		var $buy = $form.find( '.wcse-buy-now' );
		var $main = $form.find( '.single_add_to_cart_button' );
		if ( ! $buy.length || ! $main.length ) {
			return;
		}

		function sync() {
			var disabled = $main.is( ':disabled' ) || $main.hasClass( 'disabled' );
			$buy.prop( 'disabled', disabled ).toggleClass( 'disabled', disabled );
		}

		$form.on( 'found_variation hide_variation reset_data woocommerce_variation_has_changed', sync );
		sync();
	} );
}() );
