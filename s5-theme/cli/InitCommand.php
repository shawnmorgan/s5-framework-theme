<?php
/**
 * `wp s5 init` — scaffold the site subtree inside the single framework theme.
 *
 * The framework ships as ONE theme (D14). Site-authored content lives under the
 * theme's fenced `site/` subtree, which product updates never touch. This command
 * makes a fresh install authorable by writing a starter `site/` in place (D15):
 * a default s5.config.json, starter design-token + vocabulary stylesheets, and the
 * empty authoring directories (components/, parts/, assets/, pages/).
 *
 * It is strictly additive and IDEMPOTENT: an existing site file is never
 * overwritten — the same guarantee that protects a real site's customizations from
 * a product update protects them from a re-run of init.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scaffolds the theme's `site/` subtree.
 */
final class InitCommand {

	/**
	 * Scaffold the site subtree into the active theme (idempotent).
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite existing site files instead of skipping them. Use with care —
	 *   this discards authored site content. Off by default.
	 *
	 * ## EXAMPLES
	 *
	 *     wp s5 init
	 *     wp s5 init --force
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$force    = isset( $assoc_args['force'] );
		$site_dir = rtrim( (string) get_stylesheet_directory(), '/' ) . '/site';

		$created = array();
		$skipped = array();

		// 1. Authoring directories.
		foreach ( array( 'components', 'parts', 'assets', 'pages', 'styles' ) as $dir ) {
			$abs = $site_dir . '/' . $dir;
			if ( is_dir( $abs ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- local scaffold dir, CLI-only.
			if ( ! mkdir( $abs, 0755, true ) && ! is_dir( $abs ) ) {
				\WP_CLI::error( 'Could not create ' . $abs );
			}
			$created[] = $dir . '/';
		}

		// 2. Starter files. Each entry: [ relative path, contents ].
		$files = array(
			's5.config.json'        => self::starter_config(),
			'styles/tokens.css'     => self::starter_tokens(),
			'styles/scales.css'     => self::starter_scales(),
			'styles/components.css' => self::starter_components(),
			'styles/utilities.css'  => self::starter_utilities(),
		);

		foreach ( $files as $rel => $contents ) {
			$abs = $site_dir . '/' . $rel;
			if ( is_readable( $abs ) && ! $force ) {
				$skipped[] = $rel;
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local scaffold file, CLI-only.
			if ( false === file_put_contents( $abs, $contents ) ) {
				\WP_CLI::error( 'Could not write ' . $abs );
			}
			$created[] = $rel;
		}

		foreach ( $created as $c ) {
			\WP_CLI::log( '  + ' . $c );
		}
		foreach ( $skipped as $s ) {
			\WP_CLI::log( '  = ' . $s . ' (exists; left untouched)' );
		}

		// Compile the design-system bundle so a freshly-init'd site serves STYLED
		// immediately (site/dist/s5.css). Without this the front end would render
		// unstyled until the first import/recompile.
		try {
			$outfile = \S5\StyleBundle::build();
			\WP_CLI::log( '  + ' . str_replace( $site_dir . '/', '', $outfile ) . ' (compiled)' );
		} catch ( \Throwable $e ) {
			\WP_CLI::warning( 'Could not compile the CSS bundle: ' . $e->getMessage() );
		}

		\WP_CLI::success(
			sprintf(
				'Site scaffold ready at %s (%d created, %d preserved).',
				$site_dir,
				count( $created ),
				count( $skipped )
			)
		);
	}

	/**
	 * Default s5.config.json for a fresh site.
	 *
	 * @return string
	 */
	private static function starter_config(): string {
		$config = array(
			'name'       => 'S5 Site',
			'prefix'     => 's5',
			'textDomain' => 's5',
			'paths'      => array(
				'pagesSource' => 'pages',
				'parts'       => 'parts',
				'components'  => 'components',
				'dist'        => 'dist',
			),
			'styles'     => array(
				'vocabulary'   => array( 'styles/components.css', 'styles/utilities.css' ),
				'designTokens' => array( 'styles/tokens.css', 'styles/scales.css' ),
			),
			'assets'     => array(
				'dir'    => 'assets',
				'prefix' => 'assets/',
			),
			'site'       => array(
				'name'   => 'My Site',
				'url'    => 'https://example.com/',
				'logo'   => 'https://example.com/assets/logo.svg',
				'sameAs' => array(),
			),
			'cli'        => array( 'namespace' => 's5' ),
		);
		return (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Starter design tokens (custom properties only — no class selectors).
	 *
	 * @return string
	 */
	private static function starter_tokens(): string {
		return ":root {\n"
			. "  --color-fg: #111111;\n"
			. "  --color-bg: #ffffff;\n"
			. "  --color-accent: #2563eb;\n"
			. "  --font-body: system-ui, sans-serif;\n"
			. "}\n";
	}

	/**
	 * Starter scale tokens (type/space/radius ramps).
	 *
	 * @return string
	 */
	private static function starter_scales(): string {
		return ":root {\n"
			. "  --text-md: 1rem;\n"
			. "  --text-lg: 1.5rem;\n"
			. "  --space-md: 1rem;\n"
			. "  --space-lg: 2rem;\n"
			. "  --radius-md: 0.5rem;\n"
			. "}\n";
	}

	/**
	 * Starter component vocabulary (block/element/modifier classes).
	 *
	 * @return string
	 */
	private static function starter_components(): string {
		return "/* Component vocabulary — the Authoring Contract validator enforces these class names. */\n"
			. ".site-header {}\n"
			. ".site-footer {}\n"
			. ".nav {}\n"
			. ".btn {}\n"
			. ".btn--primary {}\n";
	}

	/**
	 * Starter utility vocabulary (u- prefixed).
	 *
	 * @return string
	 */
	private static function starter_utilities(): string {
		$out  = "/* Utility vocabulary. Micro-utilities (u- prefix) + the size/spacing\n";
		$out .= "   axis ladders the editor's Style tab swaps within (ControlsManifest\n";
		$out .= "   text-scale / space-top / space-bottom). Each rung maps to a scale\n";
		$out .= "   custom property, so a size/spacing edit is only ever a token swap. */\n\n";
		$out .= ".u-visually-hidden {\n\tposition: absolute;\n\twidth: 1px;\n\theight: 1px;\n\tmargin: -1px;\n\tpadding: 0;\n\toverflow: hidden;\n\tclip: rect(0, 0, 0, 0);\n\twhite-space: nowrap;\n\tborder: 0;\n}\n";
		$out .= ".u-text-left { text-align: left; }\n";
		$out .= ".u-text-center { text-align: center; }\n";
		$out .= ".u-text-right { text-align: right; }\n\n";

		$steps = array( 'xxs', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl', 'xxxl' );

		$out .= "/* Text size — font-size from the --s5-text-* ladder. */\n";
		foreach ( $steps as $s ) {
			$out .= ".text-scale-{$s} { font-size: var(--s5-text-{$s}); }\n";
		}
		$out .= "\n/* Space top — margin-block-start from the --s5-space-* ladder. */\n";
		foreach ( $steps as $s ) {
			$out .= ".space-top-{$s} { margin-block-start: var(--s5-space-{$s}); }\n";
		}
		$out .= "\n/* Space bottom — margin-block-end from the --s5-space-* ladder. */\n";
		foreach ( $steps as $s ) {
			$out .= ".space-bottom-{$s} { margin-block-end: var(--s5-space-{$s}); }\n";
		}
		return $out;
	}
}
