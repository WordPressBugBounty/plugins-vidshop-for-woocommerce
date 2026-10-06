<?php
/**
 * Analytics Controller
 *
 * @package VidShop
 */

namespace VSFW\REST_API\V1;

use VSFW\Models\Video_Model;
use VSFW\Models\Video_Session_Model;
use VSFW\Models\Video_Event_Model;
use VSFW\Models\Video_View_Time_Model;
use VSFW\Models\Video_Product_Stats_Model;
use VSFW\Models\Storefront_Model;
use VSFW\Interfaces\WooCommerce;
use VSFW\Utils\Tier;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Analytics Controller
 */
class Analytics_Controller extends REST_Controller {

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'analytics';

	/**
	 * WooCommerce service
	 *
	 * @var WooCommerce
	 */
	protected $woocommerce;

	/**
	 * Constructor
	 *
	 * @param WooCommerce $woocommerce WooCommerce service.
	 */
	public function __construct( WooCommerce $woocommerce ) {
		$this->woocommerce = $woocommerce;
	}

	/**
	 * Register routes
	 */
	public function register_routes() {
		// Get analytics
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'date_range' => array(
							'required'    => false,
							'type'        => 'string',
							'enum'        => array( 'this_week', 'last_week', 'this_month', 'last_month', 'all_time', 'custom' ),
							'default'     => 'this_week',
							'description' => __( 'Date range for analytics.', 'vidshop-for-woocommerce' ),
						),
						'start_date' => array(
							'required'    => false,
							'type'        => 'string',
							'format'      => 'date',
							'description' => __( 'Start date for custom date range (YYYY-MM-DD).', 'vidshop-for-woocommerce' ),
						),
						'end_date'   => array(
							'required'    => false,
							'type'        => 'string',
							'format'      => 'date',
							'description' => __( 'End date for custom date range (YYYY-MM-DD).', 'vidshop-for-woocommerce' ),
						),
						'storefront_id' => array(
							'required'    => false,
							'type'        => 'integer',
							'description' => __( 'Scope the analytics to a single storefront.', 'vidshop-for-woocommerce' ),
						),
					),
				),
			)
		);

		// Per-storefront analytics — same summary, scoped to one storefront id.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/storefronts/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'date_range' => array(
							'required' => false,
							'type'     => 'string',
							'enum'     => array( 'this_week', 'last_week', 'this_month', 'last_month', 'all_time', 'custom' ),
							'default'  => 'this_week',
						),
						'start_date' => array( 'required' => false, 'type' => 'string' ),
						'end_date'   => array( 'required' => false, 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * The Sunday that starts the week containing a given day.
	 *
	 * Shared with the Pro revenue report through `Analytics_Controller::week_start()`, because the
	 * two used different anchors — this one Sunday, revenue's `monday this week` — while the
	 * revenue docblock claimed both agreed. The Analytics and Revenue tabs reported different weeks.
	 *
	 * @param string $day Day as `Y-m-d`.
	 * @return string The week's first day, as `Y-m-d`.
	 */
	public static function week_start( $day ) {
		$timestamp = strtotime( $day );

		// Already Sunday: the week starts today, not seven days ago.
		if ( 0 === (int) date( 'w', $timestamp ) ) {
			return date( 'Y-m-d', $timestamp );
		}

		return date( 'Y-m-d', strtotime( 'last sunday', $timestamp ) );
	}

	/**
	 * Get analytics data
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_analytics( $request ) {
		$date_range = $request->get_param( 'date_range' );
		$start_date = $request->get_param( 'start_date' );
		$end_date   = $request->get_param( 'end_date' );

		// Get date range
		$dates = $this->get_date_range( $date_range, $start_date, $end_date );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		$start_date_sql = $dates['start_date'];
		$end_date_sql   = $dates['end_date'];

		// Optional storefront scope — from the /storefronts/{id} route (`id`) or the
		// `storefront_id` query arg on the base endpoint. null = site-wide (unchanged).
		$storefront_param = $request->get_param( 'id' );
		if ( null === $storefront_param ) {
			$storefront_param = $request->get_param( 'storefront_id' );
		}
		$storefront_id = ( null !== $storefront_param && '' !== $storefront_param ) ? (int) $storefront_param : null;

		// Per-storefront reports are a Pro feature. Refusing here keeps REST and
		// the abilities layer, which dispatches through this route, in agreement.
		if ( null !== $storefront_id && ! Tier::is_pro() ) {
			return new WP_Error(
				'vsfw_pro_required',
				__( 'Per-storefront analytics needs VidShop Pro.', 'vidshop-for-woocommerce' ),
				array(
					'status'  => 403,
					'feature' => 'storefront_analytics',
				)
			);
		}

		// A scoped request must point at a real storefront (0 = legacy traffic, not a storefront).
		$storefront = null;
		if ( null !== $storefront_id ) {
			$storefront = Storefront_Model::find( $storefront_id );

			if ( ! $storefront ) {
				return new WP_Error( 'storefront_not_found', __( 'Storefront not found', 'vidshop-for-woocommerce' ), array( 'status' => 404 ) );
			}
		}

		// Get analytics data using model methods
		$total_views          = Video_Session_Model::get_total_sessions( $start_date_sql, $end_date_sql, null, $storefront_id );
		$unique_views         = Video_Session_Model::get_unique_sessions( $start_date_sql, $end_date_sql, null, $storefront_id );
		$total_likes          = Video_Event_Model::get_total_likes( $start_date_sql, $end_date_sql, null, $storefront_id );
		$unique_likes         = Video_Event_Model::get_unique_likes( $start_date_sql, $end_date_sql, null, $storefront_id );
		$total_view_time      = Video_View_Time_Model::get_total_view_time( $start_date_sql, $end_date_sql, null, $storefront_id );
		$avg_view_time        = Video_View_Time_Model::get_average_view_time( $start_date_sql, $end_date_sql, null, $storefront_id );
		$total_add_to_cart    = Video_Product_Stats_Model::get_total_add_to_cart( $start_date_sql, $end_date_sql, $storefront_id );
		$total_views_products = Video_Product_Stats_Model::get_total_views( $start_date_sql, $end_date_sql, $storefront_id );
		$top_videos           = Video_Session_Model::get_top_videos( $start_date_sql, $end_date_sql, 5, $storefront_id );
		$top_products         = Video_Product_Stats_Model::get_top_added_to_cart_products( $storefront_id );

		$response = array(
			'date_range'           => array(
				'type'       => $date_range,
				'start_date' => $dates['start_date_display'],
				'end_date'   => $dates['end_date_display'],
			),
			'total_views'          => $total_views,
			'unique_views'         => $unique_views,
			'total_likes'          => $total_likes,
			'unique_likes'         => $unique_likes,
			'total_view_time'      => $total_view_time,
			'avg_view_time'        => $avg_view_time,
			'total_add_to_cart'    => $total_add_to_cart,
			'total_views_products' => $total_views_products,
			'top_videos'           => $this->prepare_top_videos( $top_videos ),
			// array_filter drops deleted products (prepare_simple_product returns null for
			// them); array_values re-indexes so the JSON stays an array, not an object.
			'top_products'         => array_values(
				array_filter(
					array_map(
						function ( $product ) {
							$product_data = $this->woocommerce->prepare_simple_product( $product->product_id );
							if ( empty( $product_data ) ) {
								return null;
							}

							return array_merge(
								$product_data,
								array(
									'total_views'       => (int) $product->total_views,
									'total_add_to_cart' => (int) $product->total_add_to_cart,
								)
							);
						},
						$top_products
					)
				)
			),
		);

		// The per-day series is charted on the dashboard as well as the per-feed report, so it is
		// returned for every scope rather than only the storefront-scoped one.
		$response['timeseries'] = $this->build_timeseries( $start_date_sql, $end_date_sql, $storefront_id );

		// Library-wide counts for the summary strip. These are deliberately NOT range-scoped —
		// "how many videos do I have" is a property of the library, not of the reporting window.
		$response['totals'] = $this->get_library_totals();

		// The same aggregates over the immediately preceding window of equal length, so each stat
		// card can show a real change instead of a decorative arrow. All-time has no preceding
		// window, so it reports null and the cards hide their badges.
		$response['previous'] = $this->get_previous_period( $dates, $storefront_id );

		// Per-feed rollup for the feed-performance table. Only meaningful site-wide; a scoped
		// request is already reporting one feed.
		if ( null === $storefront_id ) {
			$response['per_storefront'] = $this->get_per_storefront( $start_date_sql, $end_date_sql );
		}

		// Storefront-scoped extra: the feed's identity, for the report header.
		if ( $storefront ) {
			$response['storefront'] = array(
				'id'        => (int) $storefront->get_key(),
				'name'      => $storefront->name,
				'shortcode' => $storefront->shortcode,
				'status'    => $storefront->status,
			);
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * Serialize the top-videos list for the dashboard table.
	 *
	 * The model returns full Video objects whose `total_views`/`total_likes` accessors are lifetime
	 * counts. Cart totals come from the per-video product-stats rows, and the click-through rate is
	 * derived rather than stored. All four are lifetime figures, which the UI labels as such — a
	 * range-scoped variant would need a different query per column and is not what this table is
	 * for.
	 *
	 * @param array $videos Video models, already ordered by views.
	 * @return array Rows of { id, title, thumbnail_url, total_views, total_likes, total_add_to_cart, ctr }.
	 */
	private function prepare_top_videos( $videos ) {
		return array_map(
			function ( $video ) {
				$video_id = (int) $video->get_key();
				$views    = (int) $video->total_views;
				$cart     = Video_Product_Stats_Model::get_add_to_cart_for_video( $video_id );

				return array(
					'id'                => $video_id,
					'title'             => $video->title,
					'thumbnail_url'     => $video->thumbnail_url,
					'total_views'       => $views,
					'total_likes'       => (int) $video->total_likes,
					'total_add_to_cart' => $cart,
					'ctr'               => $views > 0 ? round( ( $cart / $views ) * 100, 1 ) : 0.0,
				);
			},
			$videos
		);
	}

	/**
	 * Library-wide counts for the dashboard's summary strip.
	 *
	 * Deliberately NOT range-scoped — "how many videos do I have" is a property of the library,
	 * not of the reporting window.
	 *
	 * @return array { videos, storefronts, products_featured }.
	 */
	private function get_library_totals() {
		return array(
			'videos'            => Video_Model::count_published(),
			'storefronts'       => (int) Storefront_Model::query()->count(),
			'products_featured' => Video_Model::count_featured_products(),
		);
	}

	/**
	 * The headline aggregates for the window immediately before the requested one.
	 *
	 * Used for the "vs previous period" badges. An all-time request has nothing before it, so this
	 * returns null and the UI drops the badges rather than inventing a baseline.
	 *
	 * @param array    $dates         Resolved range from get_date_range().
	 * @param int|null $storefront_id Optional feed scope.
	 * @return array|null Same keys as the headline stats, or null when there is no prior window.
	 */
	private function get_previous_period( $dates, $storefront_id ) {
		if ( empty( $dates['start_date'] ) || empty( $dates['end_date'] ) ) {
			return null;
		}

		$start = strtotime( $dates['start_date'] );
		$end   = strtotime( $dates['end_date'] );

		if ( ! $start || ! $end || $end <= $start ) {
			return null;
		}

		$length         = $end - $start;
		$previous_end   = gmdate( 'Y-m-d H:i:s', $start - 1 );
		$previous_start = gmdate( 'Y-m-d H:i:s', $start - 1 - $length );

		return array(
			'total_views'          => Video_Session_Model::get_total_sessions( $previous_start, $previous_end, null, $storefront_id ),
			'unique_views'         => Video_Session_Model::get_unique_sessions( $previous_start, $previous_end, null, $storefront_id ),
			'total_likes'          => Video_Event_Model::get_total_likes( $previous_start, $previous_end, null, $storefront_id ),
			'total_view_time'      => Video_View_Time_Model::get_total_view_time( $previous_start, $previous_end, null, $storefront_id ),
			'avg_view_time'        => Video_View_Time_Model::get_average_view_time( $previous_start, $previous_end, null, $storefront_id ),
			'total_add_to_cart'    => Video_Product_Stats_Model::get_total_add_to_cart( $previous_start, $previous_end, $storefront_id ),
			'total_views_products' => Video_Product_Stats_Model::get_total_views( $previous_start, $previous_end, $storefront_id ),
		);
	}

	/**
	 * Per-feed aggregates for the feed-performance table.
	 *
	 * Three grouped model queries rather than one analytics call per feed — a store with twenty
	 * feeds would otherwise pay twenty round trips to render one table.
	 *
	 * @param string|null $start_date_sql Range start, or null for all time.
	 * @param string|null $end_date_sql   Range end, or null for all time.
	 * @return array Rows of { id, name, views, likes, add_to_cart }.
	 */
	private function get_per_storefront( $start_date_sql, $end_date_sql ) {
		$views = wp_list_pluck( Video_Session_Model::get_views_by_storefront( $start_date_sql, $end_date_sql ), 'total', 'storefront_id' );
		$likes = wp_list_pluck( Video_Event_Model::get_likes_by_storefront( $start_date_sql, $end_date_sql ), 'total', 'storefront_id' );
		$cart  = wp_list_pluck( Video_Product_Stats_Model::get_add_to_cart_by_storefront( $start_date_sql, $end_date_sql ), 'total', 'storefront_id' );

		$rows = array();

		foreach ( Storefront_Model::query()->order_by( 'created_at', 'desc' )->get() as $storefront ) {
			$id     = (int) $storefront->get_key();
			$rows[] = array(
				'id'          => $id,
				'name'        => $storefront->name,
				'views'       => isset( $views[ $id ] ) ? (int) $views[ $id ] : 0,
				'likes'       => isset( $likes[ $id ] ) ? (int) $likes[ $id ] : 0,
				'add_to_cart' => isset( $cart[ $id ] ) ? (int) $cart[ $id ] : 0,
			);
		}

		return $rows;
	}

	/**
	 * Build a zero-filled per-day series of views (sessions) and likes for a storefront.
	 *
	 * All-time requests have no bounds, so they chart the trailing 30 days. A hard cap of
	 * 366 points guards pathological custom ranges.
	 *
	 * @param string|null $start_date_sql Start datetime or null (all time).
	 * @param string|null $end_date_sql   End datetime or null (all time).
	 * @param int         $storefront_id  The storefront to scope to.
	 * @return array Items of { date: 'Y-m-d', views: int, likes: int }.
	 */
	private function build_timeseries( $start_date_sql, $end_date_sql, $storefront_id ) {
		if ( ! $start_date_sql || ! $end_date_sql ) {
			$now            = current_time( 'mysql' );
			$end_date_sql   = date( 'Y-m-d 23:59:59', strtotime( $now ) );
			$start_date_sql = date( 'Y-m-d 00:00:00', strtotime( '-29 days', strtotime( $now ) ) );
		}

		$views_rows = Video_Session_Model::get_daily_views( $start_date_sql, $end_date_sql, $storefront_id );
		$likes_rows = Video_Event_Model::get_daily_likes( $start_date_sql, $end_date_sql, $storefront_id );

		$views_by_day = wp_list_pluck( $views_rows, 'views', 'day' );
		$likes_by_day = wp_list_pluck( $likes_rows, 'likes', 'day' );

		$series = array();
		$cursor = strtotime( date( 'Y-m-d', strtotime( $start_date_sql ) ) );
		$end    = strtotime( date( 'Y-m-d', strtotime( $end_date_sql ) ) );

		for ( $i = 0; $cursor <= $end && $i < 366; $cursor = strtotime( '+1 day', $cursor ), $i++ ) {
			$day      = date( 'Y-m-d', $cursor );
			$series[] = array(
				'date'  => $day,
				'views' => isset( $views_by_day[ $day ] ) ? (int) $views_by_day[ $day ] : 0,
				'likes' => isset( $likes_by_day[ $day ] ) ? (int) $likes_by_day[ $day ] : 0,
			);
		}

		return $series;
	}

	/**
	 * Get date range based on the selected option
	 *
	 * @param string $date_range Date range option.
	 * @param string $start_date Custom start date.
	 * @param string $end_date   Custom end date.
	 * @return array|WP_Error
	 */
	private function get_date_range( $date_range, $start_date = null, $end_date = null ) {
		$now            = current_time( 'mysql' );
		$today          = date( 'Y-m-d', strtotime( $now ) );
		$start_date_sql = null;
		$end_date_sql   = null;

		switch ( $date_range ) {
			case 'this_week':
				/*
				 * `strtotime('sunday last week')` returns the *previous* Sunday when today is
				 * itself a Sunday, so the week ran to eight days and overlapped last week by seven
				 * of them — every Sunday the dashboard roughly doubled and snapped back on Monday.
				 * Anchoring off tomorrow makes "the most recent Sunday" mean today when today is
				 * Sunday.
				 */
				$start_date_sql = self::week_start( $today ) . ' 00:00:00';
				$end_date_sql   = date( 'Y-m-d 23:59:59', strtotime( $today ) );
				break;

			case 'last_week':
				$this_week_start = self::week_start( $today );
				$start_date_sql  = date( 'Y-m-d 00:00:00', strtotime( '-7 days', strtotime( $this_week_start ) ) );
				$end_date_sql    = date( 'Y-m-d 23:59:59', strtotime( '-1 day', strtotime( $this_week_start ) ) );
				break;

			case 'this_month':
				// Start of current month
				$start_date_sql = date( 'Y-m-01 00:00:00', strtotime( $now ) );
				$end_date_sql   = date( 'Y-m-d 23:59:59', strtotime( $today ) );
				break;

			case 'last_month':
				// Start of last month
				$start_date_sql = date( 'Y-m-01 00:00:00', strtotime( 'first day of last month', strtotime( $now ) ) );
				$end_date_sql   = date( 'Y-m-t 23:59:59', strtotime( 'last day of last month', strtotime( $now ) ) );
				break;

			case 'all_time':
				// All time - no date restrictions
				$start_date_sql = null;
				$end_date_sql   = null;
				break;

			case 'custom':
				// Custom date range
				if ( empty( $start_date ) || empty( $end_date ) ) {
					return new WP_Error(
						'missing_dates',
						__( 'Start date and end date are required for custom date range.', 'vidshop-for-woocommerce' ),
						array( 'status' => 400 )
					);
				}

				$start_date_sql = date( 'Y-m-d 00:00:00', strtotime( $start_date ) );
				$end_date_sql   = date( 'Y-m-d 23:59:59', strtotime( $end_date ) );
				break;
		}

		return array(
			'start_date'         => $start_date_sql,
			'end_date'           => $end_date_sql,
			'start_date_display' => $start_date_sql ? date( 'Y-m-d', strtotime( $start_date_sql ) ) : __( 'All Time', 'vidshop-for-woocommerce' ),
			'end_date_display'   => $end_date_sql ? date( 'Y-m-d', strtotime( $end_date_sql ) ) : __( 'All Time', 'vidshop-for-woocommerce' ),
		);
	}
}
