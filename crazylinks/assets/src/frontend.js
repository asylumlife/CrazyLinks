/* CrazyLinks front end: in-app browser hint, schedule expiry, embed facades, countdowns. No dependencies. */
( function () {
	'use strict';
	var doc = document;
	var ua = navigator.userAgent || '';

	// In-app browser hint (SPEC 6.4): a hint, not truth; shown only where the page enabled it.
	if ( /Instagram|FBAN|FBAV|FB_IAB|BytedanceWebview|musical_ly|TikTok/i.test( ua ) ) {
		doc.querySelectorAll( '[data-cl-inapp]' ).forEach( function ( bar ) {
			bar.hidden = false;
			var btn = bar.querySelector( '[data-cl-dismiss]' );
			if ( btn ) {
				btn.addEventListener( 'click', function () {
					bar.hidden = true;
				} );
			}
		} );
	}

	// Scheduled blocks and sections: data-cl-end is when the window that was open at render time closes
	// (one-off end or the end of today's weekly window). Hide once it passes while a cache serves the page.
	// Only ever hides: blocks the server omitted are not in the HTML, so nothing can appear early.
	function expire() {
		var now = Date.now();
		doc.querySelectorAll( '[data-cl-end]' ).forEach( function ( el ) {
			var end = Date.parse( el.getAttribute( 'data-cl-end' ) );
			if ( ! isNaN( end ) && end <= now ) {
				el.hidden = true;
			}
		} );
	}
	expire();
	setInterval( expire, 30000 );

	// Countdowns: the server renders the first values; tick them every second and swap to the ended state.
	var timers = doc.querySelectorAll( '[data-cl-countdown]' );
	function pad( n ) {
		return n < 10 ? '0' + n : String( n );
	}
	function tick() {
		var now = Date.now();
		timers.forEach( function ( box ) {
			var left = Math.max( 0, Math.floor( ( Date.parse( box.getAttribute( 'data-cl-countdown' ) ) - now ) / 1000 ) );
			var live = box.querySelector( '.cl-countdown__live' );
			var done = box.querySelector( '.cl-countdown__done' );
			if ( ! live || ! done ) {
				return;
			}
			live.hidden = left <= 0;
			done.hidden = left > 0;
			var v = { d: Math.floor( left / 86400 ), h: pad( Math.floor( left % 86400 / 3600 ) ), m: pad( Math.floor( left % 3600 / 60 ) ), s: pad( left % 60 ) };
			box.querySelectorAll( '[data-cl-unit]' ).forEach( function ( el ) {
				el.textContent = v[ el.getAttribute( 'data-cl-unit' ) ];
			} );
		} );
	}
	if ( timers.length ) {
		tick();
		setInterval( tick, 1000 );
	}

	// Background videos must not autoplay for people who asked for less motion.
	if ( window.matchMedia && matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		doc.querySelectorAll( 'video[autoplay]' ).forEach( function ( v ) {
			v.pause();
			v.controls = true;
		} );
	}

	// Embed facades: load the provider iframe only after a tap.
	doc.querySelectorAll( '[data-cl-embed]' ).forEach( function ( box ) {
		var link = box.querySelector( '.cl-embed__facade' );
		var tpl = box.querySelector( 'template' );
		if ( ! link || ! tpl ) {
			return;
		}
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			box.replaceChildren( tpl.content.cloneNode( true ) );
			var frame = box.querySelector( 'iframe' );
			if ( frame ) {
				frame.focus();
			}
		} );
	} );

	// Email capture: send with fetch and show the message inline. On any failure fall back to a normal POST.
	doc.querySelectorAll( 'form[data-cl-capture]' ).forEach( function ( form ) {
		if ( ! window.fetch ) {
			return;
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var body = {};
			new FormData( form ).forEach( function ( v, k ) {
				body[ k ] = v;
			} );
			var button = form.querySelector( 'button' );
			button.disabled = true;
			fetch( form.action, { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify( body ) } )
				.then( function ( r ) {
					return r.json().then( function ( d ) {
						return { ok: r.ok, d: d || {} };
					} );
				} )
				.then( function ( res ) {
					var key = res.ok ? res.d.status || 'error' : ( res.d.data && res.d.data.key ) || 'error';
					form.querySelectorAll( '.cl-capture__msg' ).forEach( function ( m ) {
						m.classList.remove( 'is-on' );
					} );
					var msg = doc.getElementById( form.getAttribute( 'data-cl-uid' ) + '-m-' + key );
					if ( msg ) {
						msg.classList.add( 'is-on' );
						msg.focus();
					}
					if ( res.ok ) {
						form.querySelectorAll( '.cl-capture__row,.cl-capture__consent' ).forEach( function ( el ) {
							el.hidden = true;
						} );
					}
					button.disabled = false;
				} )
				.catch( function () {
					form.submit();
				} );
		} );
	} );
} )();
