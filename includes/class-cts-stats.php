<?php
/**
 * Loads, merges and caches stats from the configured source.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stats service.
 */
class CTS_Stats {

	const GEN_OPTION = 'cts_cache_gen';
	const TOP_N      = 100;

	/**
	 * Settings.
	 *
	 * @var array
	 */
	private $s;

	/**
	 * Source.
	 *
	 * @var CTS_Source
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param array|null $settings Settings; defaults to saved settings.
	 */
	public function __construct( $settings = null ) {
		$this->s      = null === $settings ? CTS_Settings::get() : $settings;
		$this->source = new CTS_Source( $this->s );
	}

	/**
	 * The source.
	 *
	 * @return CTS_Source
	 */
	public function source() {
		return $this->source;
	}

	/**
	 * Settings in use.
	 *
	 * @return array
	 */
	public function settings() {
		return $this->s;
	}

	/**
	 * Invalidate every cached result.
	 */
	public static function clear_cache() {
		update_option( self::GEN_OPTION, (int) get_option( self::GEN_OPTION, 0 ) + 1, false );
	}

	/**
	 * Whether AWStats / Webalizer are enabled in the settings.
	 *
	 * @param string $engine awstats|webalizer.
	 * @return bool
	 */
	public function uses( $engine ) {
		return $this->s['engine'] === $engine || 'both' === $this->s['engine'];
	}

	/**
	 * AWStats folders for the selected protocol(s).
	 *
	 * @return string[]
	 */
	private function awstats_dirs() {
		$dirs = array();
		if ( 'https' !== $this->s['protocol'] ) {
			$dirs[] = 'tmp/awstats';
		}
		if ( 'http' !== $this->s['protocol'] ) {
			$dirs[] = 'tmp/awstats/ssl';
		}
		return $dirs;
	}

	/**
	 * Webalizer folders for the selected protocol(s).
	 *
	 * @return string[]
	 */
	private function webalizer_dirs() {
		$dirs = array();
		if ( 'https' !== $this->s['protocol'] ) {
			$dirs[] = 'tmp/webalizer';
		}
		if ( 'http' !== $this->s['protocol'] ) {
			$dirs[] = 'tmp/webalizerssl';
		}
		return $dirs;
	}

	/**
	 * Months that have AWStats data, newest first.
	 *
	 * @return array|WP_Error List of "YYYY-MM" => [ dir, dir ... ].
	 */
	public function awstats_months() {
		return $this->cached(
			'aw_months',
			function () {
				$ready = $this->source->ready();
				if ( is_wp_error( $ready ) ) {
					return $ready;
				}
				$months = array();
				$suffix = '.' . $this->s['domain'] . '.txt';
				foreach ( $this->awstats_dirs() as $dir ) {
					$files = $this->source->list_files( $dir );
					if ( is_wp_error( $files ) ) {
						return $files;
					}
					foreach ( $files as $f ) {
						if ( substr( $f, -strlen( $suffix ) ) !== $suffix ) {
							continue;
						}
						$period = CTS_AWStats_Parser::period_from_filename( $f );
						if ( $period ) {
							$months[ sprintf( '%04d-%02d', $period[0], $period[1] ) ][] = $dir;
						}
					}
				}
				krsort( $months );
				return $months;
			}
		);
	}

	/**
	 * AWStats domains found on the account (to help with setup).
	 *
	 * @return string[]
	 */
	public function awstats_domains() {
		$domains = array();
		foreach ( array( 'tmp/awstats', 'tmp/awstats/ssl' ) as $dir ) {
			$files = $this->source->list_files( $dir );
			if ( is_wp_error( $files ) ) {
				continue;
			}
			foreach ( $files as $f ) {
				if ( preg_match( '/^awstats\d{6}\.(.+)\.txt$/', $f, $m ) ) {
					$domains[ $m[1] ] = true;
				}
			}
		}
		return array_keys( $domains );
	}

	/**
	 * Stats for one month from AWStats.
	 *
	 * @param string $ym "YYYY-MM".
	 * @return array|WP_Error|null Null when there is no data for that month.
	 */
	public function awstats( $ym ) {
		return $this->cached(
			'aw_' . $ym,
			function () use ( $ym ) {
				$months = $this->awstats_months();
				if ( is_wp_error( $months ) ) {
					return $months;
				}
				if ( empty( $months[ $ym ] ) ) {
					return null;
				}
				list( $y, $m ) = array_map( 'intval', explode( '-', $ym ) );
				$file          = CTS_AWStats_Parser::filename( $y, $m, $this->s['domain'] );
				$stats         = null;
				foreach ( $months[ $ym ] as $dir ) {
					$content = $this->source->get_file( $dir, $file );
					if ( is_wp_error( $content ) ) {
						return $content;
					}
					$parsed = CTS_AWStats_Parser::parse( $content );
					$stats  = null === $stats ? $parsed : CTS_AWStats_Parser::merge( $stats, $parsed );
				}
				return self::trim_tables( $stats );
			}
		);
	}

