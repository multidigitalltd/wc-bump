<?php

defined( 'ABSPATH' ) || exit;

/**
 * Sticky Add-to-Cart bar.
 *
 * A fixed bar on the single product page (top or bottom) that appears once the
 * main add-to-cart button scrolls out of view, keeping the CTA reachable. Its
 * button proxies the real add-to-cart (or scrolls up to the options when a
 * variation still needs choosing), and its price follows the selected variation.
 * Desktop / mobile visibility and position are controlled from the settings.
 *
 * Research: 60%+ of store traffic is mobile, where cart abandonment runs
 * ~80–85%; keeping the add-to-cart reachable while scrolling lifts mobile
 * conversion (Baymard mobile UX research).
 */
class WC_Order_Upsale_Sticky {

	const OPTION = 'wc_store_enhancer_sticky';

	public function __construct() {
		add_filter( 'wc_store_enhancer_settings_tabs',            [ $this, 'register_settings_tab' ], 18 );
		add_action( 'admin_post_save_wc_store_enhancer_sticky',   [ $this, 'save_settings' ] );

		if ( $this->is_enabled() ) {
			add_action( 'wp_enqueue_scripts',            [ $this, 'enqueue_assets' ] );
			add_action( 'woocommerce_after_single_product', [ $this, 'render_bar' ] );
		}
	}

	/* ─────────────────────────── Settings ───────────────────────────── */

