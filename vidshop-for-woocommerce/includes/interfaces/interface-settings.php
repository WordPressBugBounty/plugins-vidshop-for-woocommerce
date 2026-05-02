<?php
/**
 * Settings interface.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Interfaces;

interface Settings {

	/**
	 * Get all settings
	 *
	 * @return array
	 */
	public function get_all_settings();

	/**
	 * Get setting
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = null );

	/**
	 * Update setting
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function update_setting( $key, $value );

	/**
	 * Update all settings
	 *
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function update_all_settings( $settings );

	/**
	 * Resolve the effective currency format (merged with WooCommerce defaults).
	 *
	 * @return array
	 */
	public function get_currency_format();

	/**
	 * Format a price using the resolved currency format.
	 *
	 * @param float|string $price Price value.
	 * @return string HTML-formatted price.
	 */
	public function format_price( $price );
}
