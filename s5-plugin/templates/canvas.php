<?php
/**
 * S5 Framework — canvas (runtime) template.
 *
 * Step 1: serve the stored canonical body-fragment HTML verbatim inside a
 * minimal, valid HTML document. The fragment is echoed raw — it is NOT passed
 * through `the_content`, so WordPress applies no wpautop / wptexturize /
 * shortcode / block / oEmbed transformation to it. `wp_head()` and `wp_footer()`
 * stay OUTSIDE the fragment so the authored bytes remain byte-clean.
 *
 * The real document shell (design-system enqueue, SEO head, media, dynamics) is
 * built in later steps. This is the smallest wrapper that makes the page a valid
 * runtime for authored HTML.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s5_post_id  = get_queried_object_id();
$s5_fragment = \S5\Storage::get_canonical_html( (int) $s5_post_id );

// `wp_head()` may emit notices if headers are already sent; the fragment is
// echoed exactly as stored with no intervening filters.
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
// Byte-clean: the stored fragment is written exactly as authored.
echo $s5_fragment; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- canonical authored HTML, served verbatim by design (Build Order Step 1).
?>

<?php wp_footer(); ?>
</body>
</html>
