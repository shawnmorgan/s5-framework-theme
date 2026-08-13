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
	Seeder::seed_demo_corpus();
}
add_action( 'after_switch_theme', __NAMESPACE__ . '\\s5_theme_seed_on_activation' );

/**
 * Enqueue front-end assets.
 *
 * The compiled CSS bundle (site/dist/s5.css — the design system + every registered
 * component's scoped CSS, concatenated at compile per D17) is enqueued on framework
 * pages so a single cacheable <link> styles the served page. The bundle is a served
 * asset via wp_head, OUTSIDE the authored body fragment, so byte-clean round-trip is
 * untouched. Enqueue only on framework pages, and only if the bundle exists.
 *
 * @return void
 */
function s5_theme_enqueue_assets(): void {
	$post_id = get_queried_object_id();
	if ( ! $post_id || ! Storage::is_framework_page( (int) $post_id ) ) {
		return;
	}
	$url = StyleBundle::url();
	if ( '' === $url ) {
		return;
	}
	wp_enqueue_style( 's5-bundle', $url, array(), (string) StyleBundle::version() );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\s5_theme_enqueue_assets' );
