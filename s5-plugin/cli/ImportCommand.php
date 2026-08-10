<?php
/**
 * `wp s5 import` — import a directory of full HTML documents into pages.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5\Cli;

use S5\Compiler;
use S5\HtmlDocument;
use S5\Storage;
use S5\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns source HTML files into real WordPress pages with one command.
 *
 * Step 2 scope (flat directory only, no nesting/CPTs):
 * - Slug = filename without extension (`about.html` -> /about/).
 * - `index.html` -> the site front page (show_on_front=page, page_on_front).
 * - <body> inner HTML -> `_s5_canonical_html`, stored verbatim.
 * - Sanctioned <head> artifacts -> `_s5_head`.
 * - Match by slug and update in place; never duplicate.
 * - Idempotent: if canonical HTML and head blob are unchanged, write nothing.
 * - New pages publish immediately (dev-driven pipeline; no draft workflow yet).
 * - Any parse failure aborts with a nonzero exit, naming file + reason.
 *
 * Step 4 adds the Authoring Contract GATE: every page is validated before it is
 * written. A page with any ERROR (unknown class, illegal inline style,
 * unparseable HTML) is skipped — its diagnostics are printed — while valid pages
 * still import; the command exits nonzero at the end if any page was blocked.
 * WARNINGS print and the page proceeds. There is no --force flag; loud is the
 * point.
 */
final class ImportCommand {

