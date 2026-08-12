<?php
/**
 * Order attribution.
 *
 * Carries "this line was added from this video" from the widget's add-to-cart request, through the
 * WooCommerce cart, onto the order line item, and rolls it up to an order-level flag.
 *
 * Deliberately stores no money. WooCommerce already holds every line's total, tax, refunds,
 * currency and status, and keeps them correct as orders change — copying them into a table of our
 * own would mean five more hooks to keep in sync and five more chances to disagree with the store's
 * real numbers. What VidShop adds is the one fact WooCommerce cannot know: which video sold it.
 * Revenue is recomputed from the live line items whenever a report is read, so refunds and status
 * changes are reflected without any state of ours to maintain.
 *
 * Capture lives in the free plugin even though only Pro reports on it: free owns every surface
 * involved (the REST add-to-cart route, the cart pipeline, sessions), and a merchant who upgrades
 * then sees history immediately rather than an empty report.
 *
 * @package VidShop
 */

namespace VSFW\Services;

use VSFW\Models\Video_Session_Model;
use VSFW\Models\Video_Event_Model;

/**
 * Stamps VidShop add-to-carts and flags the orders they become.
 */
class Order_Attribution {

	/**
	 * Order meta flag marking an order as containing VidShop-attributed lines.
	 *
	 * Indexed in both order backends, so "find attributed orders in this range" stays a cheap
	 * lookup instead of a scan over every order in the store.
	 */
	const ORDER_FLAG = '_vsfw_attributed';

	/** Line item meta: the video the line was added from. */
	const ITEM_VIDEO_ID = '_vsfw_video_id';

	/** Line item meta: the feed the video was playing in. */
	const ITEM_STOREFRONT_ID = '_vsfw_storefront_id';

	/** Line item meta: the viewing session, for funnel stitching. */
	const ITEM_SESSION_ID = '_vsfw_session_id';

	/**
	 * Attribution waiting to be attached to the next cart item.
	 *
	 * Request-scoped on purpose: it is set immediately before our own `add_to_cart()` call and
	 * cleared by the filter that consumes it, so an add from the theme, a product page or the
	 * Store API is never stamped. VidShop only ever claims quantity that went through the widget.
	 *
	 * @var array|null
	 */
	private $pending = null;

	/**
	 * Wire the hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'stamp_cart_item' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'stamp_order_item' ), 10, 3 );

		// Both checkout stacks land here: classic fires the first, the Store API (block checkout)
		// the second. Deliberately NOT `woocommerce_new_order`, which also fires for admin-created
		// orders, imports and subscription renewals — renewals copy line meta, so that hook would
		// credit the same video again for every renewal of one purchase.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'flag_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'flag_order' ), 20 );

		// The checkout-started funnel stage. Fires for classic and block checkout alike, because
		// both render the checkout page; `woocommerce_before_checkout_form` does not.
		add_action( 'template_redirect', array( $this, 'record_checkout_started' ) );
	}

	/**
	 * Queue attribution for the add-to-cart that is about to happen.
	 *
	 * @param array $attribution { session_token, session_id, video_id, storefront_id }.
	 * @return void
	 */
	public function set_pending_attribution( array $attribution ) {
		$this->pending = $attribution;
	}

	/**
	 * Attach the queued attribution to the cart item being created.
	 *
	 * Because this data joins the cart item's identity hash, the same product added from two
	 * different videos becomes two cart lines, each attributed to its own video — which is what
	 * keeps per-video revenue exact. The same product added from the product page carries no stamp,
	 * so it forms its own unattributed line and VidShop never claims it.
	 *
	 * @param array $cart_item_data Existing cart item data.
	 * @param int   $product_id     Product being added.
	 * @return array
	 */
	public function stamp_cart_item( $cart_item_data, $product_id ) {
		if ( $this->pending ) {
			$cart_item_data['vsfw_attribution'] = $this->pending;
			$this->pending                      = null;
		}

		return $cart_item_data;
	}

