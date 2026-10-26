/**
 * Live Edit: in-place editing of a live Deft page for Deft staff. Loaded only for staff on Deft pages when Live Edit is
 * switched on (modules/live-edit.php). The page's own markup is never sent back: only the text inside marked
 * elements ([data-deft-edit]), an image's address and alt text, or a link's address.
 *
 * Phone shows the page at 390 px wide with the unsaved changes in it. "Ask" sends one block of plain words to Claude
 * (through Deft Core and Central) and shows its version next to the current one; nothing changes until Use this.
 *
 * Everything we draw lives in a shadow root, so the client's CSS can't reach our controls and ours can't reach theirs.
 * The only styles added to the page itself are the hover and focus outlines on editable elements.
 */
( function () {
	'use strict';

	var cfg = window.deftLiveEdit;
	if ( ! cfg || ! document.querySelector ) {
		return;
	}

	var STORE = 'deft-live-edit:' + cfg.post;
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var LABELS = {
		h1: 'Heading 1', h2: 'Heading 2', h3: 'Heading 3', h4: 'Heading 4', h5: 'Heading 5', h6: 'Heading 6',
		p: 'Paragraph', li: 'List item', a: 'Link', button: 'Button', span: 'Text', div: 'Text', small: 'Small text',
		strong: 'Bold text', b: 'Bold text', em: 'Text', blockquote: 'Quote', figcaption: 'Caption', label: 'Label',
		td: 'Table cell', th: 'Table heading', dt: 'Term', dd: 'Description', summary: 'Summary', caption: 'Caption',
		img: 'Image'
	};
	var SINGLE_LINE = { h1: 1, h2: 1, h3: 1, h4: 1, h5: 1, h6: 1, a: 1, button: 1, label: 1, summary: 1, small: 1, span: 1, strong: 1, b: 1, em: 1 };

	var state = {
		on: false,
		items: {},       // id -> { id, el, kind, tag, original, touched }
		order: [],       // ids in page order
		active: null,    // id being typed in
		popover: null,   // id whose image/link popover is open
		history: [],     // ids in the order they were last changed, for Undo
		base: cfg.base,
		busy: false,
		panel: null,     // 'review' | 'history' | null
		view: 'desktop', // 'desktop' | 'phone'
		asking: null,    // the ask Claude is answering, so a late answer for another element is dropped
		photo: null,     // id of the image the Replace photo button sits on
		confirmExit: false
	};

	/* ---------- Small helpers ---------- */

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
			if ( null === child || undefined === child || false === child ) {
				return;
			}
			el.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
		} );
		return el;
	}

	function icon( name ) {
		var paths = {
			edit: 'M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4',
			undo: 'M9 14L4 9l5-5M4 9h10a6 6 0 010 12h-3',
			history: 'M3 12a9 9 0 109-9 9 9 0 00-7 3.4M3 4v4h4M12 7v5l3 2',
			close: 'M6 6l12 12M18 6L6 18',
			check: 'M5 12l5 5L20 7',
			bold: 'M7 5h6a3.5 3.5 0 010 7H7zM7 12h7a3.5 3.5 0 010 7H7z',
			italic: 'M11 5h6M7 19h6M14 5l-4 14',
			link: 'M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1',
			clear: 'M5 5h11M10 5L7 19M15 15l5 5M20 15l-5 5',
			image: 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15 9.5a1.5 1.5 0 100-.01',
			arrow: 'M5 12h14M13 6l6 6-6 6',
			eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 100-6 3 3 0 000 6z',
			restore: 'M4 12a8 8 0 108-8 8 8 0 00-6.3 3M4 4v4h4',
			desktop: 'M3 5h18v11H3zM8 20h8M12 16v4',
			phone: 'M8 3h8a1 1 0 011 1v16a1 1 0 01-1 1H8a1 1 0 01-1-1V4a1 1 0 011-1zM11 18h2'
		};
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'class', 'i' );
		var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		path.setAttribute( 'd', paths[ name ] || '' );
		svg.appendChild( path );
		return svg;
	}

	function mark() {
		// The sliced "d": a bowl and stem cut by one diagonal line.
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 32 32' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'class', 'logo' );
		svg.innerHTML = '<circle cx="14" cy="19" r="7.5" fill="none" stroke="currentColor" stroke-width="3.6"/>' +
			'<rect x="20" y="4" width="3.6" height="23" rx="1.2" fill="currentColor"/>' +
			'<path d="M6 27L27 6" stroke="var(--obsidian)" stroke-width="2.4"/>';
		return svg;
	}

	function normalize( html ) {
		return String( html || '' ).replace( /\s+/g, ' ' ).replace( /&nbsp;/g, ' ' ).trim();
	}

	function textOf( html ) {
		var div = document.createElement( 'div' );
		div.innerHTML = html;
		return ( div.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function plural( n, one, many ) {
		return n + ' ' + ( 1 === n ? one : many );
	}

	function ago( iso ) {
		var then = new Date( iso ).getTime();
		var mins = Math.round( ( Date.now() - then ) / 60000 );
		if ( mins < 1 ) {
			return 'just now';
		}
		if ( mins < 60 ) {
			return mins + ' min ago';
		}
		var hours = Math.round( mins / 60 );
		if ( hours < 24 ) {
			return hours + ' h ago';
		}
		return new Date( iso ).toLocaleDateString( undefined, { month: 'short', day: 'numeric' } ) + ', ' +
			new Date( iso ).toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
	}

	function api( path, options ) {
		options = options || {};
		return fetch( cfg.rest + path, {
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
					error.data = data;
					throw error;
				}
				return data;
			} );
		} );
	}

	/** Word-level diff: a list of [ 'same' | 'del' | 'ins', text ]. */
	function diffWords( before, after ) {
		var a = before.split( /(\s+)/ ), b = after.split( /(\s+)/ );
		if ( a.length * b.length > 250000 ) {
			return [ [ 'del', before ], [ 'ins', after ] ];
		}
		var dp = [];
		for ( var i = 0; i <= a.length; i++ ) {
			dp.push( new Array( b.length + 1 ).fill( 0 ) );
		}
		for ( i = a.length - 1; i >= 0; i-- ) {
			for ( var j = b.length - 1; j >= 0; j-- ) {
				dp[ i ][ j ] = a[ i ] === b[ j ] ? dp[ i + 1 ][ j + 1 ] + 1 : Math.max( dp[ i + 1 ][ j ], dp[ i ][ j + 1 ] );
			}
		}
		var out = [];
		function push( kind, text ) {
			if ( out.length && out[ out.length - 1 ][ 0 ] === kind ) {
				out[ out.length - 1 ][ 1 ] += text;
			} else {
				out.push( [ kind, text ] );
			}
		}
		i = 0;
		j = 0;
		while ( i < a.length && j < b.length ) {
			if ( a[ i ] === b[ j ] ) {
				push( 'same', a[ i ] );
				i++;
				j++;
			} else if ( dp[ i + 1 ][ j ] >= dp[ i ][ j + 1 ] ) {
				push( 'del', a[ i++ ] );
			} else {
				push( 'ins', b[ j++ ] );
			}
		}
		while ( i < a.length ) {
			push( 'del', a[ i++ ] );
		}
		while ( j < b.length ) {
			push( 'ins', b[ j++ ] );
		}
		return out;
	}

	/* ---------- The page: editable elements ---------- */

	function collect() {
		state.items = {};
		state.order = [];
		document.querySelectorAll( '[data-deft-edit]' ).forEach( function ( el ) {
			var id = el.getAttribute( 'data-deft-edit' );
			if ( ! id || state.items[ id ] || ui.host.contains( el ) ) {
				return;
			}
			var kind = 'i' === id[ 0 ] ? 'image' : ( 'l' === id[ 0 ] ? 'link' : 'text' );
			// Text inside another editable text element is edited as part of it (the server does the same).
			if ( 'text' === kind && el.parentElement && el.parentElement.closest( '[data-deft-edit^="t"]' ) ) {
				return;
			}
			var tag = el.tagName.toLowerCase();
			state.items[ id ] = {
				id: id,
				el: el,
				kind: kind,
				tag: tag,
				original: snapshot( el, kind ),
				touched: 0
			};
			state.order.push( id );
		} );
	}

	function snapshot( el, kind ) {
		if ( 'image' === kind ) {
			var picture = el.parentElement && 'PICTURE' === el.parentElement.tagName ? el.parentElement : null;
			return {
				src: el.getAttribute( 'src' ) || '',
				srcset: el.getAttribute( 'srcset' ),
				sizes: el.getAttribute( 'sizes' ),
				alt: el.getAttribute( 'alt' ) || '',
				width: el.getAttribute( 'width' ),
				height: el.getAttribute( 'height' ),
				sources: picture ? Array.prototype.slice.call( picture.querySelectorAll( 'source' ) ) : []
			};
		}
		return {
			html: el.innerHTML,
			href: 'A' === el.tagName ? el.getAttribute( 'href' ) || '' : null
		};
	}

	function edit( item ) {
		var el = item.el, o = item.original, out = { id: item.id };
		if ( 'image' === item.kind ) {
			if ( ( el.getAttribute( 'src' ) || '' ) !== o.src ) {
				out.src = el.getAttribute( 'src' );
				if ( el.getAttribute( 'width' ) ) {
					out.width = parseInt( el.getAttribute( 'width' ), 10 );
				}
				if ( el.getAttribute( 'height' ) ) {
					out.height = parseInt( el.getAttribute( 'height' ), 10 );
				}
			}
			if ( ( el.getAttribute( 'alt' ) || '' ) !== o.alt ) {
				out.alt = el.getAttribute( 'alt' ) || '';
			}
		} else {
			if ( 'text' === item.kind && normalize( el.innerHTML ) !== normalize( o.html ) ) {
				out.html = el.innerHTML;
			}
			if ( null !== o.href && ( el.getAttribute( 'href' ) || '' ) !== o.href ) {
				out.href = el.getAttribute( 'href' ) || '';
			}
		}
		return Object.keys( out ).length > 1 ? out : null;
	}

	function changed() {
		return state.order.map( function ( id ) {
			return edit( state.items[ id ] );
		} ).filter( Boolean );
	}

	function refreshItem( item ) {
		var isChanged = !! edit( item );
		if ( state.active === item.id || state.popover === item.id ) {
			item.el.setAttribute( 'data-deft-le', 'active' );
		} else if ( isChanged ) {
			item.el.setAttribute( 'data-deft-le', 'changed' );
		} else {
			item.el.removeAttribute( 'data-deft-le' );
		}
	}

	function touch( item ) {
		item.touched = Date.now();
		state.history = state.history.filter( function ( id ) {
			return id !== item.id;
		} );
		if ( edit( item ) ) {
			state.history.push( item.id );
		}
		refreshItem( item );
		renderDock();
		if ( 'review' === state.panel ) {
			renderPanel();
		}
		if ( 'phone' === state.view ) {
			syncPhone();
		}
		savePending();
	}

	function revert( item ) {
		var o = item.original, el = item.el;
		if ( 'image' === item.kind ) {
			[ 'src', 'srcset', 'sizes', 'alt', 'width', 'height' ].forEach( function ( attr ) {
				if ( null === o[ attr ] || undefined === o[ attr ] ) {
					el.removeAttribute( attr );
				} else {
					el.setAttribute( attr, o[ attr ] );
				}
			} );
			if ( o.sources.length && el.parentElement ) {
				o.sources.forEach( function ( source ) {
					if ( ! source.parentElement ) {
						el.parentElement.insertBefore( source, el );
					}
				} );
			}
		} else {
			if ( 'text' === item.kind ) {
				el.innerHTML = o.html;
			}
			if ( null !== o.href ) {
				el.setAttribute( 'href', o.href );
			}
		}
		touch( item );
	}

	/* ---------- Our own UI, in a shadow root ---------- */

	var ui = {};

	function buildUI() {
		var fonts = document.createElement( 'style' );
		fonts.id = 'deft-le-fonts';
		fonts.textContent = '@font-face{font-family:"Deft Geist";src:url(' + cfg.fonts.sans + ') format("woff2");font-weight:100 900;font-display:swap}' +
			'@font-face{font-family:"Deft Archivo";src:url(' + cfg.fonts.label + ') format("woff2");font-weight:100 900;font-display:swap}';
		document.head.appendChild( fonts );

		var page = document.createElement( 'style' );
		page.id = 'deft-le-page';
		page.textContent = [
			'html.deft-le-on [data-deft-edit]{outline:1.5px dashed rgba(51,80,240,.38);outline-offset:3px;transition:outline-color .16s,background-color .16s;cursor:text}',
			'html.deft-le-on [data-deft-edit^="i"],html.deft-le-on [data-deft-edit^="l"]{cursor:pointer}',
			'html.deft-le-on [data-deft-edit]:hover{outline-style:solid;outline-color:rgba(51,80,240,.75)}',
			'html.deft-le-on [data-deft-le="changed"]{outline:2px solid rgba(255,199,0,.95);outline-offset:3px}',
			'html.deft-le-on [data-deft-le="active"]{outline:2px solid #3350F0;outline-offset:3px;cursor:text;caret-color:#3350F0}',
			'html.deft-le-on [data-deft-le="flash"]{outline:3px solid #FFC700;outline-offset:6px}',
			'html.deft-le-on [contenteditable]:focus{outline:2px solid #3350F0}',
			'@media (prefers-reduced-motion:reduce){html.deft-le-on [data-deft-edit]{transition:none}}'
		].join( '\n' );
		document.head.appendChild( page );

		ui.host = h( 'div', { id: 'deft-live-edit', 'data-deft-ui': '' } );
		document.body.appendChild( ui.host );
		var root = ui.host.attachShadow ? ui.host.attachShadow( { mode: 'open' } ) : ui.host;
		ui.root = root;
		root.appendChild( h( 'link', { rel: 'stylesheet', href: cfg.css } ) );
		ui.live = h( 'div', { class: 'sr', 'aria-live': 'polite' } );
		ui.chip = h( 'div', { class: 'chip', 'aria-hidden': 'true' } );
		ui.toolbar = h( 'div', { class: 'toolbar', role: 'toolbar', 'aria-label': 'Text formatting' } );
		ui.popover = h( 'div', { class: 'popover', role: 'dialog' } );
		ui.toasts = h( 'div', { class: 'toasts' } );
		ui.dock = h( 'div', { class: 'dock', role: 'region', 'aria-label': 'Deft Live Edit' } );
		ui.panel = h( 'aside', { class: 'panel', 'aria-label': 'Live Edit panel' } );
		ui.scrim = h( 'div', { class: 'scrim', onclick: closePanel } );
		ui.ask = h( 'div', { class: 'ask', role: 'group', 'aria-label': 'Ask Claude for a version' } );
		ui.photo = h( 'button', { class: 'photo-btn', onclick: function () {
			if ( state.photo && state.items[ state.photo ] ) {
				pickImage( state.items[ state.photo ] );
			}
		} }, [ icon( 'image' ), 'Replace photo' ] );
		ui.phone = h( 'div', { class: 'phone', role: 'dialog', 'aria-label': 'This page on a phone' } );
		[ ui.live, ui.phone, ui.chip, ui.photo, ui.toolbar, ui.ask, ui.popover, ui.scrim, ui.panel, ui.toasts, ui.dock ].forEach( function ( el ) {
			root.appendChild( el );
		} );
		if ( reduceMotion ) {
			ui.host.setAttribute( 'data-reduced', '' );
		}
		renderDock();
	}

	function announce( text ) {
		ui.live.textContent = '';
		setTimeout( function () {
			ui.live.textContent = text;
		}, 30 );
	}

	function toast( text, options ) {
		options = options || {};
		var el = h( 'div', { class: 'toast' + ( options.tone ? ' ' + options.tone : '' ), role: 'status' }, [
			options.tone === 'good' ? icon( 'check' ) : null,
			h( 'span', { class: 'toast-text', text: text } ),
			options.link ? h( 'a', { class: 'link', href: options.link.href, target: '_blank', rel: 'noopener', text: options.link.label + ' ↗' } ) : null,
			options.action ? h( 'button', { class: 'link', text: options.action.label, onclick: function () {
				el.remove();
				options.action.run();
			} } ) : null,
			h( 'button', { class: 'icon-btn small', 'aria-label': 'Dismiss', onclick: function () {
				el.remove();
			} }, [ icon( 'close' ) ] )
		] );
		ui.toasts.appendChild( el );
		announce( text );
		setTimeout( function () {
			el.classList.add( 'out' );
			setTimeout( function () {
				el.remove();
			}, 300 );
		}, options.ms || 6000 );
		return el;
	}

	/* ---------- The dock ---------- */

	function renderDock() {
		var dock = ui.dock;
		dock.textContent = '';
		var count = changed().length;
		dock.setAttribute( 'data-state', state.busy ? 'busy' : ( state.on ? 'editing' : 'idle' ) );

		var brand = h( 'span', { class: 'brand', title: 'Deft Live Edit' }, [ mark() ] );
		dock.appendChild( brand );

		if ( state.busy ) {
			dock.appendChild( h( 'div', { class: 'steps', 'aria-live': 'polite' }, state.busy.map( function ( step ) {
				return h( 'span', { class: 'step ' + step.state }, [ 'done' === step.state ? icon( 'check' ) : h( 'span', { class: 'dot' } ), step.label ] );
			} ) ) );
			return;
		}

		if ( ! state.on ) {
			dock.appendChild( h( 'button', { class: 'btn ghost', onclick: start, 'aria-keyshortcuts': 'E' }, [ icon( 'edit' ), 'Edit page', h( 'kbd', { text: 'E' } ) ] ) );
			dock.appendChild( viewSwitch() );
			if ( cfg.draft ) {
				dock.appendChild( h( 'span', { class: 'pill-note', title: 'Saved by ' + cfg.draft.by + ' ' + ago( cfg.draft.saved ) + '. Your Live Edit changes are copied into it.' }, [ 'Studio draft waiting' ] ) );
			}
			dock.appendChild( h( 'button', { class: 'icon-btn', 'aria-label': 'Revisions', title: 'Revisions', onclick: function () {
				openPanel( 'history' );
			} }, [ icon( 'history' ) ] ) );
			return;
		}

		if ( state.confirmExit ) {
			dock.appendChild( h( 'span', { class: 'label', text: 'Throw away ' + plural( count, 'change', 'changes' ) + '?' } ) );
			dock.appendChild( h( 'button', { class: 'btn danger', onclick: function () {
				state.confirmExit = false;
				state.order.forEach( function ( id ) {
					revert( state.items[ id ] );
				} );
				clearPending();
				stop( true );
			} }, [ 'Throw away' ] ) );
			dock.appendChild( h( 'button', { class: 'btn ghost', onclick: function () {
				state.confirmExit = false;
				renderDock();
			} }, [ 'Keep editing' ] ) );
			return;
		}

		dock.appendChild( h( 'span', { class: 'label' }, [ h( 'span', { class: 'eyebrow', text: 'Live Edit' } ), h( 'b', { text: cfg.title } ), h( 'span', { class: 'hide-sm muted', text: ' · Deft staff only' } ) ] ) );
		dock.appendChild( viewSwitch() );
		dock.appendChild( h( 'span', { class: 'count' + ( count ? ' has' : '' ), 'aria-live': 'polite' }, [ count ? plural( count, 'change', 'changes' ) : 'No changes', count ? h( 'span', { class: 'hide-sm', text: ', not live yet' } ) : null ] ) );
		dock.appendChild( h( 'span', { class: 'sep' } ) );
		dock.appendChild( h( 'button', { class: 'icon-btn', 'aria-label': 'Undo last change', title: 'Undo last change', disabled: ! count, onclick: undoLast }, [ icon( 'undo' ) ] ) );
		dock.appendChild( h( 'button', { class: 'icon-btn', 'aria-label': 'Revisions', title: 'Revisions', onclick: function () {
			openPanel( 'history' );
		} }, [ icon( 'history' ) ] ) );
		dock.appendChild( h( 'button', { class: 'btn primary', disabled: ! count, 'aria-keyshortcuts': 'Control+S Meta+S', onclick: function () {
			openPanel( 'review' );
		} }, [ 'Publish', h( 'span', { 'aria-hidden': 'true', text: '→' } ) ] ) );
		dock.appendChild( h( 'button', { class: 'icon-btn', 'aria-label': 'Stop editing', title: 'Stop editing (Esc)', onclick: function () {
			stop();
		} }, [ icon( 'close' ) ] ) );
	}

	function viewSwitch() {
		return h( 'span', { class: 'seg', role: 'group', 'aria-label': 'Preview size' }, [ [ 'desktop', 'Desktop' ], [ 'phone', 'Phone' ] ].map( function ( v ) {
			return h( 'button', { class: 'seg-btn', 'aria-pressed': state.view === v[ 0 ] ? 'true' : 'false', title: v[ 1 ], onclick: function () {
				setView( v[ 0 ] );
			} }, [ icon( v[ 0 ] ), h( 'span', { class: 'hide-sm', text: v[ 1 ] } ) ] );
		} ) );
	}

	function undoLast() {
		var id = state.history.pop();
		if ( id && state.items[ id ] ) {
			revert( state.items[ id ] );
			flash( state.items[ id ] );
			announce( 'Undid the change to ' + ( LABELS[ state.items[ id ].tag ] || 'an element' ) );
		}
	}

	function flash( item ) {
		item.el.scrollIntoView( { behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' } );
		var before = item.el.getAttribute( 'data-deft-le' );
		item.el.setAttribute( 'data-deft-le', 'flash' );
		setTimeout( function () {
			if ( 'flash' === item.el.getAttribute( 'data-deft-le' ) ) {
				if ( before ) {
					item.el.setAttribute( 'data-deft-le', before );
				} else {
					item.el.removeAttribute( 'data-deft-le' );
				}
				refreshItem( item );
			}
		}, 900 );
	}

	/* ---------- Turning editing on and off ---------- */

	function start() {
		if ( state.on ) {
			return;
		}
		collect();
		state.on = true;
		document.documentElement.classList.add( 'deft-le-on' );
		var restored = restorePending();
		renderDock();
		if ( ! state.order.length ) {
			toast( 'Nothing on this page is marked as editable. Change it in Studio instead.' );
		} else if ( restored ) {
			toast( 'Put back ' + plural( restored, 'unsaved change', 'unsaved changes' ) + ' from before you reloaded.', { tone: 'good' } );
		} else {
			announce( 'Editing. Click any outlined text or image.' );
		}
	}

	function stop( force ) {
		if ( ! state.on ) {
			return;
		}
		if ( ! force && changed().length ) {
			state.confirmExit = true;
			renderDock();
			return;
		}
		deactivate();
		closePopover();
		closePanel();
		hidePhoto();
		state.on = false;
		document.documentElement.classList.remove( 'deft-le-on' );
		state.order.forEach( function ( id ) {
			state.items[ id ].el.removeAttribute( 'data-deft-le' );
		} );
		renderDock();
	}

	/* ---------- Typing in place ---------- */

	function activate( item ) {
		if ( state.active === item.id ) {
			return;
		}
		deactivate();
		closePopover();
		state.active = item.id;
		item.el.setAttribute( 'contenteditable', 'true' );
		item.el.setAttribute( 'spellcheck', 'true' );
		refreshItem( item );
		item.el.focus( { preventScroll: true } );
		placeToolbar( item );
		showAsk( item );
		hideChip();
	}

	function deactivate() {
		if ( ! state.active ) {
			return;
		}
		var item = state.items[ state.active ];
		state.active = null;
		if ( item ) {
			item.el.removeAttribute( 'contenteditable' );
			item.el.removeAttribute( 'spellcheck' );
			// Typing can leave a lone <br> or nested empty tags behind.
			if ( '' === textOf( item.el.innerHTML ) && /<br\s*\/?>$/i.test( item.el.innerHTML ) ) {
				item.el.innerHTML = '';
			}
			refreshItem( item );
			touch( item );
		}
		ui.toolbar.classList.remove( 'show' );
		hideAsk();
	}

	function exec( command, value ) {
		document.execCommand( command, false, value );
		var item = state.items[ state.active ];
		if ( item ) {
			touch( item );
		}
	}

	function placeToolbar( item ) {
		var bar = ui.toolbar;
		bar.textContent = '';
		bar.appendChild( h( 'span', { class: 'tb-label', text: LABELS[ item.tag ] || 'Text' } ) );
		[ [ 'bold', 'Bold (Ctrl+B)', function () {
			exec( 'bold' );
		} ], [ 'italic', 'Italic (Ctrl+I)', function () {
			exec( 'italic' );
		} ], [ 'link', 'Link', function () {
			linkSelection( item );
		} ], [ 'clear', 'Clear formatting', function () {
			exec( 'removeFormat' );
		} ] ].forEach( function ( b ) {
			bar.appendChild( h( 'button', { class: 'icon-btn small', 'aria-label': b[ 1 ], title: b[ 1 ], onmousedown: function ( e ) {
				e.preventDefault(); // Keep the text selection.
			}, onclick: b[ 2 ] }, [ icon( b[ 0 ] ) ] ) );
		} );
		if ( 'a' === item.tag ) {
			bar.appendChild( h( 'span', { class: 'sep' } ) );
			bar.appendChild( h( 'button', { class: 'btn tiny ghost', onmousedown: function ( e ) {
				e.preventDefault();
			}, onclick: function () {
				openLinkPopover( item );
			} }, [ 'Link address' ] ) );
		}
		bar.appendChild( h( 'span', { class: 'sep' } ) );
		bar.appendChild( h( 'button', { class: 'btn tiny ghost', onmousedown: function ( e ) {
			e.preventDefault();
		}, onclick: function () {
			revert( item );
		} }, [ 'Revert' ] ) );
		bar.classList.add( 'show' );
		position( bar, item.el, 'above' );
	}

	function linkSelection( item ) {
		var selection = window.getSelection();
		if ( ! selection || selection.isCollapsed ) {
			toast( 'Select the words to link first.' );
			return;
		}
		var range = selection.getRangeAt( 0 );
		var anchor = range.commonAncestorContainer.parentElement && range.commonAncestorContainer.parentElement.closest( 'a' );
		openUrlPrompt( item, anchor ? anchor.getAttribute( 'href' ) : '', function ( url ) {
			selection.removeAllRanges();
			selection.addRange( range );
			exec( url ? 'createLink' : 'unlink', url || undefined );
		}, !! anchor );
	}

	/* ---------- Ask Claude for a version ---------- */

	var ASKS = [ 'Make it shorter', 'In their voice', 'Add the city' ];

	function keepFocus( e ) {
		e.preventDefault(); // Keep the cursor in the text.
	}

	/** Plain words only: Claude's version replaces the element's text, so anything holding links or other tags is left alone. */
	function canAsk( item ) {
		return !! cfg.ask && 'text' === item.kind && 'a' !== item.tag && ! item.el.querySelector( '*:not(br)' ) && '' !== textOf( item.el.innerHTML );
	}

	function hideAsk() {
		state.asking = null;
		ui.ask.classList.remove( 'show' );
		ui.ask.textContent = '';
	}

	function showAsk( item ) {
		var box = ui.ask;
		state.asking = null;
		box.textContent = '';
		if ( ! canAsk( item ) ) {
			box.classList.remove( 'show' );
			return;
		}
		var input = h( 'input', { type: 'text', placeholder: 'Or say what to change', 'aria-label': 'What should Claude change?', maxlength: 300 } );
		function askOwn() {
			if ( input.value.trim() ) {
				askClaude( item, input.value.trim() );
			}
		}
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				askOwn();
			}
		} );
		box.appendChild( h( 'span', { class: 'tb-label', text: 'Type in place, or ask' } ) );
		ASKS.forEach( function ( ask ) {
			box.appendChild( h( 'button', { class: 'ask-chip', onmousedown: keepFocus, onclick: function () {
				askClaude( item, ask );
			} }, [ ask ] ) );
		} );
		box.appendChild( h( 'div', { class: 'ask-own' }, [ input, h( 'button', { class: 'btn small ghost', onclick: askOwn }, [ 'Ask' ] ) ] ) );
		box.classList.add( 'show' );
		position( box, item.el, 'below' );
	}

	function askClaude( item, ask ) {
		var before = item.el.innerText.trim();
		var box = ui.ask;
		var asking = state.asking = { id: item.id };
		box.textContent = '';
		box.appendChild( h( 'p', { class: 'ask-wait', 'aria-live': 'polite' }, [ h( 'span', { class: 'dot' } ), 'Claude is writing a version…' ] ) );
		position( box, item.el, 'below' );
		api( '/suggest', { method: 'POST', body: { tag: item.tag, text: before, ask: ask } } ).then( function ( data ) {
			if ( asking !== state.asking ) {
				return;
			}
			if ( ! data.text ) {
				throw new Error( 'Claude didn\'t send any words back. Try again.' );
			}
			showProposal( item, before, data );
		} ).catch( function ( error ) {
			if ( asking !== state.asking ) {
				return;
			}
			box.textContent = '';
			box.appendChild( h( 'p', { class: 'error', text: error.message } ) );
			box.appendChild( h( 'button', { class: 'btn small ghost', onmousedown: keepFocus, onclick: function () {
				showAsk( item );
			} }, [ 'Back' ] ) );
			position( box, item.el, 'below' );
		} );
	}

	function showProposal( item, before, data ) {
		var box = ui.ask;
		box.textContent = '';
		box.appendChild( h( 'div', { class: 'proposal' }, [
			h( 'p', { class: 'was' }, [ h( 'span', { class: 'tb-label', text: 'Now' } ), before ] ),
			h( 'p', { class: 'new' }, [ h( 'span', { class: 'tb-label', text: 'Claude suggests' } ), data.text ] ),
			data.note ? h( 'p', { class: 'meta', text: data.note } ) : null,
			h( 'div', { class: 'row' }, [
				h( 'button', { class: 'btn small primary', onmousedown: keepFocus, onclick: function () {
					setText( item.el, data.text );
					touch( item );
					placeToolbar( item );
					showAsk( item );
					announce( 'Used Claude\'s version. It is not live until you publish.' );
				} }, [ 'Use this' ] ),
				h( 'button', { class: 'btn small ghost', onmousedown: keepFocus, onclick: function () {
					showAsk( item );
				} }, [ 'Keep mine' ] )
			] )
		] ) );
		position( box, item.el, 'below' );
		box.querySelector( '.btn.primary' ).focus( { preventScroll: true } );
	}

	/** Plain text into an element, line breaks kept as <br>. */
	function setText( el, text ) {
		el.textContent = '';
		String( text ).split( /\r?\n/ ).forEach( function ( line, i ) {
			if ( i ) {
				el.appendChild( document.createElement( 'br' ) );
			}
			el.appendChild( document.createTextNode( line ) );
		} );
	}

	/** Places a floating element next to a page element (fixed position, kept inside the viewport). */
	function position( floating, target, where ) {
		var rect = target.getBoundingClientRect();
		var box = floating.getBoundingClientRect();
		var gap = 10;
		var top = 'above' === where ? rect.top - box.height - gap : rect.bottom + gap;
		if ( top < 8 ) {
			top = rect.bottom + gap;
		}
		if ( top + box.height > window.innerHeight - 90 ) {
			top = Math.max( 8, rect.top - box.height - gap );
		}
		var left = Math.min( Math.max( 8, rect.left ), window.innerWidth - box.width - 8 );
		floating.style.top = Math.round( top ) + 'px';
		floating.style.left = Math.round( left ) + 'px';
	}

	/* ---------- Hover chip ---------- */

	function showChip( item ) {
		if ( state.active === item.id || state.popover === item.id ) {
			return;
		}
		var label = 'image' === item.kind ? 'Image · click to replace' : ( 'link' === item.kind ? 'Link · click to change address' : ( LABELS[ item.tag ] || 'Text' ) );
		ui.chip.textContent = label;
		ui.chip.classList.add( 'show' );
		var rect = item.el.getBoundingClientRect();
		ui.chip.style.left = Math.round( Math.max( 6, rect.left - 3 ) ) + 'px';
		ui.chip.style.top = Math.round( rect.top < 30 ? rect.bottom + 6 : rect.top - 26 ) + 'px';
	}

	function hideChip() {
		ui.chip.classList.remove( 'show' );
	}

	/** "Replace photo" on the image under the pointer: straight to the Media Library. */
	function showPhoto( item ) {
		var rect = item.el.getBoundingClientRect();
		if ( rect.width < 120 || rect.height < 80 || state.popover === item.id ) {
			hidePhoto();
			return;
		}
		state.photo = item.id;
		ui.photo.classList.add( 'show' );
		placePhoto();
	}

	function placePhoto() {
		var item = state.photo && state.items[ state.photo ];
		if ( ! item ) {
			return;
		}
		var rect = item.el.getBoundingClientRect();
		var width = ui.photo.offsetWidth || 150;
		ui.photo.style.top = Math.round( Math.max( 8, rect.top ) + 14 ) + 'px';
		ui.photo.style.left = Math.round( Math.min( rect.right, window.innerWidth ) - width - 14 ) + 'px';
	}

	function hidePhoto() {
		state.photo = null;
		ui.photo.classList.remove( 'show' );
	}

	/* ---------- Popovers: images and link addresses ---------- */

	function closePopover() {
		if ( ! state.popover ) {
			return;
		}
		var item = state.items[ state.popover ];
		state.popover = null;
		ui.popover.classList.remove( 'show' );
		ui.popover.textContent = '';
		if ( item ) {
			refreshItem( item );
		}
	}

	function openPopover( item, build ) {
		deactivate();
		closePopover();
		state.popover = item.id;
		refreshItem( item );
		ui.popover.textContent = '';
		build( ui.popover );
		ui.popover.classList.add( 'show' );
		position( ui.popover, item.el, 'below' );
		var first = ui.popover.querySelector( 'input, button' );
		if ( first ) {
			first.focus();
		}
	}

	function openImagePopover( item ) {
		hidePhoto();
		openPopover( item, function ( box ) {
			var el = item.el;
			var thumb = h( 'img', { class: 'thumb', src: el.currentSrc || el.getAttribute( 'src' ), alt: '' } );
			var alt = h( 'input', { type: 'text', value: el.getAttribute( 'alt' ) || '', placeholder: 'Describe the image for screen readers and Google', 'aria-label': 'Alt text' } );
			alt.addEventListener( 'input', function () {
				el.setAttribute( 'alt', alt.value );
				touch( item );
			} );
			box.appendChild( h( 'div', { class: 'pop-head' }, [ icon( 'image' ), h( 'b', { text: 'Image' } ), h( 'button', { class: 'icon-btn small', 'aria-label': 'Close', onclick: closePopover }, [ icon( 'close' ) ] ) ] ) );
			box.appendChild( thumb );
			box.appendChild( h( 'div', { class: 'row' }, [
				h( 'button', { class: 'btn primary small', onclick: function () {
					pickImage( item, thumb, alt );
				} }, [ 'Replace image' ] ),
				edit( item ) ? h( 'button', { class: 'btn ghost small', onclick: function () {
					revert( item );
					closePopover();
				} }, [ 'Revert' ] ) : null
			] ) );
			box.appendChild( h( 'label', { class: 'field' }, [ h( 'span', { text: 'Alt text' } ), alt ] ) );
		} );
	}

	/** The Media Library picker. thumb and alt are the image popover's, when it is open. */
	function pickImage( item, thumb, alt ) {
		if ( ! window.wp || ! window.wp.media ) {
			toast( 'The Media Library didn\'t load. Reload the page and try again.' );
			return;
		}
		var frame = window.wp.media( { title: 'Replace image', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var file = frame.state().get( 'selection' ).first().toJSON();
			var el = item.el;
			var size = file.sizes && ( file.sizes.full || file.sizes.large ) || file;
			el.setAttribute( 'src', size.url || file.url );
			el.removeAttribute( 'srcset' );
			el.removeAttribute( 'sizes' );
			if ( size.width && el.hasAttribute( 'width' ) ) {
				el.setAttribute( 'width', size.width );
			}
			if ( size.height && el.hasAttribute( 'height' ) ) {
				el.setAttribute( 'height', size.height );
			}
			item.original.sources.forEach( function ( source ) {
				source.remove();
			} );
			if ( file.alt && ! ( alt ? alt.value : el.getAttribute( 'alt' ) ) ) {
				if ( alt ) {
					alt.value = file.alt;
				}
				el.setAttribute( 'alt', file.alt );
			}
			if ( thumb ) {
				thumb.src = size.url || file.url;
			}
			touch( item );
		} );
		frame.open();
	}

	function openLinkPopover( item ) {
		openUrlPrompt( item, item.el.getAttribute( 'href' ) || '', function ( url ) {
			if ( url ) {
				item.el.setAttribute( 'href', url );
				touch( item );
			}
		}, false );
	}

	function openUrlPrompt( item, current, done, removable ) {
		var range = window.getSelection && window.getSelection().rangeCount ? window.getSelection().getRangeAt( 0 ) : null;
		openPopover( item, function ( box ) {
			var input = h( 'input', { type: 'text', value: current || '', placeholder: 'https://, /page/, #section, tel: or mailto:', 'aria-label': 'Link address', inputmode: 'url' } );
			var error = h( 'p', { class: 'error', hidden: true } );
			function save() {
				var url = input.value.trim();
				if ( url && ! /^(https?:\/\/|\/|#|mailto:|tel:|sms:)/i.test( url ) ) {
					if ( /^[\w-]+(\.[\w-]+)+/.test( url ) ) {
						url = 'https://' + url;
					} else {
						error.hidden = false;
						error.textContent = 'Start with https://, a / page path, #section, tel: or mailto:.';
						return;
					}
				}
				closePopover();
				if ( range && 'text' === item.kind ) {
					activate( item );
					var selection = window.getSelection();
					selection.removeAllRanges();
					selection.addRange( range );
				}
				done( url );
			}
			input.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					save();
				}
			} );
			box.appendChild( h( 'div', { class: 'pop-head' }, [ icon( 'link' ), h( 'b', { text: 'Link address' } ), h( 'button', { class: 'icon-btn small', 'aria-label': 'Close', onclick: closePopover }, [ icon( 'close' ) ] ) ] ) );
			box.appendChild( input );
			box.appendChild( error );
			box.appendChild( h( 'div', { class: 'row' }, [
				h( 'button', { class: 'btn primary small', onclick: save }, [ 'Save link' ] ),
				removable ? h( 'button', { class: 'btn ghost small', onclick: function () {
					input.value = '';
					save();
				} }, [ 'Remove link' ] ) : null,
				current ? h( 'a', { class: 'btn ghost small', href: current, target: '_blank', rel: 'noopener' }, [ 'Open', icon( 'arrow' ) ] ) : null
			] ) );
		} );
	}

	/* ---------- Panels: review and history ---------- */

	function openPanel( which ) {
		deactivate();
		closePopover();
		state.panel = which;
		renderPanel();
		ui.panel.classList.add( 'show' );
		ui.scrim.classList.add( 'show' );
		var focus = ui.panel.querySelector( '.panel-head button' );
		if ( focus ) {
			focus.focus();
		}
	}

	function closePanel() {
		state.panel = null;
		ui.panel.classList.remove( 'show' );
		ui.scrim.classList.remove( 'show' );
	}

	function renderPanel() {
		var panel = ui.panel;
		panel.textContent = '';
		if ( 'review' === state.panel ) {
			renderReview( panel );
		} else if ( 'history' === state.panel ) {
			renderHistory( panel );
		}
	}

	function panelHead( title, sub ) {
		return h( 'div', { class: 'panel-head' }, [
			h( 'div', {}, [ h( 'p', { class: 'eyebrow', text: sub } ), h( 'h2', { text: title } ) ] ),
			h( 'button', { class: 'icon-btn', 'aria-label': 'Close panel', onclick: closePanel }, [ icon( 'close' ) ] )
		] );
	}

	function renderReview( panel ) {
		var edits = changed();
		panel.appendChild( panelHead( 'Review ' + plural( edits.length, 'change', 'changes' ), cfg.title ) );
		var list = h( 'ol', { class: 'changes' } );
		edits.forEach( function ( e ) {
			var item = state.items[ e.id ];
			var body;
			if ( 'image' === item.kind ) {
				body = h( 'div', { class: 'img-diff' }, [
					h( 'img', { src: item.original.src, alt: '', class: 'before' } ),
					icon( 'arrow' ),
					h( 'img', { src: item.el.getAttribute( 'src' ), alt: '', class: 'after' } )
				] );
				if ( undefined !== e.alt ) {
					body = h( 'div', {}, [ body, h( 'p', { class: 'meta', text: 'Alt text: ' + ( e.alt || '(none)' ) } ) ] );
				}
			} else {
				var parts = [];
				if ( undefined !== e.html ) {
					parts.push( h( 'p', { class: 'diff' }, diffWords( textOf( item.original.html ), textOf( item.el.innerHTML ) ).map( function ( piece ) {
						return 'same' === piece[ 0 ] ? document.createTextNode( piece[ 1 ] ) : h( 'same' === piece[ 0 ] ? 'span' : piece[ 0 ], { text: piece[ 1 ] } );
					} ) ) );
					if ( ! textOf( item.el.innerHTML ) ) {
						parts.push( h( 'p', { class: 'warn', text: 'This will be empty on the page.' } ) );
					}
				}
				if ( undefined !== e.href ) {
					parts.push( h( 'p', { class: 'meta' }, [ h( 'del', { text: item.original.href || '(none)' } ), ' → ', h( 'ins', { text: e.href } ) ] ) );
				}
				body = h( 'div', {}, parts );
			}
			list.appendChild( h( 'li', {}, [
				h( 'div', { class: 'change-head' }, [
					h( 'span', { class: 'tag', text: LABELS[ item.tag ] || 'Text' } ),
					h( 'button', { class: 'btn tiny ghost', onclick: function () {
						closePanel();
						flash( item );
					} }, [ icon( 'eye' ), 'Show' ] ),
					h( 'button', { class: 'btn tiny ghost', onclick: function () {
						revert( item );
					} }, [ icon( 'undo' ), 'Undo' ] )
				] ),
				body
			] ) );
		} );
		panel.appendChild( list );
		if ( ! edits.length ) {
			panel.appendChild( h( 'p', { class: 'empty', text: 'No changes left. Click any outlined text or image on the page to edit it.' } ) );
		}
		var note = h( 'input', { type: 'text', class: 'note', placeholder: 'What changed, for the history (optional)', 'aria-label': 'Note for the history', maxlength: 200 } );
		var foot = h( 'div', { class: 'panel-foot' }, [
			note,
			cfg.draft ? h( 'p', { class: 'meta', text: 'A Studio draft is waiting. These changes are copied into it too.' } ) : null,
			h( 'div', { class: 'row end' }, [
				h( 'button', { class: 'btn ghost', onclick: closePanel }, [ 'Keep editing' ] ),
				h( 'button', { class: 'btn primary big', disabled: ! edits.length, 'aria-keyshortcuts': 'Control+Enter Meta+Enter', onclick: function () {
					publish( note.value );
				} }, [ 'Publish', h( 'kbd', { text: '⌘↵' } ) ] )
			] )
		] );
		panel.appendChild( foot );
		ui.publishNote = note;
	}

	var SOURCE = { 'live-edit': 'Live Edit', studio: 'Studio', restore: 'Restored', migration: 'Moved off Elementor', admin: 'Deft pages screen' };

	var WHAT = { 'live-edit': 'Changed in Live Edit', studio: 'Page published from Studio', restore: 'Older version put back', migration: 'Moved off Elementor', admin: 'Published from the Deft pages screen' };

	/** Revisions, kept 30 days: what changed, who and when. The live one says Live; any other is a Restore away. */
	function renderHistory( panel ) {
		panel.appendChild( panelHead( 'Revisions', 'Kept 30 days' ) );
		var list = h( 'ol', { class: 'timeline' }, [ h( 'li', { class: 'loading', text: 'Loading revisions…' } ) ] );
		panel.appendChild( list );
		panel.appendChild( h( 'div', { class: 'panel-foot' }, [
			h( 'p', { class: 'meta', text: 'Every publish saves a revision. Restore puts an older one back live, and the one it replaces stays here. Clients never see these controls.' } )
		] ) );
		api( '/revisions' ).then( function ( data ) {
			list.textContent = '';
			if ( ! data.revisions.length ) {
				list.appendChild( h( 'li', { class: 'empty', text: 'No revisions yet. The first publish starts the list.' } ) );
			}
			data.revisions.forEach( function ( rev, index ) {
				var current = rev.hash === state.base && 0 === index;
				var changes = ( rev.changes || [] ).slice( 0, 3 ).map( function ( c ) {
					return h( 'li', {}, 'text' === c.kind ? [ h( 'del', { text: shorten( c.before ) } ), ' → ', h( 'ins', { text: shorten( c.after ) } ) ] : [ 'image' === c.kind ? 'Photo replaced' : 'Link → ' + shorten( c.after ) ] );
				} );
				var actions = h( 'div', { class: 'row' } );
				list.appendChild( h( 'li', { class: current ? 'current' : '' }, [
					h( 'span', { class: 'node' } ),
					h( 'div', { class: 'rev' }, [
						h( 'p', { class: 'rev-what', text: rev.note || WHAT[ rev.source ] || 'Published' } ),
						h( 'p', { class: 'rev-head', text: ( rev.actor || 'Someone' ) + ' · ' + ago( rev.created ) } ),
						changes.length ? h( 'ul', { class: 'mini' }, changes ) : null,
						actions
					] ),
					current ? h( 'span', { class: 'tag live', text: 'Live' } ) : h( 'button', { class: 'btn tiny ghost', 'aria-label': 'Restore the revision from ' + ago( rev.created ), onclick: function () {
						confirmRestore( actions, rev );
					} }, [ icon( 'restore' ), 'Restore' ] )
				] ) );
			} );
		} ).catch( function ( error ) {
			list.textContent = '';
			list.appendChild( h( 'li', { class: 'error', text: error.message } ) );
		} );
	}

	function shorten( text ) {
		text = String( text || '' );
		return text.length > 60 ? text.slice( 0, 57 ) + '…' : text;
	}

	function confirmRestore( actions, rev ) {
		actions.textContent = '';
		actions.appendChild( h( 'span', { class: 'meta', text: changed().length ? 'Put this revision back live? Your unsaved changes are thrown away, and the live one stays in the list.' : 'Put this revision back live? The live one stays in the list.' } ) );
		actions.appendChild( h( 'button', { class: 'btn tiny primary', onclick: function () {
			actions.textContent = 'Restoring…';
			api( '/restore', { method: 'POST', body: { revision: rev.id } } ).then( function () {
				clearPending();
				toast( 'Restored. Reloading the page…', { tone: 'good' } );
				setTimeout( function () {
					window.location.reload();
				}, 700 );
			} ).catch( function ( error ) {
				actions.textContent = error.message;
			} );
		} }, [ 'Restore' ] ) );
		actions.appendChild( h( 'button', { class: 'btn tiny ghost', onclick: function () {
			renderPanel();
		} }, [ 'Cancel' ] ) );
	}

	/* ---------- Desktop and Phone ---------- */

	function setView( view ) {
		if ( view === state.view ) {
			return;
		}
		state.view = view;
		if ( 'phone' === view ) {
			openPhone();
		} else {
			ui.phone.classList.remove( 'show' );
			ui.phone.textContent = '';
			ui.phoneFrame = null;
		}
		renderDock();
	}

	/**
	 * The page at 390 x 844 in a frame (deft_frame=1, so no Live Edit, admin bar or tracking inside it), with the
	 * unsaved changes copied in. Clicking an outlined part goes back to Desktop with that part open.
	 */
	function openPhone() {
		deactivate();
		closePopover();
		closePanel();
		hidePhoto();
		hideChip();
		var url = new URL( liveUrl() );
		url.searchParams.set( 'deft_frame', '1' );
		var frame = h( 'iframe', { title: 'This page on a phone', src: url.toString() } );
		frame.addEventListener( 'load', function () {
			wirePhone();
			syncPhone();
		} );
		ui.phoneFrame = frame;
		ui.phone.textContent = '';
		ui.phone.appendChild( h( 'div', { class: 'phone-col' }, [
			h( 'div', { class: 'phone-fit' }, [ h( 'div', { class: 'device' }, [ frame ] ) ] ),
			h( 'p', { class: 'phone-note', text: changed().length ? 'Your changes are in, not live yet. Click an outlined part to edit it.' : 'Click an outlined part to edit it.' } )
		] ) );
		ui.phone.classList.add( 'show' );
		fitPhone();
	}

	function fitPhone() {
		var fit = ui.phone.querySelector( '.phone-fit' );
		if ( ! fit ) {
			return;
		}
		var scale = Math.min( 1, ( window.innerHeight - 150 ) / 868, ( window.innerWidth - 32 ) / 414 );
		fit.style.width = Math.round( 414 * scale ) + 'px';
		fit.style.height = Math.round( 868 * scale ) + 'px';
		fit.firstChild.style.transform = 'scale(' + scale + ')';
	}

	function phoneDoc() {
		try {
			return ui.phoneFrame && ui.phoneFrame.contentDocument && ui.phoneFrame.contentDocument.body ? ui.phoneFrame.contentDocument : null;
		} catch ( e ) {
			return null;
		}
	}

	/** Copies each editable part's current text, link or image into the phone frame. */
	function syncPhone() {
		var doc = phoneDoc();
		if ( ! doc ) {
			return;
		}
		state.order.forEach( function ( id ) {
			var item = state.items[ id ];
			var el = doc.querySelector( '[data-deft-edit="' + id + '"]' );
			if ( ! el ) {
				return;
			}
			if ( 'image' === item.kind ) {
				[ 'src', 'srcset', 'sizes', 'alt' ].forEach( function ( attr ) {
					var value = item.el.getAttribute( attr );
					if ( null === value ) {
						el.removeAttribute( attr );
					} else if ( el.getAttribute( attr ) !== value ) {
						el.setAttribute( attr, value );
					}
				} );
				if ( el.parentElement && 'PICTURE' === el.parentElement.tagName && ! item.el.parentElement.querySelector( 'source' ) ) {
					el.parentElement.querySelectorAll( 'source' ).forEach( function ( source ) {
						source.remove();
					} );
				}
			} else {
				if ( 'text' === item.kind && el.innerHTML !== item.el.innerHTML ) {
					el.innerHTML = item.el.innerHTML;
				}
				if ( null !== item.original.href ) {
					el.setAttribute( 'href', item.el.getAttribute( 'href' ) || '' );
				}
			}
			if ( edit( item ) ) {
				el.setAttribute( 'data-deft-le', 'changed' );
			} else {
				el.removeAttribute( 'data-deft-le' );
			}
		} );
	}

	function wirePhone() {
		var doc = phoneDoc();
		if ( ! doc || doc.getElementById( 'deft-le-page' ) ) {
			return;
		}
		var style = doc.createElement( 'style' );
		style.id = 'deft-le-page';
		style.textContent = '[data-deft-edit]{outline:1.5px dashed rgba(51,80,240,.55);outline-offset:2px;cursor:pointer}' +
			'[data-deft-le="changed"]{outline:2px solid rgba(255,199,0,.95)}';
		doc.head.appendChild( style );
		doc.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var el = e.target.closest && e.target.closest( '[data-deft-edit]' );
			if ( ! el ) {
				return;
			}
			if ( ! state.on ) {
				start();
			}
			var item = null;
			while ( el && ! item ) {
				item = state.items[ el.getAttribute( 'data-deft-edit' ) ] || null;
				el = el.parentElement && el.parentElement.closest( '[data-deft-edit]' );
			}
			if ( ! item ) {
				return;
			}
			setView( 'desktop' );
			item.el.scrollIntoView( { behavior: 'auto', block: 'center' } );
			if ( 'image' === item.kind ) {
				openImagePopover( item );
			} else if ( 'link' === item.kind ) {
				openLinkPopover( item );
			} else {
				activate( item );
			}
		}, true );
		doc.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
		}, true );
	}

	/* ---------- Publishing ---------- */

	function publish( note ) {
		var edits = changed();
		if ( ! edits.length || state.busy ) {
			return;
		}
		deactivate();
		closePanel();
		var startedAt = performance.now();
		var steps = [ 'Saving a revision', 'Clearing the cache', 'Checking the live page' ];
		function progress( at ) {
			state.busy = steps.map( function ( label, i ) {
				return { label: label, state: i < at ? 'done' : ( i === at ? 'now' : 'wait' ) };
			} );
			renderDock();
		}
		progress( 0 );
		var stepTimer = setTimeout( function () {
			if ( state.busy ) {
				progress( 1 );
			}
		}, 450 );
		api( '/edits', { method: 'POST', body: { base: state.base, edits: edits, note: note || '' } } ).then( function ( data ) {
			clearTimeout( stepTimer );
			progress( 2 );
			var previousBase = state.base;
			state.base = data.hash || state.base;
			state.order.forEach( function ( id ) {
				var item = state.items[ id ];
				item.original = snapshot( item.el, item.kind );
				refreshItem( item );
			} );
			state.history = [];
			clearPending();
			return checkLive( data.hash ).then( function ( confirmed ) {
				var seconds = Math.max( 1, Math.round( ( performance.now() - startedAt ) / 1000 ) );
				state.busy = false;
				renderDock();
				var extra = data.draft_updated ? ' Copied into the waiting Studio draft too.' : '';
				toast( ( confirmed ? 'Live in ' + plural( seconds, 'second', 'seconds' ) + '. Cache cleared.' : 'Published. The public page can take a minute to catch up while caches clear.' ) + extra, {
					tone: confirmed ? 'good' : '',
					ms: 12000,
					link: { label: 'View page', href: liveUrl() },
					action: previousBase ? { label: 'Undo', run: function () {
						undoPublish( data.revision );
					} } : null
				} );
			} );
		} ).catch( function ( error ) {
			clearTimeout( stepTimer );
			state.busy = false;
			renderDock();
			if ( 409 === error.status ) {
				savePending();
				toast( error.message, { ms: 20000, action: { label: 'Reload and keep my edits', run: function () {
					var url = new URL( window.location.href );
					url.searchParams.set( 'deft_edit', '1' );
					window.location.href = url.toString();
				} } } );
			} else {
				toast( 'Not published: ' + error.message, { ms: 15000 } );
			}
		} );
	}

	/** The page's public address, without Live Edit's own parameters. */
	function liveUrl() {
		var url = new URL( window.location.href );
		url.searchParams.delete( 'deft_edit' );
		url.hash = '';
		return url.toString();
	}

	/**
	 * Loads the page the way a visitor would (no login cookie) and looks for the version just published in its
	 * deft-page meta tag. Tries a few times, since a CDN can take a moment. Resolves true or false, never fails.
	 */
	function checkLive( hash ) {
		if ( ! hash ) {
			return Promise.resolve( false );
		}
		var want = hash.slice( 0, 12 );
		var waits = [ 0, 1200, 2500, 4000 ];
		function attempt( i ) {
			return new Promise( function ( resolve ) {
				setTimeout( resolve, waits[ i ] );
			} ).then( function () {
				return fetch( liveUrl(), { credentials: 'omit', cache: 'no-store' } );
			} ).then( function ( response ) {
				return response.text();
			} ).then( function ( html ) {
				var found = /<meta name="deft-page" content="([0-9a-f]{12})"/.exec( html );
				if ( found && found[ 1 ] === want ) {
					return true;
				}
				return i + 1 < waits.length ? attempt( i + 1 ) : false;
			} ).catch( function () {
				return i + 1 < waits.length ? attempt( i + 1 ) : false;
			} );
		}
		return attempt( 0 );
	}

	/** Puts back the version before the one just published. */
	function undoPublish( revision ) {
		api( '/revisions' ).then( function ( data ) {
			var index = data.revisions.findIndex( function ( r ) {
				return r.id === revision;
			} );
			var previous = index >= 0 ? data.revisions[ index + 1 ] : null;
			if ( ! previous ) {
				throw new Error( 'There is no earlier version to go back to.' );
			}
			return api( '/restore', { method: 'POST', body: { revision: previous.id } } );
		} ).then( function () {
			toast( 'Undone. Reloading the page…', { tone: 'good' } );
			setTimeout( function () {
				window.location.reload();
			}, 700 );
		} ).catch( function ( error ) {
			toast( error.message );
		} );
	}

	/* ---------- Unsaved edits survive a reload ---------- */

	function savePending() {
		try {
			var edits = changed();
			if ( edits.length ) {
				sessionStorage.setItem( STORE, JSON.stringify( { edits: edits, at: Date.now() } ) );
			} else {
				sessionStorage.removeItem( STORE );
			}
		} catch ( e ) {}
	}

	function clearPending() {
		try {
			sessionStorage.removeItem( STORE );
		} catch ( e ) {}
	}

	function restorePending() {
		var saved;
		try {
			saved = JSON.parse( sessionStorage.getItem( STORE ) || 'null' );
		} catch ( e ) {
			saved = null;
		}
		if ( ! saved || ! saved.edits || Date.now() - saved.at > 6 * 3600 * 1000 ) {
			return 0;
		}
		var count = 0;
		saved.edits.forEach( function ( e ) {
			var item = state.items[ e.id ];
			if ( ! item ) {
				return;
			}
			if ( undefined !== e.html ) {
				item.el.innerHTML = e.html;
			}
			if ( undefined !== e.href ) {
				item.el.setAttribute( 'href', e.href );
			}
			if ( undefined !== e.src ) {
				item.el.setAttribute( 'src', e.src );
				item.el.removeAttribute( 'srcset' );
				item.el.removeAttribute( 'sizes' );
				item.original.sources.forEach( function ( source ) {
					source.remove();
				} );
			}
			if ( undefined !== e.alt ) {
				item.el.setAttribute( 'alt', e.alt );
			}
			touch( item );
			count++;
		} );
		return count;
	}

	/* ---------- Events ---------- */

	function itemFrom( target ) {
		if ( ! target || ! target.closest || ui.host.contains( target ) ) {
			return null;
		}
		var el = target.closest( '[data-deft-edit]' );
		while ( el ) {
			var item = state.items[ el.getAttribute( 'data-deft-edit' ) ];
			if ( item ) {
				return item;
			}
			el = el.parentElement && el.parentElement.closest( '[data-deft-edit]' );
		}
		return null;
	}

	function isTyping( target ) {
		return target && ( target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) );
	}

	document.addEventListener( 'click', function ( e ) {
		var path = e.composedPath ? e.composedPath() : [];
		if ( path.indexOf( ui.host ) >= 0 ) {
			return;
		}
		var bar = e.target.closest && e.target.closest( '#wp-admin-bar-deft-live-edit' );
		if ( bar ) {
			e.preventDefault();
			if ( state.on ) {
				stop();
			} else {
				start();
			}
			return;
		}
		if ( ! state.on ) {
			return;
		}
		if ( e.target.closest && e.target.closest( '#wpadminbar' ) ) {
			return;
		}
		var item = itemFrom( e.target );
		// While editing, links and buttons on the page don't navigate or submit (Ctrl/Cmd-click still opens links).
		var link = e.target.closest && e.target.closest( 'a[href], button, [type=submit]' );
		if ( link && ! ( link.tagName === 'A' && ( e.metaKey || e.ctrlKey ) ) ) {
			e.preventDefault();
		}
		if ( ! item ) {
			deactivate();
			closePopover();
			return;
		}
		if ( 'image' === item.kind ) {
			openImagePopover( item );
		} else if ( 'link' === item.kind ) {
			openLinkPopover( item );
		} else {
			activate( item );
		}
	}, true );

	document.addEventListener( 'submit', function ( e ) {
		if ( state.on ) {
			e.preventDefault();
		}
	}, true );

	document.addEventListener( 'mouseover', function ( e ) {
		if ( ! state.on || state.busy ) {
			return;
		}
		if ( e.target === ui.host ) {
			return; // On our own controls, such as the Replace photo button.
		}
		var item = itemFrom( e.target );
		if ( item ) {
			showChip( item );
		} else {
			hideChip();
		}
		if ( item && 'image' === item.kind ) {
			showPhoto( item );
		} else {
			hidePhoto();
		}
	} );

	document.addEventListener( 'input', function ( e ) {
		var item = state.active && state.items[ state.active ];
		if ( item && item.el.contains( e.target ) ) {
			touch( item );
			placeToolbar( item );
			if ( ui.ask.classList.contains( 'show' ) && ! state.asking ) {
				showAsk( item ); // The words changed; a chip or the ask box can vanish if a link appeared.
			}
		}
	} );

	document.addEventListener( 'paste', function ( e ) {
		var item = state.active && state.items[ state.active ];
		if ( ! item || ! item.el.contains( e.target ) ) {
			return;
		}
		// Paste words, not someone else's formatting.
		e.preventDefault();
		var text = ( e.clipboardData || window.clipboardData ).getData( 'text/plain' ).replace( /\r\n?/g, '\n' );
		if ( SINGLE_LINE[ item.tag ] ) {
			text = text.replace( /\s*\n\s*/g, ' ' );
		}
		document.execCommand( 'insertText', false, text );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		var mod = e.metaKey || e.ctrlKey;
		var item = state.active && state.items[ state.active ];
		var inUi = e.composedPath && e.composedPath().indexOf( ui.host ) >= 0;
		if ( 'Escape' === e.key && 'phone' === state.view && ! state.panel ) {
			e.preventDefault();
			setView( 'desktop' );
			return;
		}
		if ( item && 'Enter' === e.key && ! mod && ! inUi ) {
			// No new paragraphs inside an element: Enter is a line break, and in headings and buttons it ends editing.
			e.preventDefault();
			if ( e.shiftKey || ! SINGLE_LINE[ item.tag ] ) {
				document.execCommand( 'insertLineBreak' );
			} else {
				deactivate();
			}
			return;
		}
		if ( 'Escape' === e.key && state.on ) {
			e.preventDefault();
			if ( state.active ) {
				deactivate();
			} else if ( state.popover ) {
				closePopover();
			} else if ( state.panel ) {
				closePanel();
			} else {
				stop();
			}
			return;
		}
		if ( mod && 's' === e.key.toLowerCase() && state.on ) {
			e.preventDefault();
			if ( 'review' === state.panel ) {
				publish( ui.publishNote ? ui.publishNote.value : '' );
			} else if ( changed().length ) {
				openPanel( 'review' );
			}
			return;
		}
		if ( mod && 'Enter' === e.key && 'review' === state.panel ) {
			e.preventDefault();
			publish( ui.publishNote ? ui.publishNote.value : '' );
			return;
		}
		if ( ! mod && ! e.altKey && 'e' === e.key.toLowerCase() && ! isTyping( e.target ) && ! inUi && ! state.busy ) {
			e.preventDefault();
			if ( state.on ) {
				stop();
			} else {
				start();
			}
		}
	} );

	function follow() {
		if ( state.active && state.items[ state.active ] ) {
			position( ui.toolbar, state.items[ state.active ].el, 'above' );
		}
		if ( state.active && ui.ask.classList.contains( 'show' ) && state.items[ state.active ] ) {
			position( ui.ask, state.items[ state.active ].el, 'below' );
		}
		if ( state.popover && state.items[ state.popover ] ) {
			position( ui.popover, state.items[ state.popover ].el, 'below' );
		}
		placePhoto();
		hideChip();
	}
	window.addEventListener( 'scroll', follow, { passive: true } );
	window.addEventListener( 'resize', function () {
		follow();
		fitPhone();
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( state.on && changed().length ) {
			savePending();
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	function boot() {
		buildUI();
		if ( cfg.autostart ) {
			start();
			var url = new URL( window.location.href );
			url.searchParams.delete( 'deft_edit' );
			window.history.replaceState( null, '', url.toString() );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
