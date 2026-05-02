<?php
/**
 * Settings service.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Services;

use VSFW\Interfaces\Settings as Settings_Interface;

/**
 * Settings service.
 */
class Settings implements Settings_Interface {

	/**
	 * Option name
	 *
	 * @var string
	 */
	const OPTION_NAME = 'svfw_settings';

	/**
	 * Get all settings
	 *
	 * @return array
	 */
	public function get_all_settings() {
		return get_option( self::OPTION_NAME );
	}

	/**
	 * Get setting
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = null ) {
		$settings = $this->get_all_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Update setting
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public function update_setting( $key, $value ) {
		$settings         = $this->get_all_settings();
		$settings[ $key ] = $value;
		return update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * Update all settings
	 *
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function update_all_settings( $settings ) {
		return update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * Resolve the effective currency format, falling back to WooCommerce defaults.
	 *
	 * Any field stored as null/empty string means "inherit from WooCommerce".
	 *
	 * @return array{
	 *     symbol:string,
	 *     position:string,
	 *     thousand_sep:string,
	 *     decimal_sep:string,
	 *     num_decimals:int,
	 *     prefix:string,
	 *     suffix:string
	 * }
	 */
	public function get_currency_format() {
		$settings = $this->get_all_settings();

		$symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
			: '';

		$wc_position     = function_exists( 'get_option' ) ? get_option( 'woocommerce_currency_pos', 'left' ) : 'left';
		$wc_thousand     = function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',';
		$wc_decimal      = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
		$wc_num_decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		$position     = ( isset( $settings['currency_position'] ) && $settings['currency_position'] !== '' && $settings['currency_position'] !== null )
			? $settings['currency_position']
			: $wc_position;
		$thousand_sep = ( isset( $settings['price_thousand_sep'] ) && $settings['price_thousand_sep'] !== null )
			? $settings['price_thousand_sep']
			: $wc_thousand;
		$decimal_sep  = ( isset( $settings['price_decimal_sep'] ) && $settings['price_decimal_sep'] !== null && $settings['price_decimal_sep'] !== '' )
			? $settings['price_decimal_sep']
			: $wc_decimal;
		$num_decimals = ( isset( $settings['price_num_decimals'] ) && $settings['price_num_decimals'] !== null && $settings['price_num_decimals'] !== '' )
			? (int) $settings['price_num_decimals']
			: (int) $wc_num_decimals;

		// Derive prefix/suffix from position, matching WooCommerce's get_woocommerce_price_format().
		switch ( $position ) {
			case 'left':
				$prefix = $symbol;
				$suffix = '';
				break;
			case 'right':
				$prefix = '';
				$suffix = $symbol;
				break;
			case 'left_space':
				$prefix = $symbol . "\u{00A0}";
				$suffix = '';
				break;
			case 'right_space':
				$prefix = '';
				$suffix = "\u{00A0}" . $symbol;
				break;
			default:
				$prefix = $symbol;
				$suffix = '';
				break;
		}

		return array(
			'symbol'       => $symbol,
			'position'     => $position,
			'thousand_sep' => $thousand_sep,
			'decimal_sep'  => $decimal_sep,
			'num_decimals' => $num_decimals,
			'prefix'       => $prefix,
			'suffix'       => $suffix,
		);
	}

	/**
	 * Format a price using the resolved currency format.
	 *
	 * Falls back to wc_price() when available so themes/WC filters apply.
	 *
	 * @param float|string $price Price value.
	 * @return string HTML-formatted price.
	 */
	public function format_price( $price ) {
		$format = $this->get_currency_format();

		$args = array(
			'currency'           => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'decimal_separator'  => $format['decimal_sep'],
			'thousand_separator' => $format['thousand_sep'],
			'decimals'           => $format['num_decimals'],
			'price_format'       => $this->position_to_wc_format( $format['position'] ),
		);

		if ( function_exists( 'wc_price' ) ) {
			return wc_price( (float) $price, $args );
		}

		$numeric   = number_format( (float) $price, $format['num_decimals'], $format['decimal_sep'], $format['thousand_sep'] );
		$formatted = $format['prefix'] . $numeric . $format['suffix'];
		return '<span class="woocommerce-Price-amount amount">' . esc_html( $formatted ) . '</span>';
	}

	/**
	 * Map our position key to WooCommerce's price_format string.
	 *
	 * @param string $position Position key.
	 * @return string
	 */
	private function position_to_wc_format( $position ) {
		switch ( $position ) {
			case 'left':
				return '%1$s%2$s';
			case 'right':
				return '%2$s%1$s';
			case 'left_space':
				return '%1$s&nbsp;%2$s';
			case 'right_space':
				return '%2$s&nbsp;%1$s';
			default:
				return '%1$s%2$s';
		}
	}
}
