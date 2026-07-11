<?php
/**
 * The reading room markup.
 *
 * Note there are no page images in here. Every <img> starts empty and is filled in by book.js
 * only when the reader is close to that page, from an address that checks their invitation.
 * View-source on this page shows the shape of a book and not one line of its content.
 *
 * @package ds4w-private-book
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ds4w_pages = (int) $manifest['pages'];
?>
<div class="ds4w-book" id="ds4w-book" data-pages="<?php echo esc_attr( (string) $ds4w_pages ); ?>">

	<div class="ds4w-book__stage">
		<div class="ds4w-book__flip" id="ds4w-book-flip"></div>

		<button class="ds4w-book__edge ds4w-book__edge--prev" id="ds4w-prev" aria-label="Previous page">
			<span aria-hidden="true">&#10094;</span>
		</button>
		<button class="ds4w-book__edge ds4w-book__edge--next" id="ds4w-next" aria-label="Next page">
			<span aria-hidden="true">&#10095;</span>
		</button>

		<div class="ds4w-book__loading" id="ds4w-loading">Opening the book…</div>
	</div>

	<?php
	/*
	 * Inline SVG rather than unicode glyphs (⌕ ⛶ ▦ …). Those are only as good as the fonts on
	 * the reader's machine, and on a plain Linux/Chrome they came out as a "p" for the search
	 * icon and an empty box for fullscreen. An icon that renders as the wrong letter is worse
	 * than no icon.
	 */
	$ds4w_ico = static function ( $paths ) {
		return '<svg class="ds4w-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
			. 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
	};

	$ds4w_icons = [
		'grid'   => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
		'search' => '<circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/>',
		'zoom'   => '<circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/>',
		'full'   => '<polyline points="4,9 4,4 9,4"/><polyline points="15,4 20,4 20,9"/><polyline points="20,15 20,20 15,20"/><polyline points="9,20 4,20 4,15"/>',
		'prev'   => '<polyline points="15,5 8,12 15,19"/>',
		'next'   => '<polyline points="9,5 16,12 9,19"/>',
		'first'  => '<polyline points="17,5 10,12 17,19"/><line x1="6" y1="5" x2="6" y2="19"/>',
		'last'   => '<polyline points="7,5 14,12 7,19"/><line x1="18" y1="5" x2="18" y2="19"/>',
	];
	?>

	<div class="ds4w-book__bar">
		<div class="ds4w-book__group">
			<button class="ds4w-book__btn" id="ds4w-contents" title="Pages">
				<?php echo $ds4w_ico( $ds4w_icons['grid'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="ds4w-lbl">Pages</span>
			</button>
			<button class="ds4w-book__btn" id="ds4w-search-open" title="Search the book">
				<?php echo $ds4w_ico( $ds4w_icons['search'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="ds4w-lbl">Search</span>
			</button>
		</div>

		<div class="ds4w-book__group ds4w-book__nav">
			<button class="ds4w-book__btn ds4w-book__btn--icon" id="ds4w-first" title="First page" aria-label="First page">
				<?php echo $ds4w_ico( $ds4w_icons['first'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
			<button class="ds4w-book__btn ds4w-book__btn--icon" id="ds4w-prev2" title="Previous page" aria-label="Previous page">
				<?php echo $ds4w_ico( $ds4w_icons['prev'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
			<label class="ds4w-book__counter">
				<input type="text" id="ds4w-pageno" value="1" inputmode="numeric" aria-label="Page number">
				<span> / <?php echo esc_html( (string) $ds4w_pages ); ?></span>
			</label>
			<button class="ds4w-book__btn ds4w-book__btn--icon" id="ds4w-next2" title="Next page" aria-label="Next page">
				<?php echo $ds4w_ico( $ds4w_icons['next'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
			<button class="ds4w-book__btn ds4w-book__btn--icon" id="ds4w-last" title="Last page" aria-label="Last page">
				<?php echo $ds4w_ico( $ds4w_icons['last'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
		</div>

		<div class="ds4w-book__group">
			<button class="ds4w-book__btn" id="ds4w-zoom" title="Zoom in / out">
				<?php echo $ds4w_ico( $ds4w_icons['zoom'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="ds4w-lbl">Zoom</span>
			</button>
			<button class="ds4w-book__btn" id="ds4w-full" title="Fullscreen">
				<?php echo $ds4w_ico( $ds4w_icons['full'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="ds4w-lbl">Fullscreen</span>
			</button>
		</div>
	</div>

	<!-- Thumbnails -->
	<div class="ds4w-book__panel" id="ds4w-thumbs-panel" hidden>
		<div class="ds4w-book__panel-head">
			<strong>Pages</strong>
			<button class="ds4w-book__close" id="ds4w-thumbs-close" aria-label="Close">&times;</button>
		</div>
		<div class="ds4w-book__thumbs" id="ds4w-thumbs"></div>
	</div>

	<!-- Search -->
	<div class="ds4w-book__panel" id="ds4w-search-panel" hidden>
		<div class="ds4w-book__panel-head">
			<strong>Search</strong>
			<button class="ds4w-book__close" id="ds4w-search-close" aria-label="Close">&times;</button>
		</div>
		<div class="ds4w-book__search">
			<input type="search" id="ds4w-search-input" placeholder="Search the book…" autocomplete="off">
		</div>
		<div class="ds4w-book__results" id="ds4w-results"></div>
	</div>

</div>
