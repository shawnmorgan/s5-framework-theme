/**
 * S5 interaction island — wishlist (SHARED state demo, Build Order Step 10).
 *
 * A minimal pub/sub store two elements share, with NO SPA runtime. It demonstrates
 * that parts of a page can coordinate small client state while the SERVER stays
 * authoritative for anything persisted — this store holds only ephemeral UI state,
 * seeded from server-rendered markup.
 *
 * The island `<div data-s5-island="wishlist">` wires an "add" button and a live
 * count badge that read/write the same store; both update before any page refresh.
 * The island name matches this module's filename so sidecar discovery enqueues it.
 */
const listeners = new Set();
const state = { count: 0 };

function publish() {
	listeners.forEach( ( fn ) => fn( state ) );
}

function subscribe( fn ) {
	listeners.add( fn );
	fn( state );
	return () => listeners.delete( fn );
}

function add( n = 1 ) {
	state.count += n;
	publish();
}

document.querySelectorAll( '[data-s5-island="wishlist"]' ).forEach( ( root ) => {
	// Seed from the server-rendered value (server is authoritative for initial state).
	const badge = root.querySelector( '[data-s5-wishlist-count]' );
	const button = root.querySelector( '[data-s5-action="add"]' );
	if ( badge ) {
		state.count = parseInt( badge.textContent, 10 ) || state.count;
	}

	// Badge: reflect shared state.
	if ( badge ) {
		subscribe( ( s ) => {
			badge.textContent = String( s.count );
		} );
	}
	// Button: mutate shared state — the badge updates via the store.
	if ( button ) {
		button.addEventListener( 'click', () => add( 1 ) );
	}
} );
