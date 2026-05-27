<?php
/**
 * AI promo notice.
 *
 * A dismissible admin banner that nudges merchants to try AI video generation. Shown only on
 * VidShop + WooCommerce product screens, and **only until the site has generated its first AI
 * video** (adaptive — once adopted, the promo stops). Per-user permanent dismiss.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Admin;

use VSFW\Services\Ai_Generation_Service;

/**
 * AI promo notice.
 */
class Ai_Promo_Notice {

	/** Per-user meta key flagging a permanent dismiss. */
	const DISMISSED_META = 'vsfw_ai_promo_dismissed';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'show' ) );
		add_action( 'wp_ajax_vsfw_dismiss_ai_promo', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Render the notice on relevant screens, unless dismissed or the site already generated a video.
	 *
	 * @return void
	 */
	public function show() {
		if ( get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) ) {
			return;
		}
		if ( Ai_Generation_Service::has_generated() ) {
			return; // Feature adopted — stop promoting.
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$on_vsfw    = false !== strpos( (string) $screen->id, 'vsfw' );
		$on_product = ( 'product' === $screen->post_type ) || ( 'edit-product' === $screen->id );
		if ( ! $on_vsfw && ! $on_product ) {
			return;
		}

		// Don't promote on the Add New screen — that's where the CTA already leads.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		if ( $on_vsfw && 'add-new' === $path ) {
			return;
		}

		$cta_url = admin_url( 'admin.php?page=vsfw&path=add-new&vsfw_generate=1' );
		$nonce   = wp_create_nonce( 'vsfw_dismiss_ai_promo' );
		?>
		<div class="notice is-dismissible vsfw-ai-promo" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="vsfw-ai-promo__inner">
				<span class="vsfw-ai-promo__icon" aria-hidden="true">&#10024;</span>
				<div class="vsfw-ai-promo__text">
					<strong><?php esc_html_e( 'New: Generate shoppable videos with AI', 'vidshop-for-woocommerce' ); ?></strong>
					<span><?php esc_html_e( 'Turn any product photo into a ready-to-sell video in minutes. Your first one is free.', 'vidshop-for-woocommerce' ); ?></span>
				</div>
				<a href="<?php echo esc_url( $cta_url ); ?>" class="button button-primary vsfw-ai-promo__cta">
					<?php esc_html_e( 'Try it free', 'vidshop-for-woocommerce' ); ?>
				</a>
			</div>
		</div>
		<style>
			.vsfw-ai-promo { border-left: 4px solid #7209b7 !important; }
			.vsfw-ai-promo__inner { display: flex; align-items: center; gap: 0.85rem; padding: 0.4rem 0; }
			.vsfw-ai-promo__icon { font-size: 1.4rem; line-height: 1; }
			.vsfw-ai-promo__text { flex: 1; display: flex; flex-direction: column; gap: 0.1rem; }
			.vsfw-ai-promo__text strong { font-size: 14px; }
			.vsfw-ai-promo__text span { color: #555; font-size: 13px; }
			.vsfw-ai-promo__cta { flex-shrink: 0; background: #4361ee !important; border-color: #4361ee !important; }
			@media (max-width: 782px) {
				.vsfw-ai-promo__inner { flex-direction: column; align-items: flex-start; }
			}
		</style>
		<script type="text/javascript">
		jQuery( function ( $ ) {
			$( document ).on( 'click', '.vsfw-ai-promo .notice-dismiss', function () {
				var $n = $( this ).closest( '.vsfw-ai-promo' );
				$.post( ajaxurl, { action: 'vsfw_dismiss_ai_promo', nonce: $n.data( 'nonce' ) } );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Permanently dismiss the notice for the current user.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		check_ajax_referer( 'vsfw_dismiss_ai_promo', 'nonce' );
		update_user_meta( get_current_user_id(), self::DISMISSED_META, 1 );
		wp_send_json_success();
	}
}
