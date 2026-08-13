/**
 * Sandbox cloud/marketplace button state machine.
 *
 * Each artifact row renders a `.karmcp-sb-cloud` cluster carrying its initial
 * state as JSON in data-state. This script renders the right buttons from that
 * state, refreshes pushed artifacts against the cloud on load, and wires the
 * Save-to-Cloud / Push-update actions.
 *
 * State shape (from KarMCP_Admin::cloud_action_payload):
 *   { kind, id, pushed, changed, slug, status, published, has_pending_update,
 *     publish_url, view_url }
 */
( function () {
	'use strict';
	var ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';

	function post( action, cluster, extra ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cluster.getAttribute( 'data-nonce' ) || '' );
		body.append( 'kind', cluster.getAttribute( 'data-kind' ) || '' );
		body.append( 'id', cluster.getAttribute( 'data-id' ) || '' );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) { body.append( k, extra[ k ] ); } );
		}
		return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
	}

	// WordPress .button (display:inline-block) overrides the [hidden] attribute,
	// so toggle inline display instead.
	function show( el, on ) { if ( el ) { el.style.display = on ? '' : 'none'; } }

	// Update a button's label without wiping its dashicon.
	function setText( el, text ) {
		if ( ! el ) { return; }
		var t = el.querySelector( '.karmcp-sb-txt' );
		if ( t ) { t.textContent = text; } else { el.textContent = text; }
	}

	function render( cluster, s ) {
		cluster.__state = s;
		var save = cluster.querySelector( '.karmcp-sb-save' );
		var pub = cluster.querySelector( '.karmcp-sb-publish' );
		var view = cluster.querySelector( '.karmcp-sb-view' );
		var upd = cluster.querySelector( '.karmcp-sb-update' );
		var tag = cluster.querySelector( '.karmcp-sb-tag' );

		// Save to Cloud: primary until published. Once published, updates go
		// through "Push update" instead.
		if ( save ) {
			if ( ! s.pushed ) {
				setText( save, save.getAttribute( 'data-t-save' ) || 'Save to Cloud' );
				save.disabled = false;
				show( save, true );
			} else if ( s.published ) {
				show( save, false );
			} else if ( s.changed ) {
				setText( save, save.getAttribute( 'data-t-update' ) || 'Update cloud' );
				save.disabled = false;
				show( save, true );
			} else {
				setText( save, save.getAttribute( 'data-t-saved' ) || 'Saved' );
				save.disabled = true;
				show( save, true );
			}
		}

		// Publish: only before it's on the marketplace.
		if ( pub ) {
			var canPublish = s.pushed && ! s.slug && s.publish_url;
			if ( canPublish ) { pub.href = s.publish_url; }
			show( pub, !! canPublish );
		}

		// View on Marketplace: once it has a listing.
		if ( view ) {
			if ( s.view_url ) { view.href = s.view_url; }
			show( view, !! s.slug );
		}

		// Push update: published + locally changed + not already in review.
		show( upd, !! ( s.published && s.changed && ! s.has_pending_update ) );

		// Status tag.
		if ( tag ) {
			var text = '';
			if ( s.has_pending_update ) { text = tag.getAttribute( 'data-t-inreview' ) || 'Update in review'; }
			else if ( s.slug && s.status === 'pending' ) { text = tag.getAttribute( 'data-t-pending' ) || 'In review'; }
			else if ( s.published && ! s.changed ) { text = tag.getAttribute( 'data-t-uptodate' ) || 'Up to date'; }
			tag.textContent = text;
			show( tag, text !== '' );
		}
	}

	function msg( cluster, text, isErr ) {
		var m = cluster.querySelector( '.karmcp-sb-msg' );
		if ( ! m ) { return; }
		m.textContent = text || '';
		m.style.color = isErr ? '#b32d2e' : '#2271b1';
	}

	function handle( res, cluster ) {
		if ( res && res.success && res.data ) {
			render( cluster, res.data );
			msg( cluster, res.data.message || '', false );
		} else {
			msg( cluster, ( res && res.data && res.data.message ) || 'Failed.', true );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.karmcp-sb-save, .karmcp-sb-update' );
		if ( ! btn ) { return; }
		var cluster = btn.closest( '.karmcp-sb-cloud' );
		if ( ! cluster ) { return; }
		e.preventDefault();
		btn.disabled = true;
		msg( cluster, '…', false );

		if ( btn.classList.contains( 'karmcp-sb-update' ) ) {
			var note = window.prompt( 'What changed in this update? (optional)' );
			if ( note === null ) { btn.disabled = false; msg( cluster, '', false ); return; }
			post( 'karmcp_tools_push_update', cluster, { changelog: note } ).then( function ( res ) {
				btn.disabled = false; handle( res, cluster );
			} ).catch( function () { btn.disabled = false; msg( cluster, 'Network error.', true ); } );
		} else {
			post( 'karmcp_tools_backup_artifact', cluster, null ).then( function ( res ) {
				btn.disabled = false; handle( res, cluster );
			} ).catch( function () { btn.disabled = false; msg( cluster, 'Network error.', true ); } );
		}
	} );

	// Full resync of one cluster: verifies the cloud backup still exists (heals a
	// stale "Saved" after a cloud-side delete) and refreshes marketplace state.
	function resync( cluster ) {
		return post( 'karmcp_tools_resync_cloud', cluster, null ).then( function ( res ) {
			if ( res && res.success && res.data ) { render( cluster, res.data ); }
			return res;
		} );
	}

	// A page-level "Refresh cloud status" button that resyncs every artifact —
	// the manual escape hatch when the cloud was changed elsewhere (e.g. an
	// artifact deleted from the website).
	function mountRefreshAll( clusters ) {
		if ( ! clusters.length ) { return; }
		var anchor = clusters[ 0 ].closest( 'table' ) || clusters[ 0 ];
		if ( ! anchor || ! anchor.parentNode ) { return; }

		var bar = document.createElement( 'p' );
		bar.className = 'karmcp-sb-refresh-bar';
		bar.style.margin = '12px 0';

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button karmcp-sb-refresh-all';
		var ico = document.createElement( 'span' );
		ico.className = 'dashicons dashicons-update';
		ico.setAttribute( 'aria-hidden', 'true' );
		ico.style.verticalAlign = 'text-bottom';
		btn.appendChild( ico );
		btn.appendChild( document.createTextNode( ' Refresh cloud status' ) );

		// "Save all to Cloud" — bulk-push every artifact of this kind in one call.
		var saveAll = document.createElement( 'button' );
		saveAll.type = 'button';
		saveAll.className = 'button karmcp-sb-save-all';
		saveAll.style.marginRight = '8px';
		var sico = document.createElement( 'span' );
		sico.className = 'dashicons dashicons-upload';
		sico.setAttribute( 'aria-hidden', 'true' );
		sico.style.verticalAlign = 'text-bottom';
		saveAll.appendChild( sico );
		saveAll.appendChild( document.createTextNode( ' Save all to Cloud' ) );

		var note = document.createElement( 'span' );
		note.className = 'karmcp-sb-refresh-msg';
		note.style.marginLeft = '10px';
		note.style.color = '#2271b1';

		bar.appendChild( saveAll );
		bar.appendChild( btn );
		bar.appendChild( note );
		anchor.parentNode.insertBefore( bar, anchor );

		saveAll.addEventListener( 'click', function () {
			saveAll.disabled = true;
			btn.disabled = true;
			note.textContent = 'Saving all to cloud…';
			// The bulk handler only needs kind + nonce (id is ignored) — send from any cluster.
			post( 'karmcp_tools_bulk_backup_artifacts', clusters[ 0 ], null ).then( function ( res ) {
				if ( res && res.success ) {
					note.textContent = ( res.data && res.data.message ) || 'Saved to cloud.';
					// Reload so every row's cloud state reflects the push.
					setTimeout( function () { window.location.reload(); }, 900 );
				} else {
					saveAll.disabled = false;
					btn.disabled = false;
					note.textContent = ( res && res.data && res.data.message ) || 'Could not save to cloud.';
				}
			} ).catch( function () {
				saveAll.disabled = false;
				btn.disabled = false;
				note.textContent = 'Could not save to cloud.';
			} );
		} );

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			note.textContent = 'Refreshing…';
			var jobs = Array.prototype.map.call( clusters, function ( c ) {
				return resync( c ).catch( function () {} );
			} );
			Promise.all( jobs ).then( function () {
				btn.disabled = false;
				note.textContent = 'Cloud status up to date.';
				setTimeout( function () { note.textContent = ''; }, 4000 );
			} );
		} );
	}

	function init() {
		var clusters = document.querySelectorAll( '.karmcp-sb-cloud' );
		Array.prototype.forEach.call( clusters, function ( cluster ) {
			var s;
			try { s = JSON.parse( cluster.getAttribute( 'data-state' ) || '{}' ); } catch ( err ) { s = {}; }
			render( cluster, s );
			// Pushed artifacts may have gained a listing (published on the website)
			// since last time — refresh their state in the background.
			if ( s.pushed ) {
				post( 'karmcp_tools_marketplace_state', cluster, null ).then( function ( res ) {
					if ( res && res.success && res.data ) { render( cluster, res.data ); }
				} ).catch( function () {} );
			}
		} );
		mountRefreshAll( clusters );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
