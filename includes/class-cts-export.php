<?php
/**
 * Turns a report (see CTS_Report::build) into CSV, PDF or an HTML email.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporters.
 */
class CTS_Export {

	const ACCENT = array( 34, 113, 177 );
	const INK    = array( 29, 35, 39 );
	const MUTED  = array( 100, 105, 112 );
	const LINE   = array( 220, 220, 222 );
	const SOFT   = array( 222, 236, 247 );
	const UP     = array( 0, 128, 40 );
	const DOWN   = array( 200, 40, 45 );

	/**
	 * File name for a report.
	 *
	 * @param array  $report Report.
	 * @param string $ext    Extension.
	 * @return string
	 */
	public static function filename( array $report, $ext ) {
		$name = 'traffic-' . $report['domain'] . '-' . $report['start'];
		if ( $report['end'] !== $report['start'] ) {
			$name .= '-to-' . $report['end'];
		}
		return sanitize_file_name( $name . '.' . $ext );
	}

	/**
	 * Summary metrics shown in every format.
	 *
	 * @param array $report Report.
	 * @return array key => [label, is_bytes]
	 */
	public static function metrics( array $report ) {
		$m = array();
		if ( null !== $report['summary']['unique'] ) {
			$m['unique'] = array( __( 'Unique visitors', 'cpanel-traffic-stats' ), false );
		}
		$m['visits']    = array( __( 'Visits', 'cpanel-traffic-stats' ), false );
		$m['pages']     = array( __( 'Page views', 'cpanel-traffic-stats' ), false );
		$m['hits']      = array( __( 'Hits', 'cpanel-traffic-stats' ), false );
		$m['bandwidth'] = array( __( 'Bandwidth', 'cpanel-traffic-stats' ), true );
		return $m;
	}

	/* ---------------------------------------------------------------------
	 * CSV
	 * ------------------------------------------------------------------- */

