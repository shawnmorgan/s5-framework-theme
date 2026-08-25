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
	var slugEl = modal.querySelector( '[data-s5-cb-slug]' );
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

	// Mockup modal: show via the `.open` class (.cb-modal.open{display:flex}).
	function showModal() {
		modal.classList.add( 'open' );
		modal.setAttribute( 'aria-hidden', 'false' );
	}
	function hideModal() {
		modal.classList.remove( 'open' );
		modal.setAttribute( 'aria-hidden', 'true' );
	}

	function openBuilder( name ) {
		current = name;
		if ( diagEl ) {
			diagEl.textContent = '';
		}
		// The mockup's cb-name is an <input> (editable); fill its value, and the slug.
		if ( nameEl ) {
			nameEl.value = name;
		}
		if ( slugEl ) {
			slugEl.textContent = '<s5-' + name + '>';
		}
		htmlEl.value = 'Loading…';
		cssEl.value = '';
		manEl.value = '';
		propsEl.innerHTML = '';
		showModal();

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
			} )
			.catch( function () {
				htmlEl.value = '';
				diag( [ { message: 'Could not load the component.' } ] );
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
			none.className = 'cb-f-hint';
			none.style.padding = '0 16px';
			none.textContent = 'No props declared in the manifest.';
			propsEl.appendChild( none );
			return;
		}
		// Render each prop as the mockup's .cb-field card (read view of the schema).
		keys.forEach( function ( k ) {
			var def = props[ k ] || {};
			var card = document.createElement( 'div' );
			card.className = 'cb-field';
			card.innerHTML =
				'<div class="cb-field-top"><span class="lbl">' + esc( def.label || k ) + '</span></div>' +
				'<div class="cb-f"><label>Token</label>' +
				'<div class="cb-token"><span class="braces">{{</span><input value="' + esc( k ) + '" readonly><span class="braces">}}</span></div></div>' +
				'<div class="cb-f"><label>Type</label><input value="' + esc( def.type || 'Text' ) + '" readonly></div>' +
				( def.description ? '<div class="cb-f"><label>Description</label><textarea readonly>' + esc( def.description ) + '</textarea></div>' : '' );
			propsEl.appendChild( card );
		} );
	}

	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function diag( list ) {
		diagEl.innerHTML = '';
		( list || [] ).forEach( function ( d ) {
			var p = document.createElement( 'span' );
			p.style.color = 'var(--s5e-danger)';
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
					diagEl.innerHTML = '<span style="color:var(--s5e-brand)">Saved. The design bundle was recompiled.</span>';
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

	// "New Component": ask for a name, create it server-side (mints a valid starter),
	// then open the builder on it so the author edits real HTML immediately. The create
	// route is collection-level (POST /components); the per-name route only edits.
	function createComponent() {
		// eslint-disable-next-line no-alert
		var name = window.prompt(
			'New component name (letters, numbers, - or _):\ne.g. pricing-card'
		);
		if ( name === null ) {
			return; // cancelled.
		}
		name = String( name ).trim();
		if ( ! name ) {
			return;
		}
		fetch( restBase().replace( /\/$/, '' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: JSON.stringify( { name: name } ),
		} )
			.then( function ( r ) {
				return r.json().then( function ( b ) {
					return { status: r.status, body: b };
				} );
			} )
			.then( function ( res ) {
				if ( res.body && res.body.ok && res.body.name ) {
					// Open the builder on the new component. A reload picks the new card up
					// in the grid on next visit; opening the builder lets the author start now.
					openBuilder( res.body.name );
				} else {
					var msg =
						( res.body && res.body.diagnostics && res.body.diagnostics[ 0 ] && res.body.diagnostics[ 0 ].message ) ||
						'Could not create the component.';
					// eslint-disable-next-line no-alert
					window.alert( msg );
				}
			} )
			.catch( function () {
				// eslint-disable-next-line no-alert
				window.alert( 'Network error creating the component.' );
			} );
	}

	var newBtn = root.querySelector( '[data-s5-comp-new]' );
	if ( newBtn ) {
		newBtn.addEventListener( 'click', createComponent );
	}

	// The card AND its pencil both carry data-s5-comp-open. Bind once per element but,
	// on the card, ignore clicks that started on an inner control (pencil / switch) so
	// the builder opens once — the pencil's own handler already opened it.
	grid.querySelectorAll( '[data-s5-comp-open]' ).forEach( function ( btn ) {
		var isCard = btn.classList.contains( 'comp-card' );
		btn.addEventListener( 'click', function ( e ) {
			if ( isCard && e.target.closest( '.comp-open, .switch' ) ) {
				return;
			}
			openBuilder( btn.getAttribute( 'data-s5-comp-open' ) );
		} );
	} );
	modal.querySelectorAll( '[data-s5-cb-close]' ).forEach( function ( b ) {
		b.addEventListener( 'click', hideModal );
	} );
	// Dev/Preview lens toggle (presentational — the mockup ships it this way).
	modal.querySelectorAll( '.cb-lens-btn' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			modal.querySelectorAll( '.cb-lens-btn' ).forEach( function ( x ) {
				x.classList.toggle( 'active', x === b );
			} );
		} );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && modal.classList.contains( 'open' ) ) {
			hideModal();
		}
	} );
	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', save );
	}
}() );
