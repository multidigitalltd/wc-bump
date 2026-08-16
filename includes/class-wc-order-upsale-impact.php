<?php

defined( 'ABSPATH' ) || exit;

/**
 * Cross-module impact reporting.
 *
 * Answers "what did these modules actually do for the shop" without adding any
 * weight to the storefront. The design constraint is deliberate:
 *
 *   - No front-end assets, no extra HTTP requests, no per-pageview writes.
 *   - Attribution happens on the server at points that already run: the
 *     add-to-cart request and the order status change.
 *   - One write per attributed order, into a table that holds one row per
 *     module per day.
 *
 * Two modules already keep their own numbers (the upsale card in the analytics
 * table, cart recovery in its own) and are read from there rather than counted
 * twice.
 *
 * What this measures is participation, not causation: an order is credited to a
 * module when the shopper actually used that module on the way to it. It says
 * how much revenue passed through each feature, not how much would have been
 * lost without it.
 */
class WC_Order_Upsale_Impact {

	const DB_VERSION_OPT = 'wc_store_enhancer_impact_db_version';
	const DB_VERSION     = '1.0.0';
	const CACHE_KEY      = 'wcse_impact_totals';
	/** How long after a back-in-stock mail an order still counts as following it. */
	const BIS_WINDOW_DAYS = 30;

	/** Session key holding the modules used on the way to the current order. */
	const SESSION_KEY = 'wcse_impact_modules';

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_create_table' ] );

		// Mark the modules a shopper actually used. Both run inside an
		// add-to-cart request that is already hitting PHP.
		add_action( 'woocommerce_add_to_cart', [ $this, 'mark_from_request' ], 10, 0 );