	/**
	 * CSV export (UTF-8 with BOM so Excel opens it correctly).
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function csv( array $report ) {
		$fh = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		$put = static function ( array $row ) use ( $fh ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'csv_cell' ), $row ), ',', '"', '\\' );
		};

		$put( array( __( 'Traffic report', 'cpanel-traffic-stats' ), $report['domain'] ) );
		$put( array( __( 'Period', 'cpanel-traffic-stats' ), $report['start'], $report['end'] ) );
		$put( array( __( 'Source', 'cpanel-traffic-stats' ), 'awstats' === $report['engine'] ? 'AWStats' : 'Webalizer' ) );
		$put( array( __( 'Generated', 'cpanel-traffic-stats' ), $report['generated'] ) );
		$put( array() );

		$put( array( __( 'Summary', 'cpanel-traffic-stats' ) ) );
		$put( array( __( 'Metric', 'cpanel-traffic-stats' ), __( 'This period', 'cpanel-traffic-stats' ), __( 'Previous period', 'cpanel-traffic-stats' ), __( 'Change %', 'cpanel-traffic-stats' ) ) );
		foreach ( self::metrics( $report ) as $key => $meta ) {
			$now    = $report['summary'][ $key ];
			$then   = $report['previous'] ? $report['previous'][ $key ] : null;
			$change = CTS_Report::change( $now, $then );
			$label  = $meta[1] ? $meta[0] . ' (bytes)' : $meta[0];
			$put( array( $label, $now, null === $then ? '' : $then, null === $change ? '' : round( $change, 1 ) ) );
		}
		$put( array() );

		$put( array( __( 'Daily', 'cpanel-traffic-stats' ) ) );
		$put( array( __( 'Date', 'cpanel-traffic-stats' ), __( 'Visits', 'cpanel-traffic-stats' ), __( 'Page views', 'cpanel-traffic-stats' ), __( 'Hits', 'cpanel-traffic-stats' ), __( 'Bandwidth (bytes)', 'cpanel-traffic-stats' ) ) );
		foreach ( $report['days'] as $date => $row ) {
			$put( array( $date, $row['visits'], $row['pages'], $row['hits'], round( $row['bandwidth'] ) ) );
		}

		if ( $report['tables'] ) {
			if ( $report['tables_note'] ) {
				$put( array() );
				$put( array( $report['tables_note'] ) );
			}
			foreach ( CTS_Report::table_specs() as $key => $spec ) {
				$rows = CTS_Report::table_rows( $report, $key, 100 );
				if ( ! $rows ) {
					continue;
				}
				$put( array() );
				$put( array( $spec[0] ) );
				$put( array_merge( array( $spec[1] ), array_values( $spec[2] ) ) );
				foreach ( $rows as $label => $r ) {
					$line = array( (string) $label );
					foreach ( array_keys( $spec[2] ) as $metric ) {
						$line[] = $r[ $metric ] ?? 0;
					}
					$put( $line );
				}
			}
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Neutralise spreadsheet formulas in text that came from visitors
	 * (referrers, search phrases, URLs).
	 *
	 * @param mixed $v Cell.
	 * @return mixed
	 */
	public static function csv_cell( $v ) {
		if ( is_string( $v ) && '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $v ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/* ---------------------------------------------------------------------
	 * PDF
	 * ------------------------------------------------------------------- */

	/**
	 * PDF export.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function pdf( array $report ) {
		$title = sprintf( /* translators: %s: domain */ __( 'Traffic report for %s', 'cpanel-traffic-stats' ), $report['domain'] );
		$pdf   = new CTS_PDF( $title );
		$left  = 40;
		$right = CTS_PDF::W - 40;
		$width = $right - $left;

		self::pdf_page( $pdf, $report );

		// Header.
		$pdf->rect( 0, 0, CTS_PDF::W, 6, self::ACCENT );
		$pdf->text( $left, 52, __( 'Traffic report', 'cpanel-traffic-stats' ), 22, true );
		$pdf->text( $left, 72, $report['domain'] . '  ·  ' . $report['label'], 11, false, self::MUTED );
		$pdf->text( $right, 52, 'awstats' === $report['engine'] ? 'AWStats' : 'Webalizer', 9, true, self::ACCENT, 'right' );

		// Summary cards.
		$metrics = self::metrics( $report );
		$gap     = 10;
		$card_w  = ( $width - $gap * ( count( $metrics ) - 1 ) ) / count( $metrics );
		$y       = 92;
		$x       = $left;
		foreach ( $metrics as $key => $meta ) {
			$now    = $report['summary'][ $key ];
			$then   = $report['previous'] ? $report['previous'][ $key ] : null;
			$change = CTS_Report::change( $now, $then );
			$pdf->box( $x, $y, $card_w, 62, self::LINE );
			$pdf->text( $x + 10, $y + 16, strtoupper( $meta[0] ), 7.5, false, self::MUTED );
			$pdf->text( $x + 10, $y + 38, $meta[1] ? size_format( $now, 1 ) : number_format_i18n( $now ), 16, true );
			if ( null !== $change ) {
				$pdf->text(
					$x + 10,
					$y + 53,
					( $change >= 0 ? '+' : '-' ) . number_format_i18n( abs( $change ), 0 ) . '%',
					8.5,
					true,
					$change >= 0 ? self::UP : self::DOWN
				);
			}
			$x += $card_w + $gap;
		}
		$y += 78;

		$notes = array();
		if ( $report['previous'] ) {
			/* translators: %s: date range */
			$notes[] = sprintf( __( 'Changes compare with %s.', 'cpanel-traffic-stats' ), $report['previous_label'] );
		}
		if ( $report['data_through'] && $report['data_through'] < $report['end'] ) {
			/* translators: %s: date */
			$notes[] = sprintf( __( 'Stats are available through %s.', 'cpanel-traffic-stats' ), self::date_label( $report['data_through'] ) );
		} elseif ( ! $report['data_through'] ) {
			$notes[] = __( 'No traffic data was found for this period.', 'cpanel-traffic-stats' );
		}
		if ( $notes ) {
			$pdf->text( $left, $y, implode( ' ', $notes ), 8.5, false, self::MUTED );
			$y += 22;
		}

		// Daily chart.
		if ( count( $report['days'] ) > 1 ) {
			$pdf->text( $left, $y, __( 'Visits per day', 'cpanel-traffic-stats' ), 12, true );
			$y      += 12;
			$chart_h = 130;
			$values  = array_column( $report['days'], 'visits' );
			$dates   = array_keys( $report['days'] );
			$max     = max( 1, max( $values ) );
			$n       = count( $values );
			$slot    = $width / $n;
			$bar     = max( 1, $slot * 0.7 );
			$every   = (int) max( 1, ceil( $n / 15 ) );
			$base    = $y + $chart_h;
			$pdf->line( $left, $y, $right, $y, self::LINE, 0.4 );
			$pdf->line( $left, $y + $chart_h / 2, $right, $y + $chart_h / 2, self::LINE, 0.4 );
			$pdf->text( $right, $y - 3, number_format_i18n( $max ), 7, false, self::MUTED, 'right' );
			foreach ( $values as $i => $v ) {
				$h  = $chart_h * $v / $max;
				$bx = $left + $i * $slot + ( $slot - $bar ) / 2;
				if ( $h > 0 ) {
					$pdf->rect( $bx, $base - $h, $bar, $h, self::ACCENT );
				}
				if ( 0 === $i % $every ) {
					$label = $n > 31 ? gmdate( 'n/j', strtotime( $dates[ $i ] ) ) : gmdate( 'j', strtotime( $dates[ $i ] ) );
					$pdf->text( $bx + $bar / 2, $base + 11, $label, 7, false, self::MUTED, 'center' );
				}
			}
			$pdf->line( $left, $base, $right, $base, self::MUTED, 0.5 );
			$y = $base + 34;
		}

		// Ranked tables, two per row.
		if ( $report['tables'] ) {
			if ( $report['tables_note'] ) {
				$pdf->text( $left, $y - 8, $report['tables_note'], 8.5, false, self::MUTED );
				$y += 8;
			}
			$col_w  = ( $width - 24 ) / 2;
			$col    = 0;
			$row_y  = $y;
			$tallest = 0;
			foreach ( CTS_Report::table_specs() as $key => $spec ) {
				$rows = CTS_Report::table_rows( $report, $key, 10 );
				if ( ! $rows ) {
					continue;
				}
				$height = 36 + count( $rows ) * 15;
				if ( 0 === $col && $row_y + $height > CTS_PDF::H - 50 ) {
					self::pdf_page( $pdf, $report );
					$row_y = 50;
				}
				$x       = $left + $col * ( $col_w + 24 );
				$tallest = max( $tallest, $height );
				self::pdf_table( $pdf, $x, $row_y, $col_w, $spec, $rows );
				$col = 1 - $col;
				if ( 0 === $col ) {
					$row_y  += $tallest + 18;
					$tallest = 0;
				}
			}
		}

		return $pdf->output();
	}

