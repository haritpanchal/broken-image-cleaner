<?php
/**
 * Walks the site's content looking for images whose files are missing.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drives the scan, a batch at a time.
 *
 * Scanning is read-only: it records what it finds and changes nothing. Work is
 * done in small batches with the position held in an option, so an archive of
 * any size can be scanned without a long-running request, and a scan survives
 * being paused, resumed, or interrupted by a timeout.
 */
class Scanner {

	/**
	 * Option holding the scan's progress.
	 */
	const STATE_OPTION = 'broken_image_cleaner_scan_state';

	/**
	 * Option holding the post types to scan.
	 */
	const POST_TYPES_OPTION = 'broken_image_cleaner_post_types';

	/**
	 * Posts examined per batch.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Post statuses worth scanning.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array( 'publish', 'future', 'draft', 'pending', 'private' );
	}

	/**
	 * Post types the user has chosen to scan.
	 *
	 * @return array
	 */
	public static function post_types() {
		$configured = get_option( self::POST_TYPES_OPTION, array( 'post', 'page' ) );
		$configured = array_filter( (array) $configured, 'post_type_exists' );

		return ! empty( $configured ) ? array_values( $configured ) : array( 'post' );
	}

	/**
	 * Current scan state.
	 *
	 * @return array
	 */
	public static function state() {
		return wp_parse_args(
			(array) get_option( self::STATE_OPTION, array() ),
			array(
				'running'      => false,
				'last_post_id' => 0,
				'scanned'      => 0,
				'total'        => 0,
				'started_at'   => 0,
				'finished_at'  => 0,
			)
		);
	}

	/**
	 * Whether a scan is in progress.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$state = self::state();

		return ! empty( $state['running'] );
	}

	/**
	 * Begin a fresh scan.
	 *
	 * @return void
	 */
	public static function start() {
		update_option(
			self::STATE_OPTION,
			array(
				'running'      => true,
				'last_post_id' => 0,
				'scanned'      => 0,
				'total'        => self::count_candidates(),
				'started_at'   => time(),
				'finished_at'  => 0,
			),
			false
		);

		Cron::schedule_batch();
	}

	/**
	 * Stop a scan, keeping whatever it has found so far.
	 *
	 * @return void
	 */
	public static function stop() {
		$state                = self::state();
		$state['running']     = false;
		$state['finished_at'] = time();

		update_option( self::STATE_OPTION, $state, false );

		Cron::clear_batch();
	}

	/**
	 * Examine the next batch of posts.
	 *
	 * @return void
	 */
	public static function run_batch() {
		if ( ! self::is_running() ) {
			return;
		}

		$state = self::state();
		$posts = self::next_posts( $state['last_post_id'] );

		if ( empty( $posts ) ) {
			self::stop();

			return;
		}

		$resolver = new Resolver();

		foreach ( $posts as $post ) {
			self::scan_content( (int) $post->ID, $post->post_content, $resolver );

			$state['last_post_id'] = (int) $post->ID;
			++$state['scanned'];
		}

		update_option( self::STATE_OPTION, $state, false );

		Cron::schedule_batch();
	}

	/**
	 * Record the broken images in one piece of content.
	 *
	 * @param int      $post_id  Post ID.
	 * @param string   $content  Post content.
	 * @param Resolver $resolver Shared resolver.
	 * @return int Number of broken images found.
	 */
	public static function scan_content( $post_id, $content, Resolver $resolver = null ) {
		if ( null === $resolver ) {
			$resolver = new Resolver();
		}

		$still_broken = array();
		$references   = Extractor::extract( $content );

		// Gather every src first. A URL that is some other image's source has
		// to be treated as one even where it also turns up in a srcset.
		$src_urls = array();

		foreach ( $references as $reference ) {
			$src_urls[ $reference['src'] ] = true;
		}

		foreach ( $references as $reference ) {
			$snippet = self::snippet( $content, $reference['offset'], $reference['length'] );
			$check   = $resolver->check( $reference['src'] );

			if ( Resolver::STATUS_BROKEN === $check['status'] ) {
				Store::record( $post_id, $reference['src'], $check['path'], $check['reason'], $snippet );

				$still_broken[] = $reference['src'];
			}

			foreach ( $reference['srcset'] as $candidate ) {
				if ( isset( $src_urls[ $candidate ] ) ) {
					continue;
				}

				$check = $resolver->check( $candidate );

				if ( Resolver::STATUS_BROKEN !== $check['status'] ) {
					continue;
				}

				// Recorded for what will be done about it rather than for why
				// the file is gone: either way the repair is to drop it from
				// the srcset and leave the image alone.
				Store::record( $post_id, $candidate, $check['path'], Store::REASON_SRCSET_MISSING, $snippet );

				$still_broken[] = $candidate;
			}
		}

		// Drop findings for images that have since been put back.
		Store::prune_post( $post_id, $still_broken );

		return count( $still_broken );
	}

	/**
	 * Re-check a single post and refresh its findings.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of broken images found.
	 */
	public static function rescan_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		return self::scan_content( (int) $post->ID, $post->post_content );
	}

	/**
	 * A short piece of surrounding content, for context in the review table.
	 *
	 * @param string $content Post content.
	 * @param int    $offset  Image tag offset.
	 * @param int    $length  Image tag length.
	 * @return string
	 */
	private static function snippet( $content, $offset, $length ) {
		$start   = max( 0, $offset - 60 );
		$snippet = substr( $content, $start, $length + 120 );

		return wp_html_excerpt( $snippet, 200, '…' );
	}

	/**
	 * Fetch the next batch of candidate posts.
	 *
	 * @param int $after_id Highest post ID already scanned.
	 * @return array
	 */
	private static function next_posts( $after_id ) {
		global $wpdb;

		$types    = self::post_types();
		$statuses = self::statuses();

		$type_placeholders   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$like                = '%' . $wpdb->esc_like( '<img' ) . '%';

		$params = array_merge( array( (int) $after_id ), $types, $statuses, array( $like, self::BATCH_SIZE ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the placeholder lists are interpolated, and they are built in code from the counts above; every value is passed to prepare(). Selecting posts by a substring of post_content has no core API equivalent, and caching one forward-only pass over the archive would serve no one.
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				WHERE ID > %d
				AND post_type IN ( {$type_placeholders} )
				AND post_status IN ( {$status_placeholders} )
				AND post_content LIKE %s
				ORDER BY ID ASC
				LIMIT %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $posts;
	}

	/**
	 * How many posts the scan expects to look at.
	 *
	 * @return int
	 */
	private static function count_candidates() {
		global $wpdb;

		$types    = self::post_types();
		$statuses = self::statuses();

		$type_placeholders   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$like                = '%' . $wpdb->esc_like( '<img' ) . '%';

		$params = array_merge( $types, $statuses, array( $like ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above: placeholder lists are built in code, every value is prepared, and this count runs once when a scan starts.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type IN ( {$type_placeholders} )
				AND post_status IN ( {$status_placeholders} )
				AND post_content LIKE %s",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $total;
	}
}
