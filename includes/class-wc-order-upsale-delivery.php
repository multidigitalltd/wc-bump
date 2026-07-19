<?php

defined( 'ABSPATH' ) || exit;

/**
 * Estimated Delivery Date (EDD).
 *
 * Shows an estimated delivery window on the product page (and via the
 * [wc_delivery_estimate] shortcode). The window is computed in business days,
 * skipping configurable non-working weekdays (Fri/Sat by default) and holiday
 * dates, with an optional daily cut-off time.
 *
 * Research: showing a delivery date (not a vague "shipping speed") lifts
 * checkout conversion ~13–25% in A/B tests; ~75% of shoppers say a product-page
 * delivery date positively influences their purchase and ~40% won't buy without
 * one (Baymard Institute / Narvar).
 */
class WC_Order_Upsale_Delivery {

	const OPTION = 'wc_store_enhancer_delivery';

	public function __construct() {
		// Settings tab on the shared "הגדרות" page (after swatches, before updates).
		add_filter( 'wc_store_enhancer_settings_tabs',              [ $this, 'register_settings_tab' ], 15 );
		add_action( 'admin_post_save_wc_store_enhancer_delivery',   [ $this, 'save_settings' ] );

		// Frontend (styles are inlined once by get_estimate_html — no external asset).
		add_shortcode( 'wc_delivery_estimate', [ $this, 'shortcode' ] );

		if ( $this->is_enabled() ) {
			[ $hook, $priority ] = $this->placement_hook();
			add_action( $hook, [ $this, 'render_on_product' ], $priority );
		}
	}

	/* ─────────────────────────── Settings ───────────────────────────── */

	public static function get_settings(): array {
		$defaults = [
			'label'       => '',
			'min_days'    => 2,
			'max_days'    => 4,
			'off_days'    => [ 5, 6 ], // Fri, Sat (0 = Sun … 6 = Sat).
			'cutoff'      => '',       // Hour 0–23, or '' to disable.
			'holidays'    => [],       // Y-m-d strings that never count as business days.
			'placement'   => 'after_cart',
			'date_format' => 'l, j.n',
		];
		$saved = wp_parse_args( (array) get_option( self::OPTION, [] ), $defaults );

		$saved['off_days'] = array_values( array_filter(
			array_map( 'intval', (array) $saved['off_days'] ),
			static fn( $d ) => $d >= 0 && $d <= 6
		) );
		$saved['holidays'] = array_values( array_filter( (array) $saved['holidays'], 'is_string' ) );

		return $saved;
	}

	private function is_enabled(): bool {
		return ! class_exists( 'WC_Order_Upsale_Modules' )
			|| WC_Order_Upsale_Modules::is_enabled( 'delivery_date' );
	}

	/** Map the placement setting to a WooCommerce hook + priority. */
	private function placement_hook(): array {
		switch ( self::get_settings()['placement'] ) {
			case 'after_price':
				return [ 'woocommerce_single_product_summary', 11 ];
			case 'after_meta':
				return [ 'woocommerce_single_product_summary', 41 ];
			case 'after_cart':
			default:
				return [ 'woocommerce_single_product_summary', 31 ];
		}
	}

	/* ─────────────────────────── Frontend ───────────────────────────── */

