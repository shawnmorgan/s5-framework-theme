<?php
/**
 * S5 Framework — media resolution (Build Order Step 6).
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
 * Resolves canonical, portable media references into environment-specific,
 * attachment-backed render markup — the Step 6 sanctioned compile transform.
 *
 * THE INVARIANT: canonical source references assets by relative path
 * (`assets/hero.png`), resolved against the asset dir declared in
 * s5.config.json. Canonical NEVER contains attachment IDs, upload URLs, or
 * srcset. Those are environment state and belong ONLY in the derived render.
 *
 * On compile, for each `<img>` / `<source srcset>` whose reference is a local
 * relative path:
 *   - the file is sideloaded into the media library if absent, or MATCHED to an
 *     existing attachment by CONTENT HASH (`_s5_source_hash`) — same file
 *     re-imports match (dedupe); a changed file (same name) re-sideloads. We
 *     never match by filename alone;
 *   - the derived element gets the attachment URL, srcset/sizes, and explicit
 *     width/height. `loading="lazy"` is added to every `<img>` EXCEPT the first
 *     content image (crude LCP heuristic) or one the author marked
 *     `fetchpriority="high"`; `decoding="async"` is added likewise. AUTHOR-SET
 *     attributes always win verbatim.
 *
 * External URLs (`http:`, `https:`, `//`, `data:`) are left untouched
 * everywhere. `<picture>`/`<source>` are resolved in place, never restructured.
 * CSS `url()` references are OUT of scope for this step (recorded gap).
 *
 * BYTE-FIDELITY: this transform NEVER re-serializes the document through DOM
 * (that would perturb unrelated bytes — entities, quoting, self-closing style).
 * It uses DOM only to LOCATE each media element's exact source substring, then
 * performs a targeted byte-level string replacement of that substring with the
 * composed resolved element. Every non-media byte is preserved exactly. Each
 * resolved element carries `data-s5-orig` (base64 of the original element
 * bytes) so the round-trip harness can map the resolved markup back to the
 * logical source before diffing (sanctioned transform #2).
 */
final class MediaResolver {

	/**
	 * Meta key on an attachment recording the sha-256 of the source file it was
	 * sideloaded from. This is how re-imports are matched/deduplicated: the same
	 * bytes always resolve to the same attachment; changed bytes force a new one.
	 *
	 * @var string
	 */
	public const HASH_KEY = '_s5_source_hash';

	/**
	 * Meta key recording the logical source path (e.g. `assets/hero.png`) the
	 * attachment was sideloaded from. Diagnostic / provenance; matching is by
	 * hash, never by this alone.
	 *
	 * @var string
	 */
	public const PATH_KEY = '_s5_source_path';

	/**
	 * Attribute stamped on a resolved element holding base64 of the ORIGINAL
	 * element source bytes, so the harness can reverse the transform.
	 *
	 * @var string
	 */
	public const ORIG_ATTR = 'data-s5-orig';

	/**
	 * Resolve all local relative media references in a render fragment.
	 *
	 * @param string $html    Render HTML (post-identity-pipeline) to resolve.
	 * @param int    $post_id Owning page id (for diagnostics only).
	 * @return string Render HTML with media resolved to environment markup.
	 *
	 * @throws \RuntimeException If a referenced local asset cannot be resolved.
	 */
	public static function resolve( string $html, int $post_id ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}
		if ( false === stripos( $html, '<img' ) && false === stripos( $html, '<source' ) ) {
			return $html; // Nothing to resolve — keep bytes untouched.
		}

		$asset_dir = self::asset_dir();
		$prefix    = self::asset_prefix();

		$dom   = self::load_dom( $html );
		$xpath = new \DOMXPath( $dom );

		// LCP heuristic: the FIRST <img> in document order is the presumed LCP
		// image and is NOT lazy-loaded (unless the author already said otherwise).
		$first_img = $xpath->query( '//img' )->item( 0 );

		// Collect (originalBytes -> resolvedBytes) edits, then apply as byte-exact
		// string replacements so non-media bytes are never touched.
		$edits = array();

