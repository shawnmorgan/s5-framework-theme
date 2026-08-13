/*
 * S5 Framework — left-panel Components palette (Item 4).
 *
 * Renders a thumbnail per registered component (from S5Editor.components) and
 * inserts one through the ONE save door (mode:"insert"). The SERVER expands the
 * component and assigns fresh recursive data-node ids — the client only names the
 * component and (optionally) an anchor.
 *
 *   - Click a component  -> insert after the currently-selected editable node, or
 *     append if nothing is selected.
 *   - Drag a component onto an element in the preview -> the overlay resolves the
 *     drop to the nearest data-node and reports it; we insert after that node.
 *
 * After a successful insert we reload the preview from the server (derived state),
 * so the newly-stamped, re-keyed instance appears and is immediately editable.
 */
( function () {
	'use strict';

	var shell = window.S5EditorShell;
	if ( ! shell ) {
		return;
	}
	var cfg = window.S5Editor || {};
	var root = document.querySelector( '[data-s5-editor]' );
	var palette = root ? root.querySelector( '[data-s5-palette]' ) : null;
	var hint = root ? root.querySelector( '[data-s5-palette-hint]' ) : null;
	if ( ! palette ) {
		return;
	}
	var ORIGIN = window.location.origin;

	// Track the currently-selected node id (from the overlay) as the click anchor.
	var selectedNode = '';

	function frameWindow() {
		var f = shell.getPreviewFrame();
		return f ? f.contentWindow : null;
	}

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function sendToFrame( msg ) {
		var w = frameWindow();
		if ( w ) {
			w.postMessage( msg, ORIGIN );
		}
	}

	/* --- render the palette --------------------------------------------- */

	function render() {
		var comps = cfg.components || [];
		palette.innerHTML = '';
		if ( ! comps.length ) {
			palette.appendChild( el( 'p', { class: 's5-editor__hint' }, 'No components registered.' ) );
			return;
		}
		comps.forEach( function ( c ) {
			var card = el( 'button', {
				type: 'button',
				class: 's5-editor__palette-item',
				'data-s5-component': c.name,
				draggable: 'true',
				title: c.description || c.name,
			} );

			var thumb = el( 'span', { class: 's5-editor__palette-thumb' } );
			if ( c.preview ) {
				thumb.appendChild( el( 'img', { src: c.preview, alt: '' } ) );
			} else {
				thumb.appendChild( el( 'span', { class: 's5-editor__palette-glyph' }, ( c.name || '?' ).charAt( 0 ).toUpperCase() ) );
			}
			card.appendChild( thumb );
			card.appendChild( el( 'span', { class: 's5-editor__palette-name' }, c.name ) );

			card.addEventListener( 'click', function () {
				doInsert( c.name, selectedNode );
			} );
			card.addEventListener( 'dragstart', function ( e ) {
				e.dataTransfer.setData( 'text/s5-component', c.name );
				e.dataTransfer.effectAllowed = 'copy';
				sendToFrame( { s5: 's5-drag', on: true, component: c.name } );
			} );
			card.addEventListener( 'dragend', function () {
				sendToFrame( { s5: 's5-drag', on: false } );
			} );

			palette.appendChild( card );
		} );
		if ( hint ) {
			hint.hidden = false;
		}
	}

	/* --- insert through the one door (mode:"insert") -------------------- */

	function doInsert( name, at ) {
		if ( ! cfg.saveUrl || ! name ) {
			return;
		}
		fetch( cfg.saveUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify( { mode: 'insert', component: name, at: at || '' } ),
		} )
			.then( function ( r ) {
				return r.json().then( function ( body ) {
					return { status: r.status, body: body };
				} );
			} )
			.then( function ( res ) {
				if ( res.body && res.body.ok ) {
					reloadPreview();
				} else {
					showError( res.body );
				}
			} )
			.catch( function () {
				showError( { diagnostics: [ { message: 'Network error inserting component.' } ] } );
			} );
	}

	function reloadPreview() {
		var f = shell.getPreviewFrame();
		if ( f ) {
			f.contentWindow.location.reload();
		}
	}

	function showError( body ) {
		var diags = ( body && body.diagnostics ) || [];
		var msg = diags.length ? ( diags[ 0 ].message || 'Insert rejected.' ) : ( body && body.message ) || 'Insert rejected.';
		var note = el( 'p', { class: 's5-editor__diag' }, msg );
		palette.insertBefore( note, palette.firstChild );
		setTimeout( function () {
			if ( note.parentNode ) {
				note.parentNode.removeChild( note );
			}
		}, 4000 );
	}

	/* --- messages from the overlay -------------------------------------- */

	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== ORIGIN || ! e.data || 'object' !== typeof e.data ) {
			return;
		}
		var msg = e.data;
		if ( 'select' === msg.s5 ) {
			selectedNode = msg.node || '';
		} else if ( 's5-drop' === msg.s5 && msg.node && msg.component ) {
			// The overlay resolved a drop onto an element to its nearest data-node.
			doInsert( msg.component, msg.node );
		}
	} );

	render();
}() );
