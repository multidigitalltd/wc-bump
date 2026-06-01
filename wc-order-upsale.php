<?php
/**
 * Plugin Name: WC Order Upsale
 * Description: הצג מוצרי אפסייל לפני התשלום בעמוד הצ'קאאוט
 * Version: 1.2.3
 * Author: Multi-Digital
 * Author URI: https://m-d.co.il
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.9
 * Text Domain: wc-order-upsale
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_ORDER_UPSALE_VERSION', '1.2.3' );
define( 'WC_ORDER_UPSALE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_ORDER_UPSALE_URL', plugin_dir_url( __FILE__ ) );

// HPOS compatibility declaration
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-admin.php';
	require_once WC_ORDER_UPSALE_PATH . 'includes/class-wc-order-upsale-frontend.php';

	new WC_Order_Upsale_Admin();
	new WC_Order_Upsale_Frontend();
} );
