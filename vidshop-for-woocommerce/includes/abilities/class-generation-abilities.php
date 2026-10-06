<?php
/**
 * Generation abilities — the two VidShop operations that destroy something or spend real money.
 *
 * `vidshop/trash-video` is reversible (its pair is `vidshop/restore-video`); permanent deletion is
 * deliberately not exposed at all. `vidshop/generate-video` is not destructive but burns cloud
 * credits, so it gets the same treatment: off MCP by default, and gated by a confirmation the model
 * cannot set reflexively.
 *
 * The `confirm: true` gate lives in the input schema rather than in guard code: a schema `enum` is
 * checked during input validation, which core runs BEFORE the permission check and before the
 * callback, so that gate cannot be forgotten or skipped. `acknowledge_credit_cost` is compared
 * inside the callback against the live cloud price, after the permission check and before any
 * credits are spent.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Abilities;

/**
 * Destructive and metered ability definitions.
 */
class Generation_Abilities {

	/**
	 * Ability definitions for the module to register.
	 *
	 * @param Abilities_Module $module Abilities module, providing permission, meta, dispatch,
	 *                                 defaults, service and error helpers.
	 * @return array Map of ability name => wp_register_ability() args.
	 */
	public static function definitions( $module ) {
		return array(
			'vidshop/trash-video'    => self::trash_video( $module ),
			'vidshop/generate-video' => self::generate_video( $module ),
		);
	}

