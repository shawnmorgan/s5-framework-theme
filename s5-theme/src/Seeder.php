<?php
/**
 * S5 Framework — UI-facing seeder + page factory (no WP-CLI required).
 *
 * This is the browser/no-CLI counterpart to the `wp s5 import` command. A theme
 * uploaded and activated from the WordPress admin has no WP-CLI, so the demo
 * corpus and "make a new S5 page" flow must be reachable from PHP alone.
 *
 * It reuses the SAME building blocks as the importer — {@see Validator},
 * {@see HtmlDocument}, {@see Compiler::persist_page} (the ONE save door),
 * {@see StyleBundle} — but returns plain arrays instead of emitting WP-CLI
 * output, so it is safe to call from an activation hook or an admin request.
 *
 * @package S5
 */

declare(strict_types=1);

namespace S5;

/**
 * Seeds the demo corpus and creates new framework pages, CLI-free.
 */
final class Seeder {

	/**
	 * Option flag: set once the demo corpus has been seeded, so activation never
	 * re-imports over a user's edits.
	 */
	public const SEEDED_OPTION = 's5_demo_seeded';

	/**
	 * The shipped, in-theme corpus directory (banner-free demo pages).
	 *
	 * Unlike the importer's {@see ValidateCommand::pages_root()} (which resolves
	 * a wp-env mapping), the shipped corpus travels INSIDE the theme so a plain
	 * uploaded-and-activated theme can seed it.
	 *
	 * @return string Absolute path to the corpus directory.
	 */
	public static function corpus_dir(): string {
		return rtrim( (string) get_stylesheet_directory(), '/' ) . '/site/pages';
	}

	/**
	 * Import the demo corpus once, on first activation.
	 *
	 * Idempotent by option flag AND by content (upsert-by-slug), so re-running is
	 * safe. Never blocks activation: individual page failures are collected and
	 * returned, not thrown.
	 *
	 * @param bool $force Re-seed even if the flag is set (used by a manual re-seed).
	 * @return array{seeded:bool,created:int,updated:int,unchanged:int,blocked:int,front:int,messages:array<int,string>}
	 */
	public static function seed_demo_corpus( bool $force = false ): array {
		$result = array(
			'seeded'    => false,
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'blocked'   => 0,
			'front'     => 0,
			'messages'  => array(),
		);

		if ( ! $force && get_option( self::SEEDED_OPTION ) ) {
			$result['messages'][] = 'Demo corpus already seeded; skipping.';
			return $result;
		}

		$dir = self::corpus_dir();
		if ( ! is_dir( $dir ) ) {
			$result['messages'][] = 'Corpus directory not found: ' . $dir;
			return $result;
		}

		$files = glob( $dir . '/*.html' );
		if ( empty( $files ) ) {
			$result['messages'][] = 'No .html files in corpus directory.';
			return $result;
		}
		sort( $files );

		$vocab = self::load_vocabulary();

		foreach ( $files as $file ) {
			$name = basename( $file );
			$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled theme file, not a remote URL (same as the CLI importer).
			if ( false === $html ) {
				++$result['blocked'];
				$result['messages'][] = $name . ': could not read file.';
				continue;
			}

			// Same Authoring-Contract gate the importer runs. Errors block THIS
			// page; seeding continues for the rest.
			$findings = Validator::validate( (string) $html, $vocab, $name );
			if ( Validator::has_errors( $findings ) ) {
				++$result['blocked'];
				$result['messages'][] = $name . ': blocked by Authoring Contract errors.';
				continue;
			}

			try {
				$doc = HtmlDocument::parse( (string) $html );
			} catch ( \RuntimeException $e ) {
				++$result['blocked'];
				$result['messages'][] = $name . ': parse failed — ' . $e->getMessage();
				continue;
			}

			$slug  = preg_replace( '/\.html$/i', '', $name );
			$title = isset( $doc->head['title'] ) ? (string) $doc->head['title'] : $slug;

			$upsert = self::upsert_page( $slug, $title, $doc->body_html, $doc->head );
			switch ( $upsert['status'] ) {
				case 'created':
					++$result['created'];
					break;
				case 'updated':
					++$result['updated'];
					break;
				case 'blocked':
					++$result['blocked'];
					$result['messages'][] = $name . ': ' . $upsert['message'];
					continue 2;
				default:
					++$result['unchanged'];
					break;
			}

			if ( 'index.html' === $name && $upsert['id'] ) {
				$result['front'] = $upsert['id'];
			}
		}

		if ( $result['front'] ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $result['front'] );
		}

