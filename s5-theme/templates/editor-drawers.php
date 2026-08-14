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
			<?php /* Head fields — seeded + saved by codelens.js through the ONE door (mode:head → _s5_head). The head round-trip contract lives here now. */ ?>
			<div class="s5-editor__headform" data-s5-headform>
				<div class="s5-editor__field">
					<label class="s5-editor__field-label" for="s5-head-title"><?php esc_html_e( 'SEO Title', 's5' ); ?></label>
					<input id="s5-head-title" class="s5-editor__input" type="text" data-s5-head="title">
				</div>
				<div class="s5-editor__field">
					<label class="s5-editor__field-label" for="s5-head-desc"><?php esc_html_e( 'Meta Description', 's5' ); ?></label>
					<textarea id="s5-head-desc" class="s5-editor__textarea" rows="3" data-s5-head="description"></textarea>
				</div>
				<div class="s5-editor__field">
					<label class="s5-editor__field-label" for="s5-head-canonical"><?php esc_html_e( 'Canonical URL', 's5' ); ?></label>
					<input id="s5-head-canonical" class="s5-editor__input" type="url" data-s5-head="canonical">
				</div>
				<button type="button" class="button button-primary s5-editor__head-save" data-s5-head-save disabled>
					<?php esc_html_e( 'Save meta', 's5' ); ?>
				</button>
				<p class="s5-editor__field-hint"><?php esc_html_e( 'Index/follow, OG image, and Yoast/RankMath write-through land in Phase C.', 's5' ); ?></p>
			</div>
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
