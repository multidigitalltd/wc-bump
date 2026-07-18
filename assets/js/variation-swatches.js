/**
 * Beautiful Variations — mirrors swatch buttons onto WooCommerce's native
 * variation <select> elements. The select stays the source of truth so all of
 * WooCommerce's variation logic (price, availability, add-to-cart) is untouched.
 */
( function () {
	'use strict';

	if ( typeof window.jQuery === 'undefined' ) {
		return;
	}

	var $ = window.jQuery;

	/** Locate the native <select> that a swatch group belongs to. */
	function findSelect( $wrap ) {
		var attr   = $wrap.data( 'attribute' );
		var $scope = $wrap.closest( 'td.value, .value, .woocommerce-variation-attribute, tr' );
		var $sel   = $scope.find( 'select[name="' + attr + '"]' );

		if ( ! $sel.length ) {
			$sel = $scope.find( 'select[data-attribute_name="' + attr + '"]' );
		}
		if ( ! $sel.length ) {
			$sel = $wrap.prevAll( 'select' ).first();
		}
		// Last resort: match by attribute name anywhere in the variation form.
		if ( ! $sel.length ) {
			var $form = $wrap.closest( '.variations_form, form' );
			$sel = $form.find( 'select[name="' + attr + '"], select[data-attribute_name="' + attr + '"]' );
		}
		return $sel.first();
	}

	/** Reflect the select's current value + option availability onto the swatches. */
	function sync( $wrap, $select ) {
		var current = $select.val() || '';

		$wrap.find( '.wcse-swatch' ).each( function () {
			var $btn  = $( this );
			var value = $btn.attr( 'data-value' );

			var $option = $select.find( 'option' ).filter( function () {
				return this.value === value;
			} ).first();

			// WooCommerce marks unavailable combinations either by disabling the
			// matching <option> or by removing it entirely — both mean the swatch
			// is not selectable, so a missing option counts as unavailable.
			var disabled = ! $option.length || $option.is( ':disabled' );
			var selected = value === current && current !== '';

			$btn.toggleClass( 'is-selected', selected );
			$btn.toggleClass( 'wcse-disabled', disabled );
			$btn.attr( 'aria-checked', selected ? 'true' : 'false' );
		} );
	}

	function init( $form ) {
		$form.find( '.wcse-swatches' ).each( function () {
			var $wrap = $( this );

			if ( $wrap.data( 'wcseInit' ) ) {
				return;
			}

			var $select = findSelect( $wrap );
			if ( ! $select.length ) {
				return;
			}

			$wrap.data( 'wcseInit', true );
			$select.addClass( 'wcse-select-hidden' );

			$wrap.on( 'click', '.wcse-swatch', function ( e ) {
				e.preventDefault();

				var $btn = $( this );
				if ( $btn.hasClass( 'wcse-disabled' ) ) {
					return;
				}

				var value = $btn.attr( 'data-value' );

				// Clicking the active swatch again clears the selection.
				if ( ( $select.val() || '' ) === value ) {
					$select.val( '' ).trigger( 'change' );
				} else {
					$select.val( value ).trigger( 'change' );
				}
			} );

			$select.on( 'change', function () {
				sync( $wrap, $select );
			} );

			sync( $wrap, $select );
		} );
	}

	$( function () {
		$( '.variations_form' ).each( function () {
			var $form = $( this );

			init( $form );

			// Re-sync after WooCommerce recalculates option availability / resets.
			$form.on(
				'woocommerce_update_variation_values reset_data check_variations woocommerce_variation_has_changed',
				function () {
					$form.find( '.wcse-swatches' ).each( function () {
						var $wrap   = $( this );
						var $select = findSelect( $wrap );
						if ( $select.length ) {
							sync( $wrap, $select );
						}
					} );
				}
			);
		} );
	} );
}() );
