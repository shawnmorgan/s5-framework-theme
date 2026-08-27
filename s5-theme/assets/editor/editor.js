/*
 * S5 Framework — Editor MVP shell behavior (Phase B1).
 *
 * Vanilla ES module-free script (enqueued classic, in the footer). No build step,
 * no dependencies. This item wires the SHELL interactions only:
 *   - viewport toggle (resizes the preview stage via a data attribute)
 *   - Edit/Code lens toggle (visual state; lens bodies land in B2/B3)
 *   - Content/Style tab toggle (disabled tabs are inert)
 *   - dirty-state helpers (markDirty/markClean) exposed for later items
 *
 * The preview iframe stays a pure projection (EDITOR-DESIGN.md §1): this script
 * never treats iframe DOM as state. Selection/overlay + REST save arrive next.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-s5-editor]' );
	if ( ! root ) {
		return;
	}

	var cfg = window.S5Editor || {};

	var stage = root.querySelector( '[data-s5-stage]' );
	// NB: no cached [data-s5-dirty] reference here — it lives inside the Save
	// button, whose innerHTML panel.js rewrites, which detaches any held node.
	// Use dirtyDot() below, which re-queries. (audit H2)
	var updateBtn = root.querySelector( '[data-s5-update]' );

	var state = {
		lens: 'edit',
		viewport: 'desktop',
		tab: 'content',
		dirty: false,
	};

	/* --- dirty state (helpers for later items) --------------------------- */

	// The dirty dot lives INSIDE the Save button, and panel.js rewrites that
	// button's innerHTML/textContent around a save ("Saving…", then restore). A
	// reference captured once at init is therefore detached the first time that
	// happens, and every later markDirty() would style an orphaned node while the
	// real dot stayed hidden for the rest of the session (audit H2). Re-query on
	// every use so we always address the node currently in the DOM.
	function dirtyDot() {
		return root.querySelector( '[data-s5-dirty]' );
	}

	// Probe registered by panel.js (registerPendingEdits): true while slot edits are
	// staged that ONLY the Save button can flush. See markClean().
	var pendingEditsProbe = null;

	function hasPendingEdits() {
		try {
			return !! ( pendingEditsProbe && pendingEditsProbe() );
		} catch ( e ) {
			return false;
		}
	}

	function markDirty() {
		state.dirty = true;
		// The dirty-dot is the mockup's .dirty-dot (inline display toggle).
		var dot = dirtyDot();
		if ( dot ) {
			dot.style.display = 'inline-block';
		}
		if ( updateBtn ) {
			updateBtn.disabled = false;
		}
	}

	// Clear the dirty state. AUDIT H1: this is called by the auto-saving structure
	// ops (structure.js delete/duplicate, styleaxes.js class swap) once THEIR save
	// succeeds — but it is a GLOBAL flag, and panel.js's staged slot edits live in a
	// separate buffer it does not touch. Clearing unconditionally therefore disabled
	// Save while the user's typing was still buffered, and left state.dirty false so
	// beforeunload stayed silent: the edit was lost with no warning at all. So: if
	// slot edits are still staged, the editor genuinely IS still dirty. Say so.
	function markClean() {
		if ( hasPendingEdits() ) {
			markDirty();
			return;
		}
		state.dirty = false;
		var dot = dirtyDot();
		if ( dot ) {
			dot.style.display = 'none';
		}
		if ( updateBtn ) {
			updateBtn.disabled = true;
		}
	}

	/* --- viewport toggle ------------------------------------------------- */

	root.querySelectorAll( '[data-s5-viewport]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var vp = btn.getAttribute( 'data-s5-viewport' );
			state.viewport = vp;
			if ( stage ) {
				stage.setAttribute( 'data-s5-viewport', vp );
			}
			root.querySelectorAll( '[data-s5-viewport]' ).forEach( function ( b ) {
				var on = b === btn;
				// Mockup segmented-control active class is `active` (.vp-btn.active).
				b.classList.toggle( 'active', on );
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
		} );
	} );

	/* --- lens toggle (Edit / Code) --------------------------------------- */
	// Swaps the CENTER panel: [data-s5-lens-edit] (stage) ⇄ [data-s5-lens-code]
	// (canonical source). Also notifies the preview overlay so it stops selecting
	// while in Code lens (postMessage bridge preserved — R2 architecture untouched).

	function applyLens( lens ) {
		state.lens = lens;
		root.querySelectorAll( '[data-s5-lens]' ).forEach( function ( b ) {
			var on = b.getAttribute( 'data-s5-lens' ) === lens;
			// Mockup lens toggle active class is `active` (.lens-btn.active).
			b.classList.toggle( 'active', on );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		root.querySelectorAll( '[data-s5-lens-edit]' ).forEach( function ( el ) {
			el.hidden = ( 'edit' !== lens );
		} );
		root.querySelectorAll( '[data-s5-lens-code]' ).forEach( function ( el ) {
			el.hidden = ( 'code' !== lens );
		} );
		root.setAttribute( 'data-s5-active-lens', lens );
		// Tell the overlay to enable/disable selection in the iframe.
		var frame = root.querySelector( '[data-s5-preview]' );
		if ( frame && frame.contentWindow ) {
			frame.contentWindow.postMessage( { s5: 's5-lens', lens: lens }, window.location.origin );
		}
	}

	root.querySelectorAll( '[data-s5-lens]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			applyLens( btn.getAttribute( 'data-s5-lens' ) );
		} );
	} );

	/* --- prop tabs (Content / Style / Advanced) -------------------------- */

	root.querySelectorAll( '[data-s5-ptab]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var name = btn.getAttribute( 'data-s5-ptab' );
			root.querySelectorAll( '[data-s5-ptab]' ).forEach( function ( b ) {
				var on = b === btn;
				// Mockup prop-tab active class is `active` (.prop-tab.active).
				b.classList.toggle( 'active', on );
				b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			root.querySelectorAll( '[data-s5-ppanel]' ).forEach( function ( p ) {
				var on = ( p.getAttribute( 'data-s5-ppanel' ) === name );
				// Mockup panel visibility is `.ptab-panel.active` — keep the `hidden`
				// attribute in sync too so nothing is announced when off-screen.
				p.classList.toggle( 'active', on );
				p.hidden = ! on;
			} );
		} );
	} );

	/* --- Tools dropdown -------------------------------------------------- */

	var toolsBtn = root.querySelector( '[data-s5-tools-btn]' );
	var toolsMenu = root.querySelector( '[data-s5-tools-menu]' );
	if ( toolsBtn && toolsMenu ) {
		toolsBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			// Mockup dropdown opens via the `.open` class (.tools-menu.open{display:block}).
			var willOpen = ! toolsMenu.classList.contains( 'open' );
			toolsMenu.classList.toggle( 'open', willOpen );
			toolsBtn.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
		} );
		document.addEventListener( 'click', function () {
			toolsMenu.classList.remove( 'open' );
			toolsBtn.setAttribute( 'aria-expanded', 'false' );
		} );
		toolsMenu.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
		} );
	}

	/* --- Gear drawers (page settings / display & conditions) ------------- */

	var scrim = root.querySelector( '[data-s5-drawer-scrim]' );
	var isTemplate = root.hasAttribute( 'data-s5-template-mode' );
	var activeDrawer = root.querySelector(
		isTemplate ? '[data-s5-drawer="template"]' : '[data-s5-drawer="page"]'
	);

	// Mockup drawer opens via `.open` (.drawer.open{transform:translateX(0)}) and the
	// scrim via `.scrim.open` — matches canvas-mockup.html exactly.
	function openDrawer() {
		if ( ! activeDrawer ) {
			return;
		}
		activeDrawer.classList.add( 'open' );
		if ( scrim ) {
			scrim.hidden = false;
			scrim.classList.add( 'open' );
		}
	}
	function closeDrawer() {
		root.querySelectorAll( '[data-s5-drawer]' ).forEach( function ( d ) {
			d.classList.remove( 'open' );
		} );
		if ( scrim ) {
			scrim.classList.remove( 'open' );
			scrim.hidden = true;
		}
	}

	var gearBtn = root.querySelector( '[data-s5-page-settings]' );
	if ( gearBtn ) {
		gearBtn.addEventListener( 'click', openDrawer );
	}
	root.querySelectorAll( '[data-s5-drawer-close]' ).forEach( function ( b ) {
		b.addEventListener( 'click', closeDrawer );
	} );
	if ( scrim ) {
		scrim.addEventListener( 'click', closeDrawer );
	}

	/* --- Update/Save button ---------------------------------------------
	 * The click handler that flushes staged slot edits through the ONE save
	 * door lives in panel.js (it owns the slotEdits buffer). editor.js only
	 * owns the button's dirty-state enable/disable via markDirty/markClean.
	 * Do NOT bind a click handler here — a second listener on the same button
	 * is exactly the "looks wired but does nothing" trap. panel.js is the
	 * sole owner of the save click. */

	/* --- expose a tiny controller for later items ------------------------ */

	// Palette ⇄ props swap. showProps()/showPalette() are the single seam other
	// scripts (panel.js) call when the selection changes.
	var palette = root.querySelector( '[data-s5-palette]' );
	var props = root.querySelector( '[data-s5-props]' );
	var leftTitle = root.querySelector( '[data-s5-left-title]' );
	var leftSub = root.querySelector( '[data-s5-left-sub]' );

	// Mockup left-panel swap: palette hides via `.hidden`, props shows via `.active`
	// (.props{display:none} / .props.active{display:flex}). Match it exactly so the
	// transcribed CSS resolves without the `hidden` attribute fighting the classes.
	function showProps( name, tag ) {
		if ( palette ) {
			palette.hidden = true;
			palette.classList.add( 'hidden' );
		}
		if ( props ) {
			props.hidden = false;
			props.classList.add( 'active' );
		}
		if ( leftTitle ) {
			leftTitle.textContent = cfg.i18nProperties || 'Properties';
		}
		if ( leftSub ) {
			leftSub.textContent = cfg.i18nEditing || 'Editing selection';
		}
		var nameEl = root.querySelector( '[data-s5-props-name]' );
		var tagEl = root.querySelector( '[data-s5-props-tag]' );
		if ( nameEl ) {
			nameEl.textContent = name || 'Element';
		}
		if ( tagEl ) {
			tagEl.textContent = tag || '';
		}
	}
	function showPalette() {
		if ( props ) {
			props.hidden = true;
			props.classList.remove( 'active' );
		}
		if ( palette ) {
			palette.hidden = false;
			palette.classList.remove( 'hidden' );
		}
		if ( leftTitle ) {
			leftTitle.textContent = cfg.i18nAdd || 'Add';
		}
		if ( leftSub ) {
			leftSub.textContent = cfg.i18nAddSub || 'Registered components · drag to place';
		}
	}

	var propsBack = root.querySelector( '[data-s5-props-back]' );
	if ( propsBack ) {
		propsBack.addEventListener( 'click', showPalette );
	}

	/* --- dirty-state guards: warn or lose-intentionally, never silent loss --- */

	// Real browser navigation (URL bar, tab close, reload, external link): the
	// native confirm dialog. Every browser suppresses a custom message here, but
	// the dialog itself is the loud signal — a silent close/reload is what this
	// closes (D-fix, post-sweep v2 item 2a).
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! state.dirty ) {
			return;
		}
		e.preventDefault();
		e.returnValue = '';
	} );

	// In-app navigation via the editor's own controls (the "Back to WordPress"
	// logo link is a plain <a>, not a route change JS can intercept after the
	// fact) — beforeunload alone does not fire reliably for same-tab anchor
	// navigation in every browser, so this is a belt-and-braces confirm() at the
	// one in-app link that leaves the editor.
	var backLink = root.querySelector( '.tb-logo[href]' );
	if ( backLink ) {
		backLink.addEventListener( 'click', function ( e ) {
			if ( ! state.dirty ) {
				return;
			}
			var proceed = window.confirm( // eslint-disable-line no-alert -- intentional, the explicit warn-or-lose-intentionally gate.
				cfg.i18nUnsavedConfirm || 'You have unsaved changes. Leave without saving?'
			);
			if ( ! proceed ) {
				e.preventDefault();
			}
		} );
	}

	// A tiny transient toast for confirmations ("Updated", etc.). Appears bottom-right,
	// auto-dismisses. The editor had no success feedback at all, so a save looked like
	// it did nothing even when it worked.
	// Toasts share ONE fixed-position stack. Appending each toast at the same
	// right/bottom anchor made two within the dismiss window render exactly
	// superimposed — an unreadable smear (audit M7). A flex column lets them
	// stack instead, and keeps the fixed positioning in one place.
	var toastStack = null;

	function toast( msg, kind ) {
		if ( ! toastStack || ! toastStack.parentNode ) {
			toastStack = document.createElement( 'div' );
			toastStack.className = 's5e-toast-stack';
			root.appendChild( toastStack );
		}
		var t = document.createElement( 'div' );
		t.className = 's5e-toast' + ( 'error' === kind ? ' s5e-toast--error' : '' );
		t.setAttribute( 'role', 'status' );
		t.setAttribute( 'aria-live', 'polite' );
		t.textContent = msg;
		toastStack.appendChild( t );
		// Force a reflow so the enter transition runs, then show.
		void t.offsetWidth;
		t.classList.add( 'is-in' );
		setTimeout( function () {
			t.classList.remove( 'is-in' );
			setTimeout( function () {
				if ( t.parentNode ) {
					t.parentNode.removeChild( t );
				}
			}, 220 );
		}, 2000 );
	}

	window.S5EditorShell = {
		state: state,
		config: cfg,
		markDirty: markDirty,
		markClean: markClean,
		showProps: showProps,
		showPalette: showPalette,
		toast: toast,
		// panel.js owns the staged-slot-edit buffer; it registers a probe here so the
		// SHARED markClean() (called by the auto-saving structure ops) can refuse to
		// disable Save while those edits are still unflushed. See markClean().
		registerPendingEdits: function ( fn ) {
			pendingEditsProbe = ( 'function' === typeof fn ) ? fn : null;
		},
		hasPendingEdits: hasPendingEdits,
		getPreviewFrame: function () {
			return root.querySelector( '[data-s5-preview]' );
		},
	};

	// Start clean, palette visible, edit lens.
	markClean();
	showPalette();
	applyLens( state.lens );
}() );