	/**
	 * Start a PDF page with a footer.
	 *
	 * @param CTS_PDF $pdf    PDF.
	 * @param array   $report Report.
	 */
	private static function pdf_page( CTS_PDF $pdf, array $report ) {
		$pdf->add_page();
		$footer = sprintf(
			/* translators: 1: domain, 2: date generated, 3: page number */
			__( '%1$s traffic report  ·  generated %2$s  ·  page %3$d', 'cpanel-traffic-stats' ),
			$report['domain'],
			$report['generated'],
			$pdf->page_count()
		);
		$pdf->text( CTS_PDF::W / 2, CTS_PDF::H - 24, $footer, 7.5, false, self::MUTED, 'center' );
	}

	/**
	 * Draw one ranked table.
	 *
	 * @param CTS_PDF $pdf  PDF.
	 * @param float   $x    Left.
	 * @param float   $y    Top.
	 * @param float   $w    Width.
	 * @param array   $spec [title, label header, metrics].
	 * @param array   $rows Rows.
	 */
	private static function pdf_table( CTS_PDF $pdf, $x, $y, $w, array $spec, array $rows ) {
		$metrics = $spec[2];
		$num_w   = 58;
		$label_w = $w - $num_w * count( $metrics );
		$first   = array_keys( $metrics )[0];
		$max     = max( 1, max( array_map( static function ( $r ) use ( $first ) { return $r[ $first ] ?? 0; }, $rows ) ) );

		$pdf->text( $x, $y + 12, $spec[0], 11, true );
		$hy = $y + 28;
		$pdf->text( $x, $hy, $spec[1], 7.5, true, self::MUTED );
		$cx = $x + $label_w;
		foreach ( $metrics as $header ) {
			$cx += $num_w;
			$pdf->text( $cx, $hy, $header, 7.5, true, self::MUTED, 'right' );
		}
		$pdf->line( $x, $hy + 4, $x + $w, $hy + 4, self::LINE );

		$ry = $hy + 4;
		foreach ( $rows as $label => $r ) {
			$share = ( $r[ $first ] ?? 0 ) / $max;
			$pdf->rect( $x, $ry + 2.5, ( $label_w - 8 ) * $share, 11, self::SOFT );
			$pdf->text( $x + 3, $ry + 11, $pdf->fit( (string) $label, $label_w - 12, 8.5 ), 8.5 );
			$cx = $x + $label_w;
			foreach ( array_keys( $metrics ) as $m ) {
				$cx += $num_w;
				$pdf->text( $cx, $ry + 11, number_format_i18n( $r[ $m ] ?? 0 ), 8.5, false, self::INK, 'right' );
			}
			$ry += 15;
		}
	}

	/* ---------------------------------------------------------------------
	 * Email
	 * ------------------------------------------------------------------- */