	public static function get_settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [
			'show_desktop' => 1,
			'show_mobile'  => 1,
			'position'     => 'bottom', // bottom | top
			'button_text'  => '',
		] );
	}

	private function is_enabled(): bool {
		return ! class_exists( 'WC_Order_Upsale_Modules' )
			|| WC_Order_Upsale_Modules::is_enabled( 'sticky_atc' );
	}

	/* ─────────────────────────── Frontend ───────────────────────────── */

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || ! $product->is_purchasable() ) {
			return;
		}

		wp_enqueue_script(
			'wcse-sticky',
			WC_ORDER_UPSALE_URL . 'assets/js/sticky-atc.js',
			[ 'jquery' ],
			WC_ORDER_UPSALE_VERSION,
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);

		$settings = self::get_settings();
		$base = '.wcse-sticky-atc{position:fixed;inset-inline:0;z-index:9999;background:#fff;font-family:inherit;color:inherit}'
			. '.wcse-sticky-atc[hidden]{display:none}'
			. '.wcse-sticky-bottom{bottom:0;box-shadow:0 -2px 14px rgba(0,0,0,.12)}'
			. '.wcse-sticky-top{top:0;box-shadow:0 2px 14px rgba(0,0,0,.12)}'
			. '.wcse-sticky-inner{display:flex;align-items:center;gap:12px;max-width:1200px;margin:0 auto;padding:8px 16px}'
			. '.wcse-sticky-media img{width:46px;height:46px;object-fit:cover;border-radius:6px;display:block}'
			. '.wcse-sticky-info{flex:1;min-width:0;display:flex;flex-direction:column;line-height:1.25}'
			. '.wcse-sticky-title{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
			. '.wcse-sticky-price{font-size:14px}'
			. '.wcse-sticky-btn{white-space:nowrap;flex:0 0 auto}'
			. '@media (max-width:600px){.wcse-sticky-media{display:none}}';

		if ( empty( $settings['show_mobile'] ) ) {
			$base .= '@media (max-width:768px){.wcse-sticky-atc{display:none!important}}';
		}
		if ( empty( $settings['show_desktop'] ) ) {
			$base .= '@media (min-width:769px){.wcse-sticky-atc{display:none!important}}';
		}

		wp_register_style( 'wcse-sticky', false, [], WC_ORDER_UPSALE_VERSION );
		wp_enqueue_style( 'wcse-sticky' );
		wp_add_inline_style( 'wcse-sticky', $base );
	}

	public function render_bar(): void {
		$product = $GLOBALS['product'] ?? null;
		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
			return;
		}

		$settings = self::get_settings();
		$position = 'top' === $settings['position'] ? 'top' : 'bottom';
		$button   = '' !== $settings['button_text'] ? $settings['button_text'] : __( 'הוסף לסל', 'wc-order-upsale' );
		$image    = $product->get_image( 'woocommerce_gallery_thumbnail' );
		?>
		<div class="wcse-sticky-atc wcse-sticky-<?php echo esc_attr( $position ); ?>" hidden aria-hidden="true">
			<div class="wcse-sticky-inner">
				<?php if ( $image ) : ?>
					<div class="wcse-sticky-media" aria-hidden="true"><?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php endif; ?>
				<div class="wcse-sticky-info">
					<span class="wcse-sticky-title"><?php echo esc_html( $product->get_name() ); ?></span>
					<span class="wcse-sticky-price"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</div>
				<button type="button" class="wcse-sticky-btn button alt"><?php echo esc_html( $button ); ?></button>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────── Settings tab ───────────────────────── */

	public function register_settings_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'sticky',
			'label'    => __( 'סרגל הוספה לסל', 'wc-order-upsale' ),
			'callback' => [ $this, 'render_settings_tab' ],
		];
		return $tabs;
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_sticky_save', 'wc_store_enhancer_sticky_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		update_option( self::OPTION, [
			'show_desktop' => empty( $_POST['show_desktop'] ) ? 0 : 1,
			'show_mobile'  => empty( $_POST['show_mobile'] ) ? 0 : 1,
			'position'     => in_array( $_POST['position'] ?? '', [ 'bottom', 'top' ], true ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : 'bottom',
			'button_text'  => sanitize_text_field( wp_unslash( $_POST['button_text'] ?? '' ) ),
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=sticky&saved=1' ) );
		exit;
	}

	public function render_settings_tab(): void {
		$settings = self::get_settings();
		?>
		<div class="wcse-admin">
			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'סרגל צף עם כפתור "הוסף לסל" שמופיע בעמוד המוצר כשגוללים מעבר לכפתור הראשי. שומר על הקריאה-לפעולה נגישה לאורך כל העמוד.', 'wc-order-upsale' ); ?>
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
				<?php wp_nonce_field( 'wc_store_enhancer_sticky_save', 'wc_store_enhancer_sticky_nonce' ); ?>
				<input type="hidden" name="action" value="save_wc_store_enhancer_sticky">
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'הצגה במכשירים', 'wc-order-upsale' ); ?></th>
						<td>
							<label style="margin-inline-end:16px">
								<input type="hidden" name="show_desktop" value="0">
								<input type="checkbox" name="show_desktop" value="1" <?php checked( ! empty( $settings['show_desktop'] ) ); ?>>
								<?php esc_html_e( 'דסקטופ', 'wc-order-upsale' ); ?>
							</label>
							<label>
								<input type="hidden" name="show_mobile" value="0">
								<input type="checkbox" name="show_mobile" value="1" <?php checked( ! empty( $settings['show_mobile'] ) ); ?>>
								<?php esc_html_e( 'מובייל', 'wc-order-upsale' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-sticky-pos"><?php esc_html_e( 'מיקום', 'wc-order-upsale' ); ?></label></th>
						<td>
							<select id="wcse-sticky-pos" name="position">
								<option value="bottom" <?php selected( $settings['position'], 'bottom' ); ?>><?php esc_html_e( 'תחתית המסך', 'wc-order-upsale' ); ?></option>
								<option value="top"    <?php selected( $settings['position'], 'top' ); ?>><?php esc_html_e( 'ראש המסך', 'wc-order-upsale' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-sticky-btn"><?php esc_html_e( 'כיתוב כפתור', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-sticky-btn" name="button_text" value="<?php echo esc_attr( $settings['button_text'] ); ?>" placeholder="<?php esc_attr_e( 'הוסף לסל', 'wc-order-upsale' ); ?>" class="regular-text"></td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'שמור הגדרות', 'wc-order-upsale' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
