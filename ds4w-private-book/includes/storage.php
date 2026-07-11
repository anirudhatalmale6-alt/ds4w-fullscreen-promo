<?php
/**
 * How the book is stored on disk, and why it is stored that way.
 *
 * The whole point of this plugin is that the book must not exist at any public address. That
 * turned out to be harder than it sounds on this host, and the design below is the result of
 * testing what the server actually does rather than what it is supposed to do:
 *
 *   1. PHP is jailed to /html. It cannot read anything outside the web root — not even the
 *      account's own home directory. So "just put it above the web root" is not available.
 *   2. .htaccess is IGNORED. Static files are served by nginx. A `Require all denied` file
 *      next to the pages does nothing at all: the page came straight back over HTTP, 200.
 *   3. BUT .php files under wp-content/uploads are refused outright — 403, and the body is
 *      never disclosed. Verified repeatedly.
 *
 * So each page is written as a .php file, which the edge will not serve. And because a hosting
 * provider can change a rule like that at any time without telling anybody, the bytes inside
 * are encrypted too, and the file begins with a PHP guard that 404s if it is ever executed.
 * Three independent things have to fail before a single page of the book leaks:
 *
 *      <?php http_response_code(404); exit; __halt_compiler();  [ 16-byte IV | AES-256-CTR ]
 *      ^ never served (403)  ^ 404s if ever executed            ^ useless without the key
 *
 * __halt_compiler() rather than a plain `exit;` + raw bytes: everything after it is not parsed
 * at all, so a stray "<?php" occurring naturally inside binary image data can never be picked
 * up as code.
 *
 * The key lives in wp_options, i.e. in the database — so a stolen copy of the files alone
 * doesn't read the book either.
 *
 * @package ds4w-private-book
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DS4W_BOOK_CLI' ) ) {
	exit;
}

const DS4W_BOOK_GUARD  = '<?php http_response_code(404); exit; __halt_compiler();';
const DS4W_BOOK_CIPHER = 'aes-256-ctr';

/**
 * Wrap a payload into the on-disk format: guard, then IV, then ciphertext.
 *
 * @param string $plain Raw bytes (an image, or the JSON text index).
 * @param string $key   32-byte binary key.
 * @return string
 */
function ds4w_book_seal( $plain, $key ) {
	$iv = random_bytes( 16 );
	$ct = openssl_encrypt( $plain, DS4W_BOOK_CIPHER, $key, OPENSSL_RAW_DATA, $iv );

	if ( false === $ct ) {
		throw new RuntimeException( 'Encryption failed' );
	}

	return DS4W_BOOK_GUARD . $iv . $ct;
}

/**
 * Reverse of ds4w_book_seal().
 *
 * @param string $blob Whole file contents.
 * @param string $key  32-byte binary key.
 * @return string|false Raw bytes, or false if the file is not ours / is corrupt.
 */
function ds4w_book_unseal( $blob, $key ) {
	$guard = strlen( DS4W_BOOK_GUARD );

	if ( strlen( $blob ) < $guard + 16 || 0 !== strncmp( $blob, DS4W_BOOK_GUARD, $guard ) ) {
		return false;
	}

	$iv = substr( $blob, $guard, 16 );
	$ct = substr( $blob, $guard + 16 );

	return openssl_decrypt( $ct, DS4W_BOOK_CIPHER, $key, OPENSSL_RAW_DATA, $iv );
}