	/** Product-summary hook callback: print the estimate for the current product. */
	public function render_on_product(): void {
		$product = $GLOBALS['product'] ?? null;
		if ( ! $product instanceof WC_Product || ! $product->is_in_stock() || ! $product->is_purchasable() ) {
			return;
		}
		echo $this->get_estimate_html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Shortcode [wc_delivery_estimate]. */
	public function shortcode( $atts ): string {
		return $this->get_estimate_html();
	}

	/** Build the estimate markup (already escaped), or '' when no estimate applies. */
	public function get_estimate_html(): string {
		if ( ! $this->is_enabled() ) {
			return '';
		}

		$settings = self::get_settings();
		$window   = $this->window( $settings );
		if ( null === $window ) {
			return ''; // No working weekday configured — show nothing rather than a fake date.
		}
		[ $from, $to ] = $window;

		$format = '' !== $settings['date_format'] ? $settings['date_format'] : 'l, j.n';
		$label  = '' !== $settings['label'] ? $settings['label'] : __( 'אספקה משוערת:', 'wc-order-upsale' );

		if ( wp_date( 'Y-m-d', $from ) === wp_date( 'Y-m-d', $to ) ) {
			$dates = wp_date( $format, $to );
		} else {
			/* translators: 1: earliest date, 2: latest date */
			$dates = sprintf( __( '%1$s – %2$s', 'wc-order-upsale' ), wp_date( $format, $from ), wp_date( $format, $to ) );
		}

		// Inline the tiny stylesheet once per request so it is styled regardless of
		// where the shortcode/hook runs (product page, archive, Elementor page…),
		// even when expanded after wp_head().
		static $printed_css = false;
		$css = '';
		if ( ! $printed_css ) {
			$printed_css = true;
			$css = '<style id="wcse-edd-css">.wcse-edd{display:flex;align-items:center;gap:8px;margin:12px 0;font-size:15px;line-height:1.4;font-family:inherit;color:inherit}.wcse-edd-date{font-weight:700}</style>';
		}

		return $css . '<p class="wcse-edd"><span class="wcse-edd-icon" aria-hidden="true">🚚</span> '
			. '<span class="wcse-edd-label">' . esc_html( $label ) . '</span> '
			. '<span class="wcse-edd-date">' . esc_html( (string) $dates ) . '</span></p>';
	}

	/** True when a timestamp falls on a non-working weekday or a holiday. */
	private function is_off_day( int $timestamp, array $off_days, array $holidays ): bool {
		return in_array( (int) wp_date( 'w', $timestamp ), $off_days, true )
			|| in_array( wp_date( 'Y-m-d', $timestamp ), $holidays, true );
	}

	/**
	 * Compute the [earliest, latest] delivery timestamps, or null when no working
	 * weekday is configured (every weekday marked off).
	 *
	 * @return array{0:int,1:int}|null
	 */
	private function window( array $settings ): ?array {
		$off_days = $settings['off_days'];
		$holidays = $settings['holidays'];

		// With no working weekday at all there is no valid delivery date.
		if ( count( array_unique( array_intersect( $off_days, range( 0, 6 ) ) ) ) >= 7 ) {
			return null;
		}

		$min = max( 0, (int) $settings['min_days'] );
		$max = max( $min, (int) $settings['max_days'] );

		// Real UTC timestamp; wp_date() applies the site timezone when formatting.
		$start = time();

		// Optional cut-off: once past it, treat the order as placed on the next
		// working day — advance through excluded weekdays/holidays first.
		if ( '' !== $settings['cutoff'] ) {
			$cutoff = max( 0, min( 23, (int) $settings['cutoff'] ) );
			if ( (int) wp_date( 'G', $start ) >= $cutoff ) {
				$start = $this->roll_to_business_day( $start + DAY_IN_SECONDS, $off_days, $holidays );
			}
		}

		$from = $this->add_business_days( $start, $min, $off_days, $holidays );
		$to   = $this->add_business_days( $start, $max, $off_days, $holidays );

		return [ $from, $to ];
	}

	/** Roll a timestamp forward to the next working day (skipping off-days/holidays). */
	private function roll_to_business_day( int $ts, array $off_days, array $holidays ): int {
		$guard = 0;
		while ( $this->is_off_day( $ts, $off_days, $holidays ) && $guard++ < 366 ) {
			$ts += DAY_IN_SECONDS;
		}
		return $ts;
	}

	/** Advance a timestamp by N business days, skipping off-days and holidays. */
	private function add_business_days( int $start, int $days, array $off_days, array $holidays ): int {
		if ( $days <= 0 ) {
			// Same-day option: roll forward to the next working day if today is off.
			return $this->roll_to_business_day( $start, $off_days, $holidays );
		}

		$ts    = $start;
		$added = 0;
		$guard = 0;
		while ( $added < $days && $guard++ < 366 ) {
			$ts += DAY_IN_SECONDS;
			if ( ! $this->is_off_day( $ts, $off_days, $holidays ) ) {
				$added++;
			}
		}
		return $ts;
	}

	/* ─────────────────────────── Settings tab ───────────────────────── */

	public function register_settings_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'delivery',
			'label'    => __( 'אספקה משוערת', 'wc-order-upsale' ),
			'callback' => [ $this, 'render_settings_tab' ],
		];
		return $tabs;
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_delivery_save', 'wc_store_enhancer_delivery_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		$min = max( 0, absint( $_POST['min_days'] ?? 0 ) );
		$max = max( $min, absint( $_POST['max_days'] ?? 0 ) );

