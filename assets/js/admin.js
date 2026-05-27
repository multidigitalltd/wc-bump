jQuery( function ( $ ) {

	var rowIndex = $( '#order-upsales-list .upsale-card' ).length;

	// ── Add new upsale card ──────────────────────────────────────
	$( '#add-order-upsale' ).on( 'click', function () {
		var html  = $( '#upsale-card-template' ).html().replace( /__INDEX__/g, rowIndex );
		var $card = $( html );
		$( '#order-upsales-list' ).append( $card );
		initProductSearch( $card );
		initColorPickers( $card );
		$card.find( '.upsale-card-body' ).slideDown( 200 );
		rowIndex++;
	} );

	// ── Remove upsale card ───────────────────────────────────────
	$( document ).on( 'click', '.remove-upsale', function () {
		$( this ).closest( '.upsale-card' ).fadeOut( 200, function () { $( this ).remove(); } );
	} );

	// ── Toggle card expand / collapse ──────────────────────────
	$( document ).on( 'click', '.upsale-toggle-body', function () {
		var $btn  = $( this );
		var $body = $btn.closest( '.upsale-card' ).find( '.upsale-card-body' );
		$body.slideToggle( 200 );
		var open = $body.is( ':visible' );
		$btn.html( open
			? ( wcOrderUpsaleAdmin.i18n.collapse + ' &#9650;' )
			: ( wcOrderUpsaleAdmin.i18n.settings  + ' &#9660;' )
		);
	} );

	// ── Update card title when product selected ────────────────
	$( document ).on( 'change', '.wc-product-search', function () {
		if ( $( this ).hasClass( 'upsale-condition-product-select' ) ) return;
		var text = $( this ).find( 'option:selected' ).text().replace( / \(#\d+\)$/, '' );
		$( this ).closest( '.upsale-card' ).find( '.upsale-card-product-name' )
			.text( text || wcOrderUpsaleAdmin.i18n.noProduct );
	} );

	// ── Live badge preview ──────────────────────────────────────
	$( document ).on( 'input', '.upsale-badge-input', function () {
		var val      = $( this ).val().trim();
		var $header  = $( this ).closest( '.upsale-card' ).find( '.upsale-card-title' );
		var $preview = $header.find( '.upsale-badge-preview' );
		if ( val ) {
			if ( $preview.length ) {
				$preview.text( val );
			} else {
				$header.append( '<span class="upsale-badge-preview">' + $( '<span>' ).text( val ).html() + '</span>' );
			}
		} else {
			$preview.remove();
		}
	} );

	// ── Discount type toggle ───────────────────────────────────
	$( document ).on( 'change', '.upsale-discount-type', function () {
		var val   = $( this ).val();
		var $wrap = $( this ).siblings( '.upsale-discount-value-wrap' );
		if ( val === 'none' ) {
			$wrap.hide();
		} else {
			$wrap.show();
			$wrap.find( '.upsale-discount-suffix' ).text( val === 'percent' ? '%' : wcOrderUpsaleAdmin.currency );
		}
	} );

	// ── Condition type toggle ──────────────────────────────────
	$( document ).on( 'change', '.upsale-condition-type', function () {
		var val  = $( this ).val();
		var $td  = $( this ).closest( 'td' );
		$td.find( '.upsale-condition-value-wrap' ).toggle( val !== 'always' );
		$td.find( '.upsale-condition-product-wrap' ).toggle( val === 'if_product' );
		$td.find( '.upsale-condition-category-wrap' ).toggle( val === 'if_category' );
	} );

	// ── Product search (Select2 / selectWoo) ───────────────────
	function initProductSearch( $scope ) {
		$scope.find( '.wc-product-search' ).each( function () {
			var $el = $( this );
			if ( $el.hasClass( 'select2-hidden-accessible' ) ) return;
			if ( typeof $el.selectWoo !== 'function' ) return;

			$el.selectWoo( {
				ajax: {
					url: wcOrderUpsaleAdmin.ajaxUrl,
					dataType: 'json',
					delay: 250,
					data: function ( p ) {
						return {
							term:     p.term,
							action:   $el.data( 'action' ) || 'woocommerce_json_search_products_and_variations',
							security: wcOrderUpsaleAdmin.searchNonce,
						};
					},
					processResults: function ( data ) {
						var results = [];
						$.each( data, function ( id, text ) { results.push( { id: id, text: text } ); } );
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

	// ── Color pickers ──────────────────────────────────────────
	function initColorPickers( $scope ) {
		$scope.find( '.upsale-color-picker' ).wpColorPicker();
	}

	// Init on page load
	$( '.upsale-card' ).each( function () {
		initProductSearch( $( this ) );
		initColorPickers( $( this ) );
	} );

} );
