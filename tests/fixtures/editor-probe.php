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

/* --- 2. invalid canonical save is rejected; canonical unchanged -------- */
$before  = S5\Storage::get_canonical_html( $post_id );
$bad     = str_replace( '<main', '<main class="zzz-not-a-real-vocab-class"', $before );
$badreq  = $make( $post_id );
$badreq->set_param( 'mode', 'canonical' );
$badreq->set_param( 'canonical', $bad );
$badresp = S5\Rest::handle_save( $badreq );
$baddata = is_wp_error( $badresp ) ? array() : $badresp->get_data();
echo 'INVALID_REJECTED=' . ( empty( $baddata['ok'] ) ? 'YES' : 'NO' ) . "\n";
echo 'DIAGNOSTICS=' . ( ! empty( $baddata['diagnostics'] ) ? 'YES' : 'NO' ) . "\n";
echo 'CANON_UNCHANGED=' . ( S5\Storage::get_canonical_html( $post_id ) === $before ? 'YES' : 'NO' ) . "\n";

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
