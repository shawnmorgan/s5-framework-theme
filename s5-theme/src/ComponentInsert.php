<?php
/**
 * S5 Framework — component insert with server-assigned recursive node IDs.
 *
 * Shared by the CLI (`wp s5 component insert`) and the REST palette insert so both
 * go through ONE code path and the SAME one save door (Compiler::persist_page).
 *
 * Copy-on-insert (Step 11) expands a component's HTML into a page's canonical once.
 * Item 4 adds the rule that made repeated inserts safe: the SERVER re-keys every
 * `data-node` in the inserted subtree to a fresh, page-unique id (recursively — not
 * just the root), so two inserts of the same component never collide and the
 * compiler owns id uniqueness. The client never assigns ids.
 *
 * Structural laws are enforced HERE, server-side (not just in the UI):
 *   - insert INTO or AFTER a node inside a `data-lock` subtree is refused.
 *   - the anchor must exist when targeted; otherwise the append path is used.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expands a component into canonical with server-assigned recursive node ids.
 */
final class ComponentInsert {

	/**
	 * Re-key every data-node in a component's markup to fresh page-unique ids.
	 *
	 * Recurses over the whole subtree (every data-node, not just the root). New ids
	 * are `<base>-<slot>-<n>` where <base> is the component name, <slot> is the
	 * original authored node id (kept for readability), and <n> is the smallest
	 * integer that makes the id unique against $existing. Deterministic given the
	 * same inputs (no randomness — the runtime forbids it and reproducibility wins).
	 *
	 * @param string             $markup    Component HTML (authored data-node ids).
	 * @param string             $base      Component name, used as the id prefix.
	 * @param array<string,true> $existing  Set of data-node ids already on the page.
	 * @return array{markup:string, ids:array<int,string>} Re-keyed markup + new ids.
	 */
	public static function rekey( string $markup, string $base, array $existing ): array {
		$new_ids = array();

		$out = (string) preg_replace_callback(
			'/\bdata-node=(["\'])(.*?)\1/s',
			static function ( array $m ) use ( $base, &$existing, &$new_ids ): string {
				$quote = $m[1];
				$slot  = $m[2];
				$n     = 1;
				do {
					$candidate = $base . '-' . $slot . '-' . $n;
					++$n;
				} while ( isset( $existing[ $candidate ] ) );
				$existing[ $candidate ] = true;
				$new_ids[]              = $candidate;
				return 'data-node=' . $quote . $candidate . $quote;
			},
			$markup
		);

		return array(
			'markup' => $out,
			'ids'    => $new_ids,
		);
	}

	/**
	 * Collect every data-node id present in a canonical string (the page's set).
	 *
	 * @param string $canonical Canonical HTML.
	 * @return array<string,true> Set of ids (id => true).
	 */
	public static function existing_ids( string $canonical ): array {
		$ids = array();
		if ( preg_match_all( '/\bdata-node=(["\'])(.*?)\1/s', $canonical, $m ) ) {
			foreach ( $m[2] as $id ) {
				$ids[ $id ] = true;
			}
		}
		return $ids;
	}

	/**
	 * Splice markup into canonical after the element carrying data-node="$at"
	 * (targeted) or append at the end ($at === ''). Byte-surgical: everything
	 * already in canonical stays byte-identical. Shared by the CLI and REST insert.
	 *
	 * @param string $canonical Current canonical body HTML.
	 * @param string $markup    Markup to insert (already re-keyed).
	 * @param string $at        data-node anchor, or '' to append.
	 * @return string|null New canonical, or null if a targeted anchor was not found
	 *                     or its element is unbalanced.
	 */
	public static function splice( string $canonical, string $markup, string $at ) {
		$block = "\n" . $markup . "\n";

		if ( '' === $at ) {
			// Anchor-less insert (append). It MUST land inside the page's main content
			// region — before </main> — never at the very end of the document, which is
			// AFTER the <s5-part name="site-footer"> part (Item 5d). Appending after the
			// footer would render the new component below the site footer, outside the
			// content. If there is no <main> to append into, refuse loudly (the caller
			// returns 400) rather than silently misplacing the component.
			$close = strripos( $canonical, '</main>' );
			if ( false === $close ) {
				return null;
			}
			return substr( $canonical, 0, $close ) . $block . substr( $canonical, $close );
		}

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

		// Unbalanced — anchor exists but no close tag found; not insertable.
		return null;
	}

	/**
	 * Whether the node carrying data-node="$at" lies inside a data-lock subtree.
	 *
	 * Structural law (Amendment A): no insert on/inside a locked subtree. We check
	 * by locating the anchor's open tag and testing whether any ancestor open tag
	 * (scanning left) carries data-lock without an intervening close of that
	 * element. A pragmatic depth check: if a `data-lock` open tag appears before
	 * the anchor and its matching close appears after, the anchor is locked.
	 *
	 * @param string $canonical Canonical HTML.
	 * @param string $at        Anchor data-node id (empty = append at end, never locked).
	 * @return bool
	 */
	public static function anchor_is_locked( string $canonical, string $at ): bool {
		if ( '' === $at ) {
			return false;
		}
		$pattern = '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bdata-node=(["\'])' . preg_quote( $at, '/' ) . '\2[^>]*>/';
		if ( ! preg_match( $pattern, $canonical, $m, PREG_OFFSET_CAPTURE ) ) {
			return false; // Anchor not found — the caller handles "not found".
		}
		$anchor_pos = (int) $m[0][1];
		$before     = substr( $canonical, 0, $anchor_pos );

		// Any element with data-lock whose close tag is AFTER the anchor wraps it.
		if ( preg_match_all( '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bdata-lock\b[^>]*>/', $before, $locks, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $locks as $lock ) {
				$tag       = $lock[1][0];
				$close     = '</' . $tag . '>';
				$close_pos = strpos( $canonical, $close, (int) $lock[0][1] );
				if ( false !== $close_pos && $close_pos > $anchor_pos ) {
					return true;
				}
			}
		}
		return false;
	}
}
