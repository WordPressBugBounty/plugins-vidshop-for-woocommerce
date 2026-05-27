<?php
/**
 * AI generation service.
 *
 * Orchestrates one "Generate with AI" request, server-side:
 *   1. Read the product + its featured image LOCALLY.
 *   2. PUSH the image bytes to R2 (keyless) — the cloud never fetches the merchant site, so this
 *      works on localhost / self-signed / firewalled stores (the root fix for "video doesn't match").
 *   3. Upsert the product into the cloud catalog with the R2 image URL.
 *   4. Start the generation (productId path) and record a local `vsfw_ai_generations` row.
 *   5. Ensure the reconcile cron is scheduled so the finished video imports even with the tab closed.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Services;

use VSFW\Models\Ai_Generation_Model;

/**
 * AI generation service.
 */
class Ai_Generation_Service {

	/** Option flag: this site has generated at least one AI video (drives promo suppression). */
	const FIRST_GENERATED_OPTION = 'vsfw_ai_first_generated';

	/**
	 * Cloud connection (token + state).
	 *
	 * @var Cloud_Connection
	 */
	private $connection;

	/**
	 * Cloud API client.
	 *
	 * @var Cloud_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Cloud_Connection $connection Cloud connection service.
	 * @param Cloud_Client     $client     Cloud API client.
	 */
	public function __construct( Cloud_Connection $connection, Cloud_Client $client ) {
		$this->connection = $connection;
		$this->client     = $client;
	}

	/**
	 * Whether this site has generated at least one AI video. Reactivation-proof: prefers the cached
	 * option, falling back to the DB so a restore/host-migrate still suppresses the promo. Only a
	 * full uninstall resets it.
	 *
	 * @return bool
	 */
	public static function has_generated() {
		if ( get_option( self::FIRST_GENERATED_OPTION ) ) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'vsfw_ai_generations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			update_option( self::FIRST_GENERATED_OPTION, 1, false );
			return true;
		}

		return false;
	}

	/**
	 * Start a generation for a WooCommerce product.
	 *
	 * @param array $input {
	 *     @type int    $product_id     Required. WooCommerce product id.
	 *     @type string $extra_prompt   Optional free-text steer.
	 *     @type int    $duration       8 or 15 (seconds).
	 *     @type string $audio          'silent' | 'music'.
	 *     @type string $template       Style template slug, or 'auto'.
	 *     @type bool   $allow_no_image Proceed even when the product has no featured image.
	 * }
	 * @return array|\WP_Error { generation_id, ai_call_id, status, estimated_seconds } or WP_Error.
	 */
	public function generate( array $input ) {
		$token = $this->connection->get_token();
		if ( ! $token ) {
			return new \WP_Error( 'not_connected', __( 'Connect your WPCreatix account first.', 'vidshop-for-woocommerce' ), array( 'status' => 401 ) );
		}

		// Free-credit enforcement lives entirely in the cloud (it owns the per-account + per-network
		// balance and is the only authority that survives a disconnect / reconnect / new account). The
		// cloud returns `insufficient_credits` when the free video is spent, which the caller surfaces as
		// the upgrade prompt — so there's no local "already used" flag to drift or wrongly block here.
		$product_id = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product ) {
			return new \WP_Error( 'invalid_product', __( 'That product could not be found.', 'vidshop-for-woocommerce' ), array( 'status' => 422 ) );
		}

		$duration = ( isset( $input['duration'] ) && 15 === (int) $input['duration'] ) ? 15 : 8;
		$audio    = ( isset( $input['audio'] ) && 'music' === $input['audio'] ) ? 'music' : 'silent';
		$template = ! empty( $input['template'] ) ? sanitize_text_field( $input['template'] ) : 'auto';

		// Featured image → push the bytes to R2 (never hand the cloud a merchant URL).
		$image_url = '';
		$thumb_id  = get_post_thumbnail_id( $product_id );
		$has_image = $thumb_id && get_attached_file( $thumb_id );
		if ( ! $has_image && empty( $input['allow_no_image'] ) ) {
			return new \WP_Error(
				'no_image',
				__( 'This product has no image. The AI would generate from text only and may not match your product. Add a featured image, or choose "Generate anyway".', 'vidshop-for-woocommerce' ),
				array( 'status' => 422 )
			);
		}
		if ( $has_image ) {
			$uploaded = $this->client->upload_image( $token, get_attached_file( $thumb_id ), get_post_mime_type( $thumb_id ) ?: 'image/jpeg' );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}
			$image_url = isset( $uploaded['url'] ) ? $uploaded['url'] : '';
		}

		// Upsert the product into the cloud catalog (with our R2 image) so the productId path is grounded.
		$synced = $this->client->sync_products( $token, array( $this->build_catalog_product( $product, $product_id, $image_url ) ) );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}

		// Kick off the render.
		$payload = array(
			'productId'       => (string) $product_id,
			'durationSeconds' => $duration,
			'audioMode'       => $audio,
			'template'        => $template,
		);
		if ( ! empty( $input['extra_prompt'] ) ) {
			$payload['extraPrompt'] = sanitize_textarea_field( $input['extra_prompt'] );
		}
		if ( $image_url ) {
			$payload['imageUrl'] = $image_url;
		}

		$video = $this->client->generate( $token, $payload );
		if ( is_wp_error( $video ) ) {
			return $video;
		}

		// Record the generation locally (idempotency anchor + powers the "In progress" list).
		$generation = Ai_Generation_Model::create(
			array(
				'ai_call_id'        => isset( $video['id'] ) ? (int) $video['id'] : 0,
				'product_id'        => $product_id,
				'title'             => $product->get_name(),
				'status'            => isset( $video['status'] ) ? $video['status'] : 'pending',
				'duration_s'        => isset( $video['duration_s'] ) ? (int) $video['duration_s'] : $duration,
				'estimated_seconds' => isset( $video['estimated_seconds'] ) ? (int) $video['estimated_seconds'] : null,
				'created_by'        => get_current_user_id(),
			)
		);

		update_option( self::FIRST_GENERATED_OPTION, 1, false );
		$this->connection->flush_usage_cache();
		Ai_Reconciler::ensure_scheduled();

		return array(
			'generation_id'     => $generation ? (int) $generation->get_attribute( 'id' ) : null,
			'ai_call_id'        => isset( $video['id'] ) ? (int) $video['id'] : null,
			'status'            => isset( $video['status'] ) ? $video['status'] : 'pending',
			'estimated_seconds' => isset( $video['estimated_seconds'] ) ? (int) $video['estimated_seconds'] : null,
		);
	}

	/**
	 * Build a cloud catalog-product payload from a WooCommerce product.
	 *
	 * @param \WC_Product $product    Product object.
	 * @param int         $product_id Product id.
	 * @param string      $image_url  R2 image URL (empty when the product has no image).
	 * @return array
	 */
	private function build_catalog_product( $product, $product_id, $image_url ) {
		$terms    = get_the_terms( $product_id, 'product_cat' );
		$category = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';

		$payload = array(
			'wcProductId' => (string) $product_id,
			'title'       => $product->get_name(),
			'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
			'category'    => $category,
			'price'       => (string) $product->get_price(),
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'permalink'   => get_permalink( $product_id ),
		);

		if ( $image_url ) {
			$payload['imageUrl'] = $image_url;
		}

		return $payload;
	}
}
