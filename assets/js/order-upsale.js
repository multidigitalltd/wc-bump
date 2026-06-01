jQuery( function ( $ ) {

	// ── Add / Remove upsale from cart ─────────────────────────
	$( document ).on( 'click', '.order-upsale-btn', function () {
		var $btn        = $( this );
		var $item       = $btn.closest( '.order-upsale-item' );
		var productId   = $btn.data( 'product-id' );
		var cartItemKey = $btn.data( 'cart-item-key' ) || '';
		var isAdded     = $btn.hasClass( 'is-added' );
		var toggle      = isAdded ? 'remove' : 'add';

		$btn.prop( 'disabled', true ).addClass( 'is-loading' );

		$.post( wcOrderUpsale.ajaxUrl, {
			action:        'order_upsale_toggle',
			nonce:         wcOrderUpsale.nonce,
			product_id:    productId,
			toggle:        toggle,
			cart_item_key: cartItemKey,
		} )
		.done( function ( response ) {
			if ( ! response.success ) return;

			if ( toggle === 'add' ) {
				var newKey = response.data.cart_item_key;
				$btn.data( 'cart-item-key', newKey )
					.addClass( 'is-added' )
					.attr( 'aria-pressed', 'true' )
					.text( $btn.data( 'remove-text' ) );
				$item.addClass( 'is-added' );
			} else {
				$btn.data( 'cart-item-key', '' )
					.removeClass( 'is-added' )
					.attr( 'aria-pressed', 'false' )
					.text( $btn.data( 'add-text' ) );
				$item.removeClass( 'is-added' );
			}

			$( document.body ).trigger( 'update_checkout' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).removeClass( 'is-loading' );
		} );
	} );

	// ── Lightbox ──────────────────────────────────────────────
	$( document ).on( 'click', '.order-upsale-image-link', function ( e ) {
		e.preventDefault();
		var $trigger = $( this );
		var src      = this.href;

		var $overlay = $(
			'<div class="upsale-lightbox-overlay" role="dialog" aria-modal="true" aria-label="' + ( wcOrderUpsale.i18n.close ) + '" tabindex="-1">' +
				'<button type="button" class="upsale-lightbox-close" aria-label="' + wcOrderUpsale.i18n.close + '">' +
					'<span aria-hidden="true">&times;</span>' +
				'</button>' +
				'<img alt="">' +
			'</div>'
		);

		$overlay.find( 'img' ).attr( 'src', src );
		$( 'body' ).append( $overlay );
		$overlay.find( '.upsale-lightbox-close' ).trigger( 'focus' );

		function closeLightbox() {
			$overlay.remove();
			$( document ).off( 'keydown.upsale_lb' );
			$trigger.trigger( 'focus' );
		}

		// Close on backdrop click (not on image or close button).
		$overlay.on( 'click', function ( ev ) {
			if ( $( ev.target ).is( $overlay ) ) {
				closeLightbox();
			}
		} );

		$overlay.find( '.upsale-lightbox-close' ).on( 'click', closeLightbox );

		// Keyboard: Esc closes, Tab stays inside overlay (focus trap).
		$( document ).on( 'keydown.upsale_lb', function ( ev ) {
			if ( ev.key === 'Escape' ) {
				closeLightbox();
				return;
			}
			if ( ev.key === 'Tab' ) {
				var $focusable = $overlay.find( '.upsale-lightbox-close' );
				ev.preventDefault();
				$focusable.trigger( 'focus' );
			}
		} );
	} );

} );
