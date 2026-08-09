<?php
/**
 * S5 round-trip harness helper — extract sanctioned head artifacts from a source
 * HTML file, on the HOST, so tests/acceptance-step3.sh can assert the served head
 * against the source without duplicating parsing logic in bash.
 *
 * IMPORTANT — byte fidelity: the served head re-emits the source tags VERBATIM
 * (entities and all: a source `&amp;` is served as `&amp;`). So this helper must
 * report the RAW source bytes of each artifact, NOT a DOM-decoded value. We use
 * DOM only to LOCATE the relevant element, then slice the original source bytes
 * for its value — otherwise `&amp;` would decode to `&` and never match the
 * served output.
 *
 * Usage:
 *   php extract-head.php <file.html> title       -> raw <title> inner bytes
 *   php extract-head.php <file.html> description  -> raw meta description content bytes
 *   php extract-head.php <file.html> canonical    -> raw canonical href bytes
 *   php extract-head.php <file.html> og           -> og:* property \t raw content, per line
 *   php extract-head.php <file.html> jsonld        -> each JSON-LD block (raw), NUL-separated
 *
 * @package S5\Tests
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "usage: extract-head.php <file> <title|description|canonical|og|jsonld>\n" );
	exit( 2 );
}

$file = $argv[1];
$what = $argv[2];

$html = file_get_contents( $file );
if ( false === $html ) {
	fwrite( STDERR, "cannot read $file\n" );
	exit( 2 );
}

// Restrict to the <head> region (raw bytes) so body content never interferes.
$head_html = '';
if ( preg_match( '/<head\b[^>]*>(.*?)<\/head>/is', $html, $hm ) ) {
	$head_html = $hm[1];
} else {
	exit( 0 );
}

switch ( $what ) {
	case 'title':
		if ( preg_match( '/<title\b[^>]*>(.*?)<\/title>/is', $head_html, $m ) ) {
			echo $m[1];
		}
		break;

	case 'description':
		// Find the <meta ... name="description" ... content="..."> raw content bytes.
		if ( preg_match_all( '/<meta\b[^>]*>/i', $head_html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\bname\s*=\s*"description"/i', $tag )
					&& preg_match( '/\bcontent\s*=\s*"([^"]*)"/i', $tag, $c ) ) {
					echo $c[1];
					break;
				}
			}
		}
		break;

	case 'canonical':
		if ( preg_match_all( '/<link\b[^>]*>/i', $head_html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\brel\s*=\s*"canonical"/i', $tag )
					&& preg_match( '/\bhref\s*=\s*"([^"]*)"/i', $tag, $h ) ) {
					echo $h[1];
					break;
				}
			}
		}
		break;

	case 'og':
		// Emit og:* property + raw content, one per line.
		$out = array();
		if ( preg_match_all( '/<meta\b[^>]*>/i', $head_html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\bproperty\s*=\s*"(og:[^"]*)"/i', $tag, $p )
					&& preg_match( '/\bcontent\s*=\s*"([^"]*)"/i', $tag, $c ) ) {
					$out[] = strtolower( $p[1] ) . "\t" . $c[1];
				}
			}
		}
		echo implode( "\n", $out );
		break;

	case 'jsonld':
		// NUL-separate blocks; each is the RAW inner bytes, trimmed.
		$blocks = array();
		if ( preg_match_all( '/<script\b[^>]*type\s*=\s*"application\/ld\+json"[^>]*>(.*?)<\/script>/is', $head_html, $m ) ) {
			foreach ( $m[1] as $body ) {
				$blocks[] = trim( $body );
			}
		}
		echo implode( "\0", $blocks );
		break;

	default:
		fwrite( STDERR, "unknown selector: $what\n" );
		exit( 2 );
}
