/**
 * Fullscreen Promo Popup — front-end behaviour.
 *
 * Two jobs:
 *   1. Lock the auto-opened Popup Maker popup so the page is blocked until the visitor acts.
 *   2. Put the browser into fullscreen on the visitor's first genuine gesture.
 *
 * Why a gesture is required: the Fullscreen API is gated behind "transient user
 * activation" in every modern browser. requestFullscreen() called on page load,
 * on a timer, or from an XHR callback is rejected outright. It must run inside the
 * call stack of a real click / tap / keypress. So the popup opens by itself, and the
 * fullscreen request rides on the visitor's first interaction with it.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.DS4W_FSP || {};
	var popupId = parseInt( cfg.popupId, 10 );

	if ( ! popupId ) {
		return;
	}

	var LOCK = !! cfg.lock;
	var CTA_URL = cfg.ctaUrl || '';
	var TARGET_MODE = cfg.target || 'element';
	var SELECTOR = cfg.selector || '';

	var $popup = null;
	var unlocked = false;   // Flips true once the visitor has acted. Only then may the popup close.
	var fsAttempted = false; // We only ever fire one fullscreen request.

	var root = document.documentElement;

	/* ------------------------------------------------------------------ */
	/* What are we making fullscreen?                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Resolve the element to expand.
	 *
	 * Fullscreening the whole document would give you a fullscreen WordPress page —
	 * header, footer and all — with the flipbook still boxed inside it at its embed
	 * height. Fullscreening the flipbook IFRAME instead makes the reader itself fill
	 * the screen, controls and all. That's the point of the exercise.
	 *
	 * We're allowed to do this even though the iframe is cross-origin: the iframe is
	 * just an element in OUR document, so the parent may fullscreen it. (Scripting
	 * *inside* it would be blocked — we never try.) The Paperturn embed already
	 * carries allowfullscreen, which is what lets its own controls work in there too.
	 *
	 * Resolved at click time, not on load: Paperturn injects the iframe via its own
	 * script, so it may not exist yet when this file first runs.
	 *
	 * @return {Element} The flipbook if we can find it, otherwise the document.
	 */
	function fullscreenTarget() {
		if ( TARGET_MODE !== 'element' || ! SELECTOR ) {
			return root;
		}

		var el = null;
		try {
			el = document.querySelector( SELECTOR );
		} catch ( e ) {
			el = null; // Bad selector typed into the settings screen — don't die, just fall back.
		}

		// Fall back to any iframe in the page content before giving up on the whole page.
		if ( ! el ) {
			el = document.querySelector( '.entry-content iframe, .elementor iframe, main iframe' );
		}

		return el || root;
	}

	/* ------------------------------------------------------------------ */
	/* Fullscreen                                                          */
	/* ------------------------------------------------------------------ */

	function fullscreenSupported( el ) {
		return !! (
			el.requestFullscreen ||
			el.webkitRequestFullscreen ||
			el.webkitRequestFullScreen ||
			el.mozRequestFullScreen ||
			el.msRequestFullscreen
		);
	}

	function isFullscreen() {
		return !! (
			document.fullscreenElement ||
			document.webkitFullscreenElement ||
			document.mozFullScreenElement ||
			document.msFullscreenElement
		);
	}

	/**
	 * iOS Safari refuses fullscreen on anything but a <video>. When there's no real
	 * Fullscreen API we fake it: pin the document to the viewport and kill scrolling,
	 * so the visitor gets the same immersive, chrome-free result.
	 *
	 * @param {boolean} isOurFullscreen True when this pinned layout IS the immersive result
	 *                                  we're giving the reader (iOS, which has no Fullscreen
	 *                                  API for page elements). False when it's a consolation
	 *                                  prize after a real request was refused — in which case
	 *                                  it must NOT count as the reader having complied.
	 */
	function pseudoFullscreen( isOurFullscreen ) {
		root.classList.add( 'ds4w-pseudo-fullscreen' );
		document.body.classList.add( 'ds4w-pseudo-fullscreen' );

		// No fullscreenchange event fires for this path, so finish the job by hand.
		if ( isOurFullscreen ) {
			complete();
		}
	}

	/**
	 * The promo has done its job — the reader is looking at the book, full bleed.
	 *
	 * This is driven by the fullscreen state itself, not by which handler happened to run.
	 * The CTA click and the catch-all first-gesture listener are two routes to the same
	 * place, and on the live site the catch-all (capture phase) sometimes gets there first,
	 * leaving the CTA's handler unfired — which used to strand the reader with the lock
	 * still on and the promo still up behind the fullscreen book. Anchoring the release to
	 * "are we actually fullscreen?" removes that race entirely.
	 */
	function complete() {
		if ( unlocked ) {
			return;
		}

		releaseLock();
		dismissForGood();

		if ( window.PUM && typeof window.PUM.close === 'function' ) {
			window.PUM.close( popupId );
		}
	}

	[ 'fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange' ].forEach(
		function ( evt ) {
			document.addEventListener( evt, function () {
				if ( isFullscreen() ) {
					complete();
				}
			} );
		}
	);

	/**
	 * MUST be called synchronously from inside a user-gesture handler.
	 * Any await/setTimeout before this point discards the activation and the request fails.
	 */
	function goFullscreen() {
		if ( fsAttempted || isFullscreen() ) {
			return;
		}
		fsAttempted = true;

		var el = fullscreenTarget();

		if ( ! fullscreenSupported( el ) ) {
			pseudoFullscreen( true ); // iOS and other holdouts — this is their fullscreen.
			return;
		}

		document.body.classList.add( 'ds4w-fsp-fullscreen' );

		var req =
			el.requestFullscreen ||
			el.webkitRequestFullscreen ||
			el.webkitRequestFullScreen ||
			el.mozRequestFullScreen ||
			el.msRequestFullscreen;

		try {
			var result = req.call( el, { navigationUI: 'hide' } );

			// Standards-compliant browsers return a promise that rejects if the
			// request is refused (e.g. the gesture expired). If expanding the
			// flipbook alone fails, try the whole page before giving up entirely.
			if ( result && typeof result.catch === 'function' ) {
				result.catch( function () {
					if ( el !== root && fullscreenSupported( root ) ) {
						try {
							root.requestFullscreen();
							return;
						} catch ( e2 ) { /* fall through */ }
					}
					pseudoFullscreen( false );
				} );
			}
		} catch ( e ) {
			pseudoFullscreen( false );
		}
	}

	/* ------------------------------------------------------------------ */
	/* The lock                                                            */
	/* ------------------------------------------------------------------ */

	function applyLock() {
		if ( ! LOCK || ! $popup ) {
			return;
		}

		// Strip Popup Maker's close (×) button — there is no way out but the CTA.
		$popup.find( '.pum-content + .pum-close, .pum-close' ).remove();

		document.body.classList.add( 'ds4w-fsp-locked' );
	}

	function releaseLock() {
		unlocked = true;
		document.body.classList.remove( 'ds4w-fsp-locked' );
	}

	/**
	 * The visitor acted. This runs inside the gesture, so fullscreen is legal here.
	 */
	function onAction() {
		goFullscreen();  // First — must not lose the user activation.

		/*
		 * complete() normally runs off the fullscreenchange event. But if the browser
		 * refuses fullscreen without saying so, the reader would be left staring at a
		 * locked promo they just clicked. They did their part; never trap them.
		 */
		window.setTimeout( complete, 1200 );

		if ( CTA_URL ) {
			// Note: navigating away drops fullscreen — a new document has no user
			// activation, so the browser exits. Deliberate, and documented for the client.
			window.setTimeout( function () {
				window.location.href = CTA_URL;
			}, 1300 );
		}
	}

	/**
	 * Hide the promo permanently, independently of Popup Maker's close animation.
	 *
	 * PUM.close() fades out over a few hundred milliseconds and only then sets display:none.
	 * We don't leave "is the promo gone?" in the hands of a third-party animation.
	 */
	function dismissForGood() {
		if ( $popup ) {
			$popup.addClass( 'ds4w-fsp-dismissed' );
		}
		document.body.classList.add( 'ds4w-fsp-done' );
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	$( document )
		// Popup Maker fires this on the popup element once it has opened.
		.on( 'pumAfterOpen', '#pum-' + popupId, function () {
			$popup = $( this );

			// Already complied once — don't ask again, whatever re-opened it.
			if ( unlocked ) {
				dismissForGood();
				if ( window.PUM && typeof window.PUM.close === 'function' ) {
					window.PUM.close( popupId );
				}
				return;
			}

			applyLock();

			// The call-to-action inside the popup.
			$popup.off( 'click.ds4w' ).on( 'click.ds4w', '.ds4w-fs-go', function ( e ) {
				e.preventDefault();
				onAction();
			} );
		} )

		// Safety net: if the popup somehow closes while still locked, put it straight back.
		.on( 'pumAfterClose', '#pum-' + popupId, function () {
			if ( LOCK && ! unlocked && window.PUM && typeof window.PUM.open === 'function' ) {
				window.PUM.open( popupId );
			}
		} );

	/**
	 * Second safety net: catch the visitor's first click/tap ANYWHERE on the page and
	 * use it for fullscreen. Covers the case where they click outside the CTA (or the
	 * client edits the popup and drops the ds4w-fs-go class).
	 *
	 * Pointer gestures only — deliberately NOT keydown. Escape carries no user activation,
	 * so a fullscreen request made from it is refused; treating that keypress as a gesture
	 * meant pressing Escape burned the one-shot attempt and dropped through to the fallback,
	 * which handed the reader exactly the skip the lock exists to prevent.
	 *
	 * Capture phase + { once: true } so it runs before anything can stop propagation,
	 * and never fires twice.
	 */
	[ 'click', 'touchend' ].forEach( function ( evt ) {
		document.addEventListener(
			evt,
			function () {
				goFullscreen();
			},
			{ capture: true, once: true, passive: true }
		);
	} );
} )( jQuery );
