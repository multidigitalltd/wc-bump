jQuery( function ( $ ) {

	$( document ).on( 'change', '.order-bump-checkbox', function () {
		var $checkbox    = $( this );
		var $item        = $checkbox.closest( '.order-bump-item' );
		var productId    = $checkbox.data( 'product-id' );
		var cartItemKey  = $checkbox.data( 'cart-item-key' ) || '';
		var quantity     = $checkbox.data( 'quantity' ) || 1;
		var toggle       = $checkbox.prop( 'checked' ) ? 'add' : 'remove';

		$checkbox.prop( 'disabled', true );
		$item.addClass( 'is-loading' );

		$.post( wcOrderBump.ajaxUrl, {
			action:        'order_bump_toggle',
			nonce:         wcOrderBump.nonce,
			product_id:    productId,
			toggle:        toggle,
			cart_item_key: cartItemKey,
			quantity:      quantity,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				if ( toggle === 'add' ) {
					$checkbox.data( 'cart-item-key', response.data.cart_item_key );
					$item.addClass( 'is-added' );
				} else {
					$checkbox.data( 'cart-item-key', '' );
					$item.removeClass( 'is-added' );
				}
				$( document.body ).trigger( 'update_checkout' );
			} else {
				$checkbox.prop( 'checked', ! $checkbox.prop( 'checked' ) );
			}
		} )
		.fail( function () {
			$checkbox.prop( 'checked', ! $checkbox.prop( 'checked' ) );
		} )
		.always( function () {
			$checkbox.prop( 'disabled', false );
			$item.removeClass( 'is-loading' );
		} );
	} );

} );
