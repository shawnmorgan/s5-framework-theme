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
<?php
/* Drawers TRANSCRIBED VERBATIM from canvas-mockup.html (lines 621-823): the .scrim
 * and .drawer shell (drawer-head / drawer-sub / drawer-body / drawer-sec /
 * drawer-foot), the mockup's exact FontAwesome section icons, and its field, input,
 * switch, seg, og, chip, schema-code and cond components. data-s5-* hooks (drawer
 * open/close, head form fields + save, head segs) are grafted on so the head
 * round-trip through the ONE door (mode:head) and open/close behavior are preserved. */
?>
<div class="scrim" data-s5-drawer-scrim hidden></div>

<?php /* ================= PAGE SETTINGS DRAWER (gear, default) ================= */ ?>
<aside class="drawer" data-s5-drawer="page" aria-label="<?php esc_attr_e( 'Page settings', 's5' ); ?>" <?php echo $s5_is_template ? 'hidden' : ''; ?>>
	<div class="drawer-head">
		<div class="drawer-title"><i class="fa-solid fa-gear"></i> <?php esc_html_e( 'Page Settings', 's5' ); ?></div>
		<button type="button" class="drawer-close" data-s5-drawer-close title="<?php esc_attr_e( 'Close', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Close', 's5' ); ?>"><i class="fa-solid fa-xmark"></i></button>
	</div>
	<div class="drawer-sub"><?php echo esc_html( $s5_title ); ?> <span class="drawer-path">/</span></div>

	<div class="drawer-body">

		<?php /* WP PAGE OPTIONS */ ?>
		<section class="drawer-sec">
			<div class="drawer-sec-head"><i class="fa-brands fa-wordpress"></i> <?php esc_html_e( 'Page Options', 's5' ); ?></div>
			<div class="field">
				<label class="field-label"><?php esc_html_e( 'Title', 's5' ); ?></label>
				<input class="input" value="<?php echo esc_attr( $s5_title ); ?>">
			</div>
			<div class="field">
				<label class="field-label"><?php esc_html_e( 'Permalink', 's5' ); ?></label>
				<div class="input-affix"><span class="affix"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>/</span><input class="input bare" value=""></div>
			</div>
			<div class="field-row">
				<div class="field">
					<label class="field-label"><?php esc_html_e( 'Status', 's5' ); ?></label>
					<select class="select"><option><?php esc_html_e( 'Published', 's5' ); ?></option><option><?php esc_html_e( 'Draft', 's5' ); ?></option><option><?php esc_html_e( 'Pending', 's5' ); ?></option><option><?php esc_html_e( 'Private', 's5' ); ?></option></select>
				</div>
				<div class="field">
					<label class="field-label"><?php esc_html_e( 'Template', 's5' ); ?></label>
					<select class="select"><option><?php esc_html_e( 'Default (S5)', 's5' ); ?></option><option><?php esc_html_e( 'Full width', 's5' ); ?></option><option><?php esc_html_e( 'Landing (no chrome)', 's5' ); ?></option></select>
				</div>
			</div>
			<div class="field">
				<label class="field-label"><?php esc_html_e( 'Parent', 's5' ); ?></label>
				<select class="select"><option><?php esc_html_e( '— none (top level) —', 's5' ); ?></option></select>
			</div>
			<div class="switch-row">
				<div class="switch-label"><?php esc_html_e( 'Set as homepage', 's5' ); ?> <span class="opt"><?php esc_html_e( 'this page is the front page', 's5' ); ?></span></div>
				<button type="button" class="switch" role="switch" aria-checked="false"><span class="knob"></span></button>
			</div>
			<div class="switch-row">
				<div class="switch-label"><?php esc_html_e( 'Show in navigation', 's5' ); ?></div>
				<button type="button" class="switch on" role="switch" aria-checked="true"><span class="knob"></span></button>
			</div>
		</section>

		<?php /* PAGE META / SEO — head fields seeded + saved by codelens.js through the ONE door (mode:head). */ ?>
		<section class="drawer-sec" data-s5-headform>
			<div class="drawer-sec-head"><i class="fa-solid fa-magnifying-glass"></i> <?php esc_html_e( 'Page Meta & SEO', 's5' ); ?></div>
			<div class="field">
				<label class="field-label" for="s5-head-title"><?php esc_html_e( 'SEO Title', 's5' ); ?></label>
				<input id="s5-head-title" class="input" type="text" data-s5-head="title">
			</div>
			<div class="field">
				<label class="field-label" for="s5-head-desc"><?php esc_html_e( 'Meta Description', 's5' ); ?></label>
				<textarea id="s5-head-desc" class="textarea" rows="3" data-s5-head="description"></textarea>
			</div>
			<div class="field">
				<label class="field-label" for="s5-head-canonical"><?php esc_html_e( 'Canonical URL', 's5' ); ?></label>
				<input id="s5-head-canonical" class="input" type="url" data-s5-head="canonical" style="font-family:var(--s5e-mono);font-size:12px">
			</div>
			<div class="field-row">
				<div class="field">
					<label class="field-label"><?php esc_html_e( 'Index', 's5' ); ?></label>
					<div class="seg wide" data-s5-head-seg="index"><button type="button" class="seg-opt active" data-val="1"><?php esc_html_e( 'Index', 's5' ); ?></button><button type="button" class="seg-opt" data-val="0"><?php esc_html_e( 'Noindex', 's5' ); ?></button></div>
				</div>
				<div class="field">
					<label class="field-label"><?php esc_html_e( 'Follow', 's5' ); ?></label>
					<div class="seg wide" data-s5-head-seg="follow"><button type="button" class="seg-opt active" data-val="1"><?php esc_html_e( 'Follow', 's5' ); ?></button><button type="button" class="seg-opt" data-val="0"><?php esc_html_e( 'Nofollow', 's5' ); ?></button></div>
				</div>
			</div>
			<div class="field">
				<label class="field-label"><?php esc_html_e( 'Social / OG Image', 's5' ); ?></label>
				<div class="og-drop"><i class="fa-regular fa-image"></i> <input id="s5-head-og" class="input bare" type="url" data-s5-head="og_image" placeholder="<?php esc_attr_e( 'https://…/og-cover.png', 's5' ); ?>" style="flex:1"></div>
			</div>
			<button type="button" class="button button-primary drawer-btn primary" data-s5-head-save disabled style="height:34px;flex:0 0 auto;padding:0 16px">
				<?php esc_html_e( 'Save meta', 's5' ); ?>
			</button>
			<p class="field-hint"><?php esc_html_e( 'When Yoast or RankMath is active, these values are written through to it; otherwise S5 emits them.', 's5' ); ?></p>
		</section>

		<?php /* SCHEMA INJECTION */ ?>
		<section class="drawer-sec">
			<div class="drawer-sec-head"><i class="fa-solid fa-diagram-project"></i> <?php esc_html_e( 'Schema (JSON-LD)', 's5' ); ?></div>
			<div class="field">
				<label class="field-label" for="s5-head-jsonld"><?php esc_html_e( 'Per-page JSON-LD', 's5' ); ?> <span class="opt"><?php esc_html_e( 'injected into <head> on render', 's5' ); ?></span></label>
				<textarea id="s5-head-jsonld" class="textarea" rows="6" data-s5-head="json_ld" spellcheck="false" style="font-family:var(--s5e-mono);font-size:12px"></textarea>
			</div>
			<button type="button" class="button button-primary drawer-btn primary" data-s5-head-save disabled style="height:34px;flex:0 0 auto;padding:0 16px">
				<?php esc_html_e( 'Save schema', 's5' ); ?>
			</button>
			<p class="field-hint"><?php esc_html_e( 'Validated as JSON on save — invalid JSON is rejected, never emitted.', 's5' ); ?></p>
		</section>

	</div>

	<div class="drawer-foot">
		<button type="button" class="drawer-btn ghost" data-s5-drawer-close><?php esc_html_e( 'Cancel', 's5' ); ?></button>
		<button type="button" class="drawer-btn primary"><?php esc_html_e( 'Apply', 's5' ); ?></button>
	</div>
