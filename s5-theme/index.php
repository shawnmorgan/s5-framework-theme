<?php
/**
 * S5 shell theme — minimal fallback template.
 *
 * Step 0: the smallest valid template so the theme activates and WordPress can
 * render the front page without error. The real framework render path (serving
 * canonical HTML fragments, bypassing Gutenberg) is Step 1+ and is NOT built
 * here.
 *
 * @package S5\Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();
		the_title( '<h1>', '</h1>' );
		the_content();
	}
}

get_footer();
