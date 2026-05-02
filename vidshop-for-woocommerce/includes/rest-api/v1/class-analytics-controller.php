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
use VSFW\Interfaces\WooCommerce;
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
					),
				),
			)
		);
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

		// Get analytics data using model methods
		$total_views          = Video_Session_Model::get_total_sessions( $start_date_sql, $end_date_sql );
		$unique_views         = Video_Session_Model::get_unique_sessions( $start_date_sql, $end_date_sql );
		$total_likes          = Video_Event_Model::get_total_likes( $start_date_sql, $end_date_sql );
		$unique_likes         = Video_Event_Model::get_unique_likes( $start_date_sql, $end_date_sql );
		$total_view_time      = Video_View_Time_Model::get_total_view_time( $start_date_sql, $end_date_sql );
		$avg_view_time        = Video_View_Time_Model::get_average_view_time( $start_date_sql, $end_date_sql );
		$total_add_to_cart    = Video_Product_Stats_Model::get_total_add_to_cart( $start_date_sql, $end_date_sql );
		$total_views_products = Video_Product_Stats_Model::get_total_views( $start_date_sql, $end_date_sql );
		$top_videos           = Video_Session_Model::get_top_videos( $start_date_sql, $end_date_sql, 5 );
		$top_products         = Video_Product_Stats_Model::get_top_added_to_cart_products();

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
			'top_videos'           => $top_videos,
			'top_products'         => array_map(
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
			),
		);

		return new WP_REST_Response( $response );
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
				// Start of current week (WordPress starts week on Sunday)
				$start_date_sql = date( 'Y-m-d 00:00:00', strtotime( 'sunday last week', strtotime( $now ) ) );
				$end_date_sql   = date( 'Y-m-d 23:59:59', strtotime( $today ) );
				break;

			case 'last_week':
				// Start of last week
				$start_date_sql = date( 'Y-m-d 00:00:00', strtotime( 'sunday -2 weeks', strtotime( $now ) ) );
				$end_date_sql   = date( 'Y-m-d 23:59:59', strtotime( 'saturday -1 week', strtotime( $now ) ) );
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
