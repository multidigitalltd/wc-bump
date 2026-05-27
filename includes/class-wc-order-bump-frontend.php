<?php

defined( 'ABSPATH' ) || exit;

class WC_Order_Bump_Frontend {

	private static ?int  $bump_product_adding  = null;
	private static array $bump_discount_adding = [];

	public function __construct() {
		$settings = WC_Order_Bump_Admin::get_settings();
		$hook     = $settings['position'] === 'after_order_table'
			? 'woocommerce_review_order_after_order_total'
			: 'woocommerce_review_order_before_payment';

		add_action( $hook, [ $this, 'display_bumps' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'wp_head', [ $this, 'output_custom_css' ] );

		add_action( 'wp_ajax_order_bump_toggle',        [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nopriv_order_bump_toggle', [ $this, 'ajax_toggle' ] );

		add_filter( 'woocommerce_add_cart_item_data',      [ $this, 'flag_cart_item' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_discount' ], 20 );
	}

	public function enqueue_scripts(): void {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'wc-order-bump',
			WC_ORDER_BUMP_URL . 'assets/css/order-bump.css',
			[],
			WC_ORDER_BUMP_VERSION
		);

		wp_enqueue_script(
			'wc-order-bump',
			WC_ORDER_BUMP_URL . 'assets/js/order-bump.js',
			[ 'jquery' ],
			WC_ORDER_BUMP_VERSION,
			true
		);

		wp_localize_script( 'wc-order-bump', 'wcOrderBump', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'order_bump_toggle' ),
		] );
	}

	// Outputs global + per-bump custom CSS on checkout page
	public function output_custom_css(): void {
		if ( ! is_checkout() ) {
			return;
		}

		$settings   = WC_Order_Bump_Admin::get_settings();
		$bumps      = WC_Order_Bump_Admin::get_bumps();
		$css_output = '';

		if ( ! empty( $settings['custom_css'] ) ) {
			$css_output .= "\n" . $settings['custom_css'];
		}

		foreach ( $bumps as $bump ) {
			if ( empty( $bump['active'] ) || empty( $bump['product_id'] ) ) {
				continue;
			}
			$pid        = absint( $bump['product_id'] );
			$per_css    = $bump['style']['custom_css'] ?? '';
			if ( $per_css ) {
				$css_output .= "\n/* bump #{$pid} */\n.order-bump-item[data-product-id=\"{$pid}\"] { " . $per_css . ' }';
			}
		}

		if ( $css_output ) {
			echo '<style id="wc-order-bump-custom">' . $css_output . '</style>' . "\n"; // phpcs:ignore
		}
	}

	public function display_bumps(): void {
		$bumps        = WC_Order_Bump_Admin::get_bumps();
		$settings     = WC_Order_Bump_Admin::get_settings();
		$active_bumps = array_values( array_filter( $bumps, fn( $b ) => ! empty( $b['active'] ) && ! empty( $b['product_id'] ) ) );

		if ( empty( $active_bumps ) ) {
			return;
		}

		$all_pids = array_column( $active_bumps, 'product_id' );
		$in_cart  = $this->get_bumps_in_cart( $all_pids );

		$heading = ! empty( $settings['heading'] )
			? $settings['heading']
			: __( 'הצעות מיוחדות עבורך', 'wc-order-bump' );

		$has_visible = false;
		ob_start();

		foreach ( $active_bumps as $bump ) {
			$product = wc_get_product( $bump['product_id'] );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			$product_id    = $product->get_id();
			$cart_item_key = $in_cart[ $product_id ] ?? '';
			$is_added      = ! empty( $cart_item_key );

			if ( $is_added && ( $bump['hide_if_in_cart'] ?? true ) ) {
				continue;
			}

			if ( ! $this->passes_condition( $bump ) ) {
				continue;
			}

			$has_visible = true;

			$title              = ! empty( $bump['title'] )       ? $bump['title']       : $product->get_name();
			$description        = ! empty( $bump['description'] ) ? $bump['description'] : wp_strip_all_tags( $product->get_short_description() );
			$badge_text         = $bump['badge_text']             ?? '';
			$urgency            = $bump['urgency_text']           ?? '';
			$cta_lines          = array_filter( (array) ( $bump['cta_lines'] ?? [] ) );
			$price_html         = $this->get_price_html( $product, $bump );
			$image              = $product->get_image( 'woocommerce_thumbnail' );
			$button_text        = ! empty( $bump['button_text'] )        ? $bump['button_text']        : __( 'כן, הוסיפו לי! →', 'wc-order-bump' );
			$button_remove_text = ! empty( $bump['button_remove_text'] ) ? $bump['button_remove_text'] : __( '✓ נוסף — לחץ להסרה', 'wc-order-bump' );
			$active_btn_text    = $is_added ? $button_remove_text : $button_text;

			$inline_style = $this->build_inline_style( $bump['style'] ?? [] );
			?>
			<div class="order-bump-item<?php echo $is_added ? ' is-added' : ''; ?>"
				 data-product-id="<?php echo esc_attr( $product_id ); ?>"
				 <?php echo $inline_style ? 'style="' . esc_attr( $inline_style ) . '"' : ''; ?>>

				<?php if ( $badge_text ) : ?>
					<span class="order-bump-badge"><?php echo esc_html( $badge_text ); ?></span>
				<?php endif; ?>

				<div class="order-bump-body">
					<?php if ( $image ) : ?>
						<div class="order-bump-image"><?php echo $image; // phpcs:ignore ?></div>
					<?php endif; ?>
					<div class="order-bump-content">
						<p class="order-bump-title"><?php echo esc_html( $title ); ?></p>
						<?php if ( $description ) : ?>
							<p class="order-bump-description"><?php echo esc_html( $description ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $cta_lines ) ) : ?>
							<ul class="order-bump-cta-list">
								<?php foreach ( $cta_lines as $line ) : ?>
									<li><span class="order-bump-cta-check" aria-hidden="true">✓</span><?php echo esc_html( $line ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<p class="order-bump-price"><?php echo $price_html; // phpcs:ignore ?></p>
					</div>
				</div>

				<button type="button"
					class="order-bump-btn<?php echo $is_added ? ' is-added' : ''; ?>"
					data-product-id="<?php echo esc_attr( $product_id ); ?>"
					data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
					data-quantity="<?php echo esc_attr( $bump['quantity'] ?? 1 ); ?>"
					data-add-text="<?php echo esc_attr( $button_text ); ?>"
					data-remove-text="<?php echo esc_attr( $button_remove_text ); ?>">
					<?php echo esc_html( $active_btn_text ); ?>
				</button>

				<?php if ( $urgency ) : ?>
					<div class="order-bump-urgency"><?php echo esc_html( $urgency ); ?></div>
				<?php endif; ?>
			</div>
			<?php
		}

		$output = ob_get_clean();

		if ( $has_visible ) {
			echo '<div class="wc-order-bumps-wrapper">';
			echo '<h3 class="order-bumps-heading">' . esc_html( $heading ) . '</h3>';
			echo $output; // phpcs:ignore
			echo '</div>';
		}
	}

	private function build_inline_style( array $style ): string {
		$vars = [];
		$map  = [
			'bg_color'          => '--bump-bg',
			'border_color'      => '--bump-border',
			'button_bg'         => '--bump-btn-bg',
			'button_text_color' => '--bump-btn-color',
			'badge_color'       => '--bump-badge-bg',
		];
		foreach ( $map as $key => $var ) {
			if ( ! empty( $style[ $key ] ) ) {
				$vars[] = $var . ':' . esc_attr( $style[ $key ] );
			}
		}
		return $vars ? implode( ';', $vars ) : '';
	}

	private function passes_condition( array $bump ): bool {
		$type  = $bump['condition_type']  ?? 'always';
		$value = absint( $bump['condition_value'] ?? 0 );

		if ( $type === 'always' || ! $value ) {
			return true;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( $type === 'if_product'  && (int) $item['product_id'] === $value ) {
				return true;
			}
			if ( $type === 'if_category' && has_term( $value, 'product_cat', $item['product_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_price_html( WC_Product $product, array $bump ): string {
		$type  = $bump['discount_type']  ?? 'none';
		$value = (float) ( $bump['discount_value'] ?? 0 );

		if ( $type === 'none' || $value <= 0 ) {
			return $product->get_price_html();
		}

		$original  = (float) $product->get_price();
		$new_price = $type === 'percent'
			? $original * ( 1 - $value / 100 )
			: $original - $value;

		$new_price = max( 0, round( $new_price, wc_get_price_decimals() ) );

		return '<del>' . wc_price( $original ) . '</del> <ins>' . wc_price( $new_price ) . '</ins>';
	}

	private function get_bumps_in_cart( array $product_ids ): array {
		$in_cart = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$pid = (int) $item['product_id'];
			if ( in_array( $pid, $product_ids, true ) && ! isset( $in_cart[ $pid ] ) ) {
				$in_cart[ $pid ] = $key;
			}
		}
		return $in_cart;
	}

	public function flag_cart_item( array $data, int $product_id ): array {
		if ( self::$bump_product_adding === $product_id ) {
			$data['_order_bump'] = true;
			if ( ! empty( self::$bump_discount_adding ) ) {
				$data['_bump_discount'] = self::$bump_discount_adding;
			}
			self::$bump_product_adding  = null;
			self::$bump_discount_adding = [];
		}
		return $data;
	}

	public function apply_discount( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['_order_bump'] ) || empty( $item['_bump_discount'] ) ) {
				continue;
			}
			$d        = $item['_bump_discount'];
			$product  = $item['data'];
			$original = (float) $product->get_regular_price() ?: (float) $product->get_price();
			$new      = $d['type'] === 'percent'
				? $original * ( 1 - (float) $d['value'] / 100 )
				: $original - (float) $d['value'];
			$product->set_price( max( 0, round( $new, wc_get_price_decimals() ) ) );
		}
	}

	public function ajax_toggle(): void {
		check_ajax_referer( 'order_bump_toggle', 'nonce' );

		$product_id    = absint( $_POST['product_id']      ?? 0 );
		$toggle        = sanitize_key( $_POST['toggle']    ?? '' );
		$cart_item_key = sanitize_key( $_POST['cart_item_key'] ?? '' );
		$quantity      = max( 1, absint( $_POST['quantity'] ?? 1 ) );

		if ( ! $product_id || ! in_array( $toggle, [ 'add', 'remove' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request' ] );
		}

		if ( $toggle === 'add' ) {
			$discount = [];
			foreach ( WC_Order_Bump_Admin::get_bumps() as $bump ) {
				if ( (int) $bump['product_id'] === $product_id
					&& ( $bump['discount_type'] ?? 'none' ) !== 'none'
					&& ! empty( $bump['discount_value'] )
				) {
					$discount = [ 'type' => $bump['discount_type'], 'value' => (float) $bump['discount_value'] ];
					break;
				}
			}

			self::$bump_product_adding  = $product_id;
			self::$bump_discount_adding = $discount;

			$new_key = WC()->cart->add_to_cart( $product_id, $quantity );

			if ( $new_key ) {
				WC()->cart->calculate_totals();
				wp_send_json_success( [ 'cart_item_key' => $new_key ] );
			} else {
				self::$bump_product_adding  = null;
				self::$bump_discount_adding = [];
				wp_send_json_error( [ 'message' => __( 'לא ניתן להוסיף את המוצר לסל', 'wc-order-bump' ) ] );
			}
		} else {
			if ( $cart_item_key ) {
				WC()->cart->remove_cart_item( $cart_item_key );
				WC()->cart->calculate_totals();
			}
			wp_send_json_success();
		}
	}
}
