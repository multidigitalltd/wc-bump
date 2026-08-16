<?php

defined( 'ABSPATH' ) || exit;

/**
 * Back-in-Stock notifications.
 *
 * On an out-of-stock product (or out-of-stock variation) shows a "notify me"
 * form collecting name + email + consent to a single restock notice. Subscribers are stored in a
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
	const DB_VERSION      = '1.4.0';
	const BATCH           = 25;
	/** Give up on an address after this many failed sends, so a dead mailer cannot loop forever. */
	const MAX_ATTEMPTS    = 3;

	/** Collects the reason wp_mail() failed, captured from the wp_mail_failed action. */
	private string $last_mail_error = '';

	/** Set once the form has been output, so the fallbacks never double it up. */
	private bool $form_rendered = false;

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_create_table' ] );

		add_filter( 'wc_store_enhancer_settings_tabs',              [ $this, 'register_settings_tab' ], 25 );
		add_action( 'admin_post_save_wc_store_enhancer_bis',        [ $this, 'save_settings' ] );
		add_action( 'admin_post_wc_store_enhancer_bis_export',      [ $this, 'export_csv' ] );
		add_action( 'admin_post_wc_store_enhancer_bis_delete',      [ $this, 'delete_subscriber' ] );
		add_action( 'admin_post_wc_store_enhancer_bis_test',        [ $this, 'send_test_email' ] );

		// Background sender — always registered so scheduled events still fire.
		add_action( 'wcse_bis_notify', [ $this, 'process_notifications' ], 10, 2 );

		// Registered unconditionally, and gated inside instead. A shortcode that
		// always resolves means seeing "[wcse_back_in_stock]" printed as plain text
		// can only mean the running code predates it — which is worth being able
		// to tell apart from the module simply having nothing to show.
		add_shortcode( 'wcse_back_in_stock', [ $this, 'shortcode' ] );

		if ( $this->is_enabled() ) {
			add_action( 'wp_enqueue_scripts',                       [ $this, 'enqueue_assets' ] );
			// Themes and page builders that rebuild the product template often fire
			// only some of WooCommerce's hooks — and one that fires none of them
			// would hide the form completely. Offer several routes and let the
			// render-once guard keep exactly one of them.
			add_action( 'woocommerce_single_product_summary',       [ $this, 'render_form' ], 35 );
			add_action( 'woocommerce_after_single_product_summary', [ $this, 'render_form' ], 5 );
			add_action( 'wp_footer',                                [ $this, 'inject_fallback' ], 20 );
			add_action( 'wp_ajax_wcse_bis_subscribe',               [ $this, 'ajax_subscribe' ] );
			add_action( 'wp_ajax_nopriv_wcse_bis_subscribe',        [ $this, 'ajax_subscribe' ] );

			add_action( 'woocommerce_product_set_stock_status',     [ $this, 'on_product_restock' ], 20, 3 );
			add_action( 'woocommerce_variation_set_stock_status',   [ $this, 'on_variation_restock' ], 20, 3 );

			// WooCommerce strips sold-out variations out of the product page's
			// variation data when "hide out of stock items" is on, so the shopper
			// can never select one and no back-in-stock form can ever appear.
			// Suspend that setting for the add-to-cart block only — the catalog,
			// search and every other query keep the store's own behaviour.
			add_action( 'woocommerce_single_product_summary',       [ $this, 'show_sold_out_variations' ], 29 );
			add_action( 'woocommerce_single_product_summary',       [ $this, 'restore_hidden_variations' ], 31 );
		}
	}

	/* ───────────── Sold-out variations on the product page ──────────── */

	/** Whether sold-out variations should be selectable on the product page. */
	private function shows_sold_out_variations(): bool {
		return '' !== (string) ( self::get_settings()['show_oos_variations'] ?? '1' );
	}

	public function show_sold_out_variations(): void {
		if ( $this->shows_sold_out_variations() ) {
			add_filter( 'pre_option_woocommerce_hide_out_of_stock_items', [ $this, 'force_no' ], 99 );
		}
	}

	public function restore_hidden_variations(): void {
		remove_filter( 'pre_option_woocommerce_hide_out_of_stock_items', [ $this, 'force_no' ], 99 );
	}

	public function force_no( $value ) {
		return 'no';
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
			consent_2 TINYINT(1) NOT NULL DEFAULT 0,
			consent_3 TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			notified_at DATETIME NULL DEFAULT NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(190) NOT NULL DEFAULT '',
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
			'consent_2_text'     => '',
			'consent_2_required' => 1,
			'consent_3_text'     => '',
			'consent_3_required' => 1,
			'success_text'  => '',
			'email_subject' => '',
			'email_body'    => '',
			'email_button'  => '',
			// Empty string means off; '1' (the default) means on.
			'show_oos_variations' => '1',
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

	/**
	 * The consent checkboxes to show, in order.
	 *
	 * The first is the core one and is always present and always required — it is
	 * what makes the sign-up itself legitimate. The other two appear only once a
	 * shop gives them wording, and each carries its own required flag. One list
	 * so the form, the validation and the stored record cannot drift apart.
	 *
	 * @return array<int,array{key:string,column:string,text:string,required:bool}>
	 */
	private function consent_boxes(): array {
		$settings = self::get_settings();

		$boxes = [
			[
				'key'      => 'consent',
				'column'   => 'consent',
				'text'     => $this->text( 'consent_text', __( 'אני מאשר/ת קבלת עדכון חד-פעמי כשהמוצר יחזור למלאי.', 'wc-order-upsale' ) ),
				'required' => true,
			],
		];

		foreach ( [ 2, 3 ] as $n ) {
			$text = trim( (string) ( $settings[ "consent_{$n}_text" ] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$boxes[] = [
				'key'      => "consent_{$n}",
				'column'   => "consent_{$n}",
				'text'     => $text,
				'required' => ! empty( $settings[ "consent_{$n}_required" ] ),
			];
		}

		return $boxes;
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
				'expired' => __( 'פג תוקף העמוד. רעננו את הדף ונסו שוב.', 'wc-order-upsale' ),
				'consent' => __( 'יש לסמן את כל האישורים המסומנים בכוכבית.', 'wc-order-upsale' ),
			],
		] );

		wp_register_style( 'wcse-bis', false, [], WC_ORDER_UPSALE_VERSION );
		wp_enqueue_style( 'wcse-bis' );
		// Layout only. No border, background, colour, font or size of our own, so
		// the theme's own typography and input styling come through untouched and
		// the form reads as part of the site rather than as a plugin's box.
		wp_add_inline_style( 'wcse-bis',
			'.wcse-bis{margin:16px 0}'
			. '.wcse-bis-title{font-weight:600;margin:0 0 10px}'
			. '.wcse-bis-form p{margin:0 0 10px}'
			. '.wcse-bis-form label{display:block}'
			. '.wcse-bis-form input[type=text],.wcse-bis-form input[type=email]{width:100%;max-width:340px}'
			. '.wcse-bis-consent{display:flex !important;align-items:flex-start;gap:8px}'
			. '.wcse-bis-consent input{margin-top:.3em;width:auto;max-width:none}'
			. '.wcse-bis-required{color:#b32d2e}'
			. '.wcse-bis-msg{margin:10px 0 0}'
			. '.wcse-bis-msg:empty{display:none;margin:0}'
		);
	}

	/** [wcse_back_in_stock] — manual placement for a custom or page-builder template. */
	public function shortcode(): string {
		if ( ! $this->is_enabled() ) {
			return '';
		}
		ob_start();
		$this->render_form();
		return (string) ob_get_clean();
	}

	/**
	 * Last resort for templates that fire none of the product hooks: emit the
	 * form hidden in the footer and let a few lines of JS move it next to the
	 * add-to-cart area. Without this such a theme simply never shows the form.
	 */
	public function inject_fallback(): void {
		if ( $this->form_rendered || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$html = $this->shortcode();
		if ( '' === trim( $html ) ) {
			return;
		}
		?>
		<div id="wcse-bis-payload" style="display:none"><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<script>
		(function () {
			var src = document.getElementById( 'wcse-bis-payload' );
			if ( ! src ) { return; }
			var targets = [
				'form.variations_form', 'form.cart', '.summary', '.entry-summary',
				'.elementor-widget-woocommerce-product-add-to-cart', '.product'
			];
			for ( var i = 0; i < targets.length; i++ ) {
				var el = document.querySelector( targets[ i ] );
				if ( el ) {
					src.style.display = '';
					el.parentNode.insertBefore( src, el.nextSibling );
					return;
				}
			}
		})();
		</script>
		<?php
	}

	public function render_form(): void {
		if ( $this->form_rendered ) {
			return;
		}

		// The global is only set inside the loop, so fall back to the queried
		// product for footer and shortcode rendering.
		$product = $GLOBALS['product'] ?? null;
		if ( ! $product instanceof WC_Product && function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
		}
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$is_variable = $product->is_type( 'variable' );
		$simple_oos  = ! $is_variable && ! $product->is_in_stock();

		// Nothing to show for an in-stock simple product.
		if ( ! $is_variable && ! $simple_oos ) {
			return;
		}

		// A variable product with nothing left in stock has no variation for the
		// shopper to pick, so waiting for a variation event would hide the form
		// forever. Show it straight away and collect against the parent.
		$variable_sold_out = $is_variable && ! $product->is_in_stock();
		$start_hidden      = $is_variable && ! $variable_sold_out;

		$title    = $this->text( 'title', __( 'רוצים שנעדכן כשחוזר למלאי?', 'wc-order-upsale' ) );
		$consents = $this->consent_boxes();
		$button   = $this->text( 'button_text', __( 'עדכנו אותי כשחוזר למלאי', 'wc-order-upsale' ) );
		$uid      = 'wcse-bis-' . $product->get_id();
		?>
		<div class="wcse-bis" <?php echo $start_hidden ? 'hidden' : ''; ?>>
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
				<?php foreach ( $consents as $box ) : ?>
					<p>
						<label class="wcse-bis-consent">
							<input type="checkbox"
								name="<?php echo esc_attr( $box['key'] ); ?>"
								value="1"
								data-required="<?php echo $box['required'] ? '1' : '0'; ?>"
								<?php echo $box['required'] ? 'required' : ''; ?>>
							<span><?php echo esc_html( $box['text'] ); ?><?php echo $box['required'] ? ' <span class="wcse-bis-required" aria-hidden="true">*</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</label>
					</p>
				<?php endforeach; ?>
				<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product->get_id() ); ?>">
				<input type="hidden" name="variation_id" value="0">
				<button type="submit" class="button"><?php echo esc_html( $button ); ?></button>
			</form>
			<p class="wcse-bis-msg" role="status" aria-live="polite"></p>
		</div>
		<?php
		$this->form_rendered = true;
	}

	/* ─────────────────────────── AJAX ───────────────────────────────── */

	public function ajax_subscribe(): void {
		check_ajax_referer( 'wcse_bis', 'nonce' );

		// Throttle the public endpoint: max 10 submissions per IP per 10 minutes.
		// Prefer an atomic increment on a persistent object cache (Redis/Memcached);
		// fall back to a transient when none is present.
		$limit  = 10;
		$window = 10 * MINUTE_IN_SECONDS;
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key    = 'wcse_bis_rl_' . md5( $ip );

		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'wcse_bis', $window );
			$hits = (int) wp_cache_incr( $key, 1, 'wcse_bis' );
		} else {
			$hits = (int) get_transient( $key ) + 1;
			set_transient( $key, $hits, $window );
		}

		if ( $hits > $limit ) {
			wp_send_json_error( [ 'message' => __( 'יותר מדי בקשות. נסו שוב מאוחר יותר.', 'wc-order-upsale' ) ] );
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$product = absint( $_POST['product_id'] ?? 0 );
		$variant = absint( $_POST['variation_id'] ?? 0 );

		if ( '' === $name || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'נא למלא שם וכתובת אימייל תקינה.', 'wc-order-upsale' ) ] );
		}

		// Which boxes are required is decided here, from the saved settings —
		// never from what the browser sent, which a crafted request controls.
		$given = [];
		foreach ( $this->consent_boxes() as $box ) {
			$ticked = ! empty( $_POST[ $box['key'] ] );
			if ( $box['required'] && ! $ticked ) {
				wp_send_json_error( [ 'message' => __( 'יש לסמן את כל האישורים המסומנים בכוכבית.', 'wc-order-upsale' ) ] );
			}
			$given[ $box['column'] ] = $ticked ? 1 : 0;
		}
		$product_obj = $product ? wc_get_product( $product ) : null;
		if ( ! $product_obj ) {
			wp_send_json_error( [ 'message' => __( 'מוצר לא תקין.', 'wc-order-upsale' ) ] );
		}

		// A variation id arrives from the browser, so confirm it is really a child
		// of this product before storing it — otherwise a crafted request could
		// attach a subscriber to a variation of some entirely different product.
		if ( $variant ) {
			$variation = wc_get_product( $variant );
			if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $variation->get_parent_id() !== $product ) {
				wp_send_json_error( [ 'message' => __( 'האפשרות שנבחרה אינה שייכת למוצר הזה.', 'wc-order-upsale' ) ] );
			}
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
			$row = array_merge(
				[
					'product_id'   => $product,
					'variation_id' => $variant,
					'name'         => $name,
					'email'        => $email,
					'created_at'   => current_time( 'mysql' ),
				],
				$given
			);
			$formats = [ '%d', '%d', '%s', '%s', '%s' ];
			$formats = array_merge( $formats, array_fill( 0, count( $given ), '%d' ) );

			$wpdb->insert( $table, $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
				"SELECT id, name, email, attempts FROM {$table} WHERE variation_id = %d AND notified_at IS NULL ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$variation_id,
				self::BATCH
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, email, attempts FROM {$table} WHERE product_id = %d AND variation_id = 0 AND notified_at IS NULL ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$product_id,
				self::BATCH
			) );
		}

		if ( empty( $rows ) ) {
			return;
		}

		$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );

		// It may have sold out again during the delay/between batches — don't send
		// a false "back in stock" mail; leave the rows pending for the next restock.
		if ( ! $product || ! $product->is_in_stock() ) {
			return;
		}

		$name = $product->get_name();

		// Someone who waited for "red / L" should be told that red / L is back —
		// a variation's get_name() is just the parent's, so spell the choice out.
		$variant_label = '';
		if ( $variation_id > 0 && function_exists( 'wc_get_formatted_variation' ) ) {
			// flat + names gives "מידה: L, צבע: אדום", with term slugs decoded.
			$variant_label = wc_get_formatted_variation( $product, true, true );
		}
		if ( '' !== $variant_label ) {
			$name .= ' — ' . $variant_label;
		}

		// A variation's permalink carries its attributes, so the link lands on the
		// product with the right size/colour already selected.
		$url = $product->get_permalink();
		if ( ! $url ) {
			$url = $product_id ? get_permalink( $product_id ) : home_url( '/' );
		}

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$now     = current_time( 'mysql' );
		$sent    = [];
		$retry   = false;

		add_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );

		foreach ( $rows as $row ) {
			$id       = (int) $row->id;
			$attempts = (int) $row->attempts + 1;

			if ( ! is_email( $row->email ) ) {
				// Retrying cannot help — close the row on the first pass.
				$this->record_failure( $id, self::MAX_ATTEMPTS, __( 'כתובת אימייל לא תקינה', 'wc-order-upsale' ), $now );
				continue;
			}

			list( $subject, $body ) = $this->build_email( (string) $row->name, $name, $url );

			$this->last_mail_error = '';
			$ok = wp_mail( $row->email, $subject, $body, $headers );

			if ( $ok ) {
				$sent[] = $id;
				continue;
			}

			// A send that failed is worth another go — a mail server can be briefly
			// unreachable — but only up to MAX_ATTEMPTS, and the reason is kept so
			// the admin list can show why nothing arrived instead of claiming success.
			$error = $this->last_mail_error ?: __( 'wp_mail נכשל ללא פירוט — כנראה שהאתר לא מוגדר לשליחת דואר', 'wc-order-upsale' );
			$this->record_failure( $id, $attempts, $error, $now );
			if ( $attempts < self::MAX_ATTEMPTS ) {
				$retry = true;
			}
		}

		remove_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );

		if ( $sent ) {
			$in = implode( ',', array_fill( 0, count( $sent ), '%d' ) );
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
				"UPDATE {$table} SET notified_at = %s, last_error = '' WHERE id IN ({$in})",
				array_merge( [ $now ], $sent )
			) );
		}

		// A full batch means more may be waiting; a retryable failure means the
		// same rows deserve another pass. Either way, come back shortly.
		if ( $retry || count( $rows ) >= self::BATCH ) {
			wp_schedule_single_event( time() + 300, 'wcse_bis_notify', [ $product_id, $variation_id ] );
		}
	}

	/* ────────────────────────── E-mail content ──────────────────────── */

	/** The tokens an admin can drop into the subject or body, in Hebrew or English. */
	private function email_tokens( string $customer, string $product_name, string $url ): array {
		$values = [
			'name'    => $customer,
			'product' => $product_name,
			'link'    => $url,
			'site'    => get_bloginfo( 'name' ),
		];
		$aliases = [ 'name' => 'שם', 'product' => 'מוצר', 'link' => 'קישור', 'site' => 'אתר' ];

		$map = [];
		foreach ( $values as $key => $value ) {
			// The template may contain markup, so escape what is substituted into
			// it rather than the template itself.
			$safe = 'link' === $key ? esc_url( $value ) : esc_html( $value );
			$map[ '{' . $key . '}' ]              = $safe;
			$map[ '{' . $aliases[ $key ] . '}' ]  = $safe;
		}
		return $map;
	}

	private function default_email_body(): string {
		return __( "שלום {שם},\n\nהמוצר \"{מוצר}\" חזר למלאי — כדאי למהר לפני שייגמר שוב.", 'wc-order-upsale' );
	}

	/**
	 * Build one notification. Returns [ subject, body ].
	 */
	private function build_email( string $customer, string $product_name, string $url ): array {
		$tokens = $this->email_tokens( $customer, $product_name, $url );

		/* translators: %s: product name */
		$subject = $this->text( 'email_subject', sprintf( __( '%s חזר למלאי!', 'wc-order-upsale' ), '{מוצר}' ) );
		// A subject is plain text, so undo the escaping the token map applied.
		$subject = wp_specialchars_decode( strtr( $subject, $tokens ), ENT_QUOTES );

		$body  = wpautop( strtr( $this->text( 'email_body', $this->default_email_body() ), $tokens ) );
		$body .= '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#111;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">'
			. esc_html( $this->text( 'email_button', __( 'לצפייה ורכישה', 'wc-order-upsale' ) ) ) . '</a></p>';

		// Mail clients default to left-to-right, so a Hebrew message arrives with
		// its punctuation and layout mirrored unless the direction is stated. A
		// wrapper carries it without assuming anything about the surrounding
		// template an SMTP plugin may add.
		$dir   = is_rtl() ? 'rtl' : 'ltr';
		$align = is_rtl() ? 'right' : 'left';
		$body  = '<div dir="' . $dir . '" style="direction:' . $dir . ';text-align:' . $align . '">'
			. $body . '</div>';

		return [ $subject, $body ];
	}

	/** Remember why wp_mail() failed so the row can explain itself in the admin list. */
	public function capture_mail_error( $error ): void {
		if ( is_wp_error( $error ) ) {
			$this->last_mail_error = $error->get_error_message();
		}
	}

	/**
	 * Record a failed send. Once MAX_ATTEMPTS is reached the row is closed off
	 * (notified_at set) so a permanently broken mailer cannot queue forever — but
	 * last_error stays, so it reads as "failed", never as "delivered".
	 */
	private function record_failure( int $id, int $attempts, string $error, string $now ): void {
		global $wpdb;
		$data    = [ 'attempts' => $attempts, 'last_error' => mb_substr( $error, 0, 190 ) ];
		$formats = [ '%d', '%s' ];

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$data['notified_at'] = $now;
			$formats[]           = '%s';
		}

		$wpdb->update( self::table(), $data, [ 'id' => $id ], $formats, [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
			'consent_2_text'     => sanitize_text_field( wp_unslash( $_POST['consent_2_text'] ?? '' ) ),
			'consent_2_required' => empty( $_POST['consent_2_required'] ) ? 0 : 1,
			'consent_3_text'     => sanitize_text_field( wp_unslash( $_POST['consent_3_text'] ?? '' ) ),
			'consent_3_required' => empty( $_POST['consent_3_required'] ) ? 0 : 1,
			'success_text'  => sanitize_text_field( wp_unslash( $_POST['success_text'] ?? '' ) ),
			'email_subject' => sanitize_text_field( wp_unslash( $_POST['email_subject'] ?? '' ) ),
			// The body may carry simple formatting, so it keeps the post-safe tags.
			'email_body'    => wp_kses_post( wp_unslash( $_POST['email_body'] ?? '' ) ),
			'email_button'  => sanitize_text_field( wp_unslash( $_POST['email_button'] ?? '' ) ),
			'show_oos_variations' => empty( $_POST['show_oos_variations'] ) ? '' : '1',
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

	/**
	 * Send a specimen of the restock e-mail to the current admin. Without this the
	 * only way to find out whether the site can send mail at all is to sell a
	 * product out, restock it, and hope.
	 */
	public function send_test_email(): void {
		if ( ! check_admin_referer( 'wcse_bis_test' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		$user = wp_get_current_user();
		$to   = $user->user_email;

		// Render the real template, so the test doubles as a preview of the wording.
		list( $subject, $body ) = $this->build_email(
			(string) $user->display_name,
			__( 'מוצר לדוגמה', 'wc-order-upsale' ),
			home_url( '/' )
		);
		$body .= '<hr><p style="color:#777;font-size:13px">' . esc_html__( 'זהו מייל בדיקה מהתוסף. אם קיבלתם אותו — שליחת הדואר באתר תקינה.', 'wc-order-upsale' ) . '</p>';

		$this->last_mail_error = '';
		add_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );
		$ok = wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
		remove_action( 'wp_mail_failed', [ $this, 'capture_mail_error' ] );

		$args = [
			'page' => WC_Order_Upsale_Dashboard::SETTINGS_SLUG,
			'tab'  => 'backinstock',
			'test' => $ok ? 'ok' : 'fail',
		];
		if ( ! $ok ) {
			$args['test_error'] = $this->last_mail_error ?: __( 'wp_mail החזיר כישלון ללא פירוט.', 'wc-order-upsale' );
		}

		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** True when WP-Cron is switched off, which silently strands every queued send. */
	private function cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
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
		$rows  = $wpdb->get_results( "SELECT product_id, variation_id, name, email, consent, consent_2, consent_3, created_at, notified_at, attempts, last_error FROM {$table} ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=back-in-stock.csv' );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.
		$this->fputcsv_safe( $out, [ 'product_id', 'variation_id', 'name', 'email', 'consent', 'consent_2', 'consent_3', 'created_at', 'notified_at', 'attempts', 'last_error' ] );
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
		$rows  = $wpdb->get_results( "SELECT id, product_id, variation_id, name, email, created_at, notified_at, attempts, last_error FROM {$table} ORDER BY created_at DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
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
				<?php esc_html_e( 'טופס "עדכנו אותי כשחוזר למלאי" (שם + אימייל + אישור דיוור) מוצג אוטומטית על מוצר/וריאציה שאזלו. כשהמוצר חוזר למלאי — נשלח מייל אוטומטי לכל הנרשמים עם קישור למוצר.', 'wc-order-upsale' ); ?>
				<br>
				<?php esc_html_e( 'אם התבנית או בונה העמודים שלכם בונים את עמוד המוצר בעצמם והטופס לא מופיע במקום הרצוי, אפשר למקם אותו ידנית עם השורטקוד:', 'wc-order-upsale' ); ?>
				<code>[wcse_back_in_stock]</code>
			</p>

			<?php if ( ! $this->is_enabled() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'המודול כבוי כרגע. הפעילו אותו מלוח הבקרה.', 'wc-order-upsale' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::MENU_SLUG ) ); ?>"><?php esc_html_e( 'לוח הבקרה', 'wc-order-upsale' ); ?></a>
				</p></div>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: 1: plugin version, 2: module state */
					esc_html__( 'גרסה פעילה: %1$s · מצב המודול: %2$s', 'wc-order-upsale' ),
					'<code>' . esc_html( WC_ORDER_UPSALE_VERSION ) . '</code>',
					$this->is_enabled()
						? '<strong style="color:#125c2b">' . esc_html__( 'דלוק', 'wc-order-upsale' ) . '</strong>'
						: '<strong style="color:#b32d2e">' . esc_html__( 'כבוי', 'wc-order-upsale' ) . '</strong>'
				); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</p>

			<?php if ( $this->cron_disabled() ) : ?>
				<div class="notice notice-error inline"><p>
					<strong><?php esc_html_e( 'WP-Cron מכובה באתר (DISABLE_WP_CRON).', 'wc-order-upsale' ); ?></strong>
					<?php esc_html_e( 'מיילי "חזר למלאי" נשלחים ברקע דרך WP-Cron — בלעדיו הם יישארו בתור ולא יישלחו לעולם. הפעילו WP-Cron, או הגדירו cron אמיתי בשרת שקורא ל-wp-cron.php.', 'wc-order-upsale' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['test'] ) && 'ok' === $_GET['test'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'מייל הבדיקה נשלח. אם הוא הגיע לתיבה שלכם — שליחת הדואר באתר תקינה.', 'wc-order-upsale' ); ?></p></div>
			<?php elseif ( isset( $_GET['test'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p>
					<strong><?php esc_html_e( 'שליחת מייל הבדיקה נכשלה.', 'wc-order-upsale' ); ?></strong><br>
					<?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['test_error'] ?? '' ) ) ); ?><br>
					<?php esc_html_e( 'כל עוד זה המצב, אף מייל "חזר למלאי" לא יגיע. מומלץ להתקין תוסף SMTP ולחבר תיבת דואר אמיתית.', 'wc-order-upsale' ); ?>
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
						<th scope="row"><label for="wcse-bis-consent"><?php esc_html_e( 'אישור 1 (תמיד מוצג, תמיד חובה)', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="text" id="wcse-bis-consent" name="consent_text" value="<?php echo esc_attr( $settings['consent_text'] ); ?>" placeholder="<?php esc_attr_e( 'אני מאשר/ת קבלת עדכון חד-פעמי כשהמוצר יחזור למלאי.', 'wc-order-upsale' ); ?>" class="large-text">
						</td>
					</tr>
					<?php foreach ( [ 2, 3 ] as $n ) : ?>
						<tr>
							<th scope="row">
								<label for="wcse-bis-consent-<?php echo (int) $n; ?>">
									<?php
									/* translators: %d: checkbox number */
									printf( esc_html__( 'אישור %d (אופציונלי)', 'wc-order-upsale' ), (int) $n );
									?>
								</label>
							</th>
							<td>
								<input type="text"
									id="wcse-bis-consent-<?php echo (int) $n; ?>"
									name="consent_<?php echo (int) $n; ?>_text"
									value="<?php echo esc_attr( (string) ( $settings[ "consent_{$n}_text" ] ?? '' ) ); ?>"
									placeholder="<?php esc_attr_e( 'השאירו ריק כדי לא להציג את הצ׳קבוקס הזה', 'wc-order-upsale' ); ?>"
									class="large-text">
								<p>
									<label>
										<input type="hidden" name="consent_<?php echo (int) $n; ?>_required" value="0">
										<input type="checkbox" name="consent_<?php echo (int) $n; ?>_required" value="1" <?php checked( ! empty( $settings[ "consent_{$n}_required" ] ) ); ?>>
										<?php esc_html_e( 'חובה — אי אפשר להירשם בלי לסמן אותו', 'wc-order-upsale' ); ?>
									</label>
								</p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"></th>
						<td>
							<p class="description" style="max-width:620px">
								<?php esc_html_e( 'אישורים שסומנו כחובה מקבלים כוכבית אדומה בטופס, ונבדקים גם בדפדפן וגם בשרת — בקשה מזויפת לא יכולה לעקוף אותם. מה שהלקוח אישר נשמר לצד ההרשמה ומופיע בייצוא ל-CSV, כדי שתוכלו להוכיח על מה בדיוק הוא הסכים.', 'wc-order-upsale' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-bis-success"><?php esc_html_e( 'הודעת הצלחה', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-bis-success" name="success_text" value="<?php echo esc_attr( $settings['success_text'] ); ?>" placeholder="<?php esc_attr_e( 'תודה! נעדכן אתכם כשהמוצר יחזור למלאי.', 'wc-order-upsale' ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'וריאציות שאזלו', 'wc-order-upsale' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="show_oos_variations" value="1" <?php checked( $this->shows_sold_out_variations() ); ?>>
								<?php esc_html_e( 'אפשר לבחור בעמוד המוצר גם מידות/צבעים שאזלו', 'wc-order-upsale' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'כשההגדרה של ווקומרס "הסתר פריטים שאזלו מהמלאי" דלוקה, ווקומרס מסיר וריאציות שאזלו מעמוד המוצר — הלקוח לא יכול לבחור אותן, ולכן טופס ההרשמה לא יופיע לעולם. האפשרות הזו מחזירה אותן לבחירה בעמוד המוצר בלבד; הקטלוג, החיפוש ושאר האתר לא משתנים.', 'wc-order-upsale' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-bis-subject"><?php esc_html_e( 'נושא המייל', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="text" id="wcse-bis-subject" name="email_subject" value="<?php echo esc_attr( $settings['email_subject'] ); ?>" placeholder="<?php esc_attr_e( '{מוצר} חזר למלאי!', 'wc-order-upsale' ); ?>" class="large-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-bis-body"><?php esc_html_e( 'תוכן המייל', 'wc-order-upsale' ); ?></label></th>
						<td>
							<textarea id="wcse-bis-body" name="email_body" rows="6" class="large-text" placeholder="<?php echo esc_attr( $this->default_email_body() ); ?>"><?php echo esc_textarea( $settings['email_body'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'אפשר להשתמש בקיצורים הבאים, והם יוחלפו בפרטים האמיתיים:', 'wc-order-upsale' ); ?><br>
								<code>{שם}</code> <?php esc_html_e( 'שם הלקוח', 'wc-order-upsale' ); ?> ·
								<code>{מוצר}</code> <?php esc_html_e( 'שם המוצר, כולל המידה/הצבע שנבחרו', 'wc-order-upsale' ); ?> ·
								<code>{קישור}</code> <?php esc_html_e( 'הקישור למוצר', 'wc-order-upsale' ); ?> ·
								<code>{אתר}</code> <?php esc_html_e( 'שם החנות', 'wc-order-upsale' ); ?>
								<br><?php esc_html_e( 'הם עובדים גם בנושא המייל, וגם באנגלית: {name} {product} {link} {site}. שורה ריקה יוצרת פסקה חדשה. כפתור המעבר למוצר נוסף אוטומטית מתחת לטקסט.', 'wc-order-upsale' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-bis-emailbtn"><?php esc_html_e( 'כיתוב הכפתור במייל', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="text" id="wcse-bis-emailbtn" name="email_button" value="<?php echo esc_attr( $settings['email_button'] ); ?>" placeholder="<?php esc_attr_e( 'לצפייה ורכישה', 'wc-order-upsale' ); ?>" class="regular-text">
						</td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'שמור הגדרות', 'wc-order-upsale' ); ?></button></p>
			</form>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wc_store_enhancer_bis_test' ), 'wcse_bis_test' ) ); ?>">
					<?php esc_html_e( 'שלחו מייל בדיקה אליי', 'wc-order-upsale' ); ?>
				</a>
				<span class="description"><?php
					/* translators: %s: admin e-mail address */
					printf( esc_html__( 'נשלח אל %s בנוסח שהגדרתם למעלה — גם בדיקה שהאתר מסוגל לשלוח דואר, וגם תצוגה מקדימה. שמרו קודם.', 'wc-order-upsale' ), esc_html( wp_get_current_user()->user_email ) );
				?></span>
			</p>

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
									<?php if ( ! empty( $row['last_error'] ) ) : ?>
										<span style="color:#b32d2e"><?php esc_html_e( 'שליחה נכשלה', 'wc-order-upsale' ); ?></span>
										<span class="description" style="display:block"><?php echo esc_html( $row['last_error'] ); ?></span>
									<?php elseif ( $row['notified_at'] ) : ?>
										<span style="color:#125c2b"><?php esc_html_e( 'נשלח', 'wc-order-upsale' ); ?></span>
									<?php else : ?>
										<span style="color:#7a5c00"><?php esc_html_e( 'ממתין', 'wc-order-upsale' ); ?></span>
									<?php endif; ?>
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