		$off_days = array_values( array_unique( array_filter(
			array_map( 'intval', (array) wp_unslash( $_POST['off_days'] ?? [] ) ),
			static fn( $d ) => $d >= 0 && $d <= 6
		) ) );

		// A schedule with no working weekday can't produce a real date — fall back
		// to the Israeli default (Fri/Sat off) so an estimate is always possible.
		if ( count( $off_days ) >= 7 ) {
			$off_days = [ 5, 6 ];
		}

		$cutoff_raw = $_POST['cutoff'] ?? '';
		$cutoff     = ( '' === $cutoff_raw || ! is_numeric( $cutoff_raw ) ) ? '' : (string) max( 0, min( 23, absint( $cutoff_raw ) ) );

		// Parse holidays: one Y-m-d per line/comma; keep only real calendar dates.
		$holidays_raw = is_string( $_POST['holidays'] ?? '' ) ? wp_unslash( $_POST['holidays'] ) : '';
		$holidays     = [];
		foreach ( preg_split( '/[\s,]+/', (string) $holidays_raw ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $candidate, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$holidays[] = $candidate;
			}
		}
		$holidays = array_values( array_unique( $holidays ) );

		$placement = in_array( $_POST['placement'] ?? '', [ 'after_cart', 'after_price', 'after_meta' ], true )
			? sanitize_key( wp_unslash( $_POST['placement'] ) ) : 'after_cart';

