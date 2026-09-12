<?php
/**
 * Removes an image from post content without disturbing the rest of it.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Excises an image, and whatever markup it leaves stranded, from content.
 *
 * The edit is a splice: the span covering the image is cut out of the original
 * string and everything else is returned untouched, byte for byte. Content is
 * never rebuilt from a parsed document, because doing so silently rewrites
 * attribute order, closes tags and mangles shortcodes across the whole post.
 *
 * Wrapper removal is deliberately timid. A wrapper is only taken when nothing
 * but the image (and markup that exists solely to decorate it) is left inside.
 * Anything unexpected in there and the wrapper stays, which at worst leaves a
 * tidy-up job rather than deleting content someone wanted.
 *
 * A broken URL found only in a srcset is treated differently again. The image
 * is displaying perfectly well from its src; all that is missing is one of the
 * alternate sizes the browser could have chosen. Removing the element there
 * would destroy something that works, so only the dead candidate is taken out
 * of the attribute.
 */
class Rewriter {

	/**
	 * Elements that may sit alongside the image inside a wrapper and still
	 * allow that wrapper to be removed with it.
	 */
	const DECORATIVE_PATTERNS = array(
		'#<figcaption\b[^>]*>.*?</figcaption>#is',
		'#<source\b[^>]*/?>#is',
		'#<br\s*/?>#i',
		'#&nbsp;#i',
	);

	/**
	 * Remove every occurrence of an image URL from a piece of content.
	 *
	 * @param string $content Post content.
	 * @param string $url     Image URL to remove.
	 * @return array {
	 *     @type string $content Updated content.
	 *     @type int    $removed Number of occurrences removed.
	 *     @type array  $spans   The excised strings, for the record.
	 * }
	 */
	public static function remove_image( $content, $url ) {
		$content = (string) $content;
		$removed = 0;
		$spans   = array();

		// Each removal shifts every later offset, so the content is re-scanned
		// after each cut rather than trying to track moving positions.
		for ( $pass = 0; $pass < 50; $pass++ ) {
			$reference = self::find_reference( $content, $url );

			if ( null === $reference ) {
				break;
			}

			// Only in the srcset: the image itself is fine, so edit the
			// attribute rather than deleting a working image.
			if ( 'srcset' === $reference['matched'] ) {
				$tag = self::strip_srcset_candidate( $reference['raw'], $url );

				// Nothing could be changed safely — stop rather than loop.
				if ( null === $tag ) {
					break;
				}

				$spans[] = $reference['raw'];
				$content = substr( $content, 0, $reference['offset'] )
					. $tag
					. substr( $content, $reference['offset'] + $reference['length'] );

				++$removed;
				continue;
			}

			$span = self::expand_span( $content, $reference['offset'], $reference['offset'] + $reference['length'] );

			$spans[] = substr( $content, $span['start'], $span['end'] - $span['start'] );
			$content = substr( $content, 0, $span['start'] ) . substr( $content, $span['end'] );

			++$removed;
		}

		return array(
			'content' => $content,
			'removed' => $removed,
			'spans'   => $spans,
		);
	}

	/**
	 * Find the first image reference matching a URL.
	 *
	 * @param string $content Post content.
	 * @param string $url     Image URL.
	 * @return array|null
	 */
	private static function find_reference( $content, $url ) {
		$target = self::comparable_url( $url );

		foreach ( Extractor::extract( $content ) as $reference ) {
			// src is checked first: an image whose own source is missing is
			// broken outright, whatever its srcset happens to say.
			if ( self::comparable_url( $reference['src'] ) === $target ) {
				$reference['matched'] = 'src';

				return $reference;
			}

			foreach ( $reference['srcset'] as $candidate ) {
				if ( self::comparable_url( $candidate ) === $target ) {
					$reference['matched'] = 'srcset';

					return $reference;
				}
			}
		}

		return null;
	}

