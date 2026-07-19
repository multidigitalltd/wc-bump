<?php

defined( 'ABSPATH' ) || exit;

/**
 * Back-in-Stock notifications.
 *
 * On an out-of-stock product (or out-of-stock variation) shows a "notify me"
 * form collecting name + email + marketing consent. Subscribers are stored in a
 * dedicated table; when the product/variation returns to stock every waiting
 * customer is e-mailed automatically with a link to the product. An admin tab
 * lists subscribers and exports them to CSV.
 *
 * Research: back-in-stock notifications are among the highest-converting
 * touchpoints in e-commerce (typically 20–35% conversion) and recover otherwise
 * lost sales on sold-out variations (MarketingSherpa / Klaviyo benchmarks).
 */
class WC_Order_Upsale_Backinstock {

	const OPTION          = 'wc_store_enhancer_bis';
	const DB_VERSION_OPT  = 'wc_store_enhancer_bis_db_version';
	const DB_VERSION      = '1.2.0';
	const BATCH           = 25;

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_create_table' ] );

		add_filter( 'wc_store_enhancer_settings_tabs',              [ $this, 'register_settings_tab' ], 25 );
		add_action( 'admin_post_save_wc_store_enhancer_bis',        [ $this, 'save_settings' ] );
		add_action( 'admin_post_wc_store_enhancer_bis_export',      [ $this, 'export_csv' ] );
		add_action( 'admin_post_wc_store_enhancer_bis_delete',      [ $this, 'delete_subscriber' ] );

		// Background sender — always registered so scheduled events still fire.
		add_action( 'wcse_bis_notify', [ $this, 'process_notifications' ], 10, 2 );

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
			email VARCHAR(190) NOT NULL DEFAULT '',
			consent TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			notified_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY variation_id (variation_id),
			KEY email (email),
			KEY notified_at (notified_at),
			KEY created_at (created_at)
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
			'button_text'   => '',
			'title'         => '',
			'consent_text'  => '',
			'success_text'  => '',
			'email_subject' => '',
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
			. '.wcse-bis-msg:empty{margin:0}'
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
					<label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'אימייל', 'wc-order-upsale' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" autocomplete="email" required>
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
			<p class="wcse-bis-msg" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	/* ─────────────────────────── AJAX ───────────────────────────────── */

