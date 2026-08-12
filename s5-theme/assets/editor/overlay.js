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
		'[data-s5-dynamic]{outline:2px solid #f0c33c !important;outline-offset:2px;}';
	document.head.appendChild( style );

	function editableAncestor( el ) {
		while ( el && el !== document.body ) {
			if ( el.nodeType === 1 && el.hasAttribute( 'data-edit' ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
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

		parent.postMessage(
			{
				s5: 'select',
				node: el.getAttribute( 'data-node' ) || '',
				type: el.getAttribute( 'data-edit' ) || 'text',
				text: el.textContent || '',
				href: el.getAttribute( 'href' ) || '',
				src: el.getAttribute( 'src' ) || '',
				alt: el.getAttribute( 'alt' ) || '',
				dynamic: el.hasAttribute( 'data-s5-dynamic' ),
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
			}
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
			}
		}
	} );

	function cssEscape( s ) {
		if ( window.CSS && CSS.escape ) {
			return CSS.escape( s );
		}
		return String( s ).replace( /["\\\]]/g, '\\$&' );
	}

	// Mark dynamic (L&L) regions so the parent can present them as locked (B5).
	document.querySelectorAll( '[data-edit][data-s5-dynamic-region]' ).forEach( function ( n ) {
		n.setAttribute( 'data-s5-dynamic', '' );
	} );

	// Announce readiness so the parent can enable the Edit lens.
	parent.postMessage( { s5: 'ready' }, ORIGIN );
}() );
