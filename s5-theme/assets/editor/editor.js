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
	var dirtyEl = root.querySelector( '[data-s5-dirty]' );
	var updateBtn = root.querySelector( '[data-s5-update]' );

	var state = {
		lens: 'edit',
		viewport: 'desktop',
		tab: 'content',
		dirty: false,
	};

	/* --- dirty state (helpers for later items) --------------------------- */

	function markDirty() {
		state.dirty = true;
		// The dirty-dot is the mockup's .dirty-dot (inline display toggle).
		if ( dirtyEl ) {
			dirtyEl.style.display = 'inline-block';
		}
		if ( updateBtn ) {
			updateBtn.disabled = false;
		}
	}

	function markClean() {
		state.dirty = false;
		if ( dirtyEl ) {
			dirtyEl.style.display = 'none';
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

	window.S5EditorShell = {
		state: state,
		config: cfg,
		markDirty: markDirty,
		markClean: markClean,
		showProps: showProps,
		showPalette: showPalette,
		getPreviewFrame: function () {
			return root.querySelector( '[data-s5-preview]' );
		},
	};

	// Start clean, palette visible, edit lens.
	markClean();
	showPalette();
	applyLens( state.lens );
}() );
