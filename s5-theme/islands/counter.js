/**
 * S5 interaction island — counter (LOCAL state demo, Build Order Step 10).
 *
 * Demonstrates local component state with a vanilla ES module and no framework
 * runtime. The island is a `<div data-s5-island="counter">` in authored HTML; the
 * framework enqueues THIS module only on pages that contain that island (sidecar
 * discovery), so static pages ship zero interaction JS.
 *
 * Progressive enhancement: the markup is meaningful without JS; this only wires
 * behavior onto it.
 */
function initCounter( root ) {
	const output = root.querySelector( '[data-s5-count]' );
	const inc = root.querySelector( '[data-s5-action="inc"]' );
	const dec = root.querySelector( '[data-s5-action="dec"]' );
	if ( ! output || ! inc || ! dec ) {
		return;
	}

	let count = parseInt( output.textContent, 10 ) || 0;
	const render = () => {
		output.textContent = String( count );
	};

	inc.addEventListener( 'click', () => {
		count += 1;
		render();
	} );
	dec.addEventListener( 'click', () => {
		count -= 1;
		render();
	} );
	render();
}

document.querySelectorAll( '[data-s5-island="counter"]' ).forEach( initCounter );