	/**
	 * HTML email body with the summary and the top lists.
	 *
	 * @param array  $report Report.
	 * @param string $name   Schedule name.
	 * @return string
	 */
	public static function email_html( array $report, $name = '' ) {
		$cell  = 'padding:8px 10px;border-bottom:1px solid #e2e4e7;';
		$html  = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d2327;max-width:640px;margin:0 auto;">';
		$html .= '<div style="height:4px;background:#2271b1;"></div>';
		$html .= '<h1 style="font-size:22px;margin:20px 0 4px;">' . esc_html__( 'Traffic report', 'cpanel-traffic-stats' ) . '</h1>';
		$html .= '<p style="margin:0 0 20px;color:#646970;">' . esc_html( $report['domain'] . ' · ' . $report['label'] ) . ( $name ? ' · ' . esc_html( $name ) : '' ) . '</p>';

		$html .= '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;font-size:14px;">';
		$html .= '<tr><th align="left" style="' . $cell . 'color:#646970;font-weight:600;"></th><th align="right" style="' . $cell . 'color:#646970;font-weight:600;">' . esc_html__( 'This period', 'cpanel-traffic-stats' ) . '</th><th align="right" style="' . $cell . 'color:#646970;font-weight:600;">' . esc_html__( 'Change', 'cpanel-traffic-stats' ) . '</th></tr>';
		foreach ( self::metrics( $report ) as $key => $meta ) {
			$now    = $report['summary'][ $key ];
			$then   = $report['previous'] ? $report['previous'][ $key ] : null;
			$change = CTS_Report::change( $now, $then );
			$chg    = '';
			if ( null !== $change ) {
				$chg = '<span style="color:' . ( $change >= 0 ? '#008a20' : '#c8282d' ) . ';">' . ( $change >= 0 ? '&#9650; ' : '&#9660; ' ) . esc_html( number_format_i18n( abs( $change ), 0 ) ) . '%</span>';
			}
			$html .= '<tr><td style="' . $cell . '">' . esc_html( $meta[0] ) . '</td><td align="right" style="' . $cell . 'font-weight:600;">' . esc_html( $meta[1] ? size_format( $now, 1 ) : number_format_i18n( $now ) ) . '</td><td align="right" style="' . $cell . '">' . $chg . '</td></tr>';
		}
		$html .= '</table>';

		if ( $report['previous'] ) {
			/* translators: %s: date range */
			$html .= '<p style="font-size:12px;color:#646970;">' . esc_html( sprintf( __( 'Changes compare with %s.', 'cpanel-traffic-stats' ), $report['previous_label'] ) ) . '</p>';
		}
		if ( ! $report['data_through'] ) {
			$html .= '<p style="font-size:13px;color:#c8282d;">' . esc_html__( 'No traffic data was found for this period. cPanel may not have processed the logs yet.', 'cpanel-traffic-stats' ) . '</p>';
		}

		foreach ( array( 'pages', 'referrers', 'search_engines' ) as $key ) {
			$rows = CTS_Report::table_rows( $report, $key, 5 );
			if ( ! $rows ) {
				continue;
			}
			$spec   = CTS_Report::table_specs()[ $key ];
			$metric = array_keys( $spec[2] )[0];
			$html  .= '<h2 style="font-size:15px;margin:24px 0 6px;">' . esc_html( $spec[0] ) . '</h2>';
			$html  .= '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;font-size:13px;">';
			foreach ( $rows as $label => $r ) {
				$html .= '<tr><td style="' . $cell . 'word-break:break-all;">' . esc_html( (string) $label ) . '</td><td align="right" style="' . $cell . 'width:80px;">' . esc_html( number_format_i18n( $r[ $metric ] ?? 0 ) ) . '</td></tr>';
			}
			$html .= '</table>';
		}
		if ( $report['tables_note'] ) {
			$html .= '<p style="font-size:12px;color:#646970;">' . esc_html( $report['tables_note'] ) . '</p>';
		}

		$html .= '<p style="margin:28px 0 8px;"><a href="' . esc_url( admin_url( 'index.php?page=' . CTS_Admin::STATS_PAGE ) ) . '" style="background:#2271b1;color:#fff;padding:9px 16px;border-radius:4px;text-decoration:none;display:inline-block;">' . esc_html__( 'View the full report', 'cpanel-traffic-stats' ) . '</a></p>';
		$html .= '<p style="font-size:11px;color:#8c8f94;margin-top:24px;">' . esc_html(
			sprintf(
				/* translators: %s: site name */
				__( 'Sent by the cPanel Traffic Stats plugin on %s. Change or stop this email under Dashboard > Email Reports.', 'cpanel-traffic-stats' ),
				get_bloginfo( 'name' )
			)
		) . '</p>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * "Nov 19, 2026" from "2026-11-19".
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	private static function date_label( $ymd ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		return $d ? wp_date( 'M j, Y', $d->getTimestamp() ) : $ymd;
	}
}
