<?php
/**
 * Scheduled work: scan batches and snapshot pruning.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's background tasks to WP-Cron.
 *
 * Scan batches are scheduled one at a time: each batch queues the next only if
 * there is more to do. A recurring event would keep firing on a finished scan.
 */
class Cron {

	/**
	 * Hook that runs one batch of the scan.
	 */
	const BATCH_HOOK = 'broken_image_cleaner_scan_batch';

	/**
	 * Hook that clears out expired undo snapshots.
	 */
	const PRUNE_HOOK = 'broken_image_cleaner_prune_backups';

	/**
	 * Register the hook callbacks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::BATCH_HOOK, array( Scanner::class, 'run_batch' ) );
		add_action( self::PRUNE_HOOK, array( Backup::class, 'prune' ) );

		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Queue the next scan batch.
	 *
	 * @return void
	 */
	public static function schedule_batch() {
		if ( wp_next_scheduled( self::BATCH_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::BATCH_HOOK );
	}

	/**
	 * Cancel any queued scan batch.
	 *
	 * @return void
	 */
	public static function clear_batch() {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
	}

	/**
	 * Cancel every scheduled task. Used on deactivation.
	 *
	 * @return void
	 */
	public static function clear_all() {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}
}
