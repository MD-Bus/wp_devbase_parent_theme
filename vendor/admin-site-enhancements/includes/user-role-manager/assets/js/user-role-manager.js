/**
 * User Role Manager (ASE).
 *
 * Renders the Users > User Role Manager page: a role list, and an editor for the
 * selected role. Free includes Essentials and By area. Pro adds All capabilities
 * and Add capability. The editor also has batch selection tools, a pending-changes
 * bar with a review dialog, and dialogs for role management.
 *
 * All strings come from window.asenhaUserRoleManager.i18n. Data is loaded through
 * the asenha-role-manager/v1 REST API using wp.apiFetch (which adds the nonce).
 *
 * The helpers are exported as window.asenhaUserRoleManagerKit so that the Pro
 * script can reuse them.
 */
( function () {
	'use strict';

	var cfg = window.asenhaUserRoleManager;

	if ( ! cfg ) {
		return;
	}

	var API_BASE = '/asenha-role-manager/v1';

	/* ---------------------------------------------------------------------
	 * Generic helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Get a translated string.
	 *
	 * @param {string} key i18n key.
	 * @return {string}
	 */
	function i( key ) {
		return cfg.i18n && Object.prototype.hasOwnProperty.call( cfg.i18n, key ) ? cfg.i18n[ key ] : key;
	}

	/**
	 * Get a translated string with %s / %d / %1$s style placeholders replaced.
	 *
	 * @param {string} key i18n key.
	 * @return {string}
	 */
	function f( key ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var seq = 0;

		return i( key ).replace( /%(?:(\d+)\$)?[sd]/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : seq++;
			return typeof args[ index ] === 'undefined' ? '' : String( args[ index ] );
		} );
	}

	var idCounter = 0;

	function uid( prefix ) {
		idCounter += 1;
		return ( prefix || 'urm' ) + '-' + idCounter;
	}

	/**
	 * Create an element.
	 *
	 * @param {string} tag      Tag name.
	 * @param {Object} props    Properties: class, text, dataset, on* handlers, other attributes.
	 * @param {Array}  children Child nodes or strings.
	 * @return {HTMLElement}
	 */
	function h( tag, props, children ) {
		var node = document.createElement( tag );

		props = props || {};

		Object.keys( props ).forEach( function ( key ) {
			var value = props[ key ];

			if ( value === null || typeof value === 'undefined' || value === false ) {
				return;
			}

			if ( key === 'class' ) {
				node.className = value;
			} else if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key === 'dataset' ) {
				Object.keys( value ).forEach( function ( dataKey ) {
					node.dataset[ dataKey ] = value[ dataKey ];
				} );
			} else if ( key.slice( 0, 2 ) === 'on' && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ), value );
			} else if ( key === 'checked' || key === 'disabled' || key === 'selected' || key === 'multiple' ) {
				node[ key ] = !! value;
			} else if ( key === 'value' ) {
				node.value = value;
			} else {
				node.setAttribute( key, value === true ? '' : value );
			}
		} );

		append( node, children );

		return node;
	}

	function append( node, children ) {
		if ( children === null || typeof children === 'undefined' || children === false ) {
			return;
		}

		if ( Array.isArray( children ) ) {
			children.forEach( function ( child ) {
				append( node, child );
			} );
		} else if ( typeof children === 'string' || typeof children === 'number' ) {
			node.appendChild( document.createTextNode( String( children ) ) );
		} else {
			node.appendChild( children );
		}
	}

	function empty( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function speak( message, politeness ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, politeness || 'polite' );
		}
	}

	function clone( object ) {
		return JSON.parse( JSON.stringify( object ) );
	}

	function debounce( fn, wait ) {
		var timer = null;

		return function () {
			var args = arguments;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( null, args );
			}, wait );
		};
	}

	/**
	 * Call the REST API.
	 *
	 * @param {string} method HTTP method.
	 * @param {string} path   Path below the namespace, starting with a slash.
	 * @param {Object} data   Request body.
	 * @return {Promise}
	 */
	function api( method, path, data ) {
		var request = {
			path: API_BASE + path,
			method: method
		};

		if ( data ) {
			request.data = data;
		}

		return window.wp.apiFetch( request );
	}

	function errorMessage( error ) {
		if ( error && error.message ) {
			return error.message;
		}

		return i( 'genericError' );
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------- */

	var noticeRegion = null;

	function notice( type, message ) {
		if ( ! noticeRegion ) {
			return;
		}

		var dismiss = h( 'button', {
			type: 'button',
			class: 'notice-dismiss',
			onclick: function () {
				box.remove();
			}
		}, [ h( 'span', { class: 'screen-reader-text', text: i( 'dismiss' ) } ) ] );

		var box = h( 'div', { class: 'notice notice-' + type + ' is-dismissible urm-notice' }, [ h( 'p', { text: message } ), dismiss ] );

		empty( noticeRegion );
		noticeRegion.appendChild( box );
		speak( message, type === 'error' ? 'assertive' : 'polite' );

		if ( type === 'success' ) {
			window.setTimeout( function () {
				if ( box.parentNode ) {
					box.remove();
				}
			}, 6000 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Dialogs
	 * ------------------------------------------------------------------- */

	/**
	 * Open a modal dialog.
	 *
	 * @param {Object} options title, content (Node), buttons ([{label, kind, onClick}]), wide, onClose.
	 * @return {{close: Function, el: HTMLElement}}
	 */
	function openDialog( options ) {
		var titleId = uid( 'urm-dialog-title' );
		var previouslyFocused = document.activeElement;
		var dialog = h( 'dialog', { class: 'urm-dialog' + ( options.wide ? ' is-wide' : '' ), 'aria-labelledby': titleId } );
		var form = h( 'form', { class: 'urm-dialog-form', method: 'dialog' } );
		var footer = h( 'div', { class: 'urm-dialog-footer' } );
		var primaryButton = null;

		function close() {
			if ( dialog.open ) {
				dialog.close();
			}
		}

		( options.buttons || [] ).forEach( function ( button ) {
			var node = h( 'button', {
				type: button.kind === 'primary' || button.kind === 'danger' ? 'submit' : 'button',
				class: 'button' + ( button.kind === 'primary' || button.kind === 'danger' ? ' button-primary' : '' ) + ( button.kind === 'danger' ? ' urm-button-danger' : '' ),
				text: button.label
			} );

			if ( button.kind === 'primary' || button.kind === 'danger' ) {
				primaryButton = node;
			} else {
				node.addEventListener( 'click', function () {
					if ( button.onClick ) {
						button.onClick( close );
					} else {
						close();
					}
				} );
			}

			footer.appendChild( node );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var primary = ( options.buttons || [] ).filter( function ( button ) {
				return button.kind === 'primary' || button.kind === 'danger';
			} )[ 0 ];

			if ( primary && primary.onClick ) {
				primary.onClick( close, primaryButton );
			} else {
				close();
			}
		} );

		form.appendChild( h( 'div', { class: 'urm-dialog-header' }, [
			h( 'h2', { id: titleId, text: options.title } ),
			h( 'button', { type: 'button', class: 'urm-dialog-x', 'aria-label': i( 'close' ), onclick: close }, [ h( 'span', { class: 'dashicons dashicons-no-alt', 'aria-hidden': 'true' } ) ] )
		] ) );
		form.appendChild( h( 'div', { class: 'urm-dialog-body' }, [ options.content ] ) );
		form.appendChild( footer );
		dialog.appendChild( form );
		document.body.appendChild( dialog );

		dialog.addEventListener( 'close', function () {
			dialog.remove();

			if ( previouslyFocused && previouslyFocused.focus && document.body.contains( previouslyFocused ) ) {
				previouslyFocused.focus();
			}

			if ( options.onClose ) {
				options.onClose();
			}
		} );

		dialog.addEventListener( 'click', function ( event ) {
			// Clicking the backdrop closes the dialog.
			if ( event.target === dialog ) {
				close();
			}
		} );

		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}

		var firstField = dialog.querySelector( '.urm-dialog-body input:not([type="hidden"]), .urm-dialog-body select, .urm-dialog-body textarea' );

		if ( firstField ) {
			firstField.focus();
		} else if ( primaryButton ) {
			primaryButton.focus();
		}

		return { close: close, el: dialog };
	}

	/**
	 * Ask for confirmation.
	 *
	 * @param {Object} options title, message (string or Node), confirmLabel, danger.
	 * @return {Promise<boolean>}
	 */
	function confirmDialog( options ) {
		return new Promise( function ( resolve ) {
			var decided = false;
			var content = typeof options.message === 'string' ? h( 'p', { text: options.message } ) : options.message;

			openDialog( {
				title: options.title,
				content: content,
				buttons: [
					{
						label: i( 'cancel' ),
						onClick: function ( close ) {
							close();
						}
					},
					{
						label: options.confirmLabel || i( 'confirm' ),
						kind: options.danger ? 'danger' : 'primary',
						onClick: function ( close ) {
							decided = true;
							close();
							resolve( true );
						}
					}
				],
				onClose: function () {
					if ( ! decided ) {
						resolve( false );
					}
				}
			} );
		} );
	}

	/**
	 * Build a labelled form row.
	 */
	function field( label, control, help ) {
		var id = uid( 'urm-field' );

		control.id = id;

		return h( 'div', { class: 'urm-field' }, [
			h( 'label', { for: id, text: label } ),
			control,
			help ? h( 'p', { class: 'description', text: help } ) : null
		] );
	}

	function badge( text, kind ) {
		return h( 'span', { class: 'urm-badge' + ( kind ? ' urm-badge-' + kind : '' ), text: text } );
	}

	function slugify( text ) {
		return text.toLowerCase().replace( /[^a-z0-9_\-\s]/g, '' ).trim().replace( /\s+/g, '_' ).replace( /^[^a-z]+/, '' ).slice( 0, 40 );
	}

	// Kit shared with the Pro script.
	window.asenhaUserRoleManagerKit = {
		cfg: cfg,
		i: i,
		f: f,
		h: h,
		empty: empty,
		uid: uid,
		api: api,
		speak: speak,
		notice: notice,
		clone: clone,
		debounce: debounce,
		field: field,
		badge: badge,
		openDialog: openDialog,
		confirmDialog: confirmDialog,
		errorMessage: errorMessage,
		slugify: slugify
	};

	/* ---------------------------------------------------------------------
	 * Roles editor
	 * ------------------------------------------------------------------- */

	var root = document.getElementById( 'asenha-urm-app' );

	if ( ! root ) {
		return;
	}

	noticeRegion = document.getElementById( 'asenha-urm-notices' );

	if ( root.getAttribute( 'data-tab' ) !== 'roles' ) {
		return;
	}

	/**
	 * Essentials and By area are available in every version. All capabilities is Pro.
	 *
	 * @param {string} level Saved view level.
	 * @return {string}
	 */
	function normalizeViewLevel( level ) {
		

		return level === 'all' ? 'areas' : level;
	}

	var S = {
		roles: cfg.state.roles || [],
		catalog: cfg.state.catalog,
		defaultRole: cfg.state.defaultRole,
		viewLevel: normalizeViewLevel( cfg.state.viewLevel ),
		currentSlug: null,
		detail: {},
		orig: {},
		caps: {},
		origMeta: { name: '', description: '' },
		meta: { name: '', description: '' },
		filter: 'all',
		search: '',
		showLegacy: false,
		index: {},
		lastClickedRow: null,
		saving: false,
		// Section ids open in each view. Missing ids stay collapsed. Reset on reload.
		openSections: {
			essentials: {},
			areas: {},
			all: {}
		}
	};

	var els = {};

	/* Capability index ---------------------------------------------------- */

	function buildIndex() {
		var index = {};
		var catalog = S.catalog;

		function add( cap, label, group, kind, extra ) {
			if ( index[ cap ] ) {
				return;
			}

			index[ cap ] = Object.assign( { cap: cap, label: label, group: group, kind: kind, sensitive: false, deletable: false }, extra || {} );
		}

		catalog.groups.forEach( function ( group ) {
			group.caps.forEach( function ( entry ) {
				add( entry.cap, entry.label, group.label, group.id === 'legacy' ? 'legacy' : 'core', { sensitive: entry.sensitive } );
			} );
		} );

		catalog.postTypes.forEach( function ( postType ) {
			catalog.postTypeColumns.forEach( function ( column ) {
				var cap = postType.caps[ column.key ];

				if ( cap ) {
					add( cap, postType.label + ': ' + column.label, i( 'groupContent' ), 'content', { sensitive: false } );
				}
			} );
		} );

		catalog.taxonomies.forEach( function ( taxonomy ) {
			catalog.taxonomyColumns.forEach( function ( column ) {
				var cap = taxonomy.caps[ column.key ];

				if ( ! cap ) {
					return;
				}

				// Link Categories uses manage_links for every column. Site registered it
				// first, so claim it for Taxonomies on the Manage column. Later columns
				// are the same capability and keep that label. Other core capabilities stay put.
				if ( 'manage_links' === cap && index[ cap ] && index[ cap ].group !== i( 'groupTaxonomies' ) ) {
					index[ cap ].label = taxonomy.label + ': ' + column.label;
					index[ cap ].group = i( 'groupTaxonomies' );
					index[ cap ].kind = 'taxonomy';
					return;
				}

				add( cap, taxonomy.label + ': ' + column.label, i( 'groupTaxonomies' ), 'taxonomy', { sensitive: false } );
			} );
		} );

		catalog.thirdParty.forEach( function ( group ) {
			group.caps.forEach( function ( entry ) {
				add( entry.cap, entry.label, group.label, 'plugin', { sensitive: entry.sensitive, deletable: true } );
			} );
		} );

		catalog.custom.forEach( function ( entry ) {
			add( entry.cap, entry.label, i( 'groupCustom' ), 'custom', { sensitive: entry.sensitive, deletable: true, description: entry.description } );
		} );

		catalog.unrecognized.forEach( function ( entry ) {
			add( entry.cap, entry.label, i( 'groupUnrecognized' ), 'unrecognized', { sensitive: entry.sensitive, deletable: true } );
		} );

		S.index = index;
	}

	function capInfo( cap ) {
		if ( ! S.index[ cap ] ) {
			S.index[ cap ] = { cap: cap, label: cap, group: i( 'groupUnrecognized' ), kind: 'unrecognized', sensitive: false, deletable: true };
		}

		return S.index[ cap ];
	}

	/**
	 * Plugin and leftover capabilities are shown as the raw slug.
	 *
	 * @param {Object} info Indexed capability.
	 * @return {boolean}
	 */
	function isSlugOnly( info ) {
		return info.kind === 'plugin' || info.kind === 'unrecognized';
	}

	/* State helpers ------------------------------------------------------- */

	function capState( cap ) {
		if ( S.caps[ cap ] === true ) {
			return 'g';
		}

		return S.caps[ cap ] === false ? 'd' : 'u';
	}

	function origState( cap ) {
		if ( S.orig[ cap ] === true ) {
			return 'g';
		}

		return S.orig[ cap ] === false ? 'd' : 'u';
	}

	function setCapState( cap, state ) {
		if ( state === 'g' ) {
			S.caps[ cap ] = true;
		} else if ( state === 'd' ) {
			S.caps[ cap ] = false;
		} else {
			delete S.caps[ cap ];
		}
	}

	function currentRole() {
		return S.roles.find( function ( role ) {
			return role.slug === S.currentSlug;
		} );
	}

	function isLocked( cap ) {
		return S.currentSlug === 'administrator' && S.catalog.protectedAdmin.indexOf( cap ) !== -1;
	}

	function computeDiff() {
		var diff = { grant: [], deny: [], unset: [] };
		var seen = {};

		Object.keys( S.caps ).concat( Object.keys( S.orig ) ).forEach( function ( cap ) {
			if ( seen[ cap ] ) {
				return;
			}

			seen[ cap ] = true;

			var now = capState( cap );
			var before = origState( cap );

			if ( now === before ) {
				return;
			}

			if ( now === 'g' ) {
				diff.grant.push( cap );
			} else if ( now === 'd' ) {
				diff.deny.push( cap );
			} else {
				diff.unset.push( cap );
			}
		} );

		return diff;
	}

	function metaChanged() {
		return {
			name: S.meta.name !== S.origMeta.name,
			description: S.meta.description !== S.origMeta.description
		};
	}

	function pendingCount() {
		var diff = computeDiff();
		var changed = metaChanged();

		return diff.grant.length + diff.deny.length + diff.unset.length + ( changed.name ? 1 : 0 ) + ( changed.description ? 1 : 0 );
	}

	/**
	 * Apply a map of capability => state ('g', 'd', 'u') to the working copy.
	 * Asks for confirmation when sensitive capabilities are newly granted to a non-administrator role.
	 *
	 * @param {Object} map Capability => state.
	 * @return {Promise<boolean>} Resolves true when something was changed.
	 */
	function applyStates( map ) {
		var changes = {};
		var sensitive = [];

		Object.keys( map ).forEach( function ( cap ) {
			var target = map[ cap ];
			var previous = capState( cap );

			if ( previous === target || ( isLocked( cap ) && target !== 'g' ) ) {
				return;
			}

			changes[ cap ] = target;

			if ( target === 'g' && capInfo( cap ).sensitive && S.currentSlug !== 'administrator' ) {
				sensitive.push( cap );
			}
		} );

		var keys = Object.keys( changes );

		if ( ! keys.length ) {
			return Promise.resolve( false );
		}

		function commit() {
			keys.forEach( function ( cap ) {
				setCapState( cap, changes[ cap ] );
			} );

			refresh();
			speak( f( 'pendingCount', pendingCount() ) );

			return true;
		}

		if ( ! sensitive.length ) {
			return Promise.resolve( commit() );
		}

		var list = h( 'ul', { class: 'urm-plain-list' } );

		sensitive.slice( 0, 12 ).forEach( function ( cap ) {
			list.appendChild( capLine( cap ) );
		} );

		if ( sensitive.length > 12 ) {
			list.appendChild( h( 'li', { text: f( 'andMore', sensitive.length - 12 ) } ) );
		}

		return confirmDialog( {
			title: i( 'sensitiveTitle' ),
			message: h( 'div', {}, [ h( 'p', { text: f( 'sensitiveMessage', currentRole() ? currentRole().name : S.currentSlug ) } ), list ] ),
			confirmLabel: i( 'grantAnyway' )
		} ).then( function ( ok ) {
			if ( ok ) {
				return commit();
			}

			refresh();
			return false;
		} );
	}

	/* Data loading -------------------------------------------------------- */

	function rebuildFromState( state ) {
		S.roles = state.roles;
		S.catalog = state.catalog;
		S.defaultRole = state.defaultRole;
		S.viewLevel = normalizeViewLevel( state.viewLevel );
		buildIndex();
	}

	function loadRole( slug ) {
		return api( 'GET', '/roles/' + encodeURIComponent( slug ) ).then( function ( role ) {
			S.detail[ slug ] = role;
			return role;
		} );
	}

	function setWorkingRole( role ) {
		var caps = {};

		Object.keys( role.capabilities || {} ).forEach( function ( cap ) {
			caps[ cap ] = !! role.capabilities[ cap ];
			capInfo( cap );
		} );

		S.orig = clone( caps );
		S.caps = clone( caps );
		S.origMeta = { name: role.name, description: role.description || '' };
		S.meta = { name: role.name, description: role.description || '' };
		S.lastClickedRow = null;
	}

	function replaceRoleOverview( role ) {
		var copy = Object.assign( {}, role );

		delete copy.capabilities;

		S.roles = S.roles.map( function ( existing ) {
			return existing.slug === copy.slug ? copy : existing;
		} );
	}

	function selectRole( slug, skipConfirm ) {
		if ( slug === S.currentSlug ) {
			return Promise.resolve();
		}

		function go() {
			S.currentSlug = slug;
			renderSidebar();
			els.main.classList.add( 'is-loading' );

			return loadRole( slug ).then( function ( role ) {
				setWorkingRole( role );
				els.main.classList.remove( 'is-loading' );
				renderMain();

				if ( window.history && window.history.replaceState ) {
					var url = new URL( window.location.href );
					url.searchParams.set( 'role', slug );
					window.history.replaceState( null, '', url.toString() );
				}
			} ).catch( function ( error ) {
				els.main.classList.remove( 'is-loading' );
				notice( 'error', errorMessage( error ) );
			} );
		}

		if ( ! skipConfirm && S.currentSlug && pendingCount() > 0 ) {
			return confirmDialog( {
				title: i( 'discardTitle' ),
				message: i( 'discardMessage' ),
				confirmLabel: i( 'discard' ),
				danger: true
			} ).then( function ( ok ) {
				return ok ? go() : null;
			} );
		}

		return go();
	}

	/**
	 * Reload roles and catalog from the server and re-render.
	 *
	 * @param {string} selectSlug Role to select afterwards.
	 * @return {Promise}
	 */
	function reloadState( selectSlug ) {
		return api( 'GET', '/state' ).then( function ( state ) {
			rebuildFromState( state );
			S.detail = {};

			var slug = selectSlug || S.currentSlug;

			if ( ! S.roles.some( function ( role ) {
				return role.slug === slug;
			} ) ) {
				slug = S.roles.length ? S.roles[ 0 ].slug : null;
			}

			S.currentSlug = null;

			return slug ? selectRole( slug, true ) : null;
		} );
	}

	/* Rendering: sidebar -------------------------------------------------- */

	function renderSidebar() {
		empty( els.roleList );

		S.roles.forEach( function ( role ) {
			var meta = [ f( 'usersCount', role.users ), f( 'capsCount', role.granted ) ];
			var badges = [];

			if ( role.isCore ) {
				badges.push( badge( i( 'badgeCore' ), 'core' ) );
			} else {
				badges.push( badge( i( 'badgeCustom' ), 'custom' ) );
			}

			if ( role.isDefault ) {
				badges.push( badge( i( 'badgeDefault' ), 'default' ) );
			}

			if ( role.modified ) {
				badges.push( badge( i( 'badgeModified' ), 'modified' ) );
			}

			var button = h( 'button', {
				type: 'button',
				class: 'urm-role-item' + ( role.slug === S.currentSlug ? ' is-current' : '' ),
				'aria-current': role.slug === S.currentSlug ? 'true' : null,
				onclick: function () {
					selectRole( role.slug );
				}
			}, [
				h( 'span', { class: 'urm-role-name', text: role.name } ),
				h( 'span', { class: 'urm-role-meta', text: meta.join( ' · ' ) } ),
				h( 'span', { class: 'urm-role-badges' }, badges )
			] );

			els.roleList.appendChild( h( 'li', {}, [ button ] ) );
		} );
	}

	/* Rendering: main ----------------------------------------------------- */

	function renderMain() {
		var role = currentRole();

		empty( els.main );

		if ( ! role ) {
			els.main.appendChild( h( 'p', { text: i( 'noRoles' ) } ) );
			return;
		}

		els.main.appendChild( renderRoleHeader( role ) );
		els.main.appendChild( renderControls() );

		els.content = h( 'div', { class: 'urm-content', id: 'urm-content' } );
		els.main.appendChild( els.content );
		els.main.appendChild( renderSaveBar() );

		renderContent();
		renderSidebar();
	}

	function renderRoleHeader( role ) {
		var badges = [];

		if ( role.isCore ) {
			badges.push( badge( i( 'badgeCore' ), 'core' ) );
		} else {
			badges.push( badge( i( 'badgeCustom' ), 'custom' ) );
		}

		if ( role.isDefault ) {
			badges.push( badge( i( 'badgeDefault' ), 'default' ) );
		}

		if ( role.isProtected ) {
			badges.push( badge( i( 'badgeProtected' ), 'protected' ) );
		}

		var toolbar = h( 'div', { class: 'urm-toolbar', role: 'group', 'aria-label': i( 'roleActions' ) } );

		function tool( label, handler, extraClass ) {
			toolbar.appendChild( h( 'button', { type: 'button', class: 'button' + ( extraClass ? ' ' + extraClass : '' ), text: label, onclick: handler } ) );
		}

		tool( i( 'actionClone' ), function () {
			openRoleDialog( { cloneFrom: role.slug } );
		} );

		

		if ( ! role.isDefault && role.slug !== 'administrator' ) {
			tool( i( 'actionSetDefault' ), function () {
				setDefaultRole( role );
			} );
		}

		var allowDelete = !! role.isManaged;

		

		if ( role.isCore ) {
			tool( i( 'actionReset' ), function () {
				resetRole( role );
			} );
		} else if ( allowDelete ) {
			tool( i( 'actionDelete' ), function () {
				openDeleteDialog( role );
			}, 'urm-button-danger-outline' );
		}

		return h( 'div', { class: 'urm-role-header' }, [
			h( 'div', { class: 'urm-role-title' }, [
				h( 'h2', { text: role.name } ),
				h( 'code', { text: role.slug } ),
				h( 'span', { class: 'urm-role-badges' }, badges ),
				h( 'a', { class: 'urm-users-link', href: role.usersUrl, text: f( 'viewUsers', role.users ) } )
			] ),
			role.description ? h( 'p', { class: 'urm-role-description', text: role.description } ) : null,
			renderRoleDetails( role ),
			toolbar
		] );
	}

	function renderRoleDetails( role ) {
		var nameInput = h( 'input', {
			type: 'text',
			class: 'regular-text',
			maxlength: '80',
			value: S.meta.name,
			oninput: function ( event ) {
				S.meta.name = event.target.value;
				updateSaveBar();
			}
		} );
		var descriptionInput = h( 'textarea', {
			rows: '2',
			class: 'large-text',
			maxlength: '300',
			oninput: function ( event ) {
				S.meta.description = event.target.value;
				updateSaveBar();
			}
		} );

		descriptionInput.value = S.meta.description;

		return h( 'details', { class: 'urm-details' }, [
			h( 'summary', { text: i( 'roleDetails' ) } ),
			h( 'div', { class: 'urm-details-body' }, [
				field( i( 'roleName' ), nameInput ),
				field( i( 'roleDescription' ), descriptionInput, i( 'roleDescriptionHelp' ) ),
				h( 'p', { class: 'description' }, [ i( 'roleSlugFixed' ), ' ', h( 'code', { text: role.slug } ) ] )
			] )
		] );
	}

	function renderControls() {
		var levels = [
			{ id: 'essentials', label: i( 'viewEssentials' ), help: i( 'viewEssentialsHelp' ) },
			{ id: 'areas', label: i( 'viewAreas' ), help: i( 'viewAreasHelp' ) }
		];

		
		var switcher = h( 'div', { class: 'urm-view-switch', role: 'group', 'aria-label': i( 'viewLevel' ) } );

		levels.forEach( function ( level ) {
			switcher.appendChild( h( 'button', {
				type: 'button',
				class: 'button' + ( S.viewLevel === level.id ? ' is-active' : '' ),
				'aria-pressed': S.viewLevel === level.id ? 'true' : 'false',
				title: level.help,
				text: level.label,
				onclick: function () {
					if ( S.viewLevel === level.id ) {
						return;
					}

					S.viewLevel = level.id;
					S.filter = 'all';
					api( 'POST', '/view-level', { level: level.id } ).catch( function () {} );
					renderMain();
				}
			} ) );
		} );

		var searchId = uid( 'urm-search' );
		var search = h( 'div', { class: 'urm-search' }, [
			h( 'label', { for: searchId, class: 'screen-reader-text', text: i( 'searchLabel' ) } ),
			h( 'input', {
				type: 'search',
				id: searchId,
				placeholder: i( 'searchPlaceholder' ),
				value: S.search,
				oninput: debounce( function ( event ) {
					S.search = event.target.value.trim().toLowerCase();
					refresh();
				}, 120 )
			} )
		] );

		var sectionTools = h( 'div', { class: 'urm-section-tools', role: 'group', 'aria-label': i( 'sectionTools' ) }, [
			h( 'button', {
				type: 'button',
				class: 'button',
				text: i( 'expandAll' ),
				onclick: function () {
					setAllSections( true );
				}
			} ),
			h( 'button', {
				type: 'button',
				class: 'button',
				text: i( 'collapseAll' ),
				onclick: function () {
					setAllSections( false );
				}
			} )
		] );

		var addCapability = null;

		

		els.controls = h( 'div', { class: 'urm-controls' }, [
			switcher,
			addCapability,
			h( 'div', { class: 'urm-controls-end' }, [ sectionTools, search ] )
		] );

		var wrapper = h( 'div', { class: 'urm-controls-wrap' }, [
			h( 'h3', { class: 'urm-section-heading', text: i( 'capabilitiesHeading' ) } ),
			els.controls
		] );

		

		return wrapper;
	}

	function renderSaveBar() {
		els.saveCount = h( 'span', { class: 'urm-save-count', role: 'status' } );
		els.reviewButton = h( 'button', { type: 'button', class: 'button', text: i( 'reviewChanges' ), onclick: openReviewDialog } );
		els.discardButton = h( 'button', { type: 'button', class: 'button', text: i( 'discard' ), onclick: discardChanges } );
		els.saveButton = h( 'button', { type: 'button', class: 'button button-primary', text: i( 'saveChanges' ), onclick: function () {
			saveChanges();
		} } );

		els.saveBar = h( 'div', { class: 'urm-savebar' }, [
			els.saveCount,
			h( 'span', { class: 'urm-save-actions' }, [ els.reviewButton, els.discardButton, els.saveButton ] )
		] );

		updateSaveBar();

		return els.saveBar;
	}

	function updateSaveBar() {
		if ( ! els.saveBar ) {
			return;
		}

		var count = pendingCount();

		els.saveCount.textContent = count ? f( 'pendingCount', count ) : i( 'noPending' );
		els.saveBar.classList.toggle( 'has-changes', count > 0 );
		els.reviewButton.disabled = count === 0 || S.saving;
		els.discardButton.disabled = count === 0 || S.saving;
		els.saveButton.disabled = count === 0 || S.saving;
	}

	/* Rendering: content -------------------------------------------------- */

	function renderContent() {
		empty( els.content );

		if ( S.viewLevel === 'essentials' ) {
			renderEssentials();
		} else if ( S.viewLevel === 'areas' ) {
			renderAreas();
		
		} else {
			renderAreas();
		}

		refresh();
	}

	function searchText( parts ) {
		return parts.join( ' ' ).toLowerCase();
	}

	/**
	 * Build a tri-state group checkbox that grants or clears a list of capabilities.
	 */
	function groupToggle( caps, label ) {
		var input = h( 'input', { type: 'checkbox', class: 'urm-group-toggle', 'aria-label': label, 'aria-checked': 'false' } );

		input.urmCaps = caps;
		input.addEventListener( 'click', function () {
			var allGranted = caps.every( function ( cap ) {
				return capState( cap ) === 'g' || isLocked( cap );
			} );
			var target = allGranted ? 'u' : 'g';
			var map = {};

			caps.forEach( function ( cap ) {
				map[ cap ] = target;
			} );

			applyStates( map ).then( refresh );
		} );

		return input;
	}

	function capCheckbox( cap, label ) {
		var input = h( 'input', { type: 'checkbox', 'aria-label': label || capInfo( cap ).label, dataset: { cap: cap } } );

		input.addEventListener( 'click', function () {
			var map = {};

			map[ cap ] = capState( cap ) === 'g' ? 'u' : 'g';
			applyStates( map ).then( refresh );
		} );

		return input;
	}

	/**
	 * Whether a search or an All-capabilities chip filter is hiding rows.
	 *
	 * @return {boolean}
	 */
	function isFiltering() {
		var filtering = S.search !== '';

		

		return filtering;
	}

	/**
	 * Whether this section should stay open so matching rows remain visible.
	 * Call after row visibility has been updated.
	 *
	 * @param {HTMLElement} card Section card.
	 * @return {boolean}
	 */
	function sectionForcedOpen( card ) {
		if ( ! isFiltering() ) {
			return false;
		}

		return !! card.querySelector( '[data-search]:not([hidden])' );
	}

	/**
	 * Whether the user has expanded this section in the current view.
	 *
	 * @param {string} id Section id.
	 * @return {boolean}
	 */
	function isSectionOpen( id ) {
		var map = S.openSections[ S.viewLevel ];

		return !! ( map && map[ id ] );
	}

	/**
	 * Remember a section as open or collapsed for the current view.
	 *
	 * @param {string}  id   Section id.
	 * @param {boolean} open True to expand.
	 */
	function setSectionOpen( id, open ) {
		var level = S.viewLevel;

		if ( ! S.openSections[ level ] ) {
			S.openSections[ level ] = {};
		}

		if ( open ) {
			S.openSections[ level ][ id ] = true;
		} else {
			delete S.openSections[ level ][ id ];
		}
	}

	/**
	 * Dashicon class for the section chevron.
	 *
	 * @param {boolean} open True when the section is expanded.
	 * @return {string}
	 */
	function sectionChevronClass( open ) {
		return 'dashicons urm-card-chevron ' + ( open ? 'dashicons-arrow-down-alt2' : 'dashicons-arrow-right-alt2' );
	}

	/**
	 * How completely a list of capabilities is granted.
	 * An empty list, and a list with no grants, is "none". Denied and not set do not count as grants.
	 *
	 * @param {Array} caps Capability slugs.
	 * @return {string} "full", "partial", or "none".
	 */
	function capsGrantState( caps ) {
		var granted = 0;

		if ( ! caps.length ) {
			return 'none';
		}

		caps.forEach( function ( cap ) {
			if ( capState( cap ) === 'g' ) {
				granted += 1;
			}
		} );

		if ( granted === 0 ) {
			return 'none';
		}

		return granted === caps.length ? 'full' : 'partial';
	}

	/**
	 * Count granted rows in a section.
	 * Essentials counts checked switches only. Matrices count rows with any grant and flag incomplete rows.
	 * Other sections count granted capabilities. Hidden rows are included.
	 *
	 * @param {HTMLElement} card Section card.
	 * @return {{granted: number, partial: boolean}}
	 */
	function sectionGrantedCount( card ) {
		var matrixRows = card.querySelectorAll( '.urm-matrix tbody .urm-matrix-name .urm-group-toggle' );

		if ( matrixRows.length ) {
			var rowsGranted = 0;
			var partial = false;

			Array.prototype.forEach.call( matrixRows, function ( toggle ) {
				var state = capsGrantState( toggle.urmCaps || [] );

				if ( state === 'none' ) {
					return;
				}

				rowsGranted += 1;

				if ( state === 'partial' ) {
					partial = true;
				}
			} );

			return { granted: rowsGranted, partial: partial };
		}

		var itemToggles = card.querySelectorAll( '.urm-check-item .urm-group-toggle' );

		if ( itemToggles.length ) {
			var itemsGranted = 0;

			Array.prototype.forEach.call( itemToggles, function ( toggle ) {
				if ( capsGrantState( toggle.urmCaps || [] ) === 'full' ) {
					itemsGranted += 1;
				}
			} );

			return { granted: itemsGranted, partial: false };
		}

		var headerToggle = card.querySelector( '.urm-card-header .urm-group-toggle' );
		var granted = 0;

		if ( headerToggle && headerToggle.urmCaps ) {
			headerToggle.urmCaps.forEach( function ( cap ) {
				if ( capState( cap ) === 'g' ) {
					granted += 1;
				}
			} );
		}

		return { granted: granted, partial: false };
	}

	/**
	 * Write the granted / total label and return the numbers.
	 * A leading tilde means some counted matrix rows are only partly granted.
	 *
	 * @param {HTMLElement} card Section card.
	 * @return {{granted: number, total: number, partial: boolean}}
	 */
	function syncSectionCount( card ) {
		var countEl = card.querySelector( '.urm-card-count' );
		var total = countEl ? parseInt( countEl.getAttribute( 'data-total' ), 10 ) : 0;
		var counts = sectionGrantedCount( card );

		if ( isNaN( total ) ) {
			total = 0;
		}

		if ( countEl ) {
			countEl.textContent = f( counts.partial ? 'sectionCountPartial' : 'sectionCount', counts.granted, total );
		}

		return { granted: counts.granted, total: total, partial: counts.partial };
	}

	/**
	 * Show or hide a section body and sync the toggle button.
	 *
	 * @param {HTMLElement} card Section card.
	 * @param {boolean}     open True to expand.
	 */
	function applySectionOpen( card, open ) {
		var body = card.querySelector( '.urm-card-body' );
		var button = card.querySelector( '.urm-card-toggle' );
		var label = card.getAttribute( 'data-section-label' ) || '';
		var chevron = button ? button.querySelector( '.urm-card-chevron' ) : null;
		var counts = syncSectionCount( card );

		card.classList.toggle( 'is-collapsed', ! open );

		if ( body ) {
			body.hidden = ! open;
		}

		if ( button ) {
			var labelKey = open ? 'collapseSectionCount' : 'expandSectionCount';

			if ( counts.partial ) {
				labelKey = open ? 'collapseSectionCountPartial' : 'expandSectionCountPartial';
			}

			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			button.setAttribute( 'aria-label', f( labelKey, label, counts.granted, counts.total ) );
		}

		if ( chevron ) {
			chevron.className = sectionChevronClass( open );
		}
	}

	/**
	 * Expand or collapse every section in the current view.
	 * A search or chip filter still keeps sections with matches open until it is cleared.
	 *
	 * @param {boolean} open True to expand all.
	 */
	function setAllSections( open ) {
		var level = S.viewLevel;

		if ( ! S.openSections[ level ] ) {
			S.openSections[ level ] = {};
		}

		if ( ! els.content ) {
			return;
		}

		Array.prototype.forEach.call( els.content.querySelectorAll( '.urm-card[data-section]' ), function ( card ) {
			var id = card.getAttribute( 'data-section' );

			if ( open ) {
				S.openSections[ level ][ id ] = true;
			} else {
				delete S.openSections[ level ][ id ];
			}
		} );

		refresh();
	}

	/**
	 * Toggle one section. Matching rows stay visible while a search or filter is active.
	 *
	 * @param {HTMLElement} card Section card.
	 */
	function toggleSection( card ) {
		var id = card.getAttribute( 'data-section' );
		var open = card.classList.contains( 'is-collapsed' );

		setSectionOpen( id, open );

		if ( ! open && sectionForcedOpen( card ) ) {
			applySectionOpen( card, true );
			return;
		}

		applySectionOpen( card, open );
	}

	/**
	 * Build a collapsible capability section.
	 * The group checkbox stays outside the toggle button so granting or clearing
	 * still runs, including the sensitive-capability confirmation, without expanding the section.
	 *
	 * @param {string}        id      Stable id within the current view.
	 * @param {string}        label   Heading text.
	 * @param {number}        count   Count shown on the header.
	 * @param {Array}         body    Nodes inside the collapsible body.
	 * @param {Object}        options Optional toggle checkbox, extraClass, and group id.
	 * @return {HTMLElement}
	 */
	function capabilityCard( id, label, count, body, options ) {
		options = options || {};

		var open = isSectionOpen( id );
		var bodyId = uid( 'urm-card-body' );
		var dataset = {
			section: id,
			sectionLabel: label,
			group: options.group || id
		};
		var classes = 'urm-card' + ( open ? '' : ' is-collapsed' );

		if ( options.extraClass ) {
			classes += ' ' + options.extraClass;
		}

		var toggle = h( 'button', {
			type: 'button',
			class: 'urm-card-toggle',
			'aria-expanded': open ? 'true' : 'false',
			'aria-controls': bodyId,
			'aria-label': f( open ? 'collapseSectionCount' : 'expandSectionCount', label, 0, count ),
			onclick: function () {
				var card = toggle.closest( '.urm-card' );

				if ( card ) {
					toggleSection( card );
				}
			}
		}, [
			h( 'span', { class: sectionChevronClass( open ), 'aria-hidden': 'true' } ),
			h( 'span', { class: 'urm-card-title', text: label } ),
			h( 'span', { class: 'urm-card-count', dataset: { total: String( count ) }, text: f( 'sectionCount', 0, count ) } )
		] );

		return h( 'section', { class: classes, dataset: dataset }, [
			h( 'header', { class: 'urm-card-header' }, [
				options.toggle || null,
				toggle
			] ),
			h( 'div', { class: 'urm-card-body', id: bodyId, hidden: ! open }, body )
		] );
	}

	function renderEssentials() {
		S.catalog.essentials.forEach( function ( section ) {
			var sectionCaps = [];
			var list = h( 'ul', { class: 'urm-check-list' } );

			section.items.forEach( function ( item ) {
				sectionCaps = sectionCaps.concat( item.caps );

				var toggle = groupToggle( item.caps, item.label );
				var labelId = uid( 'urm-ess' );

				toggle.setAttribute( 'aria-labelledby', labelId );
				toggle.removeAttribute( 'aria-label' );

				list.appendChild( h( 'li', {
					class: 'urm-check-item',
					dataset: { search: searchText( [ item.label ].concat( item.caps ) ), essential: item.id }
				}, [
					h( 'div', { class: 'urm-check-label' }, [
						toggle,
						h( 'span', { class: 'urm-check-text' }, [
							h( 'span', { class: 'urm-check-title', id: labelId, text: item.label } ),
							item.sensitive ? badge( i( 'badgeSensitive' ), 'sensitive' ) : null,
							h( 'span', { class: 'urm-state-badge', text: i( 'badgeDenied' ) } )
						] )
					] )
				] ) );
			} );

			var sectionToggle = groupToggle( sectionCaps, f( 'toggleGroup', section.label ) );

			els.content.appendChild( capabilityCard( section.id, section.label, section.items.length, [ list ], {
				toggle: sectionToggle
			} ) );
		} );

		els.content.appendChild( h( 'p', { class: 'description urm-hint', text: i( 'essentialsHint' ) } ) );
	}

	/**
	 * Label plus slug, for names and screen-reader text.
	 *
	 * @param {string} label Object label.
	 * @param {string} slug  Object slug.
	 * @return {string}
	 */
	function namedWithSlug( label, slug ) {
		if ( ! slug ) {
			return label;
		}

		return label + ' [' + slug + ']';
	}

	function matrixTable( objects, columns, caption, rowLabel ) {
		var head = h( 'tr', {}, [ h( 'th', { scope: 'col', class: 'urm-matrix-name', text: rowLabel } ) ] );
		var body = h( 'tbody' );

		columns.forEach( function ( column ) {
			var caps = [];

			objects.forEach( function ( object ) {
				if ( object.caps[ column.key ] && caps.indexOf( object.caps[ column.key ] ) === -1 ) {
					caps.push( object.caps[ column.key ] );
				}
			} );

			head.appendChild( h( 'th', { scope: 'col' }, [
				h( 'span', { class: 'urm-matrix-col-label', text: column.label } ),
				caps.length ? groupToggle( caps, f( 'toggleColumn', column.label ) ) : null
			] ) );
		} );

		objects.forEach( function ( object ) {
			var rowCaps = [];
			var row = h( 'tr', { dataset: { search: searchText( [ object.label, object.slug ].concat( Object.keys( object.caps ).map( function ( key ) {
				return object.caps[ key ];
			} ) ) ) } } );

			columns.forEach( function ( column ) {
				var cap = object.caps[ column.key ];

				if ( cap && rowCaps.indexOf( cap ) === -1 ) {
					rowCaps.push( cap );
				}
			} );

			var rowName = namedWithSlug( object.label, object.slug );
			var shareName = object.shares ? object.shares + ( object.sharesSlug ? ' (' + object.sharesSlug + ')' : '' ) : '';

			row.appendChild( h( 'th', { scope: 'row', class: 'urm-matrix-name' }, [
				groupToggle( rowCaps, f( 'toggleRow', rowName ) ),
				h( 'span', { class: 'urm-matrix-row-label' }, [
					object.label,
					object.slug ? h( 'span', { class: 'urm-object-slug', text: ' (' + object.slug + ')' } ) : null,
					shareName ? h( 'span', { class: 'description', text: f( 'sharesCaps', shareName ) } ) : null
				] )
			] ) );

			columns.forEach( function ( column ) {
				var cap = object.caps[ column.key ];

				if ( ! cap ) {
					row.appendChild( h( 'td', { class: 'urm-matrix-empty' }, [ h( 'span', { 'aria-hidden': 'true', text: '–' } ), h( 'span', { class: 'screen-reader-text', text: i( 'notApplicable' ) } ) ] ) );
					return;
				}

				row.appendChild( h( 'td', { dataset: { capRow: cap } }, [
					capCheckbox( cap, rowName + ': ' + column.label ),
					h( 'span', { class: 'urm-state-badge', text: i( 'badgeDeniedShort' ) } )
				] ) );
			} );

			body.appendChild( row );
		} );

		return h( 'div', { class: 'urm-table-wrap' }, [
			h( 'table', { class: 'urm-matrix widefat' }, [
				h( 'caption', { class: 'screen-reader-text', text: caption } ),
				h( 'thead', {}, [ head ] ),
				body
			] )
		] );
	}

	function checkListCard( id, label, description, caps, extraClass ) {
		var list = h( 'ul', { class: 'urm-check-list' } );

		caps.forEach( function ( entry ) {
			var slugOnly = isSlugOnly( capInfo( entry.cap ) );

			list.appendChild( h( 'li', {
				class: 'urm-check-item',
				dataset: { capRow: entry.cap, search: searchText( [ entry.label, entry.cap, label ] ) }
			}, [
				h( 'label', { class: 'urm-check-label' }, [
					capCheckbox( entry.cap, slugOnly ? entry.cap : null ),
					h( 'span', { class: 'urm-check-text' }, [
						slugOnly ? null : h( 'span', { class: 'urm-check-title', text: entry.label } ),
						slugOnly ? null : ' ',
						h( 'code', { text: entry.cap } ),
						entry.sensitive ? badge( i( 'badgeSensitive' ), 'sensitive' ) : null,
						h( 'span', { class: 'urm-state-badge', text: i( 'badgeDenied' ) } )
					] )
				] )
			] ) );
		} );

		return capabilityCard( id, label, caps.length, [
			description ? h( 'p', { class: 'description', text: description } ) : null,
			list
		], {
			extraClass: extraClass,
			group: id,
			toggle: groupToggle( caps.map( function ( entry ) {
				return entry.cap;
			} ), f( 'toggleGroup', label ) )
		} );
	}

	function renderAreas() {
		var catalog = S.catalog;

		if ( catalog.postTypes.length ) {
			els.content.appendChild( capabilityCard(
				'content-types',
				i( 'groupContent' ),
				catalog.postTypes.length,
				[
					h( 'p', { class: 'description', text: i( 'matrixHint' ) } ),
					matrixTable( catalog.postTypes, catalog.postTypeColumns, i( 'groupContent' ), i( 'columnPostType' ) )
				]
			) );
		}

		if ( catalog.taxonomies.length ) {
			els.content.appendChild( capabilityCard(
				'taxonomies',
				i( 'groupTaxonomies' ),
				catalog.taxonomies.length,
				[ matrixTable( catalog.taxonomies, catalog.taxonomyColumns, i( 'groupTaxonomies' ), i( 'columnTaxonomy' ) ) ]
			) );
		}

		catalog.groups.forEach( function ( group ) {
			var caps;

			if ( group.hidden && ! S.showLegacy ) {
				return;
			}

			// A capability claimed by Content or Taxonomies is edited there.
			// manage_links stays in this list only when no shown taxonomy uses it.
			caps = group.caps.filter( function ( entry ) {
				return ! S.index[ entry.cap ] || S.index[ entry.cap ].group === group.label;
			} );

			if ( ! caps.length ) {
				return;
			}

			els.content.appendChild( checkListCard( group.id, group.label, group.description, caps ) );
		} );

		catalog.thirdParty.forEach( function ( group ) {
			els.content.appendChild( checkListCard( group.id, group.label, i( 'thirdPartyHint' ), group.caps ) );
		} );

		if ( catalog.custom.length ) {
			els.content.appendChild( checkListCard( 'custom', i( 'groupCustom' ), i( 'customHint' ), catalog.custom ) );
		}

		if ( catalog.unrecognized.length ) {
			els.content.appendChild( checkListCard( 'unrecognized', i( 'groupUnrecognized' ), i( 'unrecognizedHint' ), catalog.unrecognized ) );
		}

		els.content.appendChild( h( 'p', { class: 'urm-hint' }, [
			h( 'label', {}, [
				h( 'input', {
					type: 'checkbox',
					checked: S.showLegacy,
					onchange: function ( event ) {
						S.showLegacy = event.target.checked;
						renderContent();
					}
				} ),
				' ',
				i( 'showLegacy' )
			] )
		] ) );
	}

	

	function refresh() {
		if ( ! els.content ) {
			return;
		}

		var content = els.content;

		// Row / cell wrappers.
		Array.prototype.forEach.call( content.querySelectorAll( '[data-cap-row]' ), function ( wrapper ) {
			var cap = wrapper.getAttribute( 'data-cap-row' );
			var state = capState( cap );
			var locked = isLocked( cap );

			wrapper.classList.toggle( 'is-granted', state === 'g' );
			wrapper.classList.toggle( 'is-denied', state === 'd' );
			wrapper.classList.toggle( 'is-changed', state !== origState( cap ) );
			wrapper.classList.toggle( 'is-locked', locked );

			Array.prototype.forEach.call( wrapper.querySelectorAll( '[data-cap]' ), function ( input ) {
				if ( input.type === 'checkbox' ) {
					input.checked = state === 'g';
				} else if ( input.type === 'radio' ) {
					input.checked = input.value === state;
				}

				input.disabled = locked;

				if ( locked ) {
					input.title = i( 'lockedCap' );
				}
			} );
		} );

		// Cells that are not wrapped (none expected), and group toggles.
		Array.prototype.forEach.call( content.querySelectorAll( '.urm-group-toggle' ), function ( toggle ) {
			var caps = toggle.urmCaps || [];
			var granted = 0;

			caps.forEach( function ( cap ) {
				if ( capState( cap ) === 'g' ) {
					granted += 1;
				}
			} );

			toggle.checked = caps.length > 0 && granted === caps.length;
			toggle.indeterminate = granted > 0 && granted < caps.length;
			toggle.setAttribute( 'aria-checked', toggle.indeterminate ? 'mixed' : ( toggle.checked ? 'true' : 'false' ) );
			toggle.disabled = caps.length > 0 && caps.every( isLocked );

			var item = toggle.closest( '.urm-check-item, .urm-card-header, th' );

			if ( item ) {
				item.classList.toggle( 'has-denied', caps.some( function ( cap ) {
					return capState( cap ) === 'd';
				} ) );
			}
		} );

		// Search and chip filter.
		var searching = S.search !== '';
		var chipActive = false;

		

		Array.prototype.forEach.call( content.querySelectorAll( '[data-search]' ), function ( node ) {
			var visible = true;

			if ( searching && node.getAttribute( 'data-search' ).indexOf( S.search ) === -1 ) {
				visible = false;
			}

			

			node.hidden = ! visible;
		} );

		

		Array.prototype.forEach.call( content.querySelectorAll( '.urm-card' ), function ( card ) {
			var sectionId = card.getAttribute( 'data-section' );
			var hasSearchRows = !! card.querySelector( '[data-search]' );

			if ( hasSearchRows ) {
				card.hidden = ( searching || chipActive ) && ! card.querySelector( '[data-search]:not([hidden])' );
			}

			if ( ! sectionId ) {
				return;
			}

			// Keep matches visible without writing that temporary open into the remembered state.
			applySectionOpen( card, sectionForcedOpen( card ) || isSectionOpen( sectionId ) );
		} );

		

		updateSaveBar();
	}

	/* Actions ------------------------------------------------------------- */

	function discardChanges() {
		confirmDialog( {
			title: i( 'discardTitle' ),
			message: i( 'discardMessage' ),
			confirmLabel: i( 'discard' ),
			danger: true
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}

			S.caps = clone( S.orig );
			S.meta = clone( S.origMeta );
			renderMain();
			speak( i( 'changesDiscarded' ) );
		} );
	}

	function capLine( cap ) {
		var info = capInfo( cap );

		if ( isSlugOnly( info ) ) {
			return h( 'li', {}, [ h( 'code', { text: cap } ) ] );
		}

		return h( 'li', {}, [ info.label, ' ', h( 'code', { text: cap } ) ] );
	}

	function openReviewDialog() {
		var diff = computeDiff();
		var changed = metaChanged();
		var content = h( 'div', { class: 'urm-review' } );

		function section( title, caps, kind ) {
			if ( ! caps.length ) {
				return;
			}

			var list = h( 'ul', { class: 'urm-plain-list' } );

			caps.forEach( function ( cap ) {
				list.appendChild( capLine( cap ) );
			} );

			content.appendChild( h( 'h3', { class: 'urm-review-' + kind, text: title + ' (' + caps.length + ')' } ) );
			content.appendChild( list );
		}

		if ( changed.name || changed.description ) {
			var details = h( 'ul', { class: 'urm-plain-list' } );

			if ( changed.name ) {
				details.appendChild( h( 'li', { text: f( 'reviewName', S.origMeta.name, S.meta.name ) } ) );
			}

			if ( changed.description ) {
				details.appendChild( h( 'li', { text: i( 'reviewDescription' ) } ) );
			}

			content.appendChild( h( 'h3', { text: i( 'reviewDetails' ) } ) );
			content.appendChild( details );
		}

		section( i( 'reviewGranted' ), diff.grant, 'granted' );
		section( i( 'reviewDenied' ), diff.deny, 'denied' );
		section( i( 'reviewRemoved' ), diff.unset, 'removed' );

		if ( ! content.firstChild ) {
			content.appendChild( h( 'p', { text: i( 'noPending' ) } ) );
		}

		openDialog( {
			title: f( 'reviewTitle', currentRole() ? currentRole().name : '' ),
			content: content,
			wide: true,
			buttons: [
				{ label: i( 'close' ) },
				{
					label: i( 'saveChanges' ),
					kind: 'primary',
					onClick: function ( close ) {
						close();
						saveChanges();
					}
				}
			].filter( function ( button, index ) {
				return index === 1 ? pendingCount() > 0 : true;
			} ).map( function ( button ) {
				if ( ! button.kind ) {
					button.onClick = function ( close ) {
						close();
					};
				}

				return button;
			} )
		} );
	}

	function saveChanges() {
		if ( S.saving || pendingCount() === 0 ) {
			return Promise.resolve();
		}

		var diff = computeDiff();
		var changed = metaChanged();
		var payload = { grant: diff.grant, deny: diff.deny, unset: diff.unset };

		if ( changed.name ) {
			payload.name = S.meta.name;
		}

		if ( changed.description ) {
			payload.description = S.meta.description;
		}

		S.saving = true;
		updateSaveBar();
		els.saveButton.textContent = i( 'saving' );

		return api( 'POST', '/roles/' + encodeURIComponent( S.currentSlug ), payload ).then( function ( role ) {
			S.saving = false;
			S.detail[ role.slug ] = role;
			replaceRoleOverview( role );
			setWorkingRole( role );
			renderSidebar();
			renderMain();
			notice( 'success', i( 'saved' ) );
		} ).catch( function ( error ) {
			S.saving = false;
			els.saveButton.textContent = i( 'saveChanges' );
			updateSaveBar();
			notice( 'error', errorMessage( error ) );
		} );
	}

	function setDefaultRole( role ) {
		api( 'POST', '/default-role', { slug: role.slug } ).then( function ( result ) {
			S.defaultRole = result.defaultRole;
			S.roles = S.roles.map( function ( existing ) {
				return Object.assign( {}, existing, { isDefault: existing.slug === result.defaultRole } );
			} );
			renderMain();
			notice( 'success', f( 'defaultSet', role.name ) );
		} ).catch( function ( error ) {
			notice( 'error', errorMessage( error ) );
		} );
	}

	function resetRole( role ) {
		confirmDialog( {
			title: f( 'resetTitle', role.name ),
			message: i( 'resetMessage' ),
			confirmLabel: i( 'actionReset' ),
			danger: true
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return null;
			}

			return api( 'POST', '/roles/' + encodeURIComponent( role.slug ) + '/reset' ).then( function () {
				return reloadState( role.slug );
			} ).then( function () {
				notice( 'success', f( 'resetDone', role.name ) );
			} );
		} ).catch( function ( error ) {
			notice( 'error', errorMessage( error ) );
		} );
	}

	function roleSelect( excludeSlug, selected ) {
		var select = h( 'select' );

		S.roles.forEach( function ( role ) {
			if ( role.slug === excludeSlug ) {
				return;
			}

			select.appendChild( h( 'option', { value: role.slug, text: role.name + ' (' + role.slug + ')', selected: role.slug === selected } ) );
		} );

		return select;
	}

	function openDeleteDialog( role ) {
		var reassign = roleSelect( role.slug, S.defaultRole !== role.slug ? S.defaultRole : 'subscriber' );
		var content = h( 'div', {}, [
			h( 'p', { text: f( 'deleteMessage', role.name, role.users ) } ),
			field( i( 'deleteReassign' ), reassign, i( 'deleteReassignHelp' ) )
		] );

		openDialog( {
			title: f( 'deleteTitle', role.name ),
			content: content,
			buttons: [
				{ label: i( 'cancel' ) },
				{
					label: i( 'actionDelete' ),
					kind: 'danger',
					onClick: function ( close ) {
						api( 'POST', '/roles/' + encodeURIComponent( role.slug ) + '/delete', { reassign_to: reassign.value } ).then( function () {
							close();
							S.currentSlug = null;
							return reloadState( reassign.value );
						} ).then( function () {
							notice( 'success', f( 'deleteDone', role.name ) );
						} ).catch( function ( error ) {
							notice( 'error', errorMessage( error ) );
						} );
					}
				}
			].map( function ( button ) {
				if ( ! button.kind ) {
					button.onClick = function ( close ) {
						close();
					};
				}

				return button;
			} )
		} );
	}

	/**
	 * Dialog to create a role, optionally from a preset or an existing role.
	 *
	 * @param {Object} options cloneFrom.
	 */
	function openRoleDialog( options ) {
		options = options || {};

		var source = options.cloneFrom ? S.roles.find( function ( role ) {
			return role.slug === options.cloneFrom;
		} ) : null;
		var slugTouched = false;
		var nameInput = h( 'input', { type: 'text', maxlength: '80', required: true, value: source ? f( 'cloneName', source.name ) : '' } );
		var slugInput = h( 'input', { type: 'text', maxlength: '40', required: true, pattern: '[a-z][a-z0-9_\\-]{1,39}', value: source ? slugify( f( 'cloneName', source.name ) ) : '' } );
		var descriptionInput = h( 'textarea', { rows: '2', maxlength: '300' } );
		var startFrom = h( 'select' );

		startFrom.appendChild( h( 'option', { value: '', text: i( 'startEmpty' ) } ) );

		[ 'subscriber', 'contributor', 'author', 'editor' ].forEach( function ( preset ) {
			startFrom.appendChild( h( 'option', { value: 'preset:' + preset, text: f( 'startPreset', presetLabel( preset ) ) } ) );
		} );

		S.roles.forEach( function ( role ) {
			startFrom.appendChild( h( 'option', { value: 'clone:' + role.slug, text: f( 'startClone', role.name ), selected: source && source.slug === role.slug } ) );
		} );

		nameInput.addEventListener( 'input', function () {
			if ( ! slugTouched ) {
				slugInput.value = slugify( nameInput.value );
			}
		} );
		slugInput.addEventListener( 'input', function () {
			slugTouched = true;
		} );

		var content = h( 'div', {}, [
			field( i( 'roleName' ), nameInput ),
			field( i( 'roleSlug' ), slugInput, i( 'roleSlugHelp' ) ),
			field( i( 'roleDescription' ), descriptionInput ),
			field( i( 'startFrom' ), startFrom, i( 'startFromHelp' ) )
		] );

		openDialog( {
			title: source ? f( 'cloneTitle', source.name ) : i( 'addRoleTitle' ),
			content: content,
			buttons: [
				{
					label: i( 'cancel' ),
					onClick: function ( close ) {
						close();
					}
				},
				{
					label: source ? i( 'actionClone' ) : i( 'addRole' ),
					kind: 'primary',
					onClick: function ( close, button ) {
						var payload = { name: nameInput.value, slug: slugInput.value, description: descriptionInput.value };

						if ( startFrom.value.indexOf( 'preset:' ) === 0 ) {
							payload.preset = startFrom.value.slice( 7 );
						} else if ( startFrom.value.indexOf( 'clone:' ) === 0 ) {
							payload.clone_from = startFrom.value.slice( 6 );
						}

						button.disabled = true;

						api( 'POST', '/roles', payload ).then( function ( role ) {
							close();
							return reloadState( role.slug ).then( function () {
								notice( 'success', f( 'roleCreated', role.name ) );
							} );
						} ).catch( function ( error ) {
							button.disabled = false;
							notice( 'error', errorMessage( error ) );
						} );
					}
				}
			]
		} );
	}

	function presetLabel( slug ) {
		return i( 'preset_' + slug );
	}

	

	

	/* Boot ---------------------------------------------------------------- */

	function boot() {
		buildIndex();

		var addButton = h( 'button', {
			type: 'button',
			class: 'button button-primary urm-add-role',
			text: i( 'addRole' ),
			onclick: function () {
				openRoleDialog();
			}
		} );

		els.roleList = h( 'ul', { class: 'urm-role-list' } );
		els.main = h( 'section', { class: 'urm-main', 'aria-live': 'off' } );

		var sidebarActions = h( 'div', { class: 'urm-sidebar-actions' }, [ addButton ] );
		var sidebarHead = h( 'div', { class: 'urm-sidebar-head' }, [ h( 'h2', { text: i( 'rolesLabel' ) } ), sidebarActions ] );
		var sidebar = h( 'aside', { class: 'urm-sidebar', 'aria-label': i( 'rolesLabel' ) }, [
			sidebarHead,
			els.roleList
		] );

		empty( root );
		root.appendChild( h( 'div', { class: 'urm-layout' }, [ sidebar, els.main ] ) );

		// Let extensions (e.g. the Pro bulk actions) add controls to the sidebar. Deferred so scripts loaded after this one can listen.
		window.setTimeout( function () {
			document.dispatchEvent( new CustomEvent( 'asenhaUserRoleManagerSidebar', {
				detail: {
					head: sidebarHead,
					actions: sidebarActions,
					getRoles: function () {
						return S.roles;
					},
					reload: reloadState
				}
			} ) );
		}, 0 );

		renderSidebar();

		var params = new URLSearchParams( window.location.search );
		var initial = params.get( 'role' );

		if ( ! S.roles.some( function ( role ) {
			return role.slug === initial;
		} ) ) {
			initial = S.roles.length ? S.roles[ 0 ].slug : null;
		}

		if ( initial ) {
			selectRole( initial, true );
		} else {
			renderMain();
		}

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( S.currentSlug && pendingCount() > 0 ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		// Available as soon as boot() returns, so the Pro script can mount Bulk actions even if the deferred event already fired.
		window.asenhaUserRoleManagerKit.sidebar = {
			head: sidebarHead,
			actions: sidebarActions,
			getRoles: function () {
				return S.roles;
			},
			reload: reloadState
		};
	}

	boot();
}() );
