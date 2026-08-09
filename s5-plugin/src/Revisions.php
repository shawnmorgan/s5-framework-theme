<?php
/**
 * S5 Framework — canonical-source revisions + restore recompile (Step 5).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the CANONICAL source (and its head blob) participate in native
 * WordPress revisions, and recompiles derived artifacts when a revision is
 * restored.
 *
 * Only canonical source is revisioned — the derived render is disposable and
 * regenerated on restore, never itself versioned. Restoring a source revision
 * therefore reverts the SERVED page without any manual recompile.
 */
final class Revisions {

	/**
	 * Register revision hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Include the canonical + head meta in each revision snapshot.
		add_filter( 'wp_post_revision_meta_keys', array( self::class, 'revisioned_meta_keys' ) );

		// WordPress only saves a revision when it detects a change; meta changes
		// alone don't trigger that, so signal "post has changed" when our meta did.
		add_filter( 'wp_save_post_revision_post_has_changed', array( self::class, 'meta_changed' ), 10, 3 );

		// After a revision is restored, recompile derived render + mirror from the
		// now-restored canonical source.
		add_action( 'wp_restore_post_revision', array( self::class, 'on_restore' ), 10, 2 );
	}

	/**
	 * Add our canonical + head meta keys to the revisioned set.
	 *
	 * @param array<int, string> $keys Meta keys included in revisions.
	 * @return array<int, string>
	 */
	public static function revisioned_meta_keys( array $keys ): array {
		$keys[] = Storage::META_KEY;
		$keys[] = Storage::HEAD_KEY;
		return $keys;
	}

	/**
	 * Force a revision when our revisioned meta changed, even if post fields did not.
	 *
	 * @param bool     $has_changed   Whether WordPress thinks the post changed.
	 * @param \WP_Post $last_revision The latest stored revision.
	 * @param \WP_Post $post          The post being saved.
	 * @return bool
	 */
	public static function meta_changed( $has_changed, $last_revision, $post ) {
		if ( $has_changed ) {
			return true;
		}
		if ( ! ( $post instanceof \WP_Post ) || ! ( $last_revision instanceof \WP_Post ) ) {
			return $has_changed;
		}

		foreach ( array( Storage::META_KEY, Storage::HEAD_KEY ) as $key ) {
			$current  = get_post_meta( $post->ID, $key, true );
			$revision = get_post_meta( $last_revision->ID, $key, true );
			if ( wp_json_encode( $current ) !== wp_json_encode( $revision ) ) {
				return true;
			}
		}

		return $has_changed;
	}

	/**
	 * On revision restore, recompile derived render + mirror from canonical.
	 *
	 * WordPress copies the revision's revisioned meta back onto the parent post
	 * before this fires, so the parent's canonical is already the restored value.
	 *
	 * @param int $post_id     Parent post ID.
	 * @param int $revision_id Restored revision ID.
	 * @return void
	 */
	public static function on_restore( $post_id, $revision_id ): void {
		$post_id = (int) $post_id;

		// Belt-and-suspenders: ensure the parent's canonical matches the revision
		// (core restores revisioned meta, but restoring explicitly is harmless and
		// guards against ordering differences across WP versions).
		$restored_canonical = get_post_meta( (int) $revision_id, Storage::META_KEY, true );
		if ( is_string( $restored_canonical ) && '' !== $restored_canonical ) {
			update_post_meta( $post_id, Storage::META_KEY, $restored_canonical );
		}

		$canonical = Storage::get_canonical_html( $post_id );
		if ( '' === $canonical ) {
			return;
		}

		try {
			Compiler::persist_page( $post_id, $canonical );
		} catch ( \RuntimeException $e ) {
			// Never fatal a restore over a derived-artifact failure; log for debug.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'S5 restore recompile failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}
