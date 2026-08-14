<?php
/**
 * S5 Framework — editor gear drawers (Phase B, per canvas-mockup.html §2.7).
 *
 * Two drawers behind the toolbar gear, chosen by mode (HANDOFF §2.7):
 *   • Page Settings (default)          — WP page options · Page Meta & SEO · JSON-LD.
 *   • Display & Conditions (?template)  — display options + the shared Include/Exclude
 *                                         condition builder + priority.
 * Phase B ships the drawer shells + open/close/mode-switch behavior; the fields wire
 * to post meta / template registry in Phase C (Page Settings drawer + Templates screen).
 *
 * Included by templates/editor.php. Expects $s5_is_template (bool) in scope.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s5_is_template = isset( $s5_is_template ) ? (bool) $s5_is_template : false;
?>
<div class="s5-editor__scrim" data-s5-drawer-scrim hidden></div>

<?php /* ---- PAGE SETTINGS DRAWER (default mode) ---- */ ?>
<aside class="s5-editor__drawer" data-s5-drawer="page" aria-label="<?php esc_attr_e( 'Page settings', 's5' ); ?>" <?php echo $s5_is_template ? 'hidden' : ''; ?>>
	<div class="s5-editor__drawer-head">
		<div class="s5-editor__drawer-title"><?php esc_html_e( 'Page Settings', 's5' ); ?></div>
		<button type="button" class="s5-editor__drawer-close" data-s5-drawer-close aria-label="<?php esc_attr_e( 'Close', 's5' ); ?>">&times;</button>
	</div>
	<div class="s5-editor__drawer-body">
		<section class="s5-editor__drawer-sec">
			<div class="s5-editor__drawer-sec-head"><?php esc_html_e( 'Page Options', 's5' ); ?></div>
			<p class="s5-editor__hint"><?php esc_html_e( 'Title, permalink, status, template, parent, homepage & navigation. Wires to post meta in the next step.', 's5' ); ?></p>
		</section>
		<section class="s5-editor__drawer-sec">
			<div class="s5-editor__drawer-sec-head"><?php esc_html_e( 'Page Meta & SEO', 's5' ); ?></div>
			<p class="s5-editor__hint"><?php esc_html_e( 'SEO title, meta description, canonical, index/follow, OG image. Writes through to Yoast/RankMath when present.', 's5' ); ?></p>
		</section>
		<section class="s5-editor__drawer-sec">
			<div class="s5-editor__drawer-sec-head"><?php esc_html_e( 'Schema (JSON-LD)', 's5' ); ?></div>
			<p class="s5-editor__hint"><?php esc_html_e( 'Per-page JSON-LD injected into <head> on render.', 's5' ); ?></p>
		</section>
	</div>
	<div class="s5-editor__drawer-foot">
		<button type="button" class="button s5-editor__drawer-btn" data-s5-drawer-close><?php esc_html_e( 'Cancel', 's5' ); ?></button>
		<button type="button" class="button button-primary s5-editor__drawer-btn"><?php esc_html_e( 'Apply', 's5' ); ?></button>
	</div>
</aside>

<?php /* ---- TEMPLATE DISPLAY & CONDITIONS DRAWER (?template mode) ---- */ ?>
<aside class="s5-editor__drawer" data-s5-drawer="template" aria-label="<?php esc_attr_e( 'Template display and conditions', 's5' ); ?>" <?php echo $s5_is_template ? '' : 'hidden'; ?>>
	<div class="s5-editor__drawer-head">
		<div class="s5-editor__drawer-title"><?php esc_html_e( 'Display & Conditions', 's5' ); ?></div>
		<button type="button" class="s5-editor__drawer-close" data-s5-drawer-close aria-label="<?php esc_attr_e( 'Close', 's5' ); ?>">&times;</button>
	</div>
	<div class="s5-editor__drawer-body">
		<section class="s5-editor__drawer-sec">
			<div class="s5-editor__drawer-sec-head"><?php esc_html_e( 'Display Options', 's5' ); ?></div>
			<p class="s5-editor__hint"><?php esc_html_e( 'Content width, header/footer, featured image, page title.', 's5' ); ?></p>
		</section>
		<section class="s5-editor__drawer-sec">
			<div class="s5-editor__drawer-sec-head"><?php esc_html_e( 'Conditions', 's5' ); ?></div>
			<p class="s5-editor__hint"><?php esc_html_e( 'Include / Exclude rules decide where this template applies. Shared condition builder (Phase C).', 's5' ); ?></p>
		</section>
	</div>
	<div class="s5-editor__drawer-foot">
		<button type="button" class="button s5-editor__drawer-btn" data-s5-drawer-close><?php esc_html_e( 'Cancel', 's5' ); ?></button>
		<button type="button" class="button button-primary s5-editor__drawer-btn"><?php esc_html_e( 'Apply', 's5' ); ?></button>
	</div>
</aside>
