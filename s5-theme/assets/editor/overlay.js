/*
 * S5 Framework — preview overlay (Editor MVP, Phase B2).
 *
 * Injected by the parent editor into the SAME-ORIGIN preview iframe. Its only
 * jobs are SELECTION and live feedback — it is never the state store
 * (EDITOR-DESIGN.md §1/§4):
 *   - outline every [data-edit] node on hover (Edit lens only)
 *   - on click, postMessage the node's data-node + data-edit type to the parent
 *   - apply a live text/attribute patch on request (feedback only; the real save
 *     re-writes canonical server-side via the tag processor)
 *
 * Elements WITHOUT [data-edit] are never wired: no hover, no click, no field.
 * Absence is intentional (§6.1) — this is the props demo.
 */
( function () {
	'use strict';

	var ORIGIN = window.location.origin;
	var lensActive = true;
	var hoverEl = null;

	var style = document.createElement( 'style' );
	style.textContent =
		'[data-s5-hover]{outline:2px dashed #2271b1 !important;outline-offset:2px;cursor:pointer;}' +
		'[data-s5-selected]{outline:2px solid #2271b1 !important;outline-offset:2px;}' +
		'[data-s5-dynamic]{outline:2px solid #f0c33c !important;outline-offset:2px;}' +
		'.s5-seltag{position:absolute;z-index:2147483000;display:flex;align-items:center;gap:2px;' +
		'background:#1b1e26;border:1px solid #2b2f3a;border-radius:6px;padding:3px;' +
		'box-shadow:0 6px 20px rgba(0,0,0,.4);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}' +
		'.s5-seltag button{width:26px;height:24px;border:none;background:transparent;color:#9aa0ad;' +
		'border-radius:4px;cursor:pointer;font-size:12px;line-height:1;}' +
		'.s5-seltag button:hover{background:#232733;color:#e6e8ee;}' +
		'.s5-seltag .s5-seltag-name{color:#e6e8ee;font-size:11px;padding:0 6px;white-space:nowrap;}' +
		'[data-disabled="true"]{outline:2px dashed #ff5c5c !important;outline-offset:2px;opacity:.45 !important;position:relative;}';
	document.head.appendChild( style );

	// The floating selection toolbar: edit · duplicate · disable (NO trash — R7).
	var selTag = null;
	function removeSelTag() {
		if ( selTag && selTag.parentNode ) {
			selTag.parentNode.removeChild( selTag );
		}
		selTag = null;
	}
	function showSelTag( el ) {
		removeSelTag();
		var node = el.getAttribute( 'data-node' ) || '';
		var name = el.getAttribute( 'data-s5-component' ) || el.getAttribute( 'data-edit' ) || el.tagName.toLowerCase();
		var isDisabled = 'true' === el.getAttribute( 'data-disabled' );
		selTag = document.createElement( 'div' );
		selTag.className = 's5-seltag';
		selTag.setAttribute( 'data-s5-seltag', '' );
		selTag.innerHTML =
			'<span class="s5-seltag-name"></span>' +
			'<button type="button" data-act="edit" title="Edit">✎</button>' +
			'<button type="button" data-act="duplicate" title="Duplicate">⧉</button>' +
			( isDisabled
				? '<button type="button" data-act="enable" title="Re-enable (include in output)">✓</button>'
				: '<button type="button" data-act="disable" title="Disable (drop from output)">⊘</button>' );
		selTag.querySelector( '.s5-seltag-name' ).textContent = name;
		document.body.appendChild( selTag );

		// Position above the element's top-left, clamped into view.
		var r = el.getBoundingClientRect();
		var top = window.scrollY + r.top - 32;
		if ( top < window.scrollY + 2 ) {
			top = window.scrollY + r.top + 4;
		}
		selTag.style.top = top + 'px';
		selTag.style.left = ( window.scrollX + r.left ) + 'px';

		selTag.querySelector( '[data-act="edit"]' ).addEventListener( 'click', function ( ev ) {
			ev.stopPropagation();
			parent.postMessage( { s5: 's5-focus-props', node: node }, ORIGIN );
		} );
		selTag.querySelector( '[data-act="duplicate"]' ).addEventListener( 'click', function ( ev ) {
			ev.stopPropagation();
			parent.postMessage( { s5: 's5-duplicate', node: node }, ORIGIN );
		} );
		var disBtn = selTag.querySelector( '[data-act="disable"]' );
		if ( disBtn ) {
			disBtn.addEventListener( 'click', function ( ev ) {
				ev.stopPropagation();
				parent.postMessage( { s5: 's5-disable', node: node, disabled: true }, ORIGIN );
			} );
		}
		var enBtn = selTag.querySelector( '[data-act="enable"]' );
		if ( enBtn ) {
			enBtn.addEventListener( 'click', function ( ev ) {
				ev.stopPropagation();
				parent.postMessage( { s5: 's5-disable', node: node, disabled: false }, ORIGIN );
			} );
		}
	}

	function editableAncestor( el ) {
		while ( el && el !== document.body ) {
			if ( el.nodeType === 1 && el.hasAttribute( 'data-edit' ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	// A list node's items as newline-separated text (for the list slot editor).
	function listItems( el ) {
		if ( ! el ) {
			return '';
		}
		var lis = el.querySelectorAll( 'li' );
		if ( ! lis.length ) {
			return '';
		}
		return Array.prototype.map.call( lis, function ( li ) {
			return ( li.textContent || '' ).trim();
		} ).join( '\n' );
	}

	function clearHover() {
		if ( hoverEl ) {
			hoverEl.removeAttribute( 'data-s5-hover' );
			hoverEl = null;
		}
	}

	document.addEventListener( 'mouseover', function ( e ) {
		if ( ! lensActive ) {
			return;
		}
		var el = editableAncestor( e.target );
		if ( el === hoverEl ) {
			return;
		}
		clearHover();
		if ( el ) {
			el.setAttribute( 'data-s5-hover', '' );
			hoverEl = el;
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( ! lensActive ) {
			return;
		}
		var el = editableAncestor( e.target );
		if ( ! el ) {
			return;
		}
		// Never navigate while selecting in Edit lens.
		e.preventDefault();
		e.stopPropagation();

		document.querySelectorAll( '[data-s5-selected]' ).forEach( function ( n ) {
			n.removeAttribute( 'data-s5-selected' );
		} );
		el.setAttribute( 'data-s5-selected', '' );
		showSelTag( el );

		parent.postMessage(
			{
				s5: 'select',
				node: el.getAttribute( 'data-node' ) || '',
				type: el.getAttribute( 'data-edit' ) || 'text',
				text: el.textContent || '',
				href: el.getAttribute( 'href' ) || '',
				src: el.getAttribute( 'src' ) || '',
				alt: el.getAttribute( 'alt' ) || '',
				items: listItems( el ),
				dynamic: el.hasAttribute( 'data-s5-dynamic' ),
				classes: el.getAttribute( 'class' ) || '',
				tag: el.tagName ? el.tagName.toLowerCase() : '',
			},
			ORIGIN
		);
	}, true );

	// Commands FROM the parent (live feedback patches + lens on/off).
	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== ORIGIN || ! e.data || 'object' !== typeof e.data ) {
			return;
		}
		var msg = e.data;
		if ( 's5-lens' === msg.s5 ) {
			lensActive = 'edit' === msg.lens;
			if ( ! lensActive ) {
				clearHover();
				removeSelTag();
			}
			return;
		}
		if ( 's5-drag' === msg.s5 ) {
			dragging = msg.on ? ( msg.component || '' ) : null;
			if ( ! dragging ) {
				clearHover();
			}
			return;
		}
		if ( 's5-select-node' === msg.s5 ) {
			selectByNode( msg.node || '' );
			return;
		}
		if ( 's5-patch' === msg.s5 && msg.node ) {
			var el = document.querySelector( '[data-node="' + cssEscape( msg.node ) + '"]' );
			if ( ! el ) {
				return;
			}
			if ( 'text' === msg.type ) {
				el.textContent = msg.value;
			} else if ( 'rich' === msg.type ) {
				el.innerHTML = msg.value;
			} else if ( 'link' === msg.type ) {
				el.setAttribute( 'href', msg.value );
			} else if ( 'img' === msg.type ) {
				if ( 'src' === msg.attr ) {
					el.setAttribute( 'src', msg.value );
				} else {
					el.setAttribute( 'alt', msg.value );
				}
			} else if ( 'src' === msg.type ) {
				el.setAttribute( 'src', msg.value );
			} else if ( 'list' === msg.type ) {
				var html = ( msg.value || '' ).split( /\r\n|\r|\n/ ).map( function ( line ) {
					line = line.trim();
					return line ? '<li>' + escapeHtml( line ) + '</li>' : '';
				} ).join( '' );
				el.innerHTML = html;
			}
		}
	} );

	function cssEscape( s ) {
		if ( window.CSS && CSS.escape ) {
			return CSS.escape( s );
		}
		return String( s ).replace( /["\\\]]/g, '\\$&' );
	}

	// Minimal HTML-text escape for live list feedback (server re-sanitizes on save).
	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	/* --- drag/drop from the palette: resolve a drop to its nearest data-node --- */
	var dragging = null; // component name while a palette drag is active.

	// The nearest ancestor (or self) that carries a data-node id — the drop anchor.
	function nodeAncestor( el ) {
		while ( el && el !== document.body ) {
			if ( el.nodeType === 1 && el.hasAttribute( 'data-node' ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	document.addEventListener( 'dragover', function ( e ) {
		if ( ! dragging ) {
			return;
		}
		var target = nodeAncestor( e.target );
		if ( target ) {
			e.preventDefault(); // allow drop
			clearHover();
			target.setAttribute( 'data-s5-hover', '' );
			hoverEl = target;
		}
	} );

	document.addEventListener( 'drop', function ( e ) {
		if ( ! dragging ) {
			return;
		}
		e.preventDefault();
		var target = nodeAncestor( e.target );
		clearHover();
		if ( target ) {
			parent.postMessage(
				{ s5: 's5-drop', component: dragging, node: target.getAttribute( 'data-node' ) || '' },
				ORIGIN
			);
		}
		dragging = null;
	} );

	// Mark dynamic (L&L) regions so the parent can present them as locked (B5).
	document.querySelectorAll( '[data-edit][data-s5-dynamic-region]' ).forEach( function ( n ) {
		n.setAttribute( 'data-s5-dynamic', '' );
	} );

	/* --- structure map: report the page's structural nodes (Item 5) ---------- */

	// A short human label for a node: prefer its component name, else its tag +
	// a snippet of text. Keeps the right-panel list readable without a full tree.
	function nodeLabel( el ) {
		var comp = el.getAttribute( 'data-s5-component' );
		if ( comp ) {
			return comp;
		}
		var t = ( el.textContent || '' ).trim().replace( /\s+/g, ' ' );
		if ( t.length > 32 ) {
			t = t.slice( 0, 32 ) + '…';
		}
		return t || el.tagName.toLowerCase();
	}

	// Walk every node the page exposes as structure: components (data-s5-component)
	// and editables (data-edit). Report a FLAT list (a demo bar, not a full tree)
	// with a depth hint so the parent can indent visually.
	function reportStructure() {
		var nodes = document.querySelectorAll( '[data-s5-component],[data-edit]' );
		var list = Array.prototype.map.call( nodes, function ( el ) {
			var kind = el.hasAttribute( 'data-s5-component' ) ? 'component'
				: ( el.hasAttribute( 'data-s5-dynamic' ) ? 'dynamic' : 'editable' );
			// Depth = count of structural ancestors, for a light indent.
			var depth = 0, p = el.parentNode;
			while ( p && p !== document.body ) {
				if ( p.nodeType === 1 && ( p.hasAttribute( 'data-s5-component' ) || p.hasAttribute( 'data-edit' ) ) ) {
					depth++;
				}
				p = p.parentNode;
			}
			return {
				node: el.getAttribute( 'data-node' ) || '',
				type: el.getAttribute( 'data-edit' ) || '',
				kind: kind,
				label: nodeLabel( el ),
				depth: depth,
				disabled: 'true' === el.getAttribute( 'data-disabled' ),
			};
		} );
		parent.postMessage( { s5: 's5-structure', nodes: list }, ORIGIN );
	}

	// Programmatic selection from the structure list: scroll to + select a node.
	function selectByNode( id ) {
		var el = id ? document.querySelector( '[data-node="' + cssEscape( id ) + '"]' ) : null;
		if ( ! el ) {
			return;
		}
		if ( el.scrollIntoView ) {
			el.scrollIntoView( { block: 'center' } );
		}
		el.click(); // reuse the normal selection path (posts the select message)
	}

	// Announce readiness so the parent can enable the Edit lens.
	parent.postMessage( { s5: 'ready' }, ORIGIN );
	reportStructure();
}() );
