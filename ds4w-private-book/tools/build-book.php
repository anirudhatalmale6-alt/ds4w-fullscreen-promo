<?php
/**
 * Turn a PDF into a private book: page images + thumbnails + a text index, all sealed.
 *
 * Run on a machine that has poppler-utils and ImageMagick — NOT on the web host, which has
 * neither. It produces a directory you upload, plus the three option values to install.
 *
 *   php build-book.php <book.pdf> <output-dir> [--dpi=150] [--quality=82]
 *
 * The output filenames are HMACs under the book's own key, so they give nothing away, and each
 * file's contents are encrypted (see includes/storage.php for why all of this is necessary).
 *
 * @package ds4w-private-book
 */

define( 'DS4W_BOOK_CLI', true );
require_once __DIR__ . '/../includes/storage.php';

// ---------------------------------------------------------------------------

$args = array_slice( $argv, 1 );
$opts = [ 'dpi' => 150, 'quality' => 82 ];
$pos  = [];

foreach ( $args as $a ) {
	if ( preg_match( '/^--([a-z]+)=(.+)$/', $a, $m ) ) {
		$opts[ $m[1] ] = $m[2];
	} else {
		$pos[] = $a;
	}
}

if ( count( $pos ) < 2 ) {
	fwrite( STDERR, "usage: php build-book.php <book.pdf> <output-dir> [--dpi=150] [--quality=82]\n" );
	exit( 1 );
}

list( $pdf, $out ) = $pos;

if ( ! is_readable( $pdf ) ) {
	fwrite( STDERR, "cannot read: $pdf\n" );
	exit( 1 );
}

function run( $cmd ) {
	exec( $cmd . ' 2>&1', $o, $rc );
	if ( 0 !== $rc ) {
		fwrite( STDERR, "FAILED: $cmd\n" . implode( "\n", $o ) . "\n" );
		exit( 1 );
	}
	return $o;
}

// --- How many pages, and what shape are they? ------------------------------

$info  = implode( "\n", run( 'pdfinfo ' . escapeshellarg( $pdf ) ) );
preg_match( '/^Pages:\s+(\d+)/m', $info, $m );
$pages = (int) ( $m[1] ?? 0 );

preg_match( '/^Page size:\s+([\d.]+) x ([\d.]+)/m', $info, $m );
$ratio = ( ! empty( $m[2] ) && (float) $m[2] > 0 ) ? round( (float) $m[1] / (float) $m[2], 4 ) : 0.707;

if ( $pages < 1 ) {
	fwrite( STDERR, "could not read a page count from the PDF\n" );
	exit( 1 );
}

printf( "book: %d pages, page ratio %.4f (w/h)\n", $pages, $ratio );

// --- Key + output ----------------------------------------------------------

$key    = random_bytes( 32 );
$dirTag = 'ds4w-book-' . bin2hex( random_bytes( 8 ) );
$dest   = rtrim( $out, '/' ) . '/' . $dirTag;

if ( ! is_dir( $dest ) && ! mkdir( $dest, 0755, true ) ) {
	fwrite( STDERR, "cannot create $dest\n" );
	exit( 1 );
}

$tmp = sys_get_temp_dir() . '/ds4w-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp, 0700, true );

// --- Render ----------------------------------------------------------------

$text = [];

for ( $n = 1; $n <= $pages; $n++ ) {
	// Full page.
	run( sprintf(
		'pdftoppm -f %d -l %d -r %d -png -singlefile %s %s',
		$n, $n, (int) $opts['dpi'], escapeshellarg( $pdf ), escapeshellarg( "$tmp/p" )
	) );

	foreach ( [ 'full' => 1600, 'thumb' => 240 ] as $size => $width ) {
		$webp = "$tmp/$size.webp";

		run( sprintf(
			'convert %s -resize %dx -quality %d -define webp:method=5 %s',
			escapeshellarg( "$tmp/p.png" ), $width, (int) $opts['quality'], escapeshellarg( $webp )
		) );

		$name = hash_hmac( 'sha256', $size . ':' . $n, $key );
		file_put_contents( "$dest/p-$name.php", ds4w_book_seal( file_get_contents( $webp ), $key ) );
		unlink( $webp );
	}

	// Page text, for server-side search. Never shipped to the browser wholesale.
	$txt = "$tmp/p.txt";
	run( sprintf(
		'pdftotext -f %d -l %d -layout %s %s',
		$n, $n, escapeshellarg( $pdf ), escapeshellarg( $txt )
	) );

	$text[ $n ] = preg_replace( '/\s+/u', ' ', trim( (string) file_get_contents( $txt ) ) );

	unlink( "$tmp/p.png" );
	@unlink( $txt );

	if ( 0 === $n % 10 || $n === $pages ) {
		printf( "  rendered %d/%d\n", $n, $pages );
	}
}

file_put_contents( "$dest/text.php", ds4w_book_seal( json_encode( $text ), $key ) );

array_map( 'unlink', glob( "$tmp/*" ) ?: [] );
@rmdir( $tmp );

// --- What to install -------------------------------------------------------

$title = '';
if ( preg_match( '/^Title:\s+(.+)$/m', $info, $m ) ) {
	$title = trim( $m[1] );
}

$manifest = [
	'pages' => $pages,
	'ratio' => $ratio,
	'title' => $title,
];

$install = [
	'dir'      => $dirTag,
	'key'      => base64_encode( $key ),
	'manifest' => $manifest,
];

file_put_contents( rtrim( $out, '/' ) . '/install.json', json_encode( $install, JSON_PRETTY_PRINT ) );

printf(
	"\ndone.\n  pages dir : %s\n  files     : %d\n  size      : %.1f MB\n  install   : %s/install.json\n",
	$dest,
	count( glob( "$dest/*.php" ) ?: [] ),
	array_sum( array_map( 'filesize', glob( "$dest/*.php" ) ?: [] ) ) / 1048576,
	rtrim( $out, '/' )
);
