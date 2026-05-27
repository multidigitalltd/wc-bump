jQuery( function ( $ ) {

	var rowIndex = $( '#order-bumps-list .bump-card' ).length;

	// ── Add new bump card ──────────────────────────────────────
	$( '#add-order-bump' ).on( 'click', function () {
		var html = $( '#bump-card-template' ).html().replace( /__INDEX__/g, rowIndex );
		var $card = $( html );
		$( '#order-bumps-list' ).append( $card );
		initProductSearch( $card );
		$card.find( '.bump-card-body' ).slideDown( 200 );
		rowIndex++;
	} );

	// ── Remove bump card ───────────────────────────────────────
	$( document ).on( 'click', '.remove-bump', function () {
		$( this ).closest( '.bump-card' ).fadeOut( 200, function () {
			$( this ).remove();
		} );
	} );

	// ── Toggle card body ───────────────────────────────────────
	$( document ).on( 'click', '.bump-toggle-body', function () {
		var $btn  = $( this );
		var $body = $btn.closest( '.bump-card' ).find( '.bump-card-body' );
		$body.slideToggle( 200 );
		var isOpen = $body.is( ':visible' );
		$btn.html( isOpen
			? ( wcOrderBumpAdmin.i18n.collapse + ' &#9650;' )
			: ( wcOrderBumpAdmin.i18n.settings  + ' &#9660;' )
		);
	} );

	// ── Update card title when product is selected ─────────────
	$( document ).on( 'change', '.wc-product-search', function () {
		var $select = $( this );
		if ( $select.hasClass( 'bump-condition-product-select' ) ) return;
		var text = $select.find( 'option:selected' ).text().replace( / \(#\d+\)$/, '' );
		$select.closest( '.bump-card' ).find( '.bump-card-product-name' ).text( text || wcOrderBumpAdmin.i18n.noProduct );
	} );

	// ── Update badge preview ───────────────────────────────────
	$( document ).on( 'input', '.bump-badge-input', function () {
		var val      = $( this ).val().trim();
		var $header  = $( this ).closest( '.bump-card' ).find( '.bump-card-title' );
		var $preview = $header.find( '.bump-badge-preview' );
		if ( val ) {
			if ( $preview.length ) {
				$preview.text( val );
			} else {
				$header.append( '<span class="bump-badge-preview">' + $( '<span>' ).text( val ).html() + '</span>' );
			}
		} else {
			$preview.remove();
		}
	} );

	// ── Discount type toggle ───────────────────────────────────
	$( document ).on( 'change', '.bump-discount-type', function () {
		var val   = $( this ).val();
		var $wrap = $( this ).siblings( '.bump-discount-value-wrap' );
		var $sfx  = $wrap.find( '.bump-discount-suffix' );
		if ( val === 'none' ) {
			$wrap.hide();
		} else {
			$wrap.show();
			$sfx.text( val === 'percent' ? '%' : ( typeof woocommerce_admin !== 'undefined' ? woocommerce_admin.currency_symbol : '₪' ) );
		}
	} );

	// ── Condition type toggle ──────────────────────────────────
	$( document ).on( 'change', '.bump-condition-type', function () {
		var val      = $( this ).val();
		var $td      = $( this ).closest( 'td' );
		var $wrapper = $td.find( '.bump-condition-value-wrap' );
		var $product = $td.find( '.bump-condition-product-wrap' );
		var $cat     = $td.find( '.bump-condition-category-wrap' );

		if ( val === 'always' ) {
			$wrapper.hide();
		} else {
			$wrapper.show();
			$product.toggle( val === 'if_product' );
			$cat.toggle(     val === 'if_category' );
		}
	} );

	// ── Init Select2 / selectWoo for product search ────────────
	function initProductSearch( $scope ) {
		$scope.find( '.wc-product-search' ).each( function () {
			var $el = $( this );
			if ( $el.hasClass( 'select2-hidden-accessible' ) ) return;
			if ( typeof $el.selectWoo !== 'function' ) return;

			$el.selectWoo( {
				ajax: {
					url: wcOrderBumpAdmin.ajaxUrl,
					dataType: 'json',
					delay: 250,
					data: function ( params ) {
						return {
							term:     params.term,
							action:   $el.data( 'action' ) || 'woocommerce_json_search_products_and_variations',
							security: wcOrderBumpAdmin.searchNonce,
						};
					},
					processResults: function ( data ) {
						var results = [];
						$.each( data, function ( id, text ) {
							results.push( { id: id, text: text } );
						} );
						return { results: results };
					},
					cache: true,
				},
				minimumInputLength: 3,
				placeholder: $el.data( 'placeholder' ) || '',
				allowClear: true,
			} );
		} );
	}

	// Init on existing cards
	$( '.bump-card' ).each( function () {
		initProductSearch( $( this ) );
	} );

} );
