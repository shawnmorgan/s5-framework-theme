/*
 * S5 Framework — Components screen + Component Builder (settings §3.5).
 *
 * Opens a registered component in the builder: GET its files from the /components REST
 * route, fill Props/HTML/Logic (+ read-only CSS), and POST html+manifest back through
 * the SAME door. The server enforces the contract — HTML is vocabulary-validated and CSS
 * is read-only (any css payload is ignored) — so this UI simply presents it: the CSS box
 * is `readonly`, and a rejected HTML save surfaces the server's diagnostics.
 */
( function () {
	'use strict';

	var cfg = window.S5Settings || {};
	var root = document.querySelector( '[data-s5-settings]' );
	if ( ! root ) {
		return;
	}
	var grid = root.querySelector( '[data-s5-comp-grid]' );
	var modal = root.querySelector( '[data-s5-cb]' );
	if ( ! grid || ! modal ) {
		return;
	}

	var nameEl = modal.querySelector( '[data-s5-cb-name]' );
	var htmlEl = modal.querySelector( '[data-s5-cb-html]' );
	var cssEl = modal.querySelector( '[data-s5-cb-css]' );
	var manEl = modal.querySelector( '[data-s5-cb-manifest]' );
	var propsEl = modal.querySelector( '[data-s5-cb-props]' );
	var diagEl = modal.querySelector( '[data-s5-cb-diagnostics]' );
	var saveBtn = modal.querySelector( '[data-s5-cb-save]' );

	var current = '';

	function restBase() {
		// e.g. http://site/wp-json/s5/v1/components/
		return ( cfg.restBase || '' );
	}

	function openBuilder( name ) {
		current = name;
		diagEl.textContent = '';
		nameEl.textContent = name;
		htmlEl.value = 'Loading…';
		cssEl.value = '';
		manEl.value = '';
		propsEl.innerHTML = '';

		fetch( restBase() + encodeURIComponent( name ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce || '' },
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				htmlEl.value = data.html || '';
				cssEl.value = data.css || '/* This component ships no CSS. */';
				manEl.value = data.manifest || '';
				renderProps( data.manifest );
				modal.hidden = false;
			} )
			.catch( function () {
				htmlEl.value = '';
				diag( [ { message: 'Could not load the component.' } ] );
				modal.hidden = false;
			} );
	}

	// Props are read from the manifest's `props`/`fields` (display only — they are the
	// same schema the canvas Content fields use; editing them is the manifest editor).
	function renderProps( manifestRaw ) {
		propsEl.innerHTML = '';
		var man = {};
		try {
			man = JSON.parse( manifestRaw || '{}' );
		} catch ( e ) {
			man = {};
		}
		var props = man.props || man.fields || {};
		var keys = Object.keys( props );
		if ( ! keys.length ) {
			var none = document.createElement( 'p' );
			none.className = 'description';
			none.textContent = 'No props declared in the manifest.';
			propsEl.appendChild( none );
			return;
		}
		keys.forEach( function ( k ) {
			var row = document.createElement( 'div' );
			row.className = 's5-cb__prop';
			var def = props[ k ];
			row.textContent = k + ( def && def.type ? ' · ' + def.type : '' );
			propsEl.appendChild( row );
		} );
	}

	function diag( list ) {
		diagEl.innerHTML = '';
		( list || [] ).forEach( function ( d ) {
			var p = document.createElement( 'p' );
			p.className = 's5-cb__diag';
			p.textContent = ( d.rule ? '[' + d.rule + '] ' : '' ) + ( d.message || '' ) + ( d.token ? ' — token: ' + d.token : '' );
			diagEl.appendChild( p );
		} );
	}

	function save() {
		if ( ! current ) {
			return;
		}
		saveBtn.disabled = true;
		saveBtn.textContent = 'Saving…';
		diag( [] );
		fetch( restBase() + encodeURIComponent( current ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			// NOTE: no `css` — CSS is read-only; the server ignores it regardless.
			body: JSON.stringify( { html: htmlEl.value, manifest: manEl.value } ),
		} )
			.then( function ( r ) {
				return r.json().then( function ( b ) {
					return { status: r.status, body: b };
				} );
			} )
			.then( function ( res ) {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Save component';
				if ( res.body && res.body.ok ) {
					diagEl.innerHTML = '<p class="s5-cb__ok">Saved. The design bundle was recompiled.</p>';
				} else {
					diag( ( res.body && res.body.diagnostics ) || [ { message: 'Save rejected.' } ] );
				}
			} )
			.catch( function () {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Save component';
				diag( [ { message: 'Network error saving the component.' } ] );
			} );
	}

	grid.querySelectorAll( '[data-s5-comp-open]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			openBuilder( btn.getAttribute( 'data-s5-comp-open' ) );
		} );
	} );
	modal.querySelectorAll( '[data-s5-cb-close]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			modal.hidden = true;
		} );
	} );
	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', save );
	}
}() );
