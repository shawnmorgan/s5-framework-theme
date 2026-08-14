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

	/* --- render the palette (mockup .palette-group / .palette-grid / .pal-item) ---
	 * TRANSCRIBED from canvas-mockup.html (lines 838-877). Tiles carry a FontAwesome
	 * glyph (the mockup's icon system) chosen from the mockup's own set by component
	 * name; unknown names fall back to the mockup's generic cube. */

	// Map a component name to the FA glyph the mockup uses for it (mockup lines
	// 844-874). Names not drawn in the mockup use fa-cube (the neutral component
	// glyph) — a GAP handled with an existing mockup icon, never an invented one.
	var GLYPHS = {
		hero: 'fa-solid fa-star',
		cta: 'fa-solid fa-bullhorn',
		'feature grid': 'fa-solid fa-table-columns',
		features: 'fa-solid fa-table-columns',
		pricing: 'fa-solid fa-tags',
		'pricing-card': 'fa-solid fa-tags',
		'pricing card': 'fa-solid fa-tags',
		testimonial: 'fa-solid fa-quote-left',
		faq: 'fa-regular fa-circle-question',
		'faq-item': 'fa-regular fa-circle-question',
		'faq item': 'fa-regular fa-circle-question',
		card: 'fa-regular fa-square',
		'rich text': 'fa-solid fa-heading',
		heading: 'fa-solid fa-heading',
		media: 'fa-regular fa-image',
		image: 'fa-regular fa-image',
		button: 'fa-solid fa-hand-pointer',
		'query loop': 'fa-solid fa-infinity',
		'nav menu': 'fa-solid fa-bars',
		nav: 'fa-solid fa-bars',
		embed: 'fa-solid fa-code',
	};
	function glyphFor( name ) {
		return GLYPHS[ ( name || '' ).toLowerCase() ] || 'fa-solid fa-cube';
	}

	// Build one palette tile (registered component), mockup .pal-item. The Embed
	// primitive tile is added separately with isEmbed=true (dashed + raw badge).
	function tile( name, description, preview, isEmbed ) {
		var card = el( 'button', {
			type: 'button',
			class: 'pal-item' + ( isEmbed ? ' embed' : '' ),
			'data-s5-component': name,
			draggable: 'true',
			title: description || name,
		} );

		// Prefer a registered preview thumbnail; otherwise the mockup's FA glyph.
		if ( preview ) {
			var thumb = el( 'span', { class: 'media-thumb' } );
			thumb.appendChild( el( 'img', { src: preview, alt: '' } ) );
			card.appendChild( thumb );
		} else {
			card.appendChild( el( 'i', { class: glyphFor( name ) } ) );
		}
		card.appendChild( el( 'span', {}, name ) );
		if ( isEmbed ) {
			card.appendChild( el( 'span', { class: 'prim-badge' }, 'raw' ) );
		}

		card.addEventListener( 'click', function () {
			doInsert( name, selectedNode );
		} );
		card.addEventListener( 'dragstart', function ( e ) {
			e.dataTransfer.setData( 'text/s5-component', name );
			e.dataTransfer.effectAllowed = 'copy';
			sendToFrame( { s5: 's5-drag', on: true, component: name } );
		} );
		card.addEventListener( 'dragend', function () {
			sendToFrame( { s5: 's5-drag', on: false } );
		} );
		return card;
	}

	// A labelled group holding a grid of tiles (mockup .palette-group).
	function group( label ) {
		var g = el( 'div', { class: 'palette-group' } );
		g.appendChild( el( 'div', { class: 'palette-label' }, label ) );
		var grid = el( 'div', { class: 'palette-grid' } );
		g.appendChild( grid );
		g._grid = grid;
		return g;
	}

	function render() {
		var comps = cfg.components || [];
		palette.innerHTML = '';

		// Intro: only registered components + Embed are insertable (HANDOFF §0.2/§2.2).
		palette.appendChild( el( 'p', { class: 'palette-intro' },
			'These are the only elements you can add — every one is a registered, contract-valid component. Need something new? Ask us to register it.' ) );

		// Registered components. The registry is flat, so one honest "Components"
		// group (not fabricated Sections/Content/Dynamic buckets the data can't back).
		// Any registry entry named "embed" is the Embed primitive — pull it out so it
		// appears once, in the Primitive group (never duplicated across both).
		var embedComp = null;
		var regular = comps.filter( function ( c ) {
			if ( 'embed' === ( c.name || '' ).toLowerCase() ) {
				embedComp = c;
				return false;
			}
			return true;
		} );

		if ( regular.length ) {
			var cg = group( 'Components' );
			regular.forEach( function ( c ) {
				cg._grid.appendChild( tile( c.name, c.description, c.preview, false ) );
			} );
			palette.appendChild( cg );
		} else if ( ! embedComp ) {
			palette.appendChild( el( 'p', { class: 's5e-hint' }, 'No components registered.' ) );
		}

		// The single raw primitive — Embed (HANDOFF §0.2). Inserts third-party
		// HTML/script; still recorded in the tree and disable-able. Uses the registry's
		// embed entry (name/description/preview) when present, else a hardcoded tile.
		var pg = group( 'Primitive' );
		pg._grid.appendChild( tile(
			embedComp ? embedComp.name : 'embed',
			( embedComp && embedComp.description ) || 'Embed — the only raw primitive, for third-party HTML/scripts.',
			embedComp ? embedComp.preview : '',
			true
		) );
		palette.appendChild( pg );
		// Mockup .palette-note carries a leading info icon.
		var note = el( 'p', { class: 'palette-note' } );
		note.appendChild( el( 'i', { class: 'fa-solid fa-circle-info' } ) );
		note.appendChild( document.createTextNode(
			' Embed is the only raw primitive — for third-party HTML/scripts we can’t wrap in a component.' ) );
		palette.appendChild( note );

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
		var note = el( 'p', { class: 's5e-diag' }, msg );
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