		update_option( self::OPTION, [
			'label'       => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'min_days'    => $min,
			'max_days'    => $max,
			'off_days'    => $off_days,
			'cutoff'      => $cutoff,
			'holidays'    => $holidays,
			'placement'   => $placement,
			'date_format' => sanitize_text_field( wp_unslash( $_POST['date_format'] ?? 'l, j.n' ) ),
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=delivery&saved=1' ) );
		exit;
	}

	public function render_settings_tab(): void {
		$settings = self::get_settings();
		$weekdays = [
			0 => __( 'ראשון', 'wc-order-upsale' ),
			1 => __( 'שני', 'wc-order-upsale' ),
			2 => __( 'שלישי', 'wc-order-upsale' ),
			3 => __( 'רביעי', 'wc-order-upsale' ),
			4 => __( 'חמישי', 'wc-order-upsale' ),
			5 => __( 'שישי', 'wc-order-upsale' ),
			6 => __( 'שבת', 'wc-order-upsale' ),
		];
		?>
		<div class="wcse-admin">
			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'הצגת חלון אספקה משוער בעמוד המוצר, מחושב בימי עסקים. אפשר להחריג ימים קבועים (שישי/שבת) ותאריכי חגים.', 'wc-order-upsale' ); ?>
			</p>

			<?php if ( ! $this->is_enabled() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'המודול כבוי כרגע. הפעילו אותו מלוח הבקרה כדי שהאספקה המשוערת תוצג בחזית.', 'wc-order-upsale' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::MENU_SLUG ) ); ?>"><?php esc_html_e( 'לוח הבקרה', 'wc-order-upsale' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ההגדרות נשמרו בהצלחה.', 'wc-order-upsale' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_store_enhancer_delivery_save', 'wc_store_enhancer_delivery_nonce' ); ?>
				<input type="hidden" name="action" value="save_wc_store_enhancer_delivery">

				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcse-edd-label"><?php esc_html_e( 'טקסט לפני התאריך', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="text" id="wcse-edd-label" name="label" value="<?php echo esc_attr( $settings['label'] ); ?>"
								placeholder="<?php esc_attr_e( 'אספקה משוערת:', 'wc-order-upsale' ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'טווח ימי עסקים', 'wc-order-upsale' ); ?></th>
						<td>
							<label><?php esc_html_e( 'מ־', 'wc-order-upsale' ); ?>
								<input type="number" name="min_days" value="<?php echo esc_attr( (string) $settings['min_days'] ); ?>" min="0" max="60" style="width:70px">
							</label>
							&nbsp;
							<label><?php esc_html_e( 'עד', 'wc-order-upsale' ); ?>
								<input type="number" name="max_days" value="<?php echo esc_attr( (string) $settings['max_days'] ); ?>" min="0" max="60" style="width:70px">
							</label>
							<p class="description"><?php esc_html_e( 'אם המספרים זהים — יוצג תאריך אחד; אחרת יוצג טווח.', 'wc-order-upsale' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'ימים שאינם נספרים', 'wc-order-upsale' ); ?></th>
						<td>
							<?php foreach ( $weekdays as $num => $name ) : ?>
								<label style="margin-inline-end:14px;display:inline-block">
									<input type="checkbox" name="off_days[]" value="<?php echo esc_attr( (string) $num ); ?>"
										<?php checked( in_array( $num, $settings['off_days'], true ) ); ?>>
									<?php echo esc_html( $name ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'ברירת מחדל בישראל: שישי ושבת.', 'wc-order-upsale' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-edd-cutoff"><?php esc_html_e( 'שעת גבול להזמנה (אופציונלי)', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="number" id="wcse-edd-cutoff" name="cutoff" value="<?php echo esc_attr( $settings['cutoff'] ); ?>" min="0" max="23" style="width:80px" placeholder="—">
							<p class="description"><?php esc_html_e( 'שעה (0–23). לאחר שעה זו הספירה מתחילה מיום העסקים הבא. השאירו ריק לביטול.', 'wc-order-upsale' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-edd-holidays"><?php esc_html_e( 'תאריכי חג/סגירה', 'wc-order-upsale' ); ?></label></th>
						<td>
							<textarea id="wcse-edd-holidays" name="holidays" rows="4" class="large-text code" placeholder="2026-09-14&#10;2026-10-02"><?php echo esc_textarea( implode( "\n", $settings['holidays'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'תאריך אחד בכל שורה בפורמט YYYY-MM-DD. ימים אלה לא ייספרו כימי עסקים.', 'wc-order-upsale' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-edd-placement"><?php esc_html_e( 'מיקום בעמוד המוצר', 'wc-order-upsale' ); ?></label></th>
						<td>
							<select id="wcse-edd-placement" name="placement">
								<option value="after_cart"  <?php selected( $settings['placement'], 'after_cart' ); ?>><?php esc_html_e( 'מתחת לכפתור הוספה לסל', 'wc-order-upsale' ); ?></option>
								<option value="after_price" <?php selected( $settings['placement'], 'after_price' ); ?>><?php esc_html_e( 'מתחת למחיר', 'wc-order-upsale' ); ?></option>
								<option value="after_meta"  <?php selected( $settings['placement'], 'after_meta' ); ?>><?php esc_html_e( 'מתחת לפרטי המוצר', 'wc-order-upsale' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'אפשר גם להציב בכל מקום עם השורטקוד:', 'wc-order-upsale' ); ?> <code>[wc_delivery_estimate]</code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-edd-format"><?php esc_html_e( 'פורמט תאריך', 'wc-order-upsale' ); ?></label></th>
						<td>
							<input type="text" id="wcse-edd-format" name="date_format" value="<?php echo esc_attr( $settings['date_format'] ); ?>" class="regular-text code">
							<p class="description">
								<?php esc_html_e( 'פורמט תאריך של PHP. ברירת מחדל:', 'wc-order-upsale' ); ?> <code>l, j.n</code>
								(<?php echo esc_html( wp_date( '' !== $settings['date_format'] ? $settings['date_format'] : 'l, j.n' ) ); ?>)
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'תצוגה מקדימה', 'wc-order-upsale' ); ?></h2>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;max-width:520px" dir="rtl">
					<?php echo $this->get_estimate_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'שמור הגדרות', 'wc-order-upsale' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
