<?php
/**
 * Parser for AWStats monthly data files (awstatsMMYYYY.domain.txt).
 *
 * cPanel keeps these in ~/tmp/awstats/ (HTTP) and ~/tmp/awstats/ssl/ (HTTPS).
 * The file is a plain-text database made of sections such as:
 *
 *   BEGIN_DAY 3
 *   # Date - Pages - Hits - Bandwidth - Visits
 *   20261101 120 450 1234567 40
 *   END_DAY
 *
 * This class has no WordPress dependencies so it can be unit tested on its own.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'CTS_TESTING' ) ) {
	exit;
}

/**
 * AWStats data file parser.
 */
class CTS_AWStats_Parser {

	/**
	 * Split a raw AWStats data file into its sections.
	 *
	 * @param string $content Raw file contents.
	 * @return array<string, array<int, string[]>> Section name => list of rows (each row is a token list).
	 */
	public static function sections( $content ) {
		$sections = array();
		$current  = null;
		$lines    = preg_split( '/\r\n|\r|\n/', (string) $content );

		foreach ( $lines as $line ) {
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '/^BEGIN_([A-Z0-9_]+)\b/', $line, $m ) ) {
				$current              = $m[1];
				$sections[ $current ] = array();
				continue;
			}
			if ( null !== $current && 0 === strpos( $line, 'END_' . $current ) ) {
				$current = null;
				continue;
			}
			if ( null !== $current ) {
				$sections[ $current ][] = explode( ' ', trim( $line ) );
			}
		}

		return $sections;
	}

	/**
	 * Parse a data file into a structured stats array.
	 *
	 * @param string $content Raw file contents.
	 * @return array Structured stats (see README for the shape).
	 */
	public static function parse( $content ) {
		$s = self::sections( $content );

		$stats = self::empty_stats();

		// General.
		$general = array();
		foreach ( self::rows( $s, 'GENERAL' ) as $row ) {
			$general[ $row[0] ] = isset( $row[1] ) ? $row[1] : '';
		}
		$stats['summary']['unique']      = self::int( $general, 'TotalUnique' );
		$stats['summary']['visits']      = self::int( $general, 'TotalVisits' );
		$stats['summary']['last_update'] = isset( $general['LastUpdate'] ) ? substr( $general['LastUpdate'], 0, 14 ) : '';

		// Days: Date - Pages - Hits - Bandwidth - Visits.
		foreach ( self::rows( $s, 'DAY' ) as $row ) {
			$stats['days'][ $row[0] ] = array(
				'pages'     => self::n( $row, 1 ),
				'hits'      => self::n( $row, 2 ),
				'bandwidth' => self::n( $row, 3 ),
				'visits'    => self::n( $row, 4 ),
			);
		}
		ksort( $stats['days'] );

		// Hours: Hour - Pages - Hits - Bandwidth - Not viewed Pages - Not viewed Hits - Not viewed Bandwidth.
		for ( $h = 0; $h < 24; $h++ ) {
			$stats['hours'][ $h ] = array(
				'pages'     => 0,
				'hits'      => 0,
				'bandwidth' => 0,
			);
		}
		foreach ( self::rows( $s, 'TIME' ) as $row ) {
			$h = (int) $row[0];
			if ( $h < 0 || $h > 23 ) {
				continue;
			}
			$stats['hours'][ $h ] = array(
				'pages'     => self::n( $row, 1 ),
				'hits'      => self::n( $row, 2 ),
				'bandwidth' => self::n( $row, 3 ),
			);
			$stats['summary']['pages']                += self::n( $row, 1 );
			$stats['summary']['hits']                 += self::n( $row, 2 );
			$stats['summary']['bandwidth']            += self::n( $row, 3 );
			$stats['summary']['not_viewed_pages']     += self::n( $row, 4 );
			$stats['summary']['not_viewed_hits']      += self::n( $row, 5 );
			$stats['summary']['not_viewed_bandwidth'] += self::n( $row, 6 );
		}

		// Fall back to the DAY section for totals if TIME is missing.
		if ( 0 === $stats['summary']['hits'] && ! empty( $stats['days'] ) ) {
			foreach ( $stats['days'] as $d ) {
				$stats['summary']['pages']     += $d['pages'];
				$stats['summary']['hits']      += $d['hits'];
				$stats['summary']['bandwidth'] += $d['bandwidth'];
			}
		}
		if ( 0 === $stats['summary']['visits'] && ! empty( $stats['days'] ) ) {
			foreach ( $stats['days'] as $d ) {
				$stats['summary']['visits'] += $d['visits'];
			}
		}

		// Pages (SIDER): URL - Pages - Bandwidth - Entry - Exit.
		foreach ( self::rows( $s, 'SIDER' ) as $row ) {
			$stats['pages'][ $row[0] ] = array(
				'pages'     => self::n( $row, 1 ),
				'bandwidth' => self::n( $row, 2 ),
				'entry'     => self::n( $row, 3 ),
				'exit'      => self::n( $row, 4 ),
			);
		}

		// External referrers: URL - Pages - Hits.
		foreach ( self::rows( $s, 'PAGEREFS' ) as $row ) {
			$stats['referrers'][ $row[0] ] = array(
				'pages' => self::n( $row, 1 ),
				'hits'  => self::n( $row, 2 ),
			);
		}

		// Search engines: ID - Pages - Hits.
		foreach ( self::rows( $s, 'SEREFERRALS' ) as $row ) {
			$label = self::search_engine_label( $row[0] );
			self::add( $stats['search_engines'], $label, array(
				'pages' => self::n( $row, 1 ),
				'hits'  => self::n( $row, 2 ),
			) );
		}

		// Search key phrases: phrase - count.
		foreach ( self::rows( $s, 'SEARCHWORDS' ) as $row ) {
			$phrase = trim( urldecode( str_replace( '+', ' ', $row[0] ) ) );
			if ( '' === $phrase ) {
				continue;
			}
			self::add( $stats['keyphrases'], $phrase, array( 'count' => self::n( $row, 1 ) ) );
		}

		// Countries / domains: code - Pages - Hits - Bandwidth.
		foreach ( self::rows( $s, 'DOMAIN' ) as $row ) {
			$stats['countries'][ $row[0] ] = array(
				'pages'     => self::n( $row, 1 ),
				'hits'      => self::n( $row, 2 ),
				'bandwidth' => self::n( $row, 3 ),
			);
		}

		// Browsers: ID - Hits [- Pages].
		foreach ( self::rows( $s, 'BROWSER' ) as $row ) {
			self::add( $stats['browsers'], self::browser_label( $row[0] ), array( 'hits' => self::n( $row, 1 ) ) );
		}

		// Operating systems: ID - Hits [- Pages].
		foreach ( self::rows( $s, 'OS' ) as $row ) {
			self::add( $stats['os'], self::os_label( $row[0] ), array( 'hits' => self::n( $row, 1 ) ) );
		}

		// Robots: ID - Hits - Bandwidth - Last visit - Hits on robots.txt.
		foreach ( self::rows( $s, 'ROBOT' ) as $row ) {
			$stats['robots'][ $row[0] ] = array(
				'hits'      => self::n( $row, 1 ),
				'bandwidth' => self::n( $row, 2 ),
			);
		}

		// HTTP errors: code - Hits - Bandwidth.
		foreach ( self::rows( $s, 'ERRORS' ) as $row ) {
			$stats['errors'][ $row[0] ] = array(
				'hits'      => self::n( $row, 1 ),
				'bandwidth' => self::n( $row, 2 ),
			);
		}

		// 404 pages: URL - Hits - Last referrer.
		foreach ( self::rows( $s, 'SIDER_404' ) as $row ) {
			$stats['not_found'][ $row[0] ] = array( 'hits' => self::n( $row, 1 ) );
		}

		return $stats;
	}

	/**
	 * An empty stats structure.
	 *
	 * @return array
	 */
	public static function empty_stats() {
		return array(
			'summary'        => array(
				'unique'               => 0,
				'visits'               => 0,
				'pages'                => 0,
				'hits'                 => 0,
				'bandwidth'            => 0,
				'not_viewed_pages'     => 0,
				'not_viewed_hits'      => 0,
				'not_viewed_bandwidth' => 0,
				'last_update'          => '',
			),
			'days'           => array(),
			'hours'          => array(),
			'pages'          => array(),
			'referrers'      => array(),
			'search_engines' => array(),
			'keyphrases'     => array(),
			'countries'      => array(),
			'browsers'       => array(),
			'os'             => array(),
			'robots'         => array(),
			'errors'         => array(),
			'not_found'      => array(),
		);
	}

	/**
	 * Merge two stats arrays (e.g. the HTTP and HTTPS data for the same month).
	 * Numbers are summed, strings keep the greater value. Unique visitors are
	 * summed too, so a visitor seen on both HTTP and HTTPS counts twice.
	 *
	 * @param array $a First stats array.
	 * @param array $b Second stats array.
	 * @return array
	 */
	public static function merge( array $a, array $b ) {
		foreach ( $b as $key => $value ) {
			if ( ! array_key_exists( $key, $a ) ) {
				$a[ $key ] = $value;
			} elseif ( is_array( $value ) && is_array( $a[ $key ] ) ) {
				$a[ $key ] = self::merge( $a[ $key ], $value );
			} elseif ( is_numeric( $value ) && is_numeric( $a[ $key ] ) && ! is_string( $value ) ) {
				$a[ $key ] += $value;
			} else {
				$a[ $key ] = max( (string) $a[ $key ], (string) $value );
			}
		}
		if ( isset( $a['days'] ) && is_array( $a['days'] ) ) {
			ksort( $a['days'] );
		}
		return $a;
	}

	/**
	 * Find the year/month encoded in an AWStats file name.
	 *
	 * @param string $filename e.g. awstats112026.example.com.txt.
	 * @return array{0:int,1:int}|null [year, month] or null.
	 */
	public static function period_from_filename( $filename ) {
		if ( preg_match( '/^awstats(\d{2})(\d{4})\./', basename( $filename ), $m ) ) {
			return array( (int) $m[2], (int) $m[1] );
		}
		return null;
	}

	/**
	 * Build the file name AWStats uses for a month/domain.
	 *
	 * @param int    $year   Year.
	 * @param int    $month  Month 1-12.
	 * @param string $domain Domain (config name).
	 * @return string
	 */
	public static function filename( $year, $month, $domain ) {
		return sprintf( 'awstats%02d%04d.%s.txt', $month, $year, $domain );
	}

	/**
	 * Human-friendly browser name from an AWStats browser ID.
	 *
	 * @param string $id e.g. chrome120.0, firefox, msie11.0.
	 * @return string
	 */
	public static function browser_label( $id ) {
		$base = strtolower( preg_replace( '/[\d._]+$/', '', $id ) );
		$map  = array(
			'chrome'    => 'Chrome',
			'firefox'   => 'Firefox',
			'safari'    => 'Safari',
			'edge'      => 'Edge',
			'msedge'    => 'Edge',
			'msie'      => 'Internet Explorer',
			'opera'     => 'Opera',
			'samsung'   => 'Samsung Internet',
			'android'   => 'Android Browser',
			'netscape'  => 'Netscape',
			'mozilla'   => 'Mozilla',
			'libwww'    => 'libwww',
			'curl'      => 'curl',
			'wget'      => 'Wget',
			'Unknown'   => 'Unknown',
			'unknown'   => 'Unknown',
		);
		if ( isset( $map[ $base ] ) ) {
			return $map[ $base ];
		}
		return '' === $base ? 'Unknown' : ucfirst( $base );
	}

	/**
	 * Human-friendly OS name from an AWStats OS ID.
	 *
	 * @param string $id e.g. win10, macosx15, ios, android, linuxubuntu.
	 * @return string
	 */
	public static function os_label( $id ) {
		$id       = strtolower( $id );
		$prefixes = array(
			'winphone' => 'Windows Phone',
			'win'      => 'Windows',
			'macos'    => 'macOS',
			'mac'      => 'macOS',
			'ios'      => 'iOS',
			'android'  => 'Android',
			'linux'    => 'Linux',
			'cros'     => 'Chrome OS',
			'bsd'      => 'BSD',
			'freebsd'  => 'BSD',
			'unknown'  => 'Unknown',
		);
		foreach ( $prefixes as $prefix => $label ) {
			if ( 0 === strpos( $id, $prefix ) ) {
				return $label;
			}
		}
		return ucfirst( $id );
	}

	/**
	 * Human-friendly search engine name.
	 *
	 * @param string $id e.g. google, bing, yahoo, duckduckgo.
	 * @return string
	 */
	public static function search_engine_label( $id ) {
		$id  = strtolower( $id );
		$map = array(
			'google'     => 'Google',
			'googleimages' => 'Google Images',
			'bing'       => 'Bing',
			'yahoo'      => 'Yahoo',
			'duckduckgo' => 'DuckDuckGo',
			'yandex'     => 'Yandex',
			'baidu'      => 'Baidu',
			'ecosia'     => 'Ecosia',
			'aol'        => 'AOL',
			'ask'        => 'Ask',
		);
		foreach ( $map as $prefix => $label ) {
			if ( $id === $prefix ) {
				return $label;
			}
		}
		if ( 0 === strpos( $id, 'google' ) ) {
			return 'Google';
		}
		return ucfirst( str_replace( '_', ' ', $id ) );
	}

	/**
	 * Rows of a section, or an empty list.
	 *
	 * @param array  $s    Sections.
	 * @param string $name Section name.
	 * @return array
	 */
	private static function rows( array $s, $name ) {
		return isset( $s[ $name ] ) ? $s[ $name ] : array();
	}

	/**
	 * Numeric column of a row.
	 *
	 * @param array $row Row tokens.
	 * @param int   $i   Column index.
	 * @return int|float
	 */
	private static function n( array $row, $i ) {
		if ( ! isset( $row[ $i ] ) || ! is_numeric( $row[ $i ] ) ) {
			return 0;
		}
		return $row[ $i ] + 0;
	}

	/**
	 * Integer from the GENERAL section.
	 *
	 * @param array  $general GENERAL key/values.
	 * @param string $key     Key.
	 * @return int
	 */
	private static function int( array $general, $key ) {
		return isset( $general[ $key ] ) && is_numeric( $general[ $key ] ) ? (int) $general[ $key ] : 0;
	}

	/**
	 * Add metrics to a keyed table, summing if the key exists.
	 *
	 * @param array  $table   Table (by reference).
	 * @param string $key     Row key.
	 * @param array  $metrics Metrics to add.
	 */
	private static function add( array &$table, $key, array $metrics ) {
		if ( ! isset( $table[ $key ] ) ) {
			$table[ $key ] = $metrics;
			return;
		}
		foreach ( $metrics as $k => $v ) {
			$table[ $key ][ $k ] = ( isset( $table[ $key ][ $k ] ) ? $table[ $key ][ $k ] : 0 ) + $v;
		}
	}
}