	/**
	 * Import HTML documents from a directory.
	 *
	 * ## OPTIONS
	 *
	 * [<dir>]
	 * : Directory of full HTML documents. Defaults to the `pages/` source dir.
	 *
	 * ## EXAMPLES
	 *
	 *     wp s5 import
	 *     wp s5 import ./pages
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args (unused).
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dir = isset( $args[0] ) ? $args[0] : self::default_dir();
		$dir = rtrim( $dir, '/\\' );

		if ( ! is_dir( $dir ) ) {
			\WP_CLI::error( sprintf( 'Import directory not found: %s', $dir ) );
		}

		$files = glob( $dir . '/*.html' );
		if ( empty( $files ) ) {
			\WP_CLI::warning( sprintf( 'No .html files found in %s', $dir ) );
			return;
		}
		sort( $files );

		// Authoring Contract gate: load the class vocabulary once up front.
		$vocab = ValidateCommand::load_vocabulary_or_die();

		$created   = 0;
		$updated   = 0;
		$unchanged = 0;
		$blocked   = 0;
		$front_id  = 0;

		// Drift trackers: the theme owns charset/viewport, and header/footer are
		// duplicated per page until the Step 8 parts system. Warn (never block) if
		// pages disagree, so silent divergence is caught before it ships.
		$charset_ref  = null;
		$viewport_ref = null;
		$header_ref   = null;
		$footer_ref   = null;
		$ref_file     = '';

		foreach ( $files as $file ) {
			$name = basename( $file );
			$slug = self::slug_from_filename( $name );

			$html = file_get_contents( $file );
			if ( false === $html ) {
				\WP_CLI::error( sprintf( '%s: could not read file', $name ) );
			}

			// GATE: validate before writing. Errors block THIS page; import
			// continues for the rest. Warnings print and the page proceeds.
			$findings = Validator::validate( (string) $html, $vocab, $name );
			if ( ! empty( $findings ) ) {
				ValidateCommand::print_table( $findings );
			}
			if ( Validator::has_errors( $findings ) ) {
				++$blocked;
				\WP_CLI::warning( sprintf( '%-12s %s  (blocked by Authoring Contract errors; not written)', 'blocked', $name ) );
				continue;
			}

			try {
				$doc = HtmlDocument::parse( (string) $html );
			} catch ( \RuntimeException $e ) {
				// Nonzero exit, naming file + reason.
				\WP_CLI::error( sprintf( '%s: parse failed — %s', $name, $e->getMessage() ) );
			}

			// Drift warnings (non-blocking): charset/viewport (theme-owned) and the
			// duplicated header/footer markup must match across the corpus.
			self::warn_on_drift( $name, $doc, $ref_file, $charset_ref, $viewport_ref, $header_ref, $footer_ref );

			$title  = isset( $doc->head['title'] ) ? (string) $doc->head['title'] : $slug;
			$result = self::upsert_page( $slug, $title, $doc->body_html, $doc->head );

			$post_id = $result['id'];
			switch ( $result['status'] ) {
				case 'created':
					++$created;
					break;
				case 'updated':
					++$updated;
					break;
				case 'blocked':
					// Compile/persist failure (e.g. unresolvable media) already
					// warned with file + reason; block this page, keep importing.
					++$blocked;
					continue 2;
				default:
					++$unchanged;
					break;
			}

			\WP_CLI::log( sprintf( '%-12s %s  (#%d, /%s/)', $result['status'], $name, $post_id, $slug ) );

			foreach ( $doc->unrecognized as $note ) {
				\WP_CLI::warning( sprintf( '  %s: unrecognized head element not stored — %s', $name, $note ) );
			}

			if ( 'index.html' === $name ) {
				$front_id = $post_id;
			}
		}

		if ( $front_id ) {
			self::set_front_page( $front_id );
			\WP_CLI::log( sprintf( 'front page set to #%d (index.html)', $front_id ) );
		}

		if ( $blocked > 0 ) {
			// Some pages were valid and imported; others were blocked (Authoring
			// Contract errors or compile failures such as unresolvable media).
			// Exit nonzero so the failure is loud, but keep the valid work.
			\WP_CLI::error(
				sprintf(
					'Imported %d file(s): %d created, %d updated, %d unchanged; %d blocked (Authoring Contract or compile errors).',
					count( $files ) - $blocked,
					$created,
					$updated,
					$unchanged,
					$blocked
				)
			);
		}

		\WP_CLI::success(
			sprintf( 'Imported %d file(s): %d created, %d updated, %d unchanged.', count( $files ), $created, $updated, $unchanged )
		);
	}

	/**
	 * Warn (never block) when a page drifts from the corpus on values that are
	 * expected to be uniform: the theme-owned charset/viewport, and the header /
	 * footer markup duplicated per page until the Step 8 parts system.
	 *
	 * The first page seen sets the reference; later pages are compared to it.
	 *
	 * @param string       $name         Current file name.
	 * @param HtmlDocument $doc          Parsed document.
	 * @param string       $ref_file     Reference file name (set on first page). By ref.
	 * @param string|null  $charset_ref  Reference charset. By ref.
	 * @param string|null  $viewport_ref Reference viewport. By ref.
	 * @param string|null  $header_ref   Reference <header> markup. By ref.
	 * @param string|null  $footer_ref   Reference <footer> markup. By ref.
	 * @return void
	 */
	private static function warn_on_drift(
		string $name,
		HtmlDocument $doc,
		string &$ref_file,
		?string &$charset_ref,
		?string &$viewport_ref,
		?string &$header_ref,
		?string &$footer_ref
	): void {
		$charset  = isset( $doc->head['charset'] ) ? (string) $doc->head['charset'] : '';
		$viewport = isset( $doc->head['viewport'] ) ? (string) $doc->head['viewport'] : '';
		$header   = self::first_tag_block( $doc->body_html, 'header' );
		$footer   = self::first_tag_block( $doc->body_html, 'footer' );

		if ( '' === $ref_file ) {
			$ref_file     = $name;
			$charset_ref  = $charset;
			$viewport_ref = $viewport;
			$header_ref   = $header;
			$footer_ref   = $footer;
			return;
		}

		if ( $charset !== $charset_ref ) {
			\WP_CLI::warning( sprintf( '  %s: charset "%s" drifts from %s "%s"', $name, $charset, $ref_file, (string) $charset_ref ) );
		}
		if ( $viewport !== $viewport_ref ) {
			\WP_CLI::warning( sprintf( '  %s: viewport "%s" drifts from %s "%s"', $name, $viewport, $ref_file, (string) $viewport_ref ) );
		}
		if ( $header !== $header_ref ) {
			\WP_CLI::warning( sprintf( '  %s: <header> markup drifts from %s (duplicated header/footer; unify in the Step 8 parts system)', $name, $ref_file ) );
		}
		if ( $footer !== $footer_ref ) {
			\WP_CLI::warning( sprintf( '  %s: <footer> markup drifts from %s (duplicated header/footer; unify in the Step 8 parts system)', $name, $ref_file ) );
		}
	}

