<?php
/**
 * Plugin Name: משפר חנויות ווקומרס
 * Description: משפר חנויות ווקומרס — אפסייל בצ'קאאוט + הצגת וריאציות המוצר ככפתורים יפים (מידה, אורך, צבע).
 * Version: 1.12.0
 * Author: Multi Digital
 * Author URI: https://m-d.co.il
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.9
 * Text Domain: wc-order-upsale
 */

defined( 'ABSPATH' ) || exit;

// Guard against a second copy of this plugin being active (e.g. installed under
// a different folder name). Loading it twice would fatally "Cannot redeclare
// class"; instead the duplicate copy bails out and shows an admin notice.
if ( defined( 'WC_ORDER_UPSALE_VERSION' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'משפר המרות ווקומרס מותקן פעמיים (שני עותקים בתיקיות שונות). מחקו את העותק הכפול והשאירו אחד בלבד כדי למנוע שגיאה.', 'wc-order-upsale' )
			. '</p></div>';
	} );
	return;
}

define( 'WC_ORDER_UPSALE_VERSION', '1.12.0' );
define( 'WC_ORDER_UPSALE_FILE', __FILE__ );
define( 'WC_ORDER_UPSALE_BASENAME', plugin_basename( __FILE__ ) );
define( 'WC_ORDER_UPSALE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_ORDER_UPSALE_URL', plugin_dir_url( __FILE__ ) );

// Create the plugin's custom tables on activation.
register_activation_hook( __FILE__, function () {
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-analytics.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-backinstock.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-abandoned.php';
	WC_Order_Upsale_Analytics::create_table();
	WC_Order_Upsale_Backinstock::create_table();
	WC_Order_Upsale_Abandoned::create_table();
} );

// Clear scheduled events on deactivation.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'wcse_ab_scan' );
} );

// HPOS compatibility declaration
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// Self-update checker — runs independently of WooCommerce so the site can always
// pull new versions of the plugin from the configured source (GitHub / custom URL).
require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-updater.php';
new WC_Order_Upsale_Updater( WC_ORDER_UPSALE_FILE );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-modules.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-dashboard.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-admin.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-frontend.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-analytics.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-variation-swatches.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-delivery.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-recent.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-backinstock.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-sticky.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-abandoned.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-buynow.php';

	new WC_Order_Upsale_Dashboard();
	new WC_Order_Upsale_Admin();
	new WC_Order_Upsale_Frontend();
	new WC_Order_Upsale_Analytics();
	new WC_Order_Upsale_Variation_Swatches();
	new WC_Order_Upsale_Delivery();
	new WC_Order_Upsale_Recent();
	new WC_Order_Upsale_Backinstock();
	new WC_Order_Upsale_Sticky();
	new WC_Order_Upsale_Abandoned();
	new WC_Order_Upsale_Buynow();
} );
