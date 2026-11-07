<?php
/**
 * Uninstall VidShop for WooCommerce: videos, feeds and settings are kept as before; only the shared opt-in record is handled.
 *
 * @package vidshop-for-woocommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Removes the shared opt-in options only when no other WPCreatix plugin still bundles the client.
require __DIR__ . '/lib/bridge-client/uninstall.php';
