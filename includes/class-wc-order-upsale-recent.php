<?php

defined( 'ABSPATH' ) || exit;

/**
 * Recently Viewed Products.
 *
 * Tracking is design-independent: each product view is recorded in the
 * visitor's own cookie on template_redirect, regardless of how the product page
 * is built (theme or Elementor). Display works two ways:
 *
 *   1. Elementor Loop Grid — set the grid's Query ID to "wc_recently_viewed" and
 *      the recently-viewed products render with the store's own Loop Item design.
 *   2. [wc_recently_viewed] shortcode — renders the theme's standard WooCommerce
 *      product cards anywhere.
 *
 * Research: recommendation/return-to-product surfaces lift discovery and repeat
 * purchases — Amazon attributes ~35% of revenue to recommendations (McKinsey).
 */
class WC_Order_Upsale_Recent {

	const OPTION = 'wc_store_enhancer_recent';
	const COOKIE = 'wcse_rv';
	const MAX    = 30;
	/** CSS class that marks an Elementor widget as the recently-viewed list. */
	const CSS_HOOK = 'wcse-recently-viewed';

	/** True while a marked Elementor widget is rendering. */
	private bool $capturing = false;

	/** True once the widget's own query has been rewritten this render. */
	private bool $applied = false;

	public function __construct() {
		add_filter( 'wc_store_enhancer_settings_tabs',            [ $this, 'register_settings_tab' ], 20 );
		add_action( 'admin_post_save_wc_store_enhancer_recent',   [ $this, 'save_settings' ] );

		add_action( 'template_redirect', [ $this, 'track' ] );
		// A page served from a full-page cache never reaches PHP, so the view
		// above is never recorded. Repeat it in the browser, where the cache
		// cannot get in the way.
		add_action( 'wp_footer', [ $this, 'print_tracker' ], 20 );
		add_shortcode( 'wc_recently_viewed', [ $this, 'shortcode' ] );

		// Elementor Loop Grid / Posts widget custom query (Query ID = wc_recently_viewed).
		add_action( 'elementor/query/wc_recently_viewed', [ $this, 'elementor_query' ] );

		// Elementor hides the Query ID field entirely when a Loop template's type
		// is Product (elementor/elementor#31316, open since Pro 3.29), which is
		// exactly the case for a product carousel — so the hook above can never be
		// reached there. Offer a second route keyed off the widget's CSS class,
		// a field that is always present, using only Elementor's core render
		// hooks rather than anything specific to Pro.
		add_action( 'elementor/frontend/widget/before_render', [ $this, 'elementor_widget_start' ] );
		add_action( 'elementor/frontend/widget/after_render',  [ $this, 'elementor_widget_end' ] );
	}

	/* ─────────────────────────── Settings ───────────────────────────── */