</aside>

<?php /* ============ TEMPLATE DISPLAY & CONDITIONS DRAWER (?template mode) ============ */ ?>
<aside class="drawer" data-s5-drawer="template" aria-label="<?php esc_attr_e( 'Template display and conditions', 's5' ); ?>" <?php echo $s5_is_template ? '' : 'hidden'; ?>>
	<div class="drawer-head">
		<div class="drawer-title"><i class="fa-solid fa-layer-group"></i> <?php esc_html_e( 'Display & Conditions', 's5' ); ?></div>
		<button type="button" class="drawer-close" data-s5-drawer-close title="<?php esc_attr_e( 'Close', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Close', 's5' ); ?>"><i class="fa-solid fa-xmark"></i></button>
	</div>
	<div class="drawer-sub" data-s5-tpl-sub><?php esc_html_e( 'Template', 's5' ); ?> <span class="drawer-path"></span></div>

	<div class="drawer-body">

		<?php /* DISPLAY OPTIONS */ ?>
		<section class="drawer-sec">
			<div class="drawer-sec-head"><i class="fa-solid fa-desktop"></i> <?php esc_html_e( 'Display Options', 's5' ); ?></div>
			<div class="field">
				<label class="field-label"><?php esc_html_e( 'Content Width', 's5' ); ?></label>
				<div class="seg wide"><div class="seg-opt active"><?php esc_html_e( 'Theme default', 's5' ); ?></div><div class="seg-opt"><?php esc_html_e( 'Full width', 's5' ); ?></div><div class="seg-opt"><?php esc_html_e( 'Narrow', 's5' ); ?></div></div>
			</div>
			<div class="field-row">
				<div class="field">
					<label class="field-label"><?php esc_html_e( 'Header', 's5' ); ?></label>
					<select class="select"><option><?php esc_html_e( 'Show (site-header)', 's5' ); ?></option><option><?php esc_html_e( 'Hide', 's5' ); ?></option><option><?php esc_html_e( 'Transparent', 's5' ); ?></option></select>
				</div>
				<div class="field">
					<label class="field-label"><?php esc_html_e( 'Footer', 's5' ); ?></label>
					<select class="select"><option><?php esc_html_e( 'Show (site-footer)', 's5' ); ?></option><option><?php esc_html_e( 'Hide', 's5' ); ?></option></select>
				</div>
			</div>
			<div class="switch-row" style="border-top:1px solid var(--s5e-line-soft);padding-top:12px">
				<div class="switch-label"><?php esc_html_e( 'Show featured image', 's5' ); ?> <span class="opt"><?php esc_html_e( 'at top of template', 's5' ); ?></span></div>
				<button type="button" class="switch on" role="switch" aria-checked="true"><span class="knob"></span></button>
			</div>
			<div class="switch-row">
				<div class="switch-label"><?php esc_html_e( 'Show page title', 's5' ); ?></div>
				<button type="button" class="switch on" role="switch" aria-checked="true"><span class="knob"></span></button>
			</div>
		</section>

		<?php /* CONDITIONS */ ?>
		<section class="drawer-sec">
			<div class="drawer-sec-head"><i class="fa-solid fa-code-branch"></i> <?php esc_html_e( 'Conditions', 's5' ); ?> <span style="margin-left:auto;font-size:11px;color:var(--s5e-text-faint)"><?php esc_html_e( 'where this template applies', 's5' ); ?></span></div>

			<div class="cond-group">
				<div class="cond-group-label"><span class="cond-inc"><?php esc_html_e( 'Include', 's5' ); ?></span> <?php esc_html_e( 'when', 's5' ); ?></div>
				<div class="cond-row">
					<select class="select cond-sel"><option><?php esc_html_e( 'Post type', 's5' ); ?></option><option><?php esc_html_e( 'Taxonomy', 's5' ); ?></option><option><?php esc_html_e( 'Post', 's5' ); ?></option><option><?php esc_html_e( 'Author', 's5' ); ?></option><option><?php esc_html_e( 'Template', 's5' ); ?></option></select>
					<select class="select cond-op"><option><?php esc_html_e( 'is', 's5' ); ?></option><option><?php esc_html_e( 'is not', 's5' ); ?></option></select>
					<select class="select"><option><?php esc_html_e( 'Post', 's5' ); ?></option><option><?php esc_html_e( 'Page', 's5' ); ?></option><option><?php esc_html_e( 'All singular', 's5' ); ?></option></select>
					<button type="button" class="cond-del" title="<?php esc_attr_e( 'Remove', 's5' ); ?>"><i class="fa-solid fa-xmark"></i></button>
				</div>
				<button type="button" class="cond-add"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Add include rule', 's5' ); ?></button>
			</div>

			<div class="cond-group">
				<div class="cond-group-label"><span class="cond-exc"><?php esc_html_e( 'Exclude', 's5' ); ?></span> <?php esc_html_e( 'when', 's5' ); ?></div>
				<div class="cond-row">
					<select class="select cond-sel"><option><?php esc_html_e( 'Taxonomy', 's5' ); ?></option><option><?php esc_html_e( 'Post type', 's5' ); ?></option><option><?php esc_html_e( 'Post', 's5' ); ?></option></select>
					<select class="select cond-op"><option><?php esc_html_e( 'is', 's5' ); ?></option><option><?php esc_html_e( 'is not', 's5' ); ?></option></select>
					<select class="select"><option><?php esc_html_e( 'Category: News', 's5' ); ?></option><option><?php esc_html_e( 'Category: Docs', 's5' ); ?></option></select>
					<button type="button" class="cond-del" title="<?php esc_attr_e( 'Remove', 's5' ); ?>"><i class="fa-solid fa-xmark"></i></button>
				</div>
				<button type="button" class="cond-add"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Add exclude rule', 's5' ); ?></button>
			</div>

			<div class="field" style="margin-top:16px">
				<label class="field-label"><?php esc_html_e( 'Priority', 's5' ); ?> <span class="opt"><?php esc_html_e( 'higher wins when multiple templates match', 's5' ); ?></span></label>
				<input class="input" value="10" style="width:90px;font-family:var(--s5e-mono)">
			</div>

			<div class="cond-preview">
				<i class="fa-solid fa-circle-info"></i>
				<span><?php echo wp_kses_post( __( 'Applies to <strong>all single Posts</strong>, except those in <strong>Category: News</strong>. Priority 10.', 's5' ) ); ?></span>
			</div>
		</section>

	</div>

	<div class="drawer-foot">
		<button type="button" class="drawer-btn ghost" data-s5-drawer-close><?php esc_html_e( 'Cancel', 's5' ); ?></button>
		<button type="button" class="drawer-btn primary"><?php esc_html_e( 'Apply', 's5' ); ?></button>
	</div>
</aside>
