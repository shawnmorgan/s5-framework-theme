<?php
/**
 * S5 Framework — roles & access (R1 / HANDOFF §4).
 *
 * Three S5 roles — developer · marketer · client — decide which editor tabs render and
 * which settings screens exist. Resolution is at the S5 level, not the raw WP role:
 *
 *   1. a PER-USER override (if set) wins;
 *   2. else the WP-role mapping seeds a default (Administrator→developer, Editor→marketer,
 *      Author→client, anything else→client — the most restrictive).
 *
 * The capability MATRIX (which S5 role may do which thing) and the per-user overrides are
 * DATA (options), edited on the Roles screen. Enforcement is server-side on every surface
 * and route — hidden AND blocked — including the case where the user still holds WP
 * `manage_options` but resolves to a more restrictive S5 role: `Roles::can()` is the gate,
 * not `current_user_can()`.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * S5 role resolution + capability checks + the persisted matrix/overrides.
 */
final class Roles {

	/**
	 * Option holding the capability matrix: cap_key => array(developer,marketer,client bools).
	 */
	public const OPTION_MATRIX = 's5_roles_matrix';

	/**
	 * Option holding per-user overrides: user_id => role_key.
	 */
	public const OPTION_OVERRIDES = 's5_roles_overrides';

	/**
	 * The three S5 roles, most→least privileged.
	 *
	 * @var array<int, string>
	 */
	public const ROLES = array( 'developer', 'marketer', 'client' );

	/**
	 * The capability keys the matrix governs, with a label and the default per-role
	 * grant (developer, marketer, client) seeded from §4. Adding a governed surface =
	 * one entry here + a `can()` check at that surface.
	 *
	 * @return array<string, array{label:string,default:array{0:bool,1:bool,2:bool}}>
	 */
	public static function capabilities(): array {
		return array(
			// Canvas editor.
			'editor.open'              => array(
				'label'   => 'Open the canvas editor',
				'default' => array( true, true, true ),
			),
			'editor.content'           => array(
				'label'   => 'Content tab (text · media · links)',
				'default' => array( true, true, true ),
			),
			'editor.style'             => array(
				'label'   => 'Style tab (variants · sizing · spacing)',
				'default' => array( true, true, true ),
			),
			'editor.advanced'          => array(
				'label'   => 'Advanced tab (tag · SEO · visibility)',
				'default' => array( true, true, false ),
			),
			'editor.structure'         => array(
				'label'   => 'Structure tree (disable / re-enable)',
				'default' => array( true, true, false ),
			),
			'editor.embed'             => array(
				'label'   => 'Embed primitive (raw HTML)',
				'default' => array( true, false, false ),
			),
			'editor.page_settings'     => array(
				'label'   => 'Page Settings gear (meta · schema)',
				'default' => array( true, true, false ),
			),
			// Theme Settings.
			'settings.view'            => array(
				'label'   => 'See Theme Settings at all',
				'default' => array( true, true, false ),
			),
			'settings.general'         => array(
				'label'   => 'General (identity · redirects · deploy)',
				'default' => array( true, true, false ),
			),
			'settings.styleguide_view' => array(
				'label'   => 'StyleGuide — view tokens',
				'default' => array( true, true, false ),
			),
			'settings.styleguide_edit' => array(
				'label'   => 'StyleGuide — edit tokens',
				'default' => array( true, false, false ),
			),
			'settings.templates'       => array(
				'label'   => 'Templates — edit',
				'default' => array( true, false, false ),
			),
			'settings.components'      => array(
				'label'   => 'Components — edit',
				'default' => array( true, false, false ),
			),
			'settings.integrations'    => array(
				'label'   => 'Integrations — edit',
				'default' => array( true, false, false ),
			),
			'settings.css'             => array(
				'label'   => 'CSS / Tailwind — edit',
				'default' => array( true, false, false ),
			),
			'settings.roles'           => array(
				'label'   => 'Roles — edit the matrix',
				'default' => array( true, false, false ),
			),
			'deploy.run'               => array(
				'label'   => 'Build & Deploy — run',
				'default' => array( true, false, false ),
			),
		);
	}

