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
				<button type="button" class="s5-editor__tab is-disabled" data-s5-tab="components" role="tab" aria-disabled="true" disabled><?php esc_html_e( 'Components', 's5' ); ?></button>
				<button type="button" class="s5-editor__tab is-disabled" data-s5-tab="tailwind" role="tab" aria-disabled="true" disabled><?php esc_html_e( 'Tailwind', 's5' ); ?></button>
			</div>

			<div class="s5-editor__panel-body" data-s5-panel-body>
				<p class="s5-editor__hint" data-s5-hint>
					<?php esc_html_e( 'Select an editable element in the preview to see its fields.', 's5' ); ?>
				</p>
			</div>
		</aside>
	</div>
</div>
