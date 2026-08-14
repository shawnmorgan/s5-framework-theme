<?php
/**
 * S5 Framework — editor admin screen template (Phase B: rebuilt to canvas-mockup.html).
 *
 * Rendered by Editor::render_screen() / render_fullscreen_document(). Expects $s5_editor:
 *   - post_id     int
 *   - title       string
 *   - preview_url string  (canvas permalink + ?s5-preview=1)
 *   - back_url    string  (classic edit-post link)
 *
 * LAYOUT (per HANDOFF §2 + canvas-mockup.html):
 *   • TOP TOOLBAR  — logo · gear (page settings) · Tools │ viewport + lens │
 *                    undo/redo · View · Save(+dirty).
 *   • LEFT PANEL   — palette (registered components + Embed) ⇄ props editor on
 *                    selection (Content / Style / Advanced). No CSS/class fields.
 *   • CENTER STAGE — Edit lens = live preview iframe; Code lens = canonical source.
 *   • RIGHT PANEL  — STRUCTURE TREE ONLY, always (HANDOFF §0.6 / §6).
 *   • GEAR DRAWERS — Page Settings (default) / Display & Conditions (?template mode).
 *
 * ARCHITECTURE PRESERVED (R2 + one save door): the preview is an iframe; overlay.js
 * (injected into it) reports selection by server-assigned data-node via postMessage;
 * saves go through the REST route. This template keeps every data-s5-* hook the editor
 * JS binds to — the rebuild is the DOM/layout, not the plumbing.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template data assembled by Editor::render_screen().
 *
 * @var array{post_id:int,title:string,preview_url:string,back_url:string} $s5_editor
 */
$s5_editor = isset( $s5_editor ) && is_array( $s5_editor ) ? $s5_editor : array();

$s5_title       = isset( $s5_editor['title'] ) ? (string) $s5_editor['title'] : '';
$s5_preview_url = isset( $s5_editor['preview_url'] ) ? (string) $s5_editor['preview_url'] : '';
$s5_back_url    = isset( $s5_editor['back_url'] ) ? (string) $s5_editor['back_url'] : admin_url( 'edit.php' );

