/**
 * Beautiful Variations — accessible swatch control for WooCommerce variations.
 *
 * The swatch group is an ARIA radiogroup (WAI-ARIA radio pattern): roving
 * tabindex, arrow-key navigation, Home/End, Space/Enter to select, and
 * aria-checked state. The native <select> stays the source of truth for
 * WooCommerce (price, availability, add-to-cart) but is removed from the
 * accessibility tree once the swatches take over, so assistive tech gets one
 * control, not two. Swatches are hidden until JS marks them ready, so a
 * no-JS visitor keeps the fully accessible native <select>.
 *
 * jQuery is used deliberately: WooCommerce's variation form emits jQuery custom
 * events (woocommerce_update_variation_values, reset_data) that can only be
 * observed via jQuery, and jQuery is already enqueued on variable-product pages
 * by WooCommerce itself — so this adds no extra request.
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
		if ( ! $sel.length ) {
			var $form = $wrap.closest( '.variations_form, form' );
			$sel = $form.find( 'select[name="' + attr + '"], select[data-attribute_name="' + attr + '"]' );
		}
		return $sel.first();
	}

	/** Is a given swatch value currently unavailable (option disabled or removed)? */
	function isDisabled( $select, value ) {
		var $option = $select.find( 'option' ).filter( function () {
			return this.value === value;
		} ).first();
		// A removed option means the combination is unavailable, same as disabled.
		return ! $option.length || $option.is( ':disabled' );
	}

	/** Reflect the select's value + availability onto the swatches, incl. roving tabindex. */
	function sync( $wrap, $select ) {
		var current    = $select.val() || '';
		var $swatches  = $wrap.find( '.wcse-swatch' );
		var selectedEl = null;
		var firstEl    = null;

		$swatches.each( function () {
			var $btn     = $( this );
			var value    = $btn.attr( 'data-value' );
			var disabled = isDisabled( $select, value );
			var selected = value === current && current !== '';

			$btn.toggleClass( 'is-selected', selected );
			$btn.toggleClass( 'wcse-disabled', disabled );
			$btn.attr( 'aria-checked', selected ? 'true' : 'false' );
			$btn.attr( 'aria-disabled', disabled ? 'true' : 'false' );

			if ( selected ) {
				selectedEl = this;
			}
			if ( ! disabled && ! firstEl ) {
				firstEl = this;
			}
		} );

		// Roving tabindex: only one swatch is Tab-reachable (the selected one, or
		// the first available), the rest are reached with arrow keys.
		var focusable = selectedEl || firstEl;
		$swatches.each( function () {
			this.tabIndex = ( this === focusable ) ? 0 : -1;
		} );
	}

	function selectValue( $select, value ) {
		if ( ( $select.val() || '' ) === value ) {
			$select.val( '' ).trigger( 'change' );
		} else {
			$select.val( value ).trigger( 'change' );
		}
	}

	/** Move focus/selection to another swatch (arrow-key radio navigation). */
	function moveTo( $wrap, $select, fromEl, dir ) {
		var items = $wrap.find( '.wcse-swatch' ).filter( function () {
			return ! $( this ).hasClass( 'wcse-disabled' );
		} ).toArray();

		if ( ! items.length ) {
			return;
		}

		var idx = items.indexOf( fromEl );
		var next;

		if ( 'first' === dir ) {
			next = items[ 0 ];
		} else if ( 'last' === dir ) {
			next = items[ items.length - 1 ];
		} else {
			if ( idx === -1 ) {
				idx = 0;
			}
			var step = ( 'next' === dir ) ? 1 : -1;
			next = items[ ( idx + step + items.length ) % items.length ];
		}

		if ( next ) {
			selectValue( $select, next.getAttribute( 'data-value' ) );
			next.focus();
		}
	}

	function init( $form ) {
		$form.find( '.wcse-swatches' ).each( function () {
			var $wrap = $( this );

			if ( $wrap.data( 'wcseInit' ) ) {
				return;
			}

			var $select = findSelect( $wrap );
			if ( ! $select.length ) {
				return; // No control found: leave the native <select> visible.
			}

			$wrap.data( 'wcseInit', true );

			// Swatches become the control; remove the native select from the a11y
			// tree and hide it visually, then reveal the (now-ready) swatches.
			$select.attr( 'aria-hidden', 'true' ).attr( 'tabindex', '-1' ).addClass( 'wcse-select-hidden' );
			$wrap.addClass( 'wcse-ready' );

			$wrap.on( 'click', '.wcse-swatch', function ( e ) {
				e.preventDefault();
				var $btn = $( this );
				if ( $btn.hasClass( 'wcse-disabled' ) ) {
					return;
				}
				selectValue( $select, $btn.attr( 'data-value' ) );
			} );

			$wrap.on( 'keydown', '.wcse-swatch', function ( e ) {
				switch ( e.key ) {
					case 'ArrowRight':
					case 'ArrowDown':
						e.preventDefault();
						moveTo( $wrap, $select, this, 'next' );
						break;
					case 'ArrowLeft':
					case 'ArrowUp':
						e.preventDefault();
						moveTo( $wrap, $select, this, 'prev' );
						break;
					case 'Home':
						e.preventDefault();
						moveTo( $wrap, $select, this, 'first' );
						break;
					case 'End':
						e.preventDefault();
						moveTo( $wrap, $select, this, 'last' );
						break;
					case ' ':
					case 'Enter':
						e.preventDefault();
						if ( ! $( this ).hasClass( 'wcse-disabled' ) ) {
							selectValue( $select, this.getAttribute( 'data-value' ) );
						}
						break;
					default:
						break;
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

			// Re-sync after WooCommerce recalculates availability / resets.
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
