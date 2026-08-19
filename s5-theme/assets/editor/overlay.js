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

	// The editor accent, brought into the preview iframe so the selection chrome
	// matches the tool (canvas-mockup.html --s5e-accent #5b8cff).
	var ACCENT = '#5b8cff';

	// Load the bundled FontAwesome INTO the preview iframe so the selection-chrome
	// .sel-act buttons show the mockup's exact glyphs (fa-pen/fa-copy/fa-eye-slash).
	// Same-origin, local vendor file — passed in from the parent as cfg.faUrl.
	( function loadFA() {
		try {
			var faUrl = ( parent && parent.S5Editor && parent.S5Editor.faUrl ) || '';
			if ( faUrl && ! document.querySelector( 'link[data-s5-fa]' ) ) {
				var lnk = document.createElement( 'link' );
				lnk.rel = 'stylesheet';
				lnk.href = faUrl;
				lnk.setAttribute( 'data-s5-fa', '' );
				document.head.appendChild( lnk );
			}
		} catch ( e ) { /* cross-origin parent — skip; glyphs degrade to boxes, geometry holds */ }
	}() );

	// Selection chrome TRANSCRIBED from canvas-mockup.html (.sel-outline / .sel-tag /
	// .sel-name / .sel-tools / .sel-act, CSS lines 389-403). Injected into the iframe.
	var style = document.createElement( 'style' );
	style.textContent =
		'[data-s5-hover]{outline:2px dashed ' + ACCENT + ' !important;outline-offset:2px;cursor:pointer;}' +
		'[data-s5-selected]{outline:2px solid ' + ACCENT + ' !important;outline-offset:2px;border-radius:3px;}' +
		'[data-s5-dynamic]{outline:2px solid #f0c33c !important;outline-offset:2px;}' +
		'.s5-seltag{position:absolute;z-index:2147483000;display:flex;align-items:stretch;white-space:nowrap;' +
		'background:' + ACCENT + ';color:#fff;border-radius:4px 4px 0 0;font-size:11px;font-weight:600;' +
		'box-shadow:0 2px 8px rgba(0,0,0,.3);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}' +
		'.s5-seltag .s5-seltag-name{display:flex;align-items:center;padding:3px 9px;}' +
		'.s5-seltag .s5-seltag-tools{display:flex;align-items:stretch;border-left:1px solid rgba(255,255,255,.28);}' +
		'.s5-seltag button{display:flex;align-items:center;justify-content:center;width:26px;background:transparent;' +
		'border:none;color:#fff;font-size:11px;cursor:pointer;transition:.1s;}' +
		'.s5-seltag button:hover{background:rgba(255,255,255,.18);}' +
		'.s5-seltag button:last-child{border-radius:0 4px 0 0;}' +
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
		// Mockup .sel-tag: .sel-name + .sel-tools( edit · duplicate · disable ), FA glyphs
		// (fa-pen / fa-copy / fa-eye-slash / fa-eye). NO trash (R7).
		selTag.innerHTML =
			'<span class="s5-seltag-name"></span>' +
			'<span class="s5-seltag-tools">' +
			'<button type="button" data-act="edit" title="Edit"><i class="fa-solid fa-pen"></i></button>' +
			'<button type="button" data-act="duplicate" title="Duplicate"><i class="fa-regular fa-copy"></i></button>' +
			( isDisabled
				? '<button type="button" data-act="enable" title="Re-enable (include in output)"><i class="fa-solid fa-eye"></i></button>'
				: '<button type="button" data-act="disable" title="Disable (drop from output)"><i class="fa-solid fa-eye-slash"></i></button>' ) +
			'</span>';
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
				// Bug fix (fresh re-audit): this field was missing, so the props panel's
				// Enabled/Disabled toggle (panel.js enableRow) always read undefined ->
				// always showed "Enabled" even for a disabled element, even though the
				// underlying data-disabled attribute and the structure-tree red-line were
				// both correct. reportStructure() below already computes this the same
				// way -- keep both in sync.
				disabled: 'true' === el.getAttribute( 'data-disabled' ),
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

	// True for the structural containers that give the tree its skeleton: the page
	// root (<main>), its sections, and registered components. These are what a client
	// reasons about ("where does the Hero start?"), so they must appear as rows even
	// when they carry no data-edit of their own.
	function isStructural( el ) {
		if ( el.nodeType !== 1 ) {
			return false;
		}
		if ( el.hasAttribute( 'data-s5-component' ) || el.hasAttribute( 'data-edit' ) ) {
			return true;
		}
		var tag = el.tagName.toLowerCase();
		return 'main' === tag || 'section' === tag || 'header' === tag || 'footer' === tag;
	}

	// Humanize a container class/tag into a section label: "page-hero" → "Hero",
	// "features" → "Features". Falls back to the tag name.
	function sectionLabel( el ) {
		var tag = el.tagName.toLowerCase();
		if ( 'main' === tag ) {
			return 'Page';
		}
		var cls = ( el.getAttribute( 'class' ) || '' ).split( /\s+/ )[ 0 ] || '';
		cls = cls.replace( /^(page|site)-/, '' ).replace( /[-_]+/g, ' ' ).trim();
		var label = cls
			? cls.charAt( 0 ).toUpperCase() + cls.slice( 1 )
			: tag.charAt( 0 ).toUpperCase() + tag.slice( 1 );
		return 'section' === tag ? label + ' section' : label;
	}

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

	// Ensure a structural container is selectable: leaf editables/components already
	// carry a data-node; a bare <section>/<main> gets a transient one assigned here so
	// the tree row can select it and (later) anchor an insert. This annotation lives
	// only on the in-preview DOM — it is never written back to the canonical HTML.
	var structSeq = 0;
	function ensureNode( el ) {
		var id = el.getAttribute( 'data-node' );
		if ( ! id ) {
			id = 's5-struct-' + ( ++structSeq );
			el.setAttribute( 'data-node', id );
			el.setAttribute( 'data-s5-transient-node', '' );
		}
		return id;
	}

	// Walk the page's structural nodes as a REAL tree: containers (main / sections /
	// components) and the editables nested inside them, in document order, each with a
	// depth so the parent panel can indent and show where every section starts + ends.
	function reportStructure() {
		var main = document.querySelector( 'main[data-s5], main' ) || document.body;
		var list = [];

		function walk( el, depth ) {
			for ( var child = el.firstElementChild; child; child = child.nextElementSibling ) {
				if ( isStructural( child ) ) {
					var isContainer = ! child.hasAttribute( 'data-edit' ) ||
						'main' === child.tagName.toLowerCase() ||
						'section' === child.tagName.toLowerCase();
					var kind = child.hasAttribute( 'data-s5-component' ) ? 'component'
						: ( isContainer ? 'section'
							: ( child.hasAttribute( 'data-s5-dynamic' ) ? 'dynamic' : 'editable' ) );
					list.push( {
						node: ensureNode( child ),
						type: child.getAttribute( 'data-edit' ) || '',
						kind: kind,
						label: ( 'section' === kind && ! child.hasAttribute( 'data-s5-component' ) )
							? sectionLabel( child ) : nodeLabel( child ),
						depth: depth,
						disabled: 'true' === child.getAttribute( 'data-disabled' ),
					} );
					walk( child, depth + 1 );
				} else {
					// Non-structural wrapper (e.g. a layout div): descend without adding a
					// row, so its editables still attach to the nearest structural depth.
					walk( child, depth );
				}
			}
		}

		// Report <main> itself as the root row, then everything beneath it.
		if ( main && main !== document.body && isStructural( main ) ) {
			list.push( {
				node: ensureNode( main ),
				type: '',
				kind: 'section',
				label: sectionLabel( main ),
				depth: 0,
				disabled: false,
			} );
			walk( main, 1 );
		} else {
			walk( main, 0 );
		}

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
