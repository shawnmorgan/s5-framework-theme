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

		// Structural law (Amendment A): refuse insert on/inside a data-lock subtree.
		if ( \S5\ComponentInsert::anchor_is_locked( $canonical, $at ) ) {
			\WP_CLI::error( sprintf( 'anchor "--at=%s" is inside a locked (data-lock) region — insert refused.', $at ) );
		}

		// Server-assigned recursive node ids: re-key every data-node in the component
		// markup to fresh page-unique ids so repeated inserts never collide. The
		// server owns id uniqueness; the client never assigns ids.
		$existing = \S5\ComponentInsert::existing_ids( $canonical );
		$rekeyed  = \S5\ComponentInsert::rekey( $component['html'], $component['name'], $existing );

		$new_canonical = \S5\ComponentInsert::splice( $canonical, $rekeyed['markup'], $at );
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
}
