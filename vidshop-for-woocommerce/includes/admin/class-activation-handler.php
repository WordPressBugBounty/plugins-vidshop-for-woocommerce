<?php
/**
 * Activation Handler for VidShop for WooCommerce.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Admin;

/**
 * Activation handler class.
 */
class Activation_Handler {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initialize the activation handler.
	 */
	private function init() {
		// Register activation hook for redirect.
		register_activation_hook( VSFW_PLUGIN_FILE, array( $this, 'handle_activation' ) );

		// Handle the redirect on admin init.
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
	}

	/**
	 * Handle plugin activation.
	 */
	public function handle_activation() {
		// Add a transient to trigger redirect after activation.
		set_transient( 'vsfw_activation_redirect', true, 30 );
	}

	/**
	 * Handle activation redirect.
	 */
	public function maybe_redirect_after_activation() {
		// Check if we should redirect.
		if ( ! get_transient( 'vsfw_activation_redirect' ) ) {
			return;
		}

		// Delete the transient.
		delete_transient( 'vsfw_activation_redirect' );

		// Don't redirect if activating multiple plugins or in bulk.
		if ( isset( $_GET['activate-multi'] ) || is_network_admin() ) {
			return;
		}

		// Don't redirect if we're in an AJAX request or cron.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Redirect to plugin admin page.
		wp_safe_redirect( admin_url( 'admin.php?page=vsfw' ) );
		exit;
	}
}
