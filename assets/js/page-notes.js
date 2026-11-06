/**
 * LU3G Page Notes: livello delle note sopra la pagina.
 *
 * - Pulsante "Note LU3G" nella barra admin: accende e spegne le note (lo stato resta tra una pagina e l'altra).
 * - "Nuova nota": si trascina per disegnare una zona, si scrive il testo e si salva.
 * - Ogni zona ha un numero; cliccandola si apre la nota con le risposte, "Risolta" / "Riapri", modifica ed elimina.
 * - "Elenco": tutte le note della pagina, anche quelle risolte.
 *
 * Posizione delle zone: la zona si aggancia all'elemento della pagina che la contiene (selettore CSS, preferendo
 * id e data-id di Elementor) con coordinate in frazioni del suo riquadro, così segue l'elemento quando la finestra
 * cambia larghezza. Se l'elemento non c'è più o è nascosto, si usano le coordinate nella pagina intera e la nota
 * dice che la posizione è approssimativa.
 *
 * Tutto il testo si inserisce con textContent: niente HTML da utenti.
 */
( function () {
	'use strict';

	var C = window.LU3G_PAGE_NOTES;
	if ( ! C || ! window.fetch || ! document.body ) {
		return;
	}

	var STORE_ON = 'lu3g-pn-on';
	var STORE_RESOLVED = 'lu3g-pn-resolved';
	var MIN_SIZE = 12;

	var state = {
		notes: [],
		on: false,
		showResolved: false,
		openId: null,
		loading: false,
		error: ''
	};

	var ui = {};

	/* ------------------------------------------------------------------ utilità */

	function store( key, value ) {
		try {
			if ( undefined === value ) {
				return window.localStorage.getItem( key );
			}
			if ( null === value ) {
				window.localStorage.removeItem( key );
			} else {
				window.localStorage.setItem( key, value );
			}
		} catch ( e ) {}
		return null;
	}

	/** Crea un elemento: h( 'button', { class: 'x', onclick: fn }, [ 'testo', altroNodo ] ). */
	function h( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( 0 === key.indexOf( 'on' ) && 'function' === typeof value ) {
				node.addEventListener( key.slice( 2 ), value );
			} else if ( 'class' === key ) {
				node.className = value;
			} else if ( 'text' === key ) {
				node.textContent = value;
			} else if ( true === value ) {
				node.setAttribute( key, '' );
			} else {
				node.setAttribute( key, value );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( null === child || undefined === child || false === child ) {
				return;
			}
			node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function pageUrl() {
		return window.location.href.split( '#' )[ 0 ];
	}

	function apiUrl( path, query ) {
		var url = C.rest + path;
		if ( query ) {
			url += ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + query;
		}
		return url;
	}

	function api( method, path, body, query ) {
		return window.fetch( apiUrl( path, query ), {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': C.nonce,
				'Content-Type': 'application/json',
				Accept: 'application/json'
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || 'Errore ' + res.status + ': riprova.' );
				}
				return data;
			} );
		} );
	}

	function formatDate( iso ) {
		var d = new Date( iso );
		if ( isNaN( d.getTime() ) ) {
			return '';
		}
		try {
			return d.toLocaleString( 'it-IT', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' } );
		} catch ( e ) {
			return d.toLocaleString();
		}
	}

	function isOurs( node ) {
		return !! ( node && node.closest && node.closest( '.lu3g-pn-ui, #wpadminbar' ) );
	}

	function viewportWidth() {
		return document.documentElement.clientWidth || window.innerWidth;
	}

	/** Riquadro di un elemento in coordinate della pagina. */
	function pageRect( el ) {
		var r = el.getBoundingClientRect();
		return { x: r.left + window.scrollX, y: r.top + window.scrollY, w: r.width, h: r.height };
	}

	function noteById( id ) {
		for ( var i = 0; i < state.notes.length; i++ ) {
			if ( state.notes[ i ].id === id ) {
				return state.notes[ i ];
			}
		}
		return null;
	}

	function numberOf( note ) {
		return state.notes.indexOf( note ) + 1;
	}

	function visibleNotes() {
		return state.notes.filter( function ( n ) {
			return state.showResolved || 'resolved' !== n.status || n.id === state.openId;
		} );
	}

	function openCount() {
		return state.notes.filter( function ( n ) {
			return 'resolved' !== n.status;
		} ).length;
	}

	/* ------------------------------------------------------------------ aggancio delle zone */

	function cssEscape( value ) {
		if ( window.CSS && window.CSS.escape ) {
			return window.CSS.escape( value );
		}
		return String( value ).replace( /[^a-zA-Z0-9_-]/g, function ( ch ) {
			return '\\' + ch;
		} );
	}

	function unique( selector ) {
		try {
			return 1 === document.querySelectorAll( selector ).length;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Selettore stabile per un elemento: si risale fino al primo antenato con un id o un data-id di Elementor unico,
	 * poi tag:nth-of-type per ogni livello.
	 */
	function selectorFor( el ) {
		var parts = [];
		var cur = el;
		while ( cur && cur.nodeType === 1 && cur !== document.body && cur !== document.documentElement ) {
			if ( cur.id && ! /^(lu3g-pn|wp-|elementor-popup)/.test( cur.id ) ) {
				var byId = '#' + cssEscape( cur.id );
				if ( unique( byId ) ) {
					parts.unshift( byId );
					return parts.join( ' > ' );
				}
			}
			var dataId = cur.getAttribute( 'data-id' );
			if ( dataId && cur.classList.contains( 'elementor-element' ) ) {
				var byData = '.elementor-element[data-id="' + cssEscape( dataId ) + '"]';
				if ( unique( byData ) ) {
					parts.unshift( byData );
					return parts.join( ' > ' );
				}
			}
			var index = 1;
			var sib = cur;
			while ( ( sib = sib.previousElementSibling ) ) {
				if ( sib.tagName === cur.tagName ) {
					index++;
				}
			}
			parts.unshift( cur.tagName.toLowerCase() + ':nth-of-type(' + index + ')' );
			cur = cur.parentElement;
		}
		parts.unshift( 'body' );
		return parts.join( ' > ' );
	}

	/** Elemento più interno che contiene tutta la zona (zona in coordinate della pagina). */
	function anchorFor( zone ) {
		var cx = zone.x + zone.w / 2 - window.scrollX;
		var cy = zone.y + zone.h / 2 - window.scrollY;
		var stack = document.elementsFromPoint ? document.elementsFromPoint( cx, cy ) : [];
		var start = null;
		for ( var i = 0; i < stack.length; i++ ) {
			if ( ! isOurs( stack[ i ] ) ) {
				start = stack[ i ];
				break;
			}
		}
		var el = start;
		while ( el && el !== document.body && el !== document.documentElement ) {
			var r = pageRect( el );
			if ( r.w > 0 && r.h > 0 && r.x <= zone.x + 2 && r.y <= zone.y + 2 && r.x + r.w >= zone.x + zone.w - 2 && r.y + r.h >= zone.y + zone.h - 2 ) {
				break;
			}
			el = el.parentElement;
		}
		if ( ! el || el === document.documentElement ) {
			el = document.body;
		}
		var box = pageRect( el );
		if ( ! box.w || ! box.h ) {
			return null;
		}
		return {
			selector: selectorFor( el ),
			x: ( zone.x - box.x ) / box.w,
			y: ( zone.y - box.y ) / box.h,
			w: zone.w / box.w,
			h: zone.h / box.h
		};
	}

	function docFor( zone ) {
		var vw = viewportWidth();
		return { x: zone.x / vw, y: Math.round( zone.y ), w: zone.w / vw, h: Math.round( zone.h ), vw: vw };
	}

	/** Zona di una nota in coordinate della pagina, oggi. exact = false se si usa la posizione di riserva. */
	function zoneOf( note ) {
		var a = note.anchor;
		if ( a && a.selector ) {
			var el = null;
			try {
				el = 'body' === a.selector ? document.body : document.querySelector( a.selector );
			} catch ( e ) {}
			if ( el ) {
				var r = pageRect( el );
				var visible = r.w > 0 && r.h > 0 && 'hidden' !== window.getComputedStyle( el ).visibility;
				if ( visible ) {
					return { x: r.x + a.x * r.w, y: r.y + a.y * r.h, w: a.w * r.w, h: a.h * r.h, exact: true };
				}
			}
		}
		var d = note.doc || { x: 0, y: 0, w: 0.2, h: 80 };
		var vw = viewportWidth();
		return { x: d.x * vw, y: d.y, w: Math.max( d.w * vw, 24 ), h: Math.max( d.h, 24 ), exact: ! a };
	}

	/* ------------------------------------------------------------------ struttura */

	function build() {
		ui.layer = h( 'div', { class: 'lu3g-pn-ui lu3g-pn-layer', 'aria-hidden': 'false' } );

		ui.count = h( 'span', { class: 'lu3g-pn-bar__count' } );
		ui.resolvedToggle = h( 'input', {
			type: 'checkbox',
			onchange: function () {
				state.showResolved = ui.resolvedToggle.checked;
				store( STORE_RESOLVED, state.showResolved ? '1' : null );
				render();
			}
		} );
		ui.bar = h( 'div', { class: 'lu3g-pn-ui lu3g-pn-bar', role: 'toolbar', 'aria-label': 'Note LU3G' }, [
			h( 'button', { type: 'button', class: 'lu3g-pn-btn lu3g-pn-btn--primary', onclick: startDrawing }, [ '+ Nuova nota' ] ),
			h( 'button', { type: 'button', class: 'lu3g-pn-btn', onclick: togglePanel, 'aria-expanded': 'false' }, [ 'Elenco ', ui.count ] ),
			h( 'label', { class: 'lu3g-pn-bar__check' }, [ ui.resolvedToggle, ' Risolte' ] ),
			h( 'button', { type: 'button', class: 'lu3g-pn-btn lu3g-pn-btn--icon', 'aria-label': 'Nascondi le note', title: 'Nascondi le note', onclick: function () { setOn( false ); } }, [ '×' ] )
		] );
		ui.panelButton = ui.bar.children[ 1 ];

		ui.panel = h( 'aside', { class: 'lu3g-pn-ui lu3g-pn-panel', 'aria-label': 'Note della pagina', hidden: true } );

		ui.status = h( 'div', { class: 'lu3g-pn-ui lu3g-pn-toast', role: 'status', 'aria-live': 'polite', hidden: true } );

		document.body.appendChild( ui.layer );
		document.body.appendChild( ui.bar );
		document.body.appendChild( ui.panel );
		document.body.appendChild( ui.status );

		ui.abItem = document.getElementById( 'wp-admin-bar-lu3g-page-notes' );
		if ( ui.abItem ) {
			ui.abCount = ui.abItem.querySelector( '.lu3g-pn-ab-count' );
			var link = ui.abItem.querySelector( 'a' );
			if ( link ) {
				link.setAttribute( 'role', 'button' );
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					setOn( ! state.on );
				} );
			}
		}
	}

	function toast( message, isError ) {
		ui.status.textContent = message;
		ui.status.hidden = ! message;
		ui.status.classList.toggle( 'is-error', !! isError );
		clearTimeout( toast.timer );
		if ( message ) {
			toast.timer = setTimeout( function () {
				ui.status.hidden = true;
			}, isError ? 6000 : 2500 );
		}
	}

	function setOn( on ) {
		state.on = on;
		store( STORE_ON, on ? '1' : null );
		document.documentElement.classList.toggle( 'lu3g-pn-on', on );
		if ( ui.abItem ) {
			ui.abItem.classList.toggle( 'is-active', on );
		}
		if ( ! on ) {
			stopDrawing();
			closePopover();
			ui.panel.hidden = true;
			ui.panelButton.setAttribute( 'aria-expanded', 'false' );
		}
		render();
	}

	/* ------------------------------------------------------------------ disegno */

	var current = 0; // contatore per riconoscere rendering superati

	function render() {
		current++;
		var total = openCount();
		ui.count.textContent = '(' + total + ')';
		if ( ui.abCount ) {
			ui.abCount.textContent = String( total );
			ui.abCount.hidden = ! total;
		}
		ui.resolvedToggle.checked = state.showResolved;

		ui.layer.textContent = '';
		if ( ui.draft ) {
			ui.layer.appendChild( ui.draft.el );
			placeDraft();
		}
		if ( ! state.on ) {
			renderPanel();
			return;
		}
		var origin = pageRect( ui.layer );
		visibleNotes().forEach( function ( note ) {
			var z = zoneOf( note );
			var n = numberOf( note );
			var zone = h(
				'div',
				{
					class: 'lu3g-pn-zone' + ( 'resolved' === note.status ? ' is-resolved' : '' ) + ( z.exact ? '' : ' is-approx' ) + ( note.id === state.openId ? ' is-open' : '' ),
					'data-note': String( note.id )
				},
				[
					h( 'button', {
						type: 'button',
						class: 'lu3g-pn-zone__pin',
						'aria-label': 'Nota ' + n + ( 'resolved' === note.status ? ' (risolta)' : '' ),
						onclick: function ( e ) {
							e.stopPropagation();
							toggleNote( note.id );
						}
					}, [ String( n ) ] )
				]
			);
			zone.style.left = ( z.x - origin.x ) + 'px';
			zone.style.top = ( z.y - origin.y ) + 'px';
			zone.style.width = z.w + 'px';
			zone.style.height = z.h + 'px';
			zone.addEventListener( 'click', function () {
				toggleNote( note.id );
			} );
			ui.layer.appendChild( zone );
		} );
		renderPanel();
		placePopover();
	}

	var raf = 0;
	function reflow() {
		if ( raf || ! state.on ) {
			return;
		}
		raf = window.requestAnimationFrame( function () {
			raf = 0;
			// solo posizioni: il popover aperto resta com'è (si perderebbe il testo che si sta scrivendo)
			var origin = pageRect( ui.layer );
			Array.prototype.forEach.call( ui.layer.querySelectorAll( '.lu3g-pn-zone' ), function ( zone ) {
				var note = noteById( parseInt( zone.getAttribute( 'data-note' ), 10 ) );
				if ( ! note ) {
					return;
				}
				var z = zoneOf( note );
				zone.style.left = ( z.x - origin.x ) + 'px';
				zone.style.top = ( z.y - origin.y ) + 'px';
				zone.style.width = z.w + 'px';
				zone.style.height = z.h + 'px';
				zone.classList.toggle( 'is-approx', ! z.exact );
			} );
			if ( ui.draft ) {
				placeDraft();
			}
			placePopover();
		} );
	}

	/* ------------------------------------------------------------------ nuova zona */

	var draw = null;

	function startDrawing() {
		closePopover();
		removeDraft();
		stopDrawing();
		var box = h( 'div', { class: 'lu3g-pn-draw__box', hidden: true } );
		var overlay = h( 'div', { class: 'lu3g-pn-ui lu3g-pn-draw' }, [
			h( 'div', { class: 'lu3g-pn-draw__hint', text: 'Trascina per disegnare la zona · Esc per annullare' } ),
			box
		] );
		draw = { overlay: overlay, box: box, start: null };

		overlay.addEventListener( 'pointerdown', function ( e ) {
			if ( 0 !== e.button ) {
				return;
			}
			e.preventDefault();
			overlay.setPointerCapture( e.pointerId );
			draw.start = { x: e.clientX, y: e.clientY };
			draw.end = { x: e.clientX, y: e.clientY };
			box.hidden = false;
			sizeBox();
		} );
		overlay.addEventListener( 'pointermove', function ( e ) {
			if ( ! draw || ! draw.start ) {
				return;
			}
			draw.end = { x: e.clientX, y: e.clientY };
			sizeBox();
		} );
		overlay.addEventListener( 'pointerup', function ( e ) {
			if ( ! draw || ! draw.start ) {
				return;
			}
			draw.end = { x: e.clientX, y: e.clientY };
			finishDrawing();
		} );
		overlay.addEventListener( 'pointercancel', stopDrawing );

		document.body.appendChild( overlay );
		document.documentElement.classList.add( 'lu3g-pn-drawing' );
	}

	function boxRect() {
		var x = Math.min( draw.start.x, draw.end.x );
		var y = Math.min( draw.start.y, draw.end.y );
		return { x: x, y: y, w: Math.abs( draw.end.x - draw.start.x ), h: Math.abs( draw.end.y - draw.start.y ) };
	}

	function sizeBox() {
		var r = boxRect();
		draw.box.style.left = r.x + 'px';
		draw.box.style.top = r.y + 'px';
		draw.box.style.width = r.w + 'px';
		draw.box.style.height = r.h + 'px';
	}

	function stopDrawing() {
		if ( draw ) {
			draw.overlay.remove();
			draw = null;
		}
		document.documentElement.classList.remove( 'lu3g-pn-drawing' );
	}

	function finishDrawing() {
		var r = boxRect();
		if ( r.w < MIN_SIZE || r.h < MIN_SIZE ) {
			// un clic: zona piccola attorno al punto
			r = { x: draw.start.x - 60, y: draw.start.y - 40, w: 120, h: 80 };
		}
		var zone = { x: r.x + window.scrollX, y: r.y + window.scrollY, w: r.w, h: r.h };
		stopDrawing(); // prima togliere il livello, poi cercare l'elemento sotto la zona
		ui.draft = {
			zone: zone,
			anchor: anchorFor( zone ),
			doc: docFor( zone ),
			el: h( 'div', { class: 'lu3g-pn-ui lu3g-pn-zone is-draft' } )
		};
		ui.layer.appendChild( ui.draft.el );
		placeDraft();
		openDraftForm();
	}

	function placeDraft() {
		if ( ! ui.draft ) {
			return;
		}
		var z = zoneOf( { anchor: ui.draft.anchor, doc: ui.draft.doc } );
		var origin = pageRect( ui.layer );
		ui.draft.el.style.left = ( z.x - origin.x ) + 'px';
		ui.draft.el.style.top = ( z.y - origin.y ) + 'px';
		ui.draft.el.style.width = z.w + 'px';
		ui.draft.el.style.height = z.h + 'px';
	}

	function removeDraft() {
		if ( ui.draft ) {
			ui.draft.el.remove();
			ui.draft = null;
		}
	}

	function openDraftForm() {
		var text = h( 'textarea', { class: 'lu3g-pn-input', rows: '4', placeholder: 'Scrivi la nota…', 'aria-label': 'Testo della nota', maxlength: '5000' } );
		var save = h( 'button', { type: 'submit', class: 'lu3g-pn-btn lu3g-pn-btn--primary' }, [ 'Salva nota' ] );
		var form = h( 'form', {
			class: 'lu3g-pn-form',
			onsubmit: function ( e ) {
				e.preventDefault();
				if ( ! text.value.trim() ) {
					text.focus();
					return;
				}
				save.disabled = true;
				api( 'POST', 'notes', {
					url: pageUrl(),
					text: text.value,
					anchor: ui.draft.anchor,
					doc: ui.draft.doc
				} ).then( function ( note ) {
					removeDraft();
					state.notes.push( note );
					state.openId = note.id;
					closePopover();
					render();
					showNote( note.id );
					toast( 'Nota salvata.' );
				} ).catch( function ( err ) {
					save.disabled = false;
					toast( err.message, true );
				} );
			}
		}, [
			text,
			h( 'div', { class: 'lu3g-pn-actions' }, [
				save,
				h( 'button', { type: 'button', class: 'lu3g-pn-btn', onclick: function () { closePopover(); removeDraft(); } }, [ 'Annulla' ] )
			] )
		] );
		submitOnCtrlEnter( text, form );
		openPopover( null, h( 'div', {}, [ h( 'div', { class: 'lu3g-pn-pop__title', text: 'Nuova nota' } ), form ] ) );
		text.focus();
	}

	function submitOnCtrlEnter( textarea, form ) {
		textarea.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ( e.ctrlKey || e.metaKey ) ) {
				e.preventDefault();
				form.requestSubmit ? form.requestSubmit() : form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
			}
		} );
	}

	/* ------------------------------------------------------------------ popover */

	function openPopover( noteId, content ) {
		closePopover( true );
		ui.pop = h( 'div', { class: 'lu3g-pn-ui lu3g-pn-pop', role: 'dialog', 'aria-label': noteId ? 'Nota ' + numberOf( noteById( noteId ) ) : 'Nuova nota' }, [
			h( 'button', {
				type: 'button',
				class: 'lu3g-pn-pop__close',
				'aria-label': 'Chiudi',
				onclick: function () {
					closePopover();
					removeDraft();
				}
			}, [ '×' ] ),
			content
		] );
		ui.popFor = noteId;
		ui.layer.appendChild( ui.pop );
		placePopover();
	}

	function closePopover( keepState ) {
		if ( ui.pop ) {
			ui.pop.remove();
			ui.pop = null;
		}
		ui.popFor = null;
		if ( ! keepState && state.openId ) {
			state.openId = null;
			Array.prototype.forEach.call( ui.layer.querySelectorAll( '.lu3g-pn-zone.is-open' ), function ( z ) {
				z.classList.remove( 'is-open' );
			} );
		}
	}

	/** Popover sotto la zona (o sopra, se sotto non c'è spazio), dentro la larghezza della finestra. */
	function placePopover() {
		if ( ! ui.pop ) {
			return;
		}
		if ( ui.pop.parentNode !== ui.layer ) {
			ui.layer.appendChild( ui.pop );
		}
		var z;
		if ( null === ui.popFor ) {
			if ( ! ui.draft ) {
				return;
			}
			z = zoneOf( { anchor: ui.draft.anchor, doc: ui.draft.doc } );
		} else {
			var note = noteById( ui.popFor );
			if ( ! note ) {
				closePopover();
				return;
			}
			z = zoneOf( note );
		}
		var origin = pageRect( ui.layer );
		var vw = viewportWidth();
		var width = Math.min( 340, vw - 24 );
		ui.pop.style.width = width + 'px';
		var left = Math.max( window.scrollX + 12, Math.min( z.x, window.scrollX + vw - width - 12 ) );
		var top = z.y + z.h + 10;
		var height = ui.pop.offsetHeight;
		var bottomEdge = window.scrollY + window.innerHeight;
		if ( top + height > bottomEdge && z.y - height - 10 > window.scrollY ) {
			top = z.y - height - 10;
		}
		ui.pop.style.left = ( left - origin.x ) + 'px';
		ui.pop.style.top = ( top - origin.y ) + 'px';
	}

	function toggleNote( id ) {
		if ( state.openId === id && ui.pop ) {
			closePopover();
			return;
		}
		openNote( id );
	}

	function openNote( id ) {
		var note = noteById( id );
		if ( ! note ) {
			return;
		}
		removeDraft();
		state.openId = id;
		Array.prototype.forEach.call( ui.layer.querySelectorAll( '.lu3g-pn-zone' ), function ( z ) {
			z.classList.toggle( 'is-open', String( id ) === z.getAttribute( 'data-note' ) );
		} );
		openPopover( id, noteView( note ) );
	}

	/** Apre la nota e la porta in vista. */
	function showNote( id ) {
		var note = noteById( id );
		if ( ! note ) {
			return;
		}
		if ( 'resolved' === note.status && ! state.showResolved ) {
			state.openId = id; // le risolte si vedono se aperte
		}
		if ( ! state.on ) {
			setOn( true );
		} else {
			render();
		}
		var z = zoneOf( note );
		window.scrollTo( { top: Math.max( 0, z.y - 120 ), behavior: 'smooth' } );
		openNote( id );
	}

	function replace( note ) {
		for ( var i = 0; i < state.notes.length; i++ ) {
			if ( state.notes[ i ].id === note.id ) {
				state.notes[ i ] = note;
			}
		}
	}

	function authorLine( author, date ) {
		return h( 'div', { class: 'lu3g-pn-meta' }, [
			author.avatar ? h( 'img', { class: 'lu3g-pn-avatar', src: author.avatar, alt: '', width: '24', height: '24', loading: 'lazy' } ) : null,
			h( 'strong', { text: author.name } ),
			h( 'span', { class: 'lu3g-pn-date', text: formatDate( date ) } )
		] );
	}

	function noteView( note ) {
		var n = numberOf( note );
		var resolved = 'resolved' === note.status;
		var body = h( 'div', { class: 'lu3g-pn-text', text: note.text } );
		var z = zoneOf( note );

		function run( promise, okMessage ) {
			return promise.then( function ( updated ) {
				if ( updated && updated.id ) {
					replace( updated );
				}
				render();
				if ( updated && updated.id ) {
					openNote( updated.id );
				}
				if ( okMessage ) {
					toast( okMessage );
				}
			} ).catch( function ( err ) {
				toast( err.message, true );
			} );
		}

		var tools = h( 'div', { class: 'lu3g-pn-actions' }, [
			h( 'button', {
				type: 'button',
				class: 'lu3g-pn-btn ' + ( resolved ? '' : 'lu3g-pn-btn--ok' ),
				onclick: function () {
					run( api( 'POST', 'notes/' + note.id, { status: resolved ? 'open' : 'resolved' } ), resolved ? 'Nota riaperta.' : 'Nota risolta.' );
				}
			}, [ resolved ? 'Riapri' : '✓ Risolta' ] ),
			note.can_edit ? h( 'button', {
				type: 'button',
				class: 'lu3g-pn-btn',
				onclick: function () {
					editText( note, body, run );
				}
			}, [ 'Modifica' ] ) : null,
			note.can_edit ? h( 'button', {
				type: 'button',
				class: 'lu3g-pn-btn lu3g-pn-btn--danger',
				onclick: function () {
					if ( ! window.confirm( 'Eliminare la nota ' + n + ' e le sue risposte?' ) ) {
						return;
					}
					api( 'DELETE', 'notes/' + note.id ).then( function () {
						state.notes = state.notes.filter( function ( x ) {
							return x.id !== note.id;
						} );
						closePopover();
						render();
						toast( 'Nota eliminata.' );
					} ).catch( function ( err ) {
						toast( err.message, true );
					} );
				}
			}, [ 'Elimina' ] ) : null
		] );

		var replies = h( 'div', { class: 'lu3g-pn-replies' }, note.replies.map( function ( r ) {
			return h( 'div', { class: 'lu3g-pn-reply' }, [
				authorLine( r.author, r.date ),
				h( 'div', { class: 'lu3g-pn-text', text: r.text } ),
				r.can_edit ? h( 'button', {
					type: 'button',
					class: 'lu3g-pn-link',
					onclick: function () {
						if ( window.confirm( 'Eliminare questa risposta?' ) ) {
							run( api( 'DELETE', 'replies/' + r.id ), 'Risposta eliminata.' );
						}
					}
				}, [ 'Elimina' ] ) : null
			] );
		} ) );

		var replyText = h( 'textarea', { class: 'lu3g-pn-input', rows: '2', placeholder: 'Rispondi…', 'aria-label': 'Risposta', maxlength: '5000' } );
		var replyButton = h( 'button', { type: 'submit', class: 'lu3g-pn-btn lu3g-pn-btn--primary' }, [ 'Rispondi' ] );
		var replyForm = h( 'form', {
			class: 'lu3g-pn-form',
			onsubmit: function ( e ) {
				e.preventDefault();
				if ( ! replyText.value.trim() ) {
					replyText.focus();
					return;
				}
				replyButton.disabled = true;
				run( api( 'POST', 'notes/' + note.id + '/replies', { text: replyText.value } ) ).then( function () {
					replyButton.disabled = false;
				} );
			}
		}, [ replyText, h( 'div', { class: 'lu3g-pn-actions' }, [ replyButton ] ) ] );
		submitOnCtrlEnter( replyText, replyForm );

		return h( 'div', {}, [
			h( 'div', { class: 'lu3g-pn-pop__title' }, [
				h( 'span', { class: 'lu3g-pn-num', text: String( n ) } ),
				' Nota',
				resolved ? h( 'span', { class: 'lu3g-pn-badge', text: 'Risolta' } ) : null
			] ),
			authorLine( note.author, note.date ),
			body,
			z.exact ? null : h( 'p', { class: 'lu3g-pn-hint', text: 'Posizione approssimativa: l’elemento di questa zona è cambiato o non si vede a questa larghezza.' } ),
			tools,
			replies,
			replyForm
		] );
	}

	function editText( note, body, run ) {
		var area = h( 'textarea', { class: 'lu3g-pn-input', rows: '4', 'aria-label': 'Testo della nota', maxlength: '5000' } );
		area.value = note.text;
		var form = h( 'form', {
			class: 'lu3g-pn-form',
			onsubmit: function ( e ) {
				e.preventDefault();
				if ( area.value.trim() ) {
					run( api( 'POST', 'notes/' + note.id, { text: area.value } ), 'Nota modificata.' );
				}
			}
		}, [
			area,
			h( 'div', { class: 'lu3g-pn-actions' }, [
				h( 'button', { type: 'submit', class: 'lu3g-pn-btn lu3g-pn-btn--primary' }, [ 'Salva' ] ),
				h( 'button', { type: 'button', class: 'lu3g-pn-btn', onclick: function () { openNote( note.id ); } }, [ 'Annulla' ] )
			] )
		] );
		submitOnCtrlEnter( area, form );
		body.replaceWith( form );
		area.focus();
		placePopover();
	}

	/* ------------------------------------------------------------------ elenco */

	function togglePanel() {
		ui.panel.hidden = ! ui.panel.hidden;
		ui.panelButton.setAttribute( 'aria-expanded', ui.panel.hidden ? 'false' : 'true' );
		renderPanel();
	}

	function renderPanel() {
		if ( ui.panel.hidden ) {
			return;
		}
		ui.panel.textContent = '';
		ui.panel.appendChild( h( 'div', { class: 'lu3g-pn-panel__head' }, [
			h( 'strong', { text: 'Note della pagina' } ),
			h( 'button', { type: 'button', class: 'lu3g-pn-pop__close', 'aria-label': 'Chiudi l’elenco', onclick: togglePanel }, [ '×' ] )
		] ) );
		if ( state.error ) {
			ui.panel.appendChild( h( 'p', { class: 'lu3g-pn-hint', text: state.error } ) );
		}
		var list = state.notes.filter( function ( n ) {
			return state.showResolved || 'resolved' !== n.status;
		} );
		if ( ! list.length ) {
			ui.panel.appendChild( h( 'p', { class: 'lu3g-pn-hint', text: state.notes.length ? 'Nessuna nota aperta. Spunta "Risolte" per vedere le altre.' : 'Ancora nessuna nota su questa pagina.' } ) );
		}
		list.forEach( function ( note ) {
			ui.panel.appendChild( h( 'button', {
				type: 'button',
				class: 'lu3g-pn-item' + ( 'resolved' === note.status ? ' is-resolved' : '' ),
				onclick: function () {
					showNote( note.id );
				}
			}, [
				h( 'span', { class: 'lu3g-pn-num', text: String( numberOf( note ) ) } ),
				h( 'span', { class: 'lu3g-pn-item__body' }, [
					h( 'span', { class: 'lu3g-pn-item__text', text: note.text } ),
					h( 'span', { class: 'lu3g-pn-item__meta', text: note.author.name + ' · ' + formatDate( note.date ) + ( note.replies.length ? ' · ' + note.replies.length + ( 1 === note.replies.length ? ' risposta' : ' risposte' ) : '' ) + ( 'resolved' === note.status ? ' · risolta' : '' ) } )
				] )
			] ) );
		} );
		if ( C.admin ) {
			ui.panel.appendChild( h( 'a', { class: 'lu3g-pn-link', href: C.admin, text: 'Tutte le note del sito in bacheca →' } ) );
		}
	}

	/* ------------------------------------------------------------------ dati */

	function load() {
		if ( state.loading ) {
			return Promise.resolve();
		}
		state.loading = true;
		return api( 'GET', 'notes', null, 'url=' + encodeURIComponent( pageUrl() ) ).then( function ( data ) {
			state.notes = ( data && data.notes ) || [];
			state.error = '';
		} ).catch( function ( err ) {
			state.error = err.message;
			toast( 'Note LU3G: ' + err.message, true );
		} ).then( function () {
			state.loading = false;
			// il popover aperto si ridisegna solo se non si sta scrivendo
			var typing = ui.pop && ui.pop.contains( document.activeElement ) && 'TEXTAREA' === document.activeElement.tagName;
			render();
			if ( state.openId && ! typing && noteById( state.openId ) && ui.popFor === state.openId ) {
				openNote( state.openId );
			}
		} );
	}

	/* ------------------------------------------------------------------ avvio */

	function boot() {
		build();
		state.showResolved = '1' === store( STORE_RESOLVED );

		var params = new URLSearchParams( window.location.search );
		var target = parseInt( params.get( 'lu3g-note' ), 10 ) || 0;

		setOn( '1' === store( STORE_ON ) || !! target );
		load().then( function () {
			if ( target ) {
				// dopo il caricamento di immagini e font le posizioni sono quelle definitive
				setTimeout( function () {
					showNote( target );
				}, 300 );
			}
		} );

		window.addEventListener( 'resize', reflow );
		window.addEventListener( 'load', reflow );
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( reflow ).observe( document.body );
		}
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' !== e.key ) {
				return;
			}
			if ( draw ) {
				stopDrawing();
			} else if ( ui.pop ) {
				closePopover();
				removeDraft();
			} else if ( ! ui.panel.hidden ) {
				togglePanel();
			}
		} );
		// tornando sulla scheda si rileggono le note (magari un collega ne ha aggiunte)
		document.addEventListener( 'visibilitychange', function () {
			if ( 'visible' === document.visibilityState && state.on ) {
				load();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
