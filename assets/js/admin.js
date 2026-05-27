jQuery( function ( $ ) {
	var rowIndex = $( '#order-bumps-list .bump-row' ).length;

	$( '#add-order-bump' ).on( 'click', function () {
		var template = $( '#bump-row-template' ).html().replace( /__INDEX__/g, rowIndex );
		var $row = $( template );
		$( '#order-bumps-list' ).append( $row );
		initProductSearch( $row.find( '.wc-product-search' ) );
		rowIndex++;
	} );

	$( document ).on( 'click', '.remove-bump', function () {
		$( this ).closest( '.bump-row' ).remove();
	} );

	function initProductSearch( $select ) {
		if ( typeof $.fn.selectWoo !== 'function' ) {
			return;
		}
		$select.selectWoo( {
			ajax: {
				url: wcOrderBumpAdmin.ajaxUrl,
				dataType: 'json',
				delay: 250,
				data: function ( params ) {
					return {
						term: params.term,
						action: 'woocommerce_json_search_products_and_variations',
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
			placeholder: $select.data( 'placeholder' ),
			allowClear: true,
		} );
	}
} );
