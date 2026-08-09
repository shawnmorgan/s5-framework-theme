<?php
/**
 * `wp s5 import` — import a directory of full HTML documents into pages.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5\Cli;

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
			// Some pages were valid and imported; others were blocked. Exit
			// nonzero so the failure is loud, but keep the valid work.
			\WP_CLI::error(
				sprintf(
					'Imported %d file(s): %d created, %d updated, %d unchanged; %d blocked by Authoring Contract errors.',
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

			$body_same = ( $cur_body === $body );
			$head_same = ( wp_json_encode( $cur_head ) === wp_json_encode( $head ) );

			if ( $body_same && $head_same ) {
				return array(
					'id'     => $post_id,
					'status' => 'unchanged',
				);
			}

			update_post_meta( $post_id, Storage::META_KEY, $body );
			update_post_meta( $post_id, Storage::HEAD_KEY, $head );

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
		update_post_meta( $post_id, Storage::META_KEY, $body );
		update_post_meta( $post_id, Storage::HEAD_KEY, $head );

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
