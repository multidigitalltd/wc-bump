<?php
/**
 * Plugin Name: WC Order Bump
 * Description: הצג מוצרי אפסייל לפני התשלום בעמוד הצ'קאאוט
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.9
 * Text Domain: wc-order-bump
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_ORDER_BUMP_VERSION', '1.0.0' );
define( 'WC_ORDER_BUMP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_ORDER_BUMP_URL', plugin_dir_url( __FILE__ ) );

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

	require_once WC_ORDER_BUMP_PATH . 'includes/class-wc-order-bump-admin.php';
	require_once WC_ORDER_BUMP_PATH . 'includes/class-wc-order-bump-frontend.php';

	new WC_Order_Bump_Admin();
	new WC_Order_Bump_Frontend();
} );
