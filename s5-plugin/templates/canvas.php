<?php
/**
 * S5 Framework — canvas (runtime) template.
 *
 * Serves the stored canonical/derived body fragment inside a minimal, valid HTML
 * document. The fragment is echoed raw — it is NOT passed through `the_content`,
 * so WordPress applies no wpautop / wptexturize / block / oEmbed transformation.
 * `wp_head()` / `wp_footer()` stay OUTSIDE the fragment so authored bytes stay
 * byte-clean.
 *
 * Step 8 runtime pipeline (PRD §3.3): `fetch compiled render → dynamic pass (L&L
 * if the page is dynamic) → shortcode pass → serve`. The dynamic + shortcode
 * passes run ONLY for pages flagged dynamic (their render contains Loops & Logic
 * tags or shortcodes). Static pages skip both passes entirely and are served
 * exactly as compiled — their byte-clean round-trip fidelity is untouched.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s5_post_id = get_queried_object_id();
// Step 5: serve the DERIVED render artifact. Fall back to canonical for pages
// authored before the canonical/render split (or not yet compiled).
$s5_fragment = \S5\Storage::get_render_html( (int) $s5_post_id );
if ( '' === $s5_fragment ) {
	$s5_fragment = \S5\Storage::get_canonical_html( (int) $s5_post_id );
}

// Step 8 runtime dynamic passes — ONLY for pages flagged dynamic. This is where
// compile-on-write hands off to request-time evaluation, and only where declared.
if ( \S5\Storage::is_dynamic( (int) $s5_post_id ) ) {
	// Dynamic pass: Loops & Logic template engine (loops, fields, conditions).
	if ( function_exists( 'tangible_template' ) ) {
		$s5_fragment = (string) tangible_template( $s5_fragment );
	}
	// Shortcode pass: forms / legacy / plugin compatibility.
	$s5_fragment = do_shortcode( $s5_fragment );
}

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
