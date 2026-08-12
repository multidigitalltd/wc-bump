jQuery( function ( $ ) {

	// ── Variable upsales: attribute selection ─────────────────

	/** Reads the attribute selects of a card. */
	function readAttributes( $item ) {
		var attrs    = {};
		var complete = true;

		$item.find( '.order-upsale-attr' ).each( function () {
			var value = $( this ).val() || '';
			if ( ! value ) complete = false;
			attrs[ $( this ).data( 'attribute' ) ] = value;
		} );

		return { attrs: attrs, complete: complete };
	}

	function showMessage( $item, text, hint ) {
		var $msg = $item.find( '.order-upsale-msg' );
		if ( text ) {
			$msg.text( text ).prop( 'hidden', false );
			if ( hint ) {
				$msg.append( $( '<span class="order-upsale-hint"></span>' ).text( hint ) );
			}
		} else {
			$msg.text( '' ).prop( 'hidden', true );
		}
	}

	/**
	 * An expired nonce makes admin-ajax answer with a bare "-1" instead of JSON,
	 * which otherwise surfaces as a misleading "combination unavailable".
	 */
	function isExpired( response ) {
		return response === -1 || response === '-1' || response === 0 || response === '0';
	}

	/** Reports a failed response, preferring the server's own explanation. */
	function showFailure( $item, response, fallback ) {
		if ( isExpired( response ) ) {
			showMessage( $item, wcOrderUpsale.i18n.expired );
			return;
		}
		var data = response && response.data;
		showMessage( $item, ( data && data.message ) || fallback, data && data.hint );
	}

	// Refresh price and availability whenever a variation choice changes.
	$( document ).on( 'change', '.order-upsale-attr', function () {
		var $item  = $( this ).closest( '.order-upsale-item' );
		var $btn   = $item.find( '.order-upsale-btn' );
		var $price = $item.find( '.order-upsale-price' );
		var chosen = readAttributes( $item );

		// Remember the parent's price range so clearing a select can restore it.
		if ( typeof $price.data( 'defaultHtml' ) === 'undefined' ) {
			$price.data( 'defaultHtml', $price.html() );
		}

		if ( ! chosen.complete ) {
			$btn.prop( 'disabled', true );
			$price.html( $price.data( 'defaultHtml' ) );
			showMessage( $item, '' );
			return;
		}

		$btn.prop( 'disabled', true );
		showMessage( $item, '' );

		$.post( wcOrderUpsale.ajaxUrl, {
			action:     'order_upsale_resolve_variation',
			nonce:      wcOrderUpsale.nonce,
			product_id: $btn.data( 'product-id' ),
			attributes: chosen.attrs,
		} )
		.done( function ( response ) {
			if ( ! response || ! response.success ) {
				showFailure( $item, response, wcOrderUpsale.i18n.unavailable );
				return;
			}

			$price.html( response.data.price_html );

			if ( response.data.in_stock ) {
				$btn.prop( 'disabled', false );
			} else {
				showMessage( $item, wcOrderUpsale.i18n.outOfStock );
			}
		} )
		.fail( function () {
			showMessage( $item, wcOrderUpsale.i18n.genericError );
		} );
	} );

	// ── Add / Remove upsale from cart ─────────────────────────
	$( document ).on( 'click', '.order-upsale-btn', function () {
		var $btn        = $( this );
		var $item       = $btn.closest( '.order-upsale-item' );
		var productId   = $btn.data( 'product-id' );
		var cartItemKey = $btn.data( 'cart-item-key' ) || '';
		var isAdded     = $btn.hasClass( 'is-added' );
		var toggle      = isAdded ? 'remove' : 'add';
		var chosen      = readAttributes( $item );

		if ( toggle === 'add' && $btn.data( 'variable' ) && ! chosen.complete ) {
			showMessage( $item, wcOrderUpsale.i18n.chooseOptions );
			return;
		}

		$btn.prop( 'disabled', true ).addClass( 'is-loading' );

		$.post( wcOrderUpsale.ajaxUrl, {
			action:        'order_upsale_toggle',
			nonce:         wcOrderUpsale.nonce,
			product_id:    productId,
			toggle:        toggle,
			cart_item_key: cartItemKey,
			attributes:    chosen.attrs,
		} )
		.done( function ( response ) {
			if ( ! response || ! response.success ) {
				showFailure( $item, response, wcOrderUpsale.i18n.genericError );
				return;
			}

			showMessage( $item, '' );

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
			$btn.removeClass( 'is-loading' );
			// A variable card stays locked until every option is picked again.
			$btn.prop(
				'disabled',
				!! $btn.data( 'variable' ) && ! $btn.hasClass( 'is-added' ) && ! readAttributes( $item ).complete
			);
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
