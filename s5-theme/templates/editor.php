<?php
/**
 * S5 Framework — editor admin screen template (Editor MVP shell, Phase B1).
 *
 * Rendered by Editor::render_screen(). Expects $s5_editor:
 *   - post_id     int
 *   - title       string
 *   - preview_url string  (canvas permalink + ?s5-preview=1)
 *   - back_url    string  (classic edit-post link)
 *
 * This item ships the SHELL only: top bar (back · viewport toggle · Edit/Code
 * toggle · dirty indicator · Update) and the live preview iframe. The right
 * panel, code lens, style tab, and REST save wire in through later Phase B items.
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
?>
<div class="s5-editor" data-s5-editor>

	<div class="s5-editor__topbar">
		<div class="s5-editor__topbar-group">
			<a class="s5-editor__back" href="<?php echo esc_url( $s5_back_url ); ?>">
				<span aria-hidden="true">&larr;</span>
				<?php esc_html_e( 'WordPress', 's5' ); ?>
			</a>
			<span class="s5-editor__page-title"><?php echo esc_html( $s5_title ); ?></span>
		</div>

		<div class="s5-editor__topbar-group s5-editor__topbar-group--center">
			<div class="s5-editor__viewport" role="group" aria-label="<?php esc_attr_e( 'Preview width', 's5' ); ?>">
				<button type="button" class="s5-editor__vp" data-s5-viewport="mobile" aria-pressed="false"><?php esc_html_e( 'Mobile', 's5' ); ?></button>
				<button type="button" class="s5-editor__vp" data-s5-viewport="tablet" aria-pressed="false"><?php esc_html_e( 'Tablet', 's5' ); ?></button>
				<button type="button" class="s5-editor__vp is-active" data-s5-viewport="desktop" aria-pressed="true"><?php esc_html_e( 'Desktop', 's5' ); ?></button>
			</div>

			<div class="s5-editor__lens" role="group" aria-label="<?php esc_attr_e( 'Editing lens', 's5' ); ?>">
				<button type="button" class="s5-editor__lens-btn is-active" data-s5-lens="edit" aria-pressed="true"><?php esc_html_e( 'Edit', 's5' ); ?></button>
				<button type="button" class="s5-editor__lens-btn" data-s5-lens="code" aria-pressed="false"><?php esc_html_e( 'Code', 's5' ); ?></button>
			</div>
		</div>

		<div class="s5-editor__topbar-group s5-editor__topbar-group--right">
			<span class="s5-editor__dirty" data-s5-dirty hidden>
				<span class="s5-editor__dirty-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Unsaved changes', 's5' ); ?>
			</span>
			<button type="button" class="button button-primary s5-editor__update" data-s5-update disabled>
				<?php esc_html_e( 'Update', 's5' ); ?>
			</button>
		</div>
	</div>

	<div class="s5-editor__body">
		<aside class="s5-editor__leftpanel" data-s5-leftpanel aria-label="<?php esc_attr_e( 'Components palette', 's5' ); ?>">
			<div class="s5-editor__leftpanel-head"><?php esc_html_e( 'Components', 's5' ); ?></div>
			<div class="s5-editor__palette" data-s5-palette>
				<p class="s5-editor__hint"><?php esc_html_e( 'Loading components…', 's5' ); ?></p>
			</div>
			<p class="s5-editor__palette-hint" data-s5-palette-hint hidden>
				<?php esc_html_e( 'Click to append, or drag onto an element to insert after it.', 's5' ); ?>
			</p>
		</aside>

		<div class="s5-editor__stage" data-s5-stage data-s5-viewport="desktop">
			<?php if ( '' !== $s5_preview_url ) : ?>
				<iframe
					class="s5-editor__preview"
					data-s5-preview
					src="<?php echo esc_url( $s5_preview_url ); ?>"
					title="<?php echo esc_attr( $s5_title ); ?>"></iframe>
			<?php else : ?>
				<p class="s5-editor__empty"><?php esc_html_e( 'No preview URL available for this page.', 's5' ); ?></p>
			<?php endif; ?>
		</div>

		<aside class="s5-editor__panel" data-s5-panel>
			<div class="s5-editor__panel-tabs" role="tablist">
				<button type="button" class="s5-editor__tab is-active" data-s5-tab="content" role="tab" aria-selected="true"><?php esc_html_e( 'Content', 's5' ); ?></button>
				<button type="button" class="s5-editor__tab" data-s5-tab="style" role="tab" aria-selected="false"><?php esc_html_e( 'Style', 's5' ); ?></button>
				<button type="button" class="s5-editor__tab" data-s5-tab="components" role="tab" aria-selected="false"><?php esc_html_e( 'Components', 's5' ); ?></button>
				<button type="button" class="s5-editor__tab is-disabled" data-s5-tab="tailwind" role="tab" aria-disabled="true" disabled><?php esc_html_e( 'Tailwind', 's5' ); ?></button>
			</div>

			<div class="s5-editor__tabpane" data-s5-tabpane="content">
			<div class="s5-editor__structure" data-s5-lens-edit>
				<div class="s5-editor__structure-head"><?php esc_html_e( 'Page structure', 's5' ); ?></div>
				<ul class="s5-editor__structure-list" data-s5-structure>
					<li class="s5-editor__hint"><?php esc_html_e( 'Loading structure…', 's5' ); ?></li>
				</ul>
			</div>
			<div class="s5-editor__panel-body" data-s5-panel-body data-s5-lens-edit>
				<p class="s5-editor__hint" data-s5-hint>
					<?php esc_html_e( 'Select an editable element in the preview — or in the structure list — to see its fields.', 's5' ); ?>
				</p>
			</div>

			<div class="s5-editor__panel-body s5-editor__codelens" data-s5-lens-code hidden>
				<label class="s5-editor__field-label" for="s5-code"><?php esc_html_e( 'Canonical HTML (source of truth)', 's5' ); ?></label>
				<textarea id="s5-code" class="s5-editor__code" data-s5-code spellcheck="false"></textarea>
				<div class="s5-editor__code-diagnostics" data-s5-code-diagnostics></div>
				<button type="button" class="button button-primary s5-editor__code-save" data-s5-code-save disabled>
					<?php esc_html_e( 'Save canonical', 's5' ); ?>
				</button>

				<hr class="s5-editor__rule">

				<div class="s5-editor__headform" data-s5-headform>
					<div class="s5-editor__field-label"><?php esc_html_e( 'Head', 's5' ); ?></div>
					<label class="s5-editor__field-label" for="s5-head-title"><?php esc_html_e( 'Title', 's5' ); ?></label>
					<input id="s5-head-title" class="s5-editor__input" type="text" data-s5-head="title">
					<label class="s5-editor__field-label" for="s5-head-desc"><?php esc_html_e( 'Meta description', 's5' ); ?></label>
					<textarea id="s5-head-desc" class="s5-editor__input" rows="2" data-s5-head="description"></textarea>
					<label class="s5-editor__field-label" for="s5-head-canonical"><?php esc_html_e( 'Canonical URL', 's5' ); ?></label>
					<input id="s5-head-canonical" class="s5-editor__input" type="url" data-s5-head="canonical">
					<button type="button" class="button s5-editor__head-save" data-s5-head-save disabled>
						<?php esc_html_e( 'Save head', 's5' ); ?>
					</button>
				</div>
			</div>
			</div><!-- /tabpane content -->

			<div class="s5-editor__tabpane s5-editor__styletab" data-s5-tabpane="style" hidden>
				<div class="s5-editor__cssmode" data-s5-cssmode>
					<div class="s5-editor__field-label"><?php esc_html_e( 'CSS mode', 's5' ); ?></div>
					<div class="s5-editor__cssmode-switch" role="group" aria-label="<?php esc_attr_e( 'CSS mode', 's5' ); ?>">
						<button type="button" class="s5-editor__cssmode-btn is-active" data-s5-cssmode-btn="semantic" aria-pressed="true"><?php esc_html_e( 'Semantic', 's5' ); ?> <span class="s5-editor__cssmode-badge"><?php esc_html_e( 'active', 's5' ); ?></span></button>
						<button type="button" class="s5-editor__cssmode-btn is-soon" data-s5-cssmode-btn="tailwind" aria-disabled="true" disabled title="<?php esc_attr_e( 'Tailwind mode is on the roadmap — the framework will compile the same authored HTML against a Tailwind build without changing your pages.', 's5' ); ?>"><?php esc_html_e( 'Tailwind', 's5' ); ?> <span class="s5-editor__cssmode-badge"><?php esc_html_e( 'coming soon', 's5' ); ?></span></button>
					</div>
				</div>

				<div class="s5-editor__panel-body" data-s5-styletab>
					<p class="s5-editor__hint"><?php esc_html_e( 'Read-only. Rendered live from the theme design tokens.', 's5' ); ?></p>
				</div>

				<div class="s5-editor__stylesheet">
					<button type="button" class="s5-editor__stylesheet-toggle" data-s5-stylesheet-toggle aria-expanded="false"><?php esc_html_e( 'View compiled stylesheet', 's5' ); ?></button>
					<pre class="s5-editor__stylesheet-view" data-s5-stylesheet hidden></pre>
				</div>
			</div>

			<div class="s5-editor__tabpane s5-editor__componentstab" data-s5-tabpane="components" hidden>
				<div class="s5-editor__comp-grid" data-s5-comp-grid>
					<p class="s5-editor__hint"><?php esc_html_e( 'Loading components…', 's5' ); ?></p>
				</div>
				<div class="s5-editor__comp-editor" data-s5-comp-editor hidden>
					<div class="s5-editor__comp-editor-head">
						<button type="button" class="s5-editor__comp-back" data-s5-comp-back>&larr; <?php esc_html_e( 'All components', 's5' ); ?></button>
						<span class="s5-editor__comp-name" data-s5-comp-name></span>
					</div>
					<div class="s5-editor__comp-files" role="tablist">
						<button type="button" class="s5-editor__comp-file is-active" data-s5-comp-file="html">component.html</button>
						<button type="button" class="s5-editor__comp-file" data-s5-comp-file="css">style.css</button>
						<button type="button" class="s5-editor__comp-file" data-s5-comp-file="manifest">manifest.json</button>
					</div>
					<textarea class="s5-editor__comp-code" data-s5-comp-code spellcheck="false"></textarea>
					<div class="s5-editor__comp-diagnostics" data-s5-comp-diagnostics></div>
					<div class="s5-editor__comp-preview-wrap">
						<div class="s5-editor__field-label"><?php esc_html_e( 'Live preview', 's5' ); ?></div>
						<iframe class="s5-editor__comp-preview" data-s5-comp-preview title="<?php esc_attr_e( 'Component preview', 's5' ); ?>"></iframe>
					</div>
					<button type="button" class="button button-primary s5-editor__comp-save" data-s5-comp-save><?php esc_html_e( 'Save component', 's5' ); ?></button>
				</div>
			</div>
		</aside>
	</div>
</div>
