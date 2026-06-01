jQuery( function ( $ ) {

	var rowIndex = $( '#order-upsales-list .upsale-card' ).length;

	// ── Add new upsale card ──────────────────────────────────────
	$( '#add-order-upsale' ).on( 'click', function () {
		var html  = $( '#upsale-card-template' ).html().replace( /__INDEX__/g, rowIndex );
		var $card = $( html );
		$( '#order-upsales-list' ).append( $card );
		initProductSearch( $card );
		initWysiwygEditors( $card );
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
		var val = $( this ).val();
		var $td = $( this ).closest( 'td' );
		$td.find( '.upsale-condition-value-wrap' ).toggle( val !== 'always' );
		$td.find( '.upsale-condition-product-wrap' ).toggle( val === 'if_product' );
		$td.find( '.upsale-condition-category-wrap' ).toggle( val === 'if_category' );
	} );

	// ── Native color picker ────────────────────────────────────
	$( document ).on( 'input change', '.upsale-color-picker-native', function () {
		var v    = this.value;
		var $row = $( this ).parent();
		$row.find( '.upsale-color-value' ).val( v );
		$row.find( '.upsale-color-val-display' ).text( v ).css( 'color', '#444' );
		$row.find( '.upsale-color-clear' ).css( 'visibility', 'visible' );
	} );

	$( document ).on( 'click', '.upsale-color-clear', function () {
		var $row = $( this ).parent();
		$row.find( '.upsale-color-value' ).val( '' );
		$row.find( '.upsale-color-picker-native' ).val( '#ffffff' );
		$row.find( '.upsale-color-val-display' ).text( 'ברירת מחדל' ).css( 'color', '#aaa' );
		$( this ).css( 'visibility', 'hidden' );
	} );

	// ── Product search (Select2 / selectWoo) ───────────────────
	function initProductSearch( $scope ) {
		$scope.find( '.wc-product-search' ).each( function () {
			var $el = $( this );

			var selectFn = typeof $el.selectWoo === 'function' ? 'selectWoo'
			             : typeof $el.select2   === 'function' ? 'select2'
			             : null;
			if ( ! selectFn ) return;

			if ( $el.hasClass( 'select2-hidden-accessible' ) ) {
				try { $el[ selectFn ]( 'destroy' ); } catch ( e ) {}
			}

			$el[ selectFn ]( {
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

	// ── WYSIWYG Mini Editor ────────────────────────────────────
	function applyFontSize( size ) {
		var sel = window.getSelection();
		if ( ! sel || ! sel.rangeCount ) return;
		var range = sel.getRangeAt( 0 );
		if ( range.collapsed ) return;
		var span = document.createElement( 'span' );
		span.style.fontSize = size;
		try {
			range.surroundContents( span );
		} catch ( e ) {
			span.appendChild( range.extractContents() );
			range.insertNode( span );
			range.selectNode( span );
			sel.removeAllRanges();
			sel.addRange( range );
		}
	}

	function initWysiwygEditors( $card ) {
		$card.find( '.upsale-wysiwyg-wrap' ).each( function () {
			var $wrap    = $( this );
			var $editor  = $wrap.find( '.upsale-wysiwyg-editor' );
			var $hidden  = $wrap.find( 'input[type="hidden"]' );
			var $toolbar = $wrap.find( '.upsale-wysiwyg-toolbar' );

			// Seed hidden input from current editor HTML (normalises on page load).
			$hidden.val( $editor.html() );

			// Sync on every keystroke / paste.
			$editor.on( 'input', function () {
				$hidden.val( $editor.html() );
			} );

			// Formatting buttons — mousedown keeps focus on editor.
			$toolbar.find( '.upsale-wysiwyg-btn' ).on( 'mousedown', function ( e ) {
				e.preventDefault();
				$editor[ 0 ].focus();
				document.execCommand( $( this ).data( 'cmd' ), false, null );
				$hidden.val( $editor.html() );
			} );

			// Font size via custom span injection.
			$toolbar.find( '.upsale-wysiwyg-fontsize' ).on( 'change', function () {
				var size = this.value;
				if ( ! size ) return;
				$editor[ 0 ].focus();
				applyFontSize( size );
				$hidden.val( $editor.html() );
				this.value = '';
			} );

			// Text colour.
			$toolbar.find( '.upsale-wysiwyg-color' ).on( 'input', function () {
				$editor[ 0 ].focus();
				document.execCommand( 'foreColor', false, this.value );
				$hidden.val( $editor.html() );
			} );
		} );
	}

	// Init on page load for all existing cards.
	$( '.upsale-card' ).each( function () {
		initProductSearch( $( this ) );
		initWysiwygEditors( $( this ) );
	} );

} );
