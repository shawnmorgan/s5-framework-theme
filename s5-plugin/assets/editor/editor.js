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

	root.querySelectorAll( '[data-s5-lens]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var lens = btn.getAttribute( 'data-s5-lens' );
			state.lens = lens;
			root.querySelectorAll( '.s5-editor__lens-btn' ).forEach( function ( b ) {
				var on = b === btn;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			root.setAttribute( 'data-s5-active-lens', lens );
		} );
	} );

	/* --- tab toggle (Content / Style; disabled tabs inert) --------------- */

	root.querySelectorAll( '[data-s5-tab]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( btn.classList.contains( 'is-disabled' ) || btn.disabled ) {
				return;
			}
			var tab = btn.getAttribute( 'data-s5-tab' );
			state.tab = tab;
			root.querySelectorAll( '.s5-editor__tab' ).forEach( function ( b ) {
				var on = b === btn;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			root.setAttribute( 'data-s5-active-tab', tab );
		} );
	} );

	/* --- Update button (save pipeline wires in B2) ----------------------- */

	if ( updateBtn ) {
		updateBtn.addEventListener( 'click', function () {
			// Phase B2 attaches the REST save here. In the shell, Update is inert
			// and stays disabled until a real edit marks the state dirty.
		} );
	}

	/* --- expose a tiny controller for later items ------------------------ */

	window.S5EditorShell = {
		state: state,
		config: cfg,
		markDirty: markDirty,
		markClean: markClean,
		getPreviewFrame: function () {
			return root.querySelector( '[data-s5-preview]' );
		},
	};

	// Start clean.
	markClean();
	root.setAttribute( 'data-s5-active-lens', state.lens );
	root.setAttribute( 'data-s5-active-tab', state.tab );
}() );