	/**
	 * Carry the attribution onto the order line item.
	 *
	 * Underscore-prefixed meta, so WooCommerce hides it from the order screen, emails, the customer
	 * account and Store API responses.
	 *
	 * @param \WC_Order_Item_Product $item          Line item being created.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item data.
	 * @return void
	 */
	public function stamp_order_item( $item, $cart_item_key, $values ) {
		if ( empty( $values['vsfw_attribution'] ) ) {
			return;
		}

		$attribution = $values['vsfw_attribution'];

		$item->add_meta_data( self::ITEM_VIDEO_ID, (int) ( $attribution['video_id'] ?? 0 ), true );
		$item->add_meta_data( self::ITEM_STOREFRONT_ID, (int) ( $attribution['storefront_id'] ?? 0 ), true );
		$item->add_meta_data( self::ITEM_SESSION_ID, (int) ( $attribution['session_id'] ?? 0 ), true );

		// WooCommerce saves the item as part of order creation — no save() here.
	}

	/**
	 * Flag an order that contains attributed lines.
	 *
	 * The flag exists purely so reports can find these orders without walking the whole store; the
	 * per-line detail already lives on the items. The video ids ride along so a report can filter
	 * by video before loading anything.
	 *
	 * @param int|\WC_Order $order_id Order ID, or the order itself (the Store API hook passes one).
	 * @return void
	 */
	public function flag_order( $order_id ) {
		$order = $order_id instanceof \WC_Order ? $order_id : wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$video_ids = array();

		foreach ( $order->get_items() as $item ) {
			$video_id = (int) $item->get_meta( self::ITEM_VIDEO_ID, true );

			if ( $video_id ) {
				$video_ids[ $video_id ] = $video_id;
			}
		}

		// The overwhelming majority of orders contain nothing from a video; they exit here having
		// cost one meta read per line and nothing else.
		if ( empty( $video_ids ) ) {
			return;
		}

		$order->update_meta_data( self::ORDER_FLAG, 'yes' );
		$order->update_meta_data( '_vsfw_video_ids', array_values( $video_ids ) );
		$order->save();
	}

	/**
	 * Record that a shopper reached checkout with an attributed cart.
	 *
	 * Recorded as an ordinary event so it inherits the existing dedup and the sessions join that
	 * makes every other event date-filterable — no new table for one funnel stage.
	 *
	 * Known undercount, worth documenting rather than fixing: express-pay buttons on the cart skip
	 * the checkout page entirely, so those orders reach the Orders stage without passing this one.
	 *
	 * @return void
	 */
	public function record_checkout_started() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['vsfw_attribution'] ) ) {
				continue;
			}

			$attribution = $cart_item['vsfw_attribution'];
			$session_id  = (int) ( $attribution['session_id'] ?? 0 );
			$video_id    = (int) ( $attribution['video_id'] ?? 0 );

			if ( ! $session_id || ! $video_id ) {
				continue;
			}

			// One row per session+video, however many times the page is loaded or reloaded.
			$existing = Video_Event_Model::query()
				->where( 'session_id', $session_id )
				->where( 'video_id', $video_id )
				->where( 'event_type', 'checkout_started' )
				->first();

			if ( $existing ) {
				continue;
			}

			Video_Event_Model::create_without_validation(
				array(
					'session_id' => $session_id,
					'video_id'   => $video_id,
					'event_type' => 'checkout_started',
				)
			);
		}
	}

	/**
	 * Resolve (or mint) the session an add-to-cart belongs to.
	 *
	 * A shopper who buys within the first twenty seconds has no session yet, because the tracking
	 * batch has not fired. Minting one here means the fast buyers — the most valuable ones — are
	 * not the ones attribution misses.
	 *
	 * @param string $session_token Token from the request, if the widget already has one.
	 * @param string $visitor_id    Visitor fingerprint, used when a session must be created.
	 * @param int    $storefront_id Feed the video was playing in.
	 * @return array { id, token } or an empty array when neither is available.
	 */
	public function resolve_session( $session_token, $visitor_id, $storefront_id = 0 ) {
		if ( $session_token ) {
			$session = Video_Session_Model::query()->where( 'session_token', $session_token )->first();

			if ( $session ) {
				return array(
					'id'    => (int) $session->get_key(),
					'token' => $session->session_token,
				);
			}
		}

		if ( ! $visitor_id ) {
			return array();
		}

		$session = Video_Session_Model::create_session(
			$visitor_id,
			get_current_user_id() ?: null,
			(int) $storefront_id
		);

		if ( ! $session ) {
			return array();
		}

		return array(
			'id'    => (int) $session->get_key(),
			'token' => $session->session_token,
		);
	}
}
