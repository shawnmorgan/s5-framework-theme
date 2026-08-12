/*
 * S5 Framework — Code lens + head sidebar (Editor MVP, Phase B3).
 *
 * Code lens: CodeMirror (WP core wp-codemirror, no build step) over the FULL
 * canonical fragment. Save -> mode:"canonical" through the one door; a validation
 * ERROR renders in the editor as an agent-grade diagnostic list (loud, never
 * silent) and canonical is left unchanged.
 *
 * Head sidebar: title/description/canonical -> mode:"head" through the same door
 * (_s5_head is separate from the body fragment; canonical untouched).
 *
 * Lens switch: Edit shows the declaration panel; Code shows this region.
 */
( function () {
	'use strict';

	var shell = window.S5EditorShell;
	if ( ! shell ) {
		return;
	}
	var cfg = window.S5Editor || {};
	var root = document.querySelector( '[data-s5-editor]' );

	var editRegion = root.querySelector( '[data-s5-lens-edit]' );
	var codeRegion = root.querySelector( '[data-s5-lens-code]' );
	var textarea = root.querySelector( '[data-s5-code]' );
	var codeSaveBtn = root.querySelector( '[data-s5-code-save]' );
	var codeDiag = root.querySelector( '[data-s5-code-diagnostics]' );
	var headSaveBtn = root.querySelector( '[data-s5-head-save]' );

	var cm = null;

	/* --- seed values ----------------------------------------------------- */

	if ( textarea ) {
		textarea.value = cfg.canonical || '';
	}
	root.querySelectorAll( '[data-s5-head]' ).forEach( function ( input ) {
		var key = input.getAttribute( 'data-s5-head' );
		if ( cfg.head && cfg.head[ key ] ) {
			input.value = cfg.head[ key ];
		}
		input.addEventListener( 'input', function () {
			if ( headSaveBtn ) {
				headSaveBtn.disabled = false;
			}
		} );
	} );

	/* --- lens switch ----------------------------------------------------- */

	function showLens( lens ) {
		var code = 'code' === lens;
		if ( editRegion ) {
			editRegion.hidden = code;
		}
		if ( codeRegion ) {
			codeRegion.hidden = ! code;
		}
		if ( code && ! cm ) {
			mountCodeMirror();
		}
		if ( code && cm ) {
			// CodeMirror needs a refresh when its container becomes visible.
			setTimeout( function () {
				cm.codemirror.refresh();
			}, 0 );
		}
	}

	function mountCodeMirror() {
		if ( ! textarea ) {
			return;
		}
		if ( window.wp && wp.codeEditor && cfg.cmSettings ) {
			cm = wp.codeEditor.initialize( textarea, cfg.cmSettings );
			cm.codemirror.on( 'change', function () {
				if ( codeSaveBtn ) {
					codeSaveBtn.disabled = false;
				}
			} );
		} else {
			// Fallback: plain textarea still edits + saves canonical.
			textarea.addEventListener( 'input', function () {
				if ( codeSaveBtn ) {
					codeSaveBtn.disabled = false;
				}
			} );
		}
	}

	function currentCanonical() {
		if ( cm && cm.codemirror ) {
			return cm.codemirror.getValue();
		}
		return textarea ? textarea.value : '';
	}

	root.querySelectorAll( '[data-s5-lens]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			showLens( btn.getAttribute( 'data-s5-lens' ) );
		} );
	} );

	/* --- save canonical (mode:canonical) --------------------------------- */

	function post( body ) {
		return fetch( cfg.saveUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify( body ),
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { status: r.status, data: data };
			} );
		} );
	}

	function renderDiagnostics( diags ) {
		codeDiag.innerHTML = '';
		if ( ! diags || ! diags.length ) {
			return;
		}
		var box = document.createElement( 'div' );
		box.className = 's5-editor__diagnostics';
		var title = document.createElement( 'div' );
		title.className = 's5-editor__field-label';
		title.textContent = 'Validation errors — canonical unchanged';
		box.appendChild( title );
		diags.forEach( function ( d ) {
			var p = document.createElement( 'p' );
			p.className = 's5-editor__diag';
			var permitted = d.permitted ? ' — permitted: ' + ( Array.isArray( d.permitted ) ? d.permitted.join( ', ' ) : d.permitted ) : '';
			p.textContent = ( d.rule ? '[' + d.rule + '] ' : '' ) +
				( d.line ? 'line ' + d.line + ': ' : '' ) +
				( d.message || '' ) +
				( d.token ? ' — token: ' + d.token : '' ) +
				permitted;
			box.appendChild( p );
		} );
		codeDiag.appendChild( box );
	}

	if ( codeSaveBtn ) {
		codeSaveBtn.addEventListener( 'click', function () {
			codeDiag.innerHTML = '';
			codeSaveBtn.disabled = true;
			codeSaveBtn.textContent = 'Saving…';
			post( { mode: 'canonical', canonical: currentCanonical() } )
				.then( function ( res ) {
					codeSaveBtn.textContent = 'Save canonical';
					if ( res.data && res.data.ok ) {
						shell.markClean();
						var f = shell.getPreviewFrame();
						if ( f ) {
							f.contentWindow.location.reload();
						}
					} else {
						renderDiagnostics( res.data && res.data.diagnostics );
						codeSaveBtn.disabled = false;
					}
				} )
				.catch( function () {
					codeSaveBtn.textContent = 'Save canonical';
					codeSaveBtn.disabled = false;
					renderDiagnostics( [ { message: 'Network error saving canonical.' } ] );
				} );
		} );
	}

	/* --- save head (mode:head) ------------------------------------------- */

	if ( headSaveBtn ) {
		headSaveBtn.addEventListener( 'click', function () {
			var body = { mode: 'head' };
			root.querySelectorAll( '[data-s5-head]' ).forEach( function ( input ) {
				body[ input.getAttribute( 'data-s5-head' ) ] = input.value;
			} );
			headSaveBtn.disabled = true;
			headSaveBtn.textContent = 'Saving…';
			post( body )
				.then( function ( res ) {
					headSaveBtn.textContent = 'Save head';
					if ( res.data && res.data.ok ) {
						var f = shell.getPreviewFrame();
						if ( f ) {
							f.contentWindow.location.reload();
						}
					} else {
						headSaveBtn.disabled = false;
					}
				} )
				.catch( function () {
					headSaveBtn.textContent = 'Save head';
					headSaveBtn.disabled = false;
				} );
		} );
	}
}() );
