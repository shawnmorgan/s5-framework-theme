/*
 * S5 Framework — Theme Settings app driver.
 *
 * Wires the mockup's interactions (theme-settings-mockup.html <script>) to the REAL
 * server-rendered markup: the left sub-nav screen toggle, generic switches, redirects
 * add/remove, the CSS/Tailwind layer tabs (fed the real file contents), integrations
 * add/remove/toggle + conditions, and the roles permission-matrix toggles + user-role
 * select colouring. No data is invented here — every screen's content is rendered
 * server-side from the real sources; this only drives the client-side behavior.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-s5-settings]' );
	if ( ! root ) {
		return;
	}

	/* ---- left sub-nav screen toggle (mockup .snav / .screen) ---- */
	var navs = root.querySelectorAll( '.snav' );
	var screens = root.querySelectorAll( '.screen' );
	navs.forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			// Toggle client-side (no reload); keep the URL's ?screen in sync for deep-links.
			var key = btn.getAttribute( 'data-screen' );
			if ( ! key ) {
				return;
			}
			e.preventDefault();
			navs.forEach( function ( n ) {
				n.classList.toggle( 'active', n === btn );
				n.setAttribute( 'aria-current', n === btn ? 'page' : 'false' );
			} );
			screens.forEach( function ( sc ) {
				sc.classList.toggle( 'active', sc.getAttribute( 'data-screen' ) === key );
			} );
			try {
				var url = new URL( window.location.href );
				url.searchParams.set( 'screen', key );
				window.history.replaceState( {}, '', url );
			} catch ( err ) { /* history unavailable — the toggle still works */ }
		} );
	} );

	/* ---- generic toggle switches (presentational: homepage, build flags, tpl parts) ---- */
	root.querySelectorAll( '.switch' ).forEach( function ( sw ) {
		if ( sw.hasAttribute( 'data-s5-int-switch' ) ) {
			return; // integration switches own their handler (they mirror a hidden input)
		}
		sw.addEventListener( 'click', function () {
			var on = sw.classList.toggle( 'on' );
			sw.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			var tplRow = sw.closest( '.tpl-row' );
			if ( tplRow && sw.classList.contains( 'tpl-switch' ) ) {
				tplRow.classList.toggle( 'row-off', ! on );
			}
			var comp = sw.closest( '.comp-card' );
			if ( comp ) {
				comp.classList.toggle( 'off', ! on );
			}
		} );
	} );

	/* ---- comp-card: click body opens the builder; the switch does not ---- */
	root.querySelectorAll( '.comp-card .switch' ).forEach( function ( sw ) {
		sw.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
		} );
	} );

	/* ---- General → Redirects: add / remove rows ---- */
	var rdrHost = root.querySelector( '[data-s5-redirects]' );
	if ( rdrHost ) {
		function bindRdrDel( btn ) {
			btn.addEventListener( 'click', function () {
				var row = btn.closest( '.rdr-row' );
				if ( row ) {
					row.parentNode.removeChild( row );
				}
			} );
		}
		rdrHost.querySelectorAll( '[data-s5-rdr-del]' ).forEach( bindRdrDel );
	}

	/* ---- CSS / Tailwind: layer tabs fed the REAL file contents ---- */
	var layerTabs = root.querySelector( '[data-s5-layer-tabs]' );
	if ( layerTabs ) {
		var layerData = {};
		root.querySelectorAll( '[data-s5-layer]' ).forEach( function ( node ) {
			try {
				layerData[ node.getAttribute( 'data-s5-layer' ) ] = JSON.parse( node.textContent || '{}' );
			} catch ( err ) { /* skip malformed */ }
		} );
		var lpath = root.querySelector( '[data-s5-layer-path]' );
		var ltag = root.querySelector( '[data-s5-layer-tag]' );
		var lpre = root.querySelector( '[data-s5-layer-pre]' );
		var lgut = root.querySelector( '[data-s5-layer-gutter]' );

		function fillGutter( code ) {
			if ( ! lgut ) {
				return;
			}
			var n = ( code || '' ).split( '\n' ).length;
			var out = '';
			for ( var i = 1; i <= n; i++ ) {
				out += '<span>' + i + '</span>';
			}
			lgut.innerHTML = out;
		}
		function showLayer( key ) {
			var L = layerData[ key ];
			if ( ! L ) {
				return;
			}
			if ( lpath ) {
				lpath.textContent = L.path || '';
			}
			if ( ltag ) {
				ltag.textContent = L.tag || '';
				ltag.className = 'tag ' + ( L.ro ? 'readonly' : 'editable' );
			}
			if ( lpre ) {
				lpre.textContent = L.code || '';
			}
			fillGutter( L.code || '' );
		}
		layerTabs.querySelectorAll( '.layer-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				layerTabs.querySelectorAll( '.layer-tab' ).forEach( function ( t ) {
					t.classList.toggle( 'active', t === tab );
				} );
				showLayer( tab.getAttribute( 'data-layer' ) );
			} );
		} );
		// initial gutter for the server-rendered first layer
		if ( lpre ) {
			fillGutter( lpre.textContent || '' );
		}
	}

	/* ---- Integrations: add / remove / toggle / conditions ---- */
	var intList = root.querySelector( '[data-s5-int-list]' );
	var intTpl = root.querySelector( '[data-s5-int-template]' );
	var intAdd = root.querySelector( '[data-s5-int-add]' );
	function bindIntSwitch( sw ) {
		sw.addEventListener( 'click', function () {
			var on = sw.classList.toggle( 'on' );
			sw.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			var card = sw.closest( '.int-card' );
			if ( card ) {
				card.classList.toggle( 'off', ! on );
				var hidden = card.querySelector( '[data-s5-int-enabled]' );
				if ( hidden ) {
					hidden.value = on ? '1' : '0';
				}
			}
		} );
	}
	function bindIntDel( btn ) {
		btn.addEventListener( 'click', function () {
			var card = btn.closest( '.int-card' );
			if ( card ) {
				card.parentNode.removeChild( card );
			}
		} );
	}
	function bindConditions( scope ) {
		scope.querySelectorAll( '.cond-del' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var r = b.closest( '.cond-row' );
				if ( r ) {
					r.parentNode.removeChild( r );
				}
			} );
		} );
		scope.querySelectorAll( '.cond-add' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var g = b.closest( '.cond-group' );
				var proto = g && g.querySelector( '.cond-row' );
				if ( proto ) {
					var clone = proto.cloneNode( true );
					var del = clone.querySelector( '.cond-del' );
					if ( del ) {
						del.addEventListener( 'click', function () {
							clone.parentNode.removeChild( clone );
						} );
					}
					g.insertBefore( clone, b );
				}
			} );
		} );
	}
	if ( intList ) {
		var intSeq = intList.querySelectorAll( '[data-int-row]' ).length;
		intList.querySelectorAll( '[data-s5-int-switch]' ).forEach( bindIntSwitch );
		intList.querySelectorAll( '[data-s5-int-del]' ).forEach( bindIntDel );
		bindConditions( intList );
		if ( intAdd && intTpl ) {
			intAdd.addEventListener( 'click', function () {
				var empty = intList.querySelector( '[data-s5-int-empty]' );
				if ( empty ) {
					empty.parentNode.removeChild( empty );
				}
				var html = intTpl.innerHTML.replace( /__i__/g, String( intSeq++ ) );
				var wrap = document.createElement( 'div' );
				wrap.innerHTML = html.trim();
				var card = wrap.firstChild;
				intList.appendChild( card );
				bindIntSwitch( card.querySelector( '[data-s5-int-switch]' ) );
				bindIntDel( card.querySelector( '[data-s5-int-del]' ) );
				bindConditions( card );
			} );
		}
	}

	/* ---- Roles: permission-matrix toggles (button mirrors a hidden checkbox) ---- */
	root.querySelectorAll( '[data-s5-perm-btn]' ).forEach( function ( btn ) {
		if ( btn.hasAttribute( 'disabled' ) ) {
			return; // developer column is locked-on
		}
		btn.addEventListener( 'click', function () {
			var cell = btn.closest( '.perm-mark' );
			var input = cell && cell.querySelector( '[data-s5-perm-input]' );
			if ( ! input ) {
				return;
			}
			input.checked = ! input.checked;
			var on = input.checked;
			btn.classList.toggle( 'on', on );
			btn.classList.toggle( 'off', ! on );
			btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			var i = btn.querySelector( 'i' );
			if ( i ) {
				i.className = 'fa-solid ' + ( on ? 'fa-check' : 'fa-minus' );
			}
		} );
	} );

	/* ---- Roles: user role-select colouring ---- */
	root.querySelectorAll( '[data-s5-user-role]' ).forEach( function ( sel ) {
		sel.addEventListener( 'change', function () {
			sel.classList.remove( 'role-developer', 'role-marketer', 'role-client' );
			sel.classList.add( 'role-' + sel.value );
		} );
	} );

	/* ---- StyleGuide: token edit pencils are native <details> (no JS needed);
	   the per-token form POSTs through the one-door token save. Nothing to bind. */
}() );
