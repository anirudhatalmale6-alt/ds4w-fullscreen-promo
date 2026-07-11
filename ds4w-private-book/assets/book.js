/**
 * The reading room.
 *
 * Pages are fetched one at a time from an address that checks the reader's invitation, and only
 * when they are nearly needed. Two consequences worth knowing:
 *
 *   - Nothing in the initial HTML contains the book. View-source gives you an empty frame.
 *   - Opening the book does not download 142 images. A reader who looks at three pages has
 *     fetched about five.
 *
 * There is deliberately no "download" anywhere, and the pages are images, so there is no
 * selectable manuscript to copy out either. That's the trade we made to get a private book:
 * search still works (the server does it), but the text never leaves the server.
 */
( function () {
	'use strict';

	var CFG = window.DS4W_BOOK || {};
	var TOTAL = parseInt( CFG.pages, 10 ) || 0;

	if ( ! TOTAL || typeof window.St === 'undefined' && typeof window.PageFlip === 'undefined' ) {
		// PageFlip ships as window.St.PageFlip in the browser build.
	}

	var PageFlipCtor = ( window.St && window.St.PageFlip ) || window.PageFlip;

	var root = document.getElementById( 'ds4w-book' );
	var stage = document.getElementById( 'ds4w-book-flip' );
	var stageHost = stage && stage.parentNode;

	if ( ! root || ! stage || ! stageHost || ! PageFlipCtor || ! TOTAL ) {
		return;
	}

	var flip = null;
	var zoomed = false;
	var loaded = {};   // page -> true once its <img> has a src
	var pageEls = [];  // 1-based

	/* ------------------------------------------------------------------ */
	/* Page loading                                                        */
	/* ------------------------------------------------------------------ */

	function pageUrl( n, size ) {
		return CFG.pageUrl + n + ( size === 'thumb' ? '&s=thumb' : '' );
	}

	/**
	 * Give a page its image, once.
	 *
	 * @param {number} n 1-based page number.
	 */
	function load( n ) {
		if ( n < 1 || n > TOTAL || loaded[ n ] ) {
			return;
		}

		var el = pageEls[ n ];
		if ( ! el ) {
			return;
		}

		var img = el.querySelector( 'img' );
		if ( ! img ) {
			return;
		}

		loaded[ n ] = true;
		img.src = pageUrl( n );
		img.addEventListener( 'load', function () {
			el.classList.add( 'is-loaded' );
		} );
	}

	/** Load what's on screen, plus a little way ahead and behind so turning feels instant. */
	function loadAround( n ) {
		for ( var i = n - 2; i <= n + 3; i++ ) {
			load( i );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Build                                                               */
	/* ------------------------------------------------------------------ */

	function buildPages() {
		var frag = document.createDocumentFragment();

		for ( var n = 1; n <= TOTAL; n++ ) {
			var page = document.createElement( 'div' );
			page.className = 'ds4w-page';
			page.setAttribute( 'data-density', n === 1 || n === TOTAL ? 'hard' : 'soft' );

			var img = document.createElement( 'img' );
			img.alt = 'Page ' + n;
			img.draggable = false;
			// No src yet — see the note at the top of this file.
			page.appendChild( img );

			var spinner = document.createElement( 'span' );
			spinner.className = 'ds4w-page__spin';
			page.appendChild( spinner );

			pageEls[ n ] = page;
			frag.appendChild( page );
		}

		stage.appendChild( frag );
	}

	function sizeFor() {
		var ratio = CFG.ratio || 0.707;              // width / height of one page
		var wrap = root.getBoundingClientRect();
		var barH = 58;
		var availH = Math.max( 320, ( root.classList.contains( 'is-fullscreen' ) ? window.innerHeight : wrap.height || window.innerHeight * 0.8 ) - barH );
		var availW = wrap.width || window.innerWidth;

		var single = window.innerWidth < 768;
		var spread = single ? 1 : 2;

		// Fit by height first, then make sure the spread still fits the width.
		var h = availH;
		var w = h * ratio;

		if ( w * spread > availW - 24 ) {
			w = ( availW - 24 ) / spread;
			h = w / ratio;
		}

		return { w: Math.floor( w ), h: Math.floor( h ), single: single };
	}

	function build() {
		var s = sizeFor();

		root.classList.toggle( 'is-spread', ! s.single );

		flip = new PageFlipCtor( stage, {
			width: s.w,
			height: s.h,
			size: 'fixed',
			showCover: true,
			usePortrait: s.single,
			maxShadowOpacity: 0.5,
			mobileScrollSupport: false,
			flippingTime: 700,
			swipeDistance: 30,
		} );

		flip.loadFromHTML( stage.querySelectorAll( '.ds4w-page' ) );

		flip.on( 'flip', function ( e ) {
			var n = e.data + 1;
			setCounter( n );
			loadAround( n );
			centreSinglePage( e.data );
		} );

		loadAround( 1 );
		setCounter( 1 );
		centreSinglePage( 0 );

		var loading = document.getElementById( 'ds4w-loading' );
		if ( loading ) {
			loading.remove();
		}
	}

	/**
	 * Rebuild at a new size, keeping the reader's place.
	 *
	 * StPageFlip is built around a fixed page size, so a real resize — and above all entering
	 * fullscreen, where the book has to grow to fill the screen — means building it again.
	 *
	 * We throw the whole stage element away and make a fresh one rather than reusing it:
	 * flip.destroy() takes the container down with it (verified — after a destroy, the element
	 * we were handed no longer exists in the document), so anything that held onto that node
	 * would be quietly building into an orphan. Recreating it means we never have to care what
	 * destroy() does or doesn't leave behind.
	 */
	var resizeTimer = null;
	function rebuild() {
		if ( ! flip ) {
			return;
		}

		var at = flip.getCurrentPageIndex();

		try {
			flip.destroy();
		} catch ( e ) { /* already gone — nothing to do */ }

		if ( stage && stage.parentNode ) {
			stage.parentNode.removeChild( stage );
		}

		stage = document.createElement( 'div' );
		stage.className = 'ds4w-book__flip';
		stage.id = 'ds4w-book-flip';
		stageHost.insertBefore( stage, stageHost.firstChild );

		pageEls = [];
		loaded = {};

		buildPages();
		build();

		if ( at > 0 ) {
			flip.turnToPage( at );
			loadAround( at + 1 );
		}
	}

	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( rebuild, 250 );
	} );

	/**
	 * Centre the cover and the back cover.
	 *
	 * In a two-page spread StPageFlip parks the front cover on the right half and the back cover
	 * on the left, which leaves the book visibly hanging off to one side with a slab of empty
	 * space beside it. A real book shows its cover in the middle. The spread is two pages wide,
	 * so nudging it by half a page puts the single visible page back on the centre line.
	 *
	 * @param {number} index 0-based page index StPageFlip has landed on.
	 */
	function centreSinglePage( index ) {
		if ( ! root.classList.contains( 'is-spread' ) ) {
			return; // Portrait/mobile shows one page at a time anyway — nothing to centre.
		}

		root.classList.toggle( 'is-cover', index === 0 );
		root.classList.toggle( 'is-back', index === TOTAL - 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Controls                                                            */
	/* ------------------------------------------------------------------ */

	var counter = document.getElementById( 'ds4w-pageno' );

	function setCounter( n ) {
		if ( counter && document.activeElement !== counter ) {
			counter.value = n;
		}
	}

	function goTo( n ) {
		n = Math.min( TOTAL, Math.max( 1, parseInt( n, 10 ) || 1 ) );
		loadAround( n );
		if ( flip ) {
			flip.turnToPage( n - 1 );
		}
		setCounter( n );
	}

	function on( id, fn ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.addEventListener( 'click', fn );
		}
	}

	on( 'ds4w-next', function () { flip.flipNext(); } );
	on( 'ds4w-prev', function () { flip.flipPrev(); } );
	on( 'ds4w-next2', function () { flip.flipNext(); } );
	on( 'ds4w-prev2', function () { flip.flipPrev(); } );
	on( 'ds4w-first', function () { goTo( 1 ); } );
	on( 'ds4w-last', function () { goTo( TOTAL ); } );

	if ( counter ) {
		counter.addEventListener( 'change', function () { goTo( counter.value ); } );
		counter.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				goTo( counter.value );
				counter.blur();
			}
		} );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( document.activeElement === counter || isTyping( e.target ) ) {
			return;
		}
		if ( e.key === 'ArrowRight' ) { flip.flipNext(); }
		if ( e.key === 'ArrowLeft' ) { flip.flipPrev(); }
	} );

	function isTyping( el ) {
		return el && ( el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' );
	}

	/* Zoom ------------------------------------------------------------- */

	on( 'ds4w-zoom', function () {
		zoomed = ! zoomed;
		root.classList.toggle( 'is-zoomed', zoomed );
		rebuild();
	} );

	/* Fullscreen ------------------------------------------------------- */

	function fsElement() {
		return document.fullscreenElement || document.webkitFullscreenElement || null;
	}

	on( 'ds4w-full', function () {
		if ( fsElement() ) {
			( document.exitFullscreen || document.webkitExitFullscreen ).call( document );
			return;
		}

		var req = root.requestFullscreen || root.webkitRequestFullscreen;
		if ( req ) {
			req.call( root );
		} else {
			// iOS Safari: no Fullscreen API for elements. Pin it to the viewport instead.
			root.classList.toggle( 'is-pseudo-fullscreen' );
			rebuild();
		}
	} );

	[ 'fullscreenchange', 'webkitfullscreenchange' ].forEach( function ( evt ) {
		document.addEventListener( evt, function () {
			root.classList.toggle( 'is-fullscreen', !! fsElement() );
			// Let the browser finish laying out at the new size before we measure it.
			window.setTimeout( rebuild, 120 );
		} );
	} );

	/* Thumbnails ------------------------------------------------------- */

	var thumbsPanel = document.getElementById( 'ds4w-thumbs-panel' );
	var thumbsWrap = document.getElementById( 'ds4w-thumbs' );
	var thumbsBuilt = false;

	function buildThumbs() {
		if ( thumbsBuilt ) {
			return;
		}
		thumbsBuilt = true;

		var frag = document.createDocumentFragment();

		for ( var n = 1; n <= TOTAL; n++ ) {
			var b = document.createElement( 'button' );
			b.className = 'ds4w-thumb';
			b.setAttribute( 'data-page', n );

			var im = document.createElement( 'img' );
			im.loading = 'lazy';              // the browser only fetches these as they scroll in
			im.src = pageUrl( n, 'thumb' );
			im.alt = 'Page ' + n;

			var cap = document.createElement( 'span' );
			cap.textContent = n;

			b.appendChild( im );
			b.appendChild( cap );
			frag.appendChild( b );
		}

		thumbsWrap.appendChild( frag );

		thumbsWrap.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.ds4w-thumb' );
			if ( b ) {
				goTo( b.getAttribute( 'data-page' ) );
				panel( thumbsPanel, false );
			}
		} );
	}

	function panel( el, open ) {
		if ( ! el ) {
			return;
		}
		if ( open ) {
			el.hidden = false;
			// Force a reflow so the transition runs from the closed state.
			void el.offsetWidth;
			el.classList.add( 'is-open' );
		} else {
			el.classList.remove( 'is-open' );
			window.setTimeout( function () { el.hidden = true; }, 200 );
		}
	}

	on( 'ds4w-contents', function () {
		buildThumbs();
		panel( thumbsPanel, thumbsPanel.hidden );
	} );
	on( 'ds4w-thumbs-close', function () { panel( thumbsPanel, false ); } );

	/* Search ----------------------------------------------------------- */

	var searchPanel = document.getElementById( 'ds4w-search-panel' );
	var searchInput = document.getElementById( 'ds4w-search-input' );
	var results = document.getElementById( 'ds4w-results' );
	var searchTimer = null;

	on( 'ds4w-search-open', function () {
		panel( searchPanel, searchPanel.hidden );
		if ( ! searchPanel.hidden && searchInput ) {
			searchInput.focus();
		}
	} );
	on( 'ds4w-search-close', function () { panel( searchPanel, false ); } );

	if ( searchInput ) {
		searchInput.addEventListener( 'input', function () {
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( runSearch, 250 );
		} );
	}

	function runSearch() {
		var q = searchInput.value.trim();

		if ( q.length < 2 ) {
			results.innerHTML = '';
			return;
		}

		results.innerHTML = '<p class="ds4w-book__muted">Searching…</p>';

		// The server does the searching and sends back page numbers — the book's text stays there.
		fetch( CFG.searchUrl + encodeURIComponent( q ), { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				var hits = ( data && data.results ) || [];

				if ( ! hits.length ) {
					results.innerHTML = '<p class="ds4w-book__muted">Nothing found for “' + escapeHtml( q ) + '”.</p>';
					return;
				}

				results.innerHTML = '';

				hits.forEach( function ( h ) {
					var item = document.createElement( 'button' );
					item.className = 'ds4w-result';
					item.innerHTML =
						'<strong>Page ' + h.page + '</strong>' +
						'<span>' + highlight( escapeHtml( h.snippet ), q ) + '</span>';
					item.addEventListener( 'click', function () {
						goTo( h.page );
						panel( searchPanel, false );
					} );
					results.appendChild( item );
				} );
			} )
			.catch( function () {
				results.innerHTML = '<p class="ds4w-book__muted">Search is unavailable right now.</p>';
			} );
	}

	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s;
		return d.innerHTML;
	}

	function highlight( html, q ) {
		try {
			var re = new RegExp( '(' + q.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + ')', 'ig' );
			return html.replace( re, '<mark>$1</mark>' );
		} catch ( e ) {
			return html;
		}
	}

	/* ------------------------------------------------------------------ */

	buildPages();
	build();
} )();
