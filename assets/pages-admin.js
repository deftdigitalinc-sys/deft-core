/**
 * The Deft pages screen: list, one page (draft, HTML, history), a new page, and the Move off Elementor board, with a
 * side-by-side compare. Plain DOM on top of the deft/v1 REST routes; window.deftPages comes from pages-admin.php.
 */
( function () {
	'use strict';

	var cfg = window.deftPages;
	var app = document.getElementById( 'deft-pages-app' );
	if ( ! cfg || ! app ) {
		return;
	}

	/* ---------- Helpers ---------- */

	function h( tag, attrs, children ) {
		var el = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( 'class' === key ) {
				el.className = value;
			} else if ( 'text' === key ) {
				el.textContent = value;
			} else if ( 0 === key.indexOf( 'on' ) ) {
				el.addEventListener( key.slice( 2 ), value );
			} else {
				el.setAttribute( key, true === value ? '' : value );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( null !== child && undefined !== child && false !== child ) {
				el.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
			}
		} );
		return el;
	}

	function api( path, options ) {
		options = options || {};
		return fetch( cfg.root + path, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: options.body ? JSON.stringify( options.body ) : undefined
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					var error = new Error( data.message || 'Something went wrong (' + response.status + ').' );
					error.status = response.status;
					throw error;
				}
				return data;
			} );
		} );
	}

	function ago( iso ) {
		if ( ! iso ) {
			return '';
		}
		var mins = Math.round( ( Date.now() - new Date( iso ).getTime() ) / 60000 );
		if ( mins < 1 ) {
			return 'just now';
		}
		if ( mins < 60 ) {
			return mins + ' min ago';
		}
		if ( mins < 1440 ) {
			return Math.round( mins / 60 ) + ' h ago';
		}
		return new Date( iso ).toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: mins > 525600 ? 'numeric' : undefined } );
	}

	function kb( bytes ) {
		return bytes > 1024 ? Math.round( bytes / 1024 ) + ' KB' : bytes + ' B';
	}

	function go( params ) {
		var url = new URL( cfg.screen );
		Object.keys( params || {} ).forEach( function ( key ) {
			url.searchParams.set( key, params[ key ] );
		} );
		window.history.pushState( null, '', url.toString() );
		route();
	}

	function link( text, params, cls ) {
		var url = new URL( cfg.screen );
		Object.keys( params || {} ).forEach( function ( key ) {
			url.searchParams.set( key, params[ key ] );
		} );
		return h( 'a', { href: url.toString(), class: cls, onclick: function ( e ) {
			if ( ! e.metaKey && ! e.ctrlKey ) {
				e.preventDefault();
				go( params );
			}
		} }, [ text ] );
	}

	var noticeTimer;
	function notice( text, tone ) {
		var bar = document.querySelector( '.dp-notice' ) || document.body.appendChild( h( 'div', { class: 'dp-notice', role: 'status' } ) );
		bar.textContent = text;
		bar.className = 'dp-notice show ' + ( tone || '' );
		clearTimeout( noticeTimer );
		noticeTimer = setTimeout( function () {
			bar.className = 'dp-notice';
		}, 'bad' === tone ? 9000 : 5000 );
	}

	function busy( button, text ) {
		var before = button.textContent;
		button.disabled = true;
		button.textContent = text;
		return function () {
			button.disabled = false;
			button.textContent = before;
		};
	}

	var SOURCE = { 'live-edit': 'Live Edit', studio: 'Studio', restore: 'Restored', migration: 'Moved off Elementor', admin: 'This screen' };

	function chrome( active, children ) {
		app.textContent = '';
		app.appendChild( h( 'header', { class: 'dp-top' }, [
			h( 'div', { class: 'dp-brand' }, [ h( 'span', { class: 'dp-mark', 'aria-hidden': 'true' } ), h( 'div', {}, [ h( 'p', { class: 'dp-eyebrow', text: cfg.site } ), h( 'p', { class: 'dp-title', text: 'Deft pages' } ) ] ) ] ),
			h( 'nav', { class: 'dp-tabs', 'aria-label': 'Deft pages' }, [
				link( 'Pages', {}, 'dp-tab' + ( 'pages' === active ? ' on' : '' ) ),
				link( 'Move off Elementor', { view: 'migrate' }, 'dp-tab' + ( 'migrate' === active ? ' on' : '' ) )
			] ),
			h( 'div', { class: 'dp-top-actions' }, [ link( 'New page', { view: 'new' }, 'dp-btn primary' ) ] )
		] ) );
		var main = h( 'main', { class: 'dp-main' }, children );
		app.appendChild( main );
		return main;
	}

	/* ---------- Pages list ---------- */

	function viewList() {
		var main = chrome( 'pages', [ h( 'p', { class: 'dp-loading', text: 'Loading pages…' } ) ] );
		api( '/pages' ).then( function ( data ) {
			main.textContent = '';
			var flags = h( 'div', { class: 'dp-flags' }, [
				flag( 'Live Edit', cfg.liveEdit ),
				flag( 'Studio publishing', cfg.studio ),
				h( 'a', { href: cfg.settings, class: 'dp-link', text: 'Settings' } )
			] );
			main.appendChild( h( 'div', { class: 'dp-head' }, [ h( 'h2', { text: data.pages.length ? data.pages.length + ' Deft ' + ( 1 === data.pages.length ? 'page' : 'pages' ) : 'No Deft pages yet' } ), flags ] ) );
			if ( ! data.pages.length ) {
				main.appendChild( h( 'div', { class: 'dp-empty' }, [
					h( 'p', { text: 'Deft pages are stored as plain HTML and shown without Elementor. Publish one from Studio, paste HTML into a new page, or move an Elementor page over.' } ),
					h( 'div', { class: 'dp-row' }, [ link( 'New page', { view: 'new' }, 'dp-btn primary' ), link( 'Move off Elementor', { view: 'migrate' }, 'dp-btn' ) ] )
				] ) );
				return;
			}
			var grid = h( 'div', { class: 'dp-list' } );
			data.pages.forEach( function ( page ) {
				grid.appendChild( h( 'article', { class: 'dp-card' }, [
					h( 'div', { class: 'dp-card-top' }, [
						h( 'h3', {}, [ link( page.title || '(no title)', { post: page.id } ) ] ),
						h( 'div', { class: 'dp-badges' }, [
							page.front ? h( 'span', { class: 'dp-badge', text: 'Home page' } ) : null,
							'publish' === page.status ? h( 'span', { class: 'dp-badge good', text: 'Live' } ) : h( 'span', { class: 'dp-badge', text: 'Not published' } ),
							page.draft ? h( 'span', { class: 'dp-badge warn', text: 'Draft waiting' } ) : null
						] )
					] ),
					h( 'p', { class: 'dp-url', text: page.url.replace( /^https?:\/\//, '' ) } ),
					h( 'p', { class: 'dp-meta', text: page.published ? 'Went live ' + ago( page.published ) : 'Never went live' } ),
					h( 'div', { class: 'dp-row' }, [
						link( 'Open', { post: page.id }, 'dp-btn small' ),
						'publish' === page.status ? h( 'a', { class: 'dp-btn small ghost', href: page.url, target: '_blank', rel: 'noopener', text: 'View' } ) : null,
						cfg.liveEdit && 'publish' === page.status ? h( 'a', { class: 'dp-btn small ghost', href: page.url + ( page.url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'deft_edit=1', target: '_blank', rel: 'noopener', text: 'Live Edit' } ) : null
					] )
				] ) );
			} );
			main.appendChild( grid );
		} ).catch( fail( main ) );
	}

	function flag( label, on ) {
		return h( 'span', { class: 'dp-flag' + ( on ? ' on' : '' ) }, [ h( 'span', { class: 'dp-dot' } ), label + ( on ? ' on' : ' off' ) ] );
	}

	function fail( main ) {
		return function ( error ) {
			main.textContent = '';
			main.appendChild( h( 'div', { class: 'dp-empty bad' }, [ h( 'p', { text: error.message } ) ] ) );
		};
	}

	/* ---------- One page ---------- */

	var editor = null;

	function codeArea( value ) {
		var area = h( 'textarea', { class: 'dp-code', spellcheck: 'false', 'aria-label': 'Page HTML' } );
		area.value = value || '';
		setTimeout( function () {
			if ( cfg.editor && window.wp && window.wp.codeEditor ) {
				editor = window.wp.codeEditor.initialize( area, cfg.editor );
			} else {
				editor = null;
			}
		}, 0 );
		return area;
	}

	function codeValue( area ) {
		return editor && editor.codemirror ? editor.codemirror.getValue() : area.value;
	}

	function viewPage( id ) {
		var main = chrome( 'pages', [ h( 'p', { class: 'dp-loading', text: 'Loading the page…' } ) ] );
		Promise.all( [ api( '/pages/' + id ), api( '/pages/' + id + '/revisions' ) ] ).then( function ( results ) {
			var page = results[ 0 ], revisions = results[ 1 ].revisions;
			main.textContent = '';
			main.appendChild( h( 'p', { class: 'dp-crumb' }, [ link( '← All Deft pages', {} ) ] ) );
			main.appendChild( h( 'div', { class: 'dp-head' }, [
				h( 'div', {}, [
					h( 'h2', { text: page.title } ),
					h( 'p', { class: 'dp-url' }, [ h( 'a', { href: page.url, target: '_blank', rel: 'noopener', text: page.url.replace( /^https?:\/\//, '' ) } ), ' · ' + ( cfg.templates[ page.template ] || page.template ) ] )
				] ),
				h( 'div', { class: 'dp-row' }, [
					'publish' === page.status ? h( 'a', { class: 'dp-btn ghost', href: page.url, target: '_blank', rel: 'noopener', text: 'View live' } ) : null,
					cfg.liveEdit && 'publish' === page.status ? h( 'a', { class: 'dp-btn', href: page.url + ( page.url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'deft_edit=1', target: '_blank', rel: 'noopener', text: 'Live Edit' } ) : null
				] )
			] ) );

			var cols = h( 'div', { class: 'dp-cols' } );
			var left = h( 'div', { class: 'dp-col' } );
			var right = h( 'div', { class: 'dp-col narrow' } );
			cols.appendChild( left );
			cols.appendChild( right );
			main.appendChild( cols );

			if ( page.draft ) {
				left.appendChild( draftCard( page ) );
			}

			// HTML editor: edits the draft if there is one, otherwise starts a draft from the live markup.
			var source = page.draft ? page.draft.html : page.html;
			var area = codeArea( source );
			var head = h( 'textarea', { class: 'dp-code small', spellcheck: 'false', 'aria-label': 'Extra head markup' } );
			head.value = ( page.draft ? page.draft.head : page.head ) || '';
			var saveDraft = h( 'button', { class: 'dp-btn', type: 'button', text: 'Save as draft' } );
			var saveLive = h( 'button', { class: 'dp-btn primary', type: 'button', text: 'Save and go live' } );
			saveDraft.addEventListener( 'click', function () {
				var done = busy( saveDraft, 'Saving…' );
				api( '/pages/' + id + '/draft', { method: 'POST', body: { html: codeValue( area ), head: head.value, base: page.hash, source: 'admin' } } ).then( function ( r ) {
					notice( r.stale ? 'Draft saved. The live page changed since you opened it, so compare before going live.' : 'Draft saved.', r.stale ? 'warn' : 'good' );
					viewPage( id );
				} ).catch( function ( e ) {
					done();
					notice( e.message, 'bad' );
				} );
			} );
			saveLive.addEventListener( 'click', function () {
				var done = busy( saveLive, 'Going live…' );
				api( '/pages/' + id + '/publish', { method: 'POST', body: { html: codeValue( area ), head: head.value, base: page.hash, source: 'admin' } } ).then( function ( r ) {
					notice( 'Live. Cleared: ' + r.cleared.join( ', ' ) + '.', 'good' );
					viewPage( id );
				} ).catch( function ( e ) {
					done();
					notice( e.message, 'bad' );
				} );
			} );
			left.appendChild( h( 'section', { class: 'dp-panel' }, [
				h( 'div', { class: 'dp-panel-head' }, [ h( 'h3', { text: 'HTML' } ), h( 'p', { class: 'dp-meta', text: page.draft ? 'Editing the waiting draft' : 'Editing a copy of the live page' } ) ] ),
				area,
				h( 'details', { class: 'dp-details' }, [ h( 'summary', { text: 'Extra <head> markup (fonts, meta tags)' } ), head ] ),
				h( 'div', { class: 'dp-row end' }, [ saveDraft, saveLive ] )
			] ) );

			right.appendChild( historyPanel( page, revisions ) );
			right.appendChild( h( 'section', { class: 'dp-panel' }, [
				h( 'h3', { text: 'Editable parts' } ),
				h( 'p', { class: 'dp-meta', text: page.outline.length + ' elements can be changed with Live Edit.' } ),
				h( 'ol', { class: 'dp-outline' }, page.outline.slice( 0, 40 ).map( function ( item ) {
					return h( 'li', {}, [ h( 'span', { class: 'dp-tag', text: item.tag } ), ' ' + ( item.text || item.alt || item.src || item.href || '' ).slice( 0, 80 ) ] );
				} ) )
			] ) );
		} ).catch( fail( main ) );
	}

	function draftCard( page ) {
		var live = h( 'button', { class: 'dp-btn primary', type: 'button', text: 'Go live' } );
		var discard = h( 'button', { class: 'dp-btn ghost', type: 'button', text: 'Throw away draft' } );
		live.addEventListener( 'click', function () {
			var done = busy( live, 'Going live…' );
			api( '/pages/' + page.id + '/publish', { method: 'POST', body: { source: 'admin' } } ).then( function () {
				notice( 'The draft is live.', 'good' );
				viewPage( page.id );
			} ).catch( function ( e ) {
				done();
				notice( e.message, 'bad' );
			} );
		} );
		discard.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Throw away this draft? The live page stays as it is.' ) ) {
				return;
			}
			api( '/pages/' + page.id + '/draft', { method: 'DELETE' } ).then( function () {
				notice( 'Draft thrown away.' );
				viewPage( page.id );
			} ).catch( function ( e ) {
				notice( e.message, 'bad' );
			} );
		} );
		return h( 'section', { class: 'dp-panel dp-draft' }, [
			h( 'div', { class: 'dp-panel-head' }, [
				h( 'h3', { text: 'Draft waiting' } ),
				h( 'p', { class: 'dp-meta', text: 'Saved ' + ago( page.draft.saved ) + ' by ' + page.draft.by + ( page.draft.note ? ' · ' + page.draft.note : '' ) } )
			] ),
			page.draft.base && page.hash && page.draft.base !== page.hash ? h( 'p', { class: 'dp-warn', text: 'The live page changed after this draft was started. Compare before going live.' } ) : null,
			h( 'div', { class: 'dp-row' }, [
				h( 'button', { class: 'dp-btn', type: 'button', text: 'Compare with live', onclick: function () {
					compare( page.title, page.url, page.draft.preview, 'publish' === page.status );
				} } ),
				h( 'a', { class: 'dp-btn ghost', href: page.draft.preview, target: '_blank', rel: 'noopener', text: 'Preview' } ),
				h( 'span', { class: 'dp-grow' } ),
				discard,
				live
			] )
		] );
	}

	function historyPanel( page, revisions ) {
		var list = h( 'ol', { class: 'dp-timeline' } );
		if ( ! revisions.length ) {
			list.appendChild( h( 'li', { class: 'dp-meta', text: 'Nothing has gone live yet.' } ) );
		}
		revisions.forEach( function ( rev, index ) {
			var current = 0 === index && rev.hash === page.hash;
			var actions = h( 'div', { class: 'dp-row' } );
			if ( ! current ) {
				actions.appendChild( h( 'button', { class: 'dp-btn tiny ghost', type: 'button', text: 'Restore', onclick: function ( e ) {
					if ( ! window.confirm( 'Put this version back live? The current one stays in the history.' ) ) {
						return;
					}
					var done = busy( e.target, 'Restoring…' );
					api( '/pages/' + page.id + '/restore', { method: 'POST', body: { revision: rev.id } } ).then( function () {
						notice( 'Restored.', 'good' );
						viewPage( page.id );
					} ).catch( function ( err ) {
						done();
						notice( err.message, 'bad' );
					} );
				} } ) );
			}
			list.appendChild( h( 'li', { class: current ? 'on' : '' }, [
				h( 'p', { class: 'dp-rev-head' }, [ h( 'b', { text: ago( rev.created ) } ), ' · ' + rev.actor + ' ', h( 'span', { class: 'dp-tag', text: SOURCE[ rev.source ] || rev.source } ), current ? h( 'span', { class: 'dp-tag good', text: 'Live now' } ) : null ] ),
				rev.note ? h( 'p', { class: 'dp-meta', text: rev.note } ) : null,
				actions
			] ) );
		} );
		return h( 'section', { class: 'dp-panel' }, [ h( 'h3', { text: 'History' } ), h( 'p', { class: 'dp-meta', text: 'Every version that went live, kept 30 days.' } ), list ] );
	}

	/* ---------- New page ---------- */

	function viewNew() {
		var title = h( 'input', { type: 'text', class: 'dp-input', placeholder: 'Shower doors', 'aria-label': 'Title', required: true } );
		var slug = h( 'input', { type: 'text', class: 'dp-input', placeholder: 'shower-doors (made from the title if empty)', 'aria-label': 'Address' } );
		var template = h( 'select', { class: 'dp-input', 'aria-label': 'Template' }, Object.keys( cfg.templates ).map( function ( key ) {
			return h( 'option', { value: key, text: cfg.templates[ key ] } );
		} ) );
		var area = codeArea( '' );
		var create = h( 'button', { class: 'dp-btn primary', type: 'submit', text: 'Create draft' } );
		var form = h( 'form', { class: 'dp-panel dp-form' }, [
			h( 'label', {}, [ h( 'span', { text: 'Title' } ), title ] ),
			h( 'div', { class: 'dp-row two' }, [ h( 'label', {}, [ h( 'span', { text: 'Address' } ), slug ] ), h( 'label', {}, [ h( 'span', { text: 'Template' } ), template ] ) ] ),
			h( 'label', {}, [ h( 'span', { text: 'HTML' } ), area ] ),
			h( 'p', { class: 'dp-meta', text: 'The page starts as a draft with a preview link. Nothing is public until you go live.' } ),
			h( 'div', { class: 'dp-row end' }, [ create ] )
		] );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var done = busy( create, 'Creating…' );
			api( '/pages', { method: 'POST', body: { title: title.value, slug: slug.value, template: template.value, html: codeValue( area ) } } ).then( function ( page ) {
				notice( 'Draft created.', 'good' );
				go( { post: page.id } );
			} ).catch( function ( err ) {
				done();
				notice( err.message, 'bad' );
			} );
		} );
		chrome( 'pages', [ h( 'p', { class: 'dp-crumb' }, [ link( '← All Deft pages', {} ) ] ), h( 'div', { class: 'dp-head' }, [ h( 'h2', { text: 'New Deft page' } ) ] ), form ] );
		title.focus();
	}

	/* ---------- Move off Elementor ---------- */

	var COLUMNS = [
		{ key: 'elementor', title: 'On Elementor', hint: 'HTML widgets only. Ready to lift out.' },
		{ key: 'converted', title: 'Converted', hint: 'A Deft draft is waiting. Compare, then go live.' },
		{ key: 'live', title: 'Live as Deft page', hint: 'Elementor no longer draws these.' },
		{ key: 'attention', title: 'Needs Studio', hint: 'Uses other Elementor widgets.' }
	];

	function viewMigrate( board ) {
		var main = chrome( 'migrate', [ h( 'p', { class: 'dp-loading', text: 'Reading the Elementor pages…' } ) ] );
		( board ? Promise.resolve( board ) : api( '/migration' ) ).then( function ( data ) {
			main.textContent = '';
			var total = data.pages.length;
			var done = data.count.live;
			main.appendChild( h( 'div', { class: 'dp-head' }, [
				h( 'div', {}, [ h( 'h2', { text: 'Move off Elementor' } ), h( 'p', { class: 'dp-meta', text: total ? done + ' of ' + total + ' pages moved' : 'No Elementor pages on this site.' } ) ] ),
				h( 'div', { class: 'dp-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': total, 'aria-valuenow': done }, [ h( 'span', { style: 'width:' + ( total ? Math.round( 100 * done / total ) : 0 ) + '%' } ) ] )
			] ) );
			main.appendChild( finishLine( data ) );
			var lanes = h( 'div', { class: 'dp-board' } );
			COLUMNS.forEach( function ( col ) {
				var pages = data.pages.filter( function ( p ) {
					return p.status === col.key;
				} );
				lanes.appendChild( h( 'section', { class: 'dp-lane lane-' + col.key }, [
					h( 'header', {}, [ h( 'h3', {}, [ col.title, h( 'span', { class: 'dp-count', text: String( pages.length ) } ) ] ), h( 'p', { class: 'dp-meta', text: col.hint } ) ] ),
					h( 'div', { class: 'dp-lane-body' }, pages.length ? pages.map( migrateCard ) : [ h( 'p', { class: 'dp-lane-empty', text: 'Nothing here.' } ) ] )
				] ) );
			} );
			main.appendChild( lanes );
		} ).catch( fail( main ) );
	}

	function finishLine( data ) {
		var e = data.elementor;
		if ( e.removable ) {
			return h( 'div', { class: 'dp-finish ready' }, [ h( 'b', { text: 'Every page is off Elementor.' } ), ' Take a backup, then deactivate Elementor' + ( e.pro ? ' and Elementor Pro' : '' ) + ' and check the site. Uninstall once it looks right.' ] );
		}
		var parts = [];
		if ( data.blockers.length ) {
			parts.push( h( 'p', {}, [ h( 'b', { text: 'Also still using Elementor: ' } ), data.blockers.join( '; ' ) + '.' ] ) );
		}
		if ( ! e.active ) {
			parts.push( h( 'p', { text: 'Elementor isn\'t active on this site. Pages still read their saved Elementor layout, so converting works the same.' } ) );
		}
		return parts.length ? h( 'div', { class: 'dp-finish' }, parts ) : h( 'span' );
	}

	function migrateCard( page ) {
		var actions = h( 'div', { class: 'dp-row' } );
		function act( label, path, done ) {
			return h( 'button', { class: 'dp-btn small' + ( 'Go live' === label || 'Convert' === label ? ' primary' : ' ghost' ), type: 'button', text: label, onclick: function ( e ) {
				var release = busy( e.target, label + '…' );
				api( '/migration/' + page.id + '/' + path, { method: 'POST' } ).then( function ( r ) {
					notice( done, 'good' );
					if ( r.warnings && r.warnings.length ) {
						notice( done + ' ' + r.warnings[ 0 ], 'warn' );
					}
					viewMigrate( r.board );
				} ).catch( function ( err ) {
					release();
					notice( err.message, 'bad' );
				} );
			} } );
		}
		if ( 'elementor' === page.status ) {
			actions.appendChild( act( 'Convert', 'convert', '"' + page.title + '" converted. Compare it with the live page.' ) );
		} else if ( 'converted' === page.status ) {
			actions.appendChild( h( 'button', { class: 'dp-btn small', type: 'button', text: 'Compare', onclick: function () {
				compare( page.title, page.url, page.preview, 'publish' === page.wpstatus );
			} } ) );
			actions.appendChild( act( 'Go live', 'live', '"' + page.title + '" is live as a Deft page.' ) );
			actions.appendChild( act( 'Undo', 'back', 'Draft thrown away.' ) );
		} else if ( 'live' === page.status ) {
			actions.appendChild( link( 'Open', { post: page.id }, 'dp-btn small ghost' ) );
			actions.appendChild( act( 'Back to Elementor', 'back', '"' + page.title + '" is back on Elementor.' ) );
		}
		var other = Object.keys( page.other || {} );
		return h( 'article', { class: 'dp-mcard' }, [
			h( 'div', { class: 'dp-card-top' }, [ h( 'h4', { text: page.title || '(no title)' } ), page.front ? h( 'span', { class: 'dp-badge', text: 'Home' } ) : null ] ),
			h( 'p', { class: 'dp-url', text: page.url.replace( /^https?:\/\//, '' ) } ),
			h( 'p', { class: 'dp-meta' }, [
				null !== page.widgets ? page.widgets + ' HTML ' + ( 1 === page.widgets ? 'widget' : 'widgets' ) + ' · ' : '',
				kb( page.bytes ),
				page.moved ? ' · moved ' + ago( page.moved ) : ''
			] ),
			other.length ? h( 'p', { class: 'dp-warn', text: 'Also uses: ' + other.map( function ( k ) {
				return k + ( page.other[ k ] > 1 ? ' ×' + page.other[ k ] : '' );
			} ).join( ', ' ) } ) : null,
			( page.warnings || [] ).filter( function () {
				return ! other.length;
			} ).map( function ( w ) {
				return h( 'p', { class: 'dp-note', text: w } );
			} )[ 0 ] || null,
			actions
		] );
	}

	/* ---------- Compare: live page and draft, one over the other ---------- */

	function compare( title, liveUrl, draftUrl, isLive ) {
		var widths = { Phone: 390, Tablet: 820, Desktop: 1440 };
		function framed( url ) {
			return url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'deft_frame=1';
		}
		var width = window.innerWidth < 700 ? 390 : 1440;
		var split = 50;
		var overlay = h( 'div', { class: 'dp-compare', role: 'dialog', 'aria-label': 'Compare ' + title } );
		var stage = h( 'div', { class: 'dp-stage' } );
		var frameWrap = h( 'div', { class: 'dp-frames' } );
		var before = h( 'iframe', { title: 'Live page', src: isLive ? framed( liveUrl ) : 'about:blank', loading: 'eager' } );
		var after = h( 'iframe', { title: 'Draft', src: framed( draftUrl ), loading: 'eager' } );
		var afterClip = h( 'div', { class: 'dp-after' }, [ after ] );
		var handle = h( 'div', { class: 'dp-handle', role: 'slider', tabindex: '0', 'aria-label': 'Split between live and draft', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': split }, [ h( 'span', { class: 'dp-grip' } ) ] );
		frameWrap.appendChild( before );
		frameWrap.appendChild( afterClip );
		frameWrap.appendChild( handle );
		frameWrap.appendChild( h( 'span', { class: 'dp-side left', text: isLive ? 'Live now' : 'Not published yet' } ) );
		frameWrap.appendChild( h( 'span', { class: 'dp-side right', text: 'Draft' } ) );
		stage.appendChild( frameWrap );

		var sizeButtons = Object.keys( widths ).map( function ( name ) {
			return h( 'button', { type: 'button', class: 'dp-seg' + ( widths[ name ] === width ? ' on' : '' ), text: name, onclick: function ( e ) {
				width = widths[ name ];
				sizeButtons.forEach( function ( b ) {
					b.classList.toggle( 'on', b === e.target );
				} );
				layout();
			} } );
		} );
		var modeSlider = h( 'button', { type: 'button', class: 'dp-seg on', text: 'Slider' } );
		var modeBlink = h( 'button', { type: 'button', class: 'dp-seg', text: 'Flip' } );
		var blinkTimer = null;
		modeSlider.addEventListener( 'click', function () {
			clearInterval( blinkTimer );
			modeSlider.classList.add( 'on' );
			modeBlink.classList.remove( 'on' );
			setSplit( 50 );
		} );
		modeBlink.addEventListener( 'click', function () {
			modeBlink.classList.add( 'on' );
			modeSlider.classList.remove( 'on' );
			var showDraft = true;
			clearInterval( blinkTimer );
			blinkTimer = setInterval( function () {
				showDraft = ! showDraft;
				setSplit( showDraft ? 0 : 100 );
			}, 900 );
			setSplit( 0 );
		} );

		overlay.appendChild( h( 'header', { class: 'dp-compare-top' }, [
			h( 'div', {}, [ h( 'p', { class: 'dp-eyebrow', text: 'Compare' } ), h( 'p', { class: 'dp-title', text: title } ) ] ),
			h( 'div', { class: 'dp-segs' }, sizeButtons ),
			h( 'div', { class: 'dp-segs' }, [ modeSlider, modeBlink ] ),
			h( 'a', { class: 'dp-btn ghost small', href: draftUrl, target: '_blank', rel: 'noopener', text: 'Open draft' } ),
			h( 'button', { class: 'dp-btn small', type: 'button', text: 'Close', onclick: close } )
		] ) );
		overlay.appendChild( stage );
		document.body.appendChild( overlay );
		document.body.classList.add( 'dp-noscroll' );
		var bar = document.querySelector( '.dp-notice' );
		if ( bar ) {
			bar.className = 'dp-notice';
		}

		function setSplit( value ) {
			split = Math.max( 0, Math.min( 100, value ) );
			afterClip.style.clipPath = 'inset(0 0 0 ' + split + '%)';
			handle.style.left = split + '%';
			handle.setAttribute( 'aria-valuenow', Math.round( split ) );
		}

		function layout() {
			var room = stage.clientWidth - 32;
			var scale = Math.min( 1, room / width );
			var height = ( stage.clientHeight - 32 ) / scale;
			[ before, after ].forEach( function ( f ) {
				f.style.width = width + 'px';
				f.style.height = height + 'px';
				f.style.transform = 'scale(' + scale + ')';
			} );
			frameWrap.style.width = Math.round( width * scale ) + 'px';
			frameWrap.style.height = Math.round( height * scale ) + 'px';
		}

		function drag( e ) {
			var rect = frameWrap.getBoundingClientRect();
			var x = ( e.touches ? e.touches[ 0 ].clientX : e.clientX ) - rect.left;
			setSplit( 100 * x / rect.width );
		}
		var dragging = false;
		frameWrap.addEventListener( 'pointerdown', function ( e ) {
			dragging = true;
			frameWrap.setPointerCapture( e.pointerId );
			drag( e );
		} );
		frameWrap.addEventListener( 'pointermove', function ( e ) {
			if ( dragging ) {
				drag( e );
			}
		} );
		frameWrap.addEventListener( 'pointerup', function () {
			dragging = false;
		} );
		handle.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowLeft' === e.key || 'ArrowRight' === e.key ) {
				e.preventDefault();
				setSplit( split + ( 'ArrowLeft' === e.key ? -5 : 5 ) );
			}
		} );

		// Scroll both pages together, so the same part of the page is always side by side.
		function syncScroll( from, to ) {
			try {
				from.contentWindow.addEventListener( 'scroll', function () {
					if ( to.contentWindow && Math.abs( to.contentWindow.scrollY - from.contentWindow.scrollY ) > 1 ) {
						to.contentWindow.scrollTo( 0, from.contentWindow.scrollY );
					}
				}, { passive: true } );
			} catch ( err ) {}
		}
		before.addEventListener( 'load', function () {
			syncScroll( before, after );
		} );
		after.addEventListener( 'load', function () {
			syncScroll( after, before );
		} );

		function onKey( e ) {
			if ( 'Escape' === e.key ) {
				close();
			}
		}
		function close() {
			clearInterval( blinkTimer );
			overlay.remove();
			document.body.classList.remove( 'dp-noscroll' );
			document.removeEventListener( 'keydown', onKey );
			window.removeEventListener( 'resize', layout );
		}
		document.addEventListener( 'keydown', onKey );
		window.addEventListener( 'resize', layout );
		layout();
		setSplit( 50 );
		handle.focus();
	}

	/* ---------- Routing ---------- */

	function route() {
		var params = new URL( window.location.href ).searchParams;
		if ( params.get( 'post' ) ) {
			viewPage( parseInt( params.get( 'post' ), 10 ) );
		} else if ( 'new' === params.get( 'view' ) ) {
			viewNew();
		} else if ( 'migrate' === params.get( 'view' ) ) {
			viewMigrate();
		} else {
			viewList();
		}
	}

	window.addEventListener( 'popstate', route );
	route();
}() );
