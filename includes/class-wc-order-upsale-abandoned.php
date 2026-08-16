<?php

defined( 'ABSPATH' ) || exit;

/**
 * Abandoned Cart recovery.
 *
 * When a shopper reaches checkout and enters their e-mail but doesn't complete
 * the order, the cart (items + e-mail) is captured. A cron job scans for carts
 * idle longer than the configured delay and e-mails the shopper a one-click
 * recovery link that restores their cart. Completing an order (or an unsubscribe)
 * stops further mail; recovered orders are attributed for reporting.
 *
 * Research: ~75% of carts are abandoned; recovery e-mails convert ~10% and top
 * stores recover 10–14% of carts — the first mail within ~1h captures peak
 * intent (Baymard / Rejoiner / Sendtric benchmarks).
 */
class WC_Order_Upsale_Abandoned {

	const OPTION         = 'wc_store_enhancer_abandoned';
	const DB_VERSION_OPT = 'wc_store_enhancer_abandoned_db_version';
	const DB_VERSION     = '1.0.0';
	const SESSION_KEY    = 'wcse_ab_id';
	const CRON_HOOK      = 'wcse_ab_scan';
	const BATCH          = 25;

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_create_table' ] );
		add_filter( 'cron_schedules', [ $this, 'cron_interval' ] );
		add_action( self::CRON_HOOK, [ $this, 'scan' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );

		// Recovery link works regardless of the module toggle so old links resolve.
		add_action( 'template_redirect', [ $this, 'maybe_recover' ] );

		add_filter( 'wc_store_enhancer_settings_tabs',              [ $this, 'register_settings_tab' ], 27 );
		add_action( 'admin_post_save_wc_store_enhancer_abandoned',  [ $this, 'save_settings' ] );
		add_action( 'admin_post_wc_store_enhancer_ab_export',       [ $this, 'export_csv' ] );

		if ( $this->is_enabled() ) {
			add_action( 'woocommerce_checkout_update_order_review', [ $this, 'capture' ] );
			add_action( 'woocommerce_checkout_order_processed',     [ $this, 'on_order' ], 10, 1 );
			add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_order_object' ], 10, 1 );
		}
	}

