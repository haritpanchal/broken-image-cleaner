<?php
/**
 * Locates image references inside post content.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds `<img>` tags in a block of content and reports where they are.
 *
 * Two things are needed from every image: its attributes, and the exact
 * position of its tag in the original string. Attributes are read by handing
 * the isolated tag to DOMDocument, which copes with the malformed markup that
 * legacy content is full of. Positions come from the pattern match, because
 * serialising a parsed document back to HTML would rewrite the whole post.
 */
class Extractor {

	/**
	 * Matches an `<img>` tag, including one written as `<img ... />`.
	 */
	const IMG_PATTERN = '#<img\s[^>]*?/?>#is';

	/**
	 * Find every image reference in a piece of content.
	 *
	 * @param string $content Post content.
	 * @return array List of references: raw, offset, length, src, srcset.
	 */
	public static function extract( $content ) {
		if ( '' === trim( (string) $content ) || false === stripos( $content, '<img' ) ) {
			return array();
		}

		$comment_ranges = self::comment_ranges( $content );
		$matches        = array();

		if ( ! preg_match_all( self::IMG_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$references = array();

		foreach ( $matches[0] as $match ) {
			$raw    = $match[0];
			$offset = $match[1];

			// An image inside an HTML comment is not rendered, so leave it alone.
			if ( self::is_within( $offset, $comment_ranges ) ) {
				continue;
			}

			$attributes = self::parse_attributes( $raw );

			if ( empty( $attributes['src'] ) ) {
				continue;
			}

			$references[] = array(
				'raw'    => $raw,
				'offset' => $offset,
				'length' => strlen( $raw ),
				'src'    => $attributes['src'],
				'srcset' => $attributes['srcset'],
			);
		}

		return $references;
	}

	/**
	 * Collect the distinct image URLs in a piece of content.
	 *
	 * @param string $content         Post content.
	 * @param bool   $include_srcset  Whether to include srcset candidates.
	 * @return array
	 */
	public static function urls( $content, $include_srcset = true ) {
		$urls = array();

		foreach ( self::extract( $content ) as $reference ) {
			$urls[] = $reference['src'];

			if ( $include_srcset ) {
				$urls = array_merge( $urls, $reference['srcset'] );
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Read the src and srcset of a single, isolated image tag.
	 *
	 * @param string $tag One `<img>` tag.
	 * @return array {
	 *     @type string $src    Source URL, empty when absent.
	 *     @type array  $srcset Candidate URLs from the srcset attribute.
	 * }
	 */
	public static function parse_attributes( $tag ) {
		$result = array(
			'src'    => '',
			'srcset' => array(),
		);

		$previous = libxml_use_internal_errors( true );

		$document = new \DOMDocument();
		$document->loadHTML(
			'<?xml encoding="UTF-8"?><body>' . $tag . '</body>',
			LIBXML_NOWARNING | LIBXML_NOERROR
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$nodes = $document->getElementsByTagName( 'img' );

		if ( 0 === $nodes->length ) {
			return $result;
		}

		$node = $nodes->item( 0 );

		$result['src']    = trim( (string) $node->getAttribute( 'src' ) );
		$result['srcset'] = self::parse_srcset( (string) $node->getAttribute( 'srcset' ) );

		return $result;
	}

	/**
	 * Split a srcset attribute into its candidate URLs.
	 *
	 * @param string $srcset Raw srcset attribute value.
	 * @return array
	 */
	public static function parse_srcset( $srcset ) {
		if ( '' === trim( $srcset ) ) {
			return array();
		}

		$urls = array();

		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( '' === $candidate ) {
				continue;
			}

			// A candidate is "URL" optionally followed by a width or density descriptor.
			$parts = preg_split( '/\s+/', $candidate );

			if ( ! empty( $parts[0] ) ) {
				$urls[] = $parts[0];
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Byte ranges covered by HTML comments.
	 *
	 * @param string $content Post content.
	 * @return array List of [start, end] pairs.
	 */
	private static function comment_ranges( $content ) {
		$ranges  = array();
		$matches = array();

		if ( ! preg_match_all( '/<!--.*?-->/s', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $ranges;
		}

		foreach ( $matches[0] as $match ) {
			$ranges[] = array( $match[1], $match[1] + strlen( $match[0] ) );
		}

		return $ranges;
	}

	/**
	 * Whether an offset falls inside one of the given ranges.
	 *
	 * @param int   $offset Byte offset.
	 * @param array $ranges List of [start, end] pairs.
	 * @return bool
	 */
	private static function is_within( $offset, array $ranges ) {
		foreach ( $ranges as $range ) {
			if ( $offset >= $range[0] && $offset < $range[1] ) {
				return true;
			}
		}

		return false;
	}
}
