<?php
/**
 * AI reconciler.
 *
 * The single routine that polls the cloud for in-flight generations and imports finished ones
 * into local `vsfw_videos` drafts. Shared by the browser poll (`GET /vsfw/v1/ai/generations`) and
 * the `vsfw_ai_reconcile` wp-cron. Designed so the two drivers can never duplicate work:
 *
 *   1. Single pass at a time  — a MySQL named lock (GET_LOCK, non-blocking).
 *   2. Per-row throttle        — only poll rows not checked within THROTTLE_SECONDS.
 *   3. Atomic import claim      — a conditional UPDATE; only the winner imports.
 *   4. Idempotent at the table  — UNIQUE(ai_call_id) refuses a second import.
 *
 * @package vidshop-for-woocommerce
 */

namespace VSFW\Services;

use VSFW\Models\Video_Model;

/**
 * AI reconciler.
 */
class Ai_Reconciler {

	/** wp-cron hook fired on the recurring schedule. */
	const CRON_HOOK = 'vsfw_ai_reconcile';

	/** Custom cron schedule key (registered via the cron_schedules filter). */
	const CRON_INTERVAL = 'vsfw_minute';

	/** MySQL advisory lock name (serializes a reconcile pass across tabs + cron). */
	const LOCK_NAME = 'vsfw_ai_reconcile';

	/** Don't re-poll a row checked within this many seconds. */
	const THROTTLE_SECONDS = 8;

	/** A row stuck in 'importing' longer than this is treated as a crashed import and retried. */
	const IMPORTING_STALE_SECONDS = 120;

	/** Max rows to poll per pass (bounds the cloud calls). */
	const BATCH = 25;

	/** Option storing dismissed generation ids — server-side, so a dismissal survives a localStorage clear. */
	const DISMISSED_OPTION = 'vsfw_ai_dismissed';

