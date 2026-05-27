<?php

defined( 'ABSPATH' ) || exit;

class WC_Order_Bump_Admin {

	const OPTION_BUMPS    = 'wc_order_bumps';
	const OPTION_SETTINGS = 'wc_order_bump_settings';

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
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'wp-color-picker' );

		wp_enqueue_style(
			'wc-order-bump-admin',
			WC_ORDER_BUMP_URL . 'assets/css/admin.css',
			[],
			WC_ORDER_BUMP_VERSION
		);
		wp_enqueue_script(
			'wc-order-bump-admin',
			WC_ORDER_BUMP_URL . 'assets/js/admin.js',
			[ 'jquery', 'wc-enhanced-select', 'wp-color-picker' ],
			WC_ORDER_BUMP_VERSION,
			true
		);
		wp_localize_script( 'wc-order-bump-admin', 'wcOrderBumpAdmin', [
			'searchNonce' => wp_create_nonce( 'search-products' ),
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'currency'    => get_woocommerce_currency_symbol(),
			'i18n'        => [
				'noProduct' => __( 'לא נבחר מוצר', 'wc-order-bump' ),
				'settings'  => __( 'הגדרות', 'wc-order-bump' ),
				'collapse'  => __( 'סגור', 'wc-order-bump' ),
			],
		] );
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_order_bumps_save', 'wc_order_bumps_nonce' ) ) {
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

		// Bumps
		$raw   = (array) ( $_POST['bumps'] ?? [] );
		$bumps = [];

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

			$bumps[] = [
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

		update_option( self::OPTION_BUMPS, $bumps );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-order-bumps&saved=1' ) );
		exit;
	}

	public static function get_bumps(): array {
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
		$bumps    = self::get_bumps();
		$settings = self::get_settings();

		$categories = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
		] );
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

				<!-- ── Global Settings ─────────────────────────── -->
				<div class="postbox bump-global-settings">
					<div class="postbox-header">
						<h2 class="hndle"><?php esc_html_e( 'הגדרות כלליות', 'wc-order-bump' ); ?></h2>
					</div>
					<div class="inside">
						<table class="form-table">
							<tr>
								<th><label for="setting_heading"><?php esc_html_e( 'כותרת הבלוק', 'wc-order-bump' ); ?></label></th>
								<td>
									<input type="text" id="setting_heading" name="setting_heading"
										value="<?php echo esc_attr( $settings['heading'] ); ?>"
										placeholder="<?php esc_attr_e( 'הצעות מיוחדות עבורך', 'wc-order-bump' ); ?>"
										class="regular-text">
								</td>
							</tr>
							<tr>
								<th><label for="setting_position"><?php esc_html_e( 'מיקום הצגה', 'wc-order-bump' ); ?></label></th>
								<td>
									<select id="setting_position" name="setting_position">
										<option value="before_payment" <?php selected( $settings['position'], 'before_payment' ); ?>>
											<?php esc_html_e( 'לפני כפתור התשלום (מומלץ)', 'wc-order-bump' ); ?>
										</option>
										<option value="after_order_table" <?php selected( $settings['position'], 'after_order_table' ); ?>>
											<?php esc_html_e( 'אחרי סיכום הזמנה', 'wc-order-bump' ); ?>
										</option>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="setting_custom_css"><?php esc_html_e( 'CSS גלובלי', 'wc-order-bump' ); ?></label></th>
								<td>
									<textarea id="setting_custom_css" name="setting_custom_css"
										rows="6" class="large-text code"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
									<p class="description"><?php esc_html_e( 'CSS מותאם אישית שיוחל על כל ה-bumps בצ\'קאאוט.', 'wc-order-bump' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<!-- ── Bumps List ──────────────────────────────── -->
				<h2 style="margin-top:24px"><?php esc_html_e( 'רשימת Order Bumps', 'wc-order-bump' ); ?></h2>

				<div id="order-bumps-list" class="bump-cards-list">
					<?php foreach ( $bumps as $i => $bump ) : ?>
						<?php $this->render_bump_card( $i, $bump, $categories ); ?>
					<?php endforeach; ?>
				</div>

				<p>
					<button type="button" id="add-order-bump" class="button button-secondary">
						&#43; <?php esc_html_e( 'הוסף Order Bump חדש', 'wc-order-bump' ); ?>
					</button>
				</p>

				<p class="submit">
					<button type="submit" class="button button-primary button-large">
						<?php esc_html_e( 'שמור הגדרות', 'wc-order-bump' ); ?>
					</button>
				</p>
			</form>
		</div>

		<template id="bump-card-template">
			<?php $this->render_bump_card( '__INDEX__', [], $categories ); ?>
		</template>
		<?php
	}

	public function render_bump_card( int|string $i, array $bump, array $categories = [] ): void {
		$n = esc_attr( $i );

		$product_id         = $bump['product_id']         ?? 0;
		$active             = $bump['active']              ?? true;
		$title              = $bump['title']               ?? '';
		$description        = $bump['description']         ?? '';
		$badge_text         = $bump['badge_text']          ?? '';
		$urgency_text       = $bump['urgency_text']        ?? '';
		$cta_lines          = array_pad( (array) ( $bump['cta_lines'] ?? [] ), 3, '' );
		$button_text        = $bump['button_text']         ?? '';
		$button_remove_text = $bump['button_remove_text']  ?? '';
		$discount_type      = $bump['discount_type']       ?? 'none';
		$discount_value     = $bump['discount_value']      ?? 0;
		$quantity           = $bump['quantity']            ?? 1;
		$condition_type     = $bump['condition_type']      ?? 'always';
		$condition_value    = $bump['condition_value']     ?? 0;
		$hide_if_in_cart    = $bump['hide_if_in_cart']     ?? true;
		$style              = wp_parse_args( $bump['style'] ?? [], [
			'bg_color'          => '',
			'border_color'      => '',
			'button_bg'         => '',
			'button_text_color' => '',
			'badge_color'       => '',
			'custom_css'        => '',
		] );

		$product      = $product_id ? wc_get_product( $product_id ) : null;
		$product_name = $product ? $product->get_name() : __( 'לא נבחר מוצר', 'wc-order-bump' );
		$cond_product = ( $condition_type === 'if_product' && $condition_value ) ? wc_get_product( $condition_value ) : null;
		?>
		<div class="bump-card postbox" data-index="<?php echo $n; ?>">

			<!-- Header -->
			<div class="bump-card-header postbox-header">
				<label class="bump-active-label">
					<input type="hidden"   name="bumps[<?php echo $n; ?>][active]" value="0">
					<input type="checkbox" name="bumps[<?php echo $n; ?>][active]" value="1"
						class="bump-active-cb" <?php checked( $active ); ?>>
				</label>
				<span class="bump-card-title hndle">
					<span class="bump-card-product-name"><?php echo esc_html( $product_name ); ?></span>
					<?php if ( $badge_text ) : ?>
						<span class="bump-badge-preview"><?php echo esc_html( $badge_text ); ?></span>
					<?php endif; ?>
				</span>
				<div class="bump-card-actions">
					<button type="button" class="button button-small bump-toggle-body">
						<?php esc_html_e( 'הגדרות', 'wc-order-bump' ); ?> &#9660;
					</button>
					<button type="button" class="button button-small remove-bump" style="color:#b32d2e">
						<?php esc_html_e( 'הסר', 'wc-order-bump' ); ?>
					</button>
				</div>
			</div>

			<!-- Body -->
			<div class="bump-card-body inside" style="display:none">
				<table class="form-table bump-form-table">

					<!-- Product -->
					<tr>
						<th><?php esc_html_e( 'מוצר', 'wc-order-bump' ); ?> <span class="required">*</span></th>
						<td>
							<select class="wc-product-search" name="bumps[<?php echo $n; ?>][product_id]"
								style="min-width:280px"
								data-placeholder="<?php esc_attr_e( 'חפש מוצר...', 'wc-order-bump' ); ?>"
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
						<th><?php esc_html_e( 'כותרת מותאמת', 'wc-order-bump' ); ?></th>
						<td>
							<input type="text" name="bumps[<?php echo $n; ?>][title]"
								value="<?php echo esc_attr( $title ); ?>"
								placeholder="<?php esc_attr_e( 'ברירת מחדל: שם המוצר', 'wc-order-bump' ); ?>"
								class="regular-text">
						</td>
					</tr>

					<!-- Description -->
					<tr>
						<th><?php esc_html_e( 'תיאור קצר', 'wc-order-bump' ); ?></th>
						<td>
							<textarea name="bumps[<?php echo $n; ?>][description]" rows="2" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
						</td>
					</tr>

					<!-- CTA lines -->
					<tr>
						<th><?php esc_html_e( 'משפטי הנעה (CTA)', 'wc-order-bump' ); ?></th>
						<td>
							<?php
							$placeholders = [
								__( '✓ משלוח חינם על המוצר הזה', 'wc-order-bump' ),
								__( '✓ ערך ₪199 — שלך היום ב-₪99', 'wc-order-bump' ),
								__( '✓ אחריות 30 יום', 'wc-order-bump' ),
							];
							for ( $l = 0; $l < 3; $l++ ) :
							?>
								<input type="text"
									name="bumps[<?php echo $n; ?>][cta_lines][<?php echo $l; ?>]"
									value="<?php echo esc_attr( $cta_lines[ $l ] ); ?>"
									placeholder="<?php echo esc_attr( $placeholders[ $l ] ); ?>"
									class="large-text" style="margin-bottom:5px;display:block">
							<?php endfor; ?>
						</td>
					</tr>

					<!-- Badge -->
					<tr>
						<th><?php esc_html_e( 'תג (Badge)', 'wc-order-bump' ); ?></th>
						<td>
							<input type="text" name="bumps[<?php echo $n; ?>][badge_text]"
								value="<?php echo esc_attr( $badge_text ); ?>"
								placeholder="<?php esc_attr_e( 'מבצע! / חיסכון 20% / הצעה מיוחדת', 'wc-order-bump' ); ?>"
								class="regular-text bump-badge-input">
						</td>
					</tr>

					<!-- Urgency -->
					<tr>
						<th><?php esc_html_e( 'טקסט דחיפות', 'wc-order-bump' ); ?></th>
						<td>
							<input type="text" name="bumps[<?php echo $n; ?>][urgency_text]"
								value="<?php echo esc_attr( $urgency_text ); ?>"
								placeholder="<?php esc_attr_e( '⏰ מלאי מוגבל! רק 3 נותרו במחיר זה', 'wc-order-bump' ); ?>"
								class="large-text">
						</td>
					</tr>

					<!-- Discount -->
					<tr>
						<th><?php esc_html_e( 'הנחה', 'wc-order-bump' ); ?></th>
						<td>
							<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
								<select name="bumps[<?php echo $n; ?>][discount_type]" class="bump-discount-type">
									<option value="none"    <?php selected( $discount_type, 'none' ); ?>><?php esc_html_e( 'ללא הנחה', 'wc-order-bump' ); ?></option>
									<option value="percent" <?php selected( $discount_type, 'percent' ); ?>><?php esc_html_e( 'אחוזים (%)', 'wc-order-bump' ); ?></option>
									<option value="fixed"   <?php selected( $discount_type, 'fixed' ); ?>><?php esc_html_e( 'סכום קבוע', 'wc-order-bump' ); ?></option>
								</select>
								<span class="bump-discount-value-wrap" <?php echo $discount_type === 'none' ? 'style="display:none"' : ''; ?>>
									<input type="number" name="bumps[<?php echo $n; ?>][discount_value]"
										value="<?php echo esc_attr( $discount_value ); ?>"
										min="0" step="0.01" style="width:90px">
									<span class="bump-discount-suffix">
										<?php echo $discount_type === 'percent' ? '%' : get_woocommerce_currency_symbol(); ?>
									</span>
								</span>
							</div>
						</td>
					</tr>

					<!-- Quantity -->
					<tr>
						<th><?php esc_html_e( 'כמות', 'wc-order-bump' ); ?></th>
						<td>
							<input type="number" name="bumps[<?php echo $n; ?>][quantity]"
								value="<?php echo esc_attr( $quantity ); ?>"
								min="1" step="1" style="width:70px">
						</td>
					</tr>

					<!-- Hide if in cart -->
					<tr>
						<th><?php esc_html_e( 'אם המוצר כבר בסל', 'wc-order-bump' ); ?></th>
						<td>
							<label>
								<input type="hidden"   name="bumps[<?php echo $n; ?>][hide_if_in_cart]" value="0">
								<input type="checkbox" name="bumps[<?php echo $n; ?>][hide_if_in_cart]" value="1"
									<?php checked( $hide_if_in_cart ); ?>>
								<?php esc_html_e( 'הסתר את ה-Bump אם המוצר כבר קיים בסל', 'wc-order-bump' ); ?>
							</label>
						</td>
					</tr>

					<!-- Condition -->
					<tr>
						<th><?php esc_html_e( 'תנאי הצגה', 'wc-order-bump' ); ?></th>
						<td>
							<select name="bumps[<?php echo $n; ?>][condition_type]" class="bump-condition-type">
								<option value="always"      <?php selected( $condition_type, 'always' ); ?>><?php esc_html_e( 'תמיד', 'wc-order-bump' ); ?></option>
								<option value="if_product"  <?php selected( $condition_type, 'if_product' ); ?>><?php esc_html_e( 'רק אם מוצר ספציפי בסל', 'wc-order-bump' ); ?></option>
								<option value="if_category" <?php selected( $condition_type, 'if_category' ); ?>><?php esc_html_e( 'רק אם קטגוריה ספציפית בסל', 'wc-order-bump' ); ?></option>
							</select>
							<div class="bump-condition-value-wrap" <?php echo $condition_type === 'always' ? 'style="display:none"' : ''; ?>>
								<div class="bump-condition-product-wrap" <?php echo $condition_type !== 'if_product' ? 'style="display:none"' : ''; ?>>
									<select class="wc-product-search bump-condition-product-select"
										name="bumps[<?php echo $n; ?>][condition_value]"
										style="min-width:280px;margin-top:8px"
										data-placeholder="<?php esc_attr_e( 'חפש מוצר תנאי...', 'wc-order-bump' ); ?>"
										data-action="woocommerce_json_search_products_and_variations">
										<?php if ( $cond_product ) : ?>
											<option value="<?php echo esc_attr( $condition_value ); ?>" selected>
												<?php echo esc_html( $cond_product->get_name() ); ?> (#<?php echo esc_html( $condition_value ); ?>)
											</option>
										<?php endif; ?>
									</select>
								</div>
								<div class="bump-condition-category-wrap" <?php echo $condition_type !== 'if_category' ? 'style="display:none"' : ''; ?>>
									<select name="bumps[<?php echo $n; ?>][condition_value]"
										class="bump-condition-category-select"
										style="min-width:280px;margin-top:8px">
										<option value=""><?php esc_html_e( 'בחר קטגוריה...', 'wc-order-bump' ); ?></option>
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
				<div class="bump-section-divider">
					<span><?php esc_html_e( 'עיצוב וכפתור', 'wc-order-bump' ); ?></span>
				</div>

				<table class="form-table bump-form-table">

					<!-- Button text -->
					<tr>
						<th><?php esc_html_e( 'כיתוב כפתור הוספה', 'wc-order-bump' ); ?></th>
						<td>
							<input type="text" name="bumps[<?php echo $n; ?>][button_text]"
								value="<?php echo esc_attr( $button_text ); ?>"
								placeholder="<?php esc_attr_e( 'כן, הוסיפו לי! →', 'wc-order-bump' ); ?>"
								class="regular-text">
						</td>
					</tr>

					<!-- Button remove text -->
					<tr>
						<th><?php esc_html_e( 'כיתוב כפתור הסרה', 'wc-order-bump' ); ?></th>
						<td>
							<input type="text" name="bumps[<?php echo $n; ?>][button_remove_text]"
								value="<?php echo esc_attr( $button_remove_text ); ?>"
								placeholder="<?php esc_attr_e( '✓ נוסף — לחץ להסרה', 'wc-order-bump' ); ?>"
								class="regular-text">
						</td>
					</tr>

					<!-- Colors -->
					<tr>
						<th><?php esc_html_e( 'צבעים', 'wc-order-bump' ); ?></th>
						<td>
							<div class="bump-color-grid">
								<?php
								$color_fields = [
									'bg_color'          => __( 'רקע כרטיס', 'wc-order-bump' ),
									'border_color'      => __( 'צבע גבול', 'wc-order-bump' ),
									'button_bg'         => __( 'רקע כפתור', 'wc-order-bump' ),
									'button_text_color' => __( 'טקסט כפתור', 'wc-order-bump' ),
									'badge_color'       => __( 'צבע Badge', 'wc-order-bump' ),
								];
								foreach ( $color_fields as $field => $label ) :
								?>
									<div class="bump-color-field">
										<label><?php echo esc_html( $label ); ?></label>
										<input type="text"
											class="bump-color-picker"
											name="bumps[<?php echo $n; ?>][style][<?php echo esc_attr( $field ); ?>]"
											value="<?php echo esc_attr( $style[ $field ] ); ?>"
											data-default-color="">
									</div>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>

					<!-- Custom CSS per bump -->
					<tr>
						<th><?php esc_html_e( 'CSS מותאם לכרטיס זה', 'wc-order-bump' ); ?></th>
						<td>
							<textarea name="bumps[<?php echo $n; ?>][style][custom_css]"
								rows="5" class="large-text code"
								placeholder=".order-bump-item { font-family: 'Heebo', sans-serif; }"><?php echo esc_textarea( $style['custom_css'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'CSS שיוחל על כרטיס זה בלבד. השתמש ב-.order-bump-item כ-selector.', 'wc-order-bump' ); ?>
							</p>
						</td>
					</tr>

				</table>
			</div><!-- .bump-card-body -->
		</div><!-- .bump-card -->
		<?php
	}
}
