<?php
/**
 * Parser for Webalizer output.
 *
 * cPanel keeps Webalizer output in ~/tmp/webalizer/ (HTTP) and
 * ~/tmp/webalizerssl/ (HTTPS). Two files are useful:
 *
 *  - webalizer.hist: one line per month with the monthly totals:
 *    month year hits files sites kbytes first_day last_day pages visits
 *  - usage_YYYYMM.html: the monthly report, which holds the daily table.
 *
 * No WordPress dependencies, so it can be unit tested on its own.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'CTS_TESTING' ) ) {
	exit;
}

/**
 * Webalizer parser.
 */
class CTS_Webalizer_Parser {

	/**
	 * Parse webalizer.hist into a list of months, newest first.
	 *
	 * @param string $content Raw webalizer.hist contents.
	 * @return array<string, array> Keyed by "YYYY-MM".
	 */
	public static function parse_history( $content ) {
		$months = array();
		$lines  = preg_split( '/\r\n|\r|\n/', (string) $content );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$p = preg_split( '/\s+/', $line );
			if ( count( $p ) < 6 || ! is_numeric( $p[0] ) || ! is_numeric( $p[1] ) ) {
				continue;
			}
			$month = (int) $p[0];
			$year  = (int) $p[1];
			if ( $month < 1 || $month > 12 ) {
				continue;
			}
			$key            = sprintf( '%04d-%02d', $year, $month );
			$months[ $key ] = array(
				'year'      => $year,
				'month'     => $month,
				'hits'      => (int) $p[2],
				'files'     => (int) $p[3],
				'sites'     => (int) $p[4],
				'kbytes'    => (float) $p[5],
				'first_day' => isset( $p[6] ) ? (int) $p[6] : 1,
				'last_day'  => isset( $p[7] ) ? (int) $p[7] : 0,
				'pages'     => isset( $p[8] ) ? (int) $p[8] : 0,
				'visits'    => isset( $p[9] ) ? (int) $p[9] : 0,
			);
		}

		krsort( $months );
		return $months;
	}

	/**
	 * Parse the "Daily Statistics" table out of a Webalizer usage_YYYYMM.html report.
	 *
	 * Each data row looks like:
	 *   <TR><TD ALIGN=center><B>1</B></TD><TD ALIGN=right><B>450</B></TD><TD ...>12.3%</TD>
	 *   ... Hits, Files, Pages, Visits, Sites, KBytes (each followed by a percent cell).
	 *
	 * @param string $html Report HTML.
	 * @return array<int, array> Day of month => metrics.
	 */
	public static function parse_daily( $html ) {
		$days = array();
		if ( ! preg_match( '/Daily Statistics for(.*?)<\/TABLE>/is', (string) $html, $m ) ) {
			return $days;
		}
		if ( ! preg_match_all( '/<TR[^>]*>(.*?)<\/TR>/is', $m[1], $rows ) ) {
			return $days;
		}
		foreach ( $rows[1] as $row ) {
			if ( ! preg_match_all( '/<TD[^>]*>(.*?)<\/TD>/is', $row, $cells ) ) {
				continue;
			}
			$values = array_map(
				static function ( $c ) {
					return trim( cts_strip_tags( $c ) );
				},
				$cells[1]
			);
			// Day + 6 metrics, each metric followed by its percentage = 13 cells.
			if ( count( $values ) < 13 || ! ctype_digit( $values[0] ) ) {
				continue;
			}
			$days[ (int) $values[0] ] = array(
				'hits'   => (int) $values[1],
				'files'  => (int) $values[3],
				'pages'  => (int) $values[5],
				'visits' => (int) $values[7],
				'sites'  => (int) $values[9],
				'kbytes' => (float) $values[11],
			);
		}
		ksort( $days );
		return $days;
	}

	/**
	 * Merge two parsed histories (HTTP + HTTPS) by summing each month.
	 *
	 * @param array $a History.
	 * @param array $b History.
	 * @return array
	 */
	public static function merge_history( array $a, array $b ) {
		foreach ( $b as $key => $row ) {
			if ( ! isset( $a[ $key ] ) ) {
				$a[ $key ] = $row;
				continue;
			}
			foreach ( array( 'hits', 'files', 'sites', 'kbytes', 'pages', 'visits' ) as $f ) {
				$a[ $key ][ $f ] += $row[ $f ];
			}
			$a[ $key ]['first_day'] = min( $a[ $key ]['first_day'], $row['first_day'] );
			$a[ $key ]['last_day']  = max( $a[ $key ]['last_day'], $row['last_day'] );
		}
		krsort( $a );
		return $a;
	}

	/**
	 * Merge two parsed daily tables by summing each day.
	 *
	 * @param array $a Days.
	 * @param array $b Days.
	 * @return array
	 */
	public static function merge_daily( array $a, array $b ) {
		foreach ( $b as $day => $row ) {
			if ( ! isset( $a[ $day ] ) ) {
				$a[ $day ] = $row;
				continue;
			}
			foreach ( $row as $f => $v ) {
				$a[ $day ][ $f ] += $v;
			}
		}
		ksort( $a );
		return $a;
	}
}

if ( ! function_exists( 'cts_strip_tags' ) ) {
	/**
	 * Strip tags without depending on WordPress being loaded.
	 *
	 * @param string $s HTML.
	 * @return string
	 */
	function cts_strip_tags( $s ) {
		return html_entity_decode( strip_tags( (string) $s ), ENT_QUOTES, 'UTF-8' );
	}
}
