/*
 * S5 Framework — Style tab (read-only) + tab switching (Editor MVP, Phase B4).
 *
 * Renders the theme design tokens (S5Editor.tokens, parsed live from the real
 * tokens.css / scales.css by Styles::read_tokens) as swatches, a type ramp,
 * spacing steps, and radius samples. NO editing anywhere in this tab — the
 * values come straight from the files so the panel can never drift (D5).
 * Components + Tailwind tabs stay disabled/inert (do-not-build list).
 */
( function () {
	'use strict';

	if ( ! window.S5EditorShell ) {
		return;
	}
	var cfg = window.S5Editor || {};
	var root = document.querySelector( '[data-s5-editor]' );
	var styleHost = root.querySelector( '[data-s5-styletab]' );

	/* --- tab switching (Content <-> Style; disabled tabs inert) ---------- */

	function showTab( tab ) {
		root.querySelectorAll( '[data-s5-tabpane]' ).forEach( function ( pane ) {
			pane.hidden = pane.getAttribute( 'data-s5-tabpane' ) !== tab;
		} );
	}

	root.querySelectorAll( '[data-s5-tab]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( btn.classList.contains( 'is-disabled' ) || btn.disabled ) {
				return;
			}
			showTab( btn.getAttribute( 'data-s5-tab' ) );
		} );
	} );

	/* --- render the token groups ----------------------------------------- */

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( undefined !== text && null !== text ) {
			n.textContent = text;
		}
		return n;
	}

	function section( title ) {
		var s = el( 'div', 's5-editor__style-section' );
		s.appendChild( el( 'div', 's5-editor__field-label', title ) );
		return s;
	}

	function renderColors( list, host ) {
		if ( ! list.length ) {
			return;
		}
		var s = section( 'Color' );
		var grid = el( 'div', 's5-editor__swatches' );
		list.forEach( function ( t ) {
			var sw = el( 'div', 's5-editor__swatch' );
			var chip = el( 'span', 's5-editor__chip' );
			chip.style.background = t.value;
			sw.appendChild( chip );
			sw.appendChild( el( 'span', 's5-editor__swatch-name', t.name.replace( '--s5-color-', '' ) ) );
			sw.appendChild( el( 'span', 's5-editor__swatch-val', t.value ) );
			grid.appendChild( sw );
		} );
		s.appendChild( grid );
		host.appendChild( s );
	}

	function renderTypeRamp( list, host ) {
		if ( ! list.length ) {
			return;
		}
		var s = section( 'Type scale' );
		list.forEach( function ( t ) {
			var row = el( 'div', 's5-editor__ramp-row' );
			var sample = el( 'span', 's5-editor__ramp-sample', 'Ag' );
			sample.style.fontSize = t.value;
			row.appendChild( sample );
			row.appendChild( el( 'span', 's5-editor__swatch-name', t.name.replace( '--s5-text-', '' ) ) );
			row.appendChild( el( 'span', 's5-editor__swatch-val', t.value ) );
			s.appendChild( row );
		} );
		host.appendChild( s );
	}

	function renderSpace( list, host ) {
		if ( ! list.length ) {
			return;
		}
		var s = section( 'Spacing' );
		list.forEach( function ( t ) {
			var row = el( 'div', 's5-editor__ramp-row' );
			var bar = el( 'span', 's5-editor__space-bar' );
			bar.style.width = t.value;
			row.appendChild( bar );
			row.appendChild( el( 'span', 's5-editor__swatch-name', t.name.replace( '--s5-space-', '' ) ) );
			row.appendChild( el( 'span', 's5-editor__swatch-val', t.value ) );
			s.appendChild( row );
		} );
		host.appendChild( s );
	}

	function renderRadius( list, host ) {
		if ( ! list.length ) {
			return;
		}
		var s = section( 'Radius' );
		var grid = el( 'div', 's5-editor__swatches' );
		list.forEach( function ( t ) {
			var sw = el( 'div', 's5-editor__swatch' );
			var box = el( 'span', 's5-editor__radius-box' );
			box.style.borderRadius = t.value;
			sw.appendChild( box );
			sw.appendChild( el( 'span', 's5-editor__swatch-name', t.name.replace( '--s5-radius-', '' ) ) );
			sw.appendChild( el( 'span', 's5-editor__swatch-val', t.value ) );
			grid.appendChild( sw );
		} );
		s.appendChild( grid );
		host.appendChild( s );
	}

	function renderPlain( title, list, host ) {
		if ( ! list.length ) {
			return;
		}
		var s = section( title );
		list.forEach( function ( t ) {
			var row = el( 'div', 's5-editor__ramp-row' );
			row.appendChild( el( 'span', 's5-editor__swatch-name', t.name ) );
			row.appendChild( el( 'span', 's5-editor__swatch-val', t.value ) );
			s.appendChild( row );
		} );
		host.appendChild( s );
	}

	function render() {
		if ( ! styleHost ) {
			return;
		}
		var tokens = cfg.tokens || {};
		var total = Object.keys( tokens ).reduce( function ( n, k ) {
			return n + ( tokens[ k ] ? tokens[ k ].length : 0 );
		}, 0 );

		styleHost.innerHTML = '';
		if ( ! total ) {
			styleHost.appendChild( el( 'p', 's5-editor__hint', 'No design tokens declared. Add files under styles.designTokens in s5.config.json.' ) );
			return;
		}

		renderColors( tokens.color || [], styleHost );
		renderTypeRamp( tokens.text || [], styleHost );
		renderSpace( tokens.space || [], styleHost );
		renderRadius( tokens.radius || [], styleHost );
		renderPlain( 'Font', tokens.font || [], styleHost );
		renderPlain( 'Leading', tokens.leading || [], styleHost );
		renderPlain( 'Other', tokens.other || [], styleHost );
	}

	/* --- CSS MODE toggle (Semantic active / Tailwind coming soon) --------- */

	function wireCssMode() {
		root.querySelectorAll( '[data-s5-cssmode-btn]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				// Tailwind is a designed-but-unbuilt mode: the button is disabled and
				// only signals the roadmap. Semantic stays the one active mode.
				if ( btn.disabled || 'tailwind' === btn.getAttribute( 'data-s5-cssmode-btn' ) ) {
					return;
				}
			} );
		} );
	}

	/* --- compiled stylesheet view (read-only) ----------------------------- */

	function wireStylesheet() {
		var toggle = root.querySelector( '[data-s5-stylesheet-toggle]' );
		var view = root.querySelector( '[data-s5-stylesheet]' );
		if ( ! toggle || ! view ) {
			return;
		}
		var loaded = false;
		toggle.addEventListener( 'click', function () {
			var open = view.hidden;
			view.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open && ! loaded && cfg.bundleUrl ) {
				loaded = true;
				view.textContent = 'Loading…';
				fetch( cfg.bundleUrl, { credentials: 'same-origin' } )
					.then( function ( r ) { return r.text(); } )
					.then( function ( css ) { view.textContent = css; } )
					.catch( function () { view.textContent = '/* Could not load the compiled stylesheet. */'; } );
			}
		} );
	}

	render();
	wireCssMode();
	wireStylesheet();
}() );
