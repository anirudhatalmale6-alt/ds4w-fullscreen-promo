<?php
/**
 * Plugin Name: Private Book (VIP reading room)
 * Description: Hosts the flipbook on this site instead of a third-party service. Pages are stored encrypted, are unreachable by URL, and are served one at a time only to readers who arrived through an Express Login invitation.
 * Version:     1.0.2
 * Author:      Anirudha Talmale
 * License:     GPL-2.0-or-later
 *
 * WHY THIS EXISTS
 * ---------------
 * The book was embedded from Paperturn, whose viewer is served straight to the reader's browser
 * from a public address. That address worked for anybody who had it — no invitation, no expiry —
 * so the WordPress lock in front of it was decoration: any invited reader could lift the address
 * out of the page and pass the whole book around for good. Paperturn has no way to restrict a
 * flipbook to one website (its "IP authentication" filters on the *reader's* IP, which would lock
 * out every VIP), so the only real fix is to stop relying on a public URL at all.
 *
 * Here the book never leaves this site: the reader gets one page image at a time, each one served
 * by PHP only after checking they are signed in. There is no PDF to download, no address to
 * forward, and nothing for a stranger to find.
 *
 * @package ds4w-private-book
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DS4W_BOOK_VERSION', '1.0.2' );
define( 'DS4W_BOOK_URL', plugin_dir_url( __FILE__ ) );
define( 'DS4W_BOOK_PATH', plugin_dir_path( __FILE__ ) );

require_once DS4W_BOOK_PATH . 'includes/storage.php';

/** Options. */
const DS4W_BOOK_OPT_KEY      = 'ds4w_book_key';      // base64 of the 32-byte encryption key.
const DS4W_BOOK_OPT_DIR      = 'ds4w_book_dir';      // Randomised directory name under uploads.
const DS4W_BOOK_OPT_MANIFEST = 'ds4w_book_manifest'; // Page count, aspect ratio, title.

/* -------------------------------------------------------------------------
 * Who is allowed to read the book
 * ---------------------------------------------------------------------- */

/**
 * The single gate every page image and every search passes through.
 *
 * Express Login performs a genuine WordPress login, so being signed in IS the invitation.
 * Deliberately one function, used by every entry point — a private book with two different
 * ideas about who may read it is a private book with a hole in it.
 *
 * @return bool
 */
function ds4w_book_reader_may_read() {
	/**
	 * Allow something other than a WP login to vouch for a reader.
	 *
	 * @param bool $allowed Whether this visitor may read the book.
	 */
	return (bool) apply_filters( 'ds4w_book_may_read', is_user_logged_in() );
}

/** Refuse, and make sure the refusal is never cached against this URL for somebody else. */
function ds4w_book_deny() {
	nocache_headers();
	header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
	status_header( 403 );
	exit;
}

/* -------------------------------------------------------------------------
 * Where the book lives
 * ---------------------------------------------------------------------- */

/** @return string Absolute path to the (unservable) directory holding the sealed pages. */
function ds4w_book_dir() {
	$dir = (string) get_option( DS4W_BOOK_OPT_DIR, '' );
	if ( '' === $dir ) {
		return '';
	}

	$uploads = wp_upload_dir();

	return trailingslashit( $uploads['basedir'] ) . $dir;
}

/** @return string|false 32-byte binary key, or false if the book hasn't been installed. */
function ds4w_book_key() {
	$k = (string) get_option( DS4W_BOOK_OPT_KEY, '' );

	return '' === $k ? false : base64_decode( $k, true );
}

/** @return array{pages:int,ratio:float,title:string}|null */
function ds4w_book_manifest() {
	$m = get_option( DS4W_BOOK_OPT_MANIFEST, null );

	return is_array( $m ) && ! empty( $m['pages'] ) ? $m : null;
}

/**
 * Path of one sealed page file.
 *
 * The filename is an HMAC of (page, size) under the book's own key, so the names are not
 * guessable from the outside even if the directory ever became listable.
 *
 * @param int    $page 1-based page number.
 * @param string $size 'full' or 'thumb'.
 * @return string
 */
