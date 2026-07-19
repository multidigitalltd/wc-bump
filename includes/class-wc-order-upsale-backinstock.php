<?php

defined( 'ABSPATH' ) || exit;

/**
 * Back-in-Stock notifications.
 *
 * On an out-of-stock product (or out-of-stock variation) shows a "notify me"
 * form collecting name + phone + marketing consent. Subscribers are stored in a
 * dedicated table; when the product/variation returns to stock the shop is
 * alerted by e-mail with the waiting list so it can reach out. An admin tab
 * lists subscribers and exports them to CSV.
 *
 * Research: back-in-stock notifications are among the highest-converting
 * touchpoints in e-commerce (typically 20–35% conversion) and recover otherwise
 * lost sales on sold-out variations (MarketingSherpa / Klaviyo benchmarks).
 */
class WC_Order_Upsale_Backinstock {

	const OPTION          = 'wc_store_enhancer_bis';
	const DB_VERSION_OPT  = 'wc_store_enhancer_bis_db_version';
	const DB_VERSION      = '1.0.0';

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_create_table' ] );

		add_filter( 'wc_store_enhancer_settings_tabs',              [ $this, 'register_settings_tab' ], 25 );
		add_action( 'admin_post_save_wc_store_enhancer_bis',        [ $this, 'save_settings' ] );
		add_action( 'admin_post_wc_store_enhancer_bis_export',      [ $this, 'export_csv' ] );
		add_action( 'admin_post_wc_store_enhancer_bis_delete',      [ $this, 'delete_subscriber' ] );