	public static function get_settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [
			'count'           => 8,
			'columns'         => 4,
			'exclude_current' => 1,
			'title'           => '',
			'bypass_cache'    => 1,
		] );
	}

	private function is_enabled(): bool {
		return ! class_exists( 'WC_Order_Upsale_Modules' )
			|| WC_Order_Upsale_Modules::is_enabled( 'recently_viewed' );
	}

	/* ─────────────────────────── Tracking ───────────────────────────── */

	public function track(): void {
		if ( ! $this->is_enabled() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$id = get_queried_object_id();
		if ( ! $id ) {
			return;
		}

		$ids = array_values( array_unique( array_merge( [ (int) $id ], array_diff( $this->stored_ids(), [ (int) $id ] ) ) ) );
		$ids = array_slice( $ids, 0, self::MAX );

		$value = implode( ',', $ids );
		if ( function_exists( 'wc_setcookie' ) ) {
			wc_setcookie( self::COOKIE, $value, time() + MONTH_IN_SECONDS );
		} else {
			setcookie( self::COOKIE, $value, time() + MONTH_IN_SECONDS, defined( 'COOKIEPATH' ) ? COOKIEPATH : '/', defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '' );
		}
		$_COOKIE[ self::COOKIE ] = $value; // Reflect for same-request reads.
	}

	/** All stored viewed IDs, newest first. */
	private function stored_ids(): array {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return [];
		}
		return wp_parse_id_list( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) );
	}

	/** Viewed IDs to display: exclude the current product (optionally) and cap. */
	private function display_ids( int $limit = 0 ): array {
		$settings = self::get_settings();
		$ids      = $this->stored_ids();

		if ( ! empty( $settings['exclude_current'] ) && function_exists( 'is_product' ) && is_product() ) {
			$ids = array_values( array_diff( $ids, [ get_queried_object_id() ] ) );
		}

		$limit = $limit > 0 ? $limit : (int) $settings['count'];
		return array_slice( $ids, 0, max( 1, $limit ) );
	}

	/**
	 * Products hidden from the catalog should stay hidden here too, and sold-out
	 * ones follow the store's own setting — WooCommerce's own recently-viewed
	 * widget applies exactly these, and a raw post__in query applies neither.
	 */
	private function visibility_tax_query(): array {
		$exclude = [ 'exclude-from-catalog' ];
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$exclude[] = 'outofstock';
		}

		return [
			[
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => $exclude,
				'operator' => 'NOT IN',
			],
		];
	}

	/**
	 * Keep a page that shows one visitor's history out of the page cache.
	 *
	 * Without this a full-page cache stores the first visitor's list and serves
	 * it to everyone after them — wrong for them, and a leak of what the first
	 * one was browsing. Honoured by the major caching plugins.
	 */
	private function prevent_caching(): void {
		if ( empty( self::get_settings()['bypass_cache'] ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/** Mirrors track() in the browser, for pages that never reach PHP. */
	public function print_tracker(): void {
		if ( ! $this->is_enabled() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( ! $id ) {
			return;
		}
		$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		?>
		<script>
		(function () {
			var name = <?php echo wp_json_encode( self::COOKIE ); ?>,
				id   = <?php echo wp_json_encode( (string) $id ); ?>,
				max  = <?php echo (int) self::MAX; ?>,
				path = <?php echo wp_json_encode( $path ); ?>;
			var hit = document.cookie.match( new RegExp( '(?:^|;\\s*)' + name + '=([^;]*)' ) );
			var ids = hit ? decodeURIComponent( hit[ 1 ] ).split( ',' ).filter( Boolean ) : [];
			ids = ids.filter( function ( v ) { return v !== id; } );
			ids.unshift( id );
			ids = ids.slice( 0, max );
			document.cookie = name + '=' + encodeURIComponent( ids.join( ',' ) )
				+ ';path=' + path + ';max-age=' + ( 30 * 24 * 60 * 60 ) + ';samesite=lax'
				+ ( 'https:' === location.protocol ? ';secure' : '' );
		})();
		</script>
		<?php
	}

	/* ─────────────────────────── Display ────────────────────────────── */

	/** Does this widget carry the marker class? */
	private function is_marked_widget( $widget ): bool {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_settings_for_display' ) ) {
			return false;
		}
		$classes = $widget->get_settings_for_display( '_css_classes' );
		if ( ! is_string( $classes ) || '' === $classes ) {
			return false;
		}
		return in_array( self::CSS_HOOK, preg_split( '/\s+/', trim( $classes ) ) ?: [], true );
	}

	public function elementor_widget_start( $widget ): void {
		if ( ! $this->is_enabled() || ! $this->is_marked_widget( $widget ) ) {
			return;
		}
		$this->capturing = true;
		$this->applied   = false;
		add_action( 'pre_get_posts', [ $this, 'force_recent_query' ], 9999 );
		$this->prevent_caching();
	}

	public function elementor_widget_end( $widget ): void {
		if ( ! $this->capturing ) {
			return;
		}
		remove_action( 'pre_get_posts', [ $this, 'force_recent_query' ], 9999 );
		$this->capturing = false;
		$this->applied   = false;
	}

	/**
	 * Rewrite the marked widget's product query. Scoped to the render window and
	 * applied only once, because the loop item template rendered inside it may
	 * run product queries of its own that must be left alone.
	 */
	public function force_recent_query( $query ): void {
		if ( ! $this->capturing || $this->applied || ! is_object( $query ) || ! method_exists( $query, 'set' ) ) {
			return;
		}
		if ( method_exists( $query, 'is_main_query' ) && $query->is_main_query() ) {
			return;
		}
		if ( ! in_array( 'product', (array) $query->get( 'post_type' ), true ) ) {
			return;
		}

		$this->applied = true;
		$this->apply_recent_args( $query );
	}

	/** The shared "show exactly these, in this order" arguments. */
	private function apply_recent_args( $query ): void {
		$ids = $this->display_ids();

		$query->set( 'post_type', 'product' );
		$query->set( 'post_status', 'publish' );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( 'no_found_rows', true );

		if ( empty( $ids ) ) {
			$query->set( 'post__in', [ 0 ] ); // Force an empty result set.
			return;
		}

		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'posts_per_page', count( $ids ) );
		$query->set( 'tax_query', $this->visibility_tax_query() );
	}

	/** Elementor custom-query callback: feed the viewed products into the grid. */
	public function elementor_query( $query ): void {
		if ( ! is_object( $query ) || ! method_exists( $query, 'set' ) ) {
			return;
		}
		if ( ! $this->is_enabled() ) {
			$query->set( 'post__in', [ 0 ] );
			return;
		}

		$this->apply_recent_args( $query );
		$this->prevent_caching();
	}

	/** Shortcode [wc_recently_viewed count="8" columns="4" title="..."]. */
	public function shortcode( $atts ): string {
		if ( ! $this->is_enabled() ) {
			return '';
		}

		$settings = self::get_settings();
		$atts     = shortcode_atts( [
			'count'   => (int) $settings['count'],
			'columns' => (int) $settings['columns'],
			'title'   => $settings['title'],
		], $atts, 'wc_recently_viewed' );

		$ids = $this->display_ids( (int) $atts['count'] );
		if ( empty( $ids ) ) {
			return '';
		}

		$query = new WP_Query( [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'post__in'            => $ids,
			'orderby'             => 'post__in',
			'posts_per_page'      => count( $ids ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'tax_query'           => $this->visibility_tax_query(),
		] );

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();
			return '';
		}

		$columns = max( 1, (int) $atts['columns'] );
		if ( function_exists( 'wc_set_loop_prop' ) ) {
			wc_set_loop_prop( 'columns', $columns );
		}

		ob_start();

		if ( '' !== (string) $atts['title'] ) {
			echo '<h2 class="wcse-recent-title">' . esc_html( $atts['title'] ) . '</h2>';
		}

		echo '<div class="wcse-recent woocommerce">';
		woocommerce_product_loop_start();
		while ( $query->have_posts() ) {
			$query->the_post();
			wc_get_template_part( 'content', 'product' );
		}
		woocommerce_product_loop_end();
		echo '</div>';

		wp_reset_postdata();
		// Our column override would otherwise leak into any later product loop
		// on the same page.
		if ( function_exists( 'wc_reset_loop' ) ) {
			wc_reset_loop();
		}

		$this->prevent_caching();

		return ob_get_clean();
	}

	/* ─────────────────────────── Settings tab ───────────────────────── */

	public function register_settings_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'recent',
			'label'    => __( 'נצפו לאחרונה', 'wc-order-upsale' ),
			'callback' => [ $this, 'render_settings_tab' ],
		];
		return $tabs;
	}

	public function save_settings(): void {
		if ( ! check_admin_referer( 'wc_store_enhancer_recent_save', 'wc_store_enhancer_recent_nonce' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		update_option( self::OPTION, [
			'count'           => max( 1, min( self::MAX, absint( $_POST['count'] ?? 8 ) ) ),
			'columns'         => max( 1, min( 8, absint( $_POST['columns'] ?? 4 ) ) ),
			'exclude_current' => empty( $_POST['exclude_current'] ) ? 0 : 1,
			'bypass_cache'    => empty( $_POST['bypass_cache'] ) ? 0 : 1,
			'title'           => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::SETTINGS_SLUG . '&tab=recent&saved=1' ) );
		exit;
	}

	public function render_settings_tab(): void {
		$settings = self::get_settings();
		?>
		<div class="wcse-admin">
			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'עוקב אחר המוצרים שהמבקר צפה בהם (בעוגייה, ללא התחברות) ומאפשר להציג "נצפו לאחרונה".', 'wc-order-upsale' ); ?>
			</p>

			<?php if ( ! $this->is_enabled() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'המודול כבוי כרגע. הפעילו אותו מלוח הבקרה.', 'wc-order-upsale' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WC_Order_Upsale_Dashboard::MENU_SLUG ) ); ?>"><?php esc_html_e( 'לוח הבקרה', 'wc-order-upsale' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'ההגדרות נשמרו בהצלחה.', 'wc-order-upsale' ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info inline" style="max-width:820px">
				<p><strong><?php esc_html_e( 'דרכי הצגה:', 'wc-order-upsale' ); ?></strong></p>
				<p>
					<strong>1. <?php esc_html_e( 'Elementor — מומלץ, ועובד גם עם תבנית מסוג "מוצר":', 'wc-order-upsale' ); ?></strong><br>
					<?php esc_html_e( 'גררו Loop Grid או Loop Carousel, בחרו את תבנית ה-Loop Item שלכם, ובלשונית "מתקדם" ← "מזהה CSS ומחלקות CSS" הוסיפו בשדה מחלקות CSS:', 'wc-order-upsale' ); ?>
					<code><?php echo esc_html( self::CSS_HOOK ); ?></code><br>
					<span class="description"><?php esc_html_e( 'כל שאר הגדרות השאילתא בווידג׳ט (מקור, סדר לפי, מיון) נדרסות — אין צורך לכוונן אותן.', 'wc-order-upsale' ); ?></span>
				</p>
				<p>
					<strong>2. Elementor — Query ID:</strong>
					<?php esc_html_e( 'בשדה שאילתא ← Query ID הזינו', 'wc-order-upsale' ); ?>
					<code>wc_recently_viewed</code><br>
					<span class="description"><?php esc_html_e( 'אלמנטור מסתיר את השדה הזה כשסוג תבנית ה-Loop הוא "מוצר" (באג פתוח אצלם מאז Pro 3.29). אם השדה לא מופיע — השתמשו באפשרות 1.', 'wc-order-upsale' ); ?></span>
				</p>
				<p>
					<strong>3. <?php esc_html_e( 'שורטקוד (כרטיס ברירת המחדל של התבנית):', 'wc-order-upsale' ); ?></strong>
					<code>[wc_recently_viewed count="8" columns="4" title="נצפו לאחרונה"]</code>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wc_store_enhancer_recent_save', 'wc_store_enhancer_recent_nonce' ); ?>
				<input type="hidden" name="action" value="save_wc_store_enhancer_recent">

				<table class="form-table">
					<tr>
						<th scope="row"><label for="wcse-rv-count"><?php esc_html_e( 'כמות מוצרים', 'wc-order-upsale' ); ?></label></th>
						<td><input type="number" id="wcse-rv-count" name="count" value="<?php echo esc_attr( (string) $settings['count'] ); ?>" min="1" max="<?php echo esc_attr( (string) self::MAX ); ?>" style="width:80px"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-rv-columns"><?php esc_html_e( 'עמודות (לשורטקוד)', 'wc-order-upsale' ); ?></label></th>
						<td><input type="number" id="wcse-rv-columns" name="columns" value="<?php echo esc_attr( (string) $settings['columns'] ); ?>" min="1" max="8" style="width:80px"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'החרגת המוצר הנוכחי', 'wc-order-upsale' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="exclude_current" value="0">
								<input type="checkbox" name="exclude_current" value="1" <?php checked( ! empty( $settings['exclude_current'] ) ); ?>>
								<?php esc_html_e( 'לא להציג את המוצר שבו צופים כרגע ברשימה.', 'wc-order-upsale' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'קאש', 'wc-order-upsale' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="bypass_cache" value="0">
								<input type="checkbox" name="bypass_cache" value="1" <?php checked( ! empty( $settings['bypass_cache'] ) ); ?>>
								<?php esc_html_e( 'אל תאחסנו בקאש עמודים שמציגים את הרשימה (מומלץ)', 'wc-order-upsale' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'הרשימה אישית לכל מבקר. בלי זה, תוסף קאש שומר את הרשימה של המבקר הראשון ומגיש אותה לכל מי שאחריו — גם שגוי עבורם, וגם חושף במה המבקר הראשון עיין. כיבוי משפר ביצועים אבל מחזיר את הבעיה.', 'wc-order-upsale' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wcse-rv-title"><?php esc_html_e( 'כותרת (לשורטקוד)', 'wc-order-upsale' ); ?></label></th>
						<td><input type="text" id="wcse-rv-title" name="title" value="<?php echo esc_attr( $settings['title'] ); ?>" placeholder="<?php esc_attr_e( 'נצפו לאחרונה', 'wc-order-upsale' ); ?>" class="regular-text"></td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'שמור הגדרות', 'wc-order-upsale' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