	public function ajax_subscribe(): void {
		check_ajax_referer( 'wcse_bis', 'nonce' );

		// Throttle the public endpoint: max 10 submissions per IP per 10 minutes.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'wcse_bis_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 10 ) {
			wp_send_json_error( [ 'message' => __( 'יותר מדי בקשות. נסו שוב מאוחר יותר.', 'wc-order-upsale' ) ] );
		}
		set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );

		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$consent = ! empty( $_POST['consent'] );
		$product = absint( $_POST['product_id'] ?? 0 );
		$variant = absint( $_POST['variation_id'] ?? 0 );

		if ( '' === $name || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'נא למלא שם וכתובת אימייל תקינה.', 'wc-order-upsale' ) ] );
		}
		if ( ! $consent ) {
			wp_send_json_error( [ 'message' => __( 'יש לאשר קבלת דיוור.', 'wc-order-upsale' ) ] );
		}
		if ( ! $product || ! wc_get_product( $product ) ) {
			wp_send_json_error( [ 'message' => __( 'מוצר לא תקין.', 'wc-order-upsale' ) ] );
		}

		global $wpdb;
		$table = self::table();

		// Skip an identical pending subscription (same email, product, variation).
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE product_id = %d AND variation_id = %d AND email = %s AND notified_at IS NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			$product,
			$variant,
			$email
		) );

		if ( ! $exists ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				[
					'product_id'   => $product,
					'variation_id' => $variant,
					'name'         => $name,
					'email'        => $email,
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

	/* ─────────────────────── Restock notification ───────────────────── */

	public function on_product_restock( $product_id, $status, $product ): void {
		if ( 'instock' === $status ) {
			$this->schedule_notifications( (int) $product_id, 0 );
		}
	}

	public function on_variation_restock( $variation_id, $status, $variation ): void {
		if ( 'instock' !== $status ) {
			return;
		}
		$parent = $variation instanceof WC_Product ? (int) $variation->get_parent_id() : 0;
		$this->schedule_notifications( $parent, (int) $variation_id );
	}

	/** Queue a background send so the restock request itself returns immediately. */
	private function schedule_notifications( int $product_id, int $variation_id ): void {
		$args = [ $product_id, $variation_id ];
		if ( ! wp_next_scheduled( 'wcse_bis_notify', $args ) ) {
			wp_schedule_single_event( time() + 30, 'wcse_bis_notify', $args );
		}
	}

	/**
	 * Cron callback: e-mail waiting customers in bounded batches, marking each
	 * processed row notified and rescheduling while more remain — so a large
	 * waiting list never blocks a single request or hits max_execution_time.
	 *
	 * @param int $product_id
	 * @param int $variation_id
	 */
	public function process_notifications( $product_id, $variation_id ): void {
		global $wpdb;
		$table        = self::table();
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;

		if ( $variation_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, email FROM {$table} WHERE variation_id = %d AND notified_at IS NULL ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$variation_id,
				self::BATCH
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, email FROM {$table} WHERE product_id = %d AND variation_id = 0 AND notified_at IS NULL ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$product_id,
				self::BATCH
			) );
		}

		if ( empty( $rows ) ) {
			return;
		}

		$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		$name    = $product ? $product->get_name() : ( '#' . $product_id );
		$url     = $product_id ? get_permalink( $product_id ) : home_url( '/' );

		/* translators: %s: product name */
		$subject = $this->text( 'email_subject', sprintf( __( '%s חזר למלאי!', 'wc-order-upsale' ), $name ) );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$processed = [];
		foreach ( $rows as $row ) {
			// Mark processed regardless of send result to avoid an infinite retry loop.
			$processed[] = (int) $row->id;

			if ( ! is_email( $row->email ) ) {
				continue;
			}

			/* translators: %s: recipient (customer) name */
			$greeting = sprintf( __( 'שלום %s,', 'wc-order-upsale' ), $row->name );
			/* translators: %s: product name */
			$line = sprintf( __( 'המוצר "%s" חזר למלאי — כדאי למהר לפני שייגמר שוב.', 'wc-order-upsale' ), $name );

			$body  = '<p>' . esc_html( $greeting ) . '</p>';
			$body .= '<p>' . esc_html( $line ) . '</p>';
			$body .= '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#111;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">'
				. esc_html__( 'לצפייה ורכישה', 'wc-order-upsale' ) . '</a></p>';

			wp_mail( $row->email, $subject, $body, $headers );
		}

		$in = implode( ',', array_fill( 0, count( $processed ), '%d' ) );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			"UPDATE {$table} SET notified_at = %s WHERE id IN ({$in})",
			array_merge( [ current_time( 'mysql' ) ], $processed )
		) );

		// A full batch means more may be waiting — process the next one shortly.
		if ( count( $rows ) >= self::BATCH ) {
			wp_schedule_single_event( time() + 60, 'wcse_bis_notify', [ $product_id, $variation_id ] );
		}
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
			'button_text'   => sanitize_text_field( wp_unslash( $_POST['button_text'] ?? '' ) ),
			'title'         => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'consent_text'  => sanitize_text_field( wp_unslash( $_POST['consent_text'] ?? '' ) ),
			'success_text'  => sanitize_text_field( wp_unslash( $_POST['success_text'] ?? '' ) ),
			'email_subject' => sanitize_text_field( wp_unslash( $_POST['email_subject'] ?? '' ) ),
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
		$rows  = $wpdb->get_results( "SELECT product_id, variation_id, name, email, consent, created_at, notified_at FROM {$table} ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=back-in-stock.csv' );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.
		$this->fputcsv_safe( $out, [ 'product_id', 'variation_id', 'name', 'email', 'consent', 'created_at', 'notified_at' ] );
		foreach ( (array) $rows as $row ) {
			$this->fputcsv_safe( $out, $row );
		}
		fclose( $out );
		exit;
	}

	/** Write a CSV row, neutralising spreadsheet formula-injection in every cell. */
	private function fputcsv_safe( $handle, array $row ): void {
		$safe = array_map( [ $this, 'csv_cell' ], $row );
		// Explicit $escape ('') opts into PHP's future no-backslash-escape default
		// and silences the PHP 8.4 deprecation for the default argument.
		fputcsv( $handle, $safe, ',', '"', '' );
	}

	/** Prefix a leading formula character so spreadsheets treat the value as text. */
	private function csv_cell( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	public function render_settings_tab(): void {
		$settings = self::get_settings();

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT id, product_id, variation_id, name, email, created_at, notified_at FROM {$table} ORDER BY created_at DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		// Warm post + meta caches for all listed products in one pass (avoids an
		// N+1 of wc_get_product() calls inside the render loop below).
		$prime_ids = [];
		foreach ( (array) $rows as $r ) {
			$prime_ids[] = (int) ( $r['variation_id'] ?: $r['product_id'] );
		}
		if ( $prime_ids ) {
			_prime_post_caches( array_values( array_unique( $prime_ids ) ), false, true );
		}
		?>
		<div class="wcse-admin">
			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'טופס "עדכנו אותי כשחוזר למלאי" (שם + אימייל + אישור דיוור) מוצג אוטומטית על מוצר/וריאציה שאזלו. כשהמוצר חוזר למלאי — נשלח מייל אוטומטי לכל הנרשמים עם קישור למוצר. אין צורך בשורטקוד.', 'wc-order-upsale' ); ?>
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
						<th scope="row"><label for="wcse-bis-subject"><?php esc_html_e( 'נושא מייל ללקוח', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="text" id="wcse-bis-subject" name="email_subject" value="<?php echo esc_attr( $settings['email_subject'] ); ?>" placeholder="<?php esc_attr_e( '{שם המוצר} חזר למלאי!', 'wc-order-upsale' ); ?>" class="large-text">
							<p class="description"><?php esc_html_e( 'נושא המייל שנשלח ללקוח כשהמוצר חוזר למלאי. אם ריק — ייווצר אוטומטית לפי שם המוצר.', 'wc-order-upsale' ); ?></p>
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
						<th><?php esc_html_e( 'אימייל', 'wc-order-upsale' ); ?></th>
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
								<td><?php echo esc_html( $row['email'] ); ?></td>
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
