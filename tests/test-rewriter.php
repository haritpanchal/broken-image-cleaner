<?php
/**
 * Standalone tests for the extractor and rewriter.
 *
 * Neither class calls a WordPress function, so these run under plain PHP:
 *
 *     php tests/test-rewriter.php
 *
 * The point of most of these cases is not that the image disappears — that part
 * is easy. It is that everything else in the content is returned untouched.
 *
 * @package BrokenImageCleaner
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-extractor.php';
require_once __DIR__ . '/../includes/class-rewriter.php';

use BrokenImageCleaner\Extractor;
use BrokenImageCleaner\Rewriter;

$broken_image_cleaner_passed = 0;
$broken_image_cleaner_failed = 0;

/**
 * Assert that two strings match, reporting the difference when they do not.
 *
 * @param string $label    What is being checked.
 * @param string $expected Expected value.
 * @param string $actual   Actual value.
 * @return void
 */
function broken_image_cleaner_assert( $label, $expected, $actual ) {
	global $broken_image_cleaner_passed, $broken_image_cleaner_failed;

	if ( $expected === $actual ) {
		++$broken_image_cleaner_passed;
		echo "  PASS  {$label}\n";

		return;
	}

	++$broken_image_cleaner_failed;

	echo "  FAIL  {$label}\n";
	echo '        expected: ' . str_replace( "\n", '\n', var_export( $expected, true ) ) . "\n";
	echo '        actual:   ' . str_replace( "\n", '\n', var_export( $actual, true ) ) . "\n";
}

/**
 * Remove an image and assert the resulting content.
 *
 * @param string $label    What is being checked.
 * @param string $content  Starting content.
 * @param string $url      Image URL to remove.
 * @param string $expected Expected content afterwards.
 * @return void
 */
function broken_image_cleaner_assert_removal( $label, $content, $url, $expected ) {
	$result = Rewriter::remove_image( $content, $url );

	broken_image_cleaner_assert( $label, $expected, $result['content'] );
}

$gone = '/wp-content/uploads/2013/07/gone.jpg';

echo "Extractor\n";

broken_image_cleaner_assert(
	'reads src from a plain tag',
	$gone,
	Extractor::extract( '<p>Hi</p><img src="' . $gone . '" alt="x">' )[0]['src']
);

broken_image_cleaner_assert(
	'reads src from an unquoted attribute',
	$gone,
	Extractor::extract( '<img src=' . $gone . ' alt=x>' )[0]['src']
);

broken_image_cleaner_assert(
	'decodes entities in the URL',
	'/uploads/a b.jpg?v=1',
	Extractor::extract( '<img src="/uploads/a b.jpg?v=1" />' )[0]['src']
);

broken_image_cleaner_assert(
	'ignores an image inside an HTML comment',
	'0',
	(string) count( Extractor::extract( '<!-- <img src="' . $gone . '"> -->' ) )
);

broken_image_cleaner_assert(
	'splits srcset into candidates',
	'/a.jpg|/b.jpg',
	implode( '|', Extractor::extract( '<img src="/x.jpg" srcset="/a.jpg 300w, /b.jpg 600w">' )[0]['srcset'] )
);

echo "\nRewriter: the image goes\n";

broken_image_cleaner_assert_removal(
	'a bare image',
	'<p>Before</p><img src="' . $gone . '" alt="x" /><p>After</p>',
	$gone,
	'<p>Before</p><p>After</p>'
);

broken_image_cleaner_assert_removal(
	'an image wrapped in a link',
	'<p>Before</p><a href="/full.jpg"><img src="' . $gone . '" /></a><p>After</p>',
	$gone,
	'<p>Before</p><p>After</p>'
);

broken_image_cleaner_assert_removal(
	'a caption shortcode, the Classic Editor default',
	'<p>Before</p>[caption id="attachment_9" align="alignnone" width="300"]<img src="' . $gone . '" />A caption[/caption]<p>After</p>',
	$gone,
	'<p>Before</p><p>After</p>'
);

broken_image_cleaner_assert_removal(
	'a caption shortcode wrapping a linked image',
	'[caption id="attachment_9" width="300"]<a href="/full.jpg"><img src="' . $gone . '" /></a>A caption[/caption]',
	$gone,
	''
);

broken_image_cleaner_assert_removal(
	'a figure with a figcaption',
	'<p>Before</p><figure class="wp-block-image"><img src="' . $gone . '" /><figcaption>Caption</figcaption></figure><p>After</p>',
	$gone,
	'<p>Before</p><p>After</p>'
);

broken_image_cleaner_assert_removal(
	'a picture with source elements',
	'<picture><source srcset="/a.webp" type="image/webp"><img src="' . $gone . '" /></picture>',
	$gone,
	''
);

broken_image_cleaner_assert_removal(
	'every occurrence of the same image',
	'<img src="' . $gone . '"><p>Middle</p><img src="' . $gone . '">',
	$gone,
	'<p>Middle</p>'
);

broken_image_cleaner_assert_removal(
	'matches regardless of scheme and cache-busting query',
	'<p>Before</p><img src="https://example.com/wp-content/uploads/2013/07/gone.jpg?ver=2" /><p>After</p>',
	'http://example.com/wp-content/uploads/2013/07/gone.jpg',
	'<p>Before</p><p>After</p>'
);

echo "\nRewriter: everything else stays put\n";

broken_image_cleaner_assert_removal(
	'a healthy image in the same post is untouched',
	'<img src="/wp-content/uploads/2013/07/fine.jpg"><img src="' . $gone . '">',
	$gone,
	'<img src="/wp-content/uploads/2013/07/fine.jpg">'
);

broken_image_cleaner_assert_removal(
	'surrounding markup is not reformatted',
	"<div class='odd' data-x=1>\n  <p>Text &amp; more</p>\n  <img src=\"" . $gone . "\">\n  <p>[shortcode a=1]</p>\n</div>",
	$gone,
	"<div class='odd' data-x=1>\n  <p>Text &amp; more</p>\n  <p>[shortcode a=1]</p>\n</div>"
);

broken_image_cleaner_assert_removal(
	'a figure holding real content keeps the figure',
	'<figure><img src="' . $gone . '"><p>Real text</p></figure>',
	$gone,
	'<figure><p>Real text</p></figure>'
);

broken_image_cleaner_assert_removal(
	'a link wrapping more than the image keeps the link',
	'<a href="/x"><img src="' . $gone . '">Read more</a>',
	$gone,
	'<a href="/x">Read more</a>'
);

broken_image_cleaner_assert_removal(
	'a caption holding a second image is left alone',
	'[caption width="300"]<img src="' . $gone . '"><img src="/wp-content/uploads/2013/07/fine.jpg">Caption[/caption]',
	$gone,
	'[caption width="300"]<img src="/wp-content/uploads/2013/07/fine.jpg">Caption[/caption]'
);

broken_image_cleaner_assert_removal(
	'an unrelated URL changes nothing at all',
	'<p>Before</p><img src="' . $gone . '"><p>After</p>',
	'/wp-content/uploads/2013/07/other.jpg',
	'<p>Before</p><img src="' . $gone . '"><p>After</p>'
);

echo "\n";
printf( "%d passed, %d failed\n", $broken_image_cleaner_passed, $broken_image_cleaner_failed );

exit( $broken_image_cleaner_failed > 0 ? 1 : 0 );