	/**
	 * The verbatim source of the first `<$tag>…</$tag>` block in $html, or ''.
	 *
	 * Byte-substring extraction (not DOM) so the comparison is exact.
	 *
	 * @param string $html Body HTML.
	 * @param string $tag  Tag name (header|footer).
	 * @return string
	 */
	private static function first_tag_block( string $html, string $tag ): string {
		if ( ! preg_match( '/<' . $tag . '\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$start = (int) $m[0][1];
		$close = stripos( $html, '</' . $tag . '>', $start );
		if ( false === $close ) {
			return '';
		}
		return substr( $html, $start, $close - $start + strlen( '</' . $tag . '>' ) );
	}

	/**
	 * Default source directory: the plugin's parent repo `pages/` dir.
	 *
	 * The s5-plugin dir lives at <repo>/s5-plugin, so the source is one level up.
	 *
	 * @return string
	 */
	private static function default_dir(): string {
		return dirname( S5_PLUGIN_DIR ) . '/pages';
	}

	/**
	 * Slug from a filename (strip the .html extension).
	 *
	 * @param string $name Filename, e.g. `fix-my-store.html`.
	 * @return string Slug, e.g. `fix-my-store`.
	 */
	private static function slug_from_filename( string $name ): string {
		return preg_replace( '/\.html$/i', '', $name );
	}

	/**
	 * Create or update a page by slug, writing canonical HTML + head blob only
	 * when they differ. Never duplicates.
	 *
	 * @param string               $slug  Page slug.
	 * @param string               $title Page title.
	 * @param string               $body  Verbatim body fragment.
	 * @param array<string, mixed> $head  Extracted head blob.
	 * @return array{id:int,status:string} Result.
	 */
	private static function upsert_page( string $slug, string $title, string $body, array $head ): array {
		$existing = get_page_by_path( $slug, OBJECT, 'page' );

		if ( $existing instanceof \WP_Post ) {
			$post_id = (int) $existing->ID;

			$cur_body = Storage::get_canonical_html( $post_id );
			$cur_head = Storage::get_head( $post_id );

			// A framework page must be published to serve. An existing page may be
			// a draft — notably WordPress ships a default "Privacy Policy" draft at
			// slug `privacy-policy` — so importing over it must PUBLISH it (and sync
			// the title). Status/title mismatch counts as a change, so an unpublished
			// page is never reported "unchanged" and left dark.
			$status_ok = ( 'publish' === $existing->post_status );
			$title_ok  = ( $title === $existing->post_title );
			$body_same = ( $cur_body === $body );
			$head_same = ( wp_json_encode( $cur_head ) === wp_json_encode( $head ) );

			if ( $body_same && $head_same && $status_ok && $title_ok ) {
				return array(
					'id'     => $post_id,
					'status' => 'unchanged',
				);
			}

			if ( ! $status_ok || ! $title_ok ) {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'publish',
						'post_title'  => $title,
					),
					true
				);
			}

			update_post_meta( $post_id, Storage::HEAD_KEY, $head );
			// Atomically store canonical + compile derived render + write mirror.
			// A compile error (e.g. unresolvable media) blocks THIS page loudly
			// but does not abort the whole import; the existing page is left as
			// it was (persist_page rolls its own state back).
			try {
				Compiler::persist_page( $post_id, $body );
			} catch ( \RuntimeException $e ) {
				\WP_CLI::warning( sprintf( '%s: compile/persist failed — %s', $slug, $e->getMessage() ) );
				return array(
					'id'     => $post_id,
					'status' => 'blocked',
				);
			}

			return array(
				'id'     => $post_id,
				'status' => 'updated',
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => '', // Canonical source lives in meta; post_content mirror is a later step.
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			\WP_CLI::error( sprintf( '%s: could not create page — %s', $slug, $post_id->get_error_message() ) );
		}

		$post_id = (int) $post_id;
		update_post_meta( $post_id, Storage::HEAD_KEY, $head );
		// Atomically store canonical + compile derived render + write mirror. If
		// compilation fails on a JUST-CREATED page (e.g. unresolvable media), the
		// page has no canonical/render yet — delete it so the failed import leaves
		// NO orphan page behind, then report it blocked (loud, import continues).
		try {
			Compiler::persist_page( $post_id, $body );
		} catch ( \RuntimeException $e ) {
			wp_delete_post( $post_id, true );
			\WP_CLI::warning( sprintf( '%s: compile/persist failed — %s', $slug, $e->getMessage() ) );
			return array(
				'id'     => 0,
				'status' => 'blocked',
			);
		}

		return array(
			'id'     => $post_id,
			'status' => 'created',
		);
	}

	/**
	 * Point the site front page at a page.
	 *
	 * @param int $post_id Page ID.
	 * @return void
	 */
	private static function set_front_page( int $post_id ): void {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $post_id );
	}
}
