/**
 * The Deft pages screen: list, one page (draft, HTML, history), a new page, and the Move off Elementor board, with a
 * side-by-side compare, the automatic check after Convert, and the last step that removes Elementor. Plain DOM on top
 * of the deft/v1 REST routes; window.deftPages comes from pages-admin.php.
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
		{ key: 'converted', title: 'Converted', hint: 'A Deft draft is waiting. It is checked against the live page next.' },
		{ key: 'checked', title: 'Checked', hint: 'Matches the live page at phone, tablet and desktop.' },
		{ key: 'live', title: 'Live as Deft page', hint: 'Elementor no longer draws these.' },
		{ key: 'attention', title: 'Needs Studio', hint: 'Uses other Elementor widgets.' }
	];

	var checking = {};

	function viewMigrate( board, fresh ) {
		var main = chrome( 'migrate', [ h( 'p', { class: 'dp-loading', text: 'Reading the Elementor pages…' } ) ] );
		( board ? Promise.resolve( board ) : api( '/migration' + ( fresh ? '?fresh=1' : '' ) ) ).then( function ( data ) {
			main.textContent = '';
			var total = data.pages.length;
			var done = data.count.live;
			main.appendChild( h( 'div', { class: 'dp-head' }, [
				h( 'div', {}, [ h( 'h2', { text: 'Move off Elementor' } ), h( 'p', { class: 'dp-meta', text: total ? done + ' of ' + total + ' pages moved' : 'No Elementor pages on this site.' } ) ] ),
				h( 'div', { class: 'dp-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': total, 'aria-valuenow': done }, [ h( 'span', { style: 'width:' + ( total ? Math.round( 100 * done / total ) : 0 ) + '%' } ) ] )
			] ) );
			if ( data.cleaning ) {
				main.appendChild( h( 'div', { class: 'dp-lock', role: 'status' }, [
					h( 'p', { class: 'dp-eyebrow', text: 'Cleaning first' } ),
					h( 'p', { text: 'This site was hacked. It moves off Elementor after it\'s clean and checked, so nothing infected gets copied into a Deft page.' } ),
					h( 'p', { class: 'dp-meta', text: data.cleaning } ),
					h( 'div', { class: 'dp-row' }, [
						h( 'button', { class: 'dp-btn small', type: 'button', text: 'Check again', onclick: function () {
							viewMigrate( null, true );
						} } ),
						h( 'a', { class: 'dp-btn small ghost', href: cfg.settings + '#deft-watch', text: 'Site watch settings' } )
					] )
				] ) );
			}
			var lanes = h( 'div', { class: 'dp-board' + ( data.cleaning ? ' locked' : '' ) } );
			COLUMNS.forEach( function ( col ) {
				var pages = data.pages.filter( function ( p ) {
					return p.status === col.key;
				} );
				lanes.appendChild( h( 'section', { class: 'dp-lane lane-' + col.key }, [
					h( 'header', {}, [ h( 'h3', {}, [ col.title, h( 'span', { class: 'dp-count', text: String( pages.length ) } ) ] ), h( 'p', { class: 'dp-meta', text: col.hint } ) ] ),
					h( 'div', { class: 'dp-lane-body' }, pages.length ? pages.map( function ( page ) {
						return migrateCard( page, data );
					} ) : [ h( 'p', { class: 'dp-lane-empty', text: 'Nothing here.' } ) ] )
				] ) );
			} );
			main.appendChild( lanes );
			main.appendChild( removalPanel( data ) );
			// A page converted but never checked (say, the check was closed half way) gets checked now.
			if ( ! data.cleaning ) {
				data.pages.filter( function ( p ) {
					return 'converted' === p.status && ! p.check && ! checking[ p.id ];
				} ).slice( 0, 1 ).forEach( runCheck );
			}
		} ).catch( fail( main ) );
	}

	function migrateCard( page, data ) {
		var locked = !! data.cleaning;
		var actions = h( 'div', { class: 'dp-row' } );
		function act( label, path, done, primary, tiny ) {
			return h( 'button', { class: 'dp-btn ' + ( tiny ? 'tiny' : 'small' ) + ( primary ? ' primary' : ' ghost' ), type: 'button', text: label, disabled: locked && 'back' !== path, onclick: function ( e ) {
				var release = busy( e.target, label + '…' );
				api( '/migration/' + page.id + '/' + path, { method: 'POST' } ).then( function ( r ) {
					notice( done, 'good' );
					viewMigrate( r.board );
					if ( 'convert' === path ) {
						var converted = r.board.pages.filter( function ( p ) {
							return p.id === page.id;
						} )[ 0 ];
						if ( converted ) {
							runCheck( converted );
						}
					}
				} ).catch( function ( err ) {
					release();
					notice( err.message, 'bad' );
				} );
			} } );
		}
		var isChecking = !! checking[ page.id ];
		var compareButton = h( 'button', { class: 'dp-btn small ghost', type: 'button', text: 'Compare', onclick: function () {
			compare( page.title, page.url, page.preview, 'publish' === page.wpstatus );
		} } );
		var checkNote = null;
		if ( 'elementor' === page.status ) {
			actions.appendChild( act( 'Convert', 'convert', '"' + page.title + '" converted. Checking it against the live page…', true ) );
		} else if ( 'converted' === page.status || 'checked' === page.status ) {
			actions.appendChild( compareButton );
			if ( 'checked' === page.status ) {
				actions.appendChild( act( 'Go live', 'live', '"' + page.title + '" is live as a Deft page.', true ) );
			} else if ( page.check ) {
				actions.appendChild( act( 'Go live anyway', 'live', '"' + page.title + '" is live as a Deft page. Keep an eye on the differences.', false ) );
			}
			if ( ! isChecking && ! locked ) {
				actions.appendChild( h( 'button', { class: 'dp-btn tiny ghost', type: 'button', text: page.check ? 'Check again' : 'Check', onclick: function () {
					runCheck( page );
				} } ) );
			}
			actions.appendChild( act( 'Undo', 'back', 'Draft thrown away.', false, true ) );
			checkNote = checkSummary( page, isChecking );
		} else if ( 'live' === page.status ) {
			actions.appendChild( link( 'Open', { post: page.id }, 'dp-btn small ghost' ) );
			actions.appendChild( act( 'Back to Elementor', 'back', '"' + page.title + '" is back on Elementor.', false ) );
		}
		var other = Object.keys( page.other || {} );
		return h( 'article', { class: 'dp-mcard' + ( isChecking ? ' is-checking' : '' ), 'data-page': page.id }, [
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
			checkNote,
			actions
		] );
	}

	function checkSummary( page, isChecking ) {
		if ( isChecking ) {
			return h( 'p', { class: 'dp-check run', role: 'status' }, [ h( 'span', { class: 'dp-spin', 'aria-hidden': 'true' } ), checking[ page.id ] ] );
		}
		if ( ! page.check ) {
			return h( 'p', { class: 'dp-check', text: 'Not checked yet.' } );
		}
		if ( page.check.ok ) {
			return h( 'p', { class: 'dp-check good', text: 'Matches the live page at phone, tablet and desktop.' } );
		}
		// The same difference at every width is listed once.
		var where = {}, order = [];
		[ [ 'phone', 'Phone' ], [ 'tablet', 'Tablet' ], [ 'desktop', 'Desktop' ] ].forEach( function ( size ) {
			( page.check.sizes[ size[ 0 ] ] || [] ).forEach( function ( issue ) {
				if ( ! where[ issue ] ) {
					where[ issue ] = [];
					order.push( issue );
				}
				where[ issue ].push( size[ 1 ] );
			} );
		} );
		var lines = order.map( function ( issue ) {
			var sizes = 3 === where[ issue ].length ? 'All sizes' : where[ issue ].join( ', ' );
			return h( 'li', {}, [ h( 'b', { text: sizes + ': ' } ), issue ] );
		} );
		return h( 'div', { class: 'dp-check warn' }, [ h( 'p', { text: 'Differs from the live page. Compare, fix the draft, or go live anyway.' } ), h( 'ul', {}, lines.slice( 0, 5 ) ) ] );
	}

	/* ---------- The check: live page and draft, rendered and compared at three widths ---------- */

	var SIZES = [ [ 'phone', 390, 'phone' ], [ 'tablet', 820, 'tablet' ], [ 'desktop', 1440, 'desktop' ] ];

	function runCheck( page ) {
		if ( checking[ page.id ] || ! page.preview ) {
			return;
		}
		checking[ page.id ] = 'Checking at phone, tablet and desktop…';
		redrawCard( page );
		var liveUrl = 'publish' === page.wpstatus ? page.url : page.url + ( page.url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'preview=true';
		var sizes = {};
		var chain = Promise.resolve();
		SIZES.forEach( function ( size ) {
			chain = chain.then( function () {
				checking[ page.id ] = 'Checking at ' + size[ 2 ] + ' width…';
				redrawCard( page );
				return Promise.all( [ render( liveUrl, size[ 1 ] ), render( page.preview, size[ 1 ] ) ] ).then( function ( pair ) {
					sizes[ size[ 0 ] ] = differences( pair[ 0 ], pair[ 1 ] );
				} );
			} );
		} );
		chain.then( function () {
			return api( '/migration/' + page.id + '/check', { method: 'POST', body: { sizes: sizes } } );
		} ).then( function ( r ) {
			delete checking[ page.id ];
			notice( r.ok ? '"' + page.title + '" matches the live page. Ready to go live.' : '"' + page.title + '" differs from the live page. Have a look before it goes live.', r.ok ? 'good' : 'warn' );
			if ( onMigrate() ) {
				viewMigrate( r.board );
			}
		} ).catch( function ( err ) {
			delete checking[ page.id ];
			notice( 'The check didn\'t finish: ' + err.message, 'bad' );
			if ( onMigrate() ) {
				viewMigrate();
			}
		} );
	}

	function onMigrate() {
		return 'migrate' === new URL( window.location.href ).searchParams.get( 'view' );
	}

	function redrawCard( page ) {
		var card = document.querySelector( '.dp-mcard[data-page="' + page.id + '"]' );
		if ( ! card ) {
			return;
		}
		card.classList.add( 'is-checking' );
		var note = card.querySelector( '.dp-check' );
		var fresh = checkSummary( page, true );
		if ( note ) {
			note.replaceWith( fresh );
		} else {
			card.insertBefore( fresh, card.querySelector( '.dp-row:last-child' ) );
		}
		card.querySelectorAll( '.dp-row:last-child button' ).forEach( function ( b ) {
			if ( 'Compare' !== b.textContent ) {
				b.disabled = true;
			}
		} );
	}

	/** Loads a page off screen at a width, lets lazy images and fonts settle, and measures what a visitor sees. */
	function render( url, width ) {
		return new Promise( function ( resolve, reject ) {
			var holder = document.querySelector( '.dp-check-stage' ) || document.body.appendChild( h( 'div', { class: 'dp-check-stage', 'aria-hidden': 'true' } ) );
			var frame = h( 'iframe', { tabindex: '-1', title: 'Check', style: 'width:' + width + 'px;height:900px' } );
			var timer = setTimeout( function () {
				frame.remove();
				reject( new Error( 'a page took over 30 seconds to load' ) );
			}, 30000 );
			frame.addEventListener( 'load', function () {
				var win = frame.contentWindow, doc = frame.contentDocument;
				if ( ! doc || ! doc.body ) {
					clearTimeout( timer );
					frame.remove();
					reject( new Error( 'a page could not be read' ) );
					return;
				}
				// Full height, so lazy images below the fold load and the page lays out as it would when scrolled.
				var settle = function () {
					frame.style.height = Math.min( 30000, Math.max( 900, doc.documentElement.scrollHeight ) ) + 'px';
					return new Promise( function ( r ) {
						setTimeout( r, 900 );
					} );
				};
				( doc.fonts && doc.fonts.ready ? doc.fonts.ready : Promise.resolve() ).then( settle ).then( settle ).then( function () {
					clearTimeout( timer );
					var result = measure( win, doc );
					frame.remove();
					resolve( result );
				} ).catch( function ( err ) {
					clearTimeout( timer );
					frame.remove();
					reject( err );
				} );
			} );
			frame.src = url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'deft_frame=1&deft_check=' + Date.now();
			holder.appendChild( frame );
		} );
	}

	function measure( win, doc ) {
		var scrollY = win.scrollY || 0;
		function shown( el ) {
			var box = el.getBoundingClientRect();
			var style = win.getComputedStyle( el );
			return box.width > 1 && box.height > 1 && 'hidden' !== style.visibility && '0' !== style.opacity;
		}
		function file( src ) {
			try {
				var path = new URL( src, doc.baseURI ).pathname;
				// The same image at another size (photo-300x200.jpg) counts as the same image.
				return decodeURIComponent( path.split( '/' ).pop() ).replace( /-\d+x\d+(?=\.\w+$)/, '' ).replace( /-scaled(?=\.\w+$)/, '' );
			} catch ( e ) {
				return src;
			}
		}
		function href( value ) {
			try {
				var u = new URL( value, doc.baseURI );
				u.searchParams.delete( 'deft_frame' );
				u.searchParams.delete( 'deft_check' );
				return u.origin === win.location.origin ? u.pathname.replace( /\/$/, '' ) + u.search + u.hash || '/' : u.href;
			} catch ( e ) {
				return value;
			}
		}
		var images = [].slice.call( doc.querySelectorAll( 'img' ) ).filter( shown ).map( function ( img ) {
			var box = img.getBoundingClientRect();
			return { file: file( img.currentSrc || img.src ), w: Math.round( box.width ), top: Math.round( box.top + scrollY ), loaded: img.complete && img.naturalWidth > 0 };
		} );
		var heads = [].slice.call( doc.querySelectorAll( 'h1, h2, h3' ) ).filter( shown ).map( function ( el ) {
			return { text: el.textContent.replace( /\s+/g, ' ' ).trim().slice( 0, 60 ), top: Math.round( el.getBoundingClientRect().top + scrollY ) };
		} );
		var links = [].slice.call( doc.querySelectorAll( 'a[href]' ) ).filter( function ( a ) {
			return shown( a ) && ! /^(javascript:|#$)/.test( a.getAttribute( 'href' ) );
		} ).map( function ( a ) {
			return href( a.getAttribute( 'href' ) );
		} );
		return {
			height: doc.documentElement.scrollHeight,
			words: ( doc.body.innerText || '' ).toLowerCase().replace( /[^\p{L}\p{N}@.'’-]+/gu, ' ' ).split( ' ' ).filter( Boolean ),
			images: images,
			heads: heads,
			links: links
		};
	}

	/** What changed between the live page (a) and the draft (b), as short sentences. */
	function differences( a, b ) {
		var out = [];
		var dh = b.height - a.height;
		if ( Math.abs( dh ) > Math.max( 60, a.height * 0.04 ) ) {
			out.push( 'The draft is ' + Math.abs( dh ) + ' px ' + ( dh > 0 ? 'taller' : 'shorter' ) + ' (' + a.height + ' px live).' );
		}
		var missing = minus( a.words, b.words ), extra = minus( b.words, a.words );
		var limit = Math.max( 3, Math.round( a.words.length * 0.01 ) );
		if ( missing.length > limit ) {
			out.push( missing.length + ' words on the live page aren\'t in the draft, like "' + missing.slice( 0, 6 ).join( ' ' ) + '".' );
		}
		if ( extra.length > limit ) {
			out.push( 'The draft has ' + extra.length + ' words the live page doesn\'t, like "' + extra.slice( 0, 6 ).join( ' ' ) + '".' );
		}
		var bFiles = b.images.map( function ( i ) {
			return i.file;
		} );
		var aFiles = a.images.map( function ( i ) {
			return i.file;
		} );
		minus( aFiles, bFiles ).slice( 0, 3 ).forEach( function ( name ) {
			out.push( 'Image ' + name + ' is missing from the draft.' );
		} );
		minus( bFiles, aFiles ).slice( 0, 2 ).forEach( function ( name ) {
			out.push( 'The draft shows an extra image, ' + name + '.' );
		} );
		// Same image, same place in the page: the second photo.jpg on the live page against the second one in the draft.
		var resized = 0, seen = {};
		a.images.forEach( function ( img ) {
			var nth = seen[ img.file ] = ( seen[ img.file ] || 0 ) + 1;
			var match = b.images.filter( function ( i ) {
				return i.file === img.file;
			} )[ nth - 1 ];
			if ( match && img.loaded && match.loaded && Math.abs( match.w - img.w ) > Math.max( 24, img.w * 0.15 ) && resized < 2 ) {
				resized++;
				out.push( 'Image ' + img.file + ' shows ' + match.w + ' px wide instead of ' + img.w + ' px.' );
			}
		} );
		minus( unique( a.links ), unique( b.links ) ).slice( 0, 3 ).forEach( function ( url ) {
			out.push( 'The link to ' + url + ' is missing.' );
		} );
		var moved = 0;
		a.heads.forEach( function ( head ) {
			var match = b.heads.filter( function ( x ) {
				return x.text === head.text;
			} )[ 0 ];
			if ( match && Math.abs( match.top - head.top ) > Math.max( 80, a.height * 0.06 ) && moved < 2 ) {
				moved++;
				out.push( 'The heading "' + head.text + '" sits ' + Math.abs( match.top - head.top ) + ' px ' + ( match.top > head.top ? 'lower' : 'higher' ) + '.' );
			}
		} );
		return out.slice( 0, 8 );
	}

	/** Items of a that b doesn't have, counting repeats. */
	function minus( a, b ) {
		var counts = {};
		b.forEach( function ( x ) {
			counts[ x ] = ( counts[ x ] || 0 ) + 1;
		} );
		return a.filter( function ( x ) {
			if ( counts[ x ] ) {
				counts[ x ]--;
				return false;
			}
			return true;
		} );
	}

	function unique( list ) {
		return list.filter( function ( x, i ) {
			return list.indexOf( x ) === i;
		} );
	}

	/* ---------- Last step: removing Elementor ---------- */

	var REMOVE_STEPS = [
		[ 'backup', 'Backup taken' ],
		[ 'deactivate', 'Deactivate Elementor' ],
		[ 'check', 'Check every page' ],
		[ 'uninstall', 'Uninstall Elementor' ]
	];

	function removalPanel( data ) {
		var e = data.elementor;
		var state = e.removal;
		var count = data.count;
		var total = data.pages.length;
		var names = ( e.installed || [] ).join( ' and ' ) || 'Elementor';
		var panel = h( 'section', { class: 'dp-remove', 'aria-labelledby': 'dp-remove-title' } );
		panel.appendChild( h( 'h3', { id: 'dp-remove-title', class: 'dp-eyebrow', text: 'Last step · remove Elementor' } ) );

		if ( state && 'removed' === state.step ) {
			panel.appendChild( h( 'p', { class: 'dp-check good', text: names === 'Elementor' ? 'Elementor removed ' + ago( state.removed ) + '. Every page was checked and loading fine.' : 'Removed ' + ago( state.removed ) + '. Every page was checked and loading fine.' } ) );
			panel.appendChild( h( 'p', { class: 'dp-meta', text: 'Elementor\'s saved layouts are still in the database, so a page can go back to Elementor if Elementor is installed again.' } ) );
			return panel;
		}
		if ( ! ( e.installed || [] ).length ) {
			panel.appendChild( h( 'p', { class: 'dp-meta', text: 'Elementor isn\'t installed on this site.' } ) );
			return panel;
		}

		var checks = [
			[ count.live === total && total > 0, 'Every page live as a Deft page (' + count.live + ' of ' + total + ')' ],
			[ ! data.blockers.length, data.blockers.length ? 'Still using Elementor: ' + data.blockers.join( '; ' ) : 'Nothing else uses Elementor (headers, footers, other posts)' ],
			[ ! data.cleaning, data.cleaning ? 'The site is being cleaned first' : 'No signs of a hack' ]
		];
		panel.appendChild( h( 'ul', { class: 'dp-checklist' }, checks.map( function ( c ) {
			return h( 'li', { class: c[ 0 ] ? 'ok' : '' }, [ h( 'span', { class: 'dp-tick', 'aria-hidden': 'true' } ), c[ 1 ] ] );
		} ) ) );

		if ( state && 'failed' === state.step ) {
			var failed = ( state.pages || [] ).filter( function ( p ) {
				return ! p.ok;
			} );
			panel.appendChild( h( 'div', { class: 'dp-check warn' }, [
				h( 'p', { text: 'The check failed, so ' + names + ' went straight back on. Nothing was uninstalled.' } ),
				h( 'ul', {}, failed.slice( 0, 6 ).map( function ( p ) {
					return h( 'li', {}, [ h( 'b', { text: p.title + ': ' } ), p.problem ] );
				} ) )
			] ) );
		}

		if ( state && ( 'deactivated' === state.step || 'checked' === state.step ) ) {
			// Stopped half way (the tab was closed): offer to carry on or put Elementor back.
			panel.appendChild( h( 'p', { class: 'dp-warn', text: names + ' is deactivated but not uninstalled yet.' } ) );
			var resume = h( 'div', { class: 'dp-row' }, [
				h( 'button', { class: 'dp-btn small primary', type: 'button', text: 'Check every page and finish', onclick: function () {
					runRemoval( panel, data, 'check' );
				} } ),
				h( 'button', { class: 'dp-btn small ghost', type: 'button', text: 'Put Elementor back on', onclick: function ( ev ) {
					var release = busy( ev.target, 'Putting it back…' );
					api( '/migration/remove', { method: 'POST', body: { step: 'restore' } } ).then( function ( r ) {
						notice( names + ' is back on.', 'good' );
						viewMigrate( r.board );
					} ).catch( function ( err ) {
						release();
						notice( err.message, 'bad' );
					} );
				} } )
			] );
			panel.appendChild( resume );
			return panel;
		}

		if ( ! e.removable ) {
			panel.appendChild( h( 'p', { class: 'dp-meta', text: 'Unlocks when every page is live as a Deft page and nothing else on the site uses Elementor.' } ) );
			return panel;
		}
		if ( ! e.can ) {
			panel.appendChild( h( 'p', { class: 'dp-meta', text: 'Only an administrator who can manage plugins can remove Elementor.' } ) );
			return panel;
		}

		var backup = h( 'input', { type: 'checkbox', id: 'dp-backup' } );
		var ok = h( 'input', { type: 'checkbox', id: 'dp-ok' } );
		var go = h( 'button', { class: 'dp-btn primary', type: 'button', text: 'Remove ' + names, disabled: true } );
		function update() {
			go.disabled = ! ( backup.checked && ok.checked );
		}
		backup.addEventListener( 'change', update );
		ok.addEventListener( 'change', update );
		go.addEventListener( 'click', function () {
			runRemoval( panel, data, 'deactivate' );
		} );
		panel.appendChild( h( 'div', { class: 'dp-consent' }, [
			h( 'label', { for: 'dp-backup' }, [ backup, h( 'span', {}, [ 'I took a backup of the whole site today ', h( 'a', { href: 'https://hpanel.hostinger.com/', target: '_blank', rel: 'noopener', text: '(hPanel › Websites › Backups)' } ) ] ) ] ),
			h( 'label', { for: 'dp-ok' }, [ ok, h( 'span', { text: 'OK to remove ' + names + ' from ' + cfg.site } ) ] )
		] ) );
		panel.appendChild( h( 'div', { class: 'dp-row' }, [ go, h( 'span', { class: 'dp-meta', text: 'If any page fails the check, Elementor goes straight back on.' } ) ] ) );
		return panel;
	}

	/** Runs the removal steps in order, showing each one, from deactivate (fresh start) or check (resuming). */
	function runRemoval( panel, data, from ) {
		var order = [ 'deactivate', 'check', 'uninstall' ];
		var list = h( 'ol', { class: 'dp-steps', role: 'status' } );
		var items = {};
		REMOVE_STEPS.forEach( function ( step ) {
			items[ step[ 0 ] ] = h( 'li', { class: 'backup' === step[ 0 ] ? 'done' : '' }, [ h( 'span', { class: 'dp-tick', 'aria-hidden': 'true' } ), step[ 1 ] ] );
			list.appendChild( items[ step[ 0 ] ] );
		} );
		panel.textContent = '';
		panel.appendChild( h( 'h3', { class: 'dp-eyebrow', text: 'Removing Elementor' } ) );
		panel.appendChild( list );
		var steps = order.slice( order.indexOf( from ) );
		order.slice( 0, order.indexOf( from ) ).forEach( function ( done ) {
			items[ done ].className = 'done';
		} );
		var chain = Promise.resolve();
		steps.forEach( function ( step ) {
			chain = chain.then( function ( last ) {
				if ( last && last.removal && 'failed' === last.removal.step ) {
					return last;
				}
				items[ step ].className = 'now';
				return api( '/migration/remove', { method: 'POST', body: { step: step, backup: true, ok: true } } ).then( function ( r ) {
					items[ step ].className = r.removal && 'failed' === r.removal.step ? 'bad' : 'done';
					return r;
				} );
			} );
		} );
		chain.then( function ( r ) {
			var failedCheck = r.removal && 'failed' === r.removal.step;
			notice( failedCheck ? 'A page failed the check, so Elementor is back on. Nothing was uninstalled.' : 'Elementor removed. Every page checked and loading fine.', failedCheck ? 'bad' : 'good' );
			viewMigrate( r.board );
		} ).catch( function ( err ) {
			notice( err.message, 'bad' );
			viewMigrate();
		} );
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
