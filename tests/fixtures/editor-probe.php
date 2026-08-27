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

// Snapshot the page's TRUE starting canonical before anything mutates it, so
// the probe can hand it back untouched at the end. The suite re-imports the
// corpus afterwards anyway, but a fixture that quietly leaves a test string in
// real content is a trap for anyone running it standalone.
$probe_origin = S5\Storage::get_canonical_html( $post_id );

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

/* =====================================================================
 * AUDIT 2026-08-26 REGRESSION GUARDS
 * Each block below fails on the pre-fix code and passes after. They exist
 * because every one of these defects shipped through a fully green gate.
 * ===================================================================== */

$audit_before = S5\Storage::get_canonical_html( $post_id );

/* --- A1 (audit C1). PER-OBJECT capability on the save door -------------
 * `can_save()` checks only the SITE-WIDE `edit_posts` cap, so before the fix a
 * Contributor — whom WordPress refuses `edit_post` on a published page — could
 * rewrite EVERY framework page through this door. Proven exploitable. */
$esc_uid = username_exists( 's5probe_contributor' );
if ( ! $esc_uid ) {
	$esc_uid = wp_insert_user(
		array(
			'user_login' => 's5probe_contributor',
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => 'contributor',
		)
	);
}
$esc_blocked = false;
if ( ! is_wp_error( $esc_uid ) ) {
	wp_set_current_user( (int) $esc_uid );
	// Sanity: WordPress itself must deny this user, or the test proves nothing.
	$esc_wp_denies = ! current_user_can( 'edit_post', $post_id );
	$esc_req       = $make( $post_id );
	$esc_req->set_param( 'mode', 'slots' );
	$esc_req->set_param(
		'slots',
		array(
			array(
				'node'  => 'about-hero-title',
				'type'  => 'text',
				'value' => 'ESCALATION MARKER',
			),
		)
	);
	$esc_resp    = S5\Rest::handle_save( $esc_req );
	$esc_refused = is_wp_error( $esc_resp ) || empty( $esc_resp->get_data()['ok'] );
	wp_set_current_user( 1 );
	$esc_blocked = $esc_wp_denies
		&& $esc_refused
		&& false === strpos( S5\Storage::get_canonical_html( $post_id ), 'ESCALATION MARKER' );
}
echo 'SEC_PRIVESC_BLOCKED=' . ( $esc_blocked ? 'YES' : 'NO' ) . "\n";

/* --- A2 (audit C2). URL-scheme rules cover every url-valued attribute ---
 * The rule used to scan href/src ONLY, with a leading-whitespace trim. Several
 * vectors bypassed it AND were confirmed to execute in a real browser. */
$sec2_cases       = array(
	'formaction' => '<button data-node="probe-x" formaction="javascript:alert(1)">x</button>',
	'srcdoc'     => '<iframe data-node="probe-x" srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
	'xlink'      => '<svg data-node="probe-x"><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
	'ctrlchar'   => "<a data-node=\"probe-x\" href=\"java\tscript:alert(1)\">x</a>",
	'entity'     => '<a data-node="probe-x" href="java&#115;cript:alert(1)">x</a>',
	'datahtml'   => '<a data-node="probe-x" href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;">x</a>',
);
$sec2_all_blocked = true;
foreach ( $sec2_cases as $sec2_html ) {
	$sec2_req = $make( $post_id );
	$sec2_req->set_param( 'mode', 'canonical' );
	$sec2_req->set_param( 'canonical', $audit_before . $sec2_html );
	$sec2_resp = S5\Rest::handle_save( $sec2_req );
	$sec2_data = is_wp_error( $sec2_resp ) ? array() : $sec2_resp->get_data();
	if ( ! empty( $sec2_data['ok'] ) ) {
		$sec2_all_blocked = false;
	}
}
$sec2_all_blocked = $sec2_all_blocked && S5\Storage::get_canonical_html( $post_id ) === $audit_before;
echo 'SEC_URL_ATTRS_REJECTED=' . ( $sec2_all_blocked ? 'YES' : 'NO' ) . "\n";

/* --- A3. The same sweep must NOT create false positives ---------------
 * This validator's history is false positives on correct authoring, so every
 * tightening is paired with proof that legitimate markup still saves. */
$fp_html = $audit_before
	. '<a data-node="probe-fp1" href="/about/">rel</a>'
	. '<a data-node="probe-fp2" href="https://example.com">abs</a>'
	. '<a data-node="probe-fp3" href="mailto:a@b.com">mail</a>'
	. '<a data-node="probe-fp4" href="/x/" target="_blank" rel="noopener noreferrer">tab</a>'
	. '<img data-node="probe-fp5" src="data:image/png;base64,iVBORw0KGgo=" alt="inline">';
$fp_req  = $make( $post_id );
$fp_req->set_param( 'mode', 'canonical' );
$fp_req->set_param( 'canonical', $fp_html );
$fp_resp = S5\Rest::handle_save( $fp_req );
$fp_ok   = ! is_wp_error( $fp_resp ) && ! empty( $fp_resp->get_data()['ok'] );
echo 'SEC_URL_NO_FALSE_POSITIVE=' . ( $fp_ok ? 'YES' : 'NO' ) . "\n";

// Restore before the next block.
$fp_restore = $make( $post_id );
$fp_restore->set_param( 'mode', 'canonical' );
$fp_restore->set_param( 'canonical', $audit_before );
S5\Rest::handle_save( $fp_restore );

/* --- A4 (audit M1). Shortcodes in a TEXT slot stay inert ---------------
 * esc_html() does not escape [ or ], so `[gallery]` typed into a plain text
 * field flipped the page dynamic and ran do_shortcode() for every visitor. */