// Template mode flips the gear drawer to Display & Conditions (HANDOFF §2.7).
$s5_is_template             = isset( $_GET['template'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentational mode flag; screen is capability-gated.
$s5_settings_style_url      = admin_url( 'admin.php?page=s5-settings&screen=styleguide' );
$s5_settings_components_url = admin_url( 'admin.php?page=s5-settings&screen=components' );
$s5_settings_css_url        = admin_url( 'admin.php?page=s5-settings&screen=css' );
?>
<div class="s5-editor" data-s5-editor<?php echo $s5_is_template ? ' data-s5-template-mode' : ''; ?>>

	<?php
	/* ======================= TOP TOOLBAR =======================
	 * Transcribed VERBATIM from docs/ui-reference/canvas-mockup.html (lines 581-619):
	 * mockup classes (.tb, .tb-icon-btn, .tb-tools, .vp, .lens, .tb-save…) and the
	 * exact FontAwesome glyphs. The editor JS binds by data-s5-* hooks, which are
	 * grafted onto the mockup's elements so behavior is preserved without changing
	 * the contract's markup/classes/icons. */
	?>
	<header class="tb">
		<div class="tb-group">
			<a class="tb-logo" href="<?php echo esc_url( $s5_back_url ); ?>" title="<?php esc_attr_e( 'Back to WordPress', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Back to WordPress', 's5' ); ?>">S5</a>
			<button type="button" class="tb-icon-btn" data-s5-page-settings title="<?php esc_attr_e( 'Page settings', 's5' ); ?>"><i class="fa-solid fa-gear"></i></button>
			<div class="tools-wrap">
				<button type="button" class="tb-tools" data-s5-tools-btn aria-expanded="false"><i class="fa-solid fa-screwdriver-wrench" style="font-size:12px"></i> <?php esc_html_e( 'Tools', 's5' ); ?> <i class="fa-solid fa-chevron-down" style="font-size:9px;opacity:.6"></i></button>
				<div class="tools-menu" data-s5-tools-menu>
					<a class="tools-item" href="<?php echo esc_url( $s5_settings_components_url ); ?>"><i class="fa-solid fa-cubes"></i><span class="ti-label"><?php esc_html_e( 'Components', 's5' ); ?></span><span class="ti-note"><?php esc_html_e( 'registered library', 's5' ); ?></span></a>
					<a class="tools-item" href="<?php echo esc_url( $s5_settings_style_url ); ?>"><i class="fa-solid fa-swatchbook"></i><span class="ti-label"><?php esc_html_e( 'Styleguide', 's5' ); ?></span><span class="ti-note"><?php esc_html_e( 'tokens & scales', 's5' ); ?></span></a>
					<a class="tools-item" href="<?php echo esc_url( $s5_settings_css_url ); ?>"><i class="fa-solid fa-code"></i><span class="ti-label"><?php esc_html_e( 'CSS / Tailwind', 's5' ); ?></span><span class="ti-note"><?php esc_html_e( 'source layers', 's5' ); ?></span></a>
				</div>
			</div>
		</div>

		<div class="tb-group center">
			<div class="vp" role="group" aria-label="<?php esc_attr_e( 'Preview width', 's5' ); ?>">
				<button type="button" class="vp-btn active" data-s5-viewport="desktop" aria-pressed="true"><i class="fa-solid fa-display"></i> <?php esc_html_e( 'All', 's5' ); ?></button>
				<button type="button" class="vp-btn" data-s5-viewport="tablet" aria-pressed="false"><i class="fa-solid fa-tablet-screen-button"></i> <?php esc_html_e( '768–991px', 's5' ); ?></button>
				<button type="button" class="vp-btn" data-s5-viewport="mobile" aria-pressed="false"><i class="fa-solid fa-mobile-screen-button"></i> <?php esc_html_e( '≤767px', 's5' ); ?></button>
				<button type="button" class="vp-btn" title="<?php esc_attr_e( 'Custom width', 's5' ); ?>"><i class="fa-solid fa-pen"></i></button>
			</div>

			<div class="lens" role="group" aria-label="<?php esc_attr_e( 'Editing lens', 's5' ); ?>">
				<button type="button" class="lens-btn active" data-s5-lens="edit" aria-pressed="true"><i class="fa-solid fa-arrow-pointer" style="font-size:11px"></i> <?php esc_html_e( 'Edit', 's5' ); ?></button>
				<button type="button" class="lens-btn" data-s5-lens="code" aria-pressed="false"><i class="fa-solid fa-code" style="font-size:12px"></i> <?php esc_html_e( 'Code', 's5' ); ?></button>
			</div>
		</div>

		<div class="tb-group right">
			<button type="button" class="tb-icon-btn" data-s5-undo disabled title="<?php esc_attr_e( 'Undo', 's5' ); ?>"><i class="fa-solid fa-rotate-left"></i></button>
			<button type="button" class="tb-icon-btn" data-s5-redo disabled title="<?php esc_attr_e( 'Redo', 's5' ); ?>"><i class="fa-solid fa-rotate-right"></i></button>
			<div class="tb-sep"></div>
			<a class="tb-text-btn" href="<?php echo esc_url( $s5_preview_url ); ?>" target="_blank" rel="noopener"><i class="fa-regular fa-eye"></i> <?php esc_html_e( 'View', 's5' ); ?></a>
			<button type="button" class="tb-save" data-s5-update disabled><span class="dirty-dot" data-s5-dirty style="display:none"></span> <?php esc_html_e( 'Save', 's5' ); ?></button>
		</div>
	</header>

	<?php require __DIR__ . '/editor-drawers.php'; ?>

	<?php
	/* ======================= BODY: left ⇄ stage ⇄ right =======================
	 * TRANSCRIBED VERBATIM from canvas-mockup.html (lines 825-1061): the mockup's
	 * own .body / .panel.left / .palette / .props / .stage-wrap / .panel.right / .tree
	 * markup and classes. data-s5-* hooks are grafted onto the mockup elements so the
	 * editor JS binds without changing the contract's markup. Dynamic regions
	 * (palette tiles, per-type content fields, tree rows) are populated by
	 * palette.js / panel.js / structure.js, which emit these same mockup classes. */
	?>
	<div class="body" data-s5-body>

		<?php /* ------------------ LEFT PANEL: palette ⇄ props ------------------ */ ?>
		<aside class="panel left" data-s5-leftpanel aria-label="<?php esc_attr_e( 'Components and properties', 's5' ); ?>">
			<div class="panel-head">
				<h2 data-s5-left-title><?php esc_html_e( 'Add', 's5' ); ?></h2>
				<span class="sub" data-s5-left-sub><?php esc_html_e( 'Registered components · drag to place', 's5' ); ?></span>
			</div>

			<?php /* Palette (default). Populated by palette.js — .palette-group / .palette-grid / .pal-item. */ ?>
			<div class="palette" data-s5-palette>
				<p class="palette-intro"><?php esc_html_e( 'These are the only elements you can add — every one is a registered, contract-valid component. Need something new? Ask us to register it.', 's5' ); ?></p>
				<p class="s5e-hint"><?php esc_html_e( 'Loading components…', 's5' ); ?></p>
			</div>

			<?php /* Props editor (on selection). palette hides; this shows (.props.active). */ ?>
			<div class="props" data-s5-props hidden>
				<div class="props-crumb">
					<button type="button" class="back" data-s5-props-back title="<?php esc_attr_e( 'Back to components', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Back to components', 's5' ); ?>"><i class="fa-solid fa-arrow-left"></i></button>
					<span data-s5-props-name><?php esc_html_e( 'Element', 's5' ); ?></span>
					<span class="tag" data-s5-props-tag></span>
				</div>

				<div class="prop-tabs" role="tablist">
					<button type="button" class="prop-tab active" data-s5-ptab="content" role="tab" aria-selected="true"><?php esc_html_e( 'Content', 's5' ); ?></button>
					<button type="button" class="prop-tab" data-s5-ptab="style" role="tab" aria-selected="false"><?php esc_html_e( 'Style', 's5' ); ?></button>
					<button type="button" class="prop-tab" data-s5-ptab="advanced" role="tab" aria-selected="false"><?php esc_html_e( 'Advanced', 's5' ); ?></button>
				</div>

				<?php /* CONTENT — per-type fields (.field / .enable-row …) rendered by panel.js into data-s5-content. */ ?>
				<div class="ptab-panel active" data-s5-ppanel="content">
					<div data-s5-content data-s5-panel-body>
						<p class="s5e-hint" data-s5-hint><?php esc_html_e( 'Select an editable element to see its fields.', 's5' ); ?></p>
					</div>
				</div>

				<?php /* STYLE — variant + t-shirt sizing + theme-role color (R3), then the read-only token specimen. No CSS/class field. */ ?>
				<div class="ptab-panel" data-s5-ppanel="style" hidden>
					<div data-s5-style-controls>
						<p class="s5e-hint"><?php esc_html_e( 'Select an element to adjust its variant, size, spacing and role color.', 's5' ); ?></p>
					</div>

					<?php /* GAP: CSS-mode switch (mockup never draws it) — composed from the mockup .seg pattern. */ ?>
					<div class="s5e-cssmode" data-s5-cssmode>
						<label class="field-label"><?php esc_html_e( 'CSS mode', 's5' ); ?></label>
						<div class="seg wide" role="group" aria-label="<?php esc_attr_e( 'CSS mode', 's5' ); ?>">
							<button type="button" class="seg-opt active" data-s5-cssmode-btn="semantic" aria-pressed="true"><?php esc_html_e( 'Semantic', 's5' ); ?></button>
							<button type="button" class="seg-opt is-soon" data-s5-cssmode-btn="tailwind" aria-disabled="true" disabled title="<?php esc_attr_e( 'Tailwind mode is on the roadmap — the framework will compile the same authored HTML against a Tailwind build.', 's5' ); ?>"><?php esc_html_e( 'Tailwind', 's5' ); ?> · <?php esc_html_e( 'soon', 's5' ); ?></button>
						</div>
					</div>

					<?php /* GAP: read-only token specimen (proves swatches never drift) — light cards. */ ?>
					<div class="s5e-styletab" data-s5-styletab></div>

					<?php /* GAP: compiled-stylesheet disclosure (read-only). */ ?>
					<div class="s5e-stylesheet">
						<button type="button" class="s5e-stylesheet-toggle" data-s5-stylesheet-toggle aria-expanded="false"><?php esc_html_e( 'View compiled stylesheet', 's5' ); ?></button>
						<pre class="s5e-stylesheet-view" data-s5-stylesheet hidden></pre>
					</div>
				</div>

				<?php /* ADVANCED — tag select, anchor/aria, responsive visibility (rendered by panel.js). */ ?>
				<div class="ptab-panel" data-s5-ppanel="advanced" hidden>
					<div data-s5-advanced>
						<p class="s5e-hint"><?php esc_html_e( 'Tag, anchor, accessibility, and responsive visibility for the selection.', 's5' ); ?></p>
					</div>
				</div>
			</div>
		</aside>

		<?php /* ------------------ CENTER STAGE: edit lens (iframe) ⇄ code lens ------------------ */ ?>
		<main class="stage-wrap" data-s5-stage data-s5-viewport="desktop">
			<div class="stage-scroll" data-s5-lens-edit>
				<?php if ( '' !== $s5_preview_url ) : ?>
					<div class="stage-frame" data-s5-frame>
						<iframe
							class="preview-frame"
							data-s5-preview
							src="<?php echo esc_url( $s5_preview_url ); ?>"
							title="<?php echo esc_attr( $s5_title ); ?>"></iframe>
					</div>
				<?php else : ?>
					<p class="stage-empty"><?php esc_html_e( 'No preview URL available for this page.', 's5' ); ?></p>
				<?php endif; ?>
			</div>

			<?php /* CODE lens — canonical HTML source (same center panel), .codeview. */ ?>
			<div class="codeview" data-s5-lens-code hidden>
				<div class="code-head">
					<span class="title"><?php esc_html_e( 'HTML document · canonical source', 's5' ); ?></span>
					<div class="actions">
						<button type="button" class="button button-primary code-btn is-save" data-s5-code-save disabled><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Save canonical', 's5' ); ?></button>
					</div>
				</div>
				<div class="code-body">
					<label class="screen-reader-text" for="s5-code"><?php esc_html_e( 'Canonical HTML (source of truth)', 's5' ); ?></label>
					<textarea id="s5-code" class="code-area" data-s5-code spellcheck="false"></textarea>
				</div>
				<div class="code-foot">
					<span class="ok"><span class="dot"></span><span data-s5-code-diagnostics><?php esc_html_e( '0 errors', 's5' ); ?></span></span>
					<span class="hidden-count" data-s5-hidden-count hidden><i class="fa-solid fa-ban"></i></span>
					<span style="margin-left:auto;color:var(--s5e-text-faint)"><?php esc_html_e( 'Contract-clean · closed vocabulary validated', 's5' ); ?></span>
				</div>
			</div>
		</main>

		<?php /* ------------------ RIGHT PANEL: STRUCTURE TREE ONLY (HANDOFF §0.6/§6) ------------------ */ ?>
		<aside class="panel right" aria-label="<?php esc_attr_e( 'Page structure', 's5' ); ?>">
			<div class="panel-head">
				<h2><?php esc_html_e( 'Structure', 's5' ); ?></h2>
				<span class="sub"><?php esc_html_e( 'Elements tree', 's5' ); ?></span>
			</div>
			<div class="tree" data-s5-structure>
				<p class="s5e-hint"><?php esc_html_e( 'Loading structure…', 's5' ); ?></p>
			</div>
		</aside>
	</div>

	<?php /* Legacy parked hooks (component-tab machinery) — never visible; keeps componentstab.js from erroring until it moves to the settings Components screen. */ ?>
	<div class="s5e-legacy" data-s5-legacy hidden aria-hidden="true">
		<div data-s5-comp-grid></div>
		<div data-s5-comp-editor hidden>
			<button type="button" data-s5-comp-back></button>
			<span data-s5-comp-name></span>
			<button type="button" data-s5-comp-file="html"></button>
			<button type="button" data-s5-comp-file="css"></button>
			<button type="button" data-s5-comp-file="manifest"></button>
			<textarea data-s5-comp-code></textarea>
			<div data-s5-comp-diagnostics></div>
			<iframe data-s5-comp-preview></iframe>
			<button type="button" data-s5-comp-save></button>
		</div>
	</div>
</div>
