<?php
/**
 * S5 Framework — owned schema compilation (Build Order Step 9, PRD §13.2).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

use S5\Cli\ValidateCommand;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles the S5-owned schema graph (Layer B) for a framework page.
 *
 * Structured data is built from the SAME values that produce visible content —
 * the page's stored head (title, description, canonical) plus the authoritative
 * site identity in s5.config.json — so schema cannot silently drift from the
 * page. The graph is emitted as a single JSON-LD `@graph` in the served head.
 *
 * Emitted where authoritative:
 *   - `Organization` — the site's publisher identity (name, url, logo, sameAs).
 *   - `WebSite`      — the site (name, url), publisher → Organization.
 *   - `WebPage`      — this page (name = title, description, url = canonical),
 *                      isPartOf → WebSite, publisher → Organization.
 *
 * This asserts schema.org CORRECTNESS synchronized with visible content; it makes
 * no claim about search-engine rich-result eligibility (PRD §13.2). A future SEO
 * provider (Yoast/Rank Math) can take ownership via the `s5_schema_graph` filter.
 */
final class Schema {

	/**
	 * Build the JSON-LD graph for a page, or null if there is nothing to emit.
	 *
	 * @param int $post_id Page ID.
	 * @return string|null Pretty JSON-LD `@graph` document, or null.
	 */
	public static function build_json_ld( int $post_id ): ?string {
		$site = self::site_identity();
		$head = Storage::get_head( $post_id );

		$site_url = isset( $site['url'] ) ? (string) $site['url'] : (string) home_url( '/' );
		$org_id   = trailingslashit( $site_url ) . '#organization';
		$web_id   = trailingslashit( $site_url ) . '#website';

		$graph = array();

		// Organization — the publisher identity.
		if ( ! empty( $site['name'] ) ) {
			$org = array(
				'@type' => 'Organization',
				'@id'   => $org_id,
				'name'  => (string) $site['name'],
				'url'   => $site_url,
			);
			if ( ! empty( $site['logo'] ) ) {
				$org['logo'] = (string) $site['logo'];
			}
			if ( ! empty( $site['sameAs'] ) && is_array( $site['sameAs'] ) ) {
				$org['sameAs'] = array_values( array_map( 'strval', $site['sameAs'] ) );
			}
			$graph[] = $org;

			// WebSite — the site, published by the Organization.
			$graph[] = array(
				'@type'     => 'WebSite',
				'@id'       => $web_id,
				'url'       => $site_url,
				'name'      => (string) $site['name'],
				'publisher' => array( '@id' => $org_id ),
			);
		}

		// WebPage — this page, from the SAME head values that render.
		$page_url = isset( $head['canonical'] ) && '' !== $head['canonical']
			? (string) $head['canonical']
			: (string) get_permalink( $post_id );
		$web_page = array(
			'@type' => 'WebPage',
			'@id'   => trailingslashit( $page_url ) . '#webpage',
			'url'   => $page_url,
		);
		if ( isset( $head['title'] ) && '' !== $head['title'] ) {
			$web_page['name'] = (string) $head['title'];
		}
		if ( isset( $head['description'] ) && '' !== $head['description'] ) {
			$web_page['description'] = (string) $head['description'];
		}
		if ( ! empty( $graph ) ) {
			$web_page['isPartOf']  = array( '@id' => $web_id );
			$web_page['publisher'] = array( '@id' => $org_id );
		}
		$graph[] = $web_page;

		/**
		 * Filter the S5-owned schema graph before it is emitted. A SEO provider can
		 * replace or augment it here; returning an empty array suppresses output.
		 *
		 * @param array<int, array<string, mixed>> $graph   The schema nodes.
		 * @param int                              $post_id The page ID.
		 */
		$graph = apply_filters( 's5_schema_graph', $graph, $post_id );
		if ( empty( $graph ) ) {
			return null;
		}

		$doc = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $graph ),
		);

		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : null;
	}

	/**
	 * The authoritative site identity from s5.config.json `site`.
	 *
	 * @return array<string, mixed>
	 */
	private static function site_identity(): array {
		try {
			$path = ValidateCommand::repo_root() . '/s5.config.json';
		} catch ( \RuntimeException $e ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local config; wp_remote_get is for URLs.
		$raw = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( false === $raw ) {
			return array();
		}
		$config = json_decode( (string) $raw, true );
		return ( is_array( $config ) && isset( $config['site'] ) && is_array( $config['site'] ) ) ? $config['site'] : array();
	}
}