function ds4w_book_page_path( $page, $size ) {
	$key = ds4w_book_key();
	$dir = ds4w_book_dir();

	if ( ! $key || ! $dir ) {
		return '';
	}

	$name = hash_hmac( 'sha256', $size . ':' . (int) $page, $key );

	return $dir . '/p-' . $name . '.php';
}

/* -------------------------------------------------------------------------
 * Serving pages
 * ---------------------------------------------------------------------- */

/**
 * Hand a single page image to an invited reader.
 *
 * Runs on `init` so it answers before WordPress starts rendering a theme, and exits itself.
 */
function ds4w_book_serve_page() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['ds4w_page'] ) ) {
		return;
	}

	$page = (int) $_GET['ds4w_page'];
	$size = ( isset( $_GET['s'] ) && 'thumb' === $_GET['s'] ) ? 'thumb' : 'full';
	// phpcs:enable

	if ( ! ds4w_book_reader_may_read() ) {
		ds4w_book_deny();
	}

	$manifest = ds4w_book_manifest();
	$key      = ds4w_book_key();

	if ( ! $manifest || ! $key || $page < 1 || $page > (int) $manifest['pages'] ) {
		ds4w_book_deny();
	}

	$path = ds4w_book_page_path( $page, $size );
	$blob = ( $path && is_readable( $path ) ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( false === $blob ) {
		ds4w_book_deny();
	}

	$image = ds4w_book_unseal( $blob, $key );

	if ( false === $image ) {
		ds4w_book_deny();
	}

	/*
	 * private + no-store, and never let this be shared.
	 *
	 * The edge on this host rewrites anonymous front-end responses to `public, max-age=2678400`
	 * and ignores what PHP asks for. It does step aside for requests carrying a login cookie —
	 * which every reader of this book has, by definition — so these responses are not cached.
	 * We still say so explicitly: the day that behaviour changes, this is the line that stops a
	 * page of the book being handed to a stranger out of a cache.
	 */
	nocache_headers();
	header( 'Content-Type: image/webp' );
	header( 'Content-Length: ' . strlen( $image ) );
	header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow, noarchive, noimageindex', true );

	echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}
add_action( 'init', 'ds4w_book_serve_page', 5 );

/* -------------------------------------------------------------------------
 * Search
 * ---------------------------------------------------------------------- */

/**
 * Search the book's text and return matching pages.
 *
 * The text index stays on the server: we return page numbers and a short snippet, never the
 * book's full text. Shipping the whole text to the browser to search it client-side would hand
 * every reader a clean, copy-pasteable manuscript — which rather defeats the point of all this.
 */
function ds4w_book_search() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['ds4w_book_search'] ) ) {
		return;
	}

	$q = trim( (string) wp_unslash( $_GET['ds4w_book_search'] ) );
	// phpcs:enable

	if ( ! ds4w_book_reader_may_read() ) {
		ds4w_book_deny();
	}

	nocache_headers();
	header( 'Content-Type: application/json' );
	header( 'Cache-Control: private, no-store', true );

	$key  = ds4w_book_key();
	$path = ds4w_book_dir() . '/text.php';

	if ( mb_strlen( $q ) < 2 || ! $key || ! is_readable( $path ) ) {
		echo wp_json_encode( [ 'results' => [] ] );
		exit;
	}

	$json = ds4w_book_unseal( file_get_contents( $path ), $key ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$text = $json ? json_decode( $json, true ) : [];

	$results = [];

	foreach ( (array) $text as $page => $body ) {
		$pos = mb_stripos( (string) $body, $q );

		if ( false === $pos ) {
			continue;
		}

		// A short window around the hit, so the reader can see which mention this is.
		$start   = max( 0, $pos - 40 );
		$snippet = trim( mb_substr( (string) $body, $start, 110 ) );

		$results[] = [
			'page'    => (int) $page,
			'snippet' => ( $start > 0 ? '…' : '' ) . $snippet . '…',
		];

		if ( count( $results ) >= 60 ) {
			break;
		}
	}

	echo wp_json_encode( [ 'results' => $results ] );
	exit;
}
add_action( 'init', 'ds4w_book_search', 5 );