	/**
	 * The persisted matrix, merged over the defaults (so a new capability is governed
	 * by its default until an admin edits it).
	 *
	 * @return array<string, array{0:bool,1:bool,2:bool}>
	 */
	public static function matrix(): array {
		$stored = get_option( self::OPTION_MATRIX, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( self::capabilities() as $key => $def ) {
			if ( isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) && 3 === count( $stored[ $key ] ) ) {
				$out[ $key ] = array(
					(bool) $stored[ $key ][0],
					(bool) $stored[ $key ][1],
					(bool) $stored[ $key ][2],
				);
			} else {
				$out[ $key ] = $def['default'];
			}
		}
		return $out;
	}

	/**
	 * The S5 role a user resolves to (per-user override, else WP-role mapping).
	 *
	 * @param int $user_id User id (0 = current user).
	 * @return string One of ROLES.
	 */
	public static function role_for_user( int $user_id = 0 ): string {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return 'client'; // Logged-out = most restrictive (should never reach a gate).
		}

		$overrides = get_option( self::OPTION_OVERRIDES, array() );
		$overrides = is_array( $overrides ) ? $overrides : array();
		if ( isset( $overrides[ $user_id ] ) && in_array( (string) $overrides[ $user_id ], self::ROLES, true ) ) {
			return (string) $overrides[ $user_id ];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'client';
		}
		$wp_roles = (array) $user->roles;
		if ( in_array( 'administrator', $wp_roles, true ) ) {
			return 'developer';
		}
		if ( in_array( 'editor', $wp_roles, true ) ) {
			return 'marketer';
		}
		// Author and everything else → client (most restrictive default).
		return 'client';
	}

	/**
	 * The index of a role in the matrix columns (developer=0, marketer=1, client=2).
	 *
	 * @param string $role Role key.
	 * @return int
	 */
	private static function role_index( string $role ): int {
		$i = array_search( $role, self::ROLES, true );
		return false === $i ? 2 : (int) $i;
	}

	/**
	 * Whether a user's resolved S5 role is granted a capability by the matrix.
	 *
	 * This is THE authorization gate for S5 surfaces — used instead of
	 * current_user_can() so a WP-Administrator pinned to a lower S5 role is actually
	 * restricted (R1: hidden AND blocked, including the client-holds-Administrator case).
	 *
	 * @param string $cap_key Capability key (see capabilities()).
	 * @param int    $user_id User id (0 = current).
	 * @return bool
	 */
	public static function can( string $cap_key, int $user_id = 0 ): bool {
		$matrix = self::matrix();
		if ( ! isset( $matrix[ $cap_key ] ) ) {
			return false; // Unknown capability = deny (fail closed).
		}
		$role = self::role_for_user( $user_id );
		$idx  = self::role_index( $role );
		return (bool) $matrix[ $cap_key ][ $idx ];
	}

	/**
	 * Persist the matrix (validated shape).
	 *
	 * @param array<string, array<int, mixed>> $matrix Raw matrix.
	 * @return void
	 */
	public static function save_matrix( array $matrix ): void {
		$clean = array();
		foreach ( self::capabilities() as $key => $def ) {
			$row = isset( $matrix[ $key ] ) && is_array( $matrix[ $key ] ) ? $matrix[ $key ] : $def['default'];
			// Developer is always allowed everything (the owner role); never lock it out.
			$clean[ $key ] = array(
				true,
				! empty( $row[1] ),
				! empty( $row[2] ),
			);
		}
		update_option( self::OPTION_MATRIX, $clean );
	}

	/**
	 * Persist a per-user override (empty role clears it).
	 *
	 * @param int    $user_id User id.
	 * @param string $role    Role key or '' to clear.
	 * @return void
	 */
	public static function save_override( int $user_id, string $role ): void {
		$overrides = get_option( self::OPTION_OVERRIDES, array() );
		$overrides = is_array( $overrides ) ? $overrides : array();
		if ( '' === $role ) {
			unset( $overrides[ $user_id ] );
		} elseif ( in_array( $role, self::ROLES, true ) ) {
			$overrides[ $user_id ] = $role;
		}
		update_option( self::OPTION_OVERRIDES, $overrides );
	}
}
