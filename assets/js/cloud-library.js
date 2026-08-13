/**
 * Cloud Library — browse + import the workspace's cloud artifacts.
 *
 * Each sandbox screen (Blocks / Widgets / PHP Snippets) renders a
 * `.karmcp-cloud-lib` <details> panel carrying its kind + nonce + this-site UUID
 * and its i18n labels in data-* attributes. On first open the panel fetches the
 * whole workspace's artifacts of that kind (across every connected site) and
 * lists them with a per-row Import button. Import pulls the portable bundle into
 * THIS site as a new inactive draft (KarMCP_Cloud_Sync::pull).
 *
 * Self-contained (no localized object) — mirrors sandbox-cloud.js.
 */
( function () {
	'use strict';
	var ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';

	function t( panel, key, fallback ) {
		return panel.getAttribute( 'data-t-' + key ) || fallback;
	}

	function post( action, panel, extra ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', panel.getAttribute( 'data-nonce' ) || '' );
		body.append( 'kind', panel.getAttribute( 'data-kind' ) || '' );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) { body.append( k, extra[ k ] ); } );
		}
		return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
	}

	function fmtDate( s ) {
		if ( ! s ) { return ''; }
		var d = new Date( s );
		return isNaN( d.getTime() ) ? '' : d.toISOString().slice( 0, 10 );
	}

	// Friendliest available label for the artifact's origin site. Prefers a
	// server-supplied name/url; falls back to "This site" / "Another site (uuid)".
	function originLabel( panel, a ) {
		var site = panel.getAttribute( 'data-site' ) || '';
		if ( a.origin && site && a.origin === site ) {
			return { text: t( panel, 'thissite', 'This site' ), self: true };
		}
		if ( a.origin_name ) { return { text: a.origin_name, self: false }; }
		if ( a.origin_url ) {
			return { text: a.origin_url.replace( /^https?:\/\//, '' ).replace( /\/$/, '' ), self: false };
		}
		if ( a.origin ) {
			return { text: t( panel, 'othersite', 'Another site' ) + ' (' + a.origin.slice( 0, 8 ) + ')', self: false };
		}
		return { text: t( panel, 'othersite', 'Another site' ), self: false };
	}

	function setStatus( panel, text, isErr ) {
		var status = panel.querySelector( '.karmcp-cloud-lib__status' );
		if ( ! status ) { return; }
		status.style.display = '';
		status.textContent = text;
		status.style.color = isErr ? '#b32d2e' : '';
	}

	function showReload( panel ) {
		if ( panel.__reload ) { return; }
		panel.__reload = true;
		var body = panel.querySelector( '.karmcp-cloud-lib__body' );
		if ( ! body ) { return; }
		var p = document.createElement( 'p' );
		p.style.margin = '10px 0 0';
		p.appendChild( document.createTextNode( t( panel, 'reloadhint', 'Imported as a new inactive draft below.' ) + ' ' ) );
		var link = document.createElement( 'a' );
		link.href = '#';
		link.className = 'button button-primary';
		link.textContent = t( panel, 'reload', 'Reload to view →' );
		link.addEventListener( 'click', function ( e ) { e.preventDefault(); window.location.reload(); } );
		p.appendChild( link );
		body.appendChild( p );
	}

	function doImport( panel, a, btn, cell ) {
		btn.disabled = true;
		btn.textContent = t( panel, 'importing', 'Importing…' );
		post( 'karmcp_tools_cloud_import', panel, { uuid: a.uuid } ).then( function ( res ) {
			if ( res && res.success ) {
				cell.textContent = '';
				var ok = document.createElement( 'span' );
				ok.style.color = '#1a7f4b';
				ok.style.fontWeight = '600';
				var yes = document.createElement( 'span' );
				yes.className = 'dashicons dashicons-yes';
				yes.setAttribute( 'aria-hidden', 'true' );
				yes.style.verticalAlign = 'text-bottom';
				ok.appendChild( yes );
				ok.appendChild( document.createTextNode( ' ' + t( panel, 'imported', 'Imported' ) ) );
				cell.appendChild( ok );
				showReload( panel );
			} else {
				btn.disabled = false;
				btn.textContent = t( panel, 'import', 'Import' );
				setStatus( panel, ( res && res.data && res.data.message ) || t( panel, 'error', 'Import failed.' ), true );
			}
		} ).catch( function () {
			btn.disabled = false;
			btn.textContent = t( panel, 'import', 'Import' );
			setStatus( panel, t( panel, 'error', 'Import failed.' ), true );
		} );
	}

	function render( panel, arts ) {
		var status = panel.querySelector( '.karmcp-cloud-lib__status' );
		var table = panel.querySelector( '.karmcp-cloud-lib__table' );
		var tbody = table ? table.querySelector( 'tbody' ) : null;
		if ( ! tbody ) { return; }
		tbody.innerHTML = '';

		if ( ! arts.length ) {
			if ( status ) { status.textContent = t( panel, 'empty', 'Nothing in your cloud library yet.' ); status.style.display = ''; }
			table.style.display = 'none';
			return;
		}
		if ( status ) { status.style.display = 'none'; }
		table.style.display = '';

		arts.forEach( function ( a ) {
			var o = originLabel( panel, a );
			var tr = document.createElement( 'tr' );

			var tdTitle = document.createElement( 'td' );
			var strong = document.createElement( 'strong' );
			strong.textContent = a.title || '(untitled)';
			tdTitle.appendChild( strong );
			var tdFrom = document.createElement( 'td' );
			if ( o.self ) {
				var em = document.createElement( 'span' );
				em.className = 'description';
				em.textContent = o.text;
				tdFrom.appendChild( em );
			} else {
				tdFrom.textContent = o.text;
			}
			var tdVer = document.createElement( 'td' );
			tdVer.textContent = 'v' + ( a.version || 1 );
			var tdUpd = document.createElement( 'td' );
			tdUpd.className = 'description';
			tdUpd.textContent = fmtDate( a.updated );
			var tdAct = document.createElement( 'td' );

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button';
			var ico = document.createElement( 'span' );
			ico.className = 'dashicons dashicons-download';
			ico.setAttribute( 'aria-hidden', 'true' );
			ico.style.verticalAlign = 'text-bottom';
			btn.appendChild( ico );
			btn.appendChild( document.createTextNode( ' ' + t( panel, 'import', 'Import' ) ) );
			btn.addEventListener( 'click', function () { doImport( panel, a, btn, tdAct ); } );
			tdAct.appendChild( btn );

			tr.appendChild( tdTitle );
			tr.appendChild( tdFrom );
			tr.appendChild( tdVer );
			tr.appendChild( tdUpd );
			tr.appendChild( tdAct );
			tbody.appendChild( tr );
		} );
	}

	function load( panel ) {
		if ( panel.__loaded ) { return; }
		panel.__loaded = true;
		setStatus( panel, t( panel, 'loading', 'Loading…' ), false );
		post( 'karmcp_tools_cloud_library', panel, null ).then( function ( res ) {
			if ( res && res.success && res.data ) {
				render( panel, res.data.artifacts || [] );
			} else {
				panel.__loaded = false;
				setStatus( panel, ( res && res.data && res.data.message ) || t( panel, 'error', 'Could not load.' ), true );
			}
		} ).catch( function () {
			panel.__loaded = false;
			setStatus( panel, t( panel, 'error', 'Could not load.' ), true );
		} );
	}

	function init() {
		var panels = document.querySelectorAll( '.karmcp-cloud-lib' );
		Array.prototype.forEach.call( panels, function ( panel ) {
			panel.addEventListener( 'toggle', function () { if ( panel.open ) { load( panel ); } } );
			if ( panel.open ) { load( panel ); }
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
