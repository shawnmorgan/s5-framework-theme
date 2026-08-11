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

/* --- 4. style tab reads real tokens ----------------------------------- */
$tokens = S5\Styles::read_tokens();
$count  = 0;
foreach ( $tokens as $group ) {
	$count += is_array( $group ) ? count( $group ) : 0;
}
echo 'TOKENS_OK=' . ( $count > 0 ? 'YES' : 'NO' ) . "\n";