	/**
	 * Cloud connection (token + cache).
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
	 * Full table name for the generations table.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vsfw_ai_generations';
	}

	/**
	 * Run one reconcile pass: poll in-flight generations, import finished ones.
	 *
	 * @return void
	 */
	public function reconcile() {
		global $wpdb;
		$table = $this->table();

		// Guard #1 — single pass at a time (non-blocking try-lock).
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK_NAME ) );
		if ( '1' !== $got ) {
			return;
		}

		try {
			// Recover rows stuck mid-import (crashed pass) so they retry.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'processing' WHERE status = 'importing' AND updated_at < ( UTC_TIMESTAMP() - INTERVAL %d SECOND )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::IMPORTING_STALE_SECONDS
				)
			);

			$token = $this->connection->get_token();
			if ( ! $token ) {
				return;
			}

			// Guard #2 — only poll rows due for a check.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status IN ('pending','processing') AND ( checked_at IS NULL OR checked_at < ( UTC_TIMESTAMP() - INTERVAL %d SECOND ) ) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::THROTTLE_SECONDS,
					self::BATCH
				)
			);

			$imported = 0;

			foreach ( (array) $rows as $row ) {
				$video = $this->client->get_video( $token, (int) $row->ai_call_id );

				if ( is_wp_error( $video ) ) {
					// Revoked token → stop the whole pass (reconnect needed). Other errors → throttle + move on.
					if ( 401 === (int) ( $video->get_error_data()['status'] ?? 0 ) ) {
						break;
					}
					$this->touch( (int) $row->ai_call_id );
					continue;
				}

				$status = isset( $video['status'] ) ? $video['status'] : 'processing';

				if ( 'succeeded' === $status ) {
					// Guard #3 — atomic claim; only the winner imports.
					$claimed = $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table} SET status = 'importing', updated_at = UTC_TIMESTAMP() WHERE ai_call_id = %d AND status IN ('pending','processing')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							(int) $row->ai_call_id
						)
					);
					if ( 1 !== (int) $claimed ) {
						continue; // another pass took it
					}

					$model = $this->import_generation( $video, $row );
					if ( is_wp_error( $model ) ) {
						// Import failed — back to 'processing' to retry next pass; note the reason.
						$wpdb->update(
							$table,
							array(
								'status'     => 'processing',
								'error'      => $model->get_error_message(),
								'checked_at' => gmdate( 'Y-m-d H:i:s' ),
							),
							array( 'ai_call_id' => (int) $row->ai_call_id ),
							array( '%s', '%s', '%s' ),
							array( '%d' )
						);
						continue;
					}

					$wpdb->update(
						$table,
						array(
							'status'     => 'imported',
							'video_id'   => (int) $model->get_key(),
							'checked_at' => gmdate( 'Y-m-d H:i:s' ),
						),
						array( 'ai_call_id' => (int) $row->ai_call_id ),
						array( '%s', '%d', '%s' ),
						array( '%d' )
					);
					++$imported;

					// Notify add-ons now that the row is safely imported — guarded so a misbehaving hook
					// (e.g. one expecting a REST request) can't abort the pass or trigger a re-import.
					try {
						do_action( 'vsfw_video_saved', $model, null, 'ai_import' );
					} catch ( \Throwable $e ) {
						// A misbehaving add-on hook must not abort the import pass; the row is already imported, so swallow.
					}
				} elseif ( 'failed' === $status ) {
					// Mirror the cloud's typed failure code + merchant-safe reason onto the local row so the
					// banner can branch the message (firm "content_blocked_all_models" vs soft "content_blocked"
					// vs neutral "temporarily_unavailable"). `error` keeps the human-readable reason for the
					// list view; the generic fallback is only used when the cloud didn't send one.
					$failure_code   = isset( $video['failure_code'] ) && is_string( $video['failure_code'] ) ? $video['failure_code'] : null;
					$failure_reason = isset( $video['failure_reason'] ) && is_string( $video['failure_reason'] ) ? $video['failure_reason'] : null;
					$display_error  = $failure_reason ? $failure_reason : __( 'Generation failed on the cloud. You can try again for free.', 'vidshop-for-woocommerce' );

					$wpdb->update(
						$table,
						array(
							'status'         => 'failed',
							'error'          => $display_error,
							'failure_code'   => $failure_code,
							'failure_reason' => $failure_reason,
							'checked_at'     => gmdate( 'Y-m-d H:i:s' ),
						),
						array( 'ai_call_id' => (int) $row->ai_call_id ),
						array( '%s', '%s', '%s', '%s', '%s' ),
						array( '%d' )
					);
				} else {
					// Still pending/processing — record the latest status + throttle stamp.
					$wpdb->update(
						$table,
						array(
							'status'     => ( 'processing' === $status ) ? 'processing' : 'pending',
							'checked_at' => gmdate( 'Y-m-d H:i:s' ),
						),
						array( 'ai_call_id' => (int) $row->ai_call_id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
				}
			}

			if ( $imported > 0 ) {
				$this->connection->flush_usage_cache();
			}

			// Nothing left in flight → stop the cron until the next generation re-arms it.
			$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','processing','importing')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 0 === $remaining ) {
				self::unschedule();
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
		}
	}

	/**
	 * Stamp a row's checked_at to throttle the next poll (used on transient errors).
	 *
	 * @param int $ai_call_id Cloud video id.
	 * @return void
	 */
	private function touch( $ai_call_id ) {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array( 'checked_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'ai_call_id' => (int) $ai_call_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Import a succeeded generation into a draft `vsfw_videos` row, linked to its product.
	 *
	 * @param array  $video Cloud VideoResponse (video_url, thumbnail_url, market, template_slug, ...).
	 * @param object $row   The local generation row.
	 * @return \VSFW\Models\Video_Model|\WP_Error The created video model, or WP_Error on failure.
	 */
	private function import_generation( $video, $row ) {
		$video_url = isset( $video['video_url'] ) ? $video['video_url'] : '';
		if ( ! $video_url ) {
			return new \WP_Error( 'no_video_url', 'Succeeded generation had no video URL.' );
		}

		$ai_call_id = (int) $row->ai_call_id;

		// Idempotency: one local video per cloud generation. If a draft already exists for this
		// ai_call_id, reuse it instead of creating a duplicate — covers every re-import path (a crashed
		// pass, a hook error, the stale-importing reset). Backed by the UNIQUE(ai_call_id) column.
		if ( $ai_call_id > 0 ) {
			$existing = Video_Model::query()->where( 'ai_call_id', '=', $ai_call_id )->first();
			if ( $existing ) {
				return $existing;
			}
		}

		$product_id = (int) $row->product_id;
		$title      = $row->title ? $row->title : __( 'AI video', 'vidshop-for-woocommerce' );

		$thumbnail_id = $this->resolve_thumbnail_id(
			isset( $video['thumbnail_url'] ) ? $video['thumbnail_url'] : '',
			$title,
			$product_id
		);

		$model = Video_Model::create_without_validation(
			array(
				'title'        => $title,
				'type'         => 'custom',
				'source_url'   => $video_url,
				'thumbnail_id' => $thumbnail_id,
				'video_id'     => null,
				'ai_call_id'   => $ai_call_id,
				'origin'       => 'wpcreatix_ai',
				'status'       => 'draft',
				'created_by'   => $row->created_by ? (int) $row->created_by : get_current_user_id(),
				'settings'     => array(
					'ai_call_id'    => (int) $row->ai_call_id,
					'template_slug' => isset( $video['template_slug'] ) ? $video['template_slug'] : null,
					'market'        => isset( $video['market'] ) ? $video['market'] : null,
					'duration_s'    => isset( $video['duration_s'] ) ? (int) $video['duration_s'] : null,
				),
			)
		);

		if ( ! $model || ! $model->get_key() ) {
			// Surface the underlying DB error (e.g. a missing column) so a failed import is diagnosable
			// rather than a generic message — it surfaces on the generation row's `error` field.
			global $wpdb;
			$detail = $wpdb->last_error ? ' (' . $wpdb->last_error . ')' : '';

			return new \WP_Error( 'video_create_failed', 'Could not create the video row.' . $detail );
		}

		if ( $product_id ) {
			$model->products()->sync( array( $product_id ) );
		}

		// The `vsfw_video_saved` hook is fired by the CALLER (reconcile) AFTER the generation row is
		// marked 'imported' — so a misbehaving add-on hook can't leave the row stuck mid-import and
		// trigger a re-import (which duplicated drafts). Return the model for the caller to pass along.
		return $model;
	}

	/**
	 * Resolve a thumbnail attachment id: sideload the cloud thumbnail, else fall back to the
	 * product's featured image. Returns 0 when neither is available.
	 *
	 * @param string $thumb_url  Cloud thumbnail URL (may be empty).
	 * @param string $title      Title for the sideloaded attachment.
	 * @param int    $product_id Product id (for the fallback image).
	 * @return int Attachment id, or 0.
	 */
	private function resolve_thumbnail_id( $thumb_url, $title, $product_id ) {
		if ( $thumb_url ) {
			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$sideloaded = media_sideload_image( $thumb_url, 0, $title, 'id' );
			if ( ! is_wp_error( $sideloaded ) && $sideloaded ) {
				return (int) $sideloaded;
			}
		}

		return $product_id ? (int) get_post_thumbnail_id( $product_id ) : 0;
	}

	/**
	 * Snapshot for the UI: in-flight generations + recent imported ones.
	 *
	 * @return array { in_progress: array, recent: array, in_progress_count: int }
	 */
	public function get_snapshot() {
		global $wpdb;
		$table = $this->table();

		$in_progress = $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('pending','processing','importing') ORDER BY id DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent      = $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('imported','failed') ORDER BY id DESC LIMIT 12" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Drop dismissed rows from `recent` (dismissal is stored server-side, so it survives a
		// localStorage clear or a different browser). Prune the stored list to ids still in the
		// window so the option can't grow unbounded.
		$dismissed  = $this->get_dismissed();
		$recent_ids = array();
		$visible    = array();
		foreach ( (array) $recent as $row ) {
			$recent_ids[] = (int) $row->id;
			if ( ! in_array( (int) $row->id, $dismissed, true ) ) {
				$visible[] = $row;
			}
		}
		$pruned = array_values( array_intersect( $dismissed, $recent_ids ) );
		if ( count( $pruned ) !== count( $dismissed ) ) {
			update_option( self::DISMISSED_OPTION, $pruned, false );
		}

		return array(
			'in_progress'       => array_map( array( $this, 'map_row' ), (array) $in_progress ),
			'recent'            => array_map( array( $this, 'map_row' ), $visible ),
			'in_progress_count' => count( (array) $in_progress ),
		);
	}

	/**
	 * Read the dismissed-generation ids (ints) from the persistent option.
	 *
	 * @return int[]
	 */
	private function get_dismissed() {
		$v = get_option( self::DISMISSED_OPTION, array() );
		return is_array( $v ) ? array_map( 'intval', $v ) : array();
	}

	/**
	 * Mark a generation row dismissed so it no longer appears in the banner's recent list. Persisted
	 * server-side (a WP option) so the dismissal holds across browsers + a cleared localStorage.
	 *
	 * @param int $id Local generation row id.
	 * @return void
	 */
	public function dismiss( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return;
		}
		$dismissed = $this->get_dismissed();
		if ( ! in_array( $id, $dismissed, true ) ) {
			$dismissed[] = $id;
			update_option( self::DISMISSED_OPTION, $dismissed, false );
		}
	}

	/**
	 * Map a DB row to the API shape.
	 *
	 * @param object $row Generation row.
	 * @return array
	 */
	private function map_row( $row ) {
		return array(
			'id'                => (int) $row->id,
			'ai_call_id'        => (int) $row->ai_call_id,
			'product_id'        => (int) $row->product_id,
			'title'             => $row->title,
			'status'            => $row->status,
			'video_id'          => $row->video_id ? (int) $row->video_id : null,
			'duration_s'        => isset( $row->duration_s ) ? (int) $row->duration_s : null,
			'estimated_seconds' => isset( $row->estimated_seconds ) ? (int) $row->estimated_seconds : null,
			'error'             => $row->error,
			// Typed failure code from the cloud — lets the banner branch on "blocked by every model"
			// vs "blocked by one model" vs neutral our-side issues. `isset()` keeps the snapshot safe
			// on installs that hadn't yet run the 1.4.0 migration when this row was written.
			'failure_code'      => isset( $row->failure_code ) ? $row->failure_code : null,
			'failure_reason'    => isset( $row->failure_reason ) ? $row->failure_reason : null,
			'created_at'        => $row->created_at,
		);
	}

	/**
	 * Ensure the reconcile cron is scheduled (called when a generation starts).
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Clear the reconcile cron (called when nothing is in flight).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Register the custom 1-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_cron_interval( $schedules ) {
		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (VidShop AI)', 'vidshop-for-woocommerce' ),
		);
		return $schedules;
	}
}
