<?php
/**
 * Persistence for scan findings.
 *
 * Every query below runs against this plugin's own table, for which no core API
 * exists, and each one is passed through $wpdb->prepare(). Two things in these
 * queries cannot be parameterised and are therefore built in code instead: the
 * table name, which comes from $wpdb->prefix and a class constant, and the lists
 * of %s / %d placeholders for IN clauses, whose length depends on how many values
 * were passed. No caller-supplied value is ever interpolated. The sniffs that
 * police interpolation cannot see that distinction, so they are turned off for
 * this file rather than repeated on every statement.
 *
 * The same is true of the WHERE clauses, which are assembled with their own %s
 * placeholders before being interpolated into the query and handed to prepare().
 * A static analyser sees "a variable in the query" and cannot tell that the
 * variable holds only placeholders, so UnescapedDBParameter is turned off here
 * too; the values those placeholders stand for are always passed to prepare().
 *
 * These reads are also deliberately uncached. The table is written on every
 * batch of a scan and on every clean-up, so a cache would be invalidated as
 * fast as it was filled, and the rows are only read on an admin screen that is
 * already paginated.
 *
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the findings table.
 *
 * Findings are keyed by post and image URL, so re-scanning a post updates the
 * existing rows rather than accumulating duplicates.
 */
class Store {

	/**
	 * Table name, without the site prefix.
	 */
	const TABLE = 'broken_image_cleaner_findings';

	/**
	 * Schema version, bumped whenever the table definition changes.
	 */
	const DB_VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'broken_image_cleaner_db_version';

	/**
	 * Finding statuses.
	 */
	const STATUS_BROKEN   = 'broken';
	const STATUS_IGNORED  = 'ignored';
	const STATUS_REMOVED  = 'removed';
	const STATUS_RESTORED = 'restored';

	/**
	 * Reasons a finding was recorded.
	 */
	const REASON_FILE_MISSING = 'file_missing';
	const REASON_ZERO_BYTE    = 'zero_byte';
	const REASON_SIZE_MISSING = 'size_missing';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade the findings table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		// The URL is hashed into its own column because TEXT columns cannot be
		// indexed without a prefix length, and the (post, URL) pair must be unique.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			image_url text NOT NULL,
			url_hash char(32) NOT NULL,
			resolved_path text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'broken',
			reason varchar(20) NOT NULL DEFAULT '',
			context_snippet text NOT NULL,
			first_seen datetime NOT NULL,
			last_checked datetime NOT NULL,
			removed_at datetime DEFAULT NULL,
			removed_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_url (post_id,url_hash),
			KEY status (status)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Permanently remove the findings table.
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$table = self::table_name();

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Insert a finding, or refresh it if this post/URL pair is already recorded.
	 *
	 * An existing row keeps its status so that a re-scan does not resurrect
	 * something the user has already ignored or removed.
	 *
	 * @param int    $post_id Post the image appears in.
	 * @param string $url     Image URL as written in the content.
	 * @param string $path    Resolved filesystem path, if any.
	 * @param string $reason  Why the image was flagged.
	 * @param string $snippet Surrounding content, for context in the review table.
	 * @return void
	 */
	public static function record( $post_id, $url, $path, $reason, $snippet ) {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$hash  = md5( $url );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE post_id = %d AND url_hash = %s",
				$post_id,
				$hash
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				array(
					'resolved_path'   => $path,
					'reason'          => $reason,
					'context_snippet' => $snippet,
					'last_checked'    => $now,
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return;
		}

		$wpdb->insert(
			$table,
			array(
				'post_id'         => $post_id,
				'image_url'       => $url,
				'url_hash'        => $hash,
				'resolved_path'   => $path,
				'status'          => self::STATUS_BROKEN,
				'reason'          => $reason,
				'context_snippet' => $snippet,
				'first_seen'      => $now,
				'last_checked'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Delete findings for a post that are no longer broken.
	 *
	 * Called after re-scanning a post so that images which have since been
	 * restored stop appearing in the review table.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $keep_urls Image URLs that are still broken.
	 * @return void
	 */
	public static function prune_post( $post_id, array $keep_urls ) {
		global $wpdb;

		$table  = self::table_name();
		$hashes = array_map( 'md5', $keep_urls );

		if ( empty( $hashes ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE post_id = %d AND status = %s",
					$post_id,
					self::STATUS_BROKEN
				)
			);

			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		$params       = array_merge( array( $post_id, self::STATUS_BROKEN ), $hashes );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND status = %s AND url_hash NOT IN ( {$placeholders} )",
				$params
			)
		);
	}

	/**
	 * Fetch a single finding.
	 *
	 * @param int $id Finding ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			)
		);
	}

	/**
	 * Fetch several findings by ID.
	 *
	 * @param array $ids Finding IDs.
	 * @return array
	 */
	public static function get_many( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id IN ( {$placeholders} ) ORDER BY post_id ASC",
				$ids
			)
		);
	}

	/**
	 * Query findings for the review table.
	 *
	 * @param array $args Supported keys: status, reasons, per_page, paged.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => self::STATUS_BROKEN,
				'reasons'  => array(),
				'per_page' => 20,
				'paged'    => 1,
			)
		);

		$table  = self::table_name();
		$where  = 'WHERE status = %s';
		$params = array( $args['status'] );

		if ( ! empty( $args['reasons'] ) ) {
			$reasons      = (array) $args['reasons'];
			$placeholders = implode( ', ', array_fill( 0, count( $reasons ), '%s' ) );
			$where       .= " AND reason IN ( {$placeholders} )";
			$params       = array_merge( $params, array_values( $reasons ) );
		}

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY post_id ASC, id ASC LIMIT %d OFFSET %d",
				$params
			)
		);
	}

	/**
	 * Count findings matching a status and optional set of reasons.
	 *
	 * @param string $status  Status to count.
	 * @param array  $reasons Optional list of reasons to restrict the count to.
	 * @return int
	 */
	public static function count( $status = self::STATUS_BROKEN, array $reasons = array() ) {
		global $wpdb;

		$table  = self::table_name();
		$where  = 'WHERE status = %s';
		$params = array( $status );

		if ( ! empty( $reasons ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $reasons ), '%s' ) );
			$where       .= " AND reason IN ( {$placeholders} )";
			$params       = array_merge( $params, array_values( $reasons ) );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} {$where}",
				$params
			)
		);
	}

	/**
	 * Change a finding's status.
	 *
	 * @param int    $id     Finding ID.
	 * @param string $status New status.
	 * @return void
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		$data   = array( 'status' => $status );
		$format = array( '%s' );

		if ( self::STATUS_REMOVED === $status ) {
			$data['removed_at'] = current_time( 'mysql', true );
			$data['removed_by'] = get_current_user_id();
			$format[]           = '%s';
			$format[]           = '%d';
		}

		$wpdb->update( self::table_name(), $data, array( 'id' => (int) $id ), $format, array( '%d' ) );
	}

	/**
	 * Change the status of every finding attached to a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $from    Status to match.
	 * @param string $to      Status to apply.
	 * @return void
	 */
	public static function set_status_for_post( $post_id, $from, $to ) {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array( 'status' => $to ),
			array(
				'post_id' => (int) $post_id,
				'status'  => $from,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Delete every finding.
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;

		$table = self::table_name();

		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
