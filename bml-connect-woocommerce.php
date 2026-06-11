<?php
/**
 * Plugin Name: BML Connect for WooCommerce
 * Plugin URI:  https://github.com/islandboy/bml-connect-woocommerce
 * Description: First-party WooCommerce payment gateway for Bank of Maldives (BML) Connect. Redirect-based card / MobilePay payments with signature-verified webhooks. HPOS + Blocks checkout ready. Reusable across projects.
 * Author:      Islandboy
 * Version:     1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.7
 * Text Domain: bml-connect
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMLC_VERSION', '1.1.0' );
define( 'BMLC_FILE', __FILE__ );
define( 'BMLC_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMLC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS (custom order tables) and Cart/Checkout Blocks compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BMLC_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BMLC_FILE, true );
	}
} );

/**
 * Register the gateway once WooCommerce is loaded.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p>'
				. esc_html__( 'BML Connect for WooCommerce requires WooCommerce to be installed and active.', 'bml-connect' )
				. '</p></div>';
		} );
		return;
	}

	require_once BMLC_PATH . 'includes/class-bmlc-client.php';
	require_once BMLC_PATH . 'includes/class-bmlc-gateway.php';

	add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
		$methods[] = 'BMLC_Gateway';
		return $methods;
	} );

	// Admin AJAX: "Test BML connection" button on the settings screen.
	add_action( 'wp_ajax_bmlc_test_connection', array( 'BMLC_Gateway', 'ajax_test_connection' ) );
}, 11 );

/**
 * Settings shortcut on the Plugins screen.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bml_connect' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bml-connect' ) . '</a>' );
	return $links;
} );

/**
 * Register the WooCommerce Blocks (block checkout) integration.
 */
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once BMLC_PATH . 'includes/class-bmlc-blocks.php';
	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		$registry->register( new BMLC_Blocks_Support() );
	} );
} );