	/* ─────────────────────────── Storage ────────────────────────────── */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_store_enhancer_abandoned';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL DEFAULT '',
			name VARCHAR(190) NOT NULL DEFAULT '',
			cart_contents LONGTEXT NULL,
			cart_total DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT '',
			token VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			emailed_at DATETIME NULL DEFAULT NULL,
			recovered_at DATETIME NULL DEFAULT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY status (status),
			KEY updated_at (updated_at),
			KEY token (token)
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
			'delay_minutes' => 60,
			'subject'       => '',
			'heading'       => '',
			'body'          => '',
			'button_text'   => '',
		] );
	}

	private function is_enabled(): bool {
		return ! class_exists( 'WC_Order_Upsale_Modules' )
			|| WC_Order_Upsale_Modules::is_enabled( 'abandoned_cart' );
	}

	private function text( string $key, string $default ): string {
		$value = (string) ( self::get_settings()[ $key ] ?? '' );
		return '' !== $value ? $value : $default;
	}

	/* ─────────────────────── Cron scheduling ────────────────────────── */

	public function cron_interval( array $schedules ): array {
		if ( ! isset( $schedules['wcse_15min'] ) ) {
			$schedules['wcse_15min'] = [
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'כל 15 דקות (משפר המרות)', 'wc-order-upsale' ),
			];
		}
		return $schedules;
	}

	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'wcse_15min', self::CRON_HOOK );
		}
	}

	/* ─────────────────────────── Capture ────────────────────────────── */

	/**
	 * Capture the cart + e-mail during checkout (fires on the checkout AJAX
	 * refresh, so the billing e-mail is available for guests and members alike).
	 *
	 * @param string $posted URL-encoded checkout form data.
	 */
	public function capture( $posted ): void {
		if ( ! is_string( $posted ) || null === WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$data = [];
		parse_str( $posted, $data );

		$email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : '';
		if ( ! is_email( $email ) ) {
			return;
		}

		$name = trim(
			sanitize_text_field( $data['billing_first_name'] ?? '' ) . ' ' .
			sanitize_text_field( $data['billing_last_name'] ?? '' )
		);

		$this->upsert( $email, $name );
	}

	private function upsert( string $email, string $name ): void {
		global $wpdb;
		$table = self::table();
		$cart  = WC()->cart;

		$items = [];
		foreach ( $cart->get_cart() as $item ) {
			$items[] = [
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				'quantity'     => (int) $item['quantity'],
			];
		}

		$now  = current_time( 'mysql' );
		$data = [
			'email'         => $email,
			'name'          => $name,
			'cart_contents' => wp_json_encode( $items ),
			'cart_total'    => (float) $cart->get_total( 'edit' ),
			'currency'      => get_woocommerce_currency(),
			'updated_at'    => $now,
		];

		$row_id = $this->session_row_id();
		if ( $row_id ) {
			$wpdb->update( $table, $data, [ 'id' => $row_id ], [ '%s', '%s', '%s', '%f', '%s', '%s' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$data['token']      = wp_generate_password( 32, false );
		$data['status']     = 'pending';
		$data['created_at'] = $now;
		$wpdb->insert( $table, $data, [ '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $wpdb->insert_id && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, (int) $wpdb->insert_id );
		}
	}

	private function session_row_id(): int {
		return WC()->session ? (int) WC()->session->get( self::SESSION_KEY ) : 0;
	}

	/* ─────────────────────── Sending (cron) ─────────────────────────── */

	public function scan(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		global $wpdb;
		$table = self::table();

		$delay = max( 5, (int) self::get_settings()['delay_minutes'] );
		// updated_at is stored in site time (current_time('mysql')); compare in kind.
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - ( $delay * MINUTE_IN_SECONDS ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, email, name, cart_contents, token FROM {$table}
			 WHERE status = 'pending' AND emailed_at IS NULL AND updated_at <= %s
			 ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$cutoff,
			self::BATCH
		) );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$this->send_recovery( $row );
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				[ 'status' => 'emailed', 'emailed_at' => current_time( 'mysql' ) ],
				[ 'id' => (int) $row->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}
	}

	private function send_recovery( $row ): void {
		if ( ! is_email( $row->email ) ) {
			return;
		}

		$items = json_decode( (string) $row->cart_contents, true );
		$list  = '';
		if ( is_array( $items ) ) {
			foreach ( $items as $it ) {
				$product = wc_get_product( (int) ( $it['variation_id'] ?: $it['product_id'] ) );
				if ( ! $product ) {
					continue;
				}
				$list .= '<tr><td style="padding:6px 0">' . wp_kses_post( $product->get_image( [ 48, 48 ] ) ) . '</td>'
					. '<td style="padding:6px 10px">' . esc_html( $product->get_name() ) . ' × ' . (int) $it['quantity'] . '</td></tr>';
			}
		}

		$url     = add_query_arg( 'wcse_recover', rawurlencode( $row->token ), home_url( '/' ) );
		$heading = $this->text( 'heading', __( 'שכחתם משהו בסל?', 'wc-order-upsale' ) );
		$body    = $this->text( 'body', __( 'הפריטים שבחרתם עדיין ממתינים לכם. לחצו כדי לחזור לסל ולהשלים את ההזמנה.', 'wc-order-upsale' ) );
		$button  = $this->text( 'button_text', __( 'חזרה לסל', 'wc-order-upsale' ) );
		/* translators: %s: shop name */
		$subject = $this->text( 'subject', sprintf( __( 'שכחתם משהו בסל ב-%s', 'wc-order-upsale' ), get_bloginfo( 'name' ) ) );

		$html  = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto">';
		$html .= '<h2 style="margin:0 0 10px">' . esc_html( $heading ) . '</h2>';
		$html .= '<p style="margin:0 0 14px;color:#333">' . esc_html( $body ) . '</p>';
		if ( $list ) {
			$html .= '<table style="width:100%;border-collapse:collapse;margin:0 0 16px">' . $list . '</table>';
		}
		$html .= '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#111;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:700">'
			. esc_html( $button ) . '</a></p>';
		$html .= '</div>';

		wp_mail( $row->email, $subject, $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	/* ─────────────────────── Recovery + conversion ──────────────────── */

	public function maybe_recover(): void {
		if ( empty( $_GET['wcse_recover'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['wcse_recover'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token || null === WC()->cart ) {
			return;
		}

		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, cart_contents FROM {$table} WHERE token = %s AND status <> 'recovered' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			$token
		) );

		if ( ! $row ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		$items = json_decode( (string) $row->cart_contents, true );
		if ( is_array( $items ) ) {
			WC()->cart->empty_cart();
			foreach ( $items as $it ) {
				WC()->cart->add_to_cart( (int) $it['product_id'], max( 1, (int) $it['quantity'] ), (int) ( $it['variation_id'] ?? 0 ) );
			}
		}

		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, (int) $row->id );
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	public function on_order( $order_id ): void {
		$this->mark_recovered( (int) $order_id );
	}

	/** Store API (block checkout) passes the order object. */
	public function on_order_object( $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->mark_recovered( $order->get_id() );
		}
	}

	private function mark_recovered( int $order_id ): void {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$row_id = $this->session_row_id();
		if ( $row_id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				[ 'status' => 'recovered', 'recovered_at' => $now, 'order_id' => $order_id ],
				[ 'id' => $row_id ],
				[ '%s', '%s', '%d' ],
				[ '%d' ]
			);
			if ( WC()->session ) {
				WC()->session->set( self::SESSION_KEY, null );
			}
			return;
		}

		// Fallback: attribute by the order's e-mail to the latest open cart.
		$order = wc_get_order( $order_id );
		$email = $order ? $order->get_billing_email() : '';
		if ( is_email( $email ) ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
				"UPDATE {$table} SET status = 'recovered', recovered_at = %s, order_id = %d
				 WHERE email = %s AND status IN ('pending','emailed') ORDER BY updated_at DESC LIMIT 1",
				$now,
				$order_id,
				$email
			) );
		}
	}

	/* ─────────────────────────── Admin tab ──────────────────────────── */

	public function register_settings_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'abandoned',
			'label'    => __( 'עגלות נטושות', 'wc-order-upsale' ),
			'callback' => [ $this, 'render_settings_tab' ],
		];
		return $tabs;
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_abandoned_save', 'wc_store_enhancer_abandoned_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		update_option( self::OPTION, [
			'delay_minutes' => max( 5, min( 10080, absint( $_POST['delay_minutes'] ?? 60 ) ) ),
			'subject'       => sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ),
			'heading'       => sanitize_text_field( wp_unslash( $_POST['heading'] ?? '' ) ),
			'body'          => sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ),
			'button_text'   => sanitize_text_field( wp_unslash( $_POST['button_text'] ?? '' ) ),
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=abandoned&saved=1' ) );
		exit;
	}

	public function export_csv(): void {
		if ( ! check_admin_referer( 'wcse_ab_export' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT email, name, cart_total, currency, status, created_at, recovered_at, order_id FROM {$table} ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=abandoned-carts.csv' );
		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" );
		$this->fputcsv_safe( $out, [ 'email', 'name', 'cart_total', 'currency', 'status', 'created_at', 'recovered_at', 'order_id' ] );
		foreach ( (array) $rows as $row ) {
			$this->fputcsv_safe( $out, $row );
		}
		fclose( $out );
		exit;
	}

	private function fputcsv_safe( $handle, array $row ): void {
		$safe = array_map(
			static function ( $value ) {
				$value = (string) $value;
				if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
					$value = "'" . $value;
				}
				return $value;
			},
			$row
		);
		fputcsv( $handle, $safe, ',', '"', '' );
	}

	/** Capture/send/recover counts, shared by this tab and the dashboard. */
	public static function stats(): array {
		global $wpdb;
		$table = self::table();

		return [
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			'emailed'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'emailed'" ), // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			'sent'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('emailed','recovered')" ), // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			'recovered' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'recovered'" ), // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			'revenue'   => (float) $wpdb->get_var( "SELECT COALESCE(SUM(cart_total),0) FROM {$table} WHERE status = 'recovered'" ), // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		];
	}

	public function render_settings_tab(): void {
		$settings = self::get_settings();

		global $wpdb;
		$table = self::table();
		$stats = self::stats();
		$rows = $wpdb->get_results( "SELECT id, email, name, cart_total, currency, status, created_at FROM {$table} ORDER BY created_at DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		?>
		<div class="wcse-admin">
			<p class="description" style="max-width:760px">
				<?php esc_html_e( 'לוכד עגלות של לקוחות שהזינו אימייל בצ׳קאאוט אך לא סיימו, ושולח מייל שחזור אוטומטי עם קישור לשחזור הסל. השלמת הזמנה עוצרת את המיילים.', 'wc-order-upsale' ); ?>
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

			<p>
				<strong><?php esc_html_e( 'נלכדו:', 'wc-order-upsale' ); ?></strong> <?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?> &nbsp;|&nbsp;
				<strong><?php esc_html_e( 'נשלח מייל:', 'wc-order-upsale' ); ?></strong> <?php echo esc_html( number_format_i18n( $stats['emailed'] ) ); ?> &nbsp;|&nbsp;
				<strong><?php esc_html_e( 'שוחזרו:', 'wc-order-upsale' ); ?></strong> <?php echo esc_html( number_format_i18n( $stats['recovered'] ) ); ?> &nbsp;|&nbsp;
				<strong><?php esc_html_e( 'הכנסה משוחזרת:', 'wc-order-upsale' ); ?></strong> <?php echo wp_kses_post( wc_price( $stats['revenue'] ) ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_store_enhancer_abandoned_save', 'wc_store_enhancer_abandoned_nonce' ); ?>
				<input type="hidden" name="action" value="save_wc_store_enhancer_abandoned">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcse-ab-delay"><?php esc_html_e( 'שליחה אחרי (דקות)', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="number" id="wcse-ab-delay" name="delay_minutes" value="<?php echo esc_attr( (string) $settings['delay_minutes'] ); ?>" min="5" max="10080" style="width:100px">
							<p class="description"><?php esc_html_e( 'זמן ההמתנה מרגע נטישת הצ׳קאאוט ועד שליחת המייל. מומלץ ~60 דקות.', 'wc-order-upsale' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-ab-subject"><?php esc_html_e( 'נושא המייל', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-ab-subject" name="subject" value="<?php echo esc_attr( $settings['subject'] ); ?>" placeholder="<?php esc_attr_e( 'שכחתם משהו בסל', 'wc-order-upsale' ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-ab-heading"><?php esc_html_e( 'כותרת במייל', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-ab-heading" name="heading" value="<?php echo esc_attr( $settings['heading'] ); ?>" placeholder="<?php esc_attr_e( 'שכחתם משהו בסל?', 'wc-order-upsale' ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-ab-body"><?php esc_html_e( 'טקסט', 'wc-order-upsale' ); ?></label></th>
						<td><textarea id="wcse-ab-body" name="body" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'הפריטים שבחרתם עדיין ממתינים לכם.', 'wc-order-upsale' ); ?>"><?php echo esc_textarea( $settings['body'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-ab-btn"><?php esc_html_e( 'כיתוב כפתור', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-ab-btn" name="button_text" value="<?php echo esc_attr( $settings['button_text'] ); ?>" placeholder="<?php esc_attr_e( 'חזרה לסל', 'wc-order-upsale' ); ?>" class="regular-text"></td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'שמור הגדרות', 'wc-order-upsale' ); ?></button></p>
			</form>

			<hr>
			<h2><?php esc_html_e( 'עגלות אחרונות', 'wc-order-upsale' ); ?></h2>
			<?php if ( $stats['total'] > 0 ) : ?>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wc_store_enhancer_ab_export' ), 'wcse_ab_export' ) ); ?>"><?php esc_html_e( 'ייצוא ל-CSV', 'wc-order-upsale' ); ?></a></p>
			<?php endif; ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'אימייל', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'שם', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'סכום', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'סטטוס', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'תאריך', 'wc-order-upsale' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'אין עדיין עגלות נטושות.', 'wc-order-upsale' ); ?></td></tr>
					<?php else : ?>
						<?php
						$labels = [
							'pending'   => __( 'ממתין', 'wc-order-upsale' ),
							'emailed'   => __( 'נשלח מייל', 'wc-order-upsale' ),
							'recovered' => __( 'שוחזר', 'wc-order-upsale' ),
						];
						foreach ( $rows as $row ) :
							?>
							<tr>
								<td><?php echo esc_html( $row['email'] ); ?></td>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo wp_kses_post( wc_price( (float) $row['cart_total'], [ 'currency' => $row['currency'] ] ) ); ?></td>
								<td><?php echo esc_html( $labels[ $row['status'] ] ?? $row['status'] ); ?></td>
								<td><?php echo esc_html( $row['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