$sc_req = $make( $post_id );
$sc_req->set_param( 'mode', 'slots' );
$sc_req->set_param(
	'slots',
	array(
		array(
			'node'  => 'about-hero-title',
			'type'  => 'text',
			'value' => '[gallery id=1]',
		),
	)
);
$sc_resp  = S5\Rest::handle_save( $sc_req );
$sc_canon = S5\Storage::get_canonical_html( $post_id );
$sc_ok    = ! is_wp_error( $sc_resp )
	&& ! empty( $sc_resp->get_data()['ok'] )
	// Stored inert (encoded), never as a live shortcode.
	&& false === strpos( $sc_canon, '[gallery' )
	&& false !== strpos( $sc_canon, '&#91;gallery' );
echo 'SEC_SHORTCODE_INERT=' . ( $sc_ok ? 'YES' : 'NO' ) . "\n";

/* --- A5 (audit H3). slots/class enforce the roles matrix ---------------
 * `editor.content` and `editor.style` existed in the matrix but were enforced
 * NOWHERE, so denying a role Content still let it POST slot saves directly. */
$rest_src   = (string) file_get_contents( get_stylesheet_directory() . '/src/Rest.php' );
$gate_slots = false !== strpos( $rest_src, "Roles::can( 'editor.content' )" );
$gate_class = false !== strpos( $rest_src, "Roles::can( 'editor.style' )" );
echo 'ROLE_GATES_WIRED=' . ( $gate_slots && $gate_class ? 'YES' : 'NO' ) . "\n";

/* --- A6 (audit M2). Token values enforce the CSS value policy ----------
 * /tokens and the element-CSS door write the SAME compiled bundle; the token
 * door lacked the script-bearing-value check the element door enforces. */
$tok_bad  = is_wp_error( S5\Styles::update_token( '--s5-color-brand', 'red url(javascript:alert(1))' ) );
$tok_bad2 = is_wp_error( S5\Styles::update_token( '--s5-color-brand', 'expression(alert(1))' ) );
echo 'TOKEN_VALUE_POLICY=' . ( $tok_bad && $tok_bad2 ? 'YES' : 'NO' ) . "\n";

/* --- A7 (audit H1/H2). Editor client-side save contract ----------------
 * Both editor defects shipped through a green gate because NOTHING asserted on
 * the client. Static guards in the acceptance-preflight-guards.sh style: cheap,
 * and they would have caught both. (Full behavior needs a browser suite.) */
$assets     = get_stylesheet_directory() . '/assets/editor/';
$panel_js   = (string) file_get_contents( $assets . 'panel.js' );
$editor_js  = (string) file_get_contents( $assets . 'editor.js' );
$editor_css = (string) file_get_contents( $assets . 'editor.css' );
$client_ok  = // H1: the shell must consult staged edits before going clean.
	false !== strpos( $editor_js, 'hasPendingEdits' )
	&& false !== strpos( $panel_js, 'registerPendingEdits' )
	// H2: the button's markup must never be rewritten wholesale.
	&& false === strpos( $panel_js, 'updateBtn.innerHTML' )
	&& false === strpos( $panel_js, 'updateBtn.textContent =' )
	// H2: the dot must be re-queried, never cached across a label swap.
	&& false !== strpos( $editor_js, 'function dirtyDot' )
	// Toast must exist in BOTH js and css, or success feedback is invisible.
	&& false !== strpos( $editor_js, 's5e-toast' )
	&& false !== strpos( $editor_css, '.s5e-toast' )
	&& false !== strpos( $editor_css, '.s5e-toast-stack' );
echo 'EDITOR_CLIENT_CONTRACT=' . ( $client_ok ? 'YES' : 'NO' ) . "\n";

/* --- A8. Structure modes reach canonical (were untested at ANY layer) ---
 * insert/reorder/delete/duplicate had ZERO coverage anywhere, and delete is
 * irreversible. Smoke guard that the door dispatches them and canonical stays
 * well-formed — not yet a full behavioral suite. */
$struct_before = S5\Storage::get_canonical_html( $post_id );
$struct_target = '';
$sp            = new WP_HTML_Tag_Processor( $struct_before );
while ( $sp->next_tag() ) {
	$n = (string) $sp->get_attribute( 'data-node' );
	if ( '' !== $n ) {
		$struct_target = $n;
		break;
	}
}
$struct_ok = '' !== $struct_target;
if ( $struct_ok ) {
	$dup = $make( $post_id );
	$dup->set_param( 'mode', 'duplicate' );
	$dup->set_param( 'node', $struct_target );
	$dup_resp  = S5\Rest::handle_save( $dup );
	$dup_data  = is_wp_error( $dup_resp ) ? array() : $dup_resp->get_data();
	$after_dup = S5\Storage::get_canonical_html( $post_id );
	$struct_ok = ! empty( $dup_data['ok'] )
		&& $after_dup !== $struct_before
		&& strlen( $after_dup ) > strlen( $struct_before );
}
// Restore regardless of outcome — this probe must not mutate the corpus.
$struct_restore = $make( $post_id );
$struct_restore->set_param( 'mode', 'canonical' );
$struct_restore->set_param( 'canonical', $struct_before );
S5\Rest::handle_save( $struct_restore );
echo 'STRUCTURE_MODES_OK=' . ( $struct_ok ? 'YES' : 'NO' ) . "\n";

// Final hygiene: leave `about` exactly as this probe FOUND it — the snapshot
// taken before section 1, not the post-section-1 state.
$final_restore = $make( $post_id );
$final_restore->set_param( 'mode', 'canonical' );
$final_restore->set_param( 'canonical', $probe_origin );
S5\Rest::handle_save( $final_restore );
