/*
 * S5 Framework — Components tab (code-first component editor, Item 6).
 *
 * Grid of registered components (from the scan). Click one to edit its three
 * source files — component.html / style.css / manifest.json — with a live rendered
 * preview. Save goes to the component route (POST /s5/v1/components/{name}); the
 * server writes the files and rebuilds the CSS bundle (D22). Existing inserted
 * instances are copy-on-insert and stay as they are; style edits go live via the
 * bundle.
 */
( function () {
	'use strict';

	var shell = window.S5EditorShell;
	if ( ! shell ) {
		return;
	}
	var cfg = window.S5Editor || {};
	var root = document.querySelector( '[data-s5-editor]' );
	if ( ! root ) {
		return;
	}
	var grid = root.querySelector( '[data-s5-comp-grid]' );
	var editor = root.querySelector( '[data-s5-comp-editor]' );
	var nameEl = root.querySelector( '[data-s5-comp-name]' );
	var codeEl = root.querySelector( '[data-s5-comp-code]' );
	var preview = root.querySelector( '[data-s5-comp-preview]' );
	var diag = root.querySelector( '[data-s5-comp-diagnostics]' );
	var saveBtn = root.querySelector( '[data-s5-comp-save]' );
	var backBtn = root.querySelector( '[data-s5-comp-back]' );
	if ( ! grid || ! editor || ! codeEl ) {
		return;
	}

	var current = null;                 // component name being edited
	var files = { html: '', css: '', manifest: '' };
	var activeFile = 'html';
	var cm = null;                      // CodeMirror instance, if available

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

	function restBase() {
		// saveUrl is .../pages/{id}/save — derive the namespace root from it.
		return ( cfg.saveUrl || '' ).replace( /pages\/\d+\/save.*$/, 'components/' );
	}

	/* --- grid ----------------------------------------------------------- */

	function renderGrid() {
		var comps = cfg.components || [];
		grid.innerHTML = '';
		if ( ! comps.length ) {
			grid.appendChild( el( 'p', { class: 's5-editor__hint' }, 'No components registered.' ) );
			return;
		}
		comps.forEach( function ( c ) {
			var card = el( 'button', { type: 'button', class: 's5-editor__comp-card', title: c.description || c.name } );
			var thumb = el( 'span', { class: 's5-editor__palette-thumb' } );
			if ( c.preview ) {
				thumb.appendChild( el( 'img', { src: c.preview, alt: '' } ) );
			} else {
				thumb.appendChild( el( 'span', { class: 's5-editor__palette-glyph' }, ( c.name || '?' ).charAt( 0 ).toUpperCase() ) );
			}
			card.appendChild( thumb );
			card.appendChild( el( 'span', { class: 's5-editor__palette-name' }, c.name ) );
			card.addEventListener( 'click', function () {
				open( c.name );
			} );
			grid.appendChild( card );
		} );
	}

	/* --- open + edit a component ---------------------------------------- */

	function open( name ) {
		fetch( restBase() + encodeURIComponent( name ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce || '' },
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( body ) {
				if ( ! body || ! body.ok ) {
					showDiag( body );
					return;
				}
				current = name;
				files = { html: body.html || '', css: body.css || '', manifest: body.manifest || '' };
				activeFile = 'html';
				nameEl.textContent = name;
				grid.hidden = true;
				editor.hidden = false;
				setFileButtons();
				loadIntoCode( files[ activeFile ] );
				renderPreview();
			} )
			.catch( function () {
				showDiag( { diagnostics: [ { message: 'Could not load component.' } ] } );
			} );
	}

	function setFileButtons() {
		root.querySelectorAll( '[data-s5-comp-file]' ).forEach( function ( b ) {
			var f = b.getAttribute( 'data-s5-comp-file' );
			b.classList.toggle( 'is-active', f === activeFile );
			b.onclick = function () {
				files[ activeFile ] = readCode();
				activeFile = f;
				setFileButtons();
				loadIntoCode( files[ activeFile ] );
			};
		} );
	}

	function loadIntoCode( value ) {
		if ( cm ) {
			cm.toTextArea();
			cm = null;
		}
		codeEl.value = value;
		if ( window.wp && wp.codeEditor && cfg.cmSettings ) {
			var mode = 'css' === activeFile ? 'text/css' : ( 'manifest' === activeFile ? 'application/json' : 'text/html' );
			var settings = JSON.parse( JSON.stringify( cfg.cmSettings ) );
			if ( settings.codemirror ) {
				settings.codemirror.mode = mode;
			}
			cm = wp.codeEditor.initialize( codeEl, settings );
			cm.codemirror.on( 'change', function () {
				files[ activeFile ] = cm.codemirror.getValue();
				renderPreview();
			} );
		} else {
			codeEl.oninput = function () {
				files[ activeFile ] = codeEl.value;
				renderPreview();
			};
		}
	}

	function readCode() {
		return cm ? cm.codemirror.getValue() : codeEl.value;
	}

	function renderPreview() {
		if ( ! preview ) {
			return;
		}
		files[ activeFile ] = readCode();
		var doc = '<!doctype html><html><head><meta charset="utf-8">'
			+ '<style>body{margin:12px;font-family:system-ui,sans-serif}</style>'
			+ '<style>' + ( files.css || '' ) + '</style></head><body>'
			+ ( files.html || '' ) + '</body></html>';
		preview.srcdoc = doc;
	}

	/* --- save ----------------------------------------------------------- */

	function save() {
		if ( ! current ) {
			return;
		}
		files[ activeFile ] = readCode();
		saveBtn.disabled = true;
		saveBtn.textContent = 'Saving…';
		fetch( restBase() + encodeURIComponent( current ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: JSON.stringify( { html: files.html, css: files.css, manifest: files.manifest } ),
		} )
			.then( function ( r ) { return r.json().then( function ( b ) { return { status: r.status, body: b }; } ); } )
			.then( function ( res ) {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Save component';
				if ( res.body && res.body.ok ) {
					showDiag( { ok: true } );
				} else {
					showDiag( res.body );
				}
			} )
			.catch( function () {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Save component';
				showDiag( { diagnostics: [ { message: 'Network error saving component.' } ] } );
			} );
	}

	function showDiag( body ) {
		diag.innerHTML = '';
		if ( body && body.ok ) {
			diag.appendChild( el( 'p', { class: 's5-editor__diag is-ok' }, 'Saved. Styles rebuilt; existing inserted instances are unchanged.' ) );
			return;
		}
		var diags = ( body && body.diagnostics ) || [ { message: 'Save rejected.' } ];
		diags.forEach( function ( d ) {
			diag.appendChild( el( 'p', { class: 's5-editor__diag' }, d.message || 'Error' ) );
		} );
	}

	if ( backBtn ) {
		backBtn.addEventListener( 'click', function () {
			editor.hidden = true;
			grid.hidden = false;
			diag.innerHTML = '';
		} );
	}
	if ( saveBtn ) {
		saveBtn.addEventListener( 'click', save );
	}

	renderGrid();
}() );