	/**
	 * Grow the removal span outwards over any markup the image leaves stranded.
	 *
	 * Innermost first: the link around the image, then the picture or figure
	 * element, then the Classic Editor's caption shortcode on the outside.
	 *
	 * @param string $content Post content.
	 * @param int    $start   Span start.
	 * @param int    $end     Span end.
	 * @return array Span with start and end keys.
	 */
	private static function expand_span( $content, $start, $end ) {
		$span = array(
			'start' => $start,
			'end'   => $end,
		);

		$span = self::expand_element( $content, $span, 'a' );
		$span = self::expand_element( $content, $span, 'picture' );
		$span = self::expand_element( $content, $span, 'figure' );
		$span = self::expand_caption_shortcode( $content, $span );
		$span = self::trim_blank_line( $content, $span );

		return $span;
	}

	/**
	 * Take in the enclosing element when the span is all that is inside it.
	 *
	 * @param string $content Post content.
	 * @param array  $span    Current span.
	 * @param string $tag     Element name.
	 * @return array
	 */
	private static function expand_element( $content, array $span, $tag ) {
		$open = self::preceding_open_tag( $content, $span['start'], $tag );

		if ( null === $open ) {
			return $span;
		}

		$close = stripos( $content, '</' . $tag . '>', $span['end'] );

		if ( false === $close ) {
			return $span;
		}

		// A nested element of the same name between the opening tag and the span
		// means the tag found is not the one wrapping this image.
		$between = substr( $content, $open['end'], $span['start'] - $open['end'] );

		if ( preg_match( '#<' . preg_quote( $tag, '#' ) . '[\s>]#i', $between ) ) {
			return $span;
		}

		$inside = substr( $content, $open['end'], $span['start'] - $open['end'] )
			. substr( $content, $span['end'], $close - $span['end'] );

		if ( ! self::is_only_decoration( $inside ) ) {
			return $span;
		}

		return array(
			'start' => $open['start'],
			'end'   => $close + strlen( '</' . $tag . '>' ),
		);
	}

	/**
	 * Take in a wrapping `[caption]` shortcode.
	 *
	 * The Classic Editor stores captions as a shortcode, not as markup, so a
	 * parser sees nothing to remove here. Without this step a cleaned post is
	 * left showing raw `[caption]` text where the image used to be.
	 *
	 * @param string $content Post content.
	 * @param array  $span    Current span.
	 * @return array
	 */
	private static function expand_caption_shortcode( $content, array $span ) {
		$before = substr( $content, 0, $span['start'] );
		$open   = strripos( $before, '[caption' );

		if ( false === $open ) {
			return $span;
		}

		// An intervening close means the earlier caption was not ours.
		if ( false !== strripos( $before, '[/caption]' ) && strripos( $before, '[/caption]' ) > $open ) {
			return $span;
		}

		$open_end = strpos( $content, ']', $open );

		if ( false === $open_end || $open_end > $span['start'] ) {
			return $span;
		}

		$close = stripos( $content, '[/caption]', $span['end'] );

		if ( false === $close ) {
			return $span;
		}

		// Only the caption's own image may be inside; a caption holding another
		// image is left alone entirely.
		$inside = substr( $content, $open_end + 1, $span['start'] - $open_end - 1 )
			. substr( $content, $span['end'], $close - $span['end'] );

		if ( false !== stripos( $inside, '<img' ) ) {
			return $span;
		}

		return array(
			'start' => $open,
			'end'   => $close + strlen( '[/caption]' ),
		);
	}

	/**
	 * Swallow the newline left behind when the span was alone on its line.
	 *
	 * @param string $content Post content.
	 * @param array  $span    Current span.
	 * @return array
	 */
	private static function trim_blank_line( $content, array $span ) {
		$before = substr( $content, 0, $span['start'] );
		$after  = substr( $content, $span['end'] );

		if ( ! preg_match( '/\R[ \t]*$/', $before ) || ! preg_match( '/^[ \t]*\R/', $after ) ) {
			return $span;
		}

		$trimmed = preg_replace( '/[ \t]*$/', '', $before );
		$matches = array();

		preg_match( '/^[ \t]*\R/', $after, $matches );

		return array(
			'start' => strlen( $trimmed ),
			'end'   => $span['end'] + strlen( $matches[0] ),
		);
	}

