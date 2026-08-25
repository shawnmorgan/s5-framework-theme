<?php
/**
 * Editor MVP acceptance probe (run via `wp eval-file`).
 *
 * Exercises the editor's ONE save door (S5\Rest::handle_save) server-side and
 * prints KEY=YES/NO lines the acceptance script greps. Not a PHPUnit test — it
 * runs inside real WordPress through WP-CLI so the whole pipeline is real.
 *
 * NOTE: no `declare(strict_types=1)` — `wp eval-file` wraps this in eval(), where
 * a strict_types declaration is not the first statement and would fatal.
 *
 * @package S5\Plugin
 */

// Act as an administrator (CLI has no current user by default).
wp_set_current_user( 1 );

$about   = get_page_by_path( 'about', OBJECT, 'page' );
$post_id = $about instanceof WP_Post ? (int) $about->ID : 0;
if ( ! $post_id ) {
	echo "SLOT_OK=NO\nINVALID_REJECTED=NO\nCANON_UNCHANGED=NO\nDIAGNOSTICS=NO\nHEAD_OK=NO\nTOKENS_OK=NO\n";
	echo "SEC_ONCLICK_REJECTED=NO\nSEC_JSURL_REJECTED=NO\nSEC_SCRIPT_REJECTED=NO\n";
	return;
}

/** Build a save request. */
$make = static function ( int $id ): WP_REST_Request {
	$r = new WP_REST_Request( 'POST', '/s5/v1/pages/' . $id . '/save' );
	$r->set_param( 'id', $id );
	return $r;
};

/* --- 1. slot save round-trips through persist_page --------------------- */
$slot = $make( $post_id );
$slot->set_param( 'mode', 'slots' );
$slot->set_param(
	'slots',
	array(
		array(
			'node'  => 'about-hero-title',
			'type'  => 'text',
			'value' => 'PROBE SLOT EDIT',
		),
	)
);
$resp    = S5\Rest::handle_save( $slot );
$slot_ok = ! is_wp_error( $resp ) && ! empty( $resp->get_data()['ok'] );
$canon   = S5\Storage::get_canonical_html( $post_id );
$render  = S5\Storage::get_render_html( $post_id );
$slot_ok = $slot_ok
	&& false !== strpos( $canon, 'PROBE SLOT EDIT' )
	&& false !== strpos( $render, 'PROBE SLOT EDIT' )
	&& false !== strpos( $canon, 'about-hero-title' );
echo 'SLOT_OK=' . ( $slot_ok ? 'YES' : 'NO' ) . "\n";

/* --- 2. Unknown class is ROLE-AWARE (role-aware validator, v0.18.0) -------
 * The Developer defines the vocabulary, so an unknown class in the code lens is a
 * WARNING (proceeds), NOT a hard error — the closed-vocabulary wall protects
 * marketers/clients, not the dev who owns the stylesheet. So:
 *   - a DEVELOPER save with an unknown class is ACCEPTED (with a warning diagnostic);
 *   - a NON-DEVELOPER (Author→client) save with the same class is REJECTED, unchanged.
 * Security rules (section 5) still reject for EVERYONE regardless of role. */
$bad_class = 'zzz-not-a-real-vocab-class';

// (a) Developer (user 1) — unknown class WARNS and proceeds; canonical changes.
wp_set_current_user( 1 );
$dev_before = S5\Storage::get_canonical_html( $post_id );
$dev_bad    = str_replace( '<main', '<main class="' . $bad_class . '"', $dev_before );
$dev_req    = $make( $post_id );
$dev_req->set_param( 'mode', 'canonical' );
$dev_req->set_param( 'canonical', $dev_bad );
$dev_resp   = S5\Rest::handle_save( $dev_req );
$dev_data   = is_wp_error( $dev_resp ) ? array() : $dev_resp->get_data();
$dev_ok     = ! empty( $dev_data['ok'] );
$dev_changed = S5\Storage::get_canonical_html( $post_id ) !== $dev_before;
echo 'DEV_UNKNOWN_ALLOWED=' . ( $dev_ok && $dev_changed ? 'YES' : 'NO' ) . "\n";

// Restore the good canonical for the rest of the probe.
S5\Compiler::persist_page( $post_id, $dev_before );

// (b) Non-developer who CAN reach a canonical save — a MARKETER (WP Editor → S5
// marketer). A marketer holds editor.advanced (so the canonical save is not blocked
// by the role gate), which is exactly the role that must still hit the closed-
// vocabulary wall: the unknown class is REJECTED with diagnostics, canonical
// unchanged. (A client/author can't reach a canonical save at all — a role gate, not
// a vocabulary rejection — so a marketer is the correct role to prove the boundary.)
$mkt_id = username_exists( 's5probe_marketer' );
if ( ! $mkt_id ) {
	$mkt_id = wp_insert_user(
		array(
			'user_login' => 's5probe_marketer',
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => 'editor',
		)
	);
}
if ( ! is_wp_error( $mkt_id ) ) {
	wp_set_current_user( (int) $mkt_id );
	$mkt_before = S5\Storage::get_canonical_html( $post_id );
	$mkt_bad    = str_replace( '<main', '<main class="' . $bad_class . '"', $mkt_before );
	$mkt_req    = $make( $post_id );
	$mkt_req->set_param( 'mode', 'canonical' );
	$mkt_req->set_param( 'canonical', $mkt_bad );
	$mkt_resp   = S5\Rest::handle_save( $mkt_req );
	$mkt_data   = is_wp_error( $mkt_resp ) ? array() : $mkt_resp->get_data();
	$mkt_rejected  = empty( $mkt_data['ok'] );
	$mkt_diag      = ! empty( $mkt_data['diagnostics'] );
	$mkt_unchanged = S5\Storage::get_canonical_html( $post_id ) === $mkt_before;
	echo 'NONDEV_UNKNOWN_REJECTED=' . ( $mkt_rejected ? 'YES' : 'NO' ) . "\n";
	echo 'DIAGNOSTICS=' . ( $mkt_diag ? 'YES' : 'NO' ) . "\n";
	echo 'CANON_UNCHANGED=' . ( $mkt_unchanged ? 'YES' : 'NO' ) . "\n";
} else {
	echo "NONDEV_UNKNOWN_REJECTED=NO\nDIAGNOSTICS=NO\nCANON_UNCHANGED=NO\n";
}
// Back to admin for the remaining sections (security probes run as developer;
// security rules reject for every role, so the role here does not matter).
wp_set_current_user( 1 );

