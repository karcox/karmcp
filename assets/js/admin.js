/**
 * KarMCP — Admin Settings Scripts
 *
 * @package KarMCP
 * @since   1.0.0
 */

(function () {
	'use strict';

	/**
	 * Tools tab — Enable/Disable all toggles.
	 */
	function initToolsForm() {
		var form = document.getElementById( 'karmcp-tools-form' );
		if ( ! form ) {
			return;
		}

		// Global enable/disable all.
		var enableAll = form.querySelector( '.karmcp-enable-all' );
		var disableAll = form.querySelector( '.karmcp-disable-all' );

		// Scope bulk actions to the per-tool checkboxes only — NOT the separate
		// low-tools-mode toggle, which also lives in this form. (A bare
		// form.querySelectorAll('input[type="checkbox"]') would flip low-tools
		// mode too, silently overriding every individual toggle.)
		var toolCheckboxSelector = '.karmcp-tool-card input[type="checkbox"]';

		if ( enableAll ) {
			enableAll.addEventListener( 'click', function () {
				form.querySelectorAll( toolCheckboxSelector ).forEach( function ( cb ) {
					if ( ! cb.disabled ) {
						cb.checked = true;
					}
				} );
				updateCards( form );
			} );
		}

		if ( disableAll ) {
			disableAll.addEventListener( 'click', function () {
				form.querySelectorAll( toolCheckboxSelector ).forEach( function ( cb ) {
					if ( ! cb.disabled ) {
						cb.checked = false;
					}
				} );
				updateCards( form );
			} );
		}

		// Per-category enable/disable + collapsible section headers.
		// (cat scopes to .karmcp-category, which never contains the
		// low-tools-mode toggle, so the bulk selects below are safe.)
		var COLLAPSE_KEY = 'karmcpToolsCollapsed:';
		form.querySelectorAll( '.karmcp-category' ).forEach( function ( cat ) {
			var catEnableAll = cat.querySelector( '.karmcp-cat-enable-all' );
			var catDisableAll = cat.querySelector( '.karmcp-cat-disable-all' );

			if ( catEnableAll ) {
				catEnableAll.addEventListener( 'click', function () {
					cat.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( cb ) {
						if ( ! cb.disabled ) {
							cb.checked = true;
						}
					} );
					updateCards( form );
				} );
			}

			if ( catDisableAll ) {
				catDisableAll.addEventListener( 'click', function () {
					cat.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( cb ) {
						if ( ! cb.disabled ) {
							cb.checked = false;
						}
					} );
					updateCards( form );
				} );
			}

			// Collapse/expand the section, persisting state per category.
			var toggle = cat.querySelector( '.karmcp-category-toggle' );
			if ( toggle ) {
				var key = COLLAPSE_KEY + ( cat.getAttribute( 'data-category' ) || '' );
				var stored = null;
				try {
					stored = window.localStorage.getItem( key );
				} catch ( e ) {}
				if ( '1' === stored ) {
					cat.classList.add( 'is-collapsed' );
					toggle.setAttribute( 'aria-expanded', 'false' );
				}
				toggle.addEventListener( 'click', function () {
					var collapsed = cat.classList.toggle( 'is-collapsed' );
					toggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
					try {
						window.localStorage.setItem( key, collapsed ? '1' : '0' );
					} catch ( e ) {}
				} );
			}
		} );

		// Toggle card visual state on checkbox change.
		form.addEventListener( 'change', function ( e ) {
			if ( e.target.type === 'checkbox' ) {
				updateCards( form );
			}
		} );
	}

	/**
	 * Recompute the summary + per-category counts from the live checkbox state.
	 *
	 * @param {HTMLElement} form The tools form.
	 */
	function updateToolCounts( form ) {
		var enabled = 0;
		form.querySelectorAll( '.karmcp-tool-card input[type="checkbox"]' ).forEach( function ( cb ) {
			if ( cb.checked ) {
				enabled++;
			}
		} );
		var strong = form.querySelector( '.karmcp-tools-summary strong' );
		if ( strong ) {
			// Replace just the leading "enabled" number, keeping the localized
			// "N of M" wording intact.
			strong.textContent = strong.textContent.replace( /^\s*\d+/, enabled );
		}
		form.querySelectorAll( '.karmcp-category' ).forEach( function ( cat ) {
			var cbs = cat.querySelectorAll( 'input[type="checkbox"]' );
			var ce = 0;
			cbs.forEach( function ( cb ) {
				if ( cb.checked ) {
					ce++;
				}
			} );
			var el = cat.querySelector( '.karmcp-category-count' );
			if ( el ) {
				el.textContent = ce + ' / ' + cbs.length;
			}
		} );
		// Plugin-group counts (Plugins tab): sum across the group's plugin cards.
		form.querySelectorAll( '.karmcp-plugin-group' ).forEach( function ( grp ) {
			var cbs = grp.querySelectorAll( '.karmcp-tool-card input[type="checkbox"]' );
			var ge = 0;
			cbs.forEach( function ( cb ) {
				if ( cb.checked ) {
					ge++;
				}
			} );
			var el = grp.querySelector( '.karmcp-plugin-group-count' );
			if ( el ) {
				el.textContent = ge + ' / ' + cbs.length;
			}
		} );
	}

	/**
	 * Update card visual state based on checkbox.
	 *
	 * @param {HTMLElement} form The form element.
	 */
	function updateCards( form ) {
		form.querySelectorAll( '.karmcp-tool-card' ).forEach( function ( card ) {
			var cb = card.querySelector( 'input[type="checkbox"]' );
			card.classList.toggle( 'is-enabled', cb.checked );
			card.classList.toggle( 'is-disabled', ! cb.checked );
		} );
	}

	// Tools-page platform sub-tabs (Elementor / WordPress). Presentation only —
	// hidden panels keep their checkboxes in the form, so switching tabs never
	// affects what gets saved.
	( function initToolSubtabs() {
		var tabs = document.querySelectorAll( '.karmcp-subtab' );
		var panels = document.querySelectorAll( '.karmcp-tabpanel' );
		if ( ! tabs.length || ! panels.length ) {
			return;
		}
		// Per-page storage key so different sub-tab groups (Tools vs Connection)
		// don't overwrite each other's remembered tab. Falls back to the legacy
		// key when the tablist doesn't declare one.
		var tablist = document.querySelector( '.karmcp-subtabs' );
		var STORAGE_KEY = ( tablist && tablist.getAttribute( 'data-subtab-key' ) ) || 'karmcpToolsActiveTab';

		function activate( tabId ) {
			var matched = false;
			panels.forEach( function ( panel ) {
				var on = panel.getAttribute( 'data-tab' ) === tabId;
				panel.classList.toggle( 'is-active', on );
				if ( on ) { matched = true; }
			} );
			if ( ! matched ) {
				return; // unknown stored id (e.g. a removed tab) — leave server default.
			}
			tabs.forEach( function ( tab ) {
				var on = tab.getAttribute( 'data-tab' ) === tabId;
				tab.classList.toggle( 'is-active', on );
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			// Per-panel header actions (e.g. a Save button in the sub-tab row that
			// submits the active panel's form). Only the matching one is shown.
			document.querySelectorAll( '[data-save-for]' ).forEach( function ( el ) {
				el.hidden = el.getAttribute( 'data-save-for' ) !== tabId;
			} );
			try { window.localStorage.setItem( STORAGE_KEY, tabId ); } catch ( e ) {}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activate( tab.getAttribute( 'data-tab' ) );
			} );
		} );

		// Restore the last-used tab (falls back to the server-rendered active one).
		var stored = null;
		try { stored = window.localStorage.getItem( STORAGE_KEY ); } catch ( e ) {}
		if ( stored ) {
			activate( stored );
		}
	} )();

	/**
	 * Populate a code block and its hidden copy source.
	 *
	 * @param {string} codeId  The ID of the <code> element.
	 * @param {string} copyId  The ID of the <textarea> copy source.
	 * @param {string} json    The JSON string to display.
	 */
	function setConfigBlock( codeId, copyId, json ) {
		var codeEl = document.getElementById( codeId );
		var copyEl = document.getElementById( copyId );
		if ( codeEl ) {
			codeEl.textContent = json;
		}
		if ( copyEl ) {
			copyEl.value = json;
		}
	}

	/**
	 * Connection tab — Generate credentials and populate all HTTP config blocks.
	 */
	function initBase64Generator() {
		var generateBtn = document.getElementById( 'karmcp-generate-b64' );
		if ( ! generateBtn ) {
			return;
		}

		// Endpoint is available without generating credentials (OAuth mode needs it).
		if ( typeof karmcpToolsAdmin !== 'undefined' && karmcpToolsAdmin.mcpEndpoint && ! window.karmcpConn ) {
			window.karmcpConn = { endpoint: karmcpToolsAdmin.mcpEndpoint, siteUrl: karmcpToolsAdmin.siteUrl || '' };
		}

		// Authentication-method chooser: toggle the flow + re-render the selected
		// client for the chosen method (OAuth = sign-in, app-password = configs).
		var authRadios = document.querySelectorAll( 'input[name="karmcp_auth_method"]' );
		function karmcpApplyAuthMethod() {
			document.body.setAttribute( 'data-karmcp-auth', karmcpAuthMethod() );
			var sel = document.querySelector( '.karmcp-client-card.is-selected' );
			if ( sel ) { karmcpSelectClient( sel.getAttribute( 'data-client' ) ); }
		}
		for ( var ai = 0; ai < authRadios.length; ai++ ) {
			authRadios[ ai ].addEventListener( 'change', karmcpApplyAuthMethod );
		}
		document.body.setAttribute( 'data-karmcp-auth', karmcpAuthMethod() );

		// The client picker is always visible now; auto-select so steps show.
		var picker = document.getElementById( 'karmcp-client-picker' );
		if ( picker ) {
			picker.style.display = '';
			var savedClient = window.localStorage.getItem( 'karmcpConnClient' );
			var firstCard = document.querySelector( '.karmcp-client-card' );
			var pick = savedClient || ( firstCard ? firstCard.getAttribute( 'data-client' ) : '' );
			if ( pick ) { karmcpSelectClient( pick ); }
		}

		// The Basic Authorization header from the last generated credentials,
		// used by the auth self-test (#41).
		var karmcpAuthHeader = '';

		// Connection auth self-test (#41): proves whether the Authorization
		// header actually reaches WordPress. Servers like Plesk/Apache/IIS often
		// strip it, which is the usual cause of the MCP "initialize: Unauthorized"
		// error. credentials:'omit' ensures ONLY the Authorization header
		// authenticates (not the admin login cookie), so a 401 here is a true
		// Basic-auth failure, not a false pass.
		var authBtn = document.getElementById( 'karmcp-authtest-btn' );
		if ( authBtn ) {
			authBtn.addEventListener( 'click', function () {
				if ( ! karmcpAuthHeader || typeof karmcpToolsAdmin === 'undefined' || ! karmcpToolsAdmin.restMeUrl ) {
					return;
				}
				var statusEl = document.getElementById( 'karmcp-authtest-status' );
				var fixEl = document.getElementById( 'karmcp-authtest-fix' );
				if ( statusEl ) {
					statusEl.style.display = '';
					statusEl.className = 'description';
					statusEl.textContent = karmcpToolsAdmin.authTesting || 'Testing…';
				}
				if ( fixEl ) {
					fixEl.style.display = 'none';
				}
				authBtn.disabled = true;

				/* global fetch */
				fetch( karmcpToolsAdmin.restMeUrl + '?_=' + ( new Date() ).getTime(), {
					method: 'GET',
					credentials: 'omit',
					headers: { Authorization: karmcpAuthHeader }
				} ).then( function ( response ) {
					authBtn.disabled = false;
					if ( ! statusEl ) {
						return;
					}
					if ( response.ok ) {
						statusEl.className = 'description karmcp-authtest-ok';
						statusEl.textContent = karmcpToolsAdmin.authOk || 'Authentication works.';
						if ( fixEl ) {
							fixEl.style.display = 'none';
						}
					} else {
						statusEl.className = 'description karmcp-authtest-bad';
						statusEl.textContent = ( karmcpToolsAdmin.authFail || 'Authentication failed (HTTP %d).' ).replace( '%d', response.status );
						if ( fixEl ) {
							fixEl.style.display = '';
						}
					}
				} ).catch( function () {
					authBtn.disabled = false;
					if ( statusEl ) {
						statusEl.className = 'description karmcp-authtest-bad';
						statusEl.textContent = karmcpToolsAdmin.authError || 'Could not reach the REST API.';
					}
					if ( fixEl ) {
						fixEl.style.display = '';
					}
				} );
			} );
		}

		generateBtn.addEventListener( 'click', function () {
			var usernameEl = document.getElementById( 'karmcp-b64-username' );
			if ( ! usernameEl || ! usernameEl.value ) {
				/* global alert */
				alert( 'Please select an administrator account.' );
				return;
			}

			var selectedOption = usernameEl.options[ usernameEl.selectedIndex ];
			var selectedLogin = selectedOption ? ( selectedOption.getAttribute( 'data-login' ) || '' ) : '';
			var manualEl = document.getElementById( 'karmcp-b64-app-password' );
			var manualPassword = manualEl ? manualEl.value.trim() : '';

			// If an existing password is supplied, use it directly and skip creation.
			if ( manualPassword ) {
				renderConfigs( selectedLogin, manualPassword );
				return;
			}

			if ( typeof karmcpToolsAdmin === 'undefined' || ! karmcpToolsAdmin.ajaxUrl || ! karmcpToolsAdmin.createPwNonce ) {
				setCredStatus( 'Cannot create an application password automatically. Enter one manually below.', true );
				return;
			}

			var origLabel = generateBtn.textContent;
			generateBtn.disabled = true;
			generateBtn.textContent = karmcpToolsAdmin.generating || 'Generating…';
			setCredStatus( '', false );

			var payload = new FormData();
			payload.append( 'action', 'karmcp_tools_create_app_password' );
			payload.append( 'nonce', karmcpToolsAdmin.createPwNonce );
			payload.append( 'user_id', usernameEl.value );

			/* global fetch */
			fetch( karmcpToolsAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: payload
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( result ) {
				generateBtn.disabled = false;
				generateBtn.textContent = origLabel;

				if ( ! result || ! result.success || ! result.data || ! result.data.password ) {
					var message = ( result && result.data && result.data.message ) ? result.data.message : 'Could not create an application password.';
					setCredStatus( message, true );
					return;
				}

				setCredStatus( karmcpToolsAdmin.pwCreated || 'Application password created — save it below, it is shown only once.', false );
				renderGeneratedPassword( result.data.password );
				renderConfigs( result.data.username, result.data.password );
			} ).catch( function () {
				generateBtn.disabled = false;
				generateBtn.textContent = origLabel;
				setCredStatus( 'Network error while creating the application password.', true );
			} );
		} );

		/**
		 * Shows an inline status message under the credential form.
		 *
		 * @param {string}  message  Message text ('' hides it).
		 * @param {boolean} isError  Whether to style it as an error.
		 */
		function setCredStatus( message, isError ) {
			var statusEl = document.getElementById( 'karmcp-cred-status' );
			if ( ! statusEl ) {
				return;
			}
			statusEl.style.display = message ? '' : 'none';
			statusEl.textContent = message || '';
			statusEl.style.color = isError ? '#b32d2e' : '';
		}

		/**
		 * Reveals and fills the generated application password field.
		 *
		 * @param {string} password  The newly created application password.
		 */
		function renderGeneratedPassword( password ) {
			var row = document.getElementById( 'karmcp-generated-pw-row' );
			var code = document.getElementById( 'karmcp-generated-pw' );
			var copy = document.getElementById( 'karmcp-generated-pw-copy' );
			if ( row && code && copy ) {
				row.style.display = '';
				code.textContent = password;
				copy.value = password;
			}
		}

		/**
		 * Builds every client config block from a username + application password.
		 *
		 * @param {string} rawUsername     WordPress username.
		 * @param {string} rawAppPassword  Application password.
		 */
		function renderConfigs( rawUsername, rawAppPassword ) {
			var headerValue = 'Basic ' + btoa( rawUsername + ':' + rawAppPassword );

			// Show the result row.
			var resultRow = document.getElementById( 'karmcp-b64-result-row' );
			var resultCode = document.getElementById( 'karmcp-b64-result' );
			var resultCopy = document.getElementById( 'karmcp-b64-result-copy' );

			if ( resultRow && resultCode && resultCopy ) {
				resultRow.style.display = '';
				resultCode.textContent = headerValue;
				resultCopy.value = headerValue;
			}

			// Arm the auth self-test (#41) with these credentials.
			karmcpAuthHeader = headerValue;
			var authRow = document.getElementById( 'karmcp-authtest-row' );
			if ( authRow ) {
				authRow.style.display = '';
			}

			if ( typeof karmcpToolsAdmin === 'undefined' || ! karmcpToolsAdmin.mcpEndpoint ) {
				return;
			}

			// Stash for the client picker (Step 2/3).
			window.karmcpConn = {
				endpoint: karmcpToolsAdmin.mcpEndpoint,
				siteUrl: karmcpToolsAdmin.siteUrl || '',
				username: rawUsername,
				appPassword: rawAppPassword,
				userId: ( document.getElementById( 'karmcp-b64-username' ) || {} ).value || '',
				b64: btoa( rawUsername + ':' + rawAppPassword )
			};
			var picker = document.getElementById( 'karmcp-client-picker' );
			if ( picker ) { picker.style.display = ''; }
			// Re-select a remembered client if any.
			var saved = window.localStorage.getItem( 'karmcpConnClient' );
			if ( saved ) { karmcpSelectClient( saved ); }
		}
	}

	/**
	 * Copy text to clipboard with fallback for non-HTTPS contexts.
	 *
	 * @param {string} text The text to copy.
	 * @returns {Promise} Resolves when copied.
	 */
	function copyToClipboard( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}

		// Fallback for HTTP (non-secure) contexts.
		return new Promise( function ( resolve ) {
			var textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.select();
			document.execCommand( 'copy' );
			document.body.removeChild( textarea );
			resolve();
		} );
	}

	/**
	 * Copy-to-clipboard buttons (Connection tab + every prompt card).
	 *
	 * Single delegated listener on document — avoids attaching 50+ listeners on the
	 * Prompts page, which used to slow first paint and inflate memory.
	 */
	function initCopyButtons() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.karmcp-copy-btn' );
			if ( ! btn ) {
				return;
			}
			var targetId = btn.getAttribute( 'data-target' );
			var source = targetId ? document.getElementById( targetId ) : null;
			if ( ! source ) {
				return;
			}

			var copiedText = ( typeof karmcpToolsAdmin !== 'undefined' && karmcpToolsAdmin.copied ) ? karmcpToolsAdmin.copied : 'Copied!';

			copyToClipboard( source.value ).then( function () {
				var original = btn.textContent;
				btn.textContent = copiedText;
				setTimeout( function () {
					btn.textContent = original;
				}, 2000 );
			} );

			// Best-effort usage ping for premium (website-fetched) prompts only.
			trackProPromptCopy( btn.closest( '.karmcp-pro-prompt-card' ) );
		} );
	}

	/**
	 * Fire a fire-and-forget "prompt copied" event for a Pro prompt card.
	 * No-ops for free/bundled prompts (no card / no slug) or missing nonce.
	 */
	function trackProPromptCopy( card ) {
		if ( ! card || typeof karmcpToolsAdmin === 'undefined' || ! karmcpToolsAdmin.trackPromptNonce ) {
			return;
		}
		var slug = card.getAttribute( 'data-prompt-slug' );
		var category = card.getAttribute( 'data-category' );
		if ( ! slug || ! category ) {
			return;
		}
		var body = new FormData();
		body.append( 'action', 'karmcp_tools_track_prompt_copy' );
		body.append( 'nonce', karmcpToolsAdmin.trackPromptNonce );
		body.append( 'prompt_slug', slug );
		body.append( 'category_slug', category );
		fetch( karmcpToolsAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).catch( function () {} );
	}

	/**
	 * Reusable, filter-aware client-side pagination for a card grid.
	 *
	 * Owns BOTH the category filter pills and the pager, so the two stay in
	 * sync: changing the filter recomputes the matching set and resets to page
	 * one. Only the cards on the current page are shown; the rest are
	 * display:none, which keeps the DOM light and the page responsive even with
	 * 50+ cards. Safe to call on any page — it no-ops when the grid is absent.
	 *
	 * @param {Object} opts
	 * @param {string} opts.gridSelector   Selector for the grid container.
	 * @param {string} opts.cardSelector   Selector for cards within the grid.
	 * @param {string} [opts.filterSelector] Selector for the filter-pill bar.
	 * @param {number} [opts.pageSize]      Cards per page (default 12).
	 * @param {string} [opts.label]         Noun for the status line (e.g. 'prompts').
	 */
	function initGridPagination( opts ) {
		var grid = document.querySelector( opts.gridSelector );
		if ( ! grid ) {
			return;
		}
		var cards = Array.prototype.slice.call( grid.querySelectorAll( opts.cardSelector ) );
		if ( ! cards.length ) {
			return;
		}

		var pageSize = opts.pageSize || 12;
		var label = opts.label || 'items';
		var filterBar = opts.filterSelector ? document.querySelector( opts.filterSelector ) : null;
		var activeCategory = 'all';
		var currentPage = 1;

		// Pager container lives directly after the grid.
		var pager = document.createElement( 'nav' );
		pager.className = 'karmcp-pager';
		pager.setAttribute( 'aria-label', 'Pagination' );
		grid.parentNode.insertBefore( pager, grid.nextSibling );

		function matching() {
			return cards.filter( function ( card ) {
				return 'all' === activeCategory || card.getAttribute( 'data-category' ) === activeCategory;
			} );
		}

		function makeBtn( text, page, opt ) {
			opt = opt || {};
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'karmcp-pager-btn' + ( opt.current ? ' is-current' : '' );
			btn.textContent = text;
			if ( opt.disabled ) {
				btn.disabled = true;
			}
			if ( opt.current ) {
				btn.setAttribute( 'aria-current', 'page' );
			}
			if ( opt.ariaLabel ) {
				btn.setAttribute( 'aria-label', opt.ariaLabel );
			}
			if ( ! opt.disabled && ! opt.current ) {
				btn.addEventListener( 'click', function () {
					currentPage = page;
					render();
				} );
			}
			return btn;
		}

		// Windowed page list with ellipses: 1 … 4 5 [6] 7 8 … 20.
		function pageList( total ) {
			var pages = [];
			var add = function ( p ) { if ( pages.indexOf( p ) === -1 ) { pages.push( p ); } };
			add( 1 );
			add( total );
			for ( var p = currentPage - 1; p <= currentPage + 1; p++ ) {
				if ( p >= 1 && p <= total ) {
					add( p );
				}
			}
			pages.sort( function ( a, b ) { return a - b; } );
			var withGaps = [];
			for ( var i = 0; i < pages.length; i++ ) {
				if ( i > 0 && pages[ i ] - pages[ i - 1 ] > 1 ) {
					withGaps.push( '…' );
				}
				withGaps.push( pages[ i ] );
			}
			return withGaps;
		}

		function render() {
			var list = matching();
			var totalPages = Math.max( 1, Math.ceil( list.length / pageSize ) );
			if ( currentPage > totalPages ) {
				currentPage = totalPages;
			}
			var start = ( currentPage - 1 ) * pageSize;
			var end = start + pageSize;

			cards.forEach( function ( card ) { card.style.display = 'none'; } );
			list.slice( start, end ).forEach( function ( card ) { card.style.display = ''; } );

			pager.innerHTML = '';
			if ( totalPages <= 1 ) {
				return;
			}

			pager.appendChild( makeBtn( '‹', currentPage - 1, {
				disabled: currentPage === 1,
				ariaLabel: 'Previous page'
			} ) );

			pageList( totalPages ).forEach( function ( item ) {
				if ( '…' === item ) {
					var span = document.createElement( 'span' );
					span.className = 'karmcp-pager-ellipsis';
					span.textContent = '…';
					pager.appendChild( span );
				} else {
					pager.appendChild( makeBtn( String( item ), item, {
						current: item === currentPage,
						ariaLabel: 'Page ' + item
					} ) );
				}
			} );

			pager.appendChild( makeBtn( '›', currentPage + 1, {
				disabled: currentPage === totalPages,
				ariaLabel: 'Next page'
			} ) );

			var status = document.createElement( 'p' );
			status.className = 'karmcp-pager-status';
			status.textContent = 'Showing ' + ( start + 1 ) + '–' + Math.min( end, list.length ) +
				' of ' + list.length + ' ' + label;
			pager.appendChild( status );
		}

		// Own the category filter pills (active state + reset to page 1).
		if ( filterBar ) {
			filterBar.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.karmcp-pro-filter' );
				if ( ! btn ) {
					return;
				}
				activeCategory = btn.getAttribute( 'data-category' ) || 'all';
				filterBar.querySelectorAll( '.karmcp-pro-filter' ).forEach( function ( b ) {
					b.classList.toggle( 'is-active', b === btn );
				} );
				currentPage = 1;
				render();
			} );
		}

		render();
	}

	/**
	 * Brand Kits page — transient success toast with an optional "View site" link.
	 *
	 * @param {string} message The toast message.
	 * @param {string} viewUrl Optional URL to surface as a "View site →" link.
	 */
	function showBrandKitToast( message, viewUrl ) {
		var toast = document.createElement( 'div' );
		toast.className = 'karmcp-bk-toast';
		var span = document.createElement( 'span' );
		span.textContent = message;
		toast.appendChild( span );
		if ( viewUrl ) {
			var link = document.createElement( 'a' );
			link.href = viewUrl;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.textContent = ( typeof karmcpToolsAdmin !== 'undefined' && karmcpToolsAdmin.viewSite ) ? karmcpToolsAdmin.viewSite : 'View site →';
			toast.appendChild( link );
		}
		document.body.appendChild( toast );
		// Force reflow then animate in.
		window.requestAnimationFrame( function () {
			toast.classList.add( 'is-visible' );
		} );
		setTimeout( function () {
			toast.classList.remove( 'is-visible' );
			setTimeout( function () { toast.remove(); }, 400 );
		}, 7000 );
	}

	/**
	 * Brand Kits page — category filters, apply-with-confirmation modal, and
	 * restore-from-backup.
	 */
	function initBrandKits() {
		var root = document.querySelector( '.karmcp-brand-kits' );
		if ( ! root || typeof karmcpToolsAdmin === 'undefined' || ! karmcpToolsAdmin.ajaxUrl ) {
			return;
		}

		var grid = root.querySelector( '.karmcp-brand-kit-grid' );

		// Note: the category filter pills are handled by initGridPagination(),
		// which owns both filtering and pagination so they stay in sync.

		// Apply confirmation modal.
		var modal = root.querySelector( '.karmcp-brand-kit-modal' );
		var pending = null;

		function closeModal() {
			if ( modal ) {
				modal.hidden = true;
			}
			pending = null;
		}

		if ( grid && modal ) {
			grid.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.karmcp-brand-kit-apply' );
				if ( ! btn ) {
					return;
				}
				pending = {
					slug:  btn.getAttribute( 'data-kit-slug' ) || '',
					cat:   btn.getAttribute( 'data-category-slug' ) || '',
					title: btn.getAttribute( 'data-kit-title' ) || ''
				};
				var titleEl = modal.querySelector( '.karmcp-brand-kit-modal__title' );
				if ( titleEl ) {
					var tpl = ( karmcpToolsAdmin.applyKitTitle || 'Apply "%s" brand kit?' );
					titleEl.textContent = tpl.replace( '%s', pending.title );
				}
				var bk = modal.querySelector( '.karmcp-brand-kit-modal__backup-input' );
				if ( bk ) {
					bk.checked = true;
				}
				modal.hidden = false;
			} );

			modal.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '[data-modal-dismiss]' ) ) {
					closeModal();
					return;
				}
				var confirmBtn = e.target.closest( '.karmcp-brand-kit-modal__confirm' );
				if ( ! confirmBtn || ! pending ) {
					return;
				}

				var backup = modal.querySelector( '.karmcp-brand-kit-modal__backup-input' );
				var doBackup = backup ? backup.checked : true;
				var title = pending.title;
				var orig = confirmBtn.textContent;
				confirmBtn.disabled = true;
				confirmBtn.textContent = karmcpToolsAdmin.applying || 'Applying…';

				var body = new URLSearchParams();
				body.append( 'action', 'karmcp_tools_apply_pro_brand_kit' );
				body.append( 'nonce', grid.getAttribute( 'data-apply-nonce' ) || '' );
				body.append( 'kit_slug', pending.slug );
				body.append( 'category_slug', pending.cat );
				body.append( 'backup', doBackup ? '1' : '0' );

				fetch( karmcpToolsAdmin.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						confirmBtn.disabled = false;
						confirmBtn.textContent = orig;
						if ( res && res.success ) {
							closeModal();
							var applied = ( karmcpToolsAdmin.kitApplied || '%s applied.' ).replace( '%s', title );
							showBrandKitToast( applied, res.data && res.data.view_url );
						} else {
							var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Apply failed.';
							window.alert( msg );
						}
					} )
					.catch( function () {
						confirmBtn.disabled = false;
						confirmBtn.textContent = orig;
						window.alert( 'Apply failed. Check your connection and try again.' );
					} );
			} );
		}

		// Restore from backup.
		var restore = root.querySelector( '.karmcp-brand-kit-restore' );
		if ( restore ) {
			var restoreBtn = restore.querySelector( '.karmcp-brand-kit-restore-btn' );
			if ( restoreBtn ) {
				restoreBtn.addEventListener( 'click', function () {
					var select = restore.querySelector( '.karmcp-brand-kit-backup-select' );
					var clobber = restore.querySelector( '.karmcp-brand-kit-clobber-input' );
					if ( ! select || ! select.value ) {
						return;
					}
					if ( ! window.confirm( karmcpToolsAdmin.restoreConfirm || 'Restore global colors and typography from this backup?' ) ) {
						return;
					}
					var orig = restoreBtn.textContent;
					restoreBtn.disabled = true;
					restoreBtn.textContent = karmcpToolsAdmin.restoring || 'Restoring…';

					var body = new URLSearchParams();
					body.append( 'action', 'karmcp_tools_restore_pro_brand_kit' );
					body.append( 'nonce', restore.getAttribute( 'data-restore-nonce' ) || '' );
					body.append( 'backup_id', select.value );
					body.append( 'full_clobber', ( clobber && clobber.checked ) ? '1' : '0' );

					fetch( karmcpToolsAdmin.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString()
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							restoreBtn.disabled = false;
							restoreBtn.textContent = orig;
							if ( res && res.success ) {
								var msg = ( res.data && res.data.message ) ? res.data.message : 'Restored.';
								showBrandKitToast( msg, res.data && res.data.view_url );
							} else {
								var emsg = ( res && res.data && res.data.message ) ? res.data.message : 'Restore failed.';
								window.alert( emsg );
							}
						} )
						.catch( function () {
							restoreBtn.disabled = false;
							restoreBtn.textContent = orig;
							window.alert( 'Restore failed. Check your connection and try again.' );
						} );
				} );
			}
		}
	}

	/**
	 * Wire pagination (and the category filter it owns) into each library grid.
	 * Each call no-ops when its grid isn't on the current page, so it's safe to
	 * run all three regardless of which tab rendered.
	 */
	function initPagers() {
		initGridPagination( {
			gridSelector: '.karmcp-brand-kit-grid',
			cardSelector: '.karmcp-brand-kit-card',
			filterSelector: '.karmcp-brand-kits .karmcp-pro-filters',
			pageSize: 12,
			label: 'brand kits'
		} );
		initGridPagination( {
			gridSelector: '.karmcp-changelog-list',
			cardSelector: '.karmcp-changelog-version',
			pageSize: 10,
			label: 'releases'
		} );
	}

	/**
	 * Slide-in code viewer overlay. Any element with [data-karmcp-code-view] opens
	 * it; the code is read from the nearest .karmcp-code-src in the same table cell
	 * (or a selector in the attribute's value). Provides Copy + Download.
	 */
	function initCodeOverlay() {
		if ( document.getElementById( 'karmcp-code-overlay' ) ) {
			return;
		}
		var overlay = document.createElement( 'div' );
		overlay.id = 'karmcp-code-overlay';
		overlay.className = 'karmcp-code-overlay';
		overlay.innerHTML =
			'<div class="karmcp-code-overlay__backdrop" data-karmcp-close></div>' +
			'<div class="karmcp-code-overlay__panel" role="dialog" aria-modal="true" aria-label="Code viewer">' +
				'<div class="karmcp-code-overlay__header">' +
					'<span class="karmcp-code-overlay__title"></span>' +
					'<button type="button" class="karmcp-code-overlay__close" data-karmcp-close aria-label="Close">&times;</button>' +
				'</div>' +
				'<div class="karmcp-code-overlay__toolbar">' +
					'<button type="button" class="karmcp-code-overlay__btn" data-karmcp-copy></button>' +
					'<button type="button" class="karmcp-code-overlay__btn" data-karmcp-download></button>' +
				'</div>' +
				'<pre class="karmcp-code-overlay__body"><code></code></pre>' +
			'</div>';
		document.body.appendChild( overlay );

		var titleEl = overlay.querySelector( '.karmcp-code-overlay__title' );
		var codeEl  = overlay.querySelector( '.karmcp-code-overlay__body code' );
		var copyBtn = overlay.querySelector( '[data-karmcp-copy]' );
		var dlBtn   = overlay.querySelector( '[data-karmcp-download]' );
		copyBtn.textContent = window.karmcpToolsAdmin && window.karmcpToolsAdmin.copy ? window.karmcpToolsAdmin.copy : 'Copy';
		dlBtn.textContent   = window.karmcpToolsAdmin && window.karmcpToolsAdmin.download ? window.karmcpToolsAdmin.download : 'Download';
		var filename = 'code.txt';

		function open( title, code, fname ) {
			titleEl.textContent = title || 'Code';
			codeEl.textContent = code || '';
			filename = fname || 'code.txt';
			copyBtn.textContent = window.karmcpToolsAdmin && window.karmcpToolsAdmin.copy ? window.karmcpToolsAdmin.copy : 'Copy';
			overlay.classList.add( 'is-open' );
			document.body.style.overflow = 'hidden';
		}
		function close() {
			overlay.classList.remove( 'is-open' );
			document.body.style.overflow = '';
		}

		// Open from any trigger.
		document.addEventListener( 'click', function ( e ) {
			var trigger = e.target.closest( '[data-karmcp-code-view]' );
			if ( ! trigger ) { return; }
			e.preventDefault();
			var sel = trigger.getAttribute( 'data-karmcp-code-view' );
			var src = null;
			if ( sel ) { src = document.querySelector( sel ); }
			if ( ! src ) {
				var scope = trigger.closest( 'td' ) || trigger.parentNode;
				src = scope ? scope.querySelector( '.karmcp-code-src' ) : null;
			}
			open(
				trigger.getAttribute( 'data-karmcp-code-title' ) || 'Code',
				src ? src.textContent : '',
				trigger.getAttribute( 'data-karmcp-code-filename' ) || 'code.txt'
			);
		} );

		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-karmcp-close]' ) ) { close(); }
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && overlay.classList.contains( 'is-open' ) ) { close(); }
		} );

		copyBtn.addEventListener( 'click', function () {
			var text = codeEl.textContent || '';
			var done = function () {
				copyBtn.textContent = window.karmcpToolsAdmin && window.karmcpToolsAdmin.copied ? window.karmcpToolsAdmin.copied : 'Copied!';
				setTimeout( function () { copyBtn.textContent = window.karmcpToolsAdmin && window.karmcpToolsAdmin.copy ? window.karmcpToolsAdmin.copy : 'Copy'; }, 1500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done ).catch( function () { fallbackCopy( text, codeEl ); done(); } );
			} else {
				fallbackCopy( text, codeEl );
				done();
			}
		} );

		dlBtn.addEventListener( 'click', function () {
			var blob = new Blob( [ codeEl.textContent || '' ], { type: 'text/plain' } );
			var url  = URL.createObjectURL( blob );
			var a    = document.createElement( 'a' );
			a.href = url;
			a.download = filename;
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );
		} );
	}

	/**
	 * Clipboard fallback (older browsers / insecure context): select the code
	 * node and execCommand('copy').
	 *
	 * @param {string}      text Text to copy.
	 * @param {HTMLElement} node Element whose text can be range-selected.
	 */
	function fallbackCopy( text, node ) {
		try {
			var range = document.createRange();
			range.selectNodeContents( node );
			var sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
			document.execCommand( 'copy' );
			sel.removeAllRanges();
		} catch ( e ) {}
	}

	/**
	 * Copy text to the clipboard (Clipboard API with an execCommand fallback for
	 * older browsers / insecure contexts). Returns a Promise that always resolves.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise}
	 */
	function karmcpCopyText( text ) {
		return new Promise( function ( resolve ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( resolve ).catch( function () {
					karmcpExecCopy( text );
					resolve();
				} );
			} else {
				karmcpExecCopy( text );
				resolve();
			}
		} );
	}

	function karmcpExecCopy( text ) {
		try {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
		} catch ( e ) {}
	}

	/**
	 * Click-to-copy for any [data-karmcp-copy-text] element (e.g. a shortcode
	 * chip). Copies the attribute value (or the element text) and flashes a
	 * "Copied!" tooltip via the .is-copied class.
	 */
	function initClickToCopy() {
		document.addEventListener( 'click', function ( e ) {
			var el = e.target.closest( '[data-karmcp-copy-text]' );
			if ( ! el ) { return; }
			var text = el.getAttribute( 'data-karmcp-copy-text' ) || el.textContent || '';
			karmcpCopyText( text ).then( function () {
				el.classList.add( 'is-copied' );
				clearTimeout( el._karmcpCopiedTimer );
				el._karmcpCopiedTimer = setTimeout( function () { el.classList.remove( 'is-copied' ); }, 1200 );
			} );
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( ( 'Enter' === e.key || ' ' === e.key ) && e.target && e.target.matches && e.target.matches( '[data-karmcp-copy-text]' ) ) {
				e.preventDefault();
				e.target.click();
			}
		} );
	}

	// -------------------------------------------------------------------------
	// Connection tab — client picker helpers
	// -------------------------------------------------------------------------

	function karmcpEscapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function karmcpClientById( id ) {
		var list = ( karmcpToolsAdmin.connectionClients || [] );
		for ( var i = 0; i < list.length; i++ ) { if ( list[ i ].id === id ) { return list[ i ]; } }
		return null;
	}

	// Per-site MCP server name derived from the domain, so multiple connected sites
	// don't collide in a client's config (e.g. "karmcp-sociable-sylvain-z29-zipwp-dev").
	function karmcpServerName() {
		var host = '';
		try { host = new URL( window.karmcpConn.siteUrl || window.karmcpConn.endpoint ).hostname; } catch ( e ) { host = ''; }
		host = ( host || '' ).replace( /^www\./, '' ).replace( /[^a-zA-Z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).toLowerCase();
		return host ? ( 'karmcp-' + host ) : 'karmcp-tools';
	}

	// Build the JSON config object for a given client + json-variant key.
	//
	// There is no variant for a KarMCP-published npm proxy: we publish nothing to
	// npm, so a config naming one could only 404 on the user. Clients that want
	// stdio use `mcp-remote` (the 'remote' variant), which is a real published
	// package, or the .mcpb bundle.
	function karmcpJsonConfig( variant ) {
		var c = window.karmcpConn;
		var key = karmcpServerName();
		var http = { type: 'http', url: c.endpoint, headers: { Authorization: 'Basic ' + c.b64 } };
		var servers = {};
		if ( variant === 'http' ) { servers[ key ] = http; return { mcpServers: servers }; }
		if ( variant === 'remote' ) {
			servers[ key ] = { command: 'npx', args: [ '-y', 'mcp-remote', c.endpoint, '--header', 'Authorization: Basic ' + c.b64 ] };
			return { mcpServers: servers };
		}
		return { mcpServers: servers };
	}

	// OpenClaw ~/.openclaw/openclaw.json — the server lives under mcp.servers (NOT
	// the top-level mcpServers other clients use). openclaw.json almost always
	// already has other top-level keys, so we emit the "mcp" PROPERTY to merge in
	// rather than a full { … } object that would clobber the file.
	function karmcpOpenclawConfig() {
		var c = window.karmcpConn, n = karmcpServerName(), server;
		server = { url: c.endpoint, transport: 'streamable-http', headers: { Authorization: 'Basic ' + c.b64 } };
		var inner = { servers: {} };
		inner.servers[ n ] = server;
		return '"mcp": ' + JSON.stringify( inner, null, 4 );
	}

	// Hermes ~/.hermes/config.yaml — mcp_servers (YAML). Hand-rendered so the output
	// matches Hermes' documented shape exactly.
	function karmcpHermesConfig() {
		var c = window.karmcpConn, n = karmcpServerName();
		return 'mcp_servers:\n' +
			'  ' + n + ':\n' +
			'    url: "' + c.endpoint + '"\n' +
			'    headers:\n' +
			'      Authorization: "Basic ' + c.b64 + '"';
	}

	// Codex config.toml — streamable HTTP (expects `http_headers`, an inline table).
	function karmcpTomlConfig() {
		var c = window.karmcpConn, n = karmcpServerName();
		return '[mcp_servers.' + n + ']\n' +
			'url = "' + c.endpoint + '"\n' +
			'http_headers = { "Authorization" = "Basic ' + c.b64 + '" }';
	}

	// Render one copy/download block.
	function karmcpBlock( title, bodyHtml ) {
		return '<div class="karmcp-config-card"><div class="karmcp-config-card-header">' +
			'<span class="karmcp-config-card-title">' + karmcpEscapeHtml( title ) + '</span></div>' +
			'<div class="karmcp-config-card-body">' + bodyHtml + '</div></div>';
	}

	function karmcpCopyBlock( title, text ) {
		var id = 'karmcp-opt-' + Math.abs( ( title + text ).length );
		return '<div class="karmcp-config-card"><div class="karmcp-config-card-header">' +
			'<span class="karmcp-config-card-title">' + karmcpEscapeHtml( title ) + '</span>' +
			'<button type="button" class="button karmcp-copy-btn" data-target="' + id + '">Copy</button></div>' +
			'<pre><code>' + karmcpEscapeHtml( text ) + '</code></pre>' +
			'<textarea id="' + id + '" class="karmcp-copy-source">' + karmcpEscapeHtml( text ) + '</textarea></div>';
	}

	// The selected authentication method (falls back to whatever is available).
	function karmcpAuthMethod() {
		var r = document.querySelector( 'input[name="karmcp_auth_method"]:checked' );
		if ( r ) { return r.value; }
		return ( typeof karmcpToolsAdmin !== 'undefined' && karmcpToolsAdmin.oauthEnabled ) ? 'oauth' : 'app-password';
	}

	// --- OAuth setup rendering (no credentials — browser sign-in supplies auth) ---
	function karmcpFill( tpl, name, endpoint ) {
		return String( tpl ).replace( /%NAME%/g, name ).replace( /%ENDPOINT%/g, endpoint );
	}
	function karmcpStep( title, descHtml ) {
		return '<p class="karmcp-oauth-step"><strong>' + karmcpEscapeHtml( title ) + '</strong></p>' +
			( descHtml ? '<p class="description karmcp-oauth-desc">' + descHtml + '</p>' : '' );
	}
	function karmcpSigninText() {
		return ( typeof karmcpToolsAdmin !== 'undefined' && karmcpToolsAdmin.oauthSignin ) ||
			'The next time your AI client connects, your browser opens so you can authorize it. Approve to finish connecting.';
	}
	function karmcpOAuthDeeplink( kind, name, endpoint, label ) {
		var url = '';
		if ( kind === 'cursor' ) {
			url = 'cursor://anysphere.cursor-deeplink/mcp/install?name=' + encodeURIComponent( name ) +
				'&config=' + btoa( JSON.stringify( { url: endpoint } ) );
		} else if ( kind === 'claude-ai' ) {
			url = 'https://claude.ai/customize/connectors?modal=add-custom-connector&connectorName=' +
				encodeURIComponent( name ) + '&connectorUrl=' + encodeURIComponent( endpoint );
		}
		if ( ! url ) { return ''; }
		return '<p><a class="button button-primary karmcp-oauth-deeplink" href="' + karmcpEscapeHtml( url ) + '">' +
			karmcpEscapeHtml( label ) + '</a></p>';
	}

	function karmcpRenderOAuth( client ) {
		var endpoint = ( window.karmcpConn && window.karmcpConn.endpoint ) || karmcpToolsAdmin.mcpEndpoint;
		var name = karmcpServerName();
		var o = client.oauth;
		var out = '';

		if ( ! o || typeof o !== 'object' ) {
			out += karmcpCopyBlock( 'a. Add this site as an HTTP MCP server (paste the URL)', endpoint );
			return out + karmcpStep( 'b. Sign in', karmcpEscapeHtml( karmcpSigninText() ) );
		}

		if ( o.type === 'cmd' ) {
			out += karmcpStep( 'a. Run this in your terminal' );
			out += karmcpCopyBlock( '', karmcpFill( o.cmd, name, endpoint ) );
			return out + karmcpStep( 'b. Sign in', karmcpEscapeHtml( karmcpSigninText() ) );
		}

		if ( o.type === 'connector' ) {
			if ( o.deeplink ) { out += karmcpOAuthDeeplink( o.deeplink, name, endpoint, 'Add the connector to ' + ( o.app || 'your client' ) ); }
			if ( o.note ) { out += '<p class="description">' + karmcpEscapeHtml( o.note ) + '</p>'; }
			out += karmcpStep( 'a. Open Connectors', karmcpEscapeHtml( 'In ' + ( o.app || 'your client' ) + ', open Settings and go to Connectors.' ) );
			out += karmcpStep( 'b. Add a custom connector — give it this name' );
			out += karmcpCopyBlock( '', name );
			out += karmcpStep( 'c. Enter the server URL', karmcpEscapeHtml( 'Paste the URL below and save. Leave the OAuth Client ID and Secret (under Advanced settings) empty, then sign in when the browser opens.' ) );
			out += karmcpCopyBlock( '', endpoint );
			return out;
		}

		if ( o.type === 'steps' ) {
			( o.steps || [] ).forEach( function ( s ) {
				if ( s.copy ) {
					out += karmcpCopyBlock( s.title || '', karmcpFill( s.copy, name, endpoint ) );
				} else {
					out += karmcpStep( s.title || '', s.desc ? karmcpEscapeHtml( karmcpFill( s.desc, name, endpoint ) ) : '' );
				}
			} );
			return out;
		}

		if ( o.type === 'config' ) {
			if ( o.deeplink ) { out += karmcpOAuthDeeplink( o.deeplink, name, endpoint, 'One-click install' ); }
			var paths = '';
			( o.paths || [] ).forEach( function ( p ) {
				paths += '<code>' + karmcpEscapeHtml( p.path ) + '</code> <span class="karmcp-oauth-path-label">' + karmcpEscapeHtml( p.label || '' ) + '</span><br />';
			} );
			out += karmcpStep( 'a. Open your config', paths );
			out += karmcpStep( 'b. Add this server', karmcpEscapeHtml( o.merge_msg || 'If your config file already has content, merge this into it instead of replacing it.' ) );
			out += karmcpCopyBlock( '', karmcpFill( o.template, name, endpoint ) );
			if ( o.note ) { out += '<p class="description">' + karmcpEscapeHtml( karmcpFill( o.note, name, endpoint ) ) + '</p>'; }
			return out + karmcpStep( 'c. Restart and sign in', karmcpEscapeHtml( karmcpSigninText() ) );
		}

		out += karmcpCopyBlock( 'a. Connector URL', endpoint );
		return out + karmcpStep( 'b. Sign in', karmcpEscapeHtml( karmcpSigninText() ) );
	}

	function karmcpSelectClient( id ) {
		var client = karmcpClientById( id );
		if ( ! client ) { return; }
		if ( ! window.karmcpConn ) { window.karmcpConn = { endpoint: karmcpToolsAdmin.mcpEndpoint }; }
		window.localStorage.setItem( 'karmcpConnClient', id );

		// toggle card selected state
		var cards = document.querySelectorAll( '.karmcp-client-card' );
		for ( var i = 0; i < cards.length; i++ ) {
			var on = cards[ i ].getAttribute( 'data-client' ) === id;
			cards[ i ].classList.toggle( 'is-selected', on );
			cards[ i ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
		}

		var heading = document.getElementById( 'karmcp-connect-heading' );
		var nameEl = document.getElementById( 'karmcp-connect-client-name' );
		if ( heading ) { heading.style.display = ''; }
		if ( nameEl ) { nameEl.textContent = client.label; }

		var html = '';
		var m = client.methods;
		var method = karmcpAuthMethod();

		if ( method === 'oauth' ) {
			// OAuth mode: no credentials — connect command + browser sign-in.
			html = karmcpRenderOAuth( client );
		} else if ( ! window.karmcpConn.b64 ) {
			// App-password mode needs generated credentials first.
			html = '<p class="description">' +
				karmcpEscapeHtml( ( karmcpToolsAdmin.genFirst || 'Generate your credentials above — the config for %s then appears here.' ).replace( '%s', client.label ) ) +
				'</p>';
		} else {

		// 0) Client-specific setup guide (e.g. Codex's custom-MCP form mapping).
		//    %ENDPOINT%/%B64% are filled with the live, escaped values.
		if ( client.guide ) {
			var guide = client.guide
				.replace( /%NAME%/g, karmcpEscapeHtml( karmcpServerName() ) )
				.replace( /%ENDPOINT%/g, karmcpEscapeHtml( window.karmcpConn.endpoint ) )
				.replace( /%B64%/g, karmcpEscapeHtml( window.karmcpConn.b64 ) );
			html += karmcpBlock( client.guide_title || 'Setup guide', guide );
		}

		// 1) Bundle (.mcpb)
		if ( m.bundle ) {
			html += karmcpBlock( 'One-click bundle (.mcpb)',
				'<p class="description">Download and double-click to install in Claude Desktop — no config files to edit.</p>' +
				'<p class="karmcp-mcpb-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' +
				'<strong>Treat this file as a secret.</strong> It embeds your WordPress application password in plaintext, so anyone with the file can access this site. ' +
				'Don\'t email it, share it, commit it to git, or leave it in a cloud-synced folder — and delete it once it\'s imported into Claude Desktop.</p>' +
				'<p><button type="button" class="button button-primary" id="karmcp-mcpb-download">Download .mcpb bundle</button></p>' );
		}
		// 2) CLI command
		if ( m.cli ) {
			var cli = m.cli.replace( /%NAME%/g, karmcpServerName() ).replace( /%ENDPOINT%/g, window.karmcpConn.endpoint ).replace( /%B64%/g, window.karmcpConn.b64 );
			html += karmcpCopyBlock( 'Terminal command', cli );
		}
		// 3) AI setup prompt
		if ( m.ai_prompt ) {
			var prompt = 'Add an MCP server named "' + karmcpServerName() + '" at ' + window.karmcpConn.endpoint +
				' using the HTTP transport with header  Authorization: Basic ' + window.karmcpConn.b64;
			html += karmcpCopyBlock( 'Ask your AI to set it up (paste into chat)', prompt );
		}
		// 4) Manual JSON / TOML
		( m.json || [] ).forEach( function ( variant ) {
			if ( variant === 'toml' ) { html += karmcpCopyBlock( 'Manual config — direct HTTP (config.toml)', karmcpTomlConfig() ); }
			else if ( variant === 'openclaw-http' ) { html += karmcpCopyBlock( 'Manual config — direct HTTP (openclaw.json)', karmcpOpenclawConfig() ); }
			else if ( variant === 'hermes-http' ) { html += karmcpCopyBlock( 'Manual config — direct HTTP (config.yaml)', karmcpHermesConfig() ); }
			else {
				var label = variant === 'http' ? 'Manual config — direct HTTP'
					: 'Manual config — npx mcp-remote';
				html += karmcpCopyBlock( label, JSON.stringify( karmcpJsonConfig( variant ), null, 4 ) );
			}
		} );
		} // /else (app-password mode with generated credentials)

		var host = document.getElementById( 'karmcp-client-options' );
		if ( host ) { host.innerHTML = html; }

		// Wire the .mcpb download button (if present) to submit the hidden form.
		var dl = document.getElementById( 'karmcp-mcpb-download' );
		if ( dl ) {
			dl.addEventListener( 'click', function () {
				document.getElementById( 'karmcp-mcpb-user-id' ).value = window.karmcpConn.userId;
				document.getElementById( 'karmcp-mcpb-app-password' ).value = window.karmcpConn.appPassword;
				document.getElementById( 'karmcp-mcpb-form' ).submit();
			} );
		}
	}

	// Delegate card clicks.
	document.addEventListener( 'click', function ( e ) {
		var card = e.target.closest ? e.target.closest( '.karmcp-client-card' ) : null;
		if ( card ) { karmcpSelectClient( card.getAttribute( 'data-client' ) ); }
	} );

	// Context page: char/token counter, starter template, live preview.
	function initContextPage() {
		var ta = document.getElementById( 'karmcp-context-text' );
		if ( ! ta ) { return; }
		var counter = document.getElementById( 'karmcp-context-counter' );
		var preview = document.getElementById( 'karmcp-context-preview' );
		var toggle  = document.querySelector( 'input[name="karmcp_tools_site_context_enabled"]' );
		var tplBtn  = document.getElementById( 'karmcp-context-template' );
		var max     = parseInt( ta.getAttribute( 'maxlength' ) || '20000', 10 );
		var base    = ( karmcpToolsAdmin && karmcpToolsAdmin.siteContextBase ) || '';
		var delim   = ( karmcpToolsAdmin && karmcpToolsAdmin.siteContextDelimiter ) || '\n\n## Site context\n\n';

		function refresh() {
			var len = ta.value.length;
			var tokens = Math.ceil( len / 4 );
			if ( counter ) {
				counter.textContent = len + ' characters · ~' + tokens + ' tokens';
				counter.classList.toggle( 'is-warn', len > max * 0.9 );
			}
			if ( preview ) {
				var ctx = ta.value.replace( /^\s+|\s+$/g, '' );
				var on = ! toggle || toggle.checked;
				preview.textContent = ( on && ctx ) ? ( base + delim + ctx ) : base;
			}
		}

		ta.addEventListener( 'input', refresh );
		if ( toggle ) { toggle.addEventListener( 'change', refresh ); }
		if ( tplBtn ) {
			tplBtn.addEventListener( 'click', function () {
				if ( ta.value.trim() && ! window.confirm( 'Replace the current context with the starter template?' ) ) { return; }
				ta.value = karmcpContextTemplate();
				refresh();
				ta.focus();
			} );
		}
		refresh();
	}

	function karmcpContextTemplate() {
		return [
			'# About this site',
			'',
			'## Business identity',
			'- Name:',
			'- What we do:',
			'- Primary audience:',
			'',
			'## Brand voice & tone',
			'- ',
			'',
			'## Content & SEO rules',
			'- ',
			'',
			'## Technical / Elementor constraints',
			'- ',
			'',
			'## Guardrails (what NOT to do)',
			'- '
		].join( '\n' );
	}

	/**
	 * App-bar notifications bell: click-toggle dropdown (the neighboring Help
	 * menu is a pure-CSS hover/focus-within dropdown with no JS counterpart —
	 * the notif bell needs real JS so it can mark items read on open). Closes
	 * on outside click / Escape, and marks the currently-visible unread items
	 * read via admin-ajax the first time it's opened in a page view.
	 */
	function initNotifications() {
		var wrap = document.querySelector( '.karmcp-notif' );
		if ( ! wrap ) { return; }
		var toggle = wrap.querySelector( '.karmcp-notif-toggle' );
		var badge = wrap.querySelector( '.karmcp-notif-badge' );
		var overlay = wrap.querySelector( '.karmcp-notif-overlay' );
		var closeBtn = wrap.querySelector( '.karmcp-notif-close' );
		if ( ! toggle ) { return; }

		var markedThisView = false;

		function close() {
			wrap.classList.remove( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'false' );
		}

		function markVisibleRead() {
			if ( markedThisView ) { return; }

			var items = wrap.querySelectorAll( '.karmcp-notif-item.is-unread[data-id]' );
			if ( ! items.length ) { return; }

			markedThisView = true;

			var ids = [];
			items.forEach( function ( item ) {
				ids.push( item.getAttribute( 'data-id' ) );
				item.classList.remove( 'is-unread' );
			} );

			if ( typeof karmcpToolsAdmin === 'undefined' || ! karmcpToolsAdmin.ajaxUrl ) { return; }

			var payload = new FormData();
			payload.append( 'action', 'karmcp_tools_notifications_read' );
			payload.append( 'nonce', toggle.getAttribute( 'data-nonce' ) || '' );
			ids.forEach( function ( id ) {
				payload.append( 'ids[]', id );
			} );

			/* global fetch */
			fetch( karmcpToolsAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: payload
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( result ) {
				if ( result && result.success && badge ) {
					var unread = result.data && typeof result.data.unread !== 'undefined' ? result.data.unread : 0;
					badge.textContent = String( unread );
					badge.classList.toggle( 'is-empty', 0 === unread );
				}
			} ).catch( function () {} );
		}

		function open() {
			// Only one of help / notif open at a time.
			var help = document.querySelector( '.karmcp-help-menu' );
			if ( help && document.activeElement && help.contains( document.activeElement ) ) {
				document.activeElement.blur();
			}
			wrap.classList.add( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'true' );
			markVisibleRead();
		}

		toggle.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			if ( wrap.classList.contains( 'is-open' ) ) {
				close();
			} else {
				open();
			}
		} );

		// Drawer: clicking the overlay or the close button dismisses it.
		if ( overlay ) { overlay.addEventListener( 'click', close ); }
		if ( closeBtn ) { closeBtn.addEventListener( 'click', close ); }

		document.addEventListener( 'click', function ( e ) {
			if ( wrap.classList.contains( 'is-open' ) && ! wrap.contains( e.target ) ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && wrap.classList.contains( 'is-open' ) ) {
				close();
				toggle.focus();
			}
		} );
	}

	// Initialize on DOM ready.
	/**
	 * Collapse control for the section rail.
	 *
	 * The class is toggled first and the state saved after, deliberately: the
	 * rail must answer the click immediately, and a preference that fails to
	 * persist is worth far less than a rail that stutters on every use. A failed
	 * save just means the next page load starts from the stored state.
	 *
	 * PHP renders the initial state onto .karmcp-shell, so nothing here runs on
	 * load and there is no expanded-then-collapsed flash.
	 */
	function initNavCollapse() {
		var shell = document.querySelector( '.karmcp-shell' );
		if ( ! shell ) { return; }
		var toggle = shell.querySelector( '.karmcp-appnav-toggle' );
		if ( ! toggle ) { return; }

		toggle.addEventListener( 'click', function () {
			var collapsed = shell.classList.toggle( 'is-collapsed' );
			toggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );

			if ( ! window.ajaxurl ) { return; }
			var body = new URLSearchParams();
			body.append( 'action', 'karmcp_nav_state' );
			body.append( '_ajax_nonce', toggle.getAttribute( 'data-nonce' ) || '' );
			body.append( 'collapsed', collapsed ? '1' : '0' );
			window.fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).catch( function () { /* preference only — the rail already moved */ } );
		} );
	}

	function initAll() {
		initToolsForm();
		initBase64Generator();
		initCopyButtons();
		initPagers();
		initBrandKits();
		initCodeOverlay();
		initClickToCopy();
		initContextPage();
		initNavCollapse();
		initNotifications();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
})();
