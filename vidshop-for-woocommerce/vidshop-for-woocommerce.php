<?php
/*
Plugin Name: VidShop for WooCommerce
Description: Upload your own videos and display WooCommerce products inside them. Let users interact and add items to cart while watching. Lightweight, fast, and fully integrated with WooCommerce.
Version: 1.6.0
Author: WPCreatix
Author URI: https://wpcreatix.com/
Plugin URI: https://wpcreatix.com/
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: vidshop-for-woocommerce
Domain Path: /languages
Requires Plugins: woocommerce
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VSFW_VERSION', '1.6.0' );
define( 'VSFW_MIN_PRO_VERSION', '1.4.0' );
define( 'VSFW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VSFW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VSFW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'VSFW_PLUGIN_FILE', __FILE__ );

if ( ! defined( 'VSFW_CLOUD_API_BASE' ) ) {
	define( 'VSFW_CLOUD_API_BASE', 'https://app.wpcreatix.com' );
}

if ( ! defined( 'VSFW_CLOUD_WEB_BASE' ) ) {
	define( 'VSFW_CLOUD_WEB_BASE', 'https://app.wpcreatix.com' );
}

// Load the autoloader.
require_once VSFW_PLUGIN_DIR . 'includes/autoload.php';

/**
 * Initialize the plugin.
 */
function vsfw_woocommerce() {
	VSFW\Plugin::instance();
}



/*
 * Declare High-Performance Order Storage compatibility.
 *
 * Required now that order attribution reads and writes orders: every access goes through the CRUD
 * API (`wc_get_order()`, `$order->get_items()`, `$item->get_meta()`), never postmeta or a WP_Query
 * on `shop_order`, so both storage backends work. Without this declaration a store with HPOS
 * enforced lists VidShop as an incompatible plugin.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Load the plugin.
add_action(
	'plugins_loaded',
	function () {
		// Allow developers to hook into before the plugin is fully loaded. e.g. to register custom modules.
		do_action( 'vsfw_loaded' );

		// Initialize the plugin.
		vsfw_woocommerce();
	}
);
