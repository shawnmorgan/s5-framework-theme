/*
 * S5 Framework — Style tab per-element controls (R3, axis-swap-only).
 *
 * Renders one control per manifest axis (S5Editor.styleAxes) into the props Style
 * tab. Each control can ONLY swap within its axis's sanctioned class set — it never
 * emits a free-form class. On change it posts mode:"class" { node, axis, class } to
 * the ONE save door; the server strips the axis's classes and adds the chosen one
 * (or rejects, loudly). Axes the theme's vocabulary does not yet back render disabled
 * with an honest note rather than inventing classes.
 */
( function () {
	'use strict';

	var shell = window.S5EditorShell;
	if ( ! shell ) {
		return;
	}
	var cfg = window.S5Editor || {};
	var root = document.querySelector( '[data-s5-editor]' );
	var host = root ? root.querySelector( '[data-s5-style-controls]' ) : null;
	if ( ! host ) {
		return;
	}
	var ORIGIN = window.location.origin;
	var axes = cfg.styleAxes || [];
	var selectedNode = '';

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

	function shortLabel( cls, axisKey ) {
		// A compact control label: drop the axis prefix, keep the meaningful suffix.
		return cls
			.replace( /^u-text-/, '' )
			.replace( new RegExp( '^' + axisKey.replace( /[-/\\^$*+?.()|[\]{}]/g, '\\$&' ) + '-' ), '' )
			.replace( /^.*-/, '' );
	}

	function sliderControl( axis, chosen ) {
		// Ladder: index 0 = None, 1..N = axis.classes in order. The slider position maps
		// to a class (or '' for None) and posts the same class-swap the buttons would.
		var wrap = el( 'div', 's5e-slider' );
		var ladder = [ '' ].concat( axis.classes );
		var idx = Math.max( 0, ladder.indexOf( chosen ) );

		var head = el( 'div', 's5e-slider-head' );
		var val = el( 'span', 's5e-slider-val', 0 === idx ? 'None' : shortLabel( ladder[ idx ], axis.key ) );
		head.appendChild( val );
		wrap.appendChild( head );

		var input = document.createElement( 'input' );
		input.type = 'range';
		input.className = 's5e-range';
		input.min = '0';
		input.max = String( ladder.length - 1 );
		input.step = '1';
		input.value = String( idx );
		input.setAttribute( 'aria-label', axis.label );

		function label( i ) {
			return 0 === i ? 'None' : shortLabel( ladder[ i ], axis.key );
		}
		// Live label while dragging; commit the swap only on release (fewer saves).
		input.addEventListener( 'input', function () {
			val.textContent = label( parseInt( input.value, 10 ) );
		} );
		input.addEventListener( 'change', function () {
			postSwap( axis.key, ladder[ parseInt( input.value, 10 ) ] );
		} );
		wrap.appendChild( input );

		// End-cap ticks so the ladder's range is legible (None … max step).
		var ticks = el( 'div', 's5e-slider-ticks' );
		ticks.appendChild( el( 'span', null, 'None' ) );
		ticks.appendChild( el( 'span', null, shortLabel( ladder[ ladder.length - 1 ], axis.key ) ) );
		wrap.appendChild( ticks );

		return wrap;
	}

	function postSwap( axisKey, cls ) {
		if ( ! selectedNode || ! cfg.saveUrl ) {
			return;
		}
		shell.markDirty();
		fetch( cfg.saveUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify( { mode: 'class', node: selectedNode, axis: axisKey, class: cls } ),
		} )
			.then( function ( r ) {
				return r.json().then( function ( b ) {
					return { status: r.status, body: b };
				} );
			} )
			.then( function ( res ) {
				if ( res.body && res.body.ok ) {
					shell.markClean();
					var f = shell.getPreviewFrame();
					if ( f ) {
						f.contentWindow.location.reload();
					}
				} else {
					note( ( res.body && res.body.message ) || 'Style change rejected.' );
				}
			} )
			.catch( function () {
				note( 'Network error applying style.' );
			} );
	}

	function note( msg ) {
		var n = el( 'p', 's5e-diag', msg );
		host.insertBefore( n, host.firstChild );
		setTimeout( function () {
			if ( n.parentNode ) {
				n.parentNode.removeChild( n );
			}
		}, 4000 );
	}

	// Render the controls for the CURRENT selection's classes.
	function render( currentClasses ) {
		host.innerHTML = '';
		if ( ! selectedNode ) {
			host.appendChild( el( 'p', 's5e-hint', 'Select an element to adjust its variant, size, spacing and role color.' ) );
			return;
		}
		var present = ( currentClasses || '' ).split( /\s+/ );

		axes.forEach( function ( axis ) {
			var field = el( 'div', 'field' );
			field.appendChild( el( 'label', 'field-label', axis.label ) );

			if ( ! axis.available ) {
				// Honest: no sanctioned classes for this axis in the theme's vocabulary.
				var seg = el( 'div', 'seg wide' );
				var opt = el( 'div', 'seg-opt active', '—' );
				opt.style.opacity = '0.5';
				seg.appendChild( opt );
				field.appendChild( seg );
				field.appendChild( el( 'p', 'field-hint',
					'This theme’s design system has no ' + axis.label.toLowerCase() + ' scale yet. Add one in Styleguide to enable this control.' ) );
				host.appendChild( field );
				return;
			}

			// Which sanctioned class (if any) is currently on the element.
			var chosen = '';
			axis.classes.forEach( function ( cls ) {
				if ( present.indexOf( cls ) !== -1 ) {
					chosen = cls;
				}
			} );

			if ( 'slider' === axis.control && axis.classes.length > 2 ) {
				// Ordinal scale (size / spacing) — a slider is far less cramped than a
				// long button row. Position 0 = None, 1..N = each class in ladder order.
				field.appendChild( sliderControl( axis, chosen ) );
				host.appendChild( field );
				return;
			}

			// Segmented control over the axis's sanctioned set (+ a "None" option).
			var segEl = el( 'div', 'seg wide' );
			var none = el( 'div', 'seg-opt' + ( '' === chosen ? ' is-active' : '' ), 'None' );
			none.addEventListener( 'click', function () {
				postSwap( axis.key, '' );
			} );
			segEl.appendChild( none );

			axis.classes.forEach( function ( cls ) {
				var o = el( 'div', 'seg-opt' + ( cls === chosen ? ' is-active' : '' ), shortLabel( cls, axis.key ) );
				o.title = cls;
				o.addEventListener( 'click', function () {
					postSwap( axis.key, cls );
				} );
				segEl.appendChild( o );
			} );

			field.appendChild( segEl );
			host.appendChild( field );
		} );

		if ( ! axes.length ) {
			host.appendChild( el( 'p', 's5e-hint', 'No style axes are defined for this theme yet.' ) );
		}
	}

	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== ORIGIN || ! e.data || 'object' !== typeof e.data ) {
			return;
		}
		if ( 'select' === e.data.s5 ) {
			selectedNode = e.data.node || '';
			render( e.data.classes || '' );
		}
	} );

	render( '' );
}() );
