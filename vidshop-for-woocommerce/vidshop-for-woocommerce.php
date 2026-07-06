<?php
/*
Plugin Name: VidShop for WooCommerce
Description: Upload your own videos and display WooCommerce products inside them. Let users interact and add items to cart while watching. Lightweight, fast, and fully integrated with WooCommerce.
Version: 1.4.1
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

define( 'VSFW_VERSION', '1.4.1' );
define( 'VSFW_MIN_PRO_VERSION', '1.2.0' );
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
