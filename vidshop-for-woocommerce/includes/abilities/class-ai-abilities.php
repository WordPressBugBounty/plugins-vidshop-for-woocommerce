<?php
/**
 * AI ability definitions — cloud status, the generation queue, and plugin settings.
 *
 * All three abilities here are SERVICE-backed rather than dispatch-backed, and all three are reads.
 * The connect / disconnect / verify / dismiss / reconcile / settings-write routes are deliberately
 * NOT exposed: connecting and disconnecting are human decisions that move a site slot on the
 * merchant's SaaS account, and the vsk_ token must never reach an agent.
 *
 * Services are resolved INSIDE each callback. Resolving them in `definitions()` would build the
 * cloud client on every page load, for every request that never runs an ability.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Abilities;

/**
 * AI ability provider.
 */
class Ai_Abilities {

	/**
	 * Ability definitions, keyed by ability name.
	 *
	 * @param Abilities_Module $module Abilities module, for services, meta and permissions.
	 * @return array Map of ability name => wp_register_ability() args.
	 */
	public static function definitions( $module ) {
		return array(
			'vidshop/get-ai-status'    => array(
				'label'               => __( 'Get VidShop AI status', 'vidshop-for-woocommerce' ),
				'description'         => __(
					'Everything needed before generating a video, in one call: whether this site is connected to the WPCreatix cloud, the plan and account email, the remaining credit balance, and every allowed generation duration with its exact credit cost and audio modes. It also answers the two derived questions directly, so nothing has to be inferred: `can_generate` says whether a generation can start right now, and `blocked_reason` says why not — "not_connected" (an administrator must connect the site in WP Admin first), "options_unavailable" (the cloud could not be reached) or "no_credits" (the balance is spent). The durations and credit costs returned here are AUTHORITATIVE and must never be hardcoded: they come from the cloud and can change without a plugin update. `vidshop/generate-video` requires an `acknowledge_credit_cost` value equal to the `credit_cost` of the chosen duration as read from this ability. This ability never fails because of the connection — a disconnected site, or one whose option lookup failed, still returns a complete payload with `can_generate` false. This ability contacts the WPCreatix cloud over the network (usage is cached for 60 seconds, generation options for 1 hour) and, if the stored Pro token has expired, silently re-exchanges the licence and rewrites the stored connection — so it is read-only from this site\'s point of view but it is not a purely local read.',
					'vidshop-for-woocommerce'
				),
				'category'            => Abilities_Module::CATEGORY,
				// This ability takes nothing, but an empty object is the natural call shape for a
				// caller that always sends an input map — and with no schema at all core rejects it
				// with ability_missing_input_schema.
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'required'             => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'connected'         => array(
							'type'        => 'boolean',
							'description' => __( 'Whether this site holds a valid connection to the WPCreatix cloud.', 'vidshop-for-woocommerce' ),
						),
						'plan'              => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Connected plan, "free" or "pro". Null when not connected.', 'vidshop-for-woocommerce' ),
						),
						'account_email'     => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Email of the connected cloud account. Null when not connected, or when the site was connected with a Pro license.', 'vidshop-for-woocommerce' ),
						),
						'credits_remaining' => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Credits left on the account (subscription plus packs). Null when the balance is unknown, which is not the same as zero.', 'vidshop-for-woocommerce' ),
						),
						'can_generate'      => array(
							'type'        => 'boolean',
							'description' => __( 'True only when the site is connected, the generation options resolved, and the balance is either unknown or above zero.', 'vidshop-for-woocommerce' ),
						),
						'blocked_reason'    => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Why generation is blocked: "not_connected", "options_unavailable" or "no_credits". Null when nothing is blocking it.', 'vidshop-for-woocommerce' ),
						),
						'durations'         => array(
							'type'        => 'array',
							'description' => __( 'Selectable generation durations with their authoritative credit cost. Empty when the options could not be fetched.', 'vidshop-for-woocommerce' ),
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'seconds'     => array(
										'type'        => 'integer',
										'description' => __( 'Video length in seconds, passed as `duration` to vidshop/generate-video.', 'vidshop-for-woocommerce' ),
									),
									'credit_cost' => array(
										'type'        => array( 'integer', 'null' ),
										'description' => __( 'Credits this duration costs. Pass this exact number as `acknowledge_credit_cost` to vidshop/generate-video. Null when the cloud did not price it.', 'vidshop-for-woocommerce' ),
									),
									'audio_modes' => array(
										'type'        => 'array',
										'description' => __( 'Audio modes accepted for this duration, e.g. "silent" or "music".', 'vidshop-for-woocommerce' ),
										'items'       => array( 'type' => 'string' ),
									),
								),
								'additionalProperties' => false,
							),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input = null ) use ( $module ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- the input schema is an empty object, so there is never anything to read.
					$conn    = $module->service( 'cloud_connection' );
					$status  = $conn->status_payload();
					$options = $conn->get_generation_options();

					$connected = ! empty( $status['connected'] );
					$resolved  = ! is_wp_error( $options ) && is_array( $options );
					$credits   = self::read_credits_remaining( $status );

					// A failed option fetch is reported, never returned as an error: an agent still
					// needs the connection facts to tell "connect the site" from "try again later".
					$blocked_reason = null;
					if ( ! $connected ) {
						$blocked_reason = 'not_connected';
					} elseif ( ! $resolved ) {
						$blocked_reason = 'options_unavailable';
					} elseif ( null !== $credits && $credits <= 0 ) {
						$blocked_reason = 'no_credits';
					}

					return array(
						'connected'         => $connected,
						'plan'              => self::read_string( $status, 'plan' ),
						'account_email'     => self::read_string( $status, 'account_email' ),
						'credits_remaining' => $credits,
						'can_generate'      => ( null === $blocked_reason ),
						'blocked_reason'    => $blocked_reason,
						'durations'         => $resolved ? self::map_durations( $options ) : array(),
					);
				},
				'permission_callback' => $module->permission_callback( 'vidshop/get-ai-status' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),

			'vidshop/list-generations' => array(
				'label'               => __( 'List VidShop AI generations', 'vidshop-for-woocommerce' ),
				'description'         => __(
					'The AI generation queue for this site, as { in_progress, recent, in_progress_count }. Each entry carries id, ai_call_id, product_id, title, status, video_id, duration_s, estimated_seconds, error, failure_code and created_at. The status ladder is pending -> processing -> importing -> imported | failed; "failed" spends no credits. An imported generation exists on this site as a DRAFT video — find it with vidshop/list-videos using origin "wpcreatix_ai", review it, then publish it with vidshop/update-video. This is a pure read: it does not poll the cloud and imports nothing. A background job already does that every minute, so a render that just finished can take up to a minute to appear here as imported.',
					'vidshop-for-woocommerce'
				),
				'category'            => Abilities_Module::CATEGORY,
				// The whole queue is returned, so there is nothing to filter on and no property to
				// declare — but the empty object must still be declared, or core rejects `{}` with
				// ability_missing_input_schema.
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'required'             => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				// No output_schema on purpose. The snapshot is service-owned and wide: rows come
				// straight out of the generations table with mixed and nullable columns, and any
				// schema written over it would fail output validation the first time the service
				// adds a field. Output validation is enforced with no escape hatch.
				'execute_callback'    => static function ( $input = null ) use ( $module ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- the input schema is an empty object, so there is never anything to read.
					// Deliberately NOT reconcile() first, even though the REST route does:
					// reconcile() writes rows, which would make `readonly: true` a lie. The
					// one-minute cron owns the importing.
					return $module->service( 'ai_reconciler' )->get_snapshot();
				},
				'permission_callback' => $module->permission_callback( 'vidshop/list-generations' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),

			'vidshop/get-settings'     => array(
				'label'               => __( 'Get VidShop settings', 'vidshop-for-woocommerce' ),
				'description'         => __(
					'Read-only view of the VidShop plugin settings: allow_anonymous_likes (whether logged-out shoppers may like a video) plus the currency-format overrides currency_position, price_thousand_sep, price_decimal_sep and price_num_decimals. A key that is absent, empty or null has never been overridden and is inherited from WooCommerce. There is deliberately no write counterpart: currency formatting is a display-wide setting with no agent use case, and a change would move every price on the storefront.',
					'vidshop-for-woocommerce'
				),
				'category'            => Abilities_Module::CATEGORY,
				// Takes nothing, but `{}` has to be accepted: with no schema at all core answers
				// ability_missing_input_schema instead of reading the settings.
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'required'             => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				// No output_schema on purpose: the option payload is service-owned, its currency
				// keys are nullable "inherit from WooCommerce" markers, and add-ons may store
				// their own keys in the same option.
				'execute_callback'    => static function ( $input = null ) use ( $module ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- the input schema is an empty object, so there is never anything to read.
					$settings = $module->service( 'settings' )->get_all_settings();

					// get_all_settings() returns the raw option, which is false until something is
					// saved. Parsed over the same defaults Settings_Controller::get_items() uses, so
					// the ability and /vsfw/v1/settings can never disagree on an untouched store.
					return wp_parse_args(
						$settings,
						array(
							'allow_anonymous_likes' => false,
							'currency_position'     => '',
							'price_thousand_sep'    => null,
							'price_decimal_sep'     => null,
							'price_num_decimals'    => null,
						)
					);
				},
				'permission_callback' => $module->permission_callback( 'vidshop/get-settings' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),
		);
	}

	/**
	 * Read the remaining credit balance out of the connection status payload.
	 *
	 * Null and zero mean different things to an agent: null is "the balance could not be read",
	 * which must never be reported as "out of credits".
	 *
	 * @param array $status Payload from Cloud_Connection::status_payload().
	 * @return int|null Credits remaining, or null when unknown.
	 */
	private static function read_credits_remaining( $status ) {
		if ( ! is_array( $status ) || ! isset( $status['usage']['balances'] ) || ! is_array( $status['usage']['balances'] ) ) {
			return null;
		}

		$balances = $status['usage']['balances'];
		$total    = 0;
		$known    = false;

		foreach ( array( 'subscription_credits', 'pack_credits' ) as $key ) {
			if ( isset( $balances[ $key ] ) && is_numeric( $balances[ $key ] ) ) {
				$total += (int) $balances[ $key ];
				$known  = true;
			}
		}

		return $known ? $total : null;
	}

	/**
	 * Read one string field out of a payload, normalizing anything else to null.
	 *
	 * @param array  $payload Source payload.
	 * @param string $key     Field to read.
	 * @return string|null
	 */
	private static function read_string( $payload, $key ) {
		if ( ! is_array( $payload ) || ! isset( $payload[ $key ] ) || ! is_string( $payload[ $key ] ) ) {
			return null;
		}

		return $payload[ $key ];
	}

	/**
	 * Build the durations list from the cloud options payload.
	 *
	 * Every value is rebuilt and cast here rather than passing the cloud array through, so the
	 * declared output schema stays truthful whatever the cloud adds to its own response.
	 *
	 * @param array $options Payload from Cloud_Connection::get_generation_options().
	 * @return array List of { seconds, credit_cost, audio_modes }.
	 */
	private static function map_durations( $options ) {
		$rows = isset( $options['durations'] ) && is_array( $options['durations'] ) ? $options['durations'] : array();

		// Audio modes are declared once for the whole payload; a per-duration list wins when present.
		$shared_modes = self::read_audio_modes( $options );
		$durations    = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['seconds'] ) || ! is_numeric( $row['seconds'] ) ) {
				continue;
			}

			$modes = self::read_audio_modes( $row );

			$durations[] = array(
				'seconds'     => (int) $row['seconds'],
				'credit_cost' => self::read_credit_cost( $row ),
				'audio_modes' => empty( $modes ) ? $shared_modes : $modes,
			);
		}

		return $durations;
	}

	/**
	 * Read a duration's credit cost, accepting either key the cloud may use.
	 *
	 * @param array $row One duration entry from the cloud options payload.
	 * @return int|null Credit cost, or null when the cloud did not price the duration.
	 */
	private static function read_credit_cost( $row ) {
		foreach ( array( 'credit_cost', 'credits' ) as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				return (int) $row[ $key ];
			}
		}

		return null;
	}

	/**
	 * Read a list of audio modes, accepting either key the cloud may use.
	 *
	 * @param array $source Options payload, or one duration entry within it.
	 * @return array List of mode strings, empty when none are declared.
	 */
	private static function read_audio_modes( $source ) {
		$modes = array();

		foreach ( array( 'audio_modes', 'audioModes' ) as $key ) {
			if ( ! isset( $source[ $key ] ) || ! is_array( $source[ $key ] ) ) {
				continue;
			}

			foreach ( $source[ $key ] as $mode ) {
				if ( is_scalar( $mode ) ) {
					$modes[] = (string) $mode;
				}
			}

			break;
		}

		return $modes;
	}
}