	/**
	 * `vidshop/trash-video` — move one video to the trash.
	 *
	 * `idempotent` is deliberately false. `destructive && idempotent` routes core's /run endpoint to
	 * DELETE, whose `input` query parameter is never JSON-decoded, so an object-shaped input would
	 * fail validation over the wire. Keeping idempotent false holds the route on POST + JSON body.
	 *
	 * @param Abilities_Module $module Abilities module.
	 * @return array Ability args.
	 */
	private static function trash_video( $module ) {
		$name = 'vidshop/trash-video';

		return array(
			'label'               => __( 'Trash video', 'vidshop-for-woocommerce' ),
			'description'         => __( 'Moves a video to the trash. Reversible — use vidshop/restore-video to bring it back. Permanent deletion is not available through abilities.', 'vidshop-for-woocommerce' ),
			'category'            => Abilities_Module::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the video to trash. Read it from vidshop/list-videos.', 'vidshop-for-woocommerce' ),
					),
					// The gate. rest_validate_enum() compares with === after schema sanitisation, so
					// `false` — and an absent value — is rejected as `ability_invalid_input` BEFORE the
					// permission check and before any execution. Zero lines of guard code, and it
					// cannot be forgotten.
					'confirm' => array(
						'type'        => 'boolean',
						'enum'        => array( true ),
						'description' => __( 'Must be true. Confirms a human accepted that this video will be removed from every storefront until it is restored.', 'vidshop-for-woocommerce' ),
					),
				),
				'required'             => array( 'id', 'confirm' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'trashed' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true — the video is now in the trash.', 'vidshop-for-woocommerce' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'ID of the trashed video.', 'vidshop-for-woocommerce' ),
					),
				),
				'required'             => array( 'trashed', 'id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input = null ) use ( $module ) {
				$id = isset( $input['id'] ) ? (int) $input['id'] : 0;

				// `force` is hard-coded false. Permanent delete detaches products, deletes the
				// per-product stats rows and purges analytics — it is not exposed anywhere.
				$result = $module->dispatch( 'DELETE', '/vsfw/v1/videos/' . $id, array( 'force' => false ) );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'trashed' => true,
					'id'      => $id,
				);
			},
			'permission_callback' => $module->permission_callback( $name ),
			'meta'                => $module->meta(
				array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				)
			),
		);
	}

	/**
	 * `vidshop/generate-video` — start an AI video generation for a WooCommerce product.
	 *
	 * Metered: `meta( ..., true )` keeps it off MCP by default even though it is not destructive,
	 * because every run spends real cloud credits.
	 *
	 * @param Abilities_Module $module Abilities module.
	 * @return array Ability args.
	 */
	private static function generate_video( $module ) {
		$name = 'vidshop/generate-video';

		return array(
			'label'               => __( 'Generate video with AI', 'vidshop-for-woocommerce' ),
			'description'         => __( 'Starts an AI video generation from a WooCommerce product. This spends cloud credits, which are real money. Read the allowed durations and their credit costs from vidshop/get-ai-status and never hardcode them — they change. The video is not returned here: the generation runs in the cloud and later arrives as a DRAFT video row, which vidshop/list-generations reports on.', 'vidshop-for-woocommerce' ),
			'category'            => Abilities_Module::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'              => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'WooCommerce product ID. Its featured image is used as the seed frame the video is animated from.', 'vidshop-for-woocommerce' ),
					),
					'extra_prompt'            => array(
						'type'        => 'string',
						'maxLength'   => 500,
						'description' => __( 'Optional free-text steer for the render, e.g. a mood or a detail to emphasise.', 'vidshop-for-woocommerce' ),
					),
					'duration'                => array(
						'type'        => 'integer',
						'enum'        => array( 8, 15 ),
						'description' => __( 'Clip length in seconds. Costs differ per duration — read them from vidshop/get-ai-status. Defaults to 8.', 'vidshop-for-woocommerce' ),
					),
					'audio'                   => array(
						'type'        => 'string',
						'enum'        => array( 'silent', 'music' ),
						'description' => __( 'Audio mode. Shoppable feeds autoplay muted, so this defaults to silent.', 'vidshop-for-woocommerce' ),
					),
					'template'                => array(
						'type'        => 'string',
						'description' => __( 'Style template slug, e.g. "auto", "studio-macro", "lookbook". Defaults to "auto".', 'vidshop-for-woocommerce' ),
					),
					'allow_no_image'          => array(
						'type'        => 'boolean',
						'description' => __( 'Generate even when the product has no featured image. The result will not resemble the product — only set this when a human has accepted that.', 'vidshop-for-woocommerce' ),
					),
					// Same gate as trash-video: enum-checked during input validation, so a missing or
					// false confirmation never reaches the permission check or the callback.
					'confirm'                 => array(
						'type'        => 'boolean',
						'enum'        => array( true ),
						'description' => __( 'Must be true. Confirms a human accepted that this generation spends credits.', 'vidshop-for-woocommerce' ),
					),
					'acknowledge_credit_cost' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'The exact credit_cost for the chosen duration as reported by vidshop/get-ai-status. A boolean confirmation is set reflexively by a model; a number can only be obtained by having actually read the price.', 'vidshop-for-woocommerce' ),
					),
				),
				'required'             => array( 'product_id', 'confirm', 'acknowledge_credit_cost' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'generation_id'     => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'Local generation row ID, or null when the row could not be written.', 'vidshop-for-woocommerce' ),
					),
					'ai_call_id'        => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'Cloud call ID for this render, or null when the cloud did not return one.', 'vidshop-for-woocommerce' ),
					),
					'status'            => array(
						'type'        => 'string',
						'description' => __( 'Generation status at hand-off, usually "pending".', 'vidshop-for-woocommerce' ),
					),
					'estimated_seconds' => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'Rough wall-clock estimate for the render, or null when unknown.', 'vidshop-for-woocommerce' ),
					),
					'next_actions'      => array(
						'type'        => 'array',
						'description' => __( 'What to do next with this result.', 'vidshop-for-woocommerce' ),
						'items'       => array(
							'type'                 => 'object',
							'properties'           => array(
								'ability' => array( 'type' => 'string' ),
								'reason'  => array( 'type' => 'string' ),
							),
							'required'             => array( 'ability', 'reason' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'generation_id', 'ai_call_id', 'status', 'estimated_seconds', 'next_actions' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input = null ) use ( $module ) {
				$connection = $module->service( 'cloud_connection' );
				$options    = $connection->get_generation_options();

				// Core applies only the top-level schema `default`, never per-property ones.
				$input = $module->apply_defaults(
					$input,
					array(
						'duration'       => 8,
						'audio'          => 'silent',
						'allow_no_image' => false,
					)
				);

				$duration    = (int) $input['duration'];
				$credit_cost = is_wp_error( $options ) ? null : self::credit_cost_for_duration( $options, $duration );

				if ( null === $credit_cost ) {
					return $module->agent_error(
						'options_unavailable',
						__( 'The current credit prices could not be read from the cloud, so this generation was not started.', 'vidshop-for-woocommerce' ),
						array(
							'status'     => 503,
							'agent_hint' => __( 'Prices are unknown right now, so the acknowledged cost cannot be checked. Read vidshop/get-ai-status and retry; if it also fails, the site is not connected or the cloud is unreachable.', 'vidshop-for-woocommerce' ),
							'recovery'   => array(
								'ability' => 'vidshop/get-ai-status',
								'input'   => null,
							),
						)
					);
				}

				if ( (int) $input['acknowledge_credit_cost'] !== $credit_cost ) {
					return $module->agent_error(
						'credit_cost_mismatch',
						sprintf(
							/* translators: 1: acknowledged credit cost, 2: actual credit cost, 3: duration in seconds. */
							__( 'The acknowledged credit cost (%1$d) does not match the current price (%2$d) for a %3$d second video.', 'vidshop-for-woocommerce' ),
							(int) $input['acknowledge_credit_cost'],
							$credit_cost,
							$duration
						),
						array(
							'status'     => 409,
							'agent_hint' => __( 'The acknowledged credit cost does not match the current price. Read vidshop/get-ai-status again and retry with the value it reports.', 'vidshop-for-woocommerce' ),
							'recovery'   => array(
								'ability' => 'vidshop/get-ai-status',
								'input'   => null,
							),
						)
					);
				}

				$payload = array(
					'product_id'     => (int) $input['product_id'],
					'extra_prompt'   => isset( $input['extra_prompt'] ) ? sanitize_textarea_field( $input['extra_prompt'] ) : '',
					'duration'       => $duration,
					'audio'          => (string) $input['audio'],
					'template'       => isset( $input['template'] ) ? sanitize_text_field( $input['template'] ) : '',
					'allow_no_image' => (bool) $input['allow_no_image'],
				);

				$result = $module->service( 'ai_generation_service' )->generate( $payload );

				if ( is_wp_error( $result ) ) {
					return self::recoverable_error( $module, $result );
				}

				return array(
					'generation_id'     => self::nullable_int( $result, 'generation_id' ),
					'ai_call_id'        => self::nullable_int( $result, 'ai_call_id' ),
					'status'            => isset( $result['status'] ) && is_scalar( $result['status'] ) ? (string) $result['status'] : 'pending',
					'estimated_seconds' => self::nullable_int( $result, 'estimated_seconds' ),
					'next_actions'      => array(
						array(
							'ability' => 'vidshop/list-generations',
							'reason'  => __( 'Poll until this generation reaches imported or failed.', 'vidshop-for-woocommerce' ),
						),
					),
				);
			},
			'permission_callback' => $module->permission_callback( $name ),
			'meta'                => $module->meta(
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				true
			),
		);
	}

	/**
	 * Find the credit cost the cloud currently charges for a duration.
	 *
	 * The cloud returns `durations` as a list of `{ seconds, credits }` entries. The alternate key
	 * names are read too so a rename on either side degrades to `options_unavailable` rather than to
	 * a silently skipped price check.
	 *
	 * @param mixed $options  Generation options payload from Cloud_Connection.
	 * @param int   $duration Requested duration in seconds.
	 * @return int|null Credit cost, or null when it cannot be determined.
	 */
	private static function credit_cost_for_duration( $options, $duration ) {
		if ( ! is_array( $options ) || empty( $options['durations'] ) || ! is_array( $options['durations'] ) ) {
			return null;
		}

		foreach ( $options['durations'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$seconds = isset( $entry['seconds'] ) ? (int) $entry['seconds'] : ( isset( $entry['duration'] ) ? (int) $entry['duration'] : 0 );
			if ( $seconds !== $duration ) {
				continue;
			}

			if ( isset( $entry['credits'] ) ) {
				return (int) $entry['credits'];
			}

			if ( isset( $entry['credit_cost'] ) ) {
				return (int) $entry['credit_cost'];
			}
		}

		return null;
	}

	/**
	 * Turn a generation service failure into an error an agent can act on.
	 *
	 * Execute-side only: permission errors stay bare and generic.
	 *
	 * @param Abilities_Module $module Abilities module.
	 * @param \WP_Error        $error  Error returned by the generation service.
	 * @return \WP_Error
	 */
	private static function recoverable_error( $module, $error ) {
		$code = $error->get_error_code();

		if ( 'not_connected' === $code ) {
			return $module->agent_error(
				$code,
				$error->get_error_message(),
				array(
					// 409, not 401: the site is not connected to the cloud. 401 would tell the HTTP
					// caller its own credentials failed and invite a pointless re-auth.
					'status'     => 409,
					'agent_hint' => __( 'A human must connect this site to VidShop AI in the plugin Settings screen. You cannot do this yourself.', 'vidshop-for-woocommerce' ),
					'recovery'   => array(
						'ability' => 'vidshop/get-ai-status',
						'input'   => null,
					),
				)
			);
		}

		if ( 'no_image' === $code ) {
			return $module->agent_error(
				$code,
				$error->get_error_message(),
				array(
					'status'     => 422,
					'agent_hint' => __( 'This product has no featured image, so there is no seed frame to animate. Ask a human to add one. Retrying with allow_no_image set to true generates from text only, and the result will NOT match the product.', 'vidshop-for-woocommerce' ),
					'warning'    => __( 'Generating without a featured image produces a video that does not match the product.', 'vidshop-for-woocommerce' ),
					'recovery'   => array(
						'ability' => 'vidshop/generate-video',
						'input'   => array( 'allow_no_image' => true ),
					),
				)
			);
		}

		return $error;
	}

	/**
	 * Cast one result field to an integer, preserving null.
	 *
	 * The generation service returns null for these fields on some paths, and the output schema
	 * types them as integer-or-null — so a blanket (int) cast would turn null into 0.
	 *
	 * @param array  $result Generation service result.
	 * @param string $key    Field name.
	 * @return int|null
	 */
	private static function nullable_int( array $result, $key ) {
		if ( ! isset( $result[ $key ] ) || null === $result[ $key ] ) {
			return null;
		}

		return (int) $result[ $key ];
	}
}
