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
			// ALL head-save buttons share the one mode:"head" save (a head save
			// writes every field at once) — enableHeadSave() enables every
			// [data-s5-head-save] button, not just the first one in the DOM.
			// (Was previously a single querySelector() capture that only ever
			// re-enabled "Save meta", leaving "Save schema" permanently disabled
			// once clicked once — a real, pre-existing bug independent of the
			// error-surfacing fix below: it made the JSON-LD field un-savable
			// through normal typing, not just its failure silent.)
			enableHeadSave();
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
		box.className = 's5e-diagnostics';
		var title = document.createElement( 'div' );
		title.className = 'field-label';
		title.textContent = 'Validation errors — canonical unchanged';
		box.appendChild( title );
		diags.forEach( function ( d ) {
			var p = document.createElement( 'p' );
			p.className = 's5e-diag';
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

	/* --- head SEO segments (index / follow) ------------------------------ */

	// Seed each index/follow segment from cfg.head (default = on/true), and make it a
	// single-active toggle that marks the head dirty.
	root.querySelectorAll( '[data-s5-head-seg]' ).forEach( function ( seg ) {
		var key = seg.getAttribute( 'data-s5-head-seg' );
		var val = ( cfg.head && ( key in cfg.head ) ) ? cfg.head[ key ] : true;
		var on = ( true === val || 1 === val || '1' === val || 'true' === val );
		seg.querySelectorAll( '.seg-opt' ).forEach( function ( opt ) {
			var isOn = ( '1' === opt.getAttribute( 'data-val' ) );
			opt.classList.toggle( 'active', isOn === on );
			opt.addEventListener( 'click', function () {
				seg.querySelectorAll( '.seg-opt' ).forEach( function ( o ) {
					o.classList.remove( 'active' );
				} );
				opt.classList.add( 'active' );
				enableHeadSave();
			} );
		} );
	} );

	function segValue( key ) {
		var seg = root.querySelector( '[data-s5-head-seg="' + key + '"]' );
		if ( ! seg ) {
			return '1';
		}
		var active = seg.querySelector( '.seg-opt.active' );
		return active ? active.getAttribute( 'data-val' ) : '1';
	}

	function enableHeadSave() {
		root.querySelectorAll( '[data-s5-head-save]' ).forEach( function ( b ) {
			b.disabled = false;
		} );
	}

	/* --- inline head-field errors (Page Settings drawer, NOT the Code-lens
	 * diagnostics box — a head-save failure happens while the drawer is open,
	 * so the message must render where the user is actually looking; the
	 * previous behavior silently reused renderDiagnostics(), which writes into
	 * codeDiag inside the Code lens region — off-screen and never seen while
	 * the drawer is on top). Clears all field errors first, then places each
	 * diagnostic's message next to the field it names (falls back to the
	 * first error slot if a diagnostic doesn't name a known field). */
	function clearHeadErrors() {
		root.querySelectorAll( '[data-s5-head-error]' ).forEach( function ( el ) {
			el.hidden = true;
			el.textContent = '';
		} );
	}

	function renderHeadErrors( diags ) {
		clearHeadErrors();
		if ( ! diags || ! diags.length ) {
			// The server rejected the save but sent no diagnostics — still loud,
			// never a silent no-op. Surface a generic message on every error slot
			// so whichever field the user is looking at shows something.
			root.querySelectorAll( '[data-s5-head-error]' ).forEach( function ( el ) {
				el.textContent = 'Save failed — the server rejected this without details.';
				el.hidden = false;
			} );
			return;
		}
		var placedAny = false;
		diags.forEach( function ( d ) {
			var message = ( d.rule ? '[' + d.rule + '] ' : '' ) + ( d.message || 'Save failed.' );
			// s5.jsonld.invalid -> json_ld's own error slot; anything else that
			// doesn't name a field falls back to the first slot so it is never lost.
			var field = 's5.jsonld.invalid' === d.rule ? 'json_ld' : '';
			var slot = field ? root.querySelector( '[data-s5-head-error="' + field + '"]' ) : null;
			if ( ! slot ) {
				slot = root.querySelector( '[data-s5-head-error]' );
			}
			if ( slot ) {
				slot.textContent = ( slot.hidden ? '' : slot.textContent + ' ' ) + message;
				slot.hidden = false;
				placedAny = true;
			}
		} );
		if ( ! placedAny ) {
			renderHeadErrors( null ); // No error-slot elements exist at all; fall back to the generic path.
		}
	}

	/* --- save head (mode:head) — every head-save button saves ALL head fields */

	function saveHead( btn ) {
		var label = btn.textContent;
		var body = { mode: 'head' };
		root.querySelectorAll( '[data-s5-head]' ).forEach( function ( input ) {
			body[ input.getAttribute( 'data-s5-head' ) ] = input.value;
		} );
		body.index = segValue( 'index' );
		body.follow = segValue( 'follow' );

		clearHeadErrors();
		root.querySelectorAll( '[data-s5-head-save]' ).forEach( function ( b ) {
			b.disabled = true;
		} );
		btn.textContent = 'Saving…';
		post( body )
			.then( function ( res ) {
				btn.textContent = label;
				if ( res.data && res.data.ok ) {
					var f = shell.getPreviewFrame();
					if ( f ) {
						f.contentWindow.location.reload();
					}
				} else {
					renderHeadErrors( res.data && res.data.diagnostics );
					enableHeadSave();
				}
			} )
			.catch( function () {
				btn.textContent = label;
				renderHeadErrors( null );
				enableHeadSave();
			} );
	}

	root.querySelectorAll( '[data-s5-head-save]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			saveHead( btn );
		} );
	} );
}() );
