<?php
/**
 * S5 Framework — template canvas wrapper.
 *
 * The doctype/head/body shell WordPress includes (via TemplateRouter::route on
 * template_include) for single-post / index / archive / search / 404 contexts. Unlike
 * canvas.php (which serves a stored framework-PAGE fragment), this renders an S5
 * TEMPLATE for the current query: TemplateRouter::render_current() loads the template
 * file, expands parts, and runs S5 Dynamics against the main query / queried post.
 *
 * wp_head() / wp_body_open() / wp_footer() stay OUTSIDE the fragment (same discipline
 * as canvas.php) so plugin/SEO head output and body-open integrations still fire, while
 * the template body stays clean canonical HTML.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s5_body = \S5\TemplateRouter::render_current();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php
// The rendered S5 template fragment: parts + Dynamics-bound content. Field/loop output
// is context-escaped/inert inside Dynamics (§7); parts + template markup are authored
// canonical HTML served as composed.
echo $s5_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- S5 template: authored canonical HTML + Dynamics output already escaped per field kind.
?>
<?php wp_footer(); ?>
</body>
</html>
