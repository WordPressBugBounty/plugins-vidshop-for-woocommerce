<?php
/**
 * Frontend Loader for VidShop for WooCommerce.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Frontend;

use VSFW\Interfaces\Settings;

/**
 * Frontend loader class with dependency injection.
 */
class Frontend_Loader {

	/**
	 * Settings service
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;

		// Init.
		add_action( 'init', array( $this, 'init' ) );

		// Register scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_scripts' ) );
	}

	/**
	 * Init.
	 */
	public function init() {
		// Register shortcode.
		add_shortcode( 'vsfw-videos', array( $this, 'render_video_shortcode' ) );
	}

	/**
	 * Render video shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Shortcode output.
	 */
	public function render_video_shortcode( $atts ) {
		$atts = shortcode_atts(
			apply_filters(
				'vsfw_video_shortcode_atts',
				array(
					'videos'                    => '',
					'type'                      => 'grid',
					'color-schema'              => '#1e40af',
					'id'                        => '',
					'play-on-hover'             => 'yes',
					'add-to-cart-action'        => 'modal',
					'auto-open-product-details' => 'no',
					'post-add-to-cart-action'   => 'none',
					'post-add-to-cart-url'      => '',
					'autoplay'                  => 'no',
					'loop'                      => 'no',
					'show-views'                => 'yes',
					'show-likes'                => 'yes',
					'orderby'                   => 'date',
					'order'                     => 'desc',
					'tags'                      => '',
					'tags-operator'             => 'OR',
				)
			),
			$atts,
			'vsfw-videos'
		);

		/**
		 * Allow consumers (notably Pro) to parse the final shortcode attribute set.
		 */
		$atts = apply_filters( 'vsfw_video_shortcode_parsed_atts', $atts );

		/**
		 * Whether tag-based filtering should be honoured for this shortcode.
		 *
		 * Default mirrors the `vsfw_is_pro` value so sites running new Free
		 * alongside an older Pro version (which already sets vsfw_is_pro to
		 * true) keep their existing `tags="..."` shortcodes working.
		 *
		 * Pro explicitly registers `add_filter( 'vsfw_tags_filtering_enabled', '__return_true' )`
		 * for clarity.
		 */
		$tags_enabled = (bool) apply_filters(
			'vsfw_tags_filtering_enabled',
			(bool) apply_filters( 'vsfw_is_pro', false )
		);
		if ( ! $tags_enabled ) {
			$atts['tags']          = '';
			$atts['tags-operator'] = 'OR';
		}

		$shortcode_id       = uniqid( 'vsfw-videos-' );
		$ids                = 'all' === $atts['videos'] ? null : explode( ',', $atts['videos'] );
		$videos             = $this->get_videos( $ids, $atts['orderby'], $atts['order'], $atts['tags'], $atts['tags-operator'] );
		$type               = $atts['type'];
		$color_schema       = $atts['color-schema'];
		$play_on_hover      = $atts['play-on-hover'];
		$add_to_cart_action = $atts['add-to-cart-action'];
		$auto_open_details  = $atts['auto-open-product-details'];
		$post_add_action    = $atts['post-add-to-cart-action'];
		$post_add_url       = ! empty( $atts['post-add-to-cart-url'] ) ? esc_url_raw( trim( $atts['post-add-to-cart-url'] ) ) : '';
		$autoplay           = $atts['autoplay'];
		$loop               = $atts['loop'];
		$show_views         = $atts['show-views'];
		$show_likes         = $atts['show-likes'];

		// Check if the script is already enqueued.
		wp_enqueue_script( 'vsfw-frontend' );
		wp_enqueue_style( 'vsfw-frontend' );

		// Allow developers to add custom scripts/styles if needed.
		do_action( 'vsfw_enqueue_additional_frontend_assets' );

		// Embed shortcode data directly in DOM for theme compatibility.
		$shortcode_data = wp_json_encode(
			apply_filters(
				'vsfw_shortcode_data',
				array(
					'videos'                    => $videos,
					'layout'                    => $type,
					'color_schema'              => $color_schema,
					'play_on_hover'             => $play_on_hover,
					'add_to_cart_action'        => $add_to_cart_action,
					'auto_open_product_details' => $auto_open_details,
					'post_add_to_cart_action'   => $post_add_action,
					'post_add_to_cart_url'      => $post_add_url,
					'autoplay'                  => $autoplay,
					'loop'                      => $loop,
					'show_views'                => $show_views,
					'show_likes'                => $show_likes,
				),
				$atts
			)
		);

		return sprintf(
			'<div class="vsfw-videos-wrapper" id="%s">
				<script type="application/json" class="vsfw-shortcode-data">%s</script>
			</div>',
			esc_attr( $shortcode_id ),
			$shortcode_data
		);
	}