		if ( $this->is_enabled() ) {
			add_action( 'wp_enqueue_scripts',                       [ $this, 'enqueue_assets' ] );
			add_action( 'woocommerce_single_product_summary',       [ $this, 'render_form' ], 35 );
			add_action( 'wp_ajax_wcse_bis_subscribe',               [ $this, 'ajax_subscribe' ] );
			add_action( 'wp_ajax_nopriv_wcse_bis_subscribe',        [ $this, 'ajax_subscribe' ] );

			add_action( 'woocommerce_product_set_stock_status',     [ $this, 'on_product_restock' ], 20, 3 );
			add_action( 'woocommerce_variation_set_stock_status',   [ $this, 'on_variation_restock' ], 20, 3 );
		}
	}

	/* ─────────────────────────── Storage ────────────────────────────── */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_store_enhancer_bis';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(40) NOT NULL DEFAULT '',
			consent TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			notified_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY variation_id (variation_id),
			KEY notified_at (notified_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPT, self::DB_VERSION );
	}

	public function maybe_create_table(): void {
		if ( get_option( self::DB_VERSION_OPT ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/* ─────────────────────────── Settings ───────────────────────────── */

	public static function get_settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [
			'button_text'  => '',
			'title'        => '',
			'consent_text' => '',
			'success_text' => '',
			'notify_email' => '',
		] );
	}

	private function is_enabled(): bool {
		return ! class_exists( 'WC_Order_Upsale_Modules' )
			|| WC_Order_Upsale_Modules::is_enabled( 'back_in_stock' );
	}

	private function text( string $key, string $default ): string {
		$value = (string) ( self::get_settings()[ $key ] ?? '' );
		return '' !== $value ? $value : $default;
	}

	private function admin_email(): string {
		$email = self::get_settings()['notify_email'];
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/* ─────────────────────────── Frontend ───────────────────────────── */

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) {
			return;
		}
		// Only load where a form can actually appear: variable products, or an
		// out-of-stock simple product.
		if ( ! $product->is_type( 'variable' ) && $product->is_in_stock() ) {
			return;
		}

		wp_enqueue_script(
			'wcse-bis',
			WC_ORDER_UPSALE_URL . 'assets/js/back-in-stock.js',
			[ 'jquery' ],
			WC_ORDER_UPSALE_VERSION,
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);
		wp_localize_script( 'wcse-bis', 'wcseBis', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wcse_bis' ),
			'i18n'    => [
				'success' => $this->text( 'success_text', __( 'תודה! נעדכן אתכם כשהמוצר יחזור למלאי.', 'wc-order-upsale' ) ),
				'error'   => __( 'אירעה שגיאה. נסו שוב.', 'wc-order-upsale' ),
			],
		] );

		wp_register_style( 'wcse-bis', false, [], WC_ORDER_UPSALE_VERSION );
		wp_enqueue_style( 'wcse-bis' );
		wp_add_inline_style( 'wcse-bis',
			'.wcse-bis{margin:14px 0;padding:14px 16px;border:1px solid #e0e0e6;border-radius:10px;font-family:inherit}'
			. '.wcse-bis-title{font-weight:700;margin:0 0 10px}'
			. '.wcse-bis-form p{margin:0 0 10px}'
			. '.wcse-bis-form label{display:block;font-size:14px}'
			. '.wcse-bis-form input[type=text],.wcse-bis-form input[type=tel]{width:100%;max-width:320px}'
			. '.wcse-bis-consent{display:flex !important;align-items:flex-start;gap:8px}'
			. '.wcse-bis-consent input{margin-top:3px}'
			. '.wcse-bis-msg{margin:8px 0 0;font-weight:600}'
		);
	}

	public function render_form(): void {
		$product = $GLOBALS['product'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$is_variable = $product->is_type( 'variable' );
		$simple_oos  = ! $is_variable && ! $product->is_in_stock();

		// Nothing to show for an in-stock simple product.
		if ( ! $is_variable && ! $simple_oos ) {
			return;
		}

		$title    = $this->text( 'title', __( 'רוצים שנעדכן כשחוזר למלאי?', 'wc-order-upsale' ) );
		$consent  = $this->text( 'consent_text', __( 'אני מאשר/ת קבלת עדכון וקבלת דיוור שיווקי.', 'wc-order-upsale' ) );
		$button   = $this->text( 'button_text', __( 'עדכנו אותי כשחוזר למלאי', 'wc-order-upsale' ) );
		$uid      = 'wcse-bis-' . $product->get_id();
		?>
		<div class="wcse-bis" <?php echo $is_variable ? 'hidden' : ''; ?>>
			<form class="wcse-bis-form" novalidate>
				<p class="wcse-bis-title"><?php echo esc_html( $title ); ?></p>
				<p>
					<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'שם', 'wc-order-upsale' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="name" autocomplete="name" required>
				</p>
				<p>
					<label for="<?php echo esc_attr( $uid ); ?>-phone"><?php esc_html_e( 'טלפון', 'wc-order-upsale' ); ?></label>
					<input type="tel" id="<?php echo esc_attr( $uid ); ?>-phone" name="phone" autocomplete="tel" required>
				</p>
				<p>
					<label class="wcse-bis-consent">
						<input type="checkbox" name="consent" value="1" required>
						<span><?php echo esc_html( $consent ); ?></span>
					</label>
				</p>
				<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product->get_id() ); ?>">
				<input type="hidden" name="variation_id" value="0">
				<button type="submit" class="button"><?php echo esc_html( $button ); ?></button>
			</form>
			<p class="wcse-bis-msg" role="status" aria-live="polite" style="display:none"></p>
		</div>
		<?php
	}

	/* ─────────────────────────── AJAX ───────────────────────────────── */

	public function ajax_subscribe(): void {
		check_ajax_referer( 'wcse_bis', 'nonce' );

		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$phone   = $this->sanitize_phone( wp_unslash( $_POST['phone'] ?? '' ) );
		$consent = ! empty( $_POST['consent'] );
		$product = absint( $_POST['product_id'] ?? 0 );
		$variant = absint( $_POST['variation_id'] ?? 0 );

		if ( '' === $name || '' === $phone ) {
			wp_send_json_error( [ 'message' => __( 'נא למלא שם וטלפון.', 'wc-order-upsale' ) ] );
		}
		if ( ! $consent ) {
			wp_send_json_error( [ 'message' => __( 'יש לאשר קבלת דיוור.', 'wc-order-upsale' ) ] );
		}
		if ( ! $product || ! wc_get_product( $product ) ) {
			wp_send_json_error( [ 'message' => __( 'מוצר לא תקין.', 'wc-order-upsale' ) ] );
		}

		global $wpdb;
		$table = self::table();

		// Skip an identical pending subscription (same phone, product, variation).
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE product_id = %d AND variation_id = %d AND phone = %s AND notified_at IS NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			$product,
			$variant,
			$phone
		) );

		if ( ! $exists ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				[
					'product_id'   => $product,
					'variation_id' => $variant,
					'name'         => $name,
					'phone'        => $phone,
					'consent'      => 1,
					'created_at'   => current_time( 'mysql' ),
				],
				[ '%d', '%d', '%s', '%s', '%d', '%s' ]
			);
		}

		wp_send_json_success( [
			'message' => $this->text( 'success_text', __( 'תודה! נעדכן אתכם כשהמוצר יחזור למלאי.', 'wc-order-upsale' ) ),
		] );
	}

	private function sanitize_phone( string $phone ): string {
		return trim( (string) preg_replace( '/[^0-9+\-() ]/', '', $phone ) );
	}

	/* ─────────────────────── Restock notification ───────────────────── */

	public function on_product_restock( $product_id, $status, $product ): void {
		if ( 'instock' === $status ) {
			$this->notify_waiting( (int) $product_id, 0 );
		}
	}

	public function on_variation_restock( $variation_id, $status, $variation ): void {
		if ( 'instock' !== $status ) {
			return;
		}
		$parent = $variation instanceof WC_Product ? (int) $variation->get_parent_id() : 0;
		$this->notify_waiting( $parent, (int) $variation_id );
	}

	/**
	 * Alert the shop that a restocked item has people waiting, then mark those
	 * rows notified so the alert is not repeated.
	 */
	private function notify_waiting( int $product_id, int $variation_id ): void {
		global $wpdb;
		$table = self::table();

		if ( $variation_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, phone FROM {$table} WHERE variation_id = %d AND notified_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL
				$variation_id
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, phone FROM {$table} WHERE product_id = %d AND variation_id = 0 AND notified_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL
				$product_id
			) );
		}

		if ( empty( $rows ) ) {
			return;
		}

		$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		$name    = $product ? $product->get_name() : ( '#' . $product_id );
		$edit    = $product_id ? get_edit_post_link( $product_id, '' ) : '';

		$lines = [];
		foreach ( $rows as $row ) {
			$lines[] = sprintf( '• %s — %s', $row->name, $row->phone );
		}

		$subject = sprintf(
			/* translators: %s: product name */
			__( '[משפר המרות] %s חזר למלאי — לקוחות ממתינים', 'wc-order-upsale' ),
			$name
		);
		$body = sprintf(
			/* translators: 1: count, 2: product name */
			__( '%1$d לקוחות ביקשו עדכון על "%2$s" שחזר עכשיו למלאי:', 'wc-order-upsale' ),
			count( $rows ),
			$name
		) . "\n\n" . implode( "\n", $lines );
		if ( $edit ) {
			$body .= "\n\n" . __( 'עריכת המוצר:', 'wc-order-upsale' ) . ' ' . $edit;
		}

		wp_mail( $this->admin_email(), $subject, $body );

		$ids = array_map( static fn( $r ) => (int) $r->id, $rows );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			"UPDATE {$table} SET notified_at = %s WHERE id IN ({$in})",
			array_merge( [ current_time( 'mysql' ) ], $ids )
		) );
	}

	/* ─────────────────────────── Admin tab ──────────────────────────── */

	public function register_settings_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'backinstock',
			'label'    => __( 'התראות מלאי', 'wc-order-upsale' ),
			'callback' => [ $this, 'render_settings_tab' ],
		];
		return $tabs;
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_bis_save', 'wc_store_enhancer_bis_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		update_option( self::OPTION, [
			'button_text'  => sanitize_text_field( wp_unslash( $_POST['button_text'] ?? '' ) ),
			'title'        => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'consent_text' => sanitize_text_field( wp_unslash( $_POST['consent_text'] ?? '' ) ),
			'success_text' => sanitize_text_field( wp_unslash( $_POST['success_text'] ?? '' ) ),
			'notify_email' => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=backinstock&saved=1' ) );
		exit;
	}

	public function delete_subscriber(): void {
		if ( ! check_admin_referer( 'wcse_bis_delete' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}
		global $wpdb;
		$id = absint( $_GET['id'] ?? 0 );
		if ( $id ) {
			$wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=backinstock&deleted=1' ) );
		exit;
	}

	public function export_csv(): void {
		if ( ! check_admin_referer( 'wcse_bis_export' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT product_id, variation_id, name, phone, consent, created_at, notified_at FROM {$table} ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=back-in-stock.csv' );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.
		fputcsv( $out, [ 'product_id', 'variation_id', 'name', 'phone', 'consent', 'created_at', 'notified_at' ] );
		foreach ( (array) $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	public function render_settings_tab(): void {
		$settings = self::get_settings();

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT id, product_id, variation_id, name, phone, created_at, notified_at FROM {$table} ORDER BY created_at DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		?>
		<div class="wcse-admin">
			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'טופס "עדכנו אותי כשחוזר למלאי" מוצג אוטומטית על מוצר/וריאציה שאזלו. כשהמוצר חוזר למלאי — נשלח מייל לחנות עם רשימת הממתינים.', 'wc-order-upsale' ); ?>
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
			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'הנרשם נמחק.', 'wc-order-upsale' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_store_enhancer_bis_save', 'wc_store_enhancer_bis_nonce' ); ?>
				<input type="hidden" name="action" value="save_wc_store_enhancer_bis">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcse-bis-title"><?php esc_html_e( 'כותרת הטופס', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-bis-title" name="title" value="<?php echo esc_attr( $settings['title'] ); ?>" placeholder="<?php esc_attr_e( 'רוצים שנעדכן כשחוזר למלאי?', 'wc-order-upsale' ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-bis-button"><?php esc_html_e( 'כיתוב כפתור', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-bis-button" name="button_text" value="<?php echo esc_attr( $settings['button_text'] ); ?>" placeholder="<?php esc_attr_e( 'עדכנו אותי כשחוזר למלאי', 'wc-order-upsale' ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-bis-consent"><?php esc_html_e( 'טקסט אישור דיוור', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-bis-consent" name="consent_text" value="<?php echo esc_attr( $settings['consent_text'] ); ?>" placeholder="<?php esc_attr_e( 'אני מאשר/ת קבלת עדכון וקבלת דיוור שיווקי.', 'wc-order-upsale' ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-bis-success"><?php esc_html_e( 'הודעת הצלחה', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-bis-success" name="success_text" value="<?php echo esc_attr( $settings['success_text'] ); ?>" placeholder="<?php esc_attr_e( 'תודה! נעדכן אתכם כשהמוצר יחזור למלאי.', 'wc-order-upsale' ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-bis-email"><?php esc_html_e( 'מייל לקבלת התראות', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="email" id="wcse-bis-email" name="notify_email" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'לכתובת זו יישלח מייל עם רשימת הממתינים כשמוצר חוזר למלאי. ברירת מחדל: מייל המנהל.', 'wc-order-upsale' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'שמור הגדרות', 'wc-order-upsale' ); ?></button></p>
			</form>

			<hr>
			<h2><?php esc_html_e( 'נרשמים', 'wc-order-upsale' ); ?> (<?php echo esc_html( number_format_i18n( $total ) ); ?>)</h2>

			<?php if ( $total > 0 ) : ?>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wc_store_enhancer_bis_export' ), 'wcse_bis_export' ) ); ?>">
						<?php esc_html_e( 'ייצוא ל-CSV', 'wc-order-upsale' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'מוצר', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'שם', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'טלפון', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'תאריך', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'סטטוס', 'wc-order-upsale' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'אין עדיין נרשמים.', 'wc-order-upsale' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$pid  = (int) ( $row['variation_id'] ?: $row['product_id'] );
							$prod = wc_get_product( $pid );
							$pname = $prod ? $prod->get_name() : ( '#' . $pid );
							?>
							<tr>
								<td><?php echo esc_html( $pname ); ?> <span class="description">#<?php echo esc_html( (string) $pid ); ?></span></td>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo esc_html( $row['phone'] ); ?></td>
								<td><?php echo esc_html( $row['created_at'] ); ?></td>
								<td>
									<?php echo $row['notified_at']
										? '<span style="color:#125c2b">' . esc_html__( 'עודכן/טופל', 'wc-order-upsale' ) . '</span>'
										: '<span style="color:#7a5c00">' . esc_html__( 'ממתין', 'wc-order-upsale' ) . '</span>'; ?>
								</td>
								<td>
									<a class="button button-small" style="color:#b32d2e"
										href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wc_store_enhancer_bis_delete&id=' . (int) $row['id'] ), 'wcse_bis_delete' ) ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'למחוק את הנרשם?', 'wc-order-upsale' ) ); ?>');">
										<?php esc_html_e( 'מחק', 'wc-order-upsale' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $total > count( (array) $rows ) ) : ?>
				<p class="description"><?php esc_html_e( 'מוצגים 200 האחרונים. לרשימה המלאה השתמשו בייצוא ל-CSV.', 'wc-order-upsale' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
