<?php
/**
 * `wp s5 recompile` — regenerate derived artifacts from canonical (Step 5).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5\Cli;

use S5\Compiler;
use S5\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regenerates the derived render + post_content mirror for framework pages from
 * their canonical source. This is what makes the derived artifacts DISPOSABLE:
 * delete `_s5_render_html` and the mirror, recompile, and both are restored from
 * canonical alone. Deterministic — the same canonical always yields byte-identical
 * render output.
 */
final class RecompileCommand {

	/**
	 * Recompile one page or the whole site.
	 *
	 * ## OPTIONS
	 *
	 * [<slug>]
	 * : Recompile just the page with this slug.
	 *
	 * [--all]
	 * : Recompile every framework page.
	 *
	 * ## EXAMPLES
	 *
	 *     wp s5 recompile about
	 *     wp s5 recompile --all
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$all = isset( $assoc_args['all'] );

		if ( ! $all && empty( $args ) ) {
			\WP_CLI::error( 'Provide a <slug> or --all.' );
		}

		$post_ids = $all ? self::all_framework_page_ids() : array( self::id_for_slug( (string) $args[0] ) );

		if ( empty( $post_ids ) ) {
			\WP_CLI::warning( 'No framework pages to recompile.' );
			return;
		}

		$recompiled = 0;
		foreach ( $post_ids as $post_id ) {
			$canonical = Storage::get_canonical_html( $post_id );
			if ( '' === $canonical ) {
				\WP_CLI::warning( sprintf( '#%d has no canonical source; skipped.', $post_id ) );
				continue;
			}
			try {
				$result = Compiler::persist_page( $post_id, $canonical );
			} catch ( \RuntimeException $e ) {
				\WP_CLI::error( sprintf( '#%d: recompile failed — %s', $post_id, $e->getMessage() ), false );
				continue;
			}
			++$recompiled;
			\WP_CLI::log(
				sprintf(
					'recompiled #%d (%s, render %s)',
					$post_id,
					get_post_field( 'post_name', $post_id ),
					$result['render_changed'] ? 'updated' : 'unchanged'
				)
			);
		}

		\WP_CLI::success( sprintf( 'Recompiled %d page(s).', $recompiled ) );
	}

	/**
	 * All framework page IDs (pages that have a non-empty canonical source).
	 *
	 * @return array<int, int>
	 */
	private static function all_framework_page_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => Storage::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Storage::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- framework identity lookup; small dataset.
			)
		);

		$out = array();
		foreach ( $ids as $id ) {
			if ( Storage::is_framework_page( (int) $id ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Resolve a slug to a framework page ID, or abort.
	 *
	 * @param string $slug Page slug.
	 * @return int
	 */
	private static function id_for_slug( string $slug ): int {
		$post = get_page_by_path( $slug, OBJECT, Storage::POST_TYPE );
		if ( ! ( $post instanceof \WP_Post ) ) {
			\WP_CLI::error( sprintf( 'No page with slug "%s".', $slug ) );
		}
		if ( ! Storage::is_framework_page( (int) $post->ID ) ) {
			\WP_CLI::error( sprintf( 'Page "%s" is not a framework page (no canonical source).', $slug ) );
		}
		return (int) $post->ID;
	}
}
