jQuery( function ( $ ) {

	$( document ).on( 'click', '.order-upsale-btn', function () {
		var $btn         = $( this );
		var $item        = $btn.closest( '.order-upsale-item' );
		var productId    = $btn.data( 'product-id' );
		var cartItemKey  = $btn.data( 'cart-item-key' ) || '';
		var quantity     = $btn.data( 'quantity' ) || 1;
		var isAdded      = $btn.hasClass( 'is-added' );
		var toggle       = isAdded ? 'remove' : 'add';

		$btn.prop( 'disabled', true ).addClass( 'is-loading' );

		$.post( wcOrderUpsale.ajaxUrl, {
			action:        'order_upsale_toggle',
			nonce:         wcOrderUpsale.nonce,
			product_id:    productId,
			toggle:        toggle,
			cart_item_key: cartItemKey,
			quantity:      quantity,
		} )
		.done( function ( response ) {
			if ( ! response.success ) return;

			if ( toggle === 'add' ) {
				var newKey = response.data.cart_item_key;
				$btn.data( 'cart-item-key', newKey )
					.addClass( 'is-added' )
					.text( $btn.data( 'remove-text' ) );
				$item.addClass( 'is-added' );
			} else {
				$btn.data( 'cart-item-key', '' )
					.removeClass( 'is-added' )
					.text( $btn.data( 'add-text' ) );
				$item.removeClass( 'is-added' );
			}

			$( document.body ).trigger( 'update_checkout' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).removeClass( 'is-loading' );
		} );
	} );

} );
