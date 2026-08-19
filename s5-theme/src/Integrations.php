<?php
/**
 * S5 Framework — Integrations (snippets + injection points), HANDOFF §3.6.
 *
 * ALL third-party head/analytics/pixel/verification code lives here — nothing goes in a
 * raw `<head>` field. Each integration is a named, enable-toggleable snippet with an
 * injection point (Head / Before </body> / After <body> / Footer) and optional
 * Include/Exclude conditions. The theme injects each enabled snippet at its chosen point
 * on requests matching its conditions.
 *
 * Snippets are RAW third-party code by nature (that is the point of an integration), so
 * they are stored verbatim and injected verbatim — this is an admin-only, capability-gated
 * surface (a developer pasting their analytics tag), NOT user input.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persist + inject integration snippets.
 */
final class Integrations {

	/**
	 * Option holding the integrations list.
	 */
	public const OPTION = 's5_integrations';

	/**
	 * The valid injection points → the WP hook they map to.
	 *
	 * @var array<string, array{hook:string,priority:int}>
	 */
	private const POINTS = array(
		'head'       => array(
			'hook'     => 'wp_head',
			'priority' => 20,
		),
		'body_open'  => array(
			'hook'     => 'wp_body_open',
			'priority' => 5,
		),
		'body_close' => array(
			'hook'     => 'wp_footer',
			'priority' => 5,
		),
		'footer'     => array(
			'hook'     => 'wp_footer',
			'priority' => 20,
		),
	);

	/**
	 * Register the injectors on each supported point.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::POINTS as $point => $spec ) {
			add_action(
				$spec['hook'],
				static function () use ( $point ): void {
					self::inject( $point );
				},
				$spec['priority']
			);
		}
	}

	/**
	 * Human labels for the injection points (for the settings UI).
	 *
	 * @return array<string, string>
	 */
	public static function point_labels(): array {
		return array(
			'head'       => __( 'Head', 's5' ),
			'body_open'  => __( 'After <body>', 's5' ),
			'body_close' => __( 'Before </body>', 's5' ),
			'footer'     => __( 'Footer', 's5' ),
		);
	}

	/**
	 * The stored integrations (normalized list).
	 *
	 * @return array<int, array{id:string,name:string,enabled:bool,point:string,code:string,conditions:array<string,mixed>}>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$point = isset( $row['point'] ) ? (string) $row['point'] : 'head';
			$out[] = array(
				'id'         => isset( $row['id'] ) ? (string) $row['id'] : uniqid( 'int_', false ),
				'name'       => isset( $row['name'] ) ? (string) $row['name'] : 'Integration',
				'enabled'    => ! empty( $row['enabled'] ),
				'point'      => isset( self::POINTS[ $point ] ) ? $point : 'head',
				'code'       => isset( $row['code'] ) ? (string) $row['code'] : '',
				'conditions' => isset( $row['conditions'] ) && is_array( $row['conditions'] ) ? $row['conditions'] : array(),
			);
		}
		return $out;
	}

	/**
	 * Persist a full integrations list (already shaped by the caller).
	 *
	 * @param array<int, array<string, mixed>> $rows Integrations.
	 * @return void
	 */
	public static function save( array $rows ): void {
		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$point   = isset( $row['point'] ) ? (string) $row['point'] : 'head';
			$clean[] = array(
				'id'         => isset( $row['id'] ) && '' !== (string) $row['id'] ? (string) $row['id'] : uniqid( 'int_', false ),
				'name'       => sanitize_text_field( isset( $row['name'] ) ? (string) $row['name'] : 'Integration' ),
				'enabled'    => ! empty( $row['enabled'] ),
				'point'      => isset( self::POINTS[ $point ] ) ? $point : 'head',
				// Raw third-party code — stored verbatim by design (admin-only surface).
				'code'       => (string) ( $row['code'] ?? '' ),
				'conditions' => isset( $row['conditions'] ) && is_array( $row['conditions'] ) ? $row['conditions'] : array(),
			);
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * Inject every enabled snippet whose point matches and whose conditions pass.
	 *
	 * @param string $point Injection point key.
	 * @return void
	 */
	private static function inject( string $point ): void {
		foreach ( self::all() as $row ) {
			if ( ! $row['enabled'] || $row['point'] !== $point || '' === trim( $row['code'] ) ) {
				continue;
			}
			if ( ! self::conditions_pass( $row['conditions'] ) ) {
				continue;
			}
			echo "\n" . $row['code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verbatim third-party integration snippet, admin-authored by design (§3.6).
		}
	}

	/**
	 * Evaluate a snippet's Include/Exclude conditions against the current request.
	 * Empty conditions = applies everywhere. Shared shape with template conditions.
	 *
	 * @param array<string, mixed> $conditions Condition groups.
	 * @return bool
	 */
	private static function conditions_pass( array $conditions ): bool {
		// Include: if any include rules exist, at least one must match.
		$include = isset( $conditions['include'] ) && is_array( $conditions['include'] ) ? $conditions['include'] : array();
		if ( ! empty( $include ) ) {
			$any = false;
			foreach ( $include as $rule ) {
				if ( self::rule_matches( (array) $rule ) ) {
					$any = true;
					break;
				}
			}
			if ( ! $any ) {
				return false;
			}
		}
		// Exclude: if any exclude rule matches, block.
		$exclude = isset( $conditions['exclude'] ) && is_array( $conditions['exclude'] ) ? $conditions['exclude'] : array();
		foreach ( $exclude as $rule ) {
			if ( self::rule_matches( (array) $rule ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether one condition rule matches the current request.
	 *
	 * Supported (kept small + real): { type:'post_type', op:'is'|'is not', value:'page' }
	 * and { type:'front_page' }.
	 *
	 * @param array<string, mixed> $rule Rule.
	 * @return bool
	 */
	private static function rule_matches( array $rule ): bool {
		$type  = isset( $rule['type'] ) ? (string) $rule['type'] : '';
		$op    = isset( $rule['op'] ) ? (string) $rule['op'] : 'is';
		$value = isset( $rule['value'] ) ? (string) $rule['value'] : '';

		$matches = false;
		if ( 'post_type' === $type ) {
			$matches = ( get_post_type() === $value );
		} elseif ( 'front_page' === $type ) {
			$matches = is_front_page();
		} elseif ( 'post' === $type ) {
			// A specific page/post by ID (value is the numeric post ID).
			$matches = ( is_singular() && get_the_ID() === (int) $value );
		}
		return ( 'is not' === $op ) ? ! $matches : $matches;
	}
}