		// Credit the order once it is real money.
		add_action( 'woocommerce_order_status_processing', [ $this, 'record_order' ], 25, 1 );
		add_action( 'woocommerce_order_status_completed',  [ $this, 'record_order' ], 25, 1 );
	}

	/* ─────────────────────────── Storage ────────────────────────────── */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_store_enhancer_impact';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			module VARCHAR(32) NOT NULL,
			stat_date DATE NOT NULL,
			orders INT UNSIGNED NOT NULL DEFAULT 0,
			revenue DECIMAL(18,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (module, stat_date),
			KEY stat_date (stat_date)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPT, self::DB_VERSION );
	}

	public function maybe_create_table(): void {
		if ( get_option( self::DB_VERSION_OPT ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * Add one attributed order to a module's running total.
	 *
	 * Upsert rather than read-modify-write, so two orders landing at the same
	 * moment cannot lose a count between them.
	 */
	private static function bump( string $module, float $revenue ): void {
		if ( '' === $module ) {
			return;
		}

		global $wpdb;
		$table = self::table();

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"INSERT INTO {$table} (module, stat_date, orders, revenue) VALUES (%s, %s, 1, %f)
			 ON DUPLICATE KEY UPDATE orders = orders + 1, revenue = revenue + %f",
			$module,
			current_time( 'Y-m-d' ),
			$revenue,
			$revenue
		) );

		delete_transient( self::CACHE_KEY );
	}

	/* ─────────────────────────── Attribution ────────────────────────── */

	/** Remember that a module was used, for the length of this shopping session. */
	public static function mark( string $module ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$used = (array) WC()->session->get( self::SESSION_KEY, [] );
		if ( in_array( $module, $used, true ) ) {
			return;
		}
		$used[] = $module;
		WC()->session->set( self::SESSION_KEY, $used );
	}

	/**
	 * Both flags ride along on WooCommerce's own add-to-cart form, which carries
	 * no nonce of its own — they only affect reporting, never price or contents.
	 */
	public function mark_from_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['wcse_buy_now'] ) ) {
			self::mark( 'buy_now' );
		}
		if ( ! empty( $_REQUEST['wcse_sticky'] ) ) {
			self::mark( 'sticky_atc' );
		}
		// phpcs:enable
	}

	/** Modules used this session, then cleared so the next order starts clean. */
	private function take_session_modules(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return [];
		}
		$used = (array) WC()->session->get( self::SESSION_KEY, [] );
		WC()->session->set( self::SESSION_KEY, [] );
		return array_filter( array_map( 'strval', $used ) );
	}

	/**
	 * Did this order follow a back-in-stock notice we sent?
	 *
	 * One indexed lookup on columns the table already keys, and only for orders
	 * that carry a billing address.
	 */
	private function followed_back_in_stock( WC_Order $order ): bool {
		if ( ! class_exists( 'WC_Order_Upsale_Backinstock' ) ) {
			return false;
		}
		$email = $order->get_billing_email();
		if ( ! $email ) {
			return false;
		}

		$ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( method_exists( $item, 'get_product_id' ) ) {
				$ids[] = (int) $item->get_product_id();
				$ids[] = (int) $item->get_variation_id();
			}
		}
		$ids = array_values( array_filter( array_unique( $ids ) ) );
		if ( empty( $ids ) ) {
			return false;
		}

		global $wpdb;
		$table  = WC_Order_Upsale_Backinstock::table();
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( self::BIS_WINDOW_DAYS * DAY_IN_SECONDS ) );
		$params = array_merge( [ $email, $since ], $ids, $ids );

		$found = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT id FROM {$table}
			 WHERE email = %s AND notified_at IS NOT NULL AND notified_at >= %s
			   AND ( product_id IN ({$in}) OR variation_id IN ({$in}) )
			 LIMIT 1",
			$params
		) );

		return ! empty( $found );
	}

	/**
	 * Credit every module that took part in this order.
	 *
	 * Guarded by order meta because both status hooks can fire for the same
	 * order, and an order that reaches processing then completed must not be
	 * counted twice.
	 *
	 * @param int|WC_Order $order Order or id.
	 */
	public function record_order( $order ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || $order->get_meta( '_wcse_impact_recorded' ) ) {
			return;
		}

		$modules = $this->take_session_modules();

		if ( $this->followed_back_in_stock( $order ) ) {
			$modules[] = 'back_in_stock';
		}

		$modules = array_unique( $modules );
		if ( empty( $modules ) ) {
			// Still stamp it, so a later status change does not re-run the work.
			$order->update_meta_data( '_wcse_impact_recorded', 1 );
			$order->save();
			return;
		}

		$revenue = (float) $order->get_total();
		foreach ( $modules as $module ) {
			self::bump( $module, $revenue );
		}

		$order->update_meta_data( '_wcse_impact_recorded', 1 );
		$order->save();
	}

	/* ─────────────────────────── Reporting ──────────────────────────── */

	/**
	 * Everything the dashboard shows, in one cached shape.
	 *
	 * Three aggregate queries, none of them per-row, cached for a few minutes —
	 * the dashboard is an admin page, not a hot path.
	 *
	 * @return array<string,array{orders:int,revenue:float,label:string,note:string}>
	 */
	public static function totals(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$out = [];

		// Modules attributed here.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			'SELECT module, SUM(orders) AS orders, SUM(revenue) AS revenue FROM ' . self::table() . ' GROUP BY module',
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['module'] ] = [
				'orders'  => (int) $row['orders'],
				'revenue' => (float) $row['revenue'],
			];
		}

		// The upsale card keeps richer numbers of its own — read, never re-count.
		if ( class_exists( 'WC_Order_Upsale_Analytics' ) ) {
			$stats = WC_Order_Upsale_Analytics::totals();
			$out['order_upsale'] = [
				'orders'      => (int) ( $stats['conversions'] ?? 0 ),
				'revenue'     => (float) ( $stats['revenue'] ?? 0 ),
				'impressions' => (int) ( $stats['impressions'] ?? 0 ),
				'add_to_cart' => (int) ( $stats['add_to_cart'] ?? 0 ),
			];
		}

		// Cart recovery likewise.
		if ( class_exists( 'WC_Order_Upsale_Abandoned' ) && method_exists( 'WC_Order_Upsale_Abandoned', 'stats' ) ) {
			$ab = WC_Order_Upsale_Abandoned::stats();
			$out['abandoned_cart'] = [
				'orders'  => (int) ( $ab['recovered'] ?? 0 ),
				'revenue' => (float) ( $ab['revenue'] ?? 0 ),
				'sent'    => (int) ( $ab['sent'] ?? 0 ),
			];
		}

		set_transient( self::CACHE_KEY, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/** Orders and revenue summed across every module, for the headline figures. */
	public static function headline(): array {
		$orders  = 0;
		$revenue = 0.0;
		foreach ( self::totals() as $row ) {
			$orders  += (int) ( $row['orders'] ?? 0 );
			$revenue += (float) ( $row['revenue'] ?? 0 );
		}
		return [ 'orders' => $orders, 'revenue' => $revenue ];
	}
}
