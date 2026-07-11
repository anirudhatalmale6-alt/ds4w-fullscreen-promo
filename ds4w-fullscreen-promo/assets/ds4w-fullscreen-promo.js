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

	var $popup = null;
	var unlocked = false;   // Flips true once the visitor has acted. Only then may the popup close.
	var fsAttempted = false; // We only ever fire one fullscreen request.

	var root = document.documentElement;

	/* ------------------------------------------------------------------ */
	/* Fullscreen                                                          */
	/* ------------------------------------------------------------------ */

	function fullscreenSupported() {
		return !! (
			root.requestFullscreen ||
			root.webkitRequestFullscreen ||
			root.webkitRequestFullScreen ||
			root.mozRequestFullScreen ||
			root.msRequestFullscreen
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
	 */
	function pseudoFullscreen() {
		root.classList.add( 'ds4w-pseudo-fullscreen' );
		document.body.classList.add( 'ds4w-pseudo-fullscreen' );
	}

	/**
	 * MUST be called synchronously from inside a user-gesture handler.
	 * Any await/setTimeout before this point discards the activation and the request fails.
	 */
	function goFullscreen() {
		if ( fsAttempted || isFullscreen() ) {
			return;
		}
		fsAttempted = true;

		if ( ! fullscreenSupported() ) {
			pseudoFullscreen(); // iOS and other holdouts.
			return;
		}

		var req =
			root.requestFullscreen ||
			root.webkitRequestFullscreen ||
			root.webkitRequestFullScreen ||
			root.mozRequestFullScreen ||
			root.msRequestFullscreen;

		try {
			var result = req.call( root, { navigationUI: 'hide' } );

			// Standards-compliant browsers return a promise that rejects if the
			// request is refused (e.g. gesture expired, or an iframe without the
			// allow="fullscreen" permission). Fall back rather than fail silently.
			if ( result && typeof result.catch === 'function' ) {
				result.catch( function () {
					pseudoFullscreen();
				} );
			}
		} catch ( e ) {
			pseudoFullscreen();
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
		releaseLock();

		if ( window.PUM && typeof window.PUM.close === 'function' ) {
			window.PUM.close( popupId );
		}

		if ( CTA_URL ) {
			// Note: navigating away drops fullscreen — a new document has no user
			// activation, so the browser exits. Deliberate, and documented for the client.
			window.location.href = CTA_URL;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	$( document )
		// Popup Maker fires this on the popup element once it has opened.
		.on( 'pumAfterOpen', '#pum-' + popupId, function () {
			$popup = $( this );
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
	 * Second safety net: catch the visitor's first gesture ANYWHERE on the page and
	 * use it for fullscreen. Covers the case where they click/tap/press outside the
	 * CTA (or the client edits the popup and drops the ds4w-fs-go class).
	 *
	 * Capture phase + { once: true } so it runs before anything can stop propagation,
	 * and never fires twice.
	 */
	[ 'click', 'touchend', 'keydown' ].forEach( function ( evt ) {
		document.addEventListener(
			evt,
			function () {
				goFullscreen();
			},
			{ capture: true, once: true, passive: true }
		);
	} );
} )( jQuery );
