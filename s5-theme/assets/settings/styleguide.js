/*
 * S5 Framework — StyleGuide "View Styleguide" fullscreen modal (settings §3.4).
 *
 * The specimen is static markup already rendered server-side inside the modal
 * container; this file only toggles visibility (same .open-class pattern as
 * the Component Builder modal in components.js) — no REST calls involved.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-s5-settings]' );
	if ( ! root ) {
		return;
	}
	var modal = root.querySelector( '[data-s5-sg-modal]' );
	var openBtn = root.querySelector( '[data-s5-sg-view]' );
	if ( ! modal || ! openBtn ) {
		return;
	}
	var closeBtn = modal.querySelector( '[data-s5-sg-close]' );

	function showModal() {
		modal.classList.add( 'open' );
		modal.setAttribute( 'aria-hidden', 'false' );
	}
	function hideModal() {
		modal.classList.remove( 'open' );
		modal.setAttribute( 'aria-hidden', 'true' );
	}

	openBtn.addEventListener( 'click', showModal );
	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', hideModal );
	}
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && modal.classList.contains( 'open' ) ) {
			hideModal();
		}
	} );
}() );
