<?php
/**
 * Applies removals to post content.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a set of reviewed findings into edits.
 *
 * This is the only class in the plugin that writes to post content, and it is
 * guarded accordingly: findings are grouped so each post is written once, the
 * user's permission is checked per post, and every image is re-checked on disk
 * immediately before the edit so a stale queue row cannot delete an image that
 * has since been put back.
 */
class Remover {

	/**
	 * Most findings that may be applied in a single request.
	 */
	const MAX_PER_REQUEST = 200;

	/**
	 * Apply removals for the given findings.
	 *
	 * @param array $finding_ids Finding IDs to act on.
	 * @param bool  $dry_run     When true, work out the result without saving.
	 * @return array {
	 *     @type int   $posts_changed  Posts that were edited.
	 *     @type int   $images_removed Image references removed.
	 *     @type int   $skipped        Findings left alone.
	 *     @type array $errors         Human-readable messages.
	 * }
	 */
	public static function remove( array $finding_ids, $dry_run = false ) {
		$report = array(
			'posts_changed'  => 0,
			'images_removed' => 0,
			'skipped'        => 0,
			'errors'         => array(),
		);

		$finding_ids = array_slice( array_filter( array_map( 'absint', $finding_ids ) ), 0, self::MAX_PER_REQUEST );

		if ( empty( $finding_ids ) ) {
			return $report;
		}

		// Content is saved verbatim, so the caller must be allowed to post
		// unfiltered HTML. Without it wp_update_post would run the whole post
		// through kses and strip markup that has nothing to do with the image.
		if ( ! $dry_run && ! current_user_can( 'unfiltered_html' ) ) {
			$report['errors'][] = __( 'Your account is not allowed to save unfiltered HTML, so post content cannot be edited safely. Ask an administrator to run the clean-up.', 'broken-image-cleaner' );
			$report['skipped']  = count( $finding_ids );

			return $report;
		}

		$by_post = array();

		foreach ( Store::get_many( $finding_ids ) as $finding ) {
			$by_post[ (int) $finding->post_id ][] = $finding;
		}

		$resolver = new Resolver();

		foreach ( $by_post as $post_id => $findings ) {
			$result = self::process_post( $post_id, $findings, $resolver, $dry_run );

			$report['images_removed'] += $result['images_removed'];
			$report['skipped']        += $result['skipped'];
			$report['errors']          = array_merge( $report['errors'], $result['errors'] );

			if ( $result['images_removed'] > 0 ) {
				++$report['posts_changed'];
			}
		}

		return $report;
	}

	/**
	 * Apply every selected removal for one post in a single edit.
	 *
	 * @param int      $post_id  Post ID.
	 * @param array    $findings Findings belonging to this post.
	 * @param Resolver $resolver Shared resolver.
	 * @param bool     $dry_run  Whether to skip saving.
	 * @return array
	 */
	private static function process_post( $post_id, array $findings, Resolver $resolver, $dry_run ) {
		$result = array(
			'images_removed' => 0,
			'skipped'        => 0,
			'errors'         => array(),
		);

		$post = get_post( $post_id );

		if ( ! $post ) {
			$result['skipped'] += count( $findings );
			$result['errors'][] = sprintf(
				/* translators: %d: post ID. */
				__( 'Post %d no longer exists.', 'broken-image-cleaner' ),
				$post_id
			);

			return $result;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			$result['skipped'] += count( $findings );
			$result['errors'][] = sprintf(
				/* translators: %s: post title. */
				__( 'You are not allowed to edit "%s".', 'broken-image-cleaner' ),
				get_the_title( $post_id )
			);

			return $result;
		}

		$original = $post->post_content;
		$content  = $original;
		$applied  = array();

		foreach ( $findings as $finding ) {
			// The queue may be minutes or weeks old. Confirm the file is still
			// missing before anything is deleted on the strength of it.
			$check = $resolver->check( $finding->image_url );

			if ( Resolver::STATUS_BROKEN !== $check['status'] ) {
				++$result['skipped'];

				if ( ! $dry_run ) {
					Store::set_status( $finding->id, Store::STATUS_RESTORED );
				}

				continue;
			}

			$outcome = Rewriter::remove_image( $content, $finding->image_url );

			if ( 0 === $outcome['removed'] ) {
				++$result['skipped'];
				continue;
			}

			$content                   = $outcome['content'];
			$result['images_removed'] += $outcome['removed'];
			$applied[]                 = (int) $finding->id;
		}

		if ( $content === $original || $dry_run ) {
			return $result;
		}

		Backup::save( $post_id, $original, $applied );

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			$result['errors'][]       = $updated->get_error_message();
			$result['skipped']       += count( $applied );
			$result['images_removed'] = 0;

			return $result;
		}

		foreach ( $applied as $finding_id ) {
			Store::set_status( $finding_id, Store::STATUS_REMOVED );
		}

		return $result;
	}
}
