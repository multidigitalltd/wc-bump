<?php

defined( 'ABSPATH' ) || exit;

/**
 * Central registry of the plugin's feature modules.
 *
 * Each module has a stable id, presentation metadata and an enabled flag stored
 * in a single option. Features check WC_Order_Upsale_Modules::is_enabled( $id )
 * before wiring their frontend behaviour, and the dashboard renders one card per
 * module. Adding a future module is just one more entry in definitions().
 */
class WC_Order_Upsale_Modules {

	const OPTION = 'wc_store_enhancer_modules';

	/**
	 * All known modules keyed by id.
	 *
	 * @return array<string,array{name:string,description:string,settings_slug:string,icon:string,default:bool}>
	 */
	public static function definitions(): array {
		return [
			'order_upsale'       => [
				'name'           => __( 'אפסייל בהזמנה', 'wc-order-upsale' ),
				'description'    => __( 'הצגת מוצרי אפסייל בעמוד הצ׳קאאוט להגדלת ערך ההזמנה, כולל הנחות, תנאי הצגה וסטטיסטיקות.', 'wc-order-upsale' ),
				'settings_slug'  => 'wc-order-upsales',
				'icon'           => 'cart',
				'default'        => true,
				'benefit'        => __( 'הצעה רלוונטית ברגע הקנייה מגדילה את ערך ההזמנה הממוצע (AOV) בלי להביא תנועה חדשה.', 'wc-order-upsale' ),
				'benefit_source' => 'https://baymard.com/lists/cart-abandonment-rate',
			],
			'variation_swatches' => [
				'name'           => __( 'וריאציות יפות', 'wc-order-upsale' ),
				'description'    => __( 'המרת תפריטי הווריאציות (מידה, אורך, צבע) לכפתורים עגולים ועיגולי צבע יפים בעמוד המוצר.', 'wc-order-upsale' ),
				'settings_slug'  => 'wc-store-enhancer-settings',
				'settings_tab'   => 'swatches',
				'icon'           => 'art',
				'default'        => true,
				'benefit'        => __( 'בחירה חזותית של צבע/מידה מפחיתה עומס-החלטה וטעויות מול תפריט נפתח (Baymard, UX עמוד מוצר).', 'wc-order-upsale' ),
				'benefit_source' => 'https://baymard.com/blog/shipping-speed-vs-delivery-date',
			],
			'delivery_date'      => [
				'name'           => __( 'תאריך אספקה משוער', 'wc-order-upsale' ),
				'description'    => __( 'הצגת חלון אספקה משוער בעמוד המוצר, מחושב בימי עסקים עם החרגת שישי/שבת וחגים.', 'wc-order-upsale' ),
				'settings_slug'  => 'wc-store-enhancer-settings',
				'settings_tab'   => 'delivery',
				'icon'           => 'calendar-alt',
				'default'        => true,
				'benefit'        => __( 'תאריך אספקה מדויק העלה המרה בצ׳קאאוט ב-13%–25% במבחני A/B; 75% מהקונים מושפעים ממנו ו-40% לא יקנו בלעדיו (Baymard/Narvar).', 'wc-order-upsale' ),
				'benefit_source' => 'https://baymard.com/blog/shipping-speed-vs-delivery-date',
			],
			'recently_viewed'    => [
				'name'           => __( 'נצפו לאחרונה', 'wc-order-upsale' ),
				'description'    => __( 'מעקב אחר מוצרים שנצפו והצגתם דרך Loop Grid של Elementor (עם העיצוב שלכם) או בשורטקוד.', 'wc-order-upsale' ),
				'settings_slug'  => 'wc-store-enhancer-settings',
				'settings_tab'   => 'recent',
				'icon'           => 'backup',
				'default'        => true,
				'benefit'        => __( 'החזרת מבקרים למוצרים שכבר גילו מגדילה גילוי וקנייה חוזרת — לאמזון ~35% מההכנסות מהמלצות (McKinsey).', 'wc-order-upsale' ),
				'benefit_source' => 'https://www.firney.com/news-and-insights/ai-product-recommendations-from-amazons-35-revenue-model-to-your-e-commerce-platform',
			],
			'back_in_stock'      => [
				'name'           => __( 'עדכנו אותי כשחוזר למלאי', 'wc-order-upsale' ),
				'description'    => __( 'טופס איסוף שם + אימייל + אישור דיוור על מוצר/וריאציה שאזלו, עם מייל אוטומטי ללקוח כשחוזר למלאי.', 'wc-order-upsale' ),
				'settings_slug'  => 'wc-store-enhancer-settings',
				'settings_tab'   => 'backinstock',
				'icon'           => 'bell',
				'default'        => true,
				'benefit'        => __( 'התראות "חזר למלאי" הן מהסוגים הכי ממירים (20%–35% המרה) ומשחזרות מכירות שאבדו על מידות/צבעים שאזלו (Klaviyo/MarketingSherpa).', 'wc-order-upsale' ),
				'benefit_source' => 'https://marketingsherpa.com/article/case-study/backinstock-alert-emails-achieve-2245',
			],
		];
	}

	/**
	 * Enabled state for every module, falling back to each module's default when
	 * it has never been saved.
	 *
	 * @return array<string,bool>
	 */
	public static function get_states(): array {
		$saved  = (array) get_option( self::OPTION, [] );
		$states = [];
		foreach ( self::definitions() as $id => $def ) {
			if ( array_key_exists( $id, $saved ) ) {
				$states[ $id ] = (bool) $saved[ $id ];
				continue;
			}

			$default = (bool) $def['default'];

			// Honour the legacy per-feature "enabled" flag from before this
			// registry existed, so an explicit off is never silently flipped on.
			if ( 'variation_swatches' === $id ) {
				$legacy = (array) get_option( 'wc_store_enhancer_swatches', [] );
				if ( array_key_exists( 'enabled', $legacy ) ) {
					$default = (bool) $legacy['enabled'];
				}
			}

			$states[ $id ] = $default;
		}
		return $states;
	}

	public static function is_enabled( string $id ): bool {
		return self::get_states()[ $id ] ?? false;
	}

	/**
	 * Persist enabled state from a list of module ids that should be on.
	 *
	 * @param string[] $enabled_ids
	 */
	public static function save( array $enabled_ids ): void {
		$states = [];
		foreach ( self::definitions() as $id => $def ) {
			$states[ $id ] = in_array( $id, $enabled_ids, true ) ? 1 : 0;
		}
		update_option( self::OPTION, $states );
	}

	/** Admin URL of a module's settings page (with its tab, when applicable). */
	public static function settings_url( string $id ): string {
		$def = self::definitions()[ $id ] ?? null;
		if ( ! $def ) {
			return '';
		}
		$args = [ 'page' => $def['settings_slug'] ];
		if ( ! empty( $def['settings_tab'] ) ) {
			$args['tab'] = $def['settings_tab'];
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
