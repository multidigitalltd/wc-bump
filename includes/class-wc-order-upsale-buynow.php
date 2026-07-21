<?php

defined( 'ABSPATH' ) || exit;

/**
 * "Buy Now" express button.
 *
 * Adds a second submit button to the product form that adds the item to the
 * cart and redirects straight to checkout, skipping the cart page. It rides on
 * WooCommerce's own add-to-cart form submission (so quantity and the selected
 * variation are handled natively) and only changes the post-add redirect via a
 * hidden flag.
 *
 * Research: every extra step in the path to purchase costs conversions; a direct
 * express path (à la Amazon's 1-click) shortens the funnel and lifts completion.
 */
class WC_Order_Upsale_Buynow {

	const OPTION = 'wc_store_enhancer_buynow';

	public function __construct() {
		add_filter( 'wc_store_enhancer_settings_tabs',            [ $this, 'register_settings_tab' ], 16 );
		add_action( 'admin_post_save_wc_store_enhancer_buynow',   [ $this, 'save_settings' ] );

		if ( $this->is_enabled() ) {
			add_action( 'woocommerce_after_add_to_cart_button', [ $this, 'render_button' ] );
			add_filter( 'woocommerce_add_to_cart_redirect',     [ $this, 'redirect' ] );
			add_action( 'wp_enqueue_scripts',                   [ $this, 'enqueue_assets' ] );
		}
	}

	/* ─────────────────────────── Settings ───────────────────────────── */

	public static function get_settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [
			'button_text' => '',
		] );
	}

	private function is_enabled(): bool {
		return ! class_exists( 'WC_Order_Upsale_Modules' )
			|| WC_Order_Upsale_Modules::is_enabled( 'buy_now' );
	}

	/* ─────────────────────────── Frontend ───────────────────────────── */

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return; // JS is only needed to mirror the disabled state on variable products.
		}

		wp_enqueue_script(
			'wcse-buynow',
			WC_ORDER_UPSALE_URL . 'assets/js/buy-now.js',
			[ 'jquery' ],
			WC_ORDER_UPSALE_VERSION,
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);
	}

	public function render_button(): void {
		$product = $GLOBALS['product'] ?? null;
		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		$text = '' !== self::get_settings()['button_text']
			? self::get_settings()['button_text']
			: __( 'קנה עכשיו', 'wc-order-upsale' );

		printf(
			'<button type="submit" name="wcse_buy_now" value="1" class="wcse-buy-now button" style="margin-inline-start:8px">%s</button>',
			esc_html( $text )
		);
	}

	/**
	 * Send the shopper straight to checkout after a "Buy Now" add-to-cart.
	 *
	 * @param string $url Default post-add redirect URL.
	 */
	public function redirect( $url ) {
		// The flag is a UI hint on WooCommerce's own (un-nonced) add-to-cart form;
		// it only changes the redirect target, so no nonce check is required.
		if ( ! empty( $_REQUEST['wcse_buy_now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return wc_get_checkout_url();
		}
		return $url;
	}

	/* ─────────────────────────── Settings tab ───────────────────────── */

	public function register_settings_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'buynow',
			'label'    => __( 'קנה עכשיו', 'wc-order-upsale' ),
			'callback' => [ $this, 'render_settings_tab' ],
		];
		return $tabs;
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_buynow_save', 'wc_store_enhancer_buynow_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		update_option( self::OPTION, [
			'button_text' => sanitize_text_field( wp_unslash( $_POST['button_text'] ?? '' ) ),
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=buynow&saved=1' ) );
		exit;
	}

	public function render_settings_tab(): void {
		$settings = self::get_settings();
		?>
		<div class="wcse-admin">
			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'מוסיף כפתור "קנה עכשיו" ליד "הוסף לסל" בעמוד המוצר, שמדלג על עמוד הסל ומעביר ישירות לצ׳קאאוט.', 'wc-order-upsale' ); ?>
			</p>

			<?php if ( ! $this->is_enabled() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'המודול כבוי כרגע. הפעילו אותו מלוח הבקרה.', 'wc-order-upsale' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::MENU_SLUG ) ); ?>"><?php esc_html_e( 'לוח הבקרה', 'wc-order-upsale' ); ?></a>
				</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ההגדרות נשמרו.', 'wc-order-upsale' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_store_enhancer_buynow_save', 'wc_store_enhancer_buynow_nonce' ); ?>
				<input type="hidden" name="action" value="save_wc_store_enhancer_buynow">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcse-buynow-text"><?php esc_html_e( 'כיתוב כפתור', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-buynow-text" name="button_text" value="<?php echo esc_attr( $settings['button_text'] ); ?>" placeholder="<?php esc_attr_e( 'קנה עכשיו', 'wc-order-upsale' ); ?>" class="regular-text"></td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'שמור הגדרות', 'wc-order-upsale' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
