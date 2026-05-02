<?php
/**
 * Admin notice shown when the active Pro plugin is outdated.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Admin;

/**
 * Pro Compatibility Notice.
 */
class Pro_Compat_Notice {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'admin_init', array( $this, 'handle_dismissal' ) );
	}

	/**
	 * Whether Pro is active but older than the minimum expected version.
	 *
	 * @return bool
	 */
	public static function is_pro_outdated() {
		if ( ! defined( 'VIDSHOP_PRO_VERSION' ) || ! defined( 'VSFW_MIN_PRO_VERSION' ) ) {
			return false;
		}
		return version_compare( VIDSHOP_PRO_VERSION, VSFW_MIN_PRO_VERSION, '<' );
	}

	/**
	 * Render the notice if applicable.
	 */
	public function maybe_render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! self::is_pro_outdated() ) {
			return;
		}

		$notice_id = 'pro_outdated_' . VSFW_MIN_PRO_VERSION;
		$user_id   = get_current_user_id();
		if ( get_user_meta( $user_id, 'vsfw_dismissed_' . $notice_id, true ) ) {
			return;
		}

		$update_url  = self_admin_url( 'plugins.php?plugin_status=upgrade' );
		$dismiss_url = wp_nonce_url(
			add_query_arg( array( 'vsfw_dismiss_notice' => $notice_id ), admin_url() ),
			'vsfw_dismiss_' . $notice_id
		);

		$message = sprintf(
			/* translators: 1: required version, 2: installed version */
			__( '<strong>VidShop Pro</strong> needs to be updated to version %1$s or later to unlock Pro features in the new admin UI (currently %2$s).', 'vidshop-for-woocommerce' ),
			esc_html( VSFW_MIN_PRO_VERSION ),
			esc_html( VIDSHOP_PRO_VERSION )
		);
		?>
		<div class="notice notice-warning vsfw-pro-compat-notice" data-notice-id="<?php echo esc_attr( $notice_id ); ?>">
			<p>
				<?php echo wp_kses_post( $message ); ?>
				&nbsp;<a class="button button-primary" href="<?php echo esc_url( $update_url ); ?>"><?php esc_html_e( 'Update now', 'vidshop-for-woocommerce' ); ?></a>
				&nbsp;<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:6px;"><?php esc_html_e( 'Dismiss', 'vidshop-for-woocommerce' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle dismissal clicks.
	 */
	public function handle_dismissal() {
		if ( empty( $_GET['vsfw_dismiss_notice'] ) ) {
			return;
		}
		$id = sanitize_key( wp_unslash( $_GET['vsfw_dismiss_notice'] ) );
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'vsfw_dismiss_' . $id ) ) {
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), 'vsfw_dismissed_' . $id, 1 );
		wp_safe_redirect( remove_query_arg( array( 'vsfw_dismiss_notice', '_wpnonce' ) ) );
		exit;
	}
}
