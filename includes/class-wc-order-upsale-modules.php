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
				'name'          => __( 'אפסייל בהזמנה', 'wc-order-upsale' ),
				'description'   => __( 'הצגת מוצרי אפסייל בעמוד הצ׳קאאוט להגדלת ערך ההזמנה, כולל הנחות, תנאי הצגה וסטטיסטיקות.', 'wc-order-upsale' ),
				'settings_slug' => 'wc-order-upsales',
				'icon'          => 'cart',
				'default'       => true,
			],
			'variation_swatches' => [
				'name'          => __( 'וריאציות יפות', 'wc-order-upsale' ),
				'description'   => __( 'המרת תפריטי הווריאציות (מידה, אורך, צבע) לכפתורים עגולים ועיגולי צבע יפים בעמוד המוצר.', 'wc-order-upsale' ),
				'settings_slug' => 'wc-store-enhancer-swatches',
				'icon'          => 'art',
				'default'       => true,
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
			$states[ $id ] = array_key_exists( $id, $saved )
				? (bool) $saved[ $id ]
				: (bool) $def['default'];
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

	/** Admin URL of a module's settings page. */
	public static function settings_url( string $id ): string {
		$def = self::definitions()[ $id ] ?? null;
		if ( ! $def ) {
			return '';
		}
		return admin_url( 'admin.php?page=' . $def['settings_slug'] );
	}
}
