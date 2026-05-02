<?php
/**
 * Interface for WooCommerce.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Interfaces;

/**
 * Interface for WooCommerce.
 */
interface WooCommerce {

	/**
	 * Is woocommerce installed.
	 *
	 * @return bool
	 */
	public function is_installed();

	/**
	 * Is WooCommerce active.
	 *
	 * @return bool
	 */
	public function is_active();

	/**
	 * WooCommerce version.
	 *
	 * @return string
	 */
	public function get_version();

	/**
	 * Prepare product.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return array
	 */
	public function prepare_product( $product_id );

	/**
	 * Prepare simple product.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return array
	 */
	public function prepare_simple_product( $product_id );

	/**
	 * Get product variations.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array
	 */
	public function get_product_variations( $product );

	/**
	 * Get product attributes.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array
	 */
	public function get_product_attributes( $product );

	/**
	 * Initialize WooCommerce session, customer, and cart if needed.
	 *
	 * @return bool True if WooCommerce is loaded, false otherwise.
	 */
	public function init_cart();

	/**
	 * Get cart data.
	 *
	 * @return array
	 */
	public function get_cart_data();

	/**
	 * Add product to cart
	 *
	 * @param int   $product_id           Product ID.
	 * @param int   $quantity             Quantity.
	 * @param int   $variation_id         Variation ID.
	 * @param array $variation_attributes Variation attributes.
	 *
	 * @return string|false Cart item key or false on failure.
	 */
	public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation_attributes = array() );

	/**
	 * Remove item from cart
	 *
	 * @param string $item_key Cart item key.
	 *
	 * @return bool Success or failure.
	 */
	public function remove_from_cart( $item_key );

	/**
	 * Update cart item quantity
	 *
	 * @param string $item_key Cart item key.
	 * @param int    $quantity New quantity.
	 *
	 * @return bool Success or failure.
	 */
	public function update_quantity( $item_key, $quantity );

	/**
	 * Get product and verify it exists
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return \WC_Product|false Product object or false if not found.
	 */
	public function get_product( $product_id );

	/**
	 * Check if product is variable and requires variation ID
	 *
	 * @param \WC_Product $product Product object.
	 * @param int         $variation_id Variation ID.
	 *
	 * @return bool True if valid, false if variation required but not provided.
	 */
	public function validate_product_variation( $product, $variation_id = 0 );
}