		// Rebuild the compiled CSS bundle so served pages are styled.
		try {
			StyleBundle::build();
		} catch ( \RuntimeException $e ) {
			$result['messages'][] = 'CSS bundle not rebuilt: ' . $e->getMessage();
		}

		update_option( self::SEEDED_OPTION, time() );
		$result['seeded'] = true;
		return $result;
	}

	/**
	 * Create a brand-new S5 framework page from the UI (no CLI, no corpus).
	 *
	 * Inserts a page and persists a MINIMAL valid canonical through the one save
	 * door, so the page is immediately a framework page and opens in the S5
	 * editor with editable slots.
	 *
	 * @param string $title Page title (also seeds the hero title text).
	 * @return array{id:int,error:string} New post id, or 0 + an error message.
	 */
	public static function create_page( string $title ): array {
		$title = trim( $title );
		if ( '' === $title ) {
			$title = __( 'New S5 Page', 's5' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return array(
				'id'    => 0,
				'error' => $post_id->get_error_message(),
			);
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, Storage::HEAD_KEY, array( 'title' => $title ) );

		try {
			Compiler::persist_page( $post_id, self::starter_canonical( $title ) );
		} catch ( \RuntimeException $e ) {
			wp_delete_post( $post_id, true );
			return array(
				'id'    => 0,
				'error' => $e->getMessage(),
			);
		}

		return array(
			'id'    => $post_id,
			'error' => '',
		);
	}

	/**
	 * Convert an EXISTING plain page into an S5 framework page.
	 *
	 * If the page already carries canonical HTML it is left untouched. Otherwise
	 * a minimal valid canonical is persisted (seeded from the page's title).
	 *
	 * @param int $post_id Target page id.
	 * @return array{ok:bool,error:string}
	 */
	public static function convert_page( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) || 'page' !== $post->post_type ) {
			return array(
				'ok'    => false,
				'error' => __( 'Not a page.', 's5' ),
			);
		}
		if ( Storage::is_framework_page( $post_id ) ) {
			return array(
				'ok'    => true,
				'error' => '',
			);
		}

		$title = '' !== $post->post_title ? $post->post_title : __( 'S5 Page', 's5' );
		if ( ! get_post_meta( $post_id, Storage::HEAD_KEY, true ) ) {
			update_post_meta( $post_id, Storage::HEAD_KEY, array( 'title' => $title ) );
		}

		try {
			Compiler::persist_page( $post_id, self::starter_canonical( $title ) );
		} catch ( \RuntimeException $e ) {
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}

		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * A minimal, contract-valid canonical for a fresh page: a hero with an
	 * editable title + lede, wrapped in the framework parts. Uses only vocabulary
	 * classes that ship in the design system, so it validates through the door.
	 *
	 * The data-node ids are page-local and authored here (D4); the compiler does
	 * not inject them.
	 *
	 * @param string $title Hero title text.
	 * @return string Canonical body HTML.
	 */
	private static function starter_canonical( string $title ): string {
		$safe = esc_html( $title );
		return "<s5-part name=\"site-header\"></s5-part>\n\n"
			. "<main data-s5=\"page\">\n"
			. "  <section class=\"page-hero\">\n"
			. "    <p class=\"eyebrow\" data-edit=\"text\" data-node=\"page-eyebrow\">New page</p>\n"
			. "    <h1 class=\"page-hero_title\" data-edit=\"text\" data-node=\"page-title\">{$safe}</h1>\n"
			. "    <p class=\"page-hero_lede\" data-edit=\"text\" data-node=\"page-lede\">Edit this in the S5 editor — glass or code. Every keystroke goes through the one save door.</p>\n"
			. "  </section>\n"
			. "  <section class=\"section\">\n"
			. "    <div class=\"prose\" data-edit=\"rich\" data-node=\"page-body\">\n"
			. "      <p>Start writing. Add HTML in Code view; it is validated against the Authoring Contract before it saves.</p>\n"
			. "    </div>\n"
			. "  </section>\n"
			. "</main>\n\n"
			. "<s5-part name=\"site-footer\"></s5-part>\n";
	}

	/**
	 * Load the class-name vocabulary MAP for validation (CLI-free).
	 *
	 * {@see Validator::validate()} expects the flat class map (keys = class names),
	 * NOT the {classes,files,missing} wrapper that {@see Stylesheet::load_vocabulary()}
	 * returns — the CLI's ValidateCommand::load_vocabulary_or_die() likewise returns
	 * `$v['classes']`. Passing the wrapper makes every class read as unknown.
	 *
	 * @return array<string, mixed> Class map (empty on failure — validation then
	 *                              treats all classes as unknown, the safe/loud
	 *                              direction).
	 */
	private static function load_vocabulary(): array {
		$repo = rtrim( (string) get_stylesheet_directory(), '/' ) . '/site';
		try {
			$vocab = Stylesheet::load_vocabulary( $repo );
		} catch ( \RuntimeException $e ) {
			return array();
		}
		return isset( $vocab['classes'] ) && is_array( $vocab['classes'] ) ? $vocab['classes'] : array();
	}

	/**
	 * Create-or-update a page by slug (CLI-free mirror of the importer's upsert).
	 * Never duplicates; publishes drafts; writes canonical + head only on change.
	 *
	 * @param string               $slug  Page slug.
	 * @param string               $title Page title.
	 * @param string               $body  Verbatim body fragment.
	 * @param array<string, mixed> $head  Extracted head blob.
	 * @return array{id:int,status:string,message:string}
	 */
	private static function upsert_page( string $slug, string $title, string $body, array $head ): array {
		$existing = get_page_by_path( $slug, OBJECT, 'page' );

		if ( $existing instanceof \WP_Post ) {
			$post_id   = (int) $existing->ID;
			$cur_body  = Storage::get_canonical_html( $post_id );
			$cur_head  = Storage::get_head( $post_id );
			$status_ok = ( 'publish' === $existing->post_status );
			$title_ok  = ( $title === $existing->post_title );
			$body_same = ( $cur_body === $body );
			$head_same = ( wp_json_encode( $cur_head ) === wp_json_encode( $head ) );

			if ( $body_same && $head_same && $status_ok && $title_ok ) {
				return array(
					'id'      => $post_id,
					'status'  => 'unchanged',
					'message' => '',
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
			try {
				Compiler::persist_page( $post_id, $body );
			} catch ( \RuntimeException $e ) {
				return array(
					'id'      => $post_id,
					'status'  => 'blocked',
					'message' => 'compile/persist failed — ' . $e->getMessage(),
				);
			}

			return array(
				'id'      => $post_id,
				'status'  => 'updated',
				'message' => '',
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return array(
				'id'      => 0,
				'status'  => 'blocked',
				'message' => 'could not create page — ' . $post_id->get_error_message(),
			);
		}
		$post_id = (int) $post_id;
		update_post_meta( $post_id, Storage::HEAD_KEY, $head );
		try {
			Compiler::persist_page( $post_id, $body );
		} catch ( \RuntimeException $e ) {
			wp_delete_post( $post_id, true );
			return array(
				'id'      => 0,
				'status'  => 'blocked',
				'message' => 'compile/persist failed — ' . $e->getMessage(),
			);
		}

		return array(
			'id'      => $post_id,
			'status'  => 'created',
			'message' => '',
		);
	}
}
