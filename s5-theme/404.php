<?php
/**
 * S5 shell theme — 404 template (Build Order Step 7 launch ops).
 *
 * If a framework `404` page was imported (pages/404.html → slug `404`), serve its
 * compiled render through the plugin canvas so the 404 body is authored the same
 * way as every other page. Otherwise emit a minimal, branded fallback. Either way
 * the HTTP 404 status set by WordPress is preserved.
 *
 * @package S5\Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prefer an authored framework 404 page if one exists.
if ( class_exists( '\S5\Render' ) && \S5\Render::canvas_for_slug( '404' ) ) {
	return;
}

// Minimal branded fallback (no authored 404 page present).
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<main style="max-width:40rem;margin:4rem auto;padding:0 1rem;font-family:system-ui,sans-serif;">
	<p style="text-transform:uppercase;letter-spacing:.08em;color:#666;">Error 404</p>
	<h1>Page not found</h1>
	<p>The page you were looking for isn&rsquo;t here. Try the
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">home page</a>.</p>
</main>
<?php wp_footer(); ?>
</body>
</html>
<?php
