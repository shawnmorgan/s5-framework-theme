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

	/* ---- save-feedback toast: auto-dismiss after a beat (it lives outside
	 * [data-s5-settings], pinned above the fixed app), so a save shows a visible
	 * confirmation instead of a silent full-page-reload "flash". ---- */
	( function () {
		var toast = document.querySelector( '[data-s5-toast]' );
		if ( ! toast ) {
			return;
		}
		window.setTimeout( function () {
			toast.classList.add( 's5set-toast-out' );
			window.setTimeout( function () {
				if ( toast.parentNode ) {
					toast.parentNode.removeChild( toast );
				}
			}, 320 );
		}, 2600 );
	}() );

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

	/* ---- CSS / Tailwind: layer tabs switch between editable panels ----
	 * Each layer (Tokens / Classes / Custom) is a [data-s5-css-layer] panel holding one
	 * editable <textarea> form per real file; the tabs just show/hide the active panel.
	 * The textareas POST to the CSS save handler on their own Save button (no JS save). */
	var layerTabs = root.querySelector( '[data-s5-layer-tabs]' );
	if ( layerTabs ) {
		var cssPanels = root.querySelectorAll( '[data-s5-css-layer]' );
		layerTabs.querySelectorAll( '.layer-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var key = tab.getAttribute( 'data-layer' );
				layerTabs.querySelectorAll( '.layer-tab' ).forEach( function ( t ) {
					t.classList.toggle( 'active', t === tab );
				} );
				cssPanels.forEach( function ( panel ) {
					panel.hidden = ( panel.getAttribute( 'data-s5-css-layer' ) !== key );
				} );
			} );
		} );
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
	function bindCondDel( btn ) {
		btn.addEventListener( 'click', function () {
			var r = btn.closest( '.cond-row' );
			if ( r ) {
				r.parentNode.removeChild( r );
			}
		} );
	}
	function bindCondType( row ) {
		// A rule row has two value selects sharing name="…[value]": one for post_type
		// (page/post), one listing specific pages. Only the select matching the chosen
		// type is enabled + visible; the other is hidden AND disabled so it never submits.
		var typeSel = row.querySelector( '[data-s5-cond-type]' );
		if ( ! typeSel ) {
			return;
		}
		function sync() {
			var t = typeSel.value;
			// front_page has no value control — treat it like post_type (the type select
			// alone is meaningful; the value select is harmless/ignored server-side).
			var want = ( 'post' === t ) ? 'post' : 'post_type';
			row.querySelectorAll( '[data-s5-cond-val]' ).forEach( function ( sel ) {
				var on = ( sel.getAttribute( 'data-s5-cond-val' ) === want );
				sel.hidden = ! on;
				sel.disabled = ! on;
			} );
		}
		typeSel.addEventListener( 'change', sync );
		sync();
	}

	function bindConditions( scope ) {
		// Each integration has one [data-s5-cond] block: three mode radios (all /
		// include / exclude) that show or hide the rule builder, plus an Add-rule
		// button that clones the server-rendered prototype with a fresh index.
		scope.querySelectorAll( '[data-s5-cond]' ).forEach( function ( cond ) {
			var rules = cond.querySelector( '[data-s5-cond-rules]' );
			var tpl = cond.querySelector( '[data-s5-cond-template]' );
			cond.querySelectorAll( '[data-s5-cond-mode]' ).forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					if ( rules ) {
						rules.hidden = ( 'all' === radio.value );
					}
				} );
			} );
			cond.querySelectorAll( '.cond-del' ).forEach( bindCondDel );
			cond.querySelectorAll( '[data-s5-cond-row]' ).forEach( bindCondType );
			var add = cond.querySelector( '[data-s5-cond-add]' );
			if ( add && tpl && rules ) {
				add.addEventListener( 'click', function () {
					var seq = parseInt( add.getAttribute( 'data-cond-seq' ) || '0', 10 );
					add.setAttribute( 'data-cond-seq', String( seq + 1 ) );
					// The template's field names carry the __r__ index token; swap it
					// for a unique index so the new rule submits as its own array entry.
					var html = tpl.innerHTML.replace( /__r__/g, String( seq ) );
					var wrap = document.createElement( 'div' );
					wrap.innerHTML = html.trim();
					var row = wrap.firstChild;
					var del = row.querySelector( '.cond-del' );
					if ( del ) {
						bindCondDel( del );
					}
					bindCondType( row );
					rules.insertBefore( row, add );
				} );
			}
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

	/* ---- Styles → Elements: MODAL editing (same pattern as the Component Builder).
	   A card's pencil (or clicking the preview) opens the element-editor modal filled
	   from the card's data-sg-el; "New element" opens its own modal; "View Styleguide"
	   opens the full specimen. All close on the X/Cancel, Esc, or a backdrop click. */
	( function () {
		// Generic modal open/close with backdrop-click + Esc, matching cb-modal's .open.
		function openModal( modal ) {
			if ( ! modal ) {
				return;
			}
			modal.classList.add( 'open' );
			modal.setAttribute( 'aria-hidden', 'false' );
		}
		function closeModal( modal ) {
			if ( ! modal ) {
				return;
			}
			modal.classList.remove( 'open' );
			modal.setAttribute( 'aria-hidden', 'true' );
		}
		function wireClose( modal, closeSelector ) {
			if ( ! modal ) {
				return;
			}
			modal.querySelectorAll( closeSelector ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					closeModal( modal );
				} );
			} );
			// Backdrop click: a click directly on the modal shell (not its inner content).
			modal.addEventListener( 'mousedown', function ( e ) {
				if ( e.target === modal ) {
					closeModal( modal );
				}
			} );
		}

		var elModal = root.querySelector( '[data-s5-el-modal]' );
		var newModal = root.querySelector( '[data-s5-new-element-modal]' );
		var sgModal = root.querySelector( '[data-s5-sg-modal]' );

		// Element-editor modal — fill from the clicked card, then open.
		if ( elModal ) {
			var nameEl = elModal.querySelector( '[data-s5-el-modal-name]' );
			var selEl = elModal.querySelector( '[data-s5-el-modal-selector]' );
			var openBrace = elModal.querySelector( '[data-s5-el-modal-open]' );
			var keyInput = elModal.querySelector( '[data-s5-el-modal-key]' );
			var codeEl = elModal.querySelector( '[data-s5-el-modal-code]' );

			function openElement( card ) {
				var data = {};
				try {
					data = JSON.parse( card.getAttribute( 'data-sg-el' ) || '{}' );
				} catch ( e ) {
					data = {};
				}
				if ( nameEl ) {
					nameEl.textContent = data.label || '';
				}
				if ( selEl ) {
					selEl.textContent = data.selector || '';
				}
				if ( openBrace ) {
					openBrace.textContent = ( data.selector || '' ) + ' {';
				}
				if ( keyInput ) {
					keyInput.value = data.key || '';
				}
				if ( codeEl ) {
					codeEl.value = data.rule || '';
				}
				openModal( elModal );
				if ( codeEl ) {
					codeEl.focus();
				}
			}

			root.querySelectorAll( '.sg-el-card' ).forEach( function ( card ) {
				card.querySelectorAll( '[data-s5-el-open]' ).forEach( function ( trigger ) {
					trigger.addEventListener( 'click', function () {
						openElement( card );
					} );
				} );
			} );
			wireClose( elModal, '[data-s5-el-modal-close]' );
		}

		// New-element modal.
		if ( newModal ) {
			var newBtn = root.querySelector( '[data-s5-new-element]' );
			if ( newBtn ) {
				newBtn.addEventListener( 'click', function () {
					openModal( newModal );
					var first = newModal.querySelector( 'input[name="label"]' );
					if ( first ) {
						first.focus();
					}
				} );
			}
			wireClose( newModal, '[data-s5-new-element-close]' );
		}

		// Full-styleguide modal (View Styleguide button already exists as data-s5-sg-view).
		if ( sgModal ) {
			var viewBtn = root.querySelector( '[data-s5-sg-view]' );
			if ( viewBtn ) {
				viewBtn.addEventListener( 'click', function () {
					openModal( sgModal );
				} );
			}
			wireClose( sgModal, '[data-s5-sg-close]' );
		}

		// Esc closes whichever modal is open.
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' !== e.key ) {
				return;
			}
			[ elModal, newModal, sgModal ].forEach( function ( m ) {
				if ( m && m.classList.contains( 'open' ) ) {
					closeModal( m );
				}
			} );
		} );
	}() );
}() );