		// ---- <img> elements --------------------------------------------------
		foreach ( $xpath->query( '//img[@src]' ) as $img ) {
			if ( ! ( $img instanceof \DOMElement ) ) {
				continue;
			}
			$src = (string) $img->getAttribute( 'src' );
			if ( ! self::is_local_relative( $src, $prefix ) ) {
				continue; // External / data: / non-asset — untouched.
			}

			$attachment_id = self::resolve_reference( $src, $asset_dir, $prefix, $post_id );
			$resolved      = self::compose_img(
				$img,
				$attachment_id,
				( null !== $first_img && $img->isSameNode( $first_img ) )
			);

			$edits[] = array( self::element_source( $img, $html ), $resolved );
		}

		// ---- <source srcset> elements (inside <picture>) ---------------------
		foreach ( $xpath->query( '//source[@srcset]' ) as $source ) {
			if ( ! ( $source instanceof \DOMElement ) ) {
				continue;
			}
			$srcset = (string) $source->getAttribute( 'srcset' );
			if ( ! self::srcset_has_local_relative( $srcset, $prefix ) ) {
				continue; // All candidates external / data: — untouched.
			}

			$resolved = self::compose_source( $source, $asset_dir, $prefix, $post_id );
			$edits[]  = array( self::element_source( $source, $html ), $resolved );
		}

		// Apply edits by exact-substring replacement (first occurrence each).
		foreach ( $edits as $edit ) {
			list( $original, $replacement ) = $edit;
			if ( '' === $original ) {
				continue;
			}
			$pos = strpos( $html, $original );
			if ( false === $pos ) {
				continue; // Should not happen: located via the same source bytes.
			}
			$html = substr( $html, 0, $pos ) . $replacement . substr( $html, $pos + strlen( $original ) );
		}

