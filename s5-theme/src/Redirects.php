<?php
/**
 * S5 Framework — redirects manager (HANDOFF §3.1).
 *
 * A from → to redirect table (301/302) applied on template_redirect, before WordPress
 * resolves the request. Admin-managed on the General settings screen. Kept deliberately
 * small: exact-path matches (leading-slash normalized), so it is predictable and cheap.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persist + apply from→to redirects.
 */
final class Redirects {

	/**
	 * Option holding the redirect rows.
	 */
	public const OPTION = 's5_redirects';

	/**
	 * Register the early redirect check.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_redirect' ), 1 );
	}

	/**
	 * The stored redirects: [ from, to, code ].
	 *
	 * @return array<int, array{from:string,to:string,code:int}>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['from'] ) || empty( $row['to'] ) ) {
				continue;
			}
			$code  = isset( $row['code'] ) ? (int) $row['code'] : 301;
			$out[] = array(
				'from' => self::normalize_path( (string) $row['from'] ),
				'to'   => (string) $row['to'],
				'code' => ( 302 === $code ) ? 302 : 301,
			);
		}
		return $out;
	}

	/**
	 * Persist a redirect list.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return void
	 */
	public static function save( array $rows ): void {
		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$from = isset( $row['from'] ) ? self::normalize_path( (string) $row['from'] ) : '';
			$to   = isset( $row['to'] ) ? esc_url_raw( (string) $row['to'] ) : '';
			if ( '' === $from || '' === $to ) {
				continue;
			}
			$code    = isset( $row['code'] ) && 302 === (int) $row['code'] ? 302 : 301;
			$clean[] = array(
				'from' => $from,
				'to'   => $to,
				'code' => $code,
			);
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * Redirect the current request if its path matches a rule.
	 *
	 * @return void
	 */
	public static function maybe_redirect(): void {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? self::normalize_path( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $path ) {
			return;
		}
		foreach ( self::all() as $rule ) {
			if ( $rule['from'] === $path ) {
				wp_safe_redirect( $rule['to'], $rule['code'] );
				exit;
			}
		}
	}

	/**
	 * Normalize a path: strip the query string, ensure a single leading slash, drop a
	 * trailing slash (except root).
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private static function normalize_path( string $path ): string {
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		$path = '/' . ltrim( trim( $path ), '/' );
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		return $path;
	}
}