/* -------------------------------------------------------------------------
 * The viewer
 * ---------------------------------------------------------------------- */

/**
 * [ds4w_book] — drops the reading room into a page.
 *
 * @return string
 */
function ds4w_book_shortcode() {
	$manifest = ds4w_book_manifest();

	if ( ! $manifest ) {
		return current_user_can( 'manage_options' )
			? '<p><strong>Private Book:</strong> no book has been installed yet.</p>'
			: '';
	}

	if ( ! ds4w_book_reader_may_read() ) {
		// Belt and braces. The page itself is already gated; this makes the viewer refuse to
		// render even if the shortcode is ever dropped onto an ungated page by mistake.
		return '';
	}

	wp_enqueue_style( 'ds4w-book-pageflip', DS4W_BOOK_URL . 'assets/vendor/stPageFlip.css', [], DS4W_BOOK_VERSION );
	wp_enqueue_style( 'ds4w-book', DS4W_BOOK_URL . 'assets/book.css', [ 'ds4w-book-pageflip' ], DS4W_BOOK_VERSION );

	wp_enqueue_script( 'ds4w-book-pageflip', DS4W_BOOK_URL . 'assets/vendor/page-flip.browser.js', [], '2.0.7', true );
	wp_enqueue_script( 'ds4w-book', DS4W_BOOK_URL . 'assets/book.js', [ 'ds4w-book-pageflip' ], DS4W_BOOK_VERSION, true );

	wp_localize_script(
		'ds4w-book',
		'DS4W_BOOK',
		[
			'pages'    => (int) $manifest['pages'],
			'ratio'    => (float) $manifest['ratio'],
			'title'    => (string) ( $manifest['title'] ?? '' ),
			'pageUrl'  => home_url( '/?ds4w_page=' ),
			'searchUrl'=> home_url( '/?ds4w_book_search=' ),
		]
	);

	ob_start();
	require DS4W_BOOK_PATH . 'includes/viewer-template.php';

	return (string) ob_get_clean();
}
add_shortcode( 'ds4w_book', 'ds4w_book_shortcode' );

/**
 * Give Popup Maker Pro's analytics script the dependency it forgot to declare.
 *
 * popup-maker-pro/dist/packages/analytics.js reads `window.PUM.hooks`, but it is registered
 * with no dependencies at all — so it is printed BEFORE popup-maker-site.js, which is the script
 * that creates window.PUM. Result: "Cannot read properties of undefined (reading 'hooks')" in the
 * console of every page on the site that loads a popup, this one included.
 *
 * Not our bug, but it's a red error in the reading room and a client reading his own console
 * does not care whose plugin it came from. The fix is to declare the dependency, which makes
 * WordPress order the two correctly. (Enqueuing the dependency separately does NOT work — that
 * doesn't tell WordPress anything about the order these two need to load in.)
 */
function ds4w_book_fix_pum_analytics_deps() {
	$scripts = wp_scripts();
	$handle  = 'popup-maker-pro-analytics';
	$needs   = 'popup-maker-site';

	if ( isset( $scripts->registered[ $handle ], $scripts->registered[ $needs ] )
		&& ! in_array( $needs, $scripts->registered[ $handle ]->deps, true ) ) {
		$scripts->registered[ $handle ]->deps[] = $needs;
	}
}
add_action( 'wp_enqueue_scripts', 'ds4w_book_fix_pum_analytics_deps', 99 );

/**
 * Keep the book out of search engines and out of any cache, wherever it is shown.
 */
add_action(
	'wp_head',
	function () {
		if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'ds4w_book' ) ) {
			echo '<meta name="robots" content="noindex, nofollow, noarchive, noimageindex" />' . "\n";
		}
	},
	1
);
