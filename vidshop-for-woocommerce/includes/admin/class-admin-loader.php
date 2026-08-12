<?php
/**
 * Admin Loader for VidShop for WooCommerce.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Admin;

use VSFW\Interfaces\WooCommerce;
use VSFW\Interfaces\Settings;
use VSFW\Models\Video_Model;
use VSFW\Admin\Activation_Handler;
use VSFW\Services\Cloud_Connection;
use VSFW\REST_API\V1\Promo_Controller;

/**
 * Admin loader class with dependency injection.
 */
class Admin_Loader {

	/**
	 * Logger service.
	 *
	 * @var WooCommerce
	 */
	private $woocommerce;

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	private $menu_slug = 'vsfw';

	/**
	 * Constructor with dependency injection.
	 *
	 * @param WooCommerce $woocommerce WooCommerce service.
	 * @param Settings    $settings    Settings service.
	 */
	public function __construct( WooCommerce $woocommerce, Settings $settings ) {
		$this->woocommerce = $woocommerce;
		$this->settings    = $settings;

		// Initialize activation handler.
		new Activation_Handler();

		$this->init();
	}

	/**
	 * Initialize admin functionality.
	 */
	private function init() {
		// Add admin menu.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Remove notices.
		add_action( 'admin_notices', array( $this, 'remove_notices' ), 999 );
		add_action( 'admin_notices', array( $this, 'inject_before_notices' ), -9999 );
		add_action( 'admin_notices', array( $this, 'inject_after_notices' ), PHP_INT_MAX );

		// Change Admin footer text.
		add_filter( 'admin_footer_text', array( $this, 'change_admin_footer_text' ) );

		// Add class to body.
		add_filter(
			'admin_body_class',
			function ( $classes ) {
				if ( self::is_admin_page() ) {
					$classes .= ' vsfw-admin vsfw-page';
				}

				return $classes;
			}
		);

		// Add admin bar styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'add_admin_bar_styles' ) );

		// Initialize Review notice.
		new Review_Notice();

		// Initialize Pro compatibility notice (warns when Pro is outdated).
		new Pro_Compat_Notice();

		// AI feature visibility — promo notice + product-editor "Generate with AI" entry point.
		new Ai_Promo_Notice();
		new Ai_Product_Metabox();
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'VidShop', 'vidshop-for-woocommerce' ),
			__( 'VidShop', 'vidshop-for-woocommerce' ),
			'manage_options',
			$this->menu_slug,
			array( $this, 'render_admin_page' ),
			$this->menu_icon(),
			20
		);

		add_submenu_page(
			$this->menu_slug,
			__( 'Analytics', 'vidshop-for-woocommerce' ),
			__( 'Analytics', 'vidshop-for-woocommerce' ),
			'manage_options',
			$this->menu_slug,
			array( $this, 'render_admin_page' ),
		);

		add_submenu_page(
			$this->menu_slug,
			__( 'Videos', 'vidshop-for-woocommerce' ),
			__( 'Videos', 'vidshop-for-woocommerce' ),
			'manage_options',
			"{$this->menu_slug}&path=videos",
			array( $this, 'render_admin_page' ),
		);

		add_submenu_page(
			$this->menu_slug,
			__( 'Add new video', 'vidshop-for-woocommerce' ),
			__( 'Add new video', 'vidshop-for-woocommerce' ),
			'manage_options',
			"{$this->menu_slug}&path=add-new",
			array( $this, 'render_admin_page' ),
		);

		add_submenu_page(
			$this->menu_slug,
			__( 'Tags', 'vidshop-for-woocommerce' ),
			__( 'Tags', 'vidshop-for-woocommerce' ),
			'manage_options',
			"{$this->menu_slug}&path=tags",
			array( $this, 'render_admin_page' ),
		);

		add_submenu_page(
			$this->menu_slug,
			__( 'Settings', 'vidshop-for-woocommerce' ),
			__( 'Settings', 'vidshop-for-woocommerce' ),
			'manage_options',
			"{$this->menu_slug}&path=settings",
			array( $this, 'render_admin_page' ),
		);
	}

	/**
	 * The brand mark for the WordPress admin menu, as a base64 SVG data URI.
	 *
	 * Vector on purpose. Core's `svg-painter.js` decodes a `data:image/svg+xml;base64,` menu
	 * icon and rewrites its `fill` to match whichever admin colour scheme the user picked, so
	 * one drawing stays legible on the light schemes as well as the dark ones. A PNG would keep
	 * a single fixed colour and disappear on half of them.
	 *
	 * Only the V is drawn. The logo's dark rounded tile belongs to the plugin's own chrome; on
	 * the admin menu it would read as a hole punched in the sidebar, and the painter would
	 * repaint it anyway.
	 *
	 * @return string
	 */
	private function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256"><path d="M133.8 117.8L137 113.8L137 112.8L145 100.5L152 91.2L162.5 80L168.5 75L177.5 69L184.8 66L192.2 65L195.8 66L197.8 67.8L199 69.5L199 76.8L198 78.2L198 80.2L197 81.2L197 83L195 86.2L195 87.8L194 88.5L192 94.2L191 95L187.8 102.8L187 103L185.8 106.5L183 110.5L183 111.8L182 112.2L182 113.5L179.2 117.2L179 118.8L178 119.2L178 120.5L166 139.5L152 158.8L134 180L123.5 189.8L117.2 193L113.2 193.8L108.8 193L105.2 191L101 186.5L101 185.5L98 181.5L98 180.2L97 179.8L97 178.2L95 175.5L94 171.5L93 170.8L93 169.2L91 165.8L91 164L89 160.5L88 156L86 152.5L86 150.8L85 149.8L85 148L84 147L84 145.2L82 141.5L82 139.8L81 138.8L81 137L80 136L78 129.5L77 128.5L77 127L75 123.8L75 122.2L74 121.5L73 118L68 109.5L68 108.2L67 107.8L66.8 106.2L59 94L58.2 88.8L59 86.5L60.5 85L64.8 83.5L69.8 84L78.8 89L85.8 95.2L92.8 103.2L93 104.5L96 107.5L103 117.8L108.2 123L113.2 126L116.8 127L122.2 127L125.2 126L129.5 123Z" fill="#ffffff"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {
		// Enqueue scripts.
		$this->register_admin_scripts();
		$this->enqueue_admin_scripts();
		?>
		<div id="vsfw-admin"></div>
		<?php
	}

	/**
	 * Enqueue admin scripts.
	 */
	public function enqueue_admin_scripts() {
		wp_enqueue_media();
		// Core's plugin AJAX installer (`wp.updates.installPlugin`). Lets the cross-promo card install
		// the sibling plugin in place, from WordPress.org, through core's own mechanism.
		wp_enqueue_script( 'updates' );
		wp_enqueue_script( 'vsfw-admin' );
		wp_enqueue_style( 'vsfw-admin' );

		// Allow developers to add custom scripts/styles if needed.
		do_action( 'vsfw_enqueue_additional_admin_assets' );
	}

	/**
	 * Register admin scripts.
	 */
	public function register_admin_scripts() {
		$assets_dir   = VSFW_PLUGIN_DIR . 'dist/admin';
		$assets_file  = $assets_dir . '/index.asset.php';
		$assets       = file_exists( $assets_file ) ? require_once $assets_file : array();
		$dependencies = isset( $assets['dependencies'] ) ? $assets['dependencies'] : array();
		$version      = isset( $assets['version'] ) ? $assets['version'] : VSFW_VERSION;

		// Ensure @wordpress/hooks is available so Pro (and other extensions)
		// can register JS filters that mutate Free's admin UI.
		if ( ! in_array( 'wp-hooks', $dependencies, true ) ) {
			$dependencies[] = 'wp-hooks';
		}

		// Register scripts.
		wp_register_script(
			'vsfw-admin',
			VSFW_PLUGIN_URL . 'dist/admin/index.js',
			$dependencies,
			$version,
		);

		// Localize scripts.
		wp_localize_script(
			'vsfw-admin',
			'svfwAdmin',
			$this->get_localized_data()
		);

		// Translate scripts.
		wp_set_script_translations( 'vsfw-admin', 'vidshop-for-woocommerce', VSFW_PLUGIN_DIR . 'languages' );

		// Register styles.
		wp_register_style(
			'vsfw-admin',
			VSFW_PLUGIN_URL . 'dist/admin/index.css',
			array(
				'wp-components',
			),
			$version,
		);

		// Rtl.
		wp_style_add_data( 'vsfw-admin', 'rtl', 'replace' );
	}

	/**
	 * Get localized data.
	 */
	private function get_localized_data() {
		$videos = Video_Model::count();

		// Frontend bundle URLs — the storefront builder loads these in an iframe to
		// render a true-to-production live preview at a real viewport width.
		$frontend_asset_file = VSFW_PLUGIN_DIR . 'dist/frontend/index.asset.php';
		$frontend_asset      = file_exists( $frontend_asset_file ) ? include $frontend_asset_file : array();
		$frontend_version    = isset( $frontend_asset['version'] ) ? $frontend_asset['version'] : VSFW_VERSION;

		$pro_version  = defined( 'VIDSHOP_PRO_VERSION' ) ? VIDSHOP_PRO_VERSION : null;
		$pro_outdated = $pro_version && defined( 'VSFW_MIN_PRO_VERSION' )
			? version_compare( $pro_version, VSFW_MIN_PRO_VERSION, '<' )
			: false;

		$data = array(
			'ajax_url'            => admin_url( 'admin-ajax.php' ),
			'nonce'               => wp_create_nonce( 'vsfw-admin' ),
			'assets_url'          => VSFW_PLUGIN_URL . 'assets',
			'rest_url'            => esc_url_raw( rest_url() ),
			'rest_nonce'          => wp_create_nonce( 'wp_rest' ),
			'checkout_url'        => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'site_host'           => wp_parse_url( home_url(), PHP_URL_HOST ),
			'frontend_assets'     => array(
				'js'  => add_query_arg( 'ver', $frontend_version, VSFW_PLUGIN_URL . 'dist/frontend/index.js' ),
				'css' => add_query_arg( 'ver', $frontend_version, VSFW_PLUGIN_URL . 'dist/frontend/index.css' ),
			),
			'is_woo_active'       => $this->woocommerce->is_active(),
			'videos_count'        => $videos,
			'is_pro'              => apply_filters( 'vsfw_is_pro', false ),
			'plugin_version'      => VSFW_VERSION,
			'currency_format'     => $this->settings->get_currency_format(),
			'pro_version'         => $pro_version,
			'pro_outdated'        => $pro_outdated,
			'min_pro_version'     => defined( 'VSFW_MIN_PRO_VERSION' ) ? VSFW_MIN_PRO_VERSION : null,
			'plugins_admin_url'   => self_admin_url( 'plugins.php?plugin_status=upgrade' ),
			'current_user_email'  => wp_get_current_user()->user_email,
			'current_user_name'   => wp_get_current_user()->display_name,
			'cloud'               => Cloud_Connection::localized_identity(),
			'cloud_dashboard_url' => Cloud_Connection::dashboard_base_url(),
			// Freemius "Contact Us" URL — filled by the Pro add-on; empty on free-only installs.
			'support_url'         => apply_filters( 'vsfw_support_url', '' ),
			// Whether a VidShop Pro license is present (Pro add-on active + licensed) — lets the UI
			// offer a free→Pro "switch" without exposing the key itself.
			'has_pro_license'     => ! empty( apply_filters( 'vsfw_cloud_license_key', null ) ),
			// The "More from WPCreatix" cross-promo: a filtered registry of our other free plugins, each
			// with its own install/activate state so a card can show the right call to action.
			'siblings'            => $this->get_siblings_promo_data(),
			// Feature-level promo flags shared by every sibling card: the dashboard engagement gate and
			// the in-app "Generate with AI" banner dismissal (the dashboard slot's fallback).
			'siblings_meta'       => $this->get_siblings_meta(),
		);

		/**
		 * Filter the localized data blob passed to the admin bundle.
		 *
		 * Pro uses this to inject presets, feature flags, etc.
		 */
		return apply_filters( 'vsfw_admin_localized_data', $data );
	}

	/**
	 * The WPCreatix plugin portfolio.
	 *
	 * A tiny registry the cross-promo is built from: every WPCreatix plugin that ships to WordPress.org,
	 * keyed by its wp.org slug. VidShop excludes *itself* and promotes the rest ({@see get_siblings_promo_data()}).
	 * `requires_woo` drives the render-time filter — a WooCommerce-only sibling is hidden on stores
	 * without WooCommerce.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_portfolio() {
		return array(
			'media-sweep'              => array(
				'slug'         => 'media-sweep',
				'name'         => __( 'Media Sweep', 'vidshop-for-woocommerce' ),
				'file'         => 'media-sweep/media-sweep.php',
				'menu_slug'    => 'mswp',
				'requires_woo' => false,
				'tagline'      => __( 'Find and safely remove unused media to keep your library lean and your site fast.', 'vidshop-for-woocommerce' ),
			),
			'vidshop-for-woocommerce'  => array(
				'slug'         => 'vidshop-for-woocommerce',
				'name'         => __( 'VidShop', 'vidshop-for-woocommerce' ),
				'file'         => 'vidshop-for-woocommerce/vidshop-for-woocommerce.php',
				'requires_woo' => true,
				'tagline'      => __( 'Turn shoppers into buyers with shoppable video feeds.', 'vidshop-for-woocommerce' ),
			),
			'wpcreatix-ai-sales-agent' => array(
				'slug'         => 'wpcreatix-ai-sales-agent',
				'name'         => __( 'WPCreatix AI Sales Agent', 'vidshop-for-woocommerce' ),
				'file'         => 'wpcreatix-ai-sales-agent/wpcreatix-ai-sales-agent.php',
				'menu_slug'    => 'wpcx-sa',
				'requires_woo' => true,
				'tagline'      => __( 'An AI sales agent that answers product questions and walks shoppers to checkout.', 'vidshop-for-woocommerce' ),
			),
		);
	}

	/**
	 * The current plugin's slug — the one entry excluded from its own cross-promo.
	 *
	 * @var string
	 */
	const SELF_SLUG = 'vidshop-for-woocommerce';

	/**
	 * The sibling plugins to promote, in display order. The dashboard slot shows the first relevant
	 * entry, so the higher-intent AI Sales Agent leads and Media Sweep follows.
	 *
	 * @var string[]
	 */
	const SIBLING_ORDER = array( 'wpcreatix-ai-sales-agent', 'media-sweep' );

	/**
	 * Per-product install state for one portfolio entry.
	 *
	 * Everything a sibling card needs to pick its call to action, all computed locally (no network).
	 * Install and activate links route through WordPress core's own screens (never our servers) and are
	 * only populated when the current user actually holds the matching capability — the button is
	 * hidden otherwise.
	 *
	 * @param array<string, mixed> $product A portfolio registry entry.
	 * @return array<string, mixed>
	 */
	private function build_sibling_state( array $product ) {
		$slug = $product['slug'];
		$file = $product['file'];

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed   = file_exists( WP_PLUGIN_DIR . '/' . $file );
		$active      = $installed && is_plugin_active( $file );
		$can_install = current_user_can( 'install_plugins' );
		$dismissed   = (bool) get_user_meta( get_current_user_id(), Promo_Controller::dismissed_meta_key( $slug ), true );

		// Native install: core's plugin search, pre-filtered to the plugin. Reviewers require the
		// install to go through WordPress's own UI, and the merchant lands on a screen where they can
		// read the full listing before installing.
		$install_url = ( ! $installed && $can_install )
			? self_admin_url( 'plugin-install.php?tab=search&type=term&s=' . rawurlencode( $product['name'] ) )
			: '';

		// Installed-but-inactive: a normal, nonce-protected core activation link. `wp_nonce_url()`
		// HTML-encodes the ampersands for direct HTML output; decode them so the URL survives being
		// handed to JS and used as a React href (otherwise `&amp;` reaches the server and the nonce
		// check 403s).
		$activate_url = ( $installed && ! $active && current_user_can( 'activate_plugins' ) )
			? html_entity_decode( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ) )
			: '';

		// The plugin's own mark, bundled rather than fetched from ps.w.org: the admin makes no remote
		// calls, and the icon has to render on a store that is offline or behind a firewall. Empty
		// when we ship no mark for it, and the UI falls back to a line glyph.
		$icon        = VSFW_PLUGIN_DIR . 'assets/images/siblings/' . $slug . '.webp';
		$icon_url    = file_exists( $icon ) ? VSFW_PLUGIN_URL . 'assets/images/siblings/' . $slug . '.webp' : '';
		// Where the plugin lives once it is running, so activating hands the merchant over to it.
		$admin_url   = empty( $product['menu_slug'] ) ? '' : admin_url( 'admin.php?page=' . $product['menu_slug'] );
		$banner      = VSFW_PLUGIN_DIR . 'assets/images/siblings/' . $slug . '-banner.webp';
		$banner_url  = file_exists( $banner ) ? VSFW_PLUGIN_URL . 'assets/images/siblings/' . $slug . '-banner.webp' : '';

		return array(
			'slug'         => $slug,
			// The plugin file, for core's in-place activatePlugin(); the rail activates without leaving the page.
			'file'         => $file,
			'icon'         => $icon_url,
			'banner'       => $banner_url,
			'admin_url'    => $admin_url,
			'name'         => $product['name'],
			'tagline'      => $product['tagline'],
			'requires_woo' => (bool) $product['requires_woo'],
			'installed'    => $installed,
			'active'       => $active,
			'can_install'  => $can_install,
			'wporg_url'    => 'https://wordpress.org/plugins/' . $slug . '/',
			'install_url'  => $install_url,
			'activate_url' => $activate_url,
			'dismissed'    => $dismissed,
		);
	}

	/**
	 * The filtered list of sibling plugins to cross-promote.
	 *
	 * Drops a WooCommerce-only sibling on a store without WooCommerce, and never lists VidShop
	 * itself. Each entry carries its own installed/active state for the UI to render.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_siblings_promo_data() {
		$portfolio  = $this->get_portfolio();
		$woo_active = class_exists( 'WooCommerce' );
		$siblings   = array();

		foreach ( self::SIBLING_ORDER as $slug ) {
			if ( self::SELF_SLUG === $slug || ! isset( $portfolio[ $slug ] ) ) {
				continue;
			}

			$product = $portfolio[ $slug ];

			// Hide a WooCommerce-only sibling on a store without WooCommerce.
			if ( ! empty( $product['requires_woo'] ) && ! $woo_active ) {
				continue;
			}

			// Active siblings are kept, not dropped: the rail lists them with their state, so a
			// merchant sees "Active" rather than the row silently vanishing mid-install. Surfaces
			// that only want something to sell can filter on `active` themselves.
			$siblings[] = $this->build_sibling_state( $product );
		}

		return $siblings;
	}

	/**
	 * Feature-level flags shared by all sibling cards.
	 *
	 * `should_prompt` is the engagement gate for the *dashboard* slot: it reuses the review notice's
	 * "this store is getting value" latch (`vsfw_review_success_reached`) so a brand-new install is
	 * never cross-sold, and only when the user can actually install. The settings list ignores this
	 * flag — that page is opened deliberately. `ai_banner_dismissed` is whether this user dismissed the
	 * in-app "Generate with AI" banner, the dashboard slot's fallback once the sibling card is gone.
	 *
	 * @return array<string, bool>
	 */
	private function get_siblings_meta() {
		return array(
			'should_prompt'       => current_user_can( 'install_plugins' ) && (bool) get_option( 'vsfw_review_success_reached' ),
			'ai_banner_dismissed' => (bool) get_user_meta( get_current_user_id(), Promo_Controller::AI_BANNER_DISMISSED_META, true ),
		);
	}

	/**
	 * Check if the current page is an admin page.
	 *
	 * @return bool
	 */
	public static function is_admin_page(): bool {
		// Check if the current page is an admin page.
		$current_screen = get_current_screen();
		if ( ! $current_screen ) {
			return false;
		}

		// Check if the current screen ID matches the admin page slug.
		return strpos( $current_screen->id, 'vsfw' ) !== false;
	}

	/**
	 * Runs before admin notices action and hides them.
	 *
	 * @since 1.0.0
	 */
	public static function inject_before_notices() {
		if ( ! self::is_admin_page() ) {
			return;
		}

		// Wrap the notices in a hidden div to prevent flickering before
		// they are moved elsewhere in the page by WordPress Core.
		echo '<div class="vsfw-layout__notice-list-hide" style="display: none;" id="wp__notice-list">';

		if ( self::is_admin_page() ) {
			// Capture all notices and hide them. WordPress Core looks for
			// `.wp-header-end` and appends notices after it if found.
			// https://github.com/WordPress/WordPress/blob/f6a37e7d39e2534d05b9e542045174498edfe536/wp-admin/js/common.js#L737 .
			echo '<div class="wp-header-end" id="vsfw-layout__notice-catcher"></div>';
		}
	}

	/**
	 * Runs after admin notices and closes div.
	 *
	 * @since 1.0.0
	 */
	public static function inject_after_notices() {
		if ( ! self::is_admin_page() ) {
				return;
		}
		// Close the hidden div used to prevent notices from flickering before
		// they are inserted elsewhere in the page.
		echo '</div>';
	}

	/**
	 * Remove Notices.
	 *
	 * @since 1.0.0
	 */
	public function remove_notices() {

		if ( ! self::is_admin_page() ) {
			return;
		}

		// Hello Dolly.
		if ( function_exists( 'hello_dolly' ) ) {
			remove_action( 'admin_notices', 'hello_dolly' );
		}
	}

	/**
	 * Change Admin footer text.
	 *
	 * @param string $text The text to change.
	 * @return string
	 */
	public function change_admin_footer_text( $text ) {
		if ( ! self::is_admin_page() ) {
			return $text;
		}

		/* translators: 1: Thanks for using, 2: VidShop for WooCommerce, 3: crafted with ❤️ by */
		return sprintf(
			'<span id="footer-thankyou">%1$s <strong>%2$s</strong> — %3$s <a href="https://wpcreatix.com" target="_blank" rel="noopener">WPCreatix</a></span>',
			esc_html__( 'Thanks for using', 'vidshop-for-woocommerce' ),
			esc_html__( 'VidShop for WooCommerce', 'vidshop-for-woocommerce' ),
			esc_html__( 'crafted with ❤️ by', 'vidshop-for-woocommerce' )
		);
	}
}
