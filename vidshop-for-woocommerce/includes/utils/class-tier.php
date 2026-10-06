<?php
/**
 * Free/Pro tier checks shared by the REST layer and the abilities layer.
 *
 * @package VSFW
 */

namespace VSFW\Utils;

/**
 * Answers "is Pro active" and names the storefront settings Free may not set.
 *
 * Pro answers the `vsfw_is_pro` filter from its licence state, so a lapsed
 * licence counts as Free here too.
 */
class Tier {

	/**
	 * Storefront config values Free is held to. Mirrors normalizeConfigForTier() in the admin.
	 *
	 * @var array
	 */
	const FREE_CONFIG = array(
		'layout'                    => 'grid',
		'columns'                   => array(
			'desktop' => 4,
			'tablet'  => 3,
			'mobile'  => 2,
		),
		'tags'                      => array(),
		'tags_operator'             => 'OR',
		'show_arrows'               => true,
		'show_dots'                 => true,
		'auto_open_product_details' => false,
	);

	/**
	 * Whether the Pro tier is active.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		return (bool) apply_filters( 'vsfw_is_pro', false );
	}

	/**
	 * First storefront config key carrying a Pro-only value, or null.
	 *
	 * Only keys present in the request are judged, so a partial config that
	 * leaves a Pro key out still passes.
	 *
	 * @param mixed $config Raw config from the request.
	 * @return string|null
	 */
	public static function pro_only_config_key( $config ) {
		if ( self::is_pro() || ! is_array( $config ) ) {
			return null;
		}

		foreach ( self::FREE_CONFIG as $key => $free_value ) {
			if ( ! array_key_exists( $key, $config ) ) {
				continue;
			}

			if ( ! self::equals_free_value( $config[ $key ], $free_value ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Compare a request value with its Free default, tolerating string forms.
	 *
	 * @param mixed $value      Value from the request.
	 * @param mixed $free_value Free default.
	 * @return bool
	 */
	private static function equals_free_value( $value, $free_value ) {
		if ( is_array( $free_value ) ) {
			if ( ! is_array( $value ) ) {
				return empty( $value );
			}

			if ( empty( $free_value ) ) {
				return empty( array_filter( $value ) );
			}

			foreach ( $free_value as $key => $default ) {
				if ( array_key_exists( $key, $value ) && (int) $value[ $key ] !== (int) $default ) {
					return false;
				}
			}

			return true;
		}

		if ( is_bool( $free_value ) ) {
			return rest_sanitize_boolean( $value ) === $free_value;
		}

		return 0 === strcasecmp( (string) $value, (string) $free_value );
	}
}
