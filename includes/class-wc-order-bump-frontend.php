<?php

defined( 'ABSPATH' ) || exit;

class WC_Order_Bump_Frontend {

	public function __construct() {
		add_action( 'woocommerce_review_order_before_payment', [ $this, 'display_bumps' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'wp_ajax_order_bump_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nopriv_order_bump_toggle', [ $this, 'ajax_toggle' ] );
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
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'order_bump_toggle' ),
			'adding'   => __( 'מוסיף...', 'wc-order-bump' ),
			'removing' => __( 'מסיר...', 'wc-order-bump' ),
		] );
	}

	public function display_bumps(): void {
		$bumps        = WC_Order_Bump_Admin::get_bumps();
		$active_bumps = array_values( array_filter( $bumps, fn( $b ) => ! empty( $b['active'] ) && ! empty( $b['product_id'] ) ) );

		if ( empty( $active_bumps ) ) {
			return;
		}

		$bump_product_ids = array_column( $active_bumps, 'product_id' );
		$in_cart          = $this->get_bumps_in_cart( $bump_product_ids );

		echo '<div class="wc-order-bumps-wrapper">';
		echo '<h3 class="order-bumps-heading">' . esc_html__( 'הצעות מיוחדות עבורך', 'wc-order-bump' ) . '</h3>';

		foreach ( $active_bumps as $bump ) {
			$product = wc_get_product( $bump['product_id'] );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			$product_id    = $product->get_id();
			$cart_item_key = $in_cart[ $product_id ] ?? '';
			$checked       = ! empty( $cart_item_key );

			$title       = ! empty( $bump['title'] )       ? $bump['title']       : $product->get_name();
			$description = ! empty( $bump['description'] ) ? $bump['description'] : wp_strip_all_tags( $product->get_short_description() );
			$price_html  = $product->get_price_html();
			$image       = $product->get_image( 'thumbnail' );
			?>
			<div class="order-bump-item<?php echo $checked ? ' is-added' : ''; ?>"
				 data-product-id="<?php echo esc_attr( $product_id ); ?>">
				<label class="order-bump-label">
					<span class="order-bump-checkbox-wrap">
						<input type="checkbox" class="order-bump-checkbox"
							data-product-id="<?php echo esc_attr( $product_id ); ?>"
							data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
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
							<span class="order-bump-price"><?php echo $price_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</span>
					</span>
				</label>
			</div>
			<?php
		}

		echo '</div>';
	}

	private function get_bumps_in_cart( array $product_ids ): array {
		$in_cart = [];
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( in_array( $cart_item['product_id'], $product_ids, true ) ) {
				$in_cart[ $cart_item['product_id'] ] = $cart_item_key;
			}
		}
		return $in_cart;
	}

	public function ajax_toggle(): void {
		check_ajax_referer( 'order_bump_toggle', 'nonce' );

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$toggle     = sanitize_key( $_POST['toggle'] ?? '' );

		if ( ! $product_id || ! in_array( $toggle, [ 'add', 'remove' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request' ] );
		}

		if ( $toggle === 'add' ) {
			$cart_item_key = WC()->cart->add_to_cart( $product_id, 1 );
			if ( $cart_item_key ) {
				WC()->cart->calculate_totals();
				wp_send_json_success( [ 'cart_item_key' => $cart_item_key ] );
			} else {
				wp_send_json_error( [ 'message' => __( 'לא ניתן להוסיף את המוצר לסל', 'wc-order-bump' ) ] );
			}
		} else {
			$cart_item_key = sanitize_key( $_POST['cart_item_key'] ?? '' );
			if ( $cart_item_key ) {
				WC()->cart->remove_cart_item( $cart_item_key );
				WC()->cart->calculate_totals();
			}
			wp_send_json_success();
		}
	}
}
