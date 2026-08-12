/**
 * Back-in-Stock — front-end form behaviour.
 *
 * On a variable product the "notify me" form is hidden until the shopper selects
 * an out-of-stock variation (WooCommerce fires jQuery variation events). Form
 * submission is sent over AJAX. On a simple out-of-stock product the form is
 * shown server-side and this only wires up the AJAX submit.
 */
( function () {
	'use strict';

	if ( typeof window.jQuery === 'undefined' ) {
		return;
	}

	var $   = window.jQuery;
	var cfg = window.wcseBis || {};
	var i18n = cfg.i18n || {};

	$( function () {
		var $wrap = $( '.wcse-bis' );
		if ( ! $wrap.length ) {
			return;
		}

		// A form rendered visible belongs to a product that is already sold out as
		// a whole, so it collects against the parent and must not be driven by
		// variation events — those would immediately hide it again.
		var followsVariation = $wrap.is( '[hidden]' );

		var $form = $wrap.closest( '.product' ).find( '.variations_form' );

		// Variable product: toggle by the selected variation's stock state.
		if ( followsVariation && $form.length ) {
			$form.on( 'found_variation', function ( event, variation ) {
				if ( variation && false === variation.is_in_stock ) {
					$wrap.find( 'input[name="variation_id"]' ).val( variation.variation_id );
					$wrap.removeAttr( 'hidden' ).show();
				} else {
					$wrap.hide();
				}
			} );
			$form.on( 'hide_variation reset_data', function () {
				$wrap.find( 'input[name="variation_id"]' ).val( 0 );
				$wrap.hide();
			} );
		}

		$wrap.on( 'submit', 'form.wcse-bis-form', function ( e ) {
			e.preventDefault();

			var $f   = $( this );
			var $msg = $wrap.find( '.wcse-bis-msg' );
			var $btn = $f.find( 'button[type="submit"]' );

			if ( ! $f.find( '[name="consent"]' ).is( ':checked' ) ) {
				$msg.text( i18n.error || 'Error' ).show();
				return;
			}

			$btn.prop( 'disabled', true );

			$.post( cfg.ajaxUrl, {
				action: 'wcse_bis_subscribe',
				nonce: cfg.nonce,
				name: $f.find( '[name="name"]' ).val(),
				email: $f.find( '[name="email"]' ).val(),
				consent: $f.find( '[name="consent"]' ).is( ':checked' ) ? 1 : 0,
				product_id: $f.find( '[name="product_id"]' ).val(),
				variation_id: $f.find( '[name="variation_id"]' ).val()
			} ).done( function ( res ) {
				if ( res && res.success ) {
					$f.hide();
					$msg.text( ( res.data && res.data.message ) || i18n.success || '' ).show();
					return;
				}
				$msg.text( ( res && res.data && res.data.message ) || i18n.error || 'Error' ).show();
				$btn.prop( 'disabled', false );
			} ).fail( function ( jqXHR ) {
				// A page served from cache long enough for its nonce to expire is
				// rejected by check_ajax_referer() with wp_die( -1, 403 ), so it lands
				// here rather than in .done(). Saying "try again" would be useless
				// advice — nothing works until the page is reloaded.
				var expired = jqXHR && ( 403 === jqXHR.status || '-1' === $.trim( jqXHR.responseText || '' ) );
				$msg.text( ( expired ? i18n.expired : i18n.error ) || 'Error' ).show();
				$btn.prop( 'disabled', false );
			} );
		} );
	} );
}() );
