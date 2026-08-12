<?php
/**
 * `wp s5 component` — register / list / insert components (Build Order Step 11).
 *
 * Components are REGISTERED HUMAN CAPABILITIES (components/<name>/) and are
 * COPY-ON-INSERT: `insert` expands a component's HTML into a page's canonical
 * source ONCE and persists through the SAME door as import/recompile
 * (Compiler::persist_page). The component then stops existing as an abstraction —
 * the page is just canonical HTML. There is no component JSON as page state.
 *
 * An UNREGISTERED component cannot be inserted through this human-facing surface
 * (the Step 11 acceptance guarantee).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5\Cli;

use S5\Compiler;
use S5\ComponentRegistry;
use S5\Storage;
use S5\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Component registry + copy-on-insert CLI.
 */
final class ComponentCommand {

	/**
	 * List every registered component with its version.
	 *
	 * ## EXAMPLES
	 *
	 *     wp s5 component list
	 *
	 * @return void
	 */
	public function list(): void {
		$names = ComponentRegistry::list();
		if ( empty( $names ) ) {
			\WP_CLI::log( 'No components registered.' );
			return;
		}
		foreach ( $names as $name ) {
			try {
				$c = ComponentRegistry::load( $name );
				\WP_CLI::log( sprintf( '%s@%s — %s', $c['name'], $c['version'], $c['description'] ) );
			} catch ( \RuntimeException $e ) {
				\WP_CLI::warning( sprintf( '%s: %s', $name, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Validate that a component is well-formed and report its version stamp.
	 *
	 * Registration is by presence (the filesystem is the registry); this command
	 * asserts the definition is complete and loadable, i.e. safe to insert.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Component name (the components/<name>/ directory).
	 *
	 * ## EXAMPLES
	 *
	 *     wp s5 component register card
	 *
	 * @param array<int, string> $args Positional args.
	 * @return void
	 */
	public function register( array $args ): void {
		$name = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $name ) {
			\WP_CLI::error( 'Provide a component <name>.' );
		}
		try {
			$c = ComponentRegistry::load( $name );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( sprintf( 'component "%s" is not registrable: %s', $name, $e->getMessage() ) );
		}
		\WP_CLI::success( sprintf( 'component "%s" registered (version %s).', $c['name'], $c['version'] ) );
	}

	/**
	 * Insert a registered component into a page's canonical HTML (copy-on-insert).
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Registered component name.
	 *
	 * --page=<slug>
	 * : Slug of the framework page to insert into.
	 *
	 * [--at=<data-node>]
	 * : Insert the component immediately AFTER the element carrying this data-node.
	 * : Omit to append the component at the end of the page's canonical body.
	 *
	 * ## EXAMPLES
	 *
	 *     wp s5 component insert card --page=about
	 *     wp s5 component insert card --page=about --at=about-hero-lede
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function insert( array $args, array $assoc_args ): void {
		$name = isset( $args[0] ) ? (string) $args[0] : '';
		$slug = isset( $assoc_args['page'] ) ? (string) $assoc_args['page'] : '';
		$at   = isset( $assoc_args['at'] ) ? (string) $assoc_args['at'] : '';

		if ( '' === $name || '' === $slug ) {
			\WP_CLI::error( 'Usage: wp s5 component insert <name> --page=<slug> [--at=<data-node>]' );
		}

		// UNREGISTERED components cannot be inserted through this surface.
		if ( ! ComponentRegistry::is_registered( $name ) ) {
			\WP_CLI::error( sprintf( 'component "%s" is not registered — refusing to insert.', $name ) );
		}

		try {
			$component = ComponentRegistry::load( $name );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( sprintf( 'component "%s" failed to load: %s', $name, $e->getMessage() ) );
		}

		$page = get_page_by_path( $slug, OBJECT, Storage::POST_TYPE );
		if ( ! ( $page instanceof \WP_Post ) || ! Storage::is_framework_page( (int) $page->ID ) ) {
			\WP_CLI::error( sprintf( 'no framework page with slug "%s".', $slug ) );
		}
		$post_id = (int) $page->ID;

		$canonical = Storage::get_canonical_html( $post_id );
		if ( '' === $canonical ) {
			\WP_CLI::error( sprintf( 'page "%s" has no canonical source.', $slug ) );
		}

		$new_canonical = self::splice( $canonical, $component['html'], $at );
		if ( null === $new_canonical ) {
			\WP_CLI::error( sprintf( 'insert anchor "--at=%s" not found in page "%s".', $at, $slug ) );
		}

		// Validate the RESULT against the same Authoring Contract gate as import —
		// an inserted component with an out-of-vocabulary class is rejected before
		// it can land, and canonical stays unchanged.
		$vocab    = ValidateCommand::load_vocabulary_or_die();
		$findings = Validator::validate( $new_canonical, $vocab, 'component:' . $name );
		if ( Validator::has_errors( $findings ) ) {
			foreach ( $findings as $f ) {
				if ( isset( $f['severity'] ) && Validator::SEVERITY_ERROR === $f['severity'] ) {
					\WP_CLI::log( sprintf( '  [%s] %s', $f['rule'] ?? '', $f['message'] ?? '' ) );
				}
			}
			\WP_CLI::error( sprintf( 'inserting "%s" would break the Authoring Contract — page "%s" left unchanged.', $name, $slug ) );
		}

		// Copy-on-insert lands through the ONE door: compile → mirror → persist →
		// revision. The component is now plain canonical HTML on the page.
		try {
			Compiler::persist_page( $post_id, $new_canonical );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( sprintf( 'persist failed for "%s": %s', $slug, $e->getMessage() ) );
		}

		\WP_CLI::success(
			sprintf(
				'inserted component "%s"@%s into "%s"%s.',
				$component['name'],
				$component['version'],
				$slug,
				'' !== $at ? ' after node "' . $at . '"' : ' (appended)'
			)
		);
	}

	/**
	 * Splice component markup into canonical, either after the element carrying
	 * data-node="$at" (targeted) or appended at the end (default). Surgical byte
	 * insert — everything already in canonical stays byte-identical.
	 *
	 * @param string $canonical Current canonical body HTML.
	 * @param string $markup    Component HTML to insert.
	 * @param string $at        data-node anchor, or '' to append.
	 * @return string|null New canonical, or null if the anchor was not found.
	 */
	private static function splice( string $canonical, string $markup, string $at ) {
		$block = "\n" . $markup . "\n";

		if ( '' === $at ) {
			return rtrim( $canonical, "\r\n" ) . $block . "\n";
		}

		// Find the open tag carrying data-node="$at", then its balanced close tag,
		// and insert the component immediately AFTER that close tag.
		$pattern = '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bdata-node=(["\'])' . preg_quote( $at, '/' ) . '\2[^>]*>/';
		if ( ! preg_match( $pattern, $canonical, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$tag      = $m[1][0];
		$open_end = (int) $m[0][1] + strlen( $m[0][0] );

		// Self-closing / void element: insert right after the open tag.
		if ( '/>' === substr( $m[0][0], -2 ) ) {
			return substr( $canonical, 0, $open_end ) . $block . substr( $canonical, $open_end );
		}

		$depth  = 1;
		$offset = $open_end;
		$len    = strlen( $canonical );
		$re     = '/<(\/?)' . preg_quote( $tag, '/' ) . '\b[^>]*>/i';
		while ( $offset < $len && preg_match( $re, $canonical, $tm, PREG_OFFSET_CAPTURE, $offset ) ) {
			$is_close = '' !== $tm[1][0];
			$at_pos   = (int) $tm[0][1];
			$tok_end  = $at_pos + strlen( $tm[0][0] );
			if ( $is_close ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $canonical, 0, $tok_end ) . $block . substr( $canonical, $tok_end );
				}
			} else {
				++$depth;
			}
			$offset = $tok_end;
		}

		// Unbalanced — anchor exists but no close tag found; treat as not insertable.
		return null;
	}
}
