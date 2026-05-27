jQuery( function ( $ ) {

	var rowIndex = $( '#order-bumps-list .bump-card' ).length;

	// ── Add new bump card ──────────────────────────────────────
	$( '#add-order-bump' ).on( 'click', function () {
		var html  = $( '#bump-card-template' ).html().replace( /__INDEX__/g, rowIndex );
		var $card = $( html );
		$( '#order-bumps-list' ).append( $card );
		initProductSearch( $card );
		initColorPickers( $card );
		$card.find( '.bump-card-body' ).slideDown( 200 );
		rowIndex++;
	} );

	// ── Remove bump card ───────────────────────────────────────
	$( document ).on( 'click', '.remove-bump', function () {
		$( this ).closest( '.bump-card' ).fadeOut( 200, function () { $( this ).remove(); } );
	} );

	// ── Toggle card expand / collapse ──────────────────────────
	$( document ).on( 'click', '.bump-toggle-body', function () {
		var $btn  = $( this );
		var $body = $btn.closest( '.bump-card' ).find( '.bump-card-body' );
		$body.slideToggle( 200 );
		var open = $body.is( ':visible' );
		$btn.html( open
			? ( wcOrderBumpAdmin.i18n.collapse + ' &#9650;' )
			: ( wcOrderBumpAdmin.i18n.settings  + ' &#9660;' )
		);
	} );

	// ── Update card title when product selected ────────────────
	$( document ).on( 'change', '.wc-product-search', function () {
		if ( $( this ).hasClass( 'bump-condition-product-select' ) ) return;
		var text = $( this ).find( 'option:selected' ).text().replace( / \(#\d+\)$/, '' );
		$( this ).closest( '.bump-card' ).find( '.bump-card-product-name' )
			.text( text || wcOrderBumpAdmin.i18n.noProduct );
	} );

	// ── Live badge preview ──────────────────────────────────────
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
		if ( val === 'none' ) {
			$wrap.hide();
		} else {
			$wrap.show();
			$wrap.find( '.bump-discount-suffix' ).text( val === 'percent' ? '%' : wcOrderBumpAdmin.currency );
		}
	} );

	// ── Condition type toggle ──────────────────────────────────
	$( document ).on( 'change', '.bump-condition-type', function () {
		var val  = $( this ).val();
		var $td  = $( this ).closest( 'td' );
		$td.find( '.bump-condition-value-wrap' ).toggle( val !== 'always' );
		$td.find( '.bump-condition-product-wrap' ).toggle( val === 'if_product' );
		$td.find( '.bump-condition-category-wrap' ).toggle( val === 'if_category' );
	} );

	// ── Product search (Select2 / selectWoo) ───────────────────
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
					data: function ( p ) {
						return {
							term:     p.term,
							action:   $el.data( 'action' ) || 'woocommerce_json_search_products_and_variations',
							security: wcOrderBumpAdmin.searchNonce,
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
		$scope.find( '.bump-color-picker' ).wpColorPicker();
	}

	// Init on page load
	$( '.bump-card' ).each( function () {
		initProductSearch( $( this ) );
		initColorPickers( $( this ) );
	} );

} );
