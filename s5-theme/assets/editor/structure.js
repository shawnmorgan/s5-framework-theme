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

	function kindGlyph( kind ) {
		if ( 'component' === kind ) {
			return '◧';
		}
		if ( 'dynamic' === kind ) {
			return '↻';
		}
		return '·';
	}

	function render( nodes ) {
		listEl.innerHTML = '';
		rowsByNode = {};
		if ( ! nodes || ! nodes.length ) {
			listEl.appendChild( el( 'li', { class: 's5-editor__hint' }, 'No editable structure on this page.' ) );
			return;
		}
		nodes.forEach( function ( n ) {
			var li = el( 'li', {
				class: 's5-editor__structure-item is-' + ( n.kind || 'editable' ),
				'data-node': n.node,
				'data-depth': String( n.depth || 0 ),
				style: 'padding-left:' + ( 8 + ( n.depth || 0 ) * 12 ) + 'px',
				tabindex: '0',
				role: 'button',
			} );
			li.appendChild( el( 'span', { class: 's5-editor__structure-kind' }, kindGlyph( n.kind ) ) );
			li.appendChild( el( 'span', { class: 's5-editor__structure-label' }, n.label || n.node || '(node)' ) );
			if ( n.type ) {
				li.appendChild( el( 'span', { class: 's5-editor__structure-type' }, n.type ) );
			}
			var select = function () {
				if ( ! n.node ) {
					return;
				}
				var w = frameWindow();
				if ( w ) {
					w.postMessage( { s5: 's5-select-node', node: n.node }, ORIGIN );
				}
			};
			li.addEventListener( 'click', select );
			li.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key || ' ' === e.key ) {
					e.preventDefault();
					select();
				}
			} );
			if ( n.node ) {
				rowsByNode[ n.node ] = li;
			}
			listEl.appendChild( li );
		} );
	}

	function highlight( node ) {
		Object.keys( rowsByNode ).forEach( function ( k ) {
			rowsByNode[ k ].removeAttribute( 'data-s5-active' );
		} );
		if ( node && rowsByNode[ node ] ) {
			rowsByNode[ node ].setAttribute( 'data-s5-active', '' );
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