	/**
	 * Get videos.
	 *
	 * @param array|null $ids    Optional explicit video ids.
	 * @param string     $orderby Order by field.
	 * @param string     $order   Order direction.
	 */
	public function get_videos( $ids, $orderby = 'date', $order = 'desc', $tags = '', $tags_operator = 'OR' ) {
		$path   = '/vsfw/v1/videos';
		$params = array(
			'per_page' => 100,
			'frontend' => true,
			'status'   => 'published',
			'orderby'  => $orderby,
			'order'    => $order,
		);

		if ( $ids ) {
			$params['ids'] = implode( ',', $ids );
		}

		if ( ! empty( $tags ) ) {
			$params['tags']          = is_array( $tags ) ? implode( ',', $tags ) : $tags;
			$params['tags_operator'] = strtoupper( $tags_operator ) === 'AND' ? 'AND' : 'OR';
		}

		$request = new \WP_REST_Request( 'GET', $path );
		$request->set_query_params( $params );
		$response      = rest_do_request( $request );
		$response_data = ( $response instanceof \WP_REST_Response ) ? $response->get_data() : array();

		return $response_data['data'] ?? array();
	}

	/**
	 * Register admin scripts.
	 */
	public function register_frontend_scripts() {
		$assets_dir   = VSFW_PLUGIN_DIR . 'dist/frontend';
		$assets_file  = $assets_dir . '/index.asset.php';
		$assets       = file_exists( $assets_file ) ? require_once $assets_file : array();
		$dependencies = isset( $assets['dependencies'] ) ? $assets['dependencies'] : array();
		$version      = isset( $assets['version'] ) ? $assets['version'] : VSFW_VERSION;

		// Register scripts.
		wp_register_script(
			'vsfw-frontend',
			VSFW_PLUGIN_URL . 'dist/frontend/index.js',
			$dependencies,
			$version,
		);

		// Localize script with global data.
		$frontend_data = apply_filters(
			'svfw_frontend_global_data',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'vsfw-frontend' ),
				'checkout_url'    => wc_get_checkout_url(),
				'settings'        => $this->settings->get_all_settings(),
				'currency_format' => $this->settings->get_currency_format(),
				'is_logged_in'    => is_user_logged_in(),
				'is_pro'          => apply_filters( 'vsfw_is_pro', false ),
				'is_rtl'          => is_rtl(),
			)
		);

		/**
		 * Allow Pro and other extensions to inject arbitrary values into the
		 * frontend localized data blob.
		 */
		$frontend_data = apply_filters( 'vsfw_frontend_localized_data', $frontend_data );

		wp_localize_script( 'vsfw-frontend', 'svfwFrontend', $frontend_data );

		// Translate scripts.
		wp_set_script_translations( 'vsfw-frontend', 'vidshop-for-woocommerce', VSFW_PLUGIN_DIR . 'languages' );

		// Register styles.
		wp_register_style(
			'vsfw-frontend',
			VSFW_PLUGIN_URL . 'dist/frontend/index.css',
			array(),
			$version,
		);

		// Rtl.
		wp_style_add_data( 'vsfw-frontend', 'rtl', 'replace' );
	}
}
