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

	<?php /* ======================= TOP TOOLBAR ======================= */ ?>
	<header class="s5-editor__tb">
		<div class="s5-editor__tb-group">
			<a class="s5-editor__logo" href="<?php echo esc_url( $s5_back_url ); ?>" title="<?php esc_attr_e( 'Back to WordPress', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Back to WordPress', 's5' ); ?>">S5</a>
			<button type="button" class="s5-editor__tb-icon" data-s5-page-settings title="<?php esc_attr_e( 'Page settings', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Page settings', 's5' ); ?>">
				<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
			</button>
			<div class="s5-editor__tools-wrap">
				<button type="button" class="s5-editor__tb-tools" data-s5-tools-btn aria-expanded="false">
					<?php esc_html_e( 'Tools', 's5' ); ?> <span class="s5-editor__caret" aria-hidden="true">&#9662;</span>
				</button>
				<div class="s5-editor__tools-menu" data-s5-tools-menu hidden>
					<a class="s5-editor__tools-item" href="<?php echo esc_url( $s5_settings_components_url ); ?>">
						<span class="s5-editor__ti-label"><?php esc_html_e( 'Components', 's5' ); ?></span>
						<span class="s5-editor__ti-note"><?php esc_html_e( 'registered library', 's5' ); ?></span>
					</a>
					<a class="s5-editor__tools-item" href="<?php echo esc_url( $s5_settings_style_url ); ?>">
						<span class="s5-editor__ti-label"><?php esc_html_e( 'Styleguide', 's5' ); ?></span>
						<span class="s5-editor__ti-note"><?php esc_html_e( 'tokens & scales', 's5' ); ?></span>
					</a>
					<a class="s5-editor__tools-item" href="<?php echo esc_url( $s5_settings_css_url ); ?>">
						<span class="s5-editor__ti-label"><?php esc_html_e( 'CSS / Tailwind', 's5' ); ?></span>
						<span class="s5-editor__ti-note"><?php esc_html_e( 'source layers', 's5' ); ?></span>
					</a>
				</div>
			</div>
		</div>

		<div class="s5-editor__tb-group s5-editor__tb-group--center">
			<div class="s5-editor__viewport" role="group" aria-label="<?php esc_attr_e( 'Preview width', 's5' ); ?>">
				<button type="button" class="s5-editor__vp is-active" data-s5-viewport="desktop" aria-pressed="true"><?php esc_html_e( 'All', 's5' ); ?></button>
				<button type="button" class="s5-editor__vp" data-s5-viewport="tablet" aria-pressed="false"><?php esc_html_e( '768–991', 's5' ); ?></button>
				<button type="button" class="s5-editor__vp" data-s5-viewport="mobile" aria-pressed="false"><?php esc_html_e( '≤767', 's5' ); ?></button>
			</div>

			<div class="s5-editor__lens" role="group" aria-label="<?php esc_attr_e( 'Editing lens', 's5' ); ?>">
				<button type="button" class="s5-editor__lens-btn is-active" data-s5-lens="edit" aria-pressed="true"><?php esc_html_e( 'Edit', 's5' ); ?></button>
				<button type="button" class="s5-editor__lens-btn" data-s5-lens="code" aria-pressed="false"><?php esc_html_e( 'Code', 's5' ); ?></button>
			</div>
		</div>

		<div class="s5-editor__tb-group s5-editor__tb-group--right">
			<button type="button" class="s5-editor__tb-icon" data-s5-undo disabled title="<?php esc_attr_e( 'Undo', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Undo', 's5' ); ?>">
				<span class="dashicons dashicons-undo" aria-hidden="true"></span>
			</button>
			<button type="button" class="s5-editor__tb-icon" data-s5-redo disabled title="<?php esc_attr_e( 'Redo', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Redo', 's5' ); ?>">
				<span class="dashicons dashicons-redo" aria-hidden="true"></span>
			</button>
			<span class="s5-editor__tb-sep" aria-hidden="true"></span>
			<a class="s5-editor__tb-text" href="<?php echo esc_url( $s5_preview_url ); ?>" target="_blank" rel="noopener">
				<span class="dashicons dashicons-visibility" aria-hidden="true"></span> <?php esc_html_e( 'View', 's5' ); ?>
			</a>
			<span class="s5-editor__dirty" data-s5-dirty hidden>
				<span class="s5-editor__dirty-dot" aria-hidden="true"></span>
			</span>
			<button type="button" class="button button-primary s5-editor__save" data-s5-update disabled>
				<?php esc_html_e( 'Save', 's5' ); ?>
			</button>
		</div>
	</header>

	<?php require __DIR__ . '/editor-drawers.php'; ?>

	<?php /* ======================= BODY: left ⇄ stage ⇄ right ======================= */ ?>
	<div class="s5-editor__body">

		<?php /* ---------- LEFT PANEL: palette ⇄ props ---------- */ ?>
		<aside class="s5-editor__left" data-s5-leftpanel aria-label="<?php esc_attr_e( 'Components and properties', 's5' ); ?>">
			<div class="s5-editor__panel-head">
				<h2 class="s5-editor__panel-title" data-s5-left-title><?php esc_html_e( 'Add', 's5' ); ?></h2>
				<span class="s5-editor__panel-sub" data-s5-left-sub><?php esc_html_e( 'Registered components · drag to place', 's5' ); ?></span>
			</div>

			<?php /* Palette (default). Populated by palette.js from the registered-component registry. */ ?>
			<div class="s5-editor__palette" data-s5-palette>
				<p class="s5-editor__palette-intro"><?php esc_html_e( 'These are the only elements you can add — every one is a registered, contract-valid component. Need something new? Ask us to register it.', 's5' ); ?></p>
				<p class="s5-editor__hint"><?php esc_html_e( 'Loading components…', 's5' ); ?></p>
			</div>
			<p class="s5-editor__palette-hint" data-s5-palette-hint hidden>
				<?php esc_html_e( 'Click to append, or drag onto an element to insert after it.', 's5' ); ?>
			</p>

			<?php /* Props editor (on selection). palette hides; this shows. */ ?>
			<div class="s5-editor__props" data-s5-props hidden>
				<div class="s5-editor__props-crumb">
					<button type="button" class="s5-editor__props-back" data-s5-props-back title="<?php esc_attr_e( 'Back to components', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Back to components', 's5' ); ?>">&larr;</button>
					<span class="s5-editor__props-name" data-s5-props-name><?php esc_html_e( 'Element', 's5' ); ?></span>
					<span class="s5-editor__props-tag" data-s5-props-tag></span>
				</div>

				<div class="s5-editor__prop-tabs" role="tablist">
					<button type="button" class="s5-editor__prop-tab is-active" data-s5-ptab="content" role="tab" aria-selected="true"><?php esc_html_e( 'Content', 's5' ); ?></button>
					<button type="button" class="s5-editor__prop-tab" data-s5-ptab="style" role="tab" aria-selected="false"><?php esc_html_e( 'Style', 's5' ); ?></button>
					<button type="button" class="s5-editor__prop-tab" data-s5-ptab="advanced" role="tab" aria-selected="false"><?php esc_html_e( 'Advanced', 's5' ); ?></button>
				</div>

				<?php /* CONTENT — per-type fields rendered by panel.js into data-s5-content. */ ?>
				<div class="s5-editor__ptab-panel is-active" data-s5-ppanel="content">
					<?php /* data-s5-panel-body alias kept so panel.js renders here until it is rewired to data-s5-content in the selection chunk. */ ?>
					<div data-s5-content data-s5-panel-body>
						<p class="s5-editor__hint" data-s5-hint><?php esc_html_e( 'Select an editable element to see its fields.', 's5' ); ?></p>
					</div>
				</div>

				<?php /* STYLE — component variant + t-shirt sizing + theme-role color. No CSS. (R3) */ ?>
				<div class="s5-editor__ptab-panel" data-s5-ppanel="style" hidden>
					<div data-s5-style-controls>
						<p class="s5-editor__hint"><?php esc_html_e( 'Select an element to adjust its variant, size, spacing and role color.', 's5' ); ?></p>
					</div>
					<?php /* Live design-token specimen + compiled stylesheet (relocated from the old Style tab). */ ?>
					<div class="s5-editor__styletab" data-s5-styletab hidden></div>
				</div>

				<?php /* ADVANCED — tag select, anchor/aria, responsive visibility (ships in DOM, R4). */ ?>
				<div class="s5-editor__ptab-panel" data-s5-ppanel="advanced" hidden>
					<div data-s5-advanced>
						<p class="s5-editor__hint"><?php esc_html_e( 'Tag, anchor, accessibility, and responsive visibility for the selection.', 's5' ); ?></p>
					</div>
				</div>
			</div>
		</aside>

		<?php /* ---------- CENTER STAGE: edit lens (iframe) ⇄ code lens ---------- */ ?>
		<main class="s5-editor__stage" data-s5-stage data-s5-viewport="desktop">
			<div class="s5-editor__stage-scroll" data-s5-lens-edit>
				<?php if ( '' !== $s5_preview_url ) : ?>
					<div class="s5-editor__frame" data-s5-frame>
						<iframe
							class="s5-editor__preview"
							data-s5-preview
							src="<?php echo esc_url( $s5_preview_url ); ?>"
							title="<?php echo esc_attr( $s5_title ); ?>"></iframe>
					</div>
				<?php else : ?>
					<p class="s5-editor__empty"><?php esc_html_e( 'No preview URL available for this page.', 's5' ); ?></p>
				<?php endif; ?>
			</div>

			<?php /* CODE lens — canonical HTML source (same center panel). */ ?>
			<div class="s5-editor__codeview" data-s5-lens-code hidden>
				<div class="s5-editor__code-head">
					<span class="s5-editor__code-title"><?php esc_html_e( 'HTML document · canonical source', 's5' ); ?></span>
				</div>
				<div class="s5-editor__code-body">
					<label class="screen-reader-text" for="s5-code"><?php esc_html_e( 'Canonical HTML (source of truth)', 's5' ); ?></label>
					<textarea id="s5-code" class="s5-editor__code" data-s5-code spellcheck="false"></textarea>
				</div>
				<div class="s5-editor__code-foot">
					<div class="s5-editor__code-diagnostics" data-s5-code-diagnostics></div>
					<span class="s5-editor__hidden-count" data-s5-hidden-count hidden></span>
					<button type="button" class="button button-primary s5-editor__code-save" data-s5-code-save disabled>
						<?php esc_html_e( 'Save canonical', 's5' ); ?>
					</button>
				</div>
			</div>
		</main>

		<?php /* ---------- RIGHT PANEL: STRUCTURE TREE ONLY (HANDOFF §0.6/§6) ---------- */ ?>
		<aside class="s5-editor__right" aria-label="<?php esc_attr_e( 'Page structure', 's5' ); ?>">
			<div class="s5-editor__panel-head">
				<h2 class="s5-editor__panel-title"><?php esc_html_e( 'Structure', 's5' ); ?></h2>
				<span class="s5-editor__panel-sub"><?php esc_html_e( 'Elements tree', 's5' ); ?></span>
			</div>
			<ul class="s5-editor__tree" data-s5-structure>
				<li class="s5-editor__hint"><?php esc_html_e( 'Loading structure…', 's5' ); ?></li>
			</ul>
		</aside>
	</div>

	<?php
	/*
	 * Head form (SEO title / description / canonical) + Components tab machinery move
	 * into the gear Page-Settings drawer and the settings Components screen in Phase C.
	 * Until those land, keep the hooks present-but-hidden so head save + component JS
	 * do not error. They carry the same data-s5-* attributes their handlers expect.
	 */
	?>
	<div class="s5-editor__legacy-hooks" data-s5-legacy hidden aria-hidden="true">
		<div data-s5-headform>
			<input type="text" data-s5-head="title">
			<textarea data-s5-head="description"></textarea>
			<input type="url" data-s5-head="canonical">
			<button type="button" data-s5-head-save disabled></button>
		</div>
		<div data-s5-stylesheet-wrap>
			<button type="button" data-s5-stylesheet-toggle aria-expanded="false"></button>
			<pre data-s5-stylesheet hidden></pre>
		</div>
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