	/**
	 * Webalizer monthly history, newest first.
	 *
	 * @return array|WP_Error
	 */
	public function webalizer_history() {
		return $this->cached(
			'wb_hist',
			function () {
				$ready = $this->source->ready();
				if ( is_wp_error( $ready ) ) {
					return $ready;
				}
				$history = array();
				foreach ( $this->webalizer_dirs() as $dir ) {
					$files = $this->source->list_files( $dir );
					if ( is_wp_error( $files ) ) {
						return $files;
					}
					if ( ! in_array( 'webalizer.hist', $files, true ) ) {
						continue;
					}
					$content = $this->source->get_file( $dir, 'webalizer.hist' );
					if ( is_wp_error( $content ) ) {
						return $content;
					}
					$history = CTS_Webalizer_Parser::merge_history( $history, CTS_Webalizer_Parser::parse_history( $content ) );
				}
				return $history;
			}
		);
	}

	/**
	 * Webalizer daily stats for a month.
	 *
	 * @param string $ym "YYYY-MM".
	 * @return array|WP_Error Day => metrics.
	 */
	public function webalizer_daily( $ym ) {
		return $this->cached(
			'wb_' . $ym,
			function () use ( $ym ) {
				$ready = $this->source->ready();
				if ( is_wp_error( $ready ) ) {
					return $ready;
				}
				$file = 'usage_' . str_replace( '-', '', $ym ) . '.html';
				$days = array();
				foreach ( $this->webalizer_dirs() as $dir ) {
					$files = $this->source->list_files( $dir );
					if ( is_wp_error( $files ) ) {
						return $files;
					}
					if ( ! in_array( $file, $files, true ) ) {
						continue;
					}
					$content = $this->source->get_file( $dir, $file );
					if ( is_wp_error( $content ) ) {
						return $content;
					}
					$days = CTS_Webalizer_Parser::merge_daily( $days, CTS_Webalizer_Parser::parse_daily( $content ) );
				}
				return $days;
			}
		);
	}

	/**
	 * Keep only the top rows of each ranked table so caches stay small.
	 *
	 * @param array $stats Stats.
	 * @return array
	 */
	public static function trim_tables( array $stats ) {
		$sort_by = array(
			'pages'          => 'pages',
			'referrers'      => 'pages',
			'search_engines' => 'pages',
			'keyphrases'     => 'count',
			'countries'      => 'pages',
			'browsers'       => 'hits',
			'os'             => 'hits',
			'robots'         => 'hits',
			'errors'         => 'hits',
			'not_found'      => 'hits',
		);
		foreach ( $sort_by as $table => $metric ) {
			if ( empty( $stats[ $table ] ) ) {
				continue;
			}
			uasort(
				$stats[ $table ],
				static function ( $a, $b ) use ( $metric ) {
					return ( $b[ $metric ] ?? 0 ) <=> ( $a[ $metric ] ?? 0 );
				}
			);
			$stats[ $table ] = array_slice( $stats[ $table ], 0, self::TOP_N, true );
		}
		return $stats;
	}

	/**
	 * Run a loader through the transient cache. Errors are cached briefly.
	 *
	 * @param string   $name   Cache name.
	 * @param callable $loader Loader.
	 * @return mixed
	 */
	private function cached( $name, callable $loader ) {
		static $memo = array();

		$minutes = (int) $this->s['cache_minutes'];
		$key     = 'cts_c_' . md5(
			wp_json_encode(
				array(
					$name,
					get_option( self::GEN_OPTION, 0 ),
					$this->s['source'],
					$this->s['domain'],
					$this->s['protocol'],
					$this->s['home_dir'],
					$this->s['cpanel_host'],
					$this->s['cpanel_user'],
				)
			)
		);

		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}

		if ( $minutes > 0 ) {
			$hit = get_transient( $key );
			if ( false !== $hit ) {
				$value = isset( $hit['error'] ) ? new WP_Error( $hit['error'][0], $hit['error'][1] ) : $hit['value'];
				return $memo[ $key ] = $value; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
			}
		}

		$value = $loader();

		if ( $minutes > 0 ) {
			if ( is_wp_error( $value ) ) {
				set_transient( $key, array( 'error' => array( $value->get_error_code(), $value->get_error_message() ) ), 5 * MINUTE_IN_SECONDS );
			} else {
				set_transient( $key, array( 'value' => $value ), $minutes * MINUTE_IN_SECONDS );
			}
		}

		$memo[ $key ] = $value;
		return $value;
	}
}