		return $html;
	}

	/**
	 * Compose the resolved `<img>` element string (never DOM-serialized).
	 *
	 * Author-set attributes win: existing attributes are preserved verbatim and
	 * in source order; the resolver only ADDS what the author omitted (src is
	 * rewritten to the attachment URL; srcset/sizes/width/height/loading/decoding
	 * are added only when absent).
	 *
	 * @param \DOMElement $img      Source <img> element.
	 * @param int         $att_id   Resolved attachment id.
	 * @param bool        $is_first Whether this is the first content image (LCP).
	 * @return string Resolved element bytes.
	 */
	private static function compose_img( \DOMElement $img, int $att_id, bool $is_first ): string {
		$orig_bytes = self::element_outer_html( $img );

		// Preserve every author attribute verbatim, in source order.
		$attrs = array();
		foreach ( $img->attributes as $attr ) {
			$attrs[ $attr->nodeName ] = (string) $attr->nodeValue;
		}

		$att_url = (string) wp_get_attachment_url( $att_id );

		// src -> environment attachment URL.
		$attrs['src'] = $att_url;

		// srcset / sizes — only if the author did not provide them.
		if ( ! isset( $attrs['srcset'] ) ) {
			$srcset = wp_get_attachment_image_srcset( $att_id, 'full' );
			if ( is_string( $srcset ) && '' !== $srcset ) {
				$attrs['srcset'] = $srcset;
				if ( ! isset( $attrs['sizes'] ) ) {
					$sizes = wp_get_attachment_image_sizes( $att_id, 'full' );
					if ( is_string( $sizes ) && '' !== $sizes ) {
						$attrs['sizes'] = $sizes;
					}
				}
			}
		}

		// width / height — only if the author did not set them.
		$meta = wp_get_attachment_metadata( $att_id );
		if ( is_array( $meta ) ) {
			if ( ! isset( $attrs['width'] ) && isset( $meta['width'] ) ) {
				$attrs['width'] = (string) (int) $meta['width'];
			}
			if ( ! isset( $attrs['height'] ) && isset( $meta['height'] ) ) {
				$attrs['height'] = (string) (int) $meta['height'];
			}
		}

		// loading / decoding — author wins; else lazy unless first (LCP) or
		// explicitly high-priority.
		$high_priority = isset( $attrs['fetchpriority'] ) && 'high' === strtolower( $attrs['fetchpriority'] );
		if ( ! isset( $attrs['loading'] ) && ! $is_first && ! $high_priority ) {
			$attrs['loading'] = 'lazy';
		}
		if ( ! isset( $attrs['decoding'] ) ) {
			$attrs['decoding'] = 'async';
		}

		// Back-reference for the round-trip harness (sanctioned transform #2).
		$attrs[ self::ORIG_ATTR ] = base64_encode( $orig_bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding the original element bytes for the round-trip normalizer, not obfuscation.

		return self::render_tag( 'img', $attrs );
	}

	/**
	 * Compose the resolved `<source>` element string (URLs only; not restructured).
	 *
	 * Each relative srcset candidate is resolved to its attachment URL; external
	 * candidates are left as-is. Descriptors (e.g. ` 2x`, ` 800w`) are preserved.
	 *
	 * @param \DOMElement $source    Source <source> element.
	 * @param string      $asset_dir Absolute asset dir.
	 * @param string      $prefix    Logical asset prefix.
	 * @param int         $post_id   Owning page id (diagnostics).
	 * @return string Resolved element bytes.
	 *
	 * @throws \RuntimeException If a referenced local asset cannot be resolved.
	 */
	private static function compose_source( \DOMElement $source, string $asset_dir, string $prefix, int $post_id ): string {
		$orig_bytes = self::element_outer_html( $source );

		$attrs = array();
		foreach ( $source->attributes as $attr ) {
			$attrs[ $attr->nodeName ] = (string) $attr->nodeValue;
		}

		$attrs['srcset'] = self::resolve_srcset( (string) $attrs['srcset'], $asset_dir, $prefix, $post_id );

		$attrs[ self::ORIG_ATTR ] = base64_encode( $orig_bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding the original element bytes for the round-trip normalizer, not obfuscation.

		return self::render_tag( 'source', $attrs );
	}

	/**
	 * Resolve every local relative candidate in a srcset attribute to its URL.
	 *
	 * @param string $srcset    Raw srcset value.
	 * @param string $asset_dir Absolute asset dir.
	 * @param string $prefix    Logical asset prefix.
	 * @param int    $post_id   Owning page id (diagnostics).
	 * @return string Resolved srcset.
	 *
	 * @throws \RuntimeException If a referenced local asset cannot be resolved.
	 */
	private static function resolve_srcset( string $srcset, string $asset_dir, string $prefix, int $post_id ): string {
		$candidates = array_map( 'trim', explode( ',', $srcset ) );
		$out        = array();

		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			// A candidate is "<url>[ <descriptor>]".
			$parts      = preg_split( '/\s+/', $candidate, 2 );
			$url        = $parts[0];
			$descriptor = isset( $parts[1] ) ? ' ' . $parts[1] : '';

			if ( self::is_local_relative( $url, $prefix ) ) {
				$att_id = self::resolve_reference( $url, $asset_dir, $prefix, $post_id );
				$url    = (string) wp_get_attachment_url( $att_id );
			}
			$out[] = $url . $descriptor;
		}

		return implode( ', ', $out );
	}

	/**
	 * Resolve a logical relative reference to an attachment id, sideloading or
	 * matching by content hash.
	 *
	 * @param string $ref       Logical reference (e.g. `assets/hero.png`).
	 * @param string $asset_dir Absolute asset dir.
	 * @param string $prefix    Logical asset prefix.
	 * @param int    $post_id   Owning page id (diagnostics).
	 * @return int Attachment id.
	 *
	 * @throws \RuntimeException If the referenced file does not exist on disk.
	 */
	private static function resolve_reference( string $ref, string $asset_dir, string $prefix, int $post_id ): int {
		$relative = substr( $ref, strlen( $prefix ) );
		$relative = ltrim( $relative, '/' );
		$path     = $asset_dir . '/' . $relative;

		$real_dir = realpath( $asset_dir );
		$real     = realpath( $path );
		// Loud, per contract: unresolvable path names file + path.
		if ( false === $real || ! is_file( $real ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						'media resolution failed for page #%d: asset "%s" not found (looked in %s)',
						$post_id,
						$ref,
						$path
					)
				)
			);
		}
		// Containment guard: never resolve outside the declared asset dir.
		if ( false === $real_dir || 0 !== strpos( $real, $real_dir . '/' ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						'media resolution failed for page #%d: asset "%s" escapes the asset dir (%s)',
						$post_id,
						$ref,
						$asset_dir
					)
				)
			);
		}

		$hash = hash_file( 'sha256', $real );
		if ( false === $hash ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'media resolution failed for page #%d: could not hash "%s"', $post_id, $ref ) )
			);
		}

		$existing = self::find_by_hash( $hash );
		if ( $existing > 0 ) {
			return $existing;
		}

		return self::sideload( $real, $relative, $hash );
	}

	/**
	 * Find an existing attachment previously sideloaded from identical bytes.
	 *
	 * @param string $hash sha-256 of the source file.
	 * @return int Attachment id, or 0 if none.
	 */
	private static function find_by_hash( string $hash ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::HASH_KEY,   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- dedupe lookup; small dataset, dev/import-time.
				'meta_value'     => $hash,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact-hash match is the whole point.
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Sideload a local file into the media library and stamp its provenance.
	 *
	 * @param string $abs_path  Absolute source file path.
	 * @param string $relative  Logical relative path (for filename + provenance).
	 * @param string $hash      sha-256 of the source file.
	 * @return int Attachment id.
	 *
	 * @throws \RuntimeException If the sideload fails.
	 */
	private static function sideload( string $abs_path, string $relative, string $hash ): int {
		self::require_media_api();

		$tmp = wp_tempnam( basename( $relative ) );
		if ( ! $tmp || ! copy( $abs_path, $tmp ) ) {
			if ( $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			throw new \RuntimeException(
				esc_html( sprintf( 'media resolution failed: could not stage "%s" for sideload', $relative ) )
			);
		}

		$file_array = array(
			'name'     => basename( $relative ),
			'tmp_name' => $tmp,
		);

		$att_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $att_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			throw new \RuntimeException(
				esc_html(
					sprintf( 'media resolution failed: sideload of "%s" errored — %s', $relative, $att_id->get_error_message() )
				)
			);
		}

		$att_id = (int) $att_id;
		update_post_meta( $att_id, self::HASH_KEY, $hash );
		update_post_meta( $att_id, self::PATH_KEY, $relative );

		return $att_id;
	}

	/**
	 * Whether a reference is a LOCAL RELATIVE asset reference (our prefix), as
	 * opposed to an external URL (http/https/protocol-relative/data:) or a
	 * non-asset path.
	 *
	 * @param string $ref    Reference value.
	 * @param string $prefix Logical asset prefix.
	 * @return bool
	 */
	private static function is_local_relative( string $ref, string $prefix ): bool {
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return false;
		}
		// External / absolute / data URIs are never rewritten.
		if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//)#i', $ref ) ) {
			return false;
		}
		return 0 === strpos( $ref, $prefix );
	}

	/**
	 * Whether any srcset candidate is a local relative asset reference.
	 *
	 * @param string $srcset Raw srcset value.
	 * @param string $prefix Logical asset prefix.
	 * @return bool
	 */
	private static function srcset_has_local_relative( string $srcset, string $prefix ): bool {
		foreach ( array_map( 'trim', explode( ',', $srcset ) ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			$url = preg_split( '/\s+/', $candidate, 2 )[0];
			if ( self::is_local_relative( $url, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render an HTML start tag from an ordered attribute map.
	 *
	 * Attributes are emitted in the given order with double-quoted, escaped
	 * values. `<img>`/`<source>` are void elements, emitted HTML5-style with a
	 * plain `>` (matching how they are authored in source).
	 *
	 * @param string                $tag   Tag name.
	 * @param array<string, string> $attrs Ordered attribute map.
	 * @return string
	 */
	private static function render_tag( string $tag, array $attrs ): string {
		$out = '<' . $tag;
		foreach ( $attrs as $name => $value ) {
			$out .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		$out .= '>';
		return $out;
	}

	/**
	 * The exact SOURCE substring for an element, located in the original HTML.
	 *
	 * We reconstruct the element's canonical bytes from DOM and confirm they
	 * appear verbatim in the source; if DOM re-serialization diverges from the
	 * source bytes (attribute quoting etc.), we fall back to a byte-accurate
	 * scan of the original HTML for the element's start tag.
	 *
	 * @param \DOMElement $el   Element.
	 * @param string      $html Original HTML.
	 * @return string Exact source substring, or '' if it cannot be located.
	 */
	private static function element_source( \DOMElement $el, string $html ): string {
		$outer = self::element_outer_html( $el );
		if ( '' !== $outer && false !== strpos( $html, $outer ) ) {
			return $outer;
		}
		return self::scan_start_tag( $el, $html );
	}

	/**
	 * The element's start tag as authored, reconstructed from its attributes in
	 * source order. `<img>`/`<source>` are void, so the start tag is the whole
	 * element.
	 *
	 * @param \DOMElement $el Element.
	 * @return string
	 */
	private static function element_outer_html( \DOMElement $el ): string {
		$out = '<' . $el->nodeName;
		foreach ( $el->attributes as $attr ) {
			$out .= ' ' . $attr->nodeName . '="' . $attr->nodeValue . '"';
		}
		$out .= '>';
		return $out;
	}

	/**
	 * Byte-accurate fallback: find the element's start tag in source by its src
	 * or srcset value, returning the exact `<tag ...>` substring from source.
	 *
	 * @param \DOMElement $el   Element.
	 * @param string      $html Original HTML.
	 * @return string
	 */
	private static function scan_start_tag( \DOMElement $el, string $html ): string {
		$needle = $el->hasAttribute( 'src' )
			? 'src="' . $el->getAttribute( 'src' ) . '"'
			: 'srcset="' . $el->getAttribute( 'srcset' ) . '"';

		$anchor = strpos( $html, $needle );
		if ( false === $anchor ) {
			return '';
		}
		$open = strrpos( substr( $html, 0, $anchor ), '<' );
		if ( false === $open ) {
			return '';
		}
		$close = strpos( $html, '>', $anchor );
		if ( false === $close ) {
			return '';
		}
		return substr( $html, $open, $close - $open + 1 );
	}

	/**
	 * Load an HTML fragment into DOM for LOCATING media elements only (never for
	 * re-serializing the document).
	 *
	 * @param string $html Fragment.
	 * @return \DOMDocument
	 */
	private static function load_dom( string $html ): \DOMDocument {
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $dom;
	}

	/**
	 * Absolute asset directory declared in s5.config.json (`assets.dir`),
	 * resolved against the repo root (container-aware).
	 *
	 * @return string
	 *
	 * @throws \RuntimeException If the asset dir is not declared or missing.
	 */
	private static function asset_dir(): string {
		$config = self::config();
		$dir    = isset( $config['assets']['dir'] ) ? (string) $config['assets']['dir'] : '';
		if ( '' === $dir ) {
			throw new \RuntimeException( esc_html( 's5.config.json: assets.dir is not declared' ) );
		}
		$abs = ValidateCommand::repo_root() . '/' . ltrim( $dir, '/' );
		if ( ! is_dir( $abs ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 's5.config.json: assets.dir "%s" not found at %s', $dir, $abs ) )
			);
		}
		return rtrim( $abs, '/' );
	}

	/**
	 * Logical asset prefix (`assets.prefix`, default `assets/`).
	 *
	 * @return string
	 */
	private static function asset_prefix(): string {
		$config = self::config();
		$prefix = isset( $config['assets']['prefix'] ) ? (string) $config['assets']['prefix'] : 'assets/';
		return '' !== $prefix ? $prefix : 'assets/';
	}

	/**
	 * Read + decode s5.config.json from the repo root.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException If the config cannot be read or parsed.
	 */
	private static function config(): array {
		$path = ValidateCommand::repo_root() . '/s5.config.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local config file; wp_remote_get is for URLs.
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			throw new \RuntimeException( esc_html( sprintf( 'could not read %s', $path ) ) );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'invalid JSON in %s', $path ) ) );
		}
		return $data;
	}

	/**
	 * Ensure the WordPress media sideload API is loaded (CLI context lacks it).
	 *
	 * @return void
	 */
	private static function require_media_api(): void {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
