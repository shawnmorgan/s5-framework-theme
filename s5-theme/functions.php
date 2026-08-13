<?php
/**
 * S5 Framework — single-theme bootstrap.
 *
 * The framework ships as ONE theme (D14): all product code (src/, cli/, editor
 * assets, templates, composer/vendor) lives at the theme root; all site-authored
 * content (tokens/scales/components/parts/s5.config.json/assets) lives under the
 * fenced `site/` subtree, which product updates never touch. There is no companion
 * plugin — this file is the boot seam that was formerly s5-plugin.php.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Absolute path to the framework code root (the theme directory), trailing-slashed.
 *
 * Named `S5_PLUGIN_DIR` for continuity with the pre-repackage code that consumes it
 * (Editor.php, Render.php, the CLI). It now points at the theme, not a plugin — the
 * framework is the theme (D14). Do not rename without updating every consumer.
 *
 * @var string
 */
const S5_PLUGIN_DIR = __DIR__ . '/';

/**
 * Public URL to the framework code root (the theme directory), trailing-slashed.
 * Used to enqueue the editor's admin-only assets (Build Order Step 12).
 *
 * @var string
 */
define( 'S5\\S5_PLUGIN_URL', get_stylesheet_directory_uri() . '/' );

/*
 * Locate the Composer autoloader. It maps the `S5\` namespace to `src/` and
 * `S5\Cli\` to `cli/` (see composer.json). If missing, `composer install` was not
 * run in the theme; fail loudly in WP-CLI and admin rather than white-screening.
 */
$s5_autoload = S5_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! is_readable( $s5_autoload ) ) {
	$s5_message = 'S5 Framework: Composer autoloader not found. Run `composer install` in the theme directory.';

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::error( $s5_message );
	}

	add_action(
		'admin_notices',
		static function () use ( $s5_message ): void {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $s5_message ) );
		}
	);

	return;
}

require $s5_autoload;

// Boot the framework. Plugin::boot() is idempotent and safe to call once here.
Plugin::boot();

/**
 * Seed the demo corpus once, when the theme is activated.
 *
 * A theme uploaded from the WordPress admin has no WP-CLI, so the demo pages must
 * be created from PHP. `after_switch_theme` fires once on activation; the seeder
 * is idempotent (guarded by an option flag AND upsert-by-slug), so this never
 * overwrites a user's edits on a later re-activation. Failures are collected, not
 * thrown, so a partial corpus can never brick activation.
 *
 * @return void
 */
function s5_theme_seed_on_activation(): void {
	if ( ! current_user_can( 'switch_themes' ) ) {
		return;
	}
	// Compile the design system first so the very first front-end hit is styled,
	// even before the enqueue-time self-heal runs.
	StyleBundle::ensure();
	Seeder::seed_demo_corpus();
}
add_action( 'after_switch_theme', __NAMESPACE__ . '\\s5_theme_seed_on_activation' );

/**
 * Enqueue front-end assets.
 *
 * The compiled CSS bundle (site/dist/s5.css — the design system + every registered
 * component's scoped CSS, concatenated at compile per D17) carries all design-system
 * styling. It is enqueued via wp_head, OUTSIDE any authored body fragment, so the
 * byte-clean round-trip of framework pages is untouched.
 *
 * It is enqueued on EVERY front-end view — framework pages (so the served canonical
 * HTML is styled) AND ordinary pages/posts (so the styled fallback template renders
 * with the design system's base typography instead of browser defaults). If the
 * bundle is missing (a fresh zip install, or a stale build), it is built on demand
 * via StyleBundle::ensure() rather than serving unstyled. A build that cannot happen
 * (no site subtree) surfaces as an admin notice; it never white-screens the front.
 *
 * @return void
 */
function s5_theme_enqueue_assets(): void {
	// Product-owned base typography. Always present (shipped in the theme, not site/),
	// so even a not-yet-compiled or minimal install never renders browser-default.
	$fallback = S5_PLUGIN_DIR . 'assets/fallback.css';
	if ( is_readable( $fallback ) ) {
		wp_enqueue_style(
			's5-fallback',
			get_stylesheet_directory_uri() . '/assets/fallback.css',
			array(),
			(string) filemtime( $fallback )
		);
	}

	// Self-heal: build the design-system bundle on demand if it is missing
	// (never serve unstyled). Enqueued AFTER the fallback so authored design-system
	// rules win the cascade on framework pages.
	StyleBundle::ensure();

	$url = StyleBundle::url();
	if ( '' === $url ) {
		return;
	}
	wp_enqueue_style( 's5-bundle', $url, array( 's5-fallback' ), (string) StyleBundle::version() );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\s5_theme_enqueue_assets' );
