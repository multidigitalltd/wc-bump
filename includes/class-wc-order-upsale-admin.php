<?php

defined( 'ABSPATH' ) || exit;

class WC_Order_Upsale_Admin {

	const OPTION_BUMPS    = 'wc_order_upsales';
	const OPTION_SETTINGS = 'wc_order_upsale_settings';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_save_wc_order_upsales', [ $this, 'save_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Order Upsales', 'wc-order-upsale' ),
			__( 'Order Upsales', 'wc-order-upsale' ),
			'manage_woocommerce',
			'wc-order-upsales',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_scripts( string $hook ): void {
		if ( $hook !== 'woocommerce_page_wc-order-upsales' ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );

		wp_enqueue_style(
			'wc-order-upsale-admin',
			WC_ORDER_UPSALE_URL . 'assets/css/admin.css',
			[],
			WC_ORDER_UPSALE_VERSION
		);
		wp_enqueue_script(
			'wc-order-upsale-admin',
			WC_ORDER_UPSALE_URL . 'assets/js/admin.js',
			[ 'jquery', 'wc-enhanced-select' ],
			WC_ORDER_UPSALE_VERSION,
			true
		);
		wp_localize_script( 'wc-order-upsale-admin', 'wcOrderUpsaleAdmin', [
			'searchNonce' => wp_create_nonce( 'search-products' ),
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'currency'    => get_woocommerce_currency_symbol(),
			'i18n'        => [
				'noProduct' => __( 'לא נבחר מוצר', 'wc-order-upsale' ),
				'settings'  => __( 'הגדרות', 'wc-order-upsale' ),
				'collapse'  => __( 'סגור', 'wc-order-upsale' ),
			],
		] );
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_order_upsales_save', 'wc_order_upsales_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Global settings
		update_option( self::OPTION_SETTINGS, [
			'heading'    => sanitize_text_field( $_POST['setting_heading']  ?? '' ),
			'position'   => in_array( $_POST['setting_position'] ?? '', [ 'before_payment', 'after_order_table' ], true )
				? $_POST['setting_position']
				: 'before_payment',
			'custom_css' => wp_strip_all_tags( $_POST['setting_custom_css'] ?? '' ),
		] );

		// Upsales
		$raw   = (array) ( $_POST['upsales'] ?? [] );
		$upsales = [];

		foreach ( $raw as $data ) {
			$product_id = absint( $data['product_id'] ?? 0 );
			if ( ! $product_id ) {
				continue;
			}

			$discount_type = in_array( $data['discount_type'] ?? '', [ 'none', 'percent', 'fixed' ], true )
				? $data['discount_type'] : 'none';

			$condition_type = in_array( $data['condition_type'] ?? '', [ 'always', 'if_product', 'if_category' ], true )
				? $data['condition_type'] : 'always';

			$raw_cta   = (array) ( $data['cta_lines'] ?? [] );
			$cta_lines = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $raw_cta ) ) ), 0, 3 );

			$upsales[] = [
				'product_id'         => $product_id,
				'active'             => (bool) ( $data['active'] ?? false ),
				'title'              => sanitize_text_field( $data['title']              ?? '' ),
				'description'        => wp_kses_post( $data['description']               ?? '' ),
				'badge_text'         => sanitize_text_field( $data['badge_text']         ?? '' ),
				'urgency_text'       => sanitize_text_field( $data['urgency_text']       ?? '' ),
				'cta_lines'          => $cta_lines,
				'button_text'        => sanitize_text_field( $data['button_text']        ?? '' ),
				'button_remove_text' => sanitize_text_field( $data['button_remove_text'] ?? '' ),
				'discount_type'      => $discount_type,
				'discount_value'     => max( 0.0, (float) ( $data['discount_value'] ?? 0 ) ),
				'quantity'           => max( 1, absint( $data['quantity']           ?? 1 ) ),
				'condition_type'     => $condition_type,
				'condition_value'    => absint( $data['condition_value']            ?? 0 ),
				'hide_if_in_cart'    => (bool) ( $data['hide_if_in_cart']           ?? true ),
				'style'              => [
					'bg_color'          => sanitize_hex_color( $data['style']['bg_color']          ?? '' ) ?? '',
					'border_color'      => sanitize_hex_color( $data['style']['border_color']      ?? '' ) ?? '',
					'button_bg'         => sanitize_hex_color( $data['style']['button_bg']         ?? '' ) ?? '',
					'button_text_color' => sanitize_hex_color( $data['style']['button_text_color'] ?? '' ) ?? '',
					'badge_color'       => sanitize_hex_color( $data['style']['badge_color']       ?? '' ) ?? '',
					'custom_css'        => wp_strip_all_tags( $data['style']['custom_css']         ?? '' ),
				],
			];
		}

		update_option( self::OPTION_BUMPS, $upsales );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-order-upsales&saved=1' ) );
		exit;
	}

	public static function get_upsales(): array {
		return (array) get_option( self::OPTION_BUMPS, [] );
	}

	public static function get_settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION_SETTINGS, [] ), [
			'heading'    => '',
			'position'   => 'before_payment',
			'custom_css' => '',
		] );
	}

	public function render_page(): void {
		$upsales    = self::get_upsales();
		$settings = self::get_settings();

		$categories = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
		] );
		?>
		<div class="wrap wc-order-upsales-admin">
			<h1><?php esc_html_e( 'Order Upsales', 'wc-order-upsale' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'הגדרות נשמרו בהצלחה.', 'wc-order-upsale' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_order_upsales_save', 'wc_order_upsales_nonce' ); ?>
				<input type="hidden" name="action" value="save_wc_order_upsales">

				<!-- ── Global Settings ─────────────────────────── -->
				<div class="postbox upsale-global-settings">
					<div class="postbox-header">
						<h2 class="hndle"><?php esc_html_e( 'הגדרות כלליות', 'wc-order-upsale' ); ?></h2>
					</div>
					<div class="inside">
						<table class="form-table">
							<tr>
								<th><label for="setting_heading"><?php esc_html_e( 'כותרת הבלוק', 'wc-order-upsale' ); ?></label></th>
								<td>
									<input type="text" id="setting_heading" name="setting_heading"
										value="<?php echo esc_attr( $settings['heading'] ); ?>"
										placeholder="<?php esc_attr_e( 'הצעות מיוחדות עבורך', 'wc-order-upsale' ); ?>"
										class="regular-text">
								</td>
							</tr>
							<tr>
								<th><label for="setting_position"><?php esc_html_e( 'מיקום הצגה', 'wc-order-upsale' ); ?></label></th>
								<td>
									<select id="setting_position" name="setting_position">
										<option value="before_payment" <?php selected( $settings['position'], 'before_payment' ); ?>>
											<?php esc_html_e( 'לפני כפתור התשלום (מומלץ)', 'wc-order-upsale' ); ?>
										</option>
										<option value="after_order_table" <?php selected( $settings['position'], 'after_order_table' ); ?>>
											<?php esc_html_e( 'אחרי סיכום הזמנה', 'wc-order-upsale' ); ?>
										</option>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="setting_custom_css"><?php esc_html_e( 'CSS גלובלי', 'wc-order-upsale' ); ?></label></th>
								<td>
									<textarea id="setting_custom_css" name="setting_custom_css"
										rows="6" class="large-text code"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
									<p class="description"><?php esc_html_e( 'CSS מותאם אישית שיוחל על כל ה-upsales בצ\'קאאוט.', 'wc-order-upsale' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<!-- ── Upsales List ──────────────────────────────── -->
				<h2 style="margin-top:24px"><?php esc_html_e( 'רשימת Order Upsales', 'wc-order-upsale' ); ?></h2>

				<div id="order-upsales-list" class="upsale-cards-list">
					<?php foreach ( $upsales as $i => $upsale ) : ?>
						<?php $this->render_upsale_card( $i, $upsale, $categories ); ?>
					<?php endforeach; ?>
				</div>

				<p>
					<button type="button" id="add-order-upsale" class="button button-secondary">
						&#43; <?php esc_html_e( 'הוסף Order Upsale חדש', 'wc-order-upsale' ); ?>
					</button>
				</p>

				<p class="submit">
					<button type="submit" class="button button-primary button-large">
						<?php esc_html_e( 'שמור הגדרות', 'wc-order-upsale' ); ?>
					</button>
				</p>
			</form>
		</div>

		<template id="upsale-card-template">
			<?php $this->render_upsale_card( '__INDEX__', [], $categories ); ?>
		</template>
		<?php
	}

	public function render_upsale_card( int|string $i, array $upsale, array $categories = [] ): void {
		$n = esc_attr( $i );

		$product_id         = $upsale['product_id']         ?? 0;
		$active             = $upsale['active']              ?? true;
		$title              = $upsale['title']               ?? '';
		$description        = $upsale['description']         ?? '';
		$badge_text         = $upsale['badge_text']          ?? '';
		$urgency_text       = $upsale['urgency_text']        ?? '';
		$cta_lines          = array_pad( (array) ( $upsale['cta_lines'] ?? [] ), 3, '' );
		$button_text        = $upsale['button_text']         ?? '';
		$button_remove_text = $upsale['button_remove_text']  ?? '';
		$discount_type      = $upsale['discount_type']       ?? 'none';
		$discount_value     = $upsale['discount_value']      ?? 0;
		$quantity           = $upsale['quantity']            ?? 1;
		$condition_type     = $upsale['condition_type']      ?? 'always';
		$condition_value    = $upsale['condition_value']     ?? 0;
		$hide_if_in_cart    = $upsale['hide_if_in_cart']     ?? true;
		$style              = wp_parse_args( $upsale['style'] ?? [], [
			'bg_color'          => '',
			'border_color'      => '',
			'button_bg'         => '',
			'button_text_color' => '',
			'badge_color'       => '',
			'custom_css'        => '',
		] );

		$product      = $product_id ? wc_get_product( $product_id ) : null;
		$product_name = $product ? $product->get_name() : __( 'לא נבחר מוצר', 'wc-order-upsale' );
		$cond_product = ( $condition_type === 'if_product' && $condition_value ) ? wc_get_product( $condition_value ) : null;
		?>
		<div class="upsale-card postbox" data-index="<?php echo $n; ?>">

			<!-- Header -->
			<div class="upsale-card-header postbox-header">
				<label class="upsale-active-label">
					<input type="hidden"   name="upsales[<?php echo $n; ?>][active]" value="0">
					<input type="checkbox" name="upsales[<?php echo $n; ?>][active]" value="1"
						class="upsale-active-cb" <?php checked( $active ); ?>>
				</label>
				<span class="upsale-card-title hndle">
					<span class="upsale-card-product-name"><?php echo esc_html( $product_name ); ?></span>
					<?php if ( $badge_text ) : ?>
						<span class="upsale-badge-preview"><?php echo esc_html( $badge_text ); ?></span>
					<?php endif; ?>
				</span>
				<div class="upsale-card-actions">
					<button type="button" class="button button-small upsale-toggle-body">
						<?php esc_html_e( 'הגדרות', 'wc-order-upsale' ); ?> &#9660;
					</button>
					<button type="button" class="button button-small remove-upsale" style="color:#b32d2e">
						<?php esc_html_e( 'הסר', 'wc-order-upsale' ); ?>
					</button>
				</div>
			</div>

			<!-- Body -->
			<div class="upsale-card-body inside" style="display:none">
				<table class="form-table upsale-form-table">

					<!-- Product -->
					<tr>
						<th><?php esc_html_e( 'מוצר', 'wc-order-upsale' ); ?> <span class="required">*</span></th>
						<td>
							<select class="wc-product-search" name="upsales[<?php echo $n; ?>][product_id]"
								style="min-width:280px"
								data-placeholder="<?php esc_attr_e( 'חפש מוצר...', 'wc-order-upsale' ); ?>"
								data-action="woocommerce_json_search_products_and_variations">
								<?php if ( $product ) : ?>
									<option value="<?php echo esc_attr( $product_id ); ?>" selected>
										<?php echo esc_html( $product->get_name() ); ?> (#<?php echo esc_html( $product_id ); ?>)
									</option>
								<?php endif; ?>
							</select>
						</td>
					</tr>

					<!-- Title -->
					<tr>
						<th><?php esc_html_e( 'כותרת מותאמת', 'wc-order-upsale' ); ?></th>
						<td>
							<input type="text" name="upsales[<?php echo $n; ?>][title]"
								value="<?php echo esc_attr( $title ); ?>"
								placeholder="<?php esc_attr_e( 'ברירת מחדל: שם המוצר', 'wc-order-upsale' ); ?>"
								class="regular-text">
						</td>
					</tr>

					<!-- Description -->
					<tr>
						<th><?php esc_html_e( 'תיאור קצר', 'wc-order-upsale' ); ?></th>
						<td>
							<textarea name="upsales[<?php echo $n; ?>][description]" rows="2" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
						</td>
					</tr>

					<!-- CTA lines -->
					<tr>
						<th><?php esc_html_e( 'משפטי הנעה (CTA)', 'wc-order-upsale' ); ?></th>
						<td>
							<?php
							$placeholders = [
								__( '✓ משלוח חינם על המוצר הזה', 'wc-order-upsale' ),
								__( '✓ ערך ₪199 — שלך היום ב-₪99', 'wc-order-upsale' ),
								__( '✓ אחריות 30 יום', 'wc-order-upsale' ),
							];
							for ( $l = 0; $l < 3; $l++ ) :
							?>
								<input type="text"
									name="upsales[<?php echo $n; ?>][cta_lines][<?php echo $l; ?>]"
									value="<?php echo esc_attr( $cta_lines[ $l ] ); ?>"
									placeholder="<?php echo esc_attr( $placeholders[ $l ] ); ?>"
									class="large-text" style="margin-bottom:5px;display:block">
							<?php endfor; ?>
						</td>
					</tr>

					<!-- Badge -->
					<tr>
						<th><?php esc_html_e( 'תג (Badge)', 'wc-order-upsale' ); ?></th>
						<td>
							<input type="text" name="upsales[<?php echo $n; ?>][badge_text]"
								value="<?php echo esc_attr( $badge_text ); ?>"
								placeholder="<?php esc_attr_e( 'מבצע! / חיסכון 20% / הצעה מיוחדת', 'wc-order-upsale' ); ?>"
								class="regular-text upsale-badge-input">
						</td>
					</tr>

					<!-- Urgency -->
					<tr>
						<th><?php esc_html_e( 'טקסט דחיפות', 'wc-order-upsale' ); ?></th>
						<td>
							<input type="text" name="upsales[<?php echo $n; ?>][urgency_text]"
								value="<?php echo esc_attr( $urgency_text ); ?>"
								placeholder="<?php esc_attr_e( '⏰ מלאי מוגבל! רק 3 נותרו במחיר זה', 'wc-order-upsale' ); ?>"
								class="large-text">
						</td>
					</tr>

					<!-- Discount -->
					<tr>
						<th><?php esc_html_e( 'הנחה', 'wc-order-upsale' ); ?></th>
						<td>
							<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
								<select name="upsales[<?php echo $n; ?>][discount_type]" class="upsale-discount-type">
									<option value="none"    <?php selected( $discount_type, 'none' ); ?>><?php esc_html_e( 'ללא הנחה', 'wc-order-upsale' ); ?></option>
									<option value="percent" <?php selected( $discount_type, 'percent' ); ?>><?php esc_html_e( 'אחוזים (%)', 'wc-order-upsale' ); ?></option>
									<option value="fixed"   <?php selected( $discount_type, 'fixed' ); ?>><?php esc_html_e( 'סכום קבוע', 'wc-order-upsale' ); ?></option>
								</select>
								<span class="upsale-discount-value-wrap" <?php echo $discount_type === 'none' ? 'style="display:none"' : ''; ?>>
									<input type="number" name="upsales[<?php echo $n; ?>][discount_value]"
										value="<?php echo esc_attr( $discount_value ); ?>"
										min="0" step="0.01" style="width:90px">
									<span class="upsale-discount-suffix">
										<?php echo $discount_type === 'percent' ? '%' : get_woocommerce_currency_symbol(); ?>
									</span>
								</span>
							</div>
						</td>
					</tr>

					<!-- Quantity -->
					<tr>
						<th><?php esc_html_e( 'כמות', 'wc-order-upsale' ); ?></th>
						<td>
							<input type="number" name="upsales[<?php echo $n; ?>][quantity]"
								value="<?php echo esc_attr( $quantity ); ?>"
								min="1" step="1" style="width:70px">
						</td>
					</tr>

					<!-- Hide if in cart -->
					<tr>
						<th><?php esc_html_e( 'אם המוצר כבר בסל', 'wc-order-upsale' ); ?></th>
						<td>
							<label>
								<input type="hidden"   name="upsales[<?php echo $n; ?>][hide_if_in_cart]" value="0">
								<input type="checkbox" name="upsales[<?php echo $n; ?>][hide_if_in_cart]" value="1"
									<?php checked( $hide_if_in_cart ); ?>>
								<?php esc_html_e( 'הסתר את ה-Upsale אם המוצר כבר קיים בסל', 'wc-order-upsale' ); ?>
							</label>
						</td>
					</tr>

					<!-- Condition -->
					<tr>
						<th><?php esc_html_e( 'תנאי הצגה', 'wc-order-upsale' ); ?></th>
						<td>
							<select name="upsales[<?php echo $n; ?>][condition_type]" class="upsale-condition-type">
								<option value="always"      <?php selected( $condition_type, 'always' ); ?>><?php esc_html_e( 'תמיד', 'wc-order-upsale' ); ?></option>
								<option value="if_product"  <?php selected( $condition_type, 'if_product' ); ?>><?php esc_html_e( 'רק אם מוצר ספציפי בסל', 'wc-order-upsale' ); ?></option>
								<option value="if_category" <?php selected( $condition_type, 'if_category' ); ?>><?php esc_html_e( 'רק אם קטגוריה ספציפית בסל', 'wc-order-upsale' ); ?></option>
							</select>
							<div class="upsale-condition-value-wrap" <?php echo $condition_type === 'always' ? 'style="display:none"' : ''; ?>>
								<div class="upsale-condition-product-wrap" <?php echo $condition_type !== 'if_product' ? 'style="display:none"' : ''; ?>>
									<select class="wc-product-search upsale-condition-product-select"
										name="upsales[<?php echo $n; ?>][condition_value]"
										style="min-width:280px;margin-top:8px"
										data-placeholder="<?php esc_attr_e( 'חפש מוצר תנאי...', 'wc-order-upsale' ); ?>"
										data-action="woocommerce_json_search_products_and_variations">
										<?php if ( $cond_product ) : ?>
											<option value="<?php echo esc_attr( $condition_value ); ?>" selected>
												<?php echo esc_html( $cond_product->get_name() ); ?> (#<?php echo esc_html( $condition_value ); ?>)
											</option>
										<?php endif; ?>
									</select>
								</div>
								<div class="upsale-condition-category-wrap" <?php echo $condition_type !== 'if_category' ? 'style="display:none"' : ''; ?>>
									<select name="upsales[<?php echo $n; ?>][condition_value]"
										class="upsale-condition-category-select"
										style="min-width:280px;margin-top:8px">
										<option value=""><?php esc_html_e( 'בחר קטגוריה...', 'wc-order-upsale' ); ?></option>
										<?php if ( ! is_wp_error( $categories ) ) : ?>
											<?php foreach ( $categories as $cat ) : ?>
												<option value="<?php echo esc_attr( $cat->term_id ); ?>"
													<?php selected( $condition_type === 'if_category' ? $condition_value : 0, $cat->term_id ); ?>>
													<?php echo esc_html( $cat->name ); ?>
												</option>
											<?php endforeach; ?>
										<?php endif; ?>
									</select>
								</div>
							</div>
						</td>
					</tr>

				</table>

				<!-- ── Styling & Button Section ────────────────── -->
				<div class="upsale-section-divider">
					<span><?php esc_html_e( 'עיצוב וכפתור', 'wc-order-upsale' ); ?></span>
				</div>

				<table class="form-table upsale-form-table">

					<!-- Button text -->
					<tr>
						<th><?php esc_html_e( 'כיתוב כפתור הוספה', 'wc-order-upsale' ); ?></th>
						<td>
							<input type="text" name="upsales[<?php echo $n; ?>][button_text]"
								value="<?php echo esc_attr( $button_text ); ?>"
								placeholder="<?php esc_attr_e( 'כן, הוסיפו לי! →', 'wc-order-upsale' ); ?>"
								class="regular-text">
						</td>
					</tr>

					<!-- Button remove text -->
					<tr>
						<th><?php esc_html_e( 'כיתוב כפתור הסרה', 'wc-order-upsale' ); ?></th>
						<td>
							<input type="text" name="upsales[<?php echo $n; ?>][button_remove_text]"
								value="<?php echo esc_attr( $button_remove_text ); ?>"
								placeholder="<?php esc_attr_e( '✓ נוסף — לחץ להסרה', 'wc-order-upsale' ); ?>"
								class="regular-text">
						</td>
					</tr>

					<!-- Colors -->
					<tr>
						<th><?php esc_html_e( 'צבעים', 'wc-order-upsale' ); ?></th>
						<td>
							<div class="upsale-color-grid">
								<?php
								$color_fields = [
									'bg_color'          => __( 'רקע כרטיס', 'wc-order-upsale' ),
									'border_color'      => __( 'צבע גבול', 'wc-order-upsale' ),
									'button_bg'         => __( 'רקע כפתור', 'wc-order-upsale' ),
									'button_text_color' => __( 'טקסט כפתור', 'wc-order-upsale' ),
									'badge_color'       => __( 'צבע Badge', 'wc-order-upsale' ),
								];
								foreach ( $color_fields as $field => $label ) :
									$has_val = ! empty( $style[ $field ] );
								?>
									<div class="upsale-color-field">
										<label><?php echo esc_html( $label ); ?></label>
										<div style="display:flex;align-items:center;gap:5px">
											<input type="color"
												class="upsale-color-picker-native"
												value="<?php echo esc_attr( $style[ $field ] ?: '#ffffff' ); ?>"
												style="width:36px;height:26px;padding:1px 2px;border:1px solid #c3c4c7;border-radius:3px;cursor:pointer">
											<input type="hidden"
												class="upsale-color-value"
												name="upsales[<?php echo $n; ?>][style][<?php echo esc_attr( $field ); ?>]"
												value="<?php echo esc_attr( $style[ $field ] ); ?>">
											<span class="upsale-color-val-display" style="font-size:.78em;font-family:monospace;color:<?php echo $has_val ? '#444' : '#aaa'; ?>">
												<?php echo $has_val ? esc_html( $style[ $field ] ) : __( 'ברירת מחדל', 'wc-order-upsale' ); ?>
											</span>
											<button type="button" class="upsale-color-clear button-link"
												style="font-size:15px;line-height:1;padding:0 2px;min-height:0;color:#bbb;text-decoration:none;<?php echo $has_val ? '' : 'visibility:hidden;'; ?>"
												title="<?php esc_attr_e( 'נקה', 'wc-order-upsale' ); ?>">&#x2715;</button>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>

					<!-- Custom CSS per upsale -->
					<tr>
						<th><?php esc_html_e( 'CSS מותאם לכרטיס זה', 'wc-order-upsale' ); ?></th>
						<td>
							<textarea name="upsales[<?php echo $n; ?>][style][custom_css]"
								rows="5" class="large-text code"
								placeholder=".order-upsale-item { font-family: 'Heebo', sans-serif; }"><?php echo esc_textarea( $style['custom_css'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'CSS שיוחל על כרטיס זה בלבד. השתמש ב-.order-upsale-item כ-selector.', 'wc-order-upsale' ); ?>
							</p>
						</td>
					</tr>

				</table>
			</div><!-- .upsale-card-body -->
		</div><!-- .upsale-card -->
		<?php
	}
}
