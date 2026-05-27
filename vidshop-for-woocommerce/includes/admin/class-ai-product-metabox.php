<?php
/**
 * Product-edit AI metabox.
 *
 * A "Generate with AI" entry point in the WooCommerce product editor sidebar. Links to the Add New
 * Video screen with the product pre-selected, opening the generate modal straight away (the strongest
 * discovery surface — the merchant is already editing the product).
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Admin;

/**
 * Product AI metabox.
 */
class Ai_Product_Metabox {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
	}

	/**
	 * Register the metabox on the product editor.
	 *
	 * @return void
	 */
	public function register() {
		add_meta_box(
			'vsfw_ai_generate',
			__( 'VidShop AI', 'vidshop-for-woocommerce' ),
			array( $this, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the metabox body.
	 *
	 * @param \WP_Post $post Product post.
	 * @return void
	 */
	public function render( $post ) {
		$url = admin_url( 'admin.php?page=vsfw&path=add-new&vsfw_product=' . (int) $post->ID );
		?>
		<p style="margin: 0 0 0.75rem; color: #555;">
			<?php esc_html_e( 'Turn this product into a shoppable video with AI. No camera or editing needed.', 'vidshop-for-woocommerce' ); ?>
		</p>
		<a href="<?php echo esc_url( $url ); ?>" class="button button-primary" style="width: 100%; text-align: center; background: #4361ee; border-color: #4361ee;">
			<?php esc_html_e( '✨ Generate with AI', 'vidshop-for-woocommerce' ); ?>
		</a>
		<?php
	}
}
