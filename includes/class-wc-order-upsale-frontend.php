<?php

defined( 'ABSPATH' ) || exit;

class WC_Order_Upsale_Frontend {

	/** Config id (variation id, variable parent id or simple product id) being added. */
	private static ?int  $upsale_product_adding   = null;
	/** Parent id WooCommerce will actually receive for that add_to_cart() call. */
	private static int   $upsale_parent_adding    = 0;
	/** Variation id for that call, 0 for simple products. */
	private static int   $upsale_variation_adding = 0;
	private static array $upsale_discount_adding  = [];

	/** Cached settings — avoids repeated get_option() calls per request. */
	private array $settings;

	/** Set to true once display_upsales() actually outputs HTML. */
	private bool $upsales_rendered = false;

	public function __construct() {
		$this->settings = WC_Order_Upsale_Admin::get_settings();

		$classic_hook = $this->settings['position'] === 'after_order_table'
			? 'woocommerce_review_order_after_order_total'
			: 'woocommerce_review_order_before_payment';

		// Classic shortcode-based checkout (respects position setting).
		add_action( $classic_hook,                                 [ $this, 'display_upsales' ] );

		// Fallback for WooCommerce Block Checkout (WC 8+ default).
		add_action( 'wp_footer',                                   [ $this, 'inject_for_block_checkout' ] );

		// Shortcode — user can place [wc_order_upsales] anywhere (e.g. Elementor Shortcode widget).
		add_shortcode( 'wc_order_upsales',                         [ $this, 'shortcode_output' ] );

		add_action( 'wp_enqueue_scripts',                          [ $this, 'enqueue_scripts' ] );
		add_action( 'wp_head',                                     [ $this, 'output_custom_css' ] );

		add_action( 'wp_ajax_order_upsale_toggle',                 [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nopriv_order_upsale_toggle',          [ $this, 'ajax_toggle' ] );

		// Resolves the chosen attributes of a variable upsale into a variation so
		// the card can show its real price/stock before the shopper commits.
		add_action( 'wp_ajax_order_upsale_resolve_variation',        [ $this, 'ajax_resolve_variation' ] );
		add_action( 'wp_ajax_nopriv_order_upsale_resolve_variation', [ $this, 'ajax_resolve_variation' ] );

		add_filter( 'woocommerce_add_cart_item_data',              [ $this, 'flag_cart_item' ], 10, 3 );
		// Apply discounted price immediately when the item is first placed in the cart array.
		add_filter( 'woocommerce_add_cart_item',                   [ $this, 'set_price_on_add' ], 99, 1 );
		// Restore custom data AND price when cart loads from session.
		add_filter( 'woocommerce_get_cart_item_from_session',      [ $this, 'restore_cart_item_data' ], 10, 2 );
		// Re-apply before every totals calculation (catches any plugin that resets the price).
		add_action( 'woocommerce_before_calculate_totals',         [ $this, 'apply_discount' ], 99 );
	}

	public function enqueue_scripts(): void {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'wc-order-upsale',
			WC_ORDER_UPSALE_URL . 'assets/css/order-upsale.css',
			[],
			WC_ORDER_UPSALE_VERSION
		);

		wp_enqueue_script(
			'wc-order-upsale',
			WC_ORDER_UPSALE_URL . 'assets/js/order-upsale.js',
			[ 'jquery' ],
			WC_ORDER_UPSALE_VERSION,
			true
		);

		wp_localize_script( 'wc-order-upsale', 'wcOrderUpsale', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'order_upsale_toggle' ),
			'i18n'    => [
				'close'          => __( 'סגור', 'wc-order-upsale' ),
				'chooseOptions'  => __( 'בחרו את כל האפשרויות כדי להוסיף לסל', 'wc-order-upsale' ),
				'unavailable'    => __( 'הצירוף הזה לא קיים למוצר. נסו אפשרות אחרת.', 'wc-order-upsale' ),
				'outOfStock'     => __( 'האפשרות שנבחרה אזלה מהמלאי.', 'wc-order-upsale' ),
				'expired'        => __( 'פג תוקף העמוד. רעננו את הדף ונסו שוב.', 'wc-order-upsale' ),
				'genericError'   => __( 'אירעה שגיאה. נסו שוב.', 'wc-order-upsale' ),
			],
		] );
	}

	public function output_custom_css(): void {
		if ( ! is_checkout() ) {
			return;
		}

		$upsales    = WC_Order_Upsale_Admin::get_upsales();
		$css_output = '';

		if ( ! empty( $this->settings['custom_css'] ) ) {
			$css_output .= "\n" . $this->settings['custom_css'];
		}

		foreach ( $upsales as $upsale ) {
			if ( empty( $upsale['active'] ) || empty( $upsale['product_id'] ) ) {
				continue;
			}
			$pid     = absint( $upsale['product_id'] );
			$per_css = $upsale['style']['custom_css'] ?? '';
			if ( $per_css ) {
				$css_output .= "\n/* upsale #{$pid} */\n.order-upsale-item[data-product-id=\"{$pid}\"] { " . $per_css . ' }';
			}
		}

		if ( $css_output ) {
			echo '<style id="wc-order-upsale-custom">' . $css_output . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Block Checkout fallback: if classic hooks never fired (WC Block Checkout),
	 * output the upsales HTML hidden in the footer and use JS to inject them
	 * into the correct position in the block checkout.
	 */
	public function inject_for_block_checkout(): void {
		if ( ! is_checkout() || $this->upsales_rendered ) {
			return;
		}

		ob_start();
		$this->display_upsales();
		$html = ob_get_clean();

		if ( ! $html ) {
			return;
		}

		// Output as a hidden container; JS will move it into the block checkout.
		echo '<div id="wc-upsales-block-payload" style="display:none">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<script id="wc-upsales-block-injector">
		(function () {
			var SELECTORS = [
				'.wc-block-checkout__payment-method',
				'.wc-block-checkout__order-summary-cart-items',
				'.wc-block-components-totals-wrapper',
				'.wp-block-woocommerce-checkout-payment-block',
				'.wp-block-woocommerce-checkout-order-summary-block',
				'#payment',
			];

			function inject() {
				var src = document.getElementById( 'wc-upsales-block-payload' );
				if ( ! src ) return false;

				for ( var i = 0; i < SELECTORS.length; i++ ) {
					var target = document.querySelector( SELECTORS[ i ] );
					if ( target ) {
						src.style.display = '';
						target.parentNode.insertBefore( src, target );
						return true;
					}
				}
				return false;
			}

			// Try immediately, then retry once blocks have hydrated.
			if ( ! inject() ) {
				var attempts = 0;
				var timer = setInterval( function () {
					if ( inject() || ++attempts >= 10 ) clearInterval( timer );
				}, 300 );
			}
		})();
		</script>
		<?php
	}

	/** Shortcode [wc_order_upsales] — place anywhere in Elementor or page content. */
	public function shortcode_output(): string {
		ob_start();
		$this->display_upsales();
		return ob_get_clean();
	}

	public function display_upsales(): void {
		if ( ! is_checkout() ) {
			return;
		}
		// Respect the dashboard module toggle.
		if ( class_exists( 'WC_Order_Upsale_Modules' ) && ! WC_Order_Upsale_Modules::is_enabled( 'order_upsale' ) ) {
			return;
		}
		// Prevent double rendering when multiple hooks fire on the same page.
		if ( $this->upsales_rendered ) {
			return;
		}
		$upsales        = WC_Order_Upsale_Admin::get_upsales();
		$active_upsales = array_values( array_filter( $upsales, fn( $b ) => ! empty( $b['active'] ) && ! empty( $b['product_id'] ) ) );

		if ( empty( $active_upsales ) ) {
			return;
		}

		// Guard against uninitialised cart (can happen in some edge cases).
		if ( null === WC()->cart ) {
			return;
		}

		$all_pids = array_map( 'intval', array_column( $active_upsales, 'product_id' ) );
		$in_cart  = $this->get_upsales_in_cart( $all_pids );

		$heading = ! empty( $this->settings['heading'] )
			? $this->settings['heading']
			: __( 'הצעות מיוחדות עבורך', 'wc-order-upsale' );

		$has_visible  = false;
		$rendered_ids = [];
		ob_start();

		foreach ( $active_upsales as $upsale ) {
			$product = wc_get_product( $upsale['product_id'] );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			// A variable product is offered with its attribute selectors inside the
			// card; without attributes WooCommerce could never resolve a variation,
			// so such a product is not offerable here.
			$variation_attributes = $product->is_type( 'variable' ) ? $product->get_variation_attributes() : [];
			if ( $product->is_type( 'variable' ) && empty( $variation_attributes ) ) {
				continue;
			}

			$product_id    = $product->get_id();
			$cart_item_key = $in_cart[ $product_id ] ?? '';
			$is_added      = ! empty( $cart_item_key );

			if ( $is_added && ( $upsale['hide_if_in_cart'] ?? true ) ) {
				continue;
			}

			if ( ! $this->passes_condition( $upsale ) ) {
				continue;
			}

			$has_visible    = true;
			$rendered_ids[] = $product_id;

			$title              = ! empty( $upsale['title'] )       ? $upsale['title']       : $product->get_name();
			$description        = ! empty( $upsale['description'] ) ? $upsale['description'] : wp_strip_all_tags( $product->get_short_description() );
			$badge_text         = $upsale['badge_text']             ?? '';
			$urgency            = $upsale['urgency_text']           ?? '';
			$cta_lines          = array_filter( (array) ( $upsale['cta_lines'] ?? [] ) );
			$price_html         = $this->get_price_html( $product, $upsale );
			$button_text        = ! empty( $upsale['button_text'] )        ? $upsale['button_text']        : __( 'כן, הוסיפו לי! →', 'wc-order-upsale' );
			$button_remove_text = ! empty( $upsale['button_remove_text'] ) ? $upsale['button_remove_text'] : __( '✓ נוסף — לחץ להסרה', 'wc-order-upsale' );
			$active_btn_text    = $is_added ? $button_remove_text : $button_text;

			$inline_style = $this->build_inline_style( $upsale['style'] ?? [] );
			$custom_class = ! empty( $upsale['custom_class'] ) ? ' ' . esc_attr( $upsale['custom_class'] ) : '';

			$image_id  = $product->get_image_id();
			$full_url  = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
			$image_tag = $product->get_image( 'woocommerce_thumbnail' );
			?>
			<div class="order-upsale-item<?php echo $is_added ? ' is-added' : ''; ?><?php echo $custom_class; ?>"
				 data-product-id="<?php echo esc_attr( $product_id ); ?>"
				 <?php echo $inline_style ? 'style="' . esc_attr( $inline_style ) . '"' : ''; ?>>

				<?php if ( $badge_text ) : ?>
					<span class="order-upsale-badge" aria-hidden="true"><?php echo esc_html( $badge_text ); ?></span>
				<?php endif; ?>

				<div class="order-upsale-body">
					<?php if ( $image_tag ) : ?>
						<div class="order-upsale-image">
							<?php if ( $full_url ) : ?>
								<a href="<?php echo esc_url( $full_url ); ?>" class="order-upsale-image-link"
									aria-label="<?php echo esc_attr( sprintf( __( 'הצג תמונה מוגדלת של %s', 'wc-order-upsale' ), $product->get_name() ) ); ?>">
									<?php echo $image_tag; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</a>
							<?php else : ?>
								<?php echo $image_tag; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<div class="order-upsale-content">
						<p class="order-upsale-title"><?php echo wp_kses( $title, WC_Order_Upsale_Admin::allowed_inline_html() ); ?></p>
						<?php if ( $product->is_type( 'variation' ) ) : ?>
							<?php $variation_summary = wc_get_formatted_variation( $product, true, false ); ?>
							<?php if ( $variation_summary ) : ?>
								<p class="order-upsale-variation-summary"><?php echo esc_html( $variation_summary ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( $description ) : ?>
							<p class="order-upsale-description"><?php echo wp_kses( $description, WC_Order_Upsale_Admin::allowed_inline_html() ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $cta_lines ) ) : ?>
							<ul class="order-upsale-cta-list" aria-label="<?php esc_attr_e( 'יתרונות המוצר', 'wc-order-upsale' ); ?>">
								<?php foreach ( $cta_lines as $line ) : ?>
									<li><span class="order-upsale-cta-check" aria-hidden="true">✓</span><?php echo esc_html( $line ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<p class="order-upsale-price"><?php echo $price_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					</div>
				</div>

				<?php if ( ! empty( $variation_attributes ) ) : ?>
					<?php $this->render_variation_selects( $product, $variation_attributes, (string) $product_id ); ?>
				<?php endif; ?>

				<p class="order-upsale-msg" role="alert" hidden></p>

				<button type="button"
					class="order-upsale-btn<?php echo $is_added ? ' is-added' : ''; ?>"
					data-product-id="<?php echo esc_attr( $product_id ); ?>"
					data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
					data-quantity="<?php echo esc_attr( $upsale['quantity'] ?? 1 ); ?>"
					data-add-text="<?php echo esc_attr( $button_text ); ?>"
					data-remove-text="<?php echo esc_attr( $button_remove_text ); ?>"
					<?php echo ! empty( $variation_attributes ) ? ' data-variable="1"' : ''; ?>
					<?php echo ( ! empty( $variation_attributes ) && ! $is_added ) ? ' disabled' : ''; ?>
					aria-pressed="<?php echo $is_added ? 'true' : 'false'; ?>">
					<?php echo esc_html( $active_btn_text ); ?>
				</button>

				<?php if ( $urgency ) : ?>
					<div class="order-upsale-urgency" role="note"><?php echo esc_html( $urgency ); ?></div>
				<?php endif; ?>
			</div>
			<?php
		}

		$output = ob_get_clean();

		if ( $has_visible ) {
			$this->upsales_rendered = true;
			WC_Order_Upsale_Analytics::record_impressions( $rendered_ids );
			echo '<section class="wc-order-upsales-wrapper" aria-label="' . esc_attr( $heading ) . '">';
			echo '<h3 class="order-upsales-heading" aria-hidden="true">' . esc_html( $heading ) . '</h3>';
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</section>';
		}
	}

	/**
	 * Attribute selectors for a variable upsale, rendered inside the card so the
	 * shopper can pick a variation without leaving the checkout.
	 *
	 * @param WC_Product            $product    The variable parent product.
	 * @param array<string,array>   $attributes Attribute name => available option values.
	 * @param string                $uid        Unique suffix for label/select ids.
	 */
	private function render_variation_selects( WC_Product $product, array $attributes, string $uid ): void {
		echo '<div class="order-upsale-variations">';

		foreach ( $attributes as $attribute_name => $options ) {
			$key      = wc_variation_attribute_name( $attribute_name );
			$field_id = 'upsale-attr-' . sanitize_html_class( $uid . '-' . $key );
			?>
			<p class="order-upsale-variation-field">
				<label for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( wc_attribute_label( $attribute_name, $product ) ); ?>
				</label>
				<select id="<?php echo esc_attr( $field_id ); ?>"
					class="order-upsale-attr"
					data-attribute="<?php echo esc_attr( $key ); ?>">
					<option value="">
						<?php
						/* translators: %s: attribute name, e.g. "Colour". */
						printf( esc_html__( 'בחרו %s', 'wc-order-upsale' ), esc_html( wc_attribute_label( $attribute_name, $product ) ) );
						?>
					</option>
					<?php
					if ( taxonomy_exists( $attribute_name ) ) {
						$terms = wc_get_product_terms( $product->get_id(), $attribute_name, [ 'fields' => 'all' ] );
						foreach ( $terms as $term ) {
							if ( ! in_array( $term->slug, $options, true ) ) {
								continue;
							}
							printf(
								'<option value="%s">%s</option>',
								esc_attr( $term->slug ),
								esc_html( apply_filters( 'woocommerce_variation_option_name', $term->name, $term, $attribute_name, $product ) )
							);
						}
					} else {
						foreach ( $options as $option ) {
							printf(
								'<option value="%s">%s</option>',
								esc_attr( $option ),
								esc_html( apply_filters( 'woocommerce_variation_option_name', $option, null, $attribute_name, $product ) )
							);
						}
					}
					?>
				</select>
			</p>
			<?php
		}

		echo '</div>';
	}

	/**
	 * The upsale id a cart item belongs to.
	 *
	 * WooCommerce always stores the parent id in product_id, so a variation-based
	 * upsale has to be matched on variation_id. The id the upsale was configured
	 * with is stamped onto the item on add, and is authoritative when present.
	 *
	 * @param array $item Cart item.
	 */
	private static function cart_item_upsale_id( array $item ): int {
		if ( ! empty( $item['_order_upsale_id'] ) ) {
			return (int) $item['_order_upsale_id'];
		}
		$variation_id = (int) ( $item['variation_id'] ?? 0 );
		return $variation_id ?: (int) ( $item['product_id'] ?? 0 );
	}

	/** The active upsale configured for a given product/variation id, if any. */
	private function find_config( int $product_id ): ?array {
		foreach ( WC_Order_Upsale_Admin::get_upsales() as $upsale ) {
			if ( (int) ( $upsale['product_id'] ?? 0 ) === $product_id && ! empty( $upsale['active'] ) ) {
				return $upsale;
			}
		}
		return null;
	}

	/**
	 * The chosen variation attributes for a variable product, read from a request.
	 *
	 * Keys are rebuilt from the product's own attributes rather than trusted from
	 * input, and values are sanitized exactly the way WooCommerce sanitizes them on
	 * the product page. That distinction matters: a taxonomy term slug must go
	 * through sanitize_title(), because sanitize_text_field()/wc_clean() strip
	 * percent-encoded sequences — and a non-Latin slug ("אדום" => "%d7%90%d7%93%d7%95%d7%9d")
	 * is made of nothing else, so cleaning it leaves an empty string and no
	 * variation ever matches.
	 *
	 * @param WC_Product $product The variable parent product.
	 * @param mixed      $raw     Raw, unslashed request value.
	 * @return array<string,string>
	 */
	private function chosen_attributes( WC_Product $product, $raw ): array {
		$submitted = is_array( $raw ) ? $raw : [];
		$clean     = [];

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute->get_variation() ) {
				continue;
			}

			$key   = wc_variation_attribute_name( $attribute->get_name() );
			$value = $submitted[ $key ] ?? '';
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ $key ] = $attribute->is_taxonomy()
				? sanitize_title( (string) $value )
				: html_entity_decode( wc_clean( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		}

		return $clean;
	}

	/**
	 * Whether every variation attribute has been answered. "Any" variations are
	 * handled by the data store, but a blank select means the shopper has not
	 * chosen yet.
	 */
	private function attributes_complete( WC_Product $product, array $attributes ): bool {
		foreach ( array_keys( $product->get_variation_attributes() ) as $attribute_name ) {
			$key = wc_variation_attribute_name( $attribute_name );
			if ( '' === ( $attributes[ $key ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Resolve chosen attributes to a variation id, or 0 when nothing matches. */
	private function match_variation( WC_Product $product, array $attributes ): int {
		if ( ! $product->is_type( 'variable' ) || empty( $attributes ) ) {
			return 0;
		}
		if ( ! $this->attributes_complete( $product, $attributes ) ) {
			return 0;
		}

		return (int) WC_Data_Store::load( 'product' )->find_matching_product_variation( $product, $attributes );
	}

	private function build_inline_style( array $style ): string {
		$map = [
			'bg_color'          => '--upsale-bg',
			'border_color'      => '--upsale-border',
			'button_bg'         => '--upsale-btn-bg',
			'button_text_color' => '--upsale-btn-color',
			'badge_color'       => '--upsale-badge-bg',
			'title_color'       => '--upsale-title-color',
			'desc_color'        => '--upsale-desc-color',
			'price_color'       => '--upsale-price-color',
		];
		$vars = [];
		foreach ( $map as $key => $var ) {
			if ( ! empty( $style[ $key ] ) ) {
				$vars[] = $var . ':' . esc_attr( $style[ $key ] );
			}
		}
		return $vars ? implode( ';', $vars ) : '';
	}

	private function passes_condition( array $upsale ): bool {
		$type  = $upsale['condition_type']  ?? 'always';
		$value = absint( $upsale['condition_value'] ?? 0 );

		if ( $type === 'always' || ! $value ) {
			return true;
		}

		if ( null === WC()->cart ) {
			return true;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			// The condition may point at a variable parent or at one variation.
			if ( $type === 'if_product'
				&& ( (int) $item['product_id'] === $value || (int) ( $item['variation_id'] ?? 0 ) === $value ) ) {
				return true;
			}
			if ( $type === 'if_category' && has_term( $value, 'product_cat', $item['product_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_price_html( WC_Product $product, array $upsale ): string {
		$type  = $upsale['discount_type']  ?? 'none';
		$value = (float) ( $upsale['discount_value'] ?? 0 );

		if ( $type === 'none' || $value <= 0 ) {
			return $product->get_price_html();
		}

		// A variable parent has no single price — show the discounted range until
		// the shopper picks a variation, at which point the card refreshes.
		if ( $product->is_type( 'variable' ) ) {
			$min = (float) $product->get_variation_regular_price( 'min', true );
			$max = (float) $product->get_variation_regular_price( 'max', true );
			if ( $min <= 0 && $max <= 0 ) {
				return $product->get_price_html();
			}
			return '<del>' . $this->format_range( $min, $max ) . '</del> <ins>'
				. $this->format_range( $this->discounted( $min, $type, $value ), $this->discounted( $max, $type, $value ) )
				. '</ins>';
		}

		$original = (float) $product->get_regular_price() ?: (float) $product->get_price();

		return '<del>' . wc_price( $original ) . '</del> <ins>'
			. wc_price( $this->discounted( $original, $type, $value ) ) . '</ins>';
	}

	/** Apply a percent/fixed discount to a price, floored at zero. */
	private function discounted( float $price, string $type, float $value ): float {
		$new = $type === 'percent' ? $price * ( 1 - $value / 100 ) : $price - $value;
		return max( 0, round( $new, wc_get_price_decimals() ) );
	}

	/** "₪10" for a single price, "₪10 – ₪20" for a range. */
	private function format_range( float $min, float $max ): string {
		return $min === $max
			? wc_price( $min )
			: wc_price( $min ) . ' &ndash; ' . wc_price( $max );
	}

	private function get_upsales_in_cart( array $product_ids ): array {
		if ( null === WC()->cart ) {
			return [];
		}
		$in_cart = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$uid = self::cart_item_upsale_id( $item );
			if ( in_array( $uid, $product_ids, true ) && ! isset( $in_cart[ $uid ] ) ) {
				$in_cart[ $uid ] = $key;
			}
		}
		return $in_cart;
	}

	/**
	 * Stamp the upsale flag onto the cart item we are adding.
	 *
	 * WooCommerce rewrites a variation add into parent + variation id, so the
	 * call is identified by that pair rather than by the configured id, and the
	 * configured id is stored alongside for every later lookup.
	 *
	 * @param array      $data         Cart item data.
	 * @param int|string $product_id   Parent product id.
	 * @param int|string $variation_id Variation id, 0 for simple products.
	 */
	public function flag_cart_item( array $data, $product_id, $variation_id = 0 ): array {
		if ( null === self::$upsale_product_adding ) {
			return $data;
		}
		if ( (int) $product_id !== self::$upsale_parent_adding
			|| (int) $variation_id !== self::$upsale_variation_adding ) {
			return $data;
		}

		$data['_order_upsale']    = true;
		$data['_order_upsale_id'] = self::$upsale_product_adding;
		if ( ! empty( self::$upsale_discount_adding ) ) {
			$data['_upsale_discount'] = self::$upsale_discount_adding;
		}

		self::$upsale_product_adding   = null;
		self::$upsale_parent_adding    = 0;
		self::$upsale_variation_adding = 0;
		self::$upsale_discount_adding  = [];

		return $data;
	}

	/**
	 * Layer 1: Apply discounted price immediately when item is first added to cart.
	 * Fires after woocommerce_add_cart_item_data, so _upsale_discount is already set.
	 */
	public function set_price_on_add( array $cart_item ): array {
		if ( ! empty( $cart_item['_order_upsale'] ) && ! empty( $cart_item['_upsale_discount'] ) ) {
			$this->apply_price_to_product( $cart_item['data'], $cart_item['_upsale_discount'] );
		}
		return $cart_item;
	}

	/**
	 * Layer 2: Restore custom data + price when cart loads from WC session.
	 */
	public function restore_cart_item_data( array $item, array $values ): array {
		if ( ! empty( $values['_order_upsale'] ) ) {
			$item['_order_upsale'] = true;
		}
		if ( ! empty( $values['_order_upsale_id'] ) ) {
			$item['_order_upsale_id'] = (int) $values['_order_upsale_id'];
		}
		if ( ! empty( $values['_upsale_discount'] ) && is_array( $values['_upsale_discount'] ) ) {
			$item['_upsale_discount'] = $values['_upsale_discount'];
			// Apply discounted price right as the item is restored from session.
			if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
				$this->apply_price_to_product( $item['data'], $values['_upsale_discount'] );
			}
		}
		return $item;
	}

	/** Shared helper: calculates and sets the discounted price on a product object. */
	private function apply_price_to_product( WC_Product $product, array $discount ): void {
		$type  = $discount['type']  ?? 'none';
		$value = (float) ( $discount['value'] ?? 0 );
		if ( $type === 'none' || $value <= 0 ) {
			return;
		}
		$base = (float) $product->get_regular_price();
		if ( $base <= 0 ) {
			$base = (float) $product->get_price();
		}
		if ( $base <= 0 ) {
			return;
		}
		$product->set_price( $this->discounted( $base, $type, $value ) );
	}

	/**
	 * Layer 3: Re-apply discounted prices just before WC calculates totals.
	 * Runs at priority 99 so it overrides any plugin that may have reset prices.
	 */
	public function apply_discount( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Build config map as fallback when session data is missing.
		$config_map = [];
		foreach ( WC_Order_Upsale_Admin::get_upsales() as $upsale ) {
			$pid  = (int) ( $upsale['product_id'] ?? 0 );
			$type = $upsale['discount_type']  ?? 'none';
			$val  = (float) ( $upsale['discount_value'] ?? 0 );
			if ( $pid && ! empty( $upsale['active'] ) && $type !== 'none' && $val > 0 ) {
				$config_map[ $pid ] = [ 'type' => $type, 'value' => $val ];
			}
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['_order_upsale'] ) ) {
				continue;
			}

			$pid      = self::cart_item_upsale_id( $item );
			$discount = ! empty( $item['_upsale_discount'] )
				? $item['_upsale_discount']
				: ( $config_map[ $pid ] ?? [] );

			if ( empty( $discount ) ) {
				continue;
			}

			$this->apply_price_to_product( $item['data'], $discount );
		}
	}

	/**
	 * Report the price and stock of the variation matching the chosen attributes,
	 * so the card can update before the shopper commits to adding it.
	 */
	public function ajax_resolve_variation(): void {
		check_ajax_referer( 'order_upsale_toggle', 'nonce' );

		$product_id = absint( $_POST['product_id'] ?? 0 );

		$config = $product_id ? $this->find_config( $product_id ) : null;
		if ( ! $config ) {
			wp_send_json_error( [ 'message' => __( 'המוצר אינו מוגדר כאפסייל', 'wc-order-upsale' ) ] );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( [ 'message' => __( 'למוצר זה אין אפשרויות לבחירה', 'wc-order-upsale' ) ] );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per-attribute below.
		$attributes = $this->chosen_attributes( $product, wp_unslash( $_POST['attributes'] ?? [] ) );

		$result = $this->resolve_variation( $product, $attributes );
		if ( ! $result['variation'] ) {
			wp_send_json_error( [ 'message' => $this->resolve_error_message( $result['reason'] ) ] );
		}

		$variation = $result['variation'];
		wp_send_json_success( [
			'variation_id' => $variation->get_id(),
			'price_html'   => $this->get_price_html( $variation, $config ),
			'in_stock'     => $variation->is_in_stock(),
		] );
	}

	/**
	 * The purchasable variation for a set of attributes.
	 *
	 * Returns the reason alongside it so the card can say what actually went
	 * wrong — "this combination was never created" and "this variation has no
	 * price" are different problems with different fixes, and reporting both as
	 * "unavailable" leaves the shop owner guessing.
	 *
	 * @return array{variation:?WC_Product,reason:string} reason is '' on success,
	 *         else 'incomplete', 'no_match' or 'not_purchasable'.
	 */
	private function resolve_variation( WC_Product $product, array $attributes ): array {
		if ( ! $this->attributes_complete( $product, $attributes ) ) {
			return [ 'variation' => null, 'reason' => 'incomplete' ];
		}

		$variation_id = $this->match_variation( $product, $attributes );
		if ( ! $variation_id ) {
			return [ 'variation' => null, 'reason' => 'no_match' ];
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return [ 'variation' => null, 'reason' => 'no_match' ];
		}
		if ( ! $variation->is_purchasable() ) {
			return [ 'variation' => null, 'reason' => 'not_purchasable' ];
		}

		return [ 'variation' => $variation, 'reason' => '' ];
	}

	/** Shopper-facing text for a failed variation resolution. */
	private function resolve_error_message( string $reason ): string {
		switch ( $reason ) {
			case 'incomplete':
				return __( 'בחרו את כל האפשרויות של המוצר.', 'wc-order-upsale' );
			case 'not_purchasable':
				return __( 'האפשרות הזו אינה זמינה לרכישה כרגע.', 'wc-order-upsale' );
			default:
				return __( 'הצירוף הזה לא קיים למוצר. נסו אפשרות אחרת.', 'wc-order-upsale' );
		}
	}

	public function ajax_toggle(): void {
		check_ajax_referer( 'order_upsale_toggle', 'nonce' );

		$product_id    = absint( $_POST['product_id']          ?? 0 );
		$toggle        = sanitize_key( $_POST['toggle']        ?? '' );
		$cart_item_key = sanitize_key( $_POST['cart_item_key'] ?? '' );

		if ( ! $product_id || ! in_array( $toggle, [ 'add', 'remove' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request' ] );
		}

		$upsale_config = $this->find_config( $product_id );
		if ( $upsale_config === null ) {
			wp_send_json_error( [ 'message' => 'Product not configured as upsale' ] );
		}

		$quantity = max( 1, absint( $upsale_config['quantity'] ?? 1 ) );

		if ( $toggle === 'add' ) {
			$this->handle_add( $product_id, $quantity, $upsale_config );
		}

		if ( $cart_item_key ) {
			$cart_item = WC()->cart->get_cart_item( $cart_item_key );
			if ( ! $cart_item || self::cart_item_upsale_id( $cart_item ) !== $product_id ) {
				wp_send_json_error( [ 'message' => 'Invalid cart item' ] );
			}
			WC()->cart->remove_cart_item( $cart_item_key );
			WC()->cart->calculate_totals();
		}
		wp_send_json_success();
	}

	/**
	 * Add an upsale to the cart, resolving a variation first when the configured
	 * product is variable (or is itself a variation). Always ends the request.
	 *
	 * @param int   $product_id The configured upsale id.
	 * @param int   $quantity   Configured quantity.
	 * @param array $config     The upsale configuration.
	 */
	private function handle_add( int $product_id, int $quantity, array $config ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( [ 'message' => __( 'לא ניתן להוסיף את המוצר לסל', 'wc-order-upsale' ) ] );
		}

		$parent_id    = $product_id;
		$variation_id = 0;
		$attributes   = [];

		if ( $product->is_type( 'variable' ) ) {
			// The shopper picks the variation in the card; resolve it server-side
			// so a tampered request can never add an arbitrary variation.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per-attribute below.
			$chosen    = $this->chosen_attributes( $product, wp_unslash( $_POST['attributes'] ?? [] ) );
			$result    = $this->resolve_variation( $product, $chosen );
			$variation = $result['variation'];
			if ( ! $variation ) {
				wp_send_json_error( [ 'message' => $this->resolve_error_message( $result['reason'] ) ] );
			}
			if ( ! $variation->is_in_stock() ) {
				wp_send_json_error( [ 'message' => __( 'האפשרות שנבחרה אזלה מהמלאי.', 'wc-order-upsale' ) ] );
			}
			$variation_id = $variation->get_id();
			$attributes   = $chosen;
		} elseif ( $product->is_type( 'variation' ) ) {
			// A specific variation was configured — WooCommerce needs the parent id
			// plus the variation's own attributes to place it in the cart.
			$parent_id    = $product->get_parent_id();
			$variation_id = $product_id;
			$attributes   = $product->get_variation_attributes();
			if ( ! $parent_id ) {
				wp_send_json_error( [ 'message' => __( 'לא ניתן להוסיף את המוצר לסל', 'wc-order-upsale' ) ] );
			}
		}

		$discount = [];
		$type     = $config['discount_type'] ?? 'none';
		$val      = (float) ( $config['discount_value'] ?? 0 );
		if ( $type !== 'none' && $val > 0 ) {
			$discount = [ 'type' => $type, 'value' => $val ];
		}

		self::$upsale_product_adding   = $product_id;
		self::$upsale_parent_adding    = $parent_id;
		self::$upsale_variation_adding = $variation_id;
		self::$upsale_discount_adding  = $discount;

		$new_key = WC()->cart->add_to_cart( $parent_id, $quantity, $variation_id, $attributes );

		self::$upsale_product_adding   = null;
		self::$upsale_parent_adding    = 0;
		self::$upsale_variation_adding = 0;
		self::$upsale_discount_adding  = [];

		if ( ! $new_key ) {
			wp_send_json_error( [ 'message' => __( 'לא ניתן להוסיף את המוצר לסל', 'wc-order-upsale' ) ] );
		}

		WC()->cart->calculate_totals();
		WC_Order_Upsale_Analytics::record_add_to_cart( $product_id );
		wp_send_json_success( [ 'cart_item_key' => $new_key ] );
	}
}
