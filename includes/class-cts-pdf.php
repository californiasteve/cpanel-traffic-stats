<?php
/**
 * A very small PDF writer: Letter pages, Helvetica text, lines and filled
 * rectangles. Enough for a clean one-to-few page report without bundling a
 * PDF library. Coordinates are in points from the TOP-left corner.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'CTS_TESTING' ) ) {
	exit;
}

/**
 * Minimal PDF document.
 */
class CTS_PDF {

	const W = 612;
	const H = 792;

	/**
	 * Content stream of each page.
	 *
	 * @var string[]
	 */
	private $pages = array();

	/**
	 * Document title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Helvetica widths for chars 32..126 (1/1000 em).
	 *
	 * @var int[]
	 */
	private static $w_regular = array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 );

	/**
	 * Helvetica-Bold widths for chars 32..126.
	 *
	 * @var int[]
	 */
	private static $w_bold = array( 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584 );

	/**
	 * Constructor.
	 *
	 * @param string $title Document title (metadata).
	 */
	public function __construct( $title = '' ) {
		$this->title = $title;
	}

	/**
	 * Start a new page.
	 */
	public function add_page() {
		$this->pages[] = '';
	}

	/**
	 * Number of pages.
	 *
	 * @return int
	 */
	public function page_count() {
		return count( $this->pages );
	}

	/**
	 * Draw text. $y is the baseline, measured from the top.
	 *
	 * @param float  $x     X.
	 * @param float  $y     Baseline Y from the top.
	 * @param string $text  UTF-8 text.
	 * @param float  $size  Font size.
	 * @param bool   $bold  Bold.
	 * @param array  $color RGB 0-255.
	 * @param string $align left|right|center (relative to $x).
	 */
	public function text( $x, $y, $text, $size = 10, $bold = false, array $color = array( 29, 35, 39 ), $align = 'left' ) {
		$enc = self::encode( $text );
		if ( 'left' !== $align ) {
			$w  = $this->width_encoded( $enc, $size, $bold );
			$x -= 'right' === $align ? $w : $w / 2;
		}
		$this->out(
			sprintf(
				'BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET',
				$bold ? 'F2' : 'F1',
				$size,
				self::rgb( $color ),
				$x,
				self::H - $y,
				self::escape( $enc )
			)
		);
	}

	/**
	 * Filled rectangle. $y is the top edge.
	 *
	 * @param float $x     X.
	 * @param float $y     Top.
	 * @param float $w     Width.
	 * @param float $h     Height.
	 * @param array $color RGB.
	 */
	public function rect( $x, $y, $w, $h, array $color ) {
		$this->out( sprintf( '%s rg %.2F %.2F %.2F %.2F re f', self::rgb( $color ), $x, self::H - $y - $h, $w, $h ) );
	}

	/**
	 * Stroked rectangle outline.
	 *
	 * @param float $x     X.
	 * @param float $y     Top.
	 * @param float $w     Width.
	 * @param float $h     Height.
	 * @param array $color RGB.
	 */
	public function box( $x, $y, $w, $h, array $color ) {
		$this->out( sprintf( '0.75 w %s RG %.2F %.2F %.2F %.2F re S', self::rgb( $color ), $x, self::H - $y - $h, $w, $h ) );
	}

	/**
	 * Line.
	 *
	 * @param float $x1    X1.
	 * @param float $y1    Y1.
	 * @param float $x2    X2.
	 * @param float $y2    Y2.
	 * @param array $color RGB.
	 * @param float $width Line width.
	 */
	public function line( $x1, $y1, $x2, $y2, array $color, $width = 0.5 ) {
		$this->out( sprintf( '%.2F w %s RG %.2F %.2F m %.2F %.2F l S', $width, self::rgb( $color ), $x1, self::H - $y1, $x2, self::H - $y2 ) );
	}

	/**
	 * Width of a UTF-8 string in points.
	 *
	 * @param string $text Text.
	 * @param float  $size Font size.
	 * @param bool   $bold Bold.
	 * @return float
	 */
	public function width( $text, $size, $bold = false ) {
		return $this->width_encoded( self::encode( $text ), $size, $bold );
	}

	/**
	 * Shorten text with an ellipsis so it fits a width.
	 *
	 * @param string $text  Text.
	 * @param float  $max   Max width.
	 * @param float  $size  Font size.
	 * @param bool   $bold  Bold.
	 * @return string
	 */
	public function fit( $text, $max, $size, $bold = false ) {
		if ( $this->width( $text, $size, $bold ) <= $max ) {
			return $text;
		}
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		while ( $chars && $this->width( implode( '', $chars ) . '...', $size, $bold ) > $max ) {
			array_pop( $chars );
		}
		return implode( '', $chars ) . '...';
	}

	/**
	 * Build the PDF file.
	 *
	 * @return string
	 */
	public function output() {
		if ( ! $this->pages ) {
			$this->add_page();
		}
		$objects = array();
		// 1: catalog, 2: pages, 3: F1, 4: F2, 5: info, then page + content pairs.
		$kids  = array();
		$n     = count( $this->pages );
		$first = 6;
		for ( $i = 0; $i < $n; $i++ ) {
			$kids[] = ( $first + $i * 2 ) . ' 0 R';
		}
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n . ' >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objects[5] = '<< /Title (' . self::escape( self::encode( $this->title ) ) . ') /Producer (cPanel Traffic Stats) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';

		foreach ( $this->pages as $i => $content ) {
			$page_id               = $first + $i * 2;
			$objects[ $page_id ]   = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ( $page_id + 1 ) . ' 0 R >>';
			$stream                = $content;
			$filter                = '';
			if ( function_exists( 'gzcompress' ) ) {
				$stream = gzcompress( $content );
				$filter = ' /Filter /FlateDecode';
			}
			$objects[ $page_id + 1 ] = "<< /Length " . strlen( $stream ) . $filter . " >>\nstream\n" . $stream . "\nendstream";
		}
		ksort( $objects );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$max  = max( array_keys( $objects ) );
		$pdf .= 'xref' . "\n" . '0 ' . ( $max + 1 ) . "\n" . "0000000000 65535 f \n";
		for ( $i = 1; $i <= $max; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size " . ( $max + 1 ) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}

	/**
	 * Append to the current page.
	 *
	 * @param string $s PDF operators.
	 */
	private function out( $s ) {
		if ( ! $this->pages ) {
			$this->add_page();
		}
		$this->pages[ count( $this->pages ) - 1 ] .= $s . "\n";
	}

	/**
	 * Width of an already-encoded (Windows-1252) string.
	 *
	 * @param string $enc  Encoded text.
	 * @param float  $size Size.
	 * @param bool   $bold Bold.
	 * @return float
	 */
	private function width_encoded( $enc, $size, $bold ) {
		$table = $bold ? self::$w_bold : self::$w_regular;
		$w     = 0;
		$len   = strlen( $enc );
		for ( $i = 0; $i < $len; $i++ ) {
			$c  = ord( $enc[ $i ] );
			$w += ( $c >= 32 && $c <= 126 ) ? $table[ $c - 32 ] : 556;
		}
		return $w * $size / 1000;
	}

	/**
	 * UTF-8 to Windows-1252 (what the standard fonts' WinAnsiEncoding expects).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function encode( $text ) {
		$text = (string) $text;
		$text = str_replace( array( "\r", "\n", "\t" ), ' ', $text );
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $out ) {
				return $out;
			}
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );
		}
		return preg_replace( '/[^\x20-\x7E]/', '?', $text );
	}

	/**
	 * Escape a PDF string literal.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function escape( $s ) {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
	}

	/**
	 * RGB operator operands.
	 *
	 * @param array $c RGB 0-255.
	 * @return string
	 */
	private static function rgb( array $c ) {
		return sprintf( '%.3F %.3F %.3F', $c[0] / 255, $c[1] / 255, $c[2] / 255 );
	}
}
