<?php
/**
 * Plugin Name: משפר חנויות ווקומרס
 * Description: משפר חנויות ווקומרס — אפסייל בצ'קאאוט + הצגת וריאציות המוצר ככפתורים יפים (מידה, אורך, צבע).
 * Version: 1.7.1
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

define( 'WC_ORDER_UPSALE_VERSION', '1.7.1' );
define( 'WC_ORDER_UPSALE_FILE', __FILE__ );
define( 'WC_ORDER_UPSALE_BASENAME', plugin_basename( __FILE__ ) );
define( 'WC_ORDER_UPSALE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_ORDER_UPSALE_URL', plugin_dir_url( __FILE__ ) );

// Create the analytics stats table on activation.
register_activation_hook( __FILE__, function () {
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-analytics.php';
	WC_Order_Upsale_Analytics::create_table();
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

	new WC_Order_Upsale_Dashboard();
	new WC_Order_Upsale_Admin();
	new WC_Order_Upsale_Frontend();
	new WC_Order_Upsale_Analytics();
	new WC_Order_Upsale_Variation_Swatches();
} );
