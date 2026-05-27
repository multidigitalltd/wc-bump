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

		add_action( 'wp_ajax_order_bump_toggle',        [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nopriv_order_bump_toggle', [ $this, 'ajax_toggle' ] );

		add_filter( 'woocommerce_add_cart_item_data',       [ $this, 'flag_cart_item' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals',  [ $this, 'apply_discount' ], 20 );
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

	public function display_bumps(): void {
		$bumps        = WC_Order_Bump_Admin::get_bumps();
		$settings     = WC_Order_Bump_Admin::get_settings();
		$active_bumps = array_values( array_filter( $bumps, fn( $b ) => ! empty( $b['active'] ) && ! empty( $b['product_id'] ) ) );

		if ( empty( $active_bumps ) ) {
			return;
		}

		// Build map: product_id => cart_item_key for all cart items
		$all_product_ids = array_column( $active_bumps, 'product_id' );
		$in_cart         = $this->get_bumps_in_cart( $all_product_ids );

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
			$already_in_cart = ! empty( $cart_item_key );

			// Hide if product already in cart and hide_if_in_cart is enabled
			if ( $already_in_cart && ( $bump['hide_if_in_cart'] ?? true ) ) {
				continue;
			}

			if ( ! $this->passes_condition( $bump ) ) {
				continue;
			}

			$has_visible = true;

			$checked     = $already_in_cart;
			$title       = ! empty( $bump['title'] )       ? $bump['title']       : $product->get_name();
			$description = ! empty( $bump['description'] ) ? $bump['description'] : wp_strip_all_tags( $product->get_short_description() );
			$badge_text  = $bump['badge_text']  ?? '';
			$urgency     = $bump['urgency_text'] ?? '';
			$cta_lines   = array_filter( (array) ( $bump['cta_lines'] ?? [] ) );
			$price_html  = $this->get_price_html( $product, $bump );
			$image       = $product->get_image( 'woocommerce_thumbnail' );
			?>
			<div class="order-bump-item<?php echo $checked ? ' is-added' : ''; ?>"
				 data-product-id="<?php echo esc_attr( $product_id ); ?>">

				<?php if ( $badge_text ) : ?>
					<span class="order-bump-badge"><?php echo esc_html( $badge_text ); ?></span>
				<?php endif; ?>

				<label class="order-bump-label">
					<span class="order-bump-checkbox-wrap">
						<input type="checkbox" class="order-bump-checkbox"
							data-product-id="<?php echo esc_attr( $product_id ); ?>"
							data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
							data-quantity="<?php echo esc_attr( $bump['quantity'] ?? 1 ); ?>"
							<?php checked( $checked ); ?>>
					</span>
					<span class="order-bump-inner">
						<?php if ( $image ) : ?>
							<span class="order-bump-image"><?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<?php endif; ?>
						<span class="order-bump-content">
							<span class="order-bump-title"><?php echo esc_html( $title ); ?></span>

							<?php if ( $description ) : ?>
								<span class="order-bump-description"><?php echo esc_html( $description ); ?></span>
							<?php endif; ?>

							<?php if ( ! empty( $cta_lines ) ) : ?>
								<ul class="order-bump-cta-list">
									<?php foreach ( $cta_lines as $line ) : ?>
										<li><span class="order-bump-cta-check" aria-hidden="true">✓</span><?php echo esc_html( $line ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>

							<span class="order-bump-price"><?php echo $price_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</span>
					</span>
				</label>

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
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';
		}
	}

	private function passes_condition( array $bump ): bool {
		$condition_type  = $bump['condition_type']  ?? 'always';
		$condition_value = absint( $bump['condition_value'] ?? 0 );

		if ( $condition_type === 'always' || ! $condition_value ) {
			return true;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( $condition_type === 'if_product' && (int) $cart_item['product_id'] === $condition_value ) {
				return true;
			}
			if ( $condition_type === 'if_category' && has_term( $condition_value, 'product_cat', $cart_item['product_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_price_html( WC_Product $product, array $bump ): string {
		$discount_type  = $bump['discount_type']  ?? 'none';
		$discount_value = (float) ( $bump['discount_value'] ?? 0 );

		if ( $discount_type === 'none' || $discount_value <= 0 ) {
			return $product->get_price_html();
		}

		$original_price = (float) $product->get_price();
		$new_price      = $discount_type === 'percent'
			? $original_price * ( 1 - $discount_value / 100 )
			: $original_price - $discount_value;

		$new_price = max( 0, round( $new_price, wc_get_price_decimals() ) );

		return '<del>' . wc_price( $original_price ) . '</del> <ins>' . wc_price( $new_price ) . '</ins>';
	}

	private function get_bumps_in_cart( array $product_ids ): array {
		$in_cart = [];
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$pid = (int) $cart_item['product_id'];
			if ( in_array( $pid, $product_ids, true ) && ! isset( $in_cart[ $pid ] ) ) {
				$in_cart[ $pid ] = $cart_item_key;
			}
		}
		return $in_cart;
	}

	public function flag_cart_item( array $cart_item_data, int $product_id ): array {
		if ( self::$bump_product_adding === $product_id ) {
			$cart_item_data['_order_bump'] = true;
			if ( ! empty( self::$bump_discount_adding ) ) {
				$cart_item_data['_bump_discount'] = self::$bump_discount_adding;
			}
			self::$bump_product_adding  = null;
			self::$bump_discount_adding = [];
		}
		return $cart_item_data;
	}

	public function apply_discount( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['_order_bump'] ) || empty( $cart_item['_bump_discount'] ) ) {
				continue;
			}

			$discount  = $cart_item['_bump_discount'];
			$product   = $cart_item['data'];
			$original  = (float) $product->get_regular_price() ?: (float) $product->get_price();

			$new_price = $discount['type'] === 'percent'
				? $original * ( 1 - (float) $discount['value'] / 100 )
				: $original - (float) $discount['value'];

			$product->set_price( max( 0, round( $new_price, wc_get_price_decimals() ) ) );
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
			$bumps    = WC_Order_Bump_Admin::get_bumps();
			$discount = [];

			foreach ( $bumps as $bump ) {
				if ( (int) $bump['product_id'] === $product_id
					&& ( $bump['discount_type'] ?? 'none' ) !== 'none'
					&& ! empty( $bump['discount_value'] )
				) {
					$discount = [
						'type'  => $bump['discount_type'],
						'value' => (float) $bump['discount_value'],
					];
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
