<?php
/**
 * Storefront and analytics abilities.
 *
 * Both surfaces are exposed by dispatching internally to the plugin's own REST routes, so the
 * controllers stay the single source of truth for arg defaults, sanitising and error codes. The
 * callbacks return whatever `dispatch()` returns, unchanged — a controller WP_Error such as
 * `storefront_not_found` or `missing_dates` already carries its status and per-field `errors` map,
 * and re-wrapping it would only hide that from the caller.
 *
 * Create, duplicate and delete are deliberately NOT exposed. A hard-deleted storefront makes every
 * `[vidshop id="N"]` on the site render a missing-storefront notice and orphans its analytics rows,
 * so that stays a human decision.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Abilities;

use VSFW\Utils\Tier;

/**
 * Storefront and analytics ability definitions.
 */
class Storefronts_Abilities {

	/**
	 * Build this provider's ability definitions.
	 *
	 * Every callback is declared `static function ( $input = null )` because core invokes a callback
	 * with zero arguments when the input schema is empty; a bare `function ( $input )` would throw an
	 * ArgumentCountError that core converts into `ability_callback_exception`.
	 *
	 * No ability declares an output schema: all three payloads are controller-owned and wide, and
	 * output validation is enforced with no escape hatch.
	 *
	 * @param Abilities_Module $module Module instance supplying the shared helpers.
	 * @return array Map of ability name => wp_register_ability() args.
	 */
	public static function definitions( $module ) {
		$definitions = array(
			'vidshop/list-storefronts'  => array(
				'label'               => __( 'List Storefronts', 'vidshop-for-woocommerce' ),
				'description'         => __( 'List the saved VidShop storefronts. A storefront is a saved shortcode configuration — a chosen set of videos plus how they are presented — that the merchant renders on their own pages with [vidshop id="N"]. Each row carries its decoded config object and a shortcode field, which is the ready-to-paste string for that storefront. Trashed storefronts are hidden unless status is set to trash.', 'vidshop-for-woocommerce' ),
				'category'            => Abilities_Module::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'description'          => __( 'Optional paging, search and ordering. Omit entirely to list the first page.', 'vidshop-for-woocommerce' ),
					'properties'           => array(
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page of the collection to return. Defaults to 1.', 'vidshop-for-woocommerce' ),
							'minimum'     => 1,
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Number of storefronts per page. Defaults to 20.', 'vidshop-for-woocommerce' ),
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Limit results to storefronts whose name matches this string.', 'vidshop-for-woocommerce' ),
						),
						'status'   => array(
							'type'        => 'string',
							'description' => __( 'Limit results to one status. Omit to list everything except trashed storefronts.', 'vidshop-for-woocommerce' ),
							'enum'        => array( 'published', 'draft', 'trash' ),
						),
						'orderby'  => array(
							'type'        => 'string',
							'description' => __( 'Field to sort by. Defaults to created_at.', 'vidshop-for-woocommerce' ),
							'enum'        => array( 'id', 'name', 'created_at', 'updated_at' ),
						),
						'order'    => array(
							'type'        => 'string',
							'description' => __( 'Sort direction. Defaults to desc.', 'vidshop-for-woocommerce' ),
							'enum'        => array( 'asc', 'desc' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					$params = $module->apply_defaults(
						$input,
						array(
							'page'     => 1,
							'per_page' => 20,
						)
					);

					return $module->dispatch( 'GET', '/vsfw/v1/storefronts', $params );
				},
				'permission_callback' => $module->permission_callback( 'vidshop/list-storefronts' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),

			'vidshop/update-storefront' => array(
				'label'               => __( 'Update Storefront', 'vidshop-for-woocommerce' ),
				'description'         => __( 'Rename a storefront, change its status, or replace its display configuration. Only the top-level fields you supply are written — but `config` is replaced wholesale, never merged, so always send the complete config object you read from vidshop/list-storefronts with your changes applied. The change takes effect immediately on every page carrying that storefront\'s shortcode, and the shortcode itself never changes — so a storefront can be retuned without touching any page content. Read vidshop/list-storefronts first to get the id and the current config.', 'vidshop-for-woocommerce' ),
				'category'            => Abilities_Module::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'description'          => __( 'The storefront id, plus the fields to change.', 'vidshop-for-woocommerce' ),
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => __( 'ID of the storefront to update.', 'vidshop-for-woocommerce' ),
							'minimum'     => 1,
						),
						'name'   => array(
							'type'        => 'string',
							'description' => __( 'New admin-facing name for the storefront.', 'vidshop-for-woocommerce' ),
						),
						'status' => array(
							'type'        => 'string',
							'description' => __( 'New status. Setting trash hides the storefront and makes its shortcode render a missing-storefront notice.', 'vidshop-for-woocommerce' ),
							'enum'        => array( 'published', 'draft', 'trash' ),
						),
						'config' => array(
							'type'                 => 'object',
							'description'          => __( 'The full display configuration to store. A value outside the listed choices is rejected; a key not listed here is silently dropped; a missing key falls back to its default. Send the complete object, not a partial patch.', 'vidshop-for-woocommerce' ),
							// Enums mirror Storefronts_Controller::sanitize_config(). Keep both in step.
							'properties'           => array(
								'schema'                   => array(
									'type'        => 'integer',
									'description' => __( 'Version marker set by the server. Send it back as read.', 'vidshop-for-woocommerce' ),
								),
								'video_selection'          => array(
									'type'        => 'string',
									'description' => __( 'Show every published video, or only those in video_ids.', 'vidshop-for-woocommerce' ),
									'enum'        => array( 'all', 'specific' ),
								),
								'video_ids'                => array(
									'type'        => 'array',
									'description' => __( 'Video IDs to show when video_selection is "specific".', 'vidshop-for-woocommerce' ),
									'items'       => array( 'type' => 'integer' ),
								),
								'orderby'                  => array(
									'type'        => 'string',
									'description' => __( 'Sort key for the displayed videos. This vocabulary differs from vidshop/list-videos.', 'vidshop-for-woocommerce' ),
									'enum'        => array( 'date', 'title', 'id', 'views', 'random' ),
								),
								'order'                    => array(
									'type'        => 'string',
									'description' => __( 'Sort direction, lowercase.', 'vidshop-for-woocommerce' ),
									'enum'        => array( 'asc', 'desc' ),
								),
								'tags'                     => array(
									'type'        => 'array',
									'description' => __( 'Tag term IDs to filter by.', 'vidshop-for-woocommerce' ),
									'items'       => array( 'type' => 'integer' ),
								),
								'tags_operator'            => array(
									'type'        => 'string',
									'description' => __( 'Whether a video needs any of the tags or all of them.', 'vidshop-for-woocommerce' ),
									'enum'        => array( 'OR', 'AND' ),
								),
								'layout'                   => array(
									'type'        => 'string',
									'description' => __( 'How the videos are laid out on the page.', 'vidshop-for-woocommerce' ),
									'enum'        => array( 'grid', 'carousel', 'inline', 'stories' ),
								),
								'color_schema'             => array(
									'type'        => 'string',
									'description' => __( 'Accent colour as a hex value such as #1e40af. Names like "dark" are not accepted.', 'vidshop-for-woocommerce' ),
									'pattern'     => '^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$',
								),
								'columns'                  => array(
									'type'        => 'object',
									'description' => __( 'Videos per row, 1 to 6, per breakpoint.', 'vidshop-for-woocommerce' ),
									'properties'  => array(
										'desktop' => array(
											'type'    => 'integer',
											'minimum' => 1,
											'maximum' => 6,
										),
										'tablet'  => array(
											'type'    => 'integer',
											'minimum' => 1,
											'maximum' => 6,
										),
										'mobile'  => array(
											'type'    => 'integer',
											'minimum' => 1,
											'maximum' => 6,
										),
									),
								),
								'autoplay'                 => array( 'type' => 'boolean' ),
								'loop'                     => array( 'type' => 'boolean' ),
								'play_on_hover'            => array( 'type' => 'boolean' ),
								'show_arrows'              => array( 'type' => 'boolean' ),
								'show_dots'                => array( 'type' => 'boolean' ),
								'show_views'               => array( 'type' => 'boolean' ),
								'show_likes'               => array( 'type' => 'boolean' ),
								'auto_open_product_details' => array( 'type' => 'boolean' ),
								'add_to_cart_action'       => array(
									'type'        => 'string',
									'description' => __( 'Open the product in a modal, or link to the product page.', 'vidshop-for-woocommerce' ),
									'enum'        => array( 'modal', 'link' ),
								),
								'disable_add_to_cart_icon' => array( 'type' => 'boolean' ),
								'disable_add_to_cart_text' => array( 'type' => 'boolean' ),
								'post_add_to_cart_action'  => array(
									'type'        => 'string',
									'description' => __( 'What happens after a product is added to the cart. custom_url uses post_add_to_cart_url.', 'vidshop-for-woocommerce' ),
									'enum'        => array( 'open_cart', 'none', 'redirect_checkout', 'custom_url' ),
								),
								'post_add_to_cart_url'     => array(
									'type'        => 'string',
									'description' => __( 'Destination when post_add_to_cart_action is "custom_url".', 'vidshop-for-woocommerce' ),
								),
							),
							// Unknown keys still pass so a config read back from list-storefronts
							// validates unchanged; the controller drops them.
							'additionalProperties' => true,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					$input  = is_array( $input ) ? $input : array();
					$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
					$params = array();

					// Send only what the caller supplied, so a partial update never blanks a field.
					foreach ( array( 'name', 'status', 'config' ) as $key ) {
						if ( array_key_exists( $key, $input ) ) {
							$params[ $key ] = $input[ $key ];
						}
					}

					return $module->dispatch( 'PUT', '/vsfw/v1/storefronts/' . $id, $params );
				},
				'permission_callback' => $module->permission_callback( 'vidshop/update-storefront' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			),

			'vidshop/get-analytics'     => array(
				'label'               => __( 'Get Analytics', 'vidshop-for-woocommerce' ),
				'description'         => __( 'Read the VidShop performance report for a date range. Returns total and unique views, total and unique likes, total and average view time, add-to-cart and product-open totals, the top videos, the top added-to-cart products, a zero-filled per-day timeseries, library totals (videos, storefronts, products featured), and a previous block holding the same figures for the immediately preceding window of equal length so each stat can be compared. Site-wide reports also include a per_storefront rollup. Pass storefront_id to scope the whole report to one storefront; that variant adds a storefront identity block and omits per_storefront.', 'vidshop-for-woocommerce' ),
				'category'            => Abilities_Module::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'description'          => __( 'Reporting window and optional storefront scope. Omit entirely for the default window.', 'vidshop-for-woocommerce' ),
					'properties'           => array(
						'date_range'    => array(
							'type'        => 'string',
							'description' => __( 'Named reporting window. Defaults to this_week. Use custom together with start_date and end_date.', 'vidshop-for-woocommerce' ),
							'enum'        => array( 'this_week', 'last_week', 'this_month', 'last_month', 'all_time', 'custom' ),
						),
						'start_date'    => array(
							'type'        => 'string',
							'description' => __( 'Start of the window as YYYY-MM-DD, e.g. 2026-01-31. Required only when date_range is custom, and it must not be after end_date.', 'vidshop-for-woocommerce' ),
							'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
						),
						'end_date'      => array(
							'type'        => 'string',
							'description' => __( 'End of the window as YYYY-MM-DD, e.g. 2026-02-28. Required only when date_range is custom, and it must not be before start_date.', 'vidshop-for-woocommerce' ),
							'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
						),
						'storefront_id' => array(
							'type'        => 'integer',
							'description' => __( 'Limit the report to one storefront. Omit for the whole site.', 'vidshop-for-woocommerce' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					$params = $module->apply_defaults( $input, array() );

					// An unparseable or inverted custom range reaches the controller as 1970-01-01
					// or as an empty window, and comes back as a 200 report of zeros.
					$invalid = self::check_custom_range( $module, $params );
					if ( is_wp_error( $invalid ) ) {
						return $invalid;
					}

					$storefront_id = isset( $params['storefront_id'] ) ? (int) $params['storefront_id'] : 0;

					// The scoped route carries the id in the path and returns the storefront
					// identity block; the site-wide route returns the per_storefront rollup.
					if ( $storefront_id > 0 ) {
						unset( $params['storefront_id'] );

						return $module->dispatch( 'GET', '/vsfw/v1/analytics/storefronts/' . $storefront_id, $params );
					}

					return $module->dispatch( 'GET', '/vsfw/v1/analytics', $params );
				},
				'permission_callback' => $module->permission_callback( 'vidshop/get-analytics' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),
		);

		// Free sites cannot use Pro-only storefront settings or per-storefront
		// analytics, so the schemas stop offering them; the routes refuse them too.
		if ( ! Tier::is_pro() ) {
			$layout = &$definitions['vidshop/update-storefront']['input_schema']['properties']['config']['properties']['layout'];
			$config = &$definitions['vidshop/update-storefront']['input_schema']['properties']['config'];

			$layout['enum']         = array( 'grid' );
			$layout['description']  = __( 'Grid only on the free plan. Carousel, inline and stories need VidShop Pro.', 'vidshop-for-woocommerce' );
			$config['description'] .= ' ' . __( 'On the free plan, layout, columns, tags, tags_operator, show_arrows, show_dots and auto_open_product_details must keep their defaults; other values are refused with vsfw_pro_required.', 'vidshop-for-woocommerce' );

			unset( $layout, $config );
			unset( $definitions['vidshop/get-analytics']['input_schema']['properties']['storefront_id'] );

			$definitions['vidshop/get-analytics']['description'] .= ' ' . __( 'Per-storefront reports need VidShop Pro; on the free plan the report is always site-wide.', 'vidshop-for-woocommerce' );
		}

		return $definitions;
	}

	/**
	 * Refuse a custom date range that cannot produce a real report.
	 *
	 * Mirrors the controller's own `missing_dates`: an absent value is left to it, so the three
	 * refusals read as one family.
	 *
	 * @param Abilities_Module $module Abilities module, for the error helper.
	 * @param array            $params Analytics parameters.
	 * @return \WP_Error|null WP_Error when the range is unusable, null otherwise.
	 */
	private static function check_custom_range( $module, array $params ) {
		if ( ! isset( $params['date_range'] ) || 'custom' !== $params['date_range'] ) {
			return null;
		}

		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			if ( empty( $params[ $key ] ) ) {
				continue;
			}

			if ( ! self::is_ymd( $params[ $key ] ) ) {
				return $module->agent_error(
					'invalid_date_range',
					sprintf(
						/* translators: 1: parameter name, 2: value supplied for it. */
						__( '%1$s must be a real YYYY-MM-DD date for a custom date range, and "%2$s" is not one.', 'vidshop-for-woocommerce' ),
						$key,
						is_scalar( $params[ $key ] ) ? (string) $params[ $key ] : gettype( $params[ $key ] )
					),
					array(
						'status'     => 400,
						'agent_hint' => __( 'Send start_date and end_date as YYYY-MM-DD, e.g. 2026-01-31, or drop them and use a named date_range such as this_month.', 'vidshop-for-woocommerce' ),
					)
				);
			}
		}

		if ( ! empty( $params['start_date'] ) && ! empty( $params['end_date'] ) && $params['start_date'] > $params['end_date'] ) {
			return $module->agent_error(
				'invalid_date_range',
				sprintf(
					/* translators: 1: start date, 2: end date. */
					__( 'start_date (%1$s) is after end_date (%2$s), so the custom date range covers no days at all.', 'vidshop-for-woocommerce' ),
					$params['start_date'],
					$params['end_date']
				),
				array(
					'status'     => 400,
					'agent_hint' => __( 'Swap the two values: start_date must be the earlier date and end_date the later one.', 'vidshop-for-woocommerce' ),
				)
			);
		}

		return null;
	}

	/**
	 * Whether a value is a YYYY-MM-DD date that exists on the calendar.
	 *
	 * The checkdate() call is what rejects 2026-02-31, which the schema pattern accepts.
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	private static function is_ymd( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return false;
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}
}
