<?php
/**
 * Builds a traffic report for any date range, for downloads and emails.
 *
 * AWStats and Webalizer both store their data per month with a daily
 * breakdown, so totals for any range are summed from the daily rows. The
 * ranked lists (top pages, referrers, ...) only exist per month, so a report
 * uses the lists of every month the range touches and says so.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report builder.
 */
class CTS_Report {

	/**
	 * Report periods that can be chosen for downloads and schedules.
	 *
	 * @return array key => label
	 */
	public static function periods() {
		return array(
			'yesterday'     => __( 'Yesterday', 'cpanel-traffic-stats' ),
			'last_7_days'   => __( 'Last 7 days', 'cpanel-traffic-stats' ),
			'last_week'     => __( 'Last week (full week)', 'cpanel-traffic-stats' ),
			'last_30_days'  => __( 'Last 30 days', 'cpanel-traffic-stats' ),
			'month_to_date' => __( 'This month so far', 'cpanel-traffic-stats' ),
			'last_month'    => __( 'Last month (full month)', 'cpanel-traffic-stats' ),
		);
	}

	/**
	 * Date range (inclusive, site time zone) for a period.
	 *
	 * @param string                  $period Period key, "month:YYYY-MM" or "custom".
	 * @param DateTimeImmutable|null  $now    Now (defaults to current time).
	 * @param string                  $from   Custom start Y-m-d.
	 * @param string                  $to     Custom end Y-m-d.
	 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}|WP_Error
	 */
	public static function range( $period, $now = null, $from = '', $to = '' ) {
		$tz    = wp_timezone();
		$now   = $now ? $now : new DateTimeImmutable( 'now', $tz );
		$today = $now->setTime( 0, 0 );

		if ( preg_match( '/^month:(\d{4})-(\d{2})$/', (string) $period, $m ) ) {
			$start = $today->setDate( (int) $m[1], (int) $m[2], 1 );
			return array( $start, $start->modify( 'last day of this month' ) );
		}

		switch ( $period ) {
			case 'yesterday':
				$d = $today->modify( '-1 day' );
				return array( $d, $d );
			case 'last_7_days':
				return array( $today->modify( '-7 days' ), $today->modify( '-1 day' ) );
			case 'last_30_days':
				return array( $today->modify( '-30 days' ), $today->modify( '-1 day' ) );
			case 'last_week':
				$week_start = (int) get_option( 'start_of_week', 1 );
				$back       = ( (int) $today->format( 'w' ) - $week_start + 7 ) % 7;
				$this_week  = $today->modify( '-' . $back . ' days' );
				return array( $this_week->modify( '-7 days' ), $this_week->modify( '-1 day' ) );
			case 'month_to_date':
				return array( $today->modify( 'first day of this month' ), $today );
			case 'last_month':
				$first = $today->modify( 'first day of last month' );
				return array( $first, $first->modify( 'last day of this month' ) );
			case 'custom':
				$a = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $from, $tz );
				$b = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $to, $tz );
				if ( ! $a || ! $b ) {
					return new WP_Error( 'cts_range', __( 'Choose a start and end date.', 'cpanel-traffic-stats' ) );
				}
				if ( $a > $b ) {
					list( $a, $b ) = array( $b, $a );
				}
				if ( $a->diff( $b )->days > 400 ) {
					return new WP_Error( 'cts_range', __( 'Choose a range of 13 months or less.', 'cpanel-traffic-stats' ) );
				}
				return array( $a, $b );
		}
		return new WP_Error( 'cts_range', __( 'Unknown report period.', 'cpanel-traffic-stats' ) );
	}

	/**
	 * Build the report.
	 *
	 * @param CTS_Stats         $stats Stats service.
	 * @param DateTimeImmutable $start First day (inclusive).
	 * @param DateTimeImmutable $end   Last day (inclusive).
	 * @return array|WP_Error
	 */
	public static function build( CTS_Stats $stats, DateTimeImmutable $start, DateTimeImmutable $end ) {
		$settings = $stats->settings();
		$engine   = $stats->uses( 'awstats' ) ? 'awstats' : 'webalizer';

		$current = self::collect( $stats, $engine, $start, $end, true );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$length     = (int) $start->diff( $end )->days + 1;
		$prev_end   = $start->modify( '-1 day' );
		$prev_start = $prev_end->modify( '-' . ( $length - 1 ) . ' days' );
		$full_month = self::is_full_month( $start, $end );
		if ( $full_month ) {
			// Compare a calendar month with the calendar month before it.
			$prev_start = $start->modify( 'first day of last month' );
			$prev_end   = $prev_start->modify( 'last day of this month' );
		}
		// A month still in progress is compared with the same days of the month before.
		$partial = false;
		if ( $full_month && $current['days'] ) {
			$through = (int) substr( max( array_keys( $current['days'] ) ), 8, 2 );
			if ( $through < (int) $end->format( 'j' ) ) {
				$partial  = true;
				$prev_end = min( $prev_end, $prev_start->modify( '+' . ( $through - 1 ) . ' days' ) );
			}
		}
		$previous = self::collect( $stats, $engine, $prev_start, $prev_end, false );
		$previous = ( is_wp_error( $previous ) || empty( $previous['days'] ) ) ? null : $previous;

		$summary = self::sum_days( $current['days'] );
		$prev    = $previous ? self::sum_days( $previous['days'] ) : null;

		// Unique visitors only exist per month in AWStats.
		$summary['unique'] = ( $full_month && 'awstats' === $engine ) ? $current['unique'] : null;
		if ( $prev ) {
			$prev['unique'] = ( $full_month && ! $partial && 'awstats' === $engine && $previous ) ? $previous['unique'] : null;
		}

		$months_note = '';
		if ( 'awstats' === $engine && ! empty( $current['months'] ) && ! $full_month ) {
			$labels      = array_map( array( 'CTS_Admin', 'month_label' ), $current['months'] );
			$months_note = sprintf(
				/* translators: %s: list of months */
				__( 'Top lists cover all of %s, because AWStats only keeps them per month.', 'cpanel-traffic-stats' ),
				implode( ', ', $labels )
			);
		}

		$data_through = $current['days'] ? max( array_keys( $current['days'] ) ) : '';

		return array(
			'domain'       => $settings['domain'],
			'engine'       => $engine,
			'start'        => $start->format( 'Y-m-d' ),
			'end'          => $end->format( 'Y-m-d' ),
			'label'        => self::range_label( $start, $end ),
			'summary'      => $summary,
			'previous'     => $prev,
			'previous_label' => $previous ? self::range_label( $prev_start, $prev_end ) : '',
			'days'         => self::fill_days( $current['days'], $start, $end ),
			'tables'       => $current['tables'],
			'tables_note'  => $months_note,
			'data_through' => $data_through,
			'generated'    => wp_date( 'Y-m-d H:i' ),
		);
	}

	/**
	 * Collect daily rows (and, for the main range, ranked tables) for a range.
	 *
	 * @param CTS_Stats         $stats       Stats.
	 * @param string            $engine      awstats|webalizer.
	 * @param DateTimeImmutable $start       Start.
	 * @param DateTimeImmutable $end         End.
	 * @param bool              $with_tables Whether to merge ranked tables.
	 * @return array|WP_Error
	 */
	private static function collect( CTS_Stats $stats, $engine, DateTimeImmutable $start, DateTimeImmutable $end, $with_tables ) {
		$out = array(
			'days'   => array(),
			'tables' => array(),
			'unique' => 0,
			'months' => array(),
		);
		$from = $start->format( 'Ymd' );
		$to   = $end->format( 'Ymd' );

		if ( 'awstats' === $engine ) {
			$available = $stats->awstats_months();
			if ( is_wp_error( $available ) ) {
				return $available;
			}
		}

		$merged = null;
		foreach ( self::months_between( $start, $end ) as $ym ) {
			if ( 'awstats' === $engine ) {
				if ( empty( $available[ $ym ] ) ) {
					continue;
				}
				$data = $stats->awstats( $ym );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				if ( ! $data ) {
					continue;
				}
				$out['months'][] = $ym;
				$out['unique']  += $data['summary']['unique'];
				foreach ( $data['days'] as $date => $row ) {
					$date = (string) $date;
					if ( $date >= $from && $date <= $to ) {
						$out['days'][ self::dash( $date ) ] = array(
							'visits'    => $row['visits'],
							'pages'     => $row['pages'],
							'hits'      => $row['hits'],
							'bandwidth' => $row['bandwidth'],
						);
					}
				}
				if ( $with_tables ) {
					$tables = array_diff_key( $data, array_flip( array( 'summary', 'days', 'hours' ) ) );
					$merged = null === $merged ? $tables : CTS_AWStats_Parser::merge( $merged, $tables );
				}
			} else {
				$days = $stats->webalizer_daily( $ym );
				if ( is_wp_error( $days ) ) {
					return $days;
				}
				foreach ( $days as $day => $row ) {
					$date = str_replace( '-', '', $ym ) . sprintf( '%02d', $day );
					if ( $date >= $from && $date <= $to ) {
						$out['days'][ self::dash( $date ) ] = array(
							'visits'    => $row['visits'],
							'pages'     => $row['pages'],
							'hits'      => $row['hits'],
							'bandwidth' => $row['kbytes'] * 1024,
						);
					}
				}
			}
		}

		if ( $merged ) {
			$out['tables'] = CTS_Stats::trim_tables( $merged );
		}
		ksort( $out['days'] );
		return $out;
	}

	/**
	 * Sum daily rows.
	 *
	 * @param array $days Days.
	 * @return array
	 */
	private static function sum_days( array $days ) {
		$sum = array(
			'visits'    => 0,
			'pages'     => 0,
			'hits'      => 0,
			'bandwidth' => 0,
		);
		foreach ( $days as $row ) {
			foreach ( $sum as $k => $v ) {
				$sum[ $k ] += $row[ $k ];
			}
		}
		return $sum;
	}

	/**
	 * Every date in the range, with zero rows where there is no data.
	 *
	 * @param array             $days  Days with data.
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End.
	 * @return array
	 */
	private static function fill_days( array $days, DateTimeImmutable $start, DateTimeImmutable $end ) {
		$out = array();
		for ( $d = $start; $d <= $end; $d = $d->modify( '+1 day' ) ) {
			$key         = $d->format( 'Y-m-d' );
			$out[ $key ] = isset( $days[ $key ] ) ? $days[ $key ] : array(
				'visits'    => 0,
				'pages'     => 0,
				'hits'      => 0,
				'bandwidth' => 0,
			);
		}
		return $out;
	}

	/**
	 * "YYYY-MM" months touched by a range.
	 *
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End.
	 * @return string[]
	 */
	private static function months_between( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$months = array();
		$m      = $start->modify( 'first day of this month' );
		while ( $m <= $end ) {
			$months[] = $m->format( 'Y-m' );
			$m        = $m->modify( 'first day of next month' );
		}
		return $months;
	}

	/**
	 * Whether a range is exactly one calendar month.
	 *
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End.
	 * @return bool
	 */
	public static function is_full_month( DateTimeImmutable $start, DateTimeImmutable $end ) {
		return '01' === $start->format( 'd' )
			&& $start->format( 'Y-m' ) === $end->format( 'Y-m' )
			&& $end->format( 'd' ) === $end->format( 't' );
	}

	/**
	 * Human label for a range.
	 *
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End.
	 * @return string
	 */
	public static function range_label( DateTimeImmutable $start, DateTimeImmutable $end ) {
		if ( self::is_full_month( $start, $end ) ) {
			return wp_date( 'F Y', $start->getTimestamp(), $start->getTimezone() );
		}
		if ( $start->format( 'Y-m-d' ) === $end->format( 'Y-m-d' ) ) {
			return wp_date( 'l, F j, Y', $start->getTimestamp(), $start->getTimezone() );
		}
		$same_year = $start->format( 'Y' ) === $end->format( 'Y' );
		return wp_date( $same_year ? 'M j' : 'M j, Y', $start->getTimestamp(), $start->getTimezone() )
			. ' – ' . wp_date( 'M j, Y', $end->getTimestamp(), $end->getTimezone() );
	}

	/**
	 * "20261105" to "2026-11-05".
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	private static function dash( $ymd ) {
		return substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 );
	}

	/**
	 * Percent change, or null when there is nothing to compare with.
	 *
	 * @param int|float|null $now  Current.
	 * @param int|float|null $then Previous.
	 * @return float|null
	 */
	public static function change( $now, $then ) {
		if ( null === $now || null === $then || $then <= 0 ) {
			return null;
		}
		return ( $now - $then ) / $then * 100;
	}

	/**
	 * Ranked tables to include in exports, with titles and columns.
	 *
	 * @return array key => [title, label column, [metric => header]]
	 */
	public static function table_specs() {
		return array(
			'pages'          => array( __( 'Top pages', 'cpanel-traffic-stats' ), __( 'Page', 'cpanel-traffic-stats' ), array( 'pages' => __( 'Views', 'cpanel-traffic-stats' ), 'entry' => __( 'Entries', 'cpanel-traffic-stats' ) ) ),
			'referrers'      => array( __( 'Referring sites', 'cpanel-traffic-stats' ), __( 'Referrer', 'cpanel-traffic-stats' ), array( 'pages' => __( 'Pages', 'cpanel-traffic-stats' ) ) ),
			'search_engines' => array( __( 'Search engines', 'cpanel-traffic-stats' ), __( 'Search engine', 'cpanel-traffic-stats' ), array( 'pages' => __( 'Pages', 'cpanel-traffic-stats' ) ) ),
			'keyphrases'     => array( __( 'Search phrases', 'cpanel-traffic-stats' ), __( 'Phrase', 'cpanel-traffic-stats' ), array( 'count' => __( 'Searches', 'cpanel-traffic-stats' ) ) ),
			'countries'      => array( __( 'Countries', 'cpanel-traffic-stats' ), __( 'Country', 'cpanel-traffic-stats' ), array( 'pages' => __( 'Pages', 'cpanel-traffic-stats' ) ) ),
			'browsers'       => array( __( 'Browsers', 'cpanel-traffic-stats' ), __( 'Browser', 'cpanel-traffic-stats' ), array( 'hits' => __( 'Hits', 'cpanel-traffic-stats' ) ) ),
			'os'             => array( __( 'Operating systems', 'cpanel-traffic-stats' ), __( 'System', 'cpanel-traffic-stats' ), array( 'hits' => __( 'Hits', 'cpanel-traffic-stats' ) ) ),
			'not_found'      => array( __( 'Pages not found (404)', 'cpanel-traffic-stats' ), __( 'URL', 'cpanel-traffic-stats' ), array( 'hits' => __( 'Hits', 'cpanel-traffic-stats' ) ) ),
		);
	}

	/**
	 * Rows of a ranked table, sorted, with display labels.
	 *
	 * @param array  $report Report.
	 * @param string $key    Table key.
	 * @param int    $limit  Max rows.
	 * @return array label => metrics
	 */
	public static function table_rows( array $report, $key, $limit ) {
		if ( empty( $report['tables'][ $key ] ) ) {
			return array();
		}
		$specs  = self::table_specs();
		$metric = array_keys( $specs[ $key ][2] )[0];
		$rows   = $report['tables'][ $key ];
		uasort(
			$rows,
			static function ( $a, $b ) use ( $metric ) {
				return ( $b[ $metric ] ?? 0 ) <=> ( $a[ $metric ] ?? 0 );
			}
		);
		$rows = array_slice( $rows, 0, $limit, true );
		if ( 'countries' === $key ) {
			$labelled = array();
			foreach ( $rows as $code => $row ) {
				$labelled[ CTS_Admin::country_label( $code ) ] = $row;
			}
			$rows = $labelled;
		}
		return $rows;
	}
}