	/**
	 * Locate the nearest opening tag of a given name before an offset.
	 *
	 * @param string $content Post content.
	 * @param int    $offset  Offset to search back from.
	 * @param string $tag     Element name.
	 * @return array|null Start and end offsets of the opening tag.
	 */
	private static function preceding_open_tag( $content, $offset, $tag ) {
		$before  = substr( $content, 0, $offset );
		$matches = array();

		if ( ! preg_match_all( '#<' . preg_quote( $tag, '#' ) . '(?:\s[^>]*)?>#i', $before, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$last = end( $matches[0] );

		// Only whitespace may sit between the opening tag and the span; anything
		// else means the image is not the sole occupant.
		$gap = substr( $content, $last[1] + strlen( $last[0] ), $offset - $last[1] - strlen( $last[0] ) );

		if ( '' !== trim( $gap ) && ! self::is_only_decoration( $gap ) ) {
			return null;
		}

		return array(
			'start' => $last[1],
			'end'   => $last[1] + strlen( $last[0] ),
		);
	}

	/**
	 * Whether a fragment holds nothing but whitespace and image decoration.
	 *
	 * @param string $fragment Markup fragment.
	 * @return bool
	 */
	private static function is_only_decoration( $fragment ) {
		foreach ( self::DECORATIVE_PATTERNS as $pattern ) {
			$fragment = preg_replace( $pattern, '', $fragment );
		}

		return '' === trim( (string) $fragment );
	}

	/**
	 * Take one dead candidate out of an image tag's srcset.
	 *
	 * The element is left alone otherwise. If that candidate was the only one,
	 * the srcset goes entirely, and `sizes` with it, since `sizes` describes a
	 * srcset that would no longer exist.
	 *
	 * @param string $tag One `<img>` tag.
	 * @param string $url Candidate URL to drop.
	 * @return string|null Rewritten tag, or null if nothing could be changed.
	 */
	private static function strip_srcset_candidate( $tag, $url ) {
		$matches = array();

		// Only a quoted srcset can be rewritten with confidence. An unquoted
		// one is left untouched rather than guessed at.
		if ( ! preg_match( '#\ssrcset\s*=\s*(["\'])(.*?)\1#is', $tag, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$quote  = $matches[1][0];
		$value  = $matches[2][0];
		$target = self::comparable_url( $url );

		$kept    = array();
		$dropped = 0;

		foreach ( explode( ',', $value ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( '' === $candidate ) {
				continue;
			}

			$parts = preg_split( '/\s+/', $candidate );

			if ( ! empty( $parts[0] ) && self::comparable_url( $parts[0] ) === $target ) {
				++$dropped;
				continue;
			}

			$kept[] = $candidate;
		}

		if ( 0 === $dropped ) {
			return null;
		}

		$start  = $matches[0][1];
		$length = strlen( $matches[0][0] );

		if ( empty( $kept ) ) {
			$tag = substr( $tag, 0, $start ) . substr( $tag, $start + $length );

			return preg_replace( '#\ssizes\s*=\s*(["\']).*?\1#is', '', $tag, 1 );
		}

		return substr( $tag, 0, $start )
			. ' srcset=' . $quote . implode( ', ', $kept ) . $quote
			. substr( $tag, $start + $length );
	}

	/**
	 * Normalise a URL for comparison, ignoring scheme, entities and cache-busters.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private static function comparable_url( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		$url = preg_replace( '/[?#].*$/', '', $url );
		$url = preg_replace( '#^[a-z][a-z0-9+.-]*:#i', '', $url );

		return $url;
	}
}
