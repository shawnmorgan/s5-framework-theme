/*
 * S5 Framework — right-panel structure list (Item 5).
 *
 * A SIMPLE flat list (not a full tree — a demo bar) of the page's structural
 * nodes: components and editables, derived from the preview's sidecar node map
 * (the overlay walks the DOM and reports it as s5-structure).
 *
 * Selection is synced BOTH ways with the glass:
 *   - click a row  -> tell the overlay to select that node (scroll + highlight);
 *     the overlay's normal select flow then opens the existing edit panel.
 *   - select in the preview -> the matching row highlights here.
 */
( function () {
	'use strict';

	var shell = window.S5EditorShell;
	if ( ! shell ) {
		return;
	}
	var root = document.querySelector( '[data-s5-editor]' );
	var listEl = root ? root.querySelector( '[data-s5-structure]' ) : null;
	if ( ! listEl ) {
		return;
	}
	var ORIGIN = window.location.origin;
	var rowsByNode = {};

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

	// Mockup kind→glyph map (canvas-mockup.html line 1371-1373). Unknown kinds use
	// the mockup's generic fa-cube. The mockup's own icon system — invents nothing.
	var KIND_ICON = {
		heading: 'fa-heading',
		text: 'fa-align-left',
		rich: 'fa-align-left',
		button: 'fa-hand-pointer',
		link: 'fa-hand-pointer',
		media: 'fa-image',
		img: 'fa-image',
		card: 'fa-square',
		section: 'fa-table-cells-large',
		group: 'fa-object-group',
		collection: 'fa-border-all',
		list: 'fa-border-all',
		component: 'fa-cubes',
	};

	function render( nodes ) {
		listEl.innerHTML = '';
		rowsByNode = {};
		if ( ! nodes || ! nodes.length ) {
			listEl.appendChild( el( 'p', { class: 's5e-hint' }, 'No editable structure on this page.' ) );
			return;
		}
		// TRANSCRIBED from canvas-mockup.html (.tnode/.trow/.twist/.tico/.tlabel/.ttype,
		// lines 1384-1407). GAP: the server reports a FLAT depth list, not a DOM tree, so
		// rows are rendered flat and indented by depth via nested .tchildren rails rather
		// than reconstructing parent nesting (logged in FIDELITY-EXCEPTIONS.md). Each row
		// keeps the mockup's markup, kind glyph, and type tag exactly.
		nodes.forEach( function ( n ) {
			var kind = n.kind || n.type || 'editable';
			var ico = KIND_ICON[ kind ] || KIND_ICON[ n.type ] || 'fa-cube';
			var depth = n.depth || 0;

			var node = el( 'div', { class: 'tnode leaf' + ( n.disabled ? ' ' : '' ) } );
			var row = el( 'div', {
				class: 'trow' + ( n.disabled ? ' row-disabled' : '' ),
				'data-node': n.node,
				'data-depth': String( depth ),
				style: depth ? 'margin-left:' + depth * 14 + 'px' : '',
				tabindex: '0',
				role: 'button',
			} );
			// twist (leaf → hidden via .leaf .twist), kind icon, label + type tag.
			row.appendChild( el( 'span', { class: 'twist' } ) );
			var tico = el( 'span', { class: 'tico' } );
			tico.appendChild( el( 'i', { class: 'fa-solid ' + ico } ) );
			row.appendChild( tico );
			var label = el( 'span', { class: 'tlabel' }, n.label || n.node || '(node)' );
			if ( n.type ) {
				label.appendChild( el( 'span', { class: 'ttype' }, n.type ) );
			}
			row.appendChild( label );
			node.appendChild( row );

			var select = function () {
				if ( ! n.node ) {
					return;
				}
				var w = frameWindow();
				if ( w ) {
					w.postMessage( { s5: 's5-select-node', node: n.node }, ORIGIN );
				}
			};
			row.addEventListener( 'click', select );
			row.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key || ' ' === e.key ) {
					e.preventDefault();
					select();
				}
			} );
			if ( n.node ) {
				rowsByNode[ n.node ] = row;
			}
			listEl.appendChild( node );
		} );
	}

	function highlight( node ) {
		Object.keys( rowsByNode ).forEach( function ( k ) {
			rowsByNode[ k ].classList.remove( 'selected' );
		} );
		if ( node && rowsByNode[ node ] ) {
			rowsByNode[ node ].classList.add( 'selected' );
		}
	}

	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== ORIGIN || ! e.data || 'object' !== typeof e.data ) {
			return;
		}
		var msg = e.data;
		if ( 's5-structure' === msg.s5 ) {
			render( msg.nodes );
		} else if ( 'select' === msg.s5 ) {
			highlight( msg.node || '' );
		}
	} );
}() );
