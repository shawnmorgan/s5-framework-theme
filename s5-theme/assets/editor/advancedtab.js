/*
 * S5 Framework — Advanced tab (Editor, Phase B).
 *
 * Renders the selected node's structural/meta facts into the props Advanced tab:
 *   - HTML tag (shown; a validated tag-swap mode is a later, separate one-door op —
 *     changing an element's tag is a structural edit, not a class swap, so it is NOT
 *     faked here);
 *   - the node's data-node id (the stable selection anchor, R2);
 *   - responsive visibility — breakpoint HIDE classes that SHIP in the DOM (R4). These
 *     are class swaps through the SAME axis-swap door as the Style tab; if the theme's
 *     vocabulary defines no breakpoint-hide axis yet, the control is shown disabled
 *     with an honest note rather than inventing classes.
 *
 * No free-form class or raw-CSS field ever appears here (HANDOFF §0.1 / §6).
 */
( function () {
	'use strict';

	var shell = window.S5EditorShell;
	if ( ! shell ) {
		return;
	}
	var cfg = window.S5Editor || {};
	var root = document.querySelector( '[data-s5-editor]' );
	var host = root ? root.querySelector( '[data-s5-advanced]' ) : null;
	if ( ! host ) {
		return;
	}
	var ORIGIN = window.location.origin;

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

	function field( label, valueEl ) {
		var f = el( 'div', 's5-editor__field' );
		f.appendChild( el( 'label', 's5-editor__field-label', label ) );
		f.appendChild( valueEl );
		return f;
	}

	// The breakpoint-hide axes from the manifest (if the vocabulary backs any).
	function visibilityAxes() {
		return ( cfg.styleAxes || [] ).filter( function ( a ) {
			return /^hide-/.test( a.key ) || /visibility/.test( a.key );
		} );
	}

	function render( sel ) {
		host.innerHTML = '';
		if ( ! sel || ! sel.node ) {
			host.appendChild( el( 'p', 's5-editor__hint', 'Select an element to see its tag, anchor, and visibility.' ) );
			return;
		}

		// Tag (read-only display; a validated tag-swap is a later one-door op).
		var tag = ( sel.tag || sel.type || '' ).toString();
		host.appendChild( field( 'HTML tag', el( 'div', 's5-editor__badge', tag || '—' ) ) );

		// Stable selection anchor.
		var nodeVal = el( 'div' );
		nodeVal.style.fontFamily = 'var(--s5e-mono)';
		nodeVal.style.fontSize = '11px';
		nodeVal.style.color = 'var(--s5e-text-faint)';
		nodeVal.textContent = sel.node;
		host.appendChild( field( 'Node id', nodeVal ) );

		// Responsive visibility.
		var vAxes = visibilityAxes();
		var vf = el( 'div', 's5-editor__field' );
		vf.appendChild( el( 'label', 's5-editor__field-label', 'Responsive visibility' ) );
		if ( ! vAxes.length ) {
			var seg = el( 'div', 's5-editor__seg is-wide' );
			var opt = el( 'div', 's5-editor__seg-opt is-active', 'Always visible' );
			opt.style.opacity = '0.6';
			seg.appendChild( opt );
			vf.appendChild( seg );
			vf.appendChild( el( 'p', 's5-editor__field-hint',
				'This theme’s design system has no breakpoint-hide classes yet. Add them in Styleguide to hide elements per breakpoint (they SHIP in the DOM — distinct from Disable).' ) );
		}
		host.appendChild( vf );
	}

	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== ORIGIN || ! e.data || 'object' !== typeof e.data ) {
			return;
		}
		if ( 'select' === e.data.s5 ) {
			render( e.data );
		}
	} );

	render( null );
}() );
