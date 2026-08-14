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
		if ( dirtyEl ) {
			dirtyEl.hidden = false;
		}
		if ( updateBtn ) {
			updateBtn.disabled = false;
		}
	}

	function markClean() {
		state.dirty = false;
		if ( dirtyEl ) {
			dirtyEl.hidden = true;
		}
		if ( updateBtn ) {
			updateBtn.disabled = true;
		}
	}

	/* --- viewport toggle ------------------------------------------------- */

	root.querySelectorAll( '.s5-editor__vp' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var vp = btn.getAttribute( 'data-s5-viewport' );
			state.viewport = vp;
			if ( stage ) {
				stage.setAttribute( 'data-s5-viewport', vp );
			}
			root.querySelectorAll( '.s5-editor__vp' ).forEach( function ( b ) {
				var on = b === btn;
				b.classList.toggle( 'is-active', on );
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
		root.querySelectorAll( '.s5-editor__lens-btn' ).forEach( function ( b ) {
			var on = b.getAttribute( 'data-s5-lens' ) === lens;
			b.classList.toggle( 'is-active', on );
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
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			root.querySelectorAll( '[data-s5-ppanel]' ).forEach( function ( p ) {
				p.hidden = ( p.getAttribute( 'data-s5-ppanel' ) !== name );
			} );
		} );
	} );

	/* --- Tools dropdown -------------------------------------------------- */

	var toolsBtn = root.querySelector( '[data-s5-tools-btn]' );
	var toolsMenu = root.querySelector( '[data-s5-tools-menu]' );
	if ( toolsBtn && toolsMenu ) {
		toolsBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			var open = toolsMenu.hidden;
			toolsMenu.hidden = ! open;
			toolsBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
		document.addEventListener( 'click', function () {
			toolsMenu.hidden = true;
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

	function openDrawer() {
		if ( ! activeDrawer ) {
			return;
		}
		activeDrawer.classList.add( 'is-open' );
		if ( scrim ) {
			scrim.hidden = false;
		}
	}
	function closeDrawer() {
		root.querySelectorAll( '[data-s5-drawer]' ).forEach( function ( d ) {
			d.classList.remove( 'is-open' );
		} );
		if ( scrim ) {
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

	/* --- Update button (save pipeline wires in B2) ----------------------- */

	if ( updateBtn ) {
		updateBtn.addEventListener( 'click', function () {
			// Phase B2 attaches the REST save here. In the shell, Update is inert
			// and stays disabled until a real edit marks the state dirty.
		} );
	}

	/* --- expose a tiny controller for later items ------------------------ */

	// Palette ⇄ props swap. showProps()/showPalette() are the single seam other
	// scripts (panel.js) call when the selection changes.
	var palette = root.querySelector( '[data-s5-palette]' );
	var props = root.querySelector( '[data-s5-props]' );
	var leftTitle = root.querySelector( '[data-s5-left-title]' );
	var leftSub = root.querySelector( '[data-s5-left-sub]' );

	function showProps( name, tag ) {
		if ( palette ) {
			palette.hidden = true;
		}
		if ( props ) {
			props.hidden = false;
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
		}
		if ( palette ) {
			palette.hidden = false;
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
