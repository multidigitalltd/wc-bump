<?php

defined( 'ABSPATH' ) || exit;

/**
 * Tracks and reports performance statistics for each Order Upsale:
 * impressions, add-to-cart clicks, completed conversions and revenue.
 *
 * Counters are stored in a dedicated table and incremented atomically
 * (INSERT … ON DUPLICATE KEY UPDATE) so high-volume impression counts
 * are never lost to race conditions.
 */
class WC_Order_Upsale_Analytics {

	const DB_VERSION_OPTION = 'wc_order_upsale_stats_db_version';
	const DB_VERSION        = '1.0.0';

	public function __construct() {
		// Tag upsale line items as they are written to the order
		// (classic checkout and the Store API / Block checkout share this hook).
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'tag_order_line_item' ], 10, 4 );

		// Record conversions + revenue only once the order reaches a paid state,
		// so failed or abandoned payments are never counted as completed purchases.
		// The order meta guard makes the processing -> completed transition idempotent.
		add_action( 'woocommerce_order_status_processing', [ $this, 'record_order' ], 20, 1 );
		add_action( 'woocommerce_order_status_completed',  [ $this, 'record_order' ], 20, 1 );

		// Stats admin page.
		add_action( 'admin_menu',                                  [ $this, 'add_menu' ], 20 );
		add_action( 'admin_post_wc_order_upsale_reset_stats',      [ $this, 'handle_reset' ] );

		// Make sure the table exists even after a plugin update where the
		// activation hook did not run.
		add_action( 'init', [ $this, 'maybe_create_table' ] );
	}

	/* ───────────────────────── Storage ───────────────────────── */

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_order_upsale_stats';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			product_id BIGINT UNSIGNED NOT NULL,
			impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
			add_to_cart BIGINT UNSIGNED NOT NULL DEFAULT 0,
			conversions BIGINT UNSIGNED NOT NULL DEFAULT 0,
			revenue DECIMAL(18,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (product_id)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public function maybe_create_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/* ─────────────────────── Increment API ───────────────────── */

	/** Atomic +N on a single whitelisted counter column. */
	private static function bump( int $product_id, string $column, int $count = 1 ): void {
		if ( $product_id <= 0 || $count <= 0
			|| ! in_array( $column, [ 'impressions', 'add_to_cart', 'conversions' ], true ) ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();

		// $column is whitelisted above, safe to interpolate.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (product_id, {$column}) VALUES (%d, %d)
			 ON DUPLICATE KEY UPDATE {$column} = {$column} + %d",
			$product_id,
			$count,
			$count
		) );
	}

	public static function record_impression( int $product_id ): void {
		self::bump( $product_id, 'impressions' );
	}

	/** Records one impression for each rendered upsale product. */
	public static function record_impressions( array $product_ids ): void {
		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $pid ) {
			self::record_impression( $pid );
		}
	}

	public static function record_add_to_cart( int $product_id ): void {
		self::bump( $product_id, 'add_to_cart' );
	}

	public static function record_conversion( int $product_id, float $revenue, int $count = 1 ): void {
		if ( $product_id <= 0 || $count <= 0 ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (product_id, conversions, revenue) VALUES (%d, %d, %f)
			 ON DUPLICATE KEY UPDATE conversions = conversions + %d, revenue = revenue + %f",
			$product_id,
			$count,
			$revenue,
			$count,
			$revenue
		) );
	}

	/* ─────────────────────── Reporting API ───────────────────── */

	/**
	 * @return array<int,array{impressions:int,add_to_cart:int,conversions:int,revenue:float}>
	 */
	public static function get_all_stats(): array {
		global $wpdb;
		$table = self::table_name();

		$rows  = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$stats = [];

		foreach ( (array) $rows as $row ) {
			$stats[ (int) $row['product_id'] ] = [
				'impressions' => (int) $row['impressions'],
				'add_to_cart' => (int) $row['add_to_cart'],
				'conversions' => (int) $row['conversions'],
				'revenue'     => (float) $row['revenue'],
			];
		}

		return $stats;
	}

	public static function blank_stats(): array {
		return [
			'impressions' => 0,
			'add_to_cart' => 0,
			'conversions' => 0,
			'revenue'     => 0.0,
		];
	}

	public static function reset_stats(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* ─────────────────────── Order tracking ──────────────────── */

	/**
	 * Persist the "_order_upsale" flag from cart item data onto the order line
	 * item, so conversions can be attributed after checkout.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 * @param array                 $values
	 * @param WC_Order              $order
	 */
	public function tag_order_line_item( $item, $cart_item_key, $values, $order ): void {
		if ( ! empty( $values['_order_upsale'] ) ) {
			$item->add_meta_data( '_order_upsale', 1, true );
		}
	}

	/**
	 * Record conversions + revenue for every upsale line in an order that has
	 * reached a paid state. Guarded by an order meta flag so it runs at most
	 * once per order (e.g. across the processing -> completed transition).
	 *
	 * @param int|WC_Order $order
	 */
	public function record_order( $order ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_upsale_stats_recorded' ) ) {
			return;
		}

		$recorded = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item->get_meta( '_order_upsale' ) ) {
				continue;
			}
			self::record_conversion( $item->get_product_id(), (float) $item->get_total(), 1 );
			$recorded = true;
		}

		if ( $recorded ) {
			$order->update_meta_data( '_upsale_stats_recorded', 1 );
			$order->save();
		}
	}

	/* ─────────────────────── Admin stats page ────────────────── */

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'סטטיסטיקות Upsale', 'wc-order-upsale' ),
			__( 'סטטיסטיקות Upsale', 'wc-order-upsale' ),
			'manage_woocommerce',
			'wc-order-upsales-stats',
			[ $this, 'render_stats_page' ]
		);
	}

	public function handle_reset(): void {
		if ( ! check_admin_referer( 'wc_order_upsale_reset_stats', 'wc_order_upsale_reset_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		self::reset_stats();
		wp_safe_redirect( admin_url( 'admin.php?page=wc-order-upsales-stats&reset=1' ) );
		exit;
	}

	/**
	 * Format a ratio (0–1) as a percentage string, or "—" when undefined.
	 */
	public static function format_rate( int $numerator, int $denominator ): string {
		if ( $denominator <= 0 ) {
			return '—';
		}
		return number_format( $numerator / $denominator * 100, 1 ) . '%';
	}

	public function render_stats_page(): void {
		$all_stats = self::get_all_stats();
		$upsales   = WC_Order_Upsale_Admin::get_upsales();

		// Build a display list: every configured upsale, plus any product that
		// has recorded stats but is no longer configured.
		$rows         = [];
		$configured   = [];
		foreach ( $upsales as $upsale ) {
			$pid = (int) ( $upsale['product_id'] ?? 0 );
			if ( ! $pid || isset( $configured[ $pid ] ) ) {
				continue;
			}
			$configured[ $pid ] = true;
			$rows[ $pid ]       = $all_stats[ $pid ] ?? self::blank_stats();
		}
		foreach ( $all_stats as $pid => $stat ) {
			if ( ! isset( $rows[ $pid ] ) ) {
				$rows[ $pid ] = $stat;
			}
		}

		$totals = self::blank_stats();
		foreach ( $rows as $stat ) {
			$totals['impressions'] += $stat['impressions'];
			$totals['add_to_cart'] += $stat['add_to_cart'];
			$totals['conversions'] += $stat['conversions'];
			$totals['revenue']     += $stat['revenue'];
		}
		?>
		<div class="wrap wc-order-upsales-stats">
			<h1><?php esc_html_e( 'סטטיסטיקות Order Upsales', 'wc-order-upsale' ); ?></h1>

			<?php if ( isset( $_GET['reset'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'הסטטיסטיקות אופסו.', 'wc-order-upsale' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'CTR = הוספות לסל מתוך חשיפות. שיעור המרה = רכישות שהושלמו מתוך הוספות לסל.', 'wc-order-upsale' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped wc-upsale-stats-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'מוצר', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'חשיפות', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'הוספות לסל', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'CTR', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'רכישות', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'שיעור המרה', 'wc-order-upsale' ); ?></th>
						<th><?php esc_html_e( 'הכנסות', 'wc-order-upsale' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'אין עדיין נתונים. הסטטיסטיקות יצטברו ברגע שלקוחות יראו את ה-Upsales בצ\'קאאוט.', 'wc-order-upsale' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $pid => $stat ) : ?>
							<?php
							$product = wc_get_product( $pid );
							$name    = $product ? $product->get_name() : sprintf( __( 'מוצר #%d (נמחק)', 'wc-order-upsale' ), $pid );
							$edit    = $product ? get_edit_post_link( $pid ) : '';
							?>
							<tr>
								<td>
									<?php if ( $edit ) : ?>
										<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $name ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $name ); ?>
									<?php endif; ?>
									<span class="description">#<?php echo esc_html( $pid ); ?></span>
								</td>
								<td><?php echo esc_html( number_format_i18n( $stat['impressions'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $stat['add_to_cart'] ) ); ?></td>
								<td><?php echo esc_html( self::format_rate( $stat['add_to_cart'], $stat['impressions'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $stat['conversions'] ) ); ?></td>
								<td><?php echo esc_html( self::format_rate( $stat['conversions'], $stat['add_to_cart'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $stat['revenue'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
				<?php if ( ! empty( $rows ) ) : ?>
					<tfoot>
						<tr>
							<th><?php esc_html_e( 'סה"כ', 'wc-order-upsale' ); ?></th>
							<th><?php echo esc_html( number_format_i18n( $totals['impressions'] ) ); ?></th>
							<th><?php echo esc_html( number_format_i18n( $totals['add_to_cart'] ) ); ?></th>
							<th><?php echo esc_html( self::format_rate( $totals['add_to_cart'], $totals['impressions'] ) ); ?></th>
							<th><?php echo esc_html( number_format_i18n( $totals['conversions'] ) ); ?></th>
							<th><?php echo esc_html( self::format_rate( $totals['conversions'], $totals['add_to_cart'] ) ); ?></th>
							<th><?php echo wp_kses_post( wc_price( $totals['revenue'] ) ); ?></th>
						</tr>
					</tfoot>
				<?php endif; ?>
			</table>

			<?php if ( ! empty( $rows ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					style="margin-top:20px"
					onsubmit="return confirm('<?php echo esc_js( __( 'לאפס את כל הסטטיסטיקות? פעולה זו אינה הפיכה.', 'wc-order-upsale' ) ); ?>');">
					<?php wp_nonce_field( 'wc_order_upsale_reset_stats', 'wc_order_upsale_reset_nonce' ); ?>
					<input type="hidden" name="action" value="wc_order_upsale_reset_stats">
					<button type="submit" class="button button-secondary" style="color:#b32d2e">
						<?php esc_html_e( 'אפס סטטיסטיקות', 'wc-order-upsale' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