/* --- 3. head save updates _s5_head ------------------------------------ */
$headreq = $make( $post_id );
$headreq->set_param( 'mode', 'head' );
$headreq->set_param( 'description', 'PROBE HEAD DESCRIPTION' );
$headresp = S5\Rest::handle_save( $headreq );
$head_ok  = ! is_wp_error( $headresp ) && ! empty( $headresp->get_data()['ok'] );
$head     = S5\Storage::get_head( $post_id );
$head_ok  = $head_ok && ( ( $head['description'] ?? '' ) === 'PROBE HEAD DESCRIPTION' );
echo 'HEAD_OK=' . ( $head_ok ? 'YES' : 'NO' ) . "\n";

/* --- 5. security fixtures (D-fix #6 regression guard) ------------------
 * The save door must independently reject the same three attack patterns
 * the Authoring Contract validator now blocks at validate/import: on*
 * event-handler attributes, javascript: URLs, and a mid-body <script>.
 * Each is planted into a KNOWN-GOOD canonical (the current, already-valid
 * $before snapshot) so a rejection can only be attributed to the planted
 * payload, not to some other pre-existing issue. Canonical must be left
 * byte-unchanged after each rejected save, same contract as check 2. ---- */
$sec_before = S5\Storage::get_canonical_html( $post_id );

// --- 5a. onclick event-handler attribute --------------------------------
$sec_onclick_html = str_replace(
	'<main',
	'<main onclick="alert(1)"',
	$sec_before
);
$sec_onclick_req = $make( $post_id );
$sec_onclick_req->set_param( 'mode', 'canonical' );
$sec_onclick_req->set_param( 'canonical', $sec_onclick_html );
$sec_onclick_resp = S5\Rest::handle_save( $sec_onclick_req );
$sec_onclick_data = is_wp_error( $sec_onclick_resp ) ? array() : $sec_onclick_resp->get_data();
$sec_onclick_ok   = empty( $sec_onclick_data['ok'] )
	&& ! empty( $sec_onclick_data['diagnostics'] )
	&& S5\Storage::get_canonical_html( $post_id ) === $sec_before;
echo 'SEC_ONCLICK_REJECTED=' . ( $sec_onclick_ok ? 'YES' : 'NO' ) . "\n";

// --- 5b. javascript: URL -------------------------------------------------
$sec_jsurl_html = '<a href="javascript:alert(1)">x</a>' . $sec_before;
$sec_jsurl_req  = $make( $post_id );
$sec_jsurl_req->set_param( 'mode', 'canonical' );
$sec_jsurl_req->set_param( 'canonical', $sec_jsurl_html );
$sec_jsurl_resp = S5\Rest::handle_save( $sec_jsurl_req );
$sec_jsurl_data = is_wp_error( $sec_jsurl_resp ) ? array() : $sec_jsurl_resp->get_data();
$sec_jsurl_ok   = empty( $sec_jsurl_data['ok'] )
	&& ! empty( $sec_jsurl_data['diagnostics'] )
	&& S5\Storage::get_canonical_html( $post_id ) === $sec_before;
echo 'SEC_JSURL_REJECTED=' . ( $sec_jsurl_ok ? 'YES' : 'NO' ) . "\n";

// --- 5c. mid-body <script> ------------------------------------------------
$sec_script_html = $sec_before . '<script>alert(1)</script>';
$sec_script_req  = $make( $post_id );
$sec_script_req->set_param( 'mode', 'canonical' );
$sec_script_req->set_param( 'canonical', $sec_script_html );
$sec_script_resp = S5\Rest::handle_save( $sec_script_req );
$sec_script_data = is_wp_error( $sec_script_resp ) ? array() : $sec_script_resp->get_data();
$sec_script_ok   = empty( $sec_script_data['ok'] )
	&& ! empty( $sec_script_data['diagnostics'] )
	&& S5\Storage::get_canonical_html( $post_id ) === $sec_before;
echo 'SEC_SCRIPT_REJECTED=' . ( $sec_script_ok ? 'YES' : 'NO' ) . "\n";

/* --- 4. style tab reads real tokens ----------------------------------- */
$tokens = S5\Styles::read_tokens();
$count  = 0;
foreach ( $tokens as $group ) {
	$count += is_array( $group ) ? count( $group ) : 0;
}
echo 'TOKENS_OK=' . ( $count > 0 ? 'YES' : 'NO' ) . "\n";
