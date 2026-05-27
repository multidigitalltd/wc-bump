<?php

defined( 'ABSPATH' ) || exit;

class WC_Order_Bump_Admin {

	const OPTION_KEY = 'wc_order_bumps';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_save_wc_order_bumps', [ $this, 'save_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Order Bumps', 'wc-order-bump' ),
			__( 'Order Bumps', 'wc-order-bump' ),
			'manage_woocommerce',
			'wc-order-bumps',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_scripts( string $hook ): void {
		if ( $hook !== 'woocommerce_page_wc-order-bumps' ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style(
			'wc-order-bump-admin',
			WC_ORDER_BUMP_URL . 'assets/css/admin.css',
			[],
			WC_ORDER_BUMP_VERSION
		);
		wp_enqueue_script(
			'wc-order-bump-admin',
			WC_ORDER_BUMP_URL . 'assets/js/admin.js',
			[ 'jquery', 'wc-enhanced-select' ],
			WC_ORDER_BUMP_VERSION,
			true
		);
		wp_localize_script( 'wc-order-bump-admin', 'wcOrderBumpAdmin', [
			'searchNonce' => wp_create_nonce( 'search-products' ),
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		] );
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_order_bumps_save', 'wc_order_bumps_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		$product_ids  = array_map( 'absint', (array) ( $_POST['bump_product_id']  ?? [] ) );
		$titles       = array_map( 'sanitize_text_field', (array) ( $_POST['bump_title']       ?? [] ) );
		$descriptions = array_map( 'wp_kses_post',        (array) ( $_POST['bump_description'] ?? [] ) );
		$active_keys  = array_map( 'intval',              (array) ( $_POST['bump_active']       ?? [] ) );

		$bumps = [];
		foreach ( $product_ids as $i => $product_id ) {
			if ( ! $product_id ) {
				continue;
			}
			$bumps[] = [
				'product_id'  => $product_id,
				'title'       => $titles[ $i ]       ?? '',
				'description' => $descriptions[ $i ] ?? '',
				'active'      => in_array( $i, $active_keys, true ),
			];
		}

		update_option( self::OPTION_KEY, $bumps );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-order-bumps&saved=1' ) );
		exit;
	}

	public static function get_bumps(): array {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	public function render_page(): void {
		$bumps = self::get_bumps();
		?>
		<div class="wrap wc-order-bumps-admin">
			<h1><?php esc_html_e( 'Order Bumps', 'wc-order-bump' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'הגדרות נשמרו בהצלחה.', 'wc-order-bump' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_order_bumps_save', 'wc_order_bumps_nonce' ); ?>
				<input type="hidden" name="action" value="save_wc_order_bumps">

				<table class="widefat order-bumps-table">
					<thead>
						<tr>
							<th style="width:40px"><?php esc_html_e( 'פעיל', 'wc-order-bump' ); ?></th>
							<th style="width:280px"><?php esc_html_e( 'מוצר', 'wc-order-bump' ); ?></th>
							<th><?php esc_html_e( 'כותרת (אופציונלי)', 'wc-order-bump' ); ?></th>
							<th><?php esc_html_e( 'תיאור קצר (אופציונלי)', 'wc-order-bump' ); ?></th>
							<th style="width:70px"></th>
						</tr>
					</thead>
					<tbody id="order-bumps-list">
						<?php foreach ( $bumps as $i => $bump ) : ?>
							<?php $this->render_bump_row( $i, $bump ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" id="add-order-bump" class="button">
						+ <?php esc_html_e( 'הוסף Order Bump', 'wc-order-bump' ); ?>
					</button>
				</p>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'שמור הגדרות', 'wc-order-bump' ); ?>
					</button>
				</p>
			</form>
		</div>

		<template id="bump-row-template">
			<?php $this->render_bump_row( '__INDEX__', [] ); ?>
		</template>
		<?php
	}

	public function render_bump_row( int|string $i, array $bump ): void {
		$product_id  = $bump['product_id']  ?? 0;
		$title       = $bump['title']       ?? '';
		$description = $bump['description'] ?? '';
		$active      = $bump['active']      ?? true;
		$product     = $product_id ? wc_get_product( $product_id ) : null;
		?>
		<tr class="bump-row">
			<td>
				<input type="checkbox" name="bump_active[]"
					value="<?php echo esc_attr( $i ); ?>"
					<?php checked( $active ); ?>>
			</td>
			<td>
				<select class="wc-product-search" name="bump_product_id[]"
					style="width:100%"
					data-placeholder="<?php esc_attr_e( 'חפש מוצר...', 'wc-order-bump' ); ?>"
					data-action="woocommerce_json_search_products_and_variations">
					<?php if ( $product ) : ?>
						<option value="<?php echo esc_attr( $product_id ); ?>" selected>
							<?php echo esc_html( $product->get_name() ); ?> (#<?php echo esc_html( $product_id ); ?>)
						</option>
					<?php endif; ?>
				</select>
			</td>
			<td>
				<input type="text" name="bump_title[]"
					value="<?php echo esc_attr( $title ); ?>"
					placeholder="<?php echo esc_attr( $product ? $product->get_name() : __( 'כותרת ברירת מחדל מהמוצר', 'wc-order-bump' ) ); ?>"
					style="width:100%">
			</td>
			<td>
				<textarea name="bump_description[]" rows="2" style="width:100%"><?php echo esc_textarea( $description ); ?></textarea>
			</td>
			<td>
				<button type="button" class="button remove-bump">
					<?php esc_html_e( 'הסר', 'wc-order-bump' ); ?>
				</button>
			</td>
		</tr>
		<?php
	}
}
