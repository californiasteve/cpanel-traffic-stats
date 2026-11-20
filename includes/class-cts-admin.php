<?php
/**
 * Admin screens: the Traffic Stats page, the Dashboard widget, and the test/refresh actions.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI.
 */
class CTS_Admin {

	const STATS_PAGE = 'cts-traffic';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_cts_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_cts_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_cts_use_domain', array( __CLASS__, 'handle_use_domain' ) );
		add_action( 'admin_post_cts_export', array( __CLASS__, 'handle_export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CTS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Capability needed to view stats.
	 *
	 * @return string
	 */
	public static function view_cap() {
		$s = CTS_Settings::get();
		/**
		 * Filter the capability required to view traffic stats.
		 *
		 * @param string $cap Capability.
		 */
		return apply_filters( 'cts_view_capability', $s['view_capability'] );
	}

	/**
	 * Menus.
	 */
	public static function menu() {
		add_dashboard_page(
			__( 'Traffic Stats', 'cpanel-traffic-stats' ),
			__( 'Traffic Stats', 'cpanel-traffic-stats' ),
			self::view_cap(),
			self::STATS_PAGE,
			array( __CLASS__, 'render_stats_page' )
		);
		add_options_page(
			__( 'cPanel Traffic Stats', 'cpanel-traffic-stats' ),
			__( 'cPanel Traffic Stats', 'cpanel-traffic-stats' ),
			'manage_options',
			CTS_Settings::PAGE,
			array( 'CTS_Settings', 'render_page' )
		);
	}

	/**
	 * Plugins list links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . CTS_Settings::PAGE ) ) . '">' . esc_html__( 'Settings', 'cpanel-traffic-stats' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'index.php?page=' . self::STATS_PAGE ) ) . '">' . esc_html__( 'View stats', 'cpanel-traffic-stats' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Styles and scripts.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		$pages = array( 'index.php', 'dashboard_page_' . self::STATS_PAGE, 'dashboard_page_' . CTS_Schedules::PAGE, 'settings_page_' . CTS_Settings::PAGE );
		if ( ! in_array( $hook, $pages, true ) ) {
			return;
		}
		wp_enqueue_style( 'cts-admin', CTS_URL . 'assets/admin.css', array(), CTS_VERSION );
		wp_enqueue_script( 'cts-admin', CTS_URL . 'assets/admin.js', array(), CTS_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	/**
	 * Clear the cache and go back to the stats page.
	 */
	public static function handle_refresh() {
		if ( ! current_user_can( self::view_cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'cpanel-traffic-stats' ) );
		}
		check_admin_referer( 'cts_refresh' );
		CTS_Stats::clear_cache();
		$month = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$args  = array( 'page' => self::STATS_PAGE );
		if ( preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$args['month'] = $month;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'index.php' ) ) );
		exit;
	}

	/**
	 * Run the connection test and show the result on the settings page.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'cpanel-traffic-stats' ) );
		}
		check_admin_referer( 'cts_test' );
		self::run_test();
	}

	/**
	 * Run the connection test, store the result for the settings page, and redirect there.
	 */
	private static function run_test() {
		CTS_Stats::clear_cache();

		$stats  = new CTS_Stats();
		$s      = $stats->settings();
		$source = $stats->source();
		$lines  = array();
		$ok     = true;

		$lines[] = array( 'info', sprintf( /* translators: %s: source description */ __( 'Reading from %s.', 'cpanel-traffic-stats' ), $source->describe() ) );

		$ready = $source->ready();
		if ( is_wp_error( $ready ) ) {
			$lines[] = array( 'error', $ready->get_error_message() );
			$ok      = false;
		}

		if ( $ok && 'cpanel' === $s['source'] ) {
			$ping = $source->uapi( 'Variables', 'get_user_information', array( 'name' => 'home' ) );
			if ( is_wp_error( $ping ) && 'cts_uapi_error' !== $ping->get_error_code() ) {
				$lines[] = array( 'error', $ping->get_error_message() );
				$ok      = false;
			} else {
				$lines[] = array( 'ok', __( 'Connected to cPanel and the API token was accepted.', 'cpanel-traffic-stats' ) );
			}
		}

		if ( $ok && $stats->uses( 'awstats' ) ) {
			$months = $stats->awstats_months();
			if ( is_wp_error( $months ) ) {
				$lines[] = array( 'error', 'AWStats: ' . $months->get_error_message() );
				$ok      = false;
			} elseif ( empty( $months ) ) {
				$domains = $stats->awstats_domains();
				$msg     = sprintf( /* translators: %s: domain */ __( 'AWStats: no data files found for %s.', 'cpanel-traffic-stats' ), $s['domain'] );
				if ( $domains ) {
					$msg .= ' ' . __( 'Some hosts (Bluehost, for example) file the main website\'s stats under the account\'s built-in domain instead. AWStats has data for:', 'cpanel-traffic-stats' );
				} else {
					$msg .= ' ' . __( 'Make sure AWStats is turned on in cPanel (Metrics > Metrics Editor) and has run at least once.', 'cpanel-traffic-stats' );
				}
				$lines[] = array( 'error', $msg );
				foreach ( $domains as $domain ) {
					$lines[] = array( 'suggest', $domain );
				}
				$ok      = false;
			} else {
				$latest = key( $months );
				$data   = $stats->awstats( $latest );
				if ( is_wp_error( $data ) ) {
					$lines[] = array( 'error', 'AWStats: ' . $data->get_error_message() );
					$ok      = false;
				} else {
					$lines[] = array(
						'ok',
						sprintf(
							/* translators: 1: number of months, 2: month, 3: visits */
							__( 'AWStats: found %1$d month(s) of data. %2$s had %3$s visits.', 'cpanel-traffic-stats' ),
							count( $months ),
							self::month_label( $latest ),
							number_format_i18n( $data['summary']['visits'] )
						),
					);
				}
			}
		}

		if ( $ok && $stats->uses( 'webalizer' ) ) {
			$hist = $stats->webalizer_history();
			if ( is_wp_error( $hist ) ) {
				$lines[] = array( 'error', 'Webalizer: ' . $hist->get_error_message() );
			} elseif ( empty( $hist ) ) {
				$lines[] = array( 'error', __( 'Webalizer: no webalizer.hist file found. Make sure Webalizer is turned on in cPanel (Metrics > Metrics Editor).', 'cpanel-traffic-stats' ) );
			} else {
				$lines[] = array( 'ok', sprintf( /* translators: %d: number of months */ __( 'Webalizer: found %d month(s) of history.', 'cpanel-traffic-stats' ), count( $hist ) ) );
			}
		}

		set_transient( 'cts_test_' . get_current_user_id(), $lines, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . CTS_Settings::PAGE . '#cts-test' ) );
		exit;
	}

	/**
	 * Switch the configured domain to one suggested by the test, then re-run the test.
	 */
	public static function handle_use_domain() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'cpanel-traffic-stats' ) );
		}
		check_admin_referer( 'cts_use_domain' );
		$domain = isset( $_POST['domain'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['domain'] ) ) ) : '';
		if ( preg_match( '/^[a-z0-9.-]+$/', $domain ) ) {
			$saved = get_option( CTS_Settings::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			// Saved values go back through the settings sanitizer; the stored token is kept as is.
			update_option( CTS_Settings::OPTION, array_merge( CTS_Settings::defaults(), $saved, array( 'domain' => $domain ) ) );
		}
		self::run_test();
	}

	/**
	 * Show the last test result (once).
	 */
	public static function render_test_result() {
		$key   = 'cts_test_' . get_current_user_id();
		$lines = get_transient( $key );
		if ( ! is_array( $lines ) ) {
			return;
		}
		delete_transient( $key );
		echo '<ul id="cts-test" class="cts-test">';
		foreach ( $lines as $line ) {
			if ( 'suggest' === $line[0] ) {
				printf(
					'<li class="cts-test-suggest"><form method="post" action="%1$s"><input type="hidden" name="action" value="cts_use_domain" /><input type="hidden" name="domain" value="%2$s" />%3$s<code>%4$s</code> <button type="submit" class="button button-small">%5$s</button></form></li>',
					esc_url( admin_url( 'admin-post.php' ) ),
					esc_attr( $line[1] ),
					wp_nonce_field( 'cts_use_domain', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $line[1] ),
					esc_html__( 'Use this domain', 'cpanel-traffic-stats' )
				);
				continue;
			}
			$icon = 'ok' === $line[0] ? 'yes-alt' : ( 'error' === $line[0] ? 'warning' : 'info-outline' );
			printf(
				'<li class="cts-test-%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span> %3$s</li>',
				esc_attr( $line[0] ),
				esc_attr( $icon ),
				esc_html( $line[1] )
			);
		}
		echo '</ul>';
	}

	/* ---------------------------------------------------------------------
	 * Dashboard widget
	 * ------------------------------------------------------------------- */

	/**
	 * Register the widget.
	 */
	public static function dashboard_widget() {
		$s = CTS_Settings::get();
		if ( empty( $s['show_widget'] ) || ! current_user_can( self::view_cap() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'cts_widget', __( 'Website Traffic', 'cpanel-traffic-stats' ), array( __CLASS__, 'render_widget' ) );
	}

	/**
	 * Render the widget.
	 */
	public static function render_widget() {
		$stats = new CTS_Stats();
		$link  = admin_url( 'index.php?page=' . self::STATS_PAGE );

		if ( $stats->uses( 'awstats' ) ) {
			$months = $stats->awstats_months();
			if ( is_wp_error( $months ) ) {
				self::notice( $months->get_error_message(), true );
				return;
			}
			if ( $months ) {
				$keys   = array_keys( $months );
				$ym     = $keys[0];
				$data   = $stats->awstats( $ym );
				$prev   = isset( $keys[1] ) ? $stats->awstats( $keys[1] ) : null;
				if ( is_wp_error( $data ) ) {
					self::notice( $data->get_error_message(), true );
					return;
				}
				if ( $data ) {
					$prev_summary = is_array( $prev ) ? self::comparable_summary( $data, $prev, $ym ) : null;
					echo '<p class="cts-widget-month">' . esc_html( self::month_label( $ym ) ) . '</p>';
					self::render_cards(
						array(
							array( __( 'Visitors', 'cpanel-traffic-stats' ), $data['summary']['unique'], $prev_summary ? $prev_summary['unique'] : null ),
							array( __( 'Visits', 'cpanel-traffic-stats' ), $data['summary']['visits'], $prev_summary ? $prev_summary['visits'] : null ),
							array( __( 'Page views', 'cpanel-traffic-stats' ), $data['summary']['pages'], $prev_summary ? $prev_summary['pages'] : null ),
							array( __( 'Bandwidth', 'cpanel-traffic-stats' ), $data['summary']['bandwidth'], $prev_summary ? $prev_summary['bandwidth'] : null, 'bytes' ),
						),
						true
					);
					self::render_bar_chart( self::days_series( $data['days'], $ym, 'visits' ), __( 'Visits per day', 'cpanel-traffic-stats' ), 'cts-chart-small' );
					printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $link ), esc_html__( 'Full report', 'cpanel-traffic-stats' ) );
					return;
				}
			}
		}

		if ( $stats->uses( 'webalizer' ) ) {
			$hist = $stats->webalizer_history();
			if ( is_wp_error( $hist ) ) {
				self::notice( $hist->get_error_message(), true );
				return;
			}
			if ( $hist ) {
				$row  = reset( $hist );
				$prev = self::comparable_webalizer( $row, next( $hist ) );
				echo '<p class="cts-widget-month">' . esc_html( self::month_label( sprintf( '%04d-%02d', $row['year'], $row['month'] ) ) ) . ' &middot; Webalizer</p>';
				self::render_cards(
					array(
						array( __( 'Visits', 'cpanel-traffic-stats' ), $row['visits'], $prev ? $prev['visits'] : null ),
						array( __( 'Sites', 'cpanel-traffic-stats' ), $row['sites'], $prev ? $prev['sites'] : null ),
						array( __( 'Pages', 'cpanel-traffic-stats' ), $row['pages'], $prev ? $prev['pages'] : null ),
						array( __( 'Bandwidth', 'cpanel-traffic-stats' ), $row['kbytes'] * 1024, $prev ? $prev['kbytes'] * 1024 : null, 'bytes' ),
					),
					true
				);
				printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $link ), esc_html__( 'Full report', 'cpanel-traffic-stats' ) );
				return;
			}
		}

		self::notice( self::no_data_message( $stats ), false );
	}

	/* ---------------------------------------------------------------------
	 * Stats page
	 * ------------------------------------------------------------------- */

	/**
	 * Render the full Traffic Stats page.
	 */
	public static function render_stats_page() {
		if ( ! current_user_can( self::view_cap() ) ) {
			return;
		}
		$stats = new CTS_Stats();
		$s     = $stats->settings();

		// Months available from whichever engines are enabled.
		$options = array();
		$error   = null;
		if ( $stats->uses( 'awstats' ) ) {
			$months = $stats->awstats_months();
			if ( is_wp_error( $months ) ) {
				$error = $months;
			} else {
				foreach ( array_keys( $months ) as $ym ) {
					$options[ $ym ] = true;
				}
			}
		}
		$hist = array();
		if ( $stats->uses( 'webalizer' ) && ! $error ) {
			$hist = $stats->webalizer_history();
			if ( is_wp_error( $hist ) ) {
				$error = $hist;
				$hist  = array();
			} else {
				foreach ( array_keys( $hist ) as $ym ) {
					$options[ $ym ] = true;
				}
			}
		}
		krsort( $options );
		$options = array_keys( $options );

		$requested = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ym        = in_array( $requested, $options, true ) ? $requested : ( $options ? $options[0] : '' );
		?>
		<div class="wrap cts-wrap">
			<div class="cts-header">
				<h1>
					<?php esc_html_e( 'Traffic Stats', 'cpanel-traffic-stats' ); ?>
					<span class="cts-domain"><?php echo esc_html( $s['domain'] ); ?></span>
				</h1>
				<div class="cts-toolbar">
					<?php if ( $options ) : ?>
						<form method="get" action="<?php echo esc_url( admin_url( 'index.php' ) ); ?>">
							<input type="hidden" name="page" value="<?php echo esc_attr( self::STATS_PAGE ); ?>" />
							<label for="cts-month" class="screen-reader-text"><?php esc_html_e( 'Month', 'cpanel-traffic-stats' ); ?></label>
							<select id="cts-month" name="month" class="cts-autosubmit">
								<?php foreach ( $options as $opt ) : ?>
									<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $ym, $opt ); ?>><?php echo esc_html( self::month_label( $opt ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<noscript><?php submit_button( __( 'Show', 'cpanel-traffic-stats' ), 'secondary', '', false ); ?></noscript>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cts_refresh" />
						<input type="hidden" name="month" value="<?php echo esc_attr( $ym ); ?>" />
						<?php wp_nonce_field( 'cts_refresh' ); ?>
						<button type="submit" class="button"><span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e( 'Refresh', 'cpanel-traffic-stats' ); ?></button>
					</form>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'index.php?page=' . CTS_Schedules::PAGE ) ); ?>"><?php esc_html_e( 'Email reports', 'cpanel-traffic-stats' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . CTS_Settings::PAGE ) ); ?>"><?php esc_html_e( 'Settings', 'cpanel-traffic-stats' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php
			if ( $error ) {
				self::notice( $error->get_error_message(), true );
			} elseif ( ! $options ) {
				self::notice( self::no_data_message( $stats ), false );
			} else {
				self::render_download_panel( $ym );
				if ( $stats->uses( 'awstats' ) ) {
					self::render_awstats_section( $stats, $ym, $options );
				}
				if ( $stats->uses( 'webalizer' ) ) {
					self::render_webalizer_section( $stats, $ym, $hist );
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * "Download report" panel: PDF or CSV for the shown month, a preset period or custom dates.
	 *
	 * @param string $ym Month being viewed.
	 */
	private static function render_download_panel( $ym ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$error = isset( $_GET['export_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['export_error'] ) ) ) : '';
		?>
		<details class="cts-panel cts-download"<?php echo $error ? ' open' : ''; ?>>
			<summary><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download report (PDF or CSV)', 'cpanel-traffic-stats' ); ?></summary>
			<?php if ( $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cts-download-form">
				<input type="hidden" name="action" value="cts_export" />
				<input type="hidden" name="month" value="<?php echo esc_attr( $ym ); ?>" />
				<?php wp_nonce_field( 'cts_export' ); ?>
				<label for="cts-x-period"><?php esc_html_e( 'Period', 'cpanel-traffic-stats' ); ?></label>
				<select id="cts-x-period" name="period" data-cts-export-period>
					<option value="<?php echo esc_attr( 'month:' . $ym ); ?>"><?php echo esc_html( self::month_label( $ym ) ); ?></option>
					<?php foreach ( CTS_Report::periods() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
					<option value="custom"><?php esc_html_e( 'Custom dates…', 'cpanel-traffic-stats' ); ?></option>
				</select>
				<span class="cts-custom-dates" hidden>
					<label for="cts-x-from" class="screen-reader-text"><?php esc_html_e( 'From', 'cpanel-traffic-stats' ); ?></label>
					<input type="date" id="cts-x-from" name="from" value="<?php echo esc_attr( $ym . '-01' ); ?>" />
					<span aria-hidden="true">–</span>
					<label for="cts-x-to" class="screen-reader-text"><?php esc_html_e( 'To', 'cpanel-traffic-stats' ); ?></label>
					<input type="date" id="cts-x-to" name="to" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
				</span>
				<button type="submit" name="format" value="pdf" class="button button-primary"><?php esc_html_e( 'Download PDF', 'cpanel-traffic-stats' ); ?></button>
				<button type="submit" name="format" value="csv" class="button"><?php esc_html_e( 'Download CSV', 'cpanel-traffic-stats' ); ?></button>
			</form>
		</details>
		<?php
	}

	/**
	 * Stream a PDF or CSV download.
	 */
	public static function handle_export() {
		if ( ! current_user_can( self::view_cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'cpanel-traffic-stats' ) );
		}
		check_admin_referer( 'cts_export' );

		$format = isset( $_POST['format'] ) && 'csv' === $_POST['format'] ? 'csv' : 'pdf';
		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		$from   = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to     = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$month  = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';

		$range  = CTS_Report::range( $period, null, $from, $to );
		$report = is_wp_error( $range ) ? $range : CTS_Report::build( new CTS_Stats(), $range[0], $range[1] );
		if ( is_wp_error( $report ) ) {
			$args = array(
				'page'         => self::STATS_PAGE,
				'export_error' => rawurlencode( $report->get_error_message() ),
			);
			if ( preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
				$args['month'] = $month;
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'index.php' ) ) );
			exit;
		}

		$body = 'csv' === $format ? CTS_Export::csv( $report ) : CTS_Export::pdf( $report );
		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === $format ? 'text/csv; charset=utf-8' : 'application/pdf' ) );
		header( 'Content-Disposition: attachment; filename="' . CTS_Export::filename( $report, $format ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file download.
		exit;
	}

	/**
	 * AWStats report for one month.
	 *
	 * @param CTS_Stats $stats   Stats.
	 * @param string    $ym      Month.
	 * @param string[]  $options All months, newest first.
	 */
	private static function render_awstats_section( CTS_Stats $stats, $ym, array $options ) {
		$data = $stats->awstats( $ym );
		if ( is_wp_error( $data ) ) {
			self::notice( $data->get_error_message(), true );
			return;
		}
		if ( ! $data ) {
			if ( 'awstats' === $stats->settings()['engine'] ) {
				self::notice( __( 'AWStats has no data for this month.', 'cpanel-traffic-stats' ), false );
			}
			return;
		}

		$idx  = array_search( $ym, $options, true );
		$prev = ( false !== $idx && isset( $options[ $idx + 1 ] ) ) ? $stats->awstats( $options[ $idx + 1 ] ) : null;
		$ps   = is_array( $prev ) ? self::comparable_summary( $data, $prev, $ym ) : null;
		$sum  = $data['summary'];

		if ( 'both' === $stats->settings()['engine'] ) {
			echo '<h2 class="cts-engine">AWStats</h2>';
		}

		self::render_cards(
			array(
				array( __( 'Unique visitors', 'cpanel-traffic-stats' ), $sum['unique'], $ps ? $ps['unique'] : null ),
				array( __( 'Visits', 'cpanel-traffic-stats' ), $sum['visits'], $ps ? $ps['visits'] : null ),
				array( __( 'Page views', 'cpanel-traffic-stats' ), $sum['pages'], $ps ? $ps['pages'] : null ),
				array( __( 'Hits', 'cpanel-traffic-stats' ), $sum['hits'], $ps ? $ps['hits'] : null ),
				array( __( 'Bandwidth', 'cpanel-traffic-stats' ), $sum['bandwidth'], $ps ? $ps['bandwidth'] : null, 'bytes' ),
			)
		);

		echo '<p class="cts-meta">';
		if ( $sum['last_update'] ) {
			$ts = self::awstats_time( $sum['last_update'] );
			if ( $ts ) {
				/* translators: %s: date and time */
				printf( esc_html__( 'Last updated by AWStats: %s.', 'cpanel-traffic-stats' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) );
				echo ' ';
			}
		}
		if ( $ps ) {
			if ( ! empty( $ps['partial_days'] ) ) {
				/* translators: %d: number of days */
				printf( esc_html__( 'This month is still in progress, so arrows compare with the first %d days of the previous month.', 'cpanel-traffic-stats' ), (int) $ps['partial_days'] );
			} else {
				esc_html_e( 'Arrows compare with the previous month.', 'cpanel-traffic-stats' );
			}
			echo ' ';
		}
		/* translators: 1: pages, 2: hits */
		printf( esc_html__( 'Not counted as human traffic (robots, spiders, etc.): %1$s pages, %2$s hits.', 'cpanel-traffic-stats' ), esc_html( number_format_i18n( $sum['not_viewed_pages'] ) ), esc_html( number_format_i18n( $sum['not_viewed_hits'] ) ) );
		echo '</p>';

		echo '<div class="cts-panel">';
		echo '<h2>' . esc_html__( 'Daily traffic', 'cpanel-traffic-stats' ) . '</h2>';
		echo '<div class="cts-tabs" data-cts-tabs>';
		$metrics = array(
			'visits' => __( 'Visits', 'cpanel-traffic-stats' ),
			'pages'  => __( 'Page views', 'cpanel-traffic-stats' ),
			'hits'   => __( 'Hits', 'cpanel-traffic-stats' ),
		);
		$first   = true;
		foreach ( $metrics as $key => $label ) {
			printf( '<button type="button" class="button-link cts-tab%s" data-cts-tab="cts-daily-%s" aria-pressed="%s">%s</button>', $first ? ' is-active' : '', esc_attr( $key ), $first ? 'true' : 'false', esc_html( $label ) );
			$first = false;
		}
		echo '</div>';
		$first = true;
		foreach ( $metrics as $key => $label ) {
			printf( '<div id="cts-daily-%s" class="cts-tab-panel"%s>', esc_attr( $key ), $first ? '' : ' hidden' );
			/* translators: %s: metric name */
			self::render_bar_chart( self::days_series( $data['days'], $ym, $key ), sprintf( __( '%s per day', 'cpanel-traffic-stats' ), $label ) );
			echo '</div>';
			$first = false;
		}
		echo '</div>';

		$hours = array();
		foreach ( $data['hours'] as $h => $row ) {
			$hours[ sprintf( '%02d', $h ) ] = $row['pages'];
		}
		echo '<div class="cts-panel">';
		echo '<h2>' . esc_html__( 'Page views by hour of day', 'cpanel-traffic-stats' ) . '</h2>';
		self::render_bar_chart( $hours, __( 'Page views by hour of day (server time)', 'cpanel-traffic-stats' ), 'cts-chart-hours' );
		echo '</div>';

		echo '<div class="cts-grid">';

		self::render_table(
			__( 'Top pages', 'cpanel-traffic-stats' ),
			$data['pages'],
			array(
				'pages' => __( 'Views', 'cpanel-traffic-stats' ),
				'entry' => __( 'Entry', 'cpanel-traffic-stats' ),
			),
			__( 'Page', 'cpanel-traffic-stats' )
		);

		self::render_table(
			__( 'Referring sites', 'cpanel-traffic-stats' ),
			$data['referrers'],
			array( 'pages' => __( 'Pages', 'cpanel-traffic-stats' ) ),
			__( 'Referrer', 'cpanel-traffic-stats' )
		);

		self::render_table(
			__( 'Search engines', 'cpanel-traffic-stats' ),
			$data['search_engines'],
			array( 'pages' => __( 'Pages', 'cpanel-traffic-stats' ) ),
			__( 'Search engine', 'cpanel-traffic-stats' ),
			true
		);

		self::render_table(
			__( 'Search phrases', 'cpanel-traffic-stats' ),
			$data['keyphrases'],
			array( 'count' => __( 'Searches', 'cpanel-traffic-stats' ) ),
			__( 'Phrase', 'cpanel-traffic-stats' )
		);

		$countries = array();
		foreach ( $data['countries'] as $code => $row ) {
			$countries[ self::country_label( $code ) ] = $row;
		}
		self::render_table(
			__( 'Countries', 'cpanel-traffic-stats' ),
			$countries,
			array(
				'pages'     => __( 'Pages', 'cpanel-traffic-stats' ),
				'bandwidth' => __( 'Bandwidth', 'cpanel-traffic-stats' ),
			),
			__( 'Country', 'cpanel-traffic-stats' ),
			true
		);

		self::render_table(
			__( 'Browsers', 'cpanel-traffic-stats' ),
			$data['browsers'],
			array( 'hits' => __( 'Hits', 'cpanel-traffic-stats' ) ),
			__( 'Browser', 'cpanel-traffic-stats' ),
			true
		);

		self::render_table(
			__( 'Operating systems', 'cpanel-traffic-stats' ),
			$data['os'],
			array( 'hits' => __( 'Hits', 'cpanel-traffic-stats' ) ),
			__( 'System', 'cpanel-traffic-stats' ),
			true
		);

		self::render_table(
			__( 'Robots and spiders', 'cpanel-traffic-stats' ),
			$data['robots'],
			array(
				'hits'      => __( 'Hits', 'cpanel-traffic-stats' ),
				'bandwidth' => __( 'Bandwidth', 'cpanel-traffic-stats' ),
			),
			__( 'Robot', 'cpanel-traffic-stats' )
		);

		$errors = array();
		foreach ( $data['errors'] as $code => $row ) {
			$errors[ $code . ' ' . self::http_status_label( $code ) ] = $row;
		}
		self::render_table(
			__( 'HTTP errors', 'cpanel-traffic-stats' ),
			$errors,
			array( 'hits' => __( 'Hits', 'cpanel-traffic-stats' ) ),
			__( 'Error', 'cpanel-traffic-stats' )
		);

		self::render_table(
			__( 'Pages not found (404)', 'cpanel-traffic-stats' ),
			$data['not_found'],
			array( 'hits' => __( 'Hits', 'cpanel-traffic-stats' ) ),
			__( 'URL', 'cpanel-traffic-stats' )
		);

		echo '</div>';
	}

	/**
	 * Webalizer report.
	 *
	 * @param CTS_Stats $stats Stats.
	 * @param string    $ym    Month.
	 * @param array     $hist  Monthly history.
	 */
	private static function render_webalizer_section( CTS_Stats $stats, $ym, array $hist ) {
		if ( empty( $hist ) ) {
			if ( 'webalizer' === $stats->settings()['engine'] ) {
				self::notice( __( 'Webalizer has no data yet.', 'cpanel-traffic-stats' ), false );
			}
			return;
		}
		$both = 'both' === $stats->settings()['engine'];

		if ( $both ) {
			echo '<h2 class="cts-engine">Webalizer</h2>';
		}

		if ( isset( $hist[ $ym ] ) ) {
			$row  = $hist[ $ym ];
			$keys = array_keys( $hist );
			$idx  = array_search( $ym, $keys, true );
			$prev = ( false !== $idx && isset( $keys[ $idx + 1 ] ) ) ? self::comparable_webalizer( $row, $hist[ $keys[ $idx + 1 ] ] ) : null;

			if ( ! $both ) {
				self::render_cards(
					array(
						array( __( 'Visits', 'cpanel-traffic-stats' ), $row['visits'], $prev ? $prev['visits'] : null ),
						array( __( 'Sites (unique IPs)', 'cpanel-traffic-stats' ), $row['sites'], $prev ? $prev['sites'] : null ),
						array( __( 'Pages', 'cpanel-traffic-stats' ), $row['pages'], $prev ? $prev['pages'] : null ),
						array( __( 'Hits', 'cpanel-traffic-stats' ), $row['hits'], $prev ? $prev['hits'] : null ),
						array( __( 'Bandwidth', 'cpanel-traffic-stats' ), $row['kbytes'] * 1024, $prev ? $prev['kbytes'] * 1024 : null, 'bytes' ),
					)
				);
			}

			$days = $stats->webalizer_daily( $ym );
			if ( is_array( $days ) && $days ) {
				$series = array();
				$count  = self::days_in_month( $ym );
				for ( $d = 1; $d <= $count; $d++ ) {
					$series[ (string) $d ] = isset( $days[ $d ] ) ? $days[ $d ]['visits'] : 0;
				}
				echo '<div class="cts-panel">';
				/* translators: %s: month */
				echo '<h2>' . esc_html( sprintf( __( 'Webalizer visits per day, %s', 'cpanel-traffic-stats' ), self::month_label( $ym ) ) ) . '</h2>';
				self::render_bar_chart( $series, __( 'Visits per day', 'cpanel-traffic-stats' ) );
				echo '</div>';
			}
		}

		// Monthly history table + chart (oldest to newest, last 12 months).
		$recent = array_slice( $hist, 0, 12, true );
		$series = array();
		foreach ( array_reverse( $recent, true ) as $key => $row ) {
			$series[ wp_date( 'M y', mktime( 0, 0, 0, $row['month'], 1, $row['year'] ) ) ] = $row['visits'];
		}

		echo '<div class="cts-panel">';
		echo '<h2>' . esc_html__( 'Monthly history (Webalizer)', 'cpanel-traffic-stats' ) . '</h2>';
		self::render_bar_chart( $series, __( 'Visits per month', 'cpanel-traffic-stats' ) );
		echo '<div class="cts-scroll"><table class="widefat striped cts-table cts-history"><thead><tr>';
		foreach ( array( __( 'Month', 'cpanel-traffic-stats' ), __( 'Visits', 'cpanel-traffic-stats' ), __( 'Sites', 'cpanel-traffic-stats' ), __( 'Pages', 'cpanel-traffic-stats' ), __( 'Hits', 'cpanel-traffic-stats' ), __( 'Bandwidth', 'cpanel-traffic-stats' ), __( 'Visits / day', 'cpanel-traffic-stats' ) ) as $i => $h ) {
			printf( '<th scope="col"%s>%s</th>', $i ? ' class="num"' : '', esc_html( $h ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $recent as $key => $row ) {
			$days = max( 1, $row['last_day'] - $row['first_day'] + 1 );
			printf(
				'<tr%s><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
				$key === $ym ? ' class="cts-current"' : '',
				esc_html( self::month_label( $key ) ),
				esc_html( number_format_i18n( $row['visits'] ) ),
				esc_html( number_format_i18n( $row['sites'] ) ),
				esc_html( number_format_i18n( $row['pages'] ) ),
				esc_html( number_format_i18n( $row['hits'] ) ),
				esc_html( size_format( $row['kbytes'] * 1024, 1 ) ),
				esc_html( number_format_i18n( $row['visits'] / $days, 1 ) )
			);
		}
		echo '</tbody></table></div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Rendering helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Summary cards.
	 *
	 * @param array $cards   List of [label, value, previous|null, 'bytes'?].
	 * @param bool  $compact Smaller cards for the widget.
	 */
	private static function render_cards( array $cards, $compact = false ) {
		echo '<div class="cts-cards' . ( $compact ? ' cts-cards-compact' : '' ) . '">';
		foreach ( $cards as $card ) {
			$label = $card[0];
			$value = $card[1];
			$prev  = $card[2];
			$bytes = isset( $card[3] ) && 'bytes' === $card[3];

			echo '<div class="cts-card">';
			echo '<div class="cts-card-label">' . esc_html( $label ) . '</div>';
			echo '<div class="cts-card-value">' . esc_html( $bytes ? size_format( $value, 1 ) : number_format_i18n( $value ) ) . '</div>';
			if ( null !== $prev && $prev > 0 ) {
				$change = ( $value - $prev ) / $prev * 100;
				$class  = $change >= 0 ? 'up' : 'down';
				printf(
					'<div class="cts-card-change cts-%1$s" title="%2$s">%3$s %4$s%%</div>',
					esc_attr( $class ),
					/* translators: %s: previous month value */
					esc_attr( sprintf( __( 'Previous month: %s', 'cpanel-traffic-stats' ), $bytes ? size_format( $prev, 1 ) : number_format_i18n( $prev ) ) ),
					$change >= 0 ? '&#9650;' : '&#9660;',
					esc_html( number_format_i18n( abs( $change ), 0 ) )
				);
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Inline SVG bar chart. Values are labels => numbers.
	 *
	 * @param array  $series Label => value.
	 * @param string $title  Accessible title.
	 * @param string $class  Extra CSS class.
	 */
	private static function render_bar_chart( array $series, $title, $class = '' ) {
		if ( ! $series ) {
			return;
		}
		$max   = max( 1, max( $series ) );
		$n     = count( $series );
		$w     = 1000;
		$h     = 220;
		$pad_b = 22;
		$pad_t = 8;
		$slot  = $w / $n;
		$bar   = max( 2, $slot * 0.7 );
		$every = $n > 16 ? (int) ceil( $n / 16 ) : 1;
		$id    = 'cts-chart-' . wp_unique_id();

		printf(
			'<figure class="cts-chart %1$s"><svg viewBox="0 0 %2$d %3$d" role="img" aria-labelledby="%4$s"><title id="%4$s">%5$s</title>',
			esc_attr( $class ),
			(int) $w,
			(int) $h,
			esc_attr( $id ),
			esc_html( $title )
		);

		// Grid lines at 50% and 100%.
		foreach ( array( 0.5, 1 ) as $f ) {
			$y = $pad_t + ( $h - $pad_b - $pad_t ) * ( 1 - $f );
			printf( '<line class="cts-grid-line" x1="0" x2="%d" y1="%.1f" y2="%.1f" />', (int) $w, $y, $y );
		}

		$i = 0;
		foreach ( $series as $label => $value ) {
			$bh = ( $h - $pad_b - $pad_t ) * ( $value / $max );
			$x  = $i * $slot + ( $slot - $bar ) / 2;
			$y  = $h - $pad_b - $bh;
			printf(
				'<rect class="cts-bar" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2"><title>%s: %s</title></rect>',
				$x,
				$y,
				$bar,
				max( 0, $bh ),
				esc_html( (string) $label ),
				esc_html( number_format_i18n( $value ) )
			);
			if ( 0 === $i % $every ) {
				printf( '<text class="cts-axis" x="%.1f" y="%d" text-anchor="middle">%s</text>', $x + $bar / 2, $h - 6, esc_html( (string) $label ) );
			}
			$i++;
		}
		echo '</svg>';
		/* translators: %s: highest value */
		printf( '<figcaption>%s</figcaption>', esc_html( sprintf( __( 'Peak: %s', 'cpanel-traffic-stats' ), number_format_i18n( $max ) ) ) );
		echo '</figure>';
	}

	/**
	 * Ranked table with share bars.
	 *
	 * @param string $title     Title.
	 * @param array  $rows      Label => metrics.
	 * @param array  $columns   Metric key => header. First is the ranking metric.
	 * @param string $first_col Header of the label column.
	 * @param bool   $share     Show a percentage share column.
	 */
	private static function render_table( $title, array $rows, array $columns, $first_col, $share = false ) {
		$limit  = 10;
		$keys   = array_keys( $columns );
		$rank   = $keys[0];
		$total  = 0;
		foreach ( $rows as $r ) {
			$total += $r[ $rank ] ?? 0;
		}
		uasort(
			$rows,
			static function ( $a, $b ) use ( $rank ) {
				return ( $b[ $rank ] ?? 0 ) <=> ( $a[ $rank ] ?? 0 );
			}
		);
		$top  = array_slice( $rows, 0, 25, true );
		$max  = $top ? max( 1, (int) max( array_column( $top, $rank ) ) ) : 1;

		echo '<div class="cts-panel cts-table-panel">';
		echo '<h2>' . esc_html( $title ) . '</h2>';
		if ( ! $top ) {
			echo '<p class="cts-empty">' . esc_html__( 'No data for this month.', 'cpanel-traffic-stats' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped cts-table"><thead><tr><th scope="col">' . esc_html( $first_col ) . '</th>';
		foreach ( $columns as $h ) {
			echo '<th scope="col" class="num">' . esc_html( $h ) . '</th>';
		}
		if ( $share ) {
			echo '<th scope="col" class="num">%</th>';
		}
		echo '</tr></thead><tbody>';
		$i = 0;
		foreach ( $top as $label => $r ) {
			$pct = $max ? ( ( $r[ $rank ] ?? 0 ) / $max * 100 ) : 0;
			printf(
				'<tr%s><td class="cts-label"><span class="cts-share" style="width:%.1f%%"></span><span class="cts-label-text" title="%s">%s</span></td>',
				$i >= $limit ? ' class="cts-more" hidden' : '',
				$pct,
				esc_attr( (string) $label ),
				esc_html( (string) $label )
			);
			foreach ( $keys as $k ) {
				$v = $r[ $k ] ?? 0;
				echo '<td class="num">' . esc_html( 'bandwidth' === $k ? size_format( $v, 1 ) : number_format_i18n( $v ) ) . '</td>';
			}
			if ( $share ) {
				echo '<td class="num">' . esc_html( $total ? number_format_i18n( ( $r[ $rank ] ?? 0 ) / $total * 100, 1 ) : '0' ) . '</td>';
			}
			echo '</tr>';
			$i++;
		}
		echo '</tbody></table>';
		if ( count( $top ) > $limit ) {
			echo '<p><button type="button" class="button-link cts-show-more">' . esc_html__( 'Show more', 'cpanel-traffic-stats' ) . '</button></p>';
		}
		echo '</div>';
	}

	/**
	 * The previous month's summary, made comparable with the given month.
	 *
	 * When the given month is still in progress (its data stops before the
	 * last day of the month), the previous month is cut to the same number of
	 * days using its daily data. Unique visitors cannot be split by day, so
	 * that comparison is dropped for partial months.
	 *
	 * @param array  $data Current month stats.
	 * @param array  $prev Previous month stats.
	 * @param string $ym   Current month "YYYY-MM".
	 * @return array Summary with an extra "partial_days" key (0 when the month is complete).
	 */
	private static function comparable_summary( array $data, array $prev, $ym ) {
		$summary                 = $prev['summary'];
		$summary['partial_days'] = 0;
		if ( empty( $data['days'] ) ) {
			return $summary;
		}
		$last_day = (int) substr( (string) max( array_keys( $data['days'] ) ), 6, 2 );
		if ( $last_day >= self::days_in_month( $ym ) || $last_day < 1 ) {
			return $summary;
		}
		$cut = array(
			'pages'     => 0,
			'hits'      => 0,
			'bandwidth' => 0,
			'visits'    => 0,
		);
		foreach ( $prev['days'] as $date => $row ) {
			if ( (int) substr( (string) $date, 6, 2 ) <= $last_day ) {
				foreach ( $cut as $k => $v ) {
					$cut[ $k ] += $row[ $k ];
				}
			}
		}
		$summary                 = array_merge( $summary, $cut );
		$summary['unique']       = null;
		$summary['partial_days'] = $last_day;
		return $summary;
	}

	/**
	 * A previous Webalizer month scaled to the same number of days as the
	 * current one, so an in-progress month is not compared with a full month.
	 *
	 * @param array      $row  Current month.
	 * @param array|bool $prev Previous month, or false.
	 * @return array|null
	 */
	private static function comparable_webalizer( array $row, $prev ) {
		if ( ! is_array( $prev ) ) {
			return null;
		}
		$cur_days  = max( 1, $row['last_day'] - $row['first_day'] + 1 );
		$prev_days = max( 1, $prev['last_day'] - $prev['first_day'] + 1 );
		$full      = self::days_in_month( sprintf( '%04d-%02d', $row['year'], $row['month'] ) );
		if ( $row['last_day'] >= $full || $cur_days >= $prev_days ) {
			return $prev;
		}
		$f = $cur_days / $prev_days;
		foreach ( array( 'hits', 'files', 'sites', 'kbytes', 'pages', 'visits' ) as $k ) {
			$prev[ $k ] = $prev[ $k ] * $f;
		}
		return $prev;
	}

	/**
	 * Daily series for a month, filling missing days with zero.
	 *
	 * @param array  $days   YYYYMMDD => metrics.
	 * @param string $ym     Month.
	 * @param string $metric Metric key.
	 * @return array Day number => value.
	 */
	private static function days_series( array $days, $ym, $metric ) {
		$series = array();
		$prefix = str_replace( '-', '', $ym );
		$count  = self::days_in_month( $ym );
		for ( $d = 1; $d <= $count; $d++ ) {
			$key                   = $prefix . sprintf( '%02d', $d );
			$series[ (string) $d ] = isset( $days[ $key ][ $metric ] ) ? $days[ $key ][ $metric ] : 0;
		}
		return $series;
	}

	/**
	 * Days in a "YYYY-MM" month.
	 *
	 * @param string $ym Month.
	 * @return int
	 */
	private static function days_in_month( $ym ) {
		list( $y, $m ) = array_map( 'intval', explode( '-', $ym ) );
		return (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
	}

	/**
	 * "November 2026" from "2026-11".
	 *
	 * @param string $ym Month.
	 * @return string
	 */
	public static function month_label( $ym ) {
		list( $y, $m ) = array_map( 'intval', explode( '-', $ym ) );
		return wp_date( 'F Y', gmmktime( 12, 0, 0, $m, 15, $y ), new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Timestamp from an AWStats YYYYMMDDHHMMSS value (server local time).
	 *
	 * @param string $v Value.
	 * @return int|null
	 */
	private static function awstats_time( $v ) {
		$dt = DateTime::createFromFormat( 'YmdHis', $v, wp_timezone() );
		return $dt ? $dt->getTimestamp() : null;
	}

	/**
	 * Show a notice.
	 *
	 * @param string $message Message.
	 * @param bool   $error   Error style.
	 */
	private static function notice( $message, $error ) {
		printf( '<div class="notice inline notice-%s"><p>%s</p></div>', $error ? 'error' : 'info', esc_html( $message ) );
		if ( current_user_can( 'manage_options' ) ) {
			printf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'options-general.php?page=' . CTS_Settings::PAGE ) ), esc_html__( 'Check the plugin settings', 'cpanel-traffic-stats' ) );
		}
	}

	/**
	 * Message for when there is no data at all.
	 *
	 * @param CTS_Stats $stats Stats.
	 * @return string
	 */
	private static function no_data_message( CTS_Stats $stats ) {
		$ready = $stats->source()->ready();
		if ( is_wp_error( $ready ) ) {
			return $ready->get_error_message();
		}
		/* translators: %s: domain */
		return sprintf( __( 'No traffic stats found yet for %s. Check that the domain is right and that AWStats or Webalizer is turned on in cPanel (Metrics > Metrics Editor). cPanel updates stats about once a day.', 'cpanel-traffic-stats' ), $stats->settings()['domain'] );
	}

	/**
	 * Short label for an HTTP status code.
	 *
	 * @param string|int $code Code.
	 * @return string
	 */
	private static function http_status_label( $code ) {
		$desc = get_status_header_desc( (int) $code );
		return $desc ? $desc : '';
	}

	/**
	 * Country name from an AWStats domain code.
	 *
	 * @param string $code Two-letter code, "ip" or "unknown".
	 * @return string
	 */
	public static function country_label( $code ) {
		$code = strtolower( (string) $code );
		$map  = array(
			'ip'      => __( 'Unresolved IP address', 'cpanel-traffic-stats' ),
			'unknown' => __( 'Unknown', 'cpanel-traffic-stats' ),
			'us'      => 'United States',
			'ca'      => 'Canada',
			'mx'      => 'Mexico',
			'gb'      => 'United Kingdom',
			'uk'      => 'United Kingdom',
			'ie'      => 'Ireland',
			'fr'      => 'France',
			'de'      => 'Germany',
			'nl'      => 'Netherlands',
			'be'      => 'Belgium',
			'es'      => 'Spain',
			'pt'      => 'Portugal',
			'it'      => 'Italy',
			'ch'      => 'Switzerland',
			'at'      => 'Austria',
			'se'      => 'Sweden',
			'no'      => 'Norway',
			'dk'      => 'Denmark',
			'fi'      => 'Finland',
			'pl'      => 'Poland',
			'cz'      => 'Czechia',
			'ro'      => 'Romania',
			'ua'      => 'Ukraine',
			'ru'      => 'Russia',
			'tr'      => 'Turkey',
			'il'      => 'Israel',
			'ae'      => 'United Arab Emirates',
			'sa'      => 'Saudi Arabia',
			'in'      => 'India',
			'pk'      => 'Pakistan',
			'bd'      => 'Bangladesh',
			'cn'      => 'China',
			'hk'      => 'Hong Kong',
			'tw'      => 'Taiwan',
			'jp'      => 'Japan',
			'kr'      => 'South Korea',
			'sg'      => 'Singapore',
			'my'      => 'Malaysia',
			'th'      => 'Thailand',
			'vn'      => 'Vietnam',
			'id'      => 'Indonesia',
			'ph'      => 'Philippines',
			'au'      => 'Australia',
			'nz'      => 'New Zealand',
			'br'      => 'Brazil',
			'ar'      => 'Argentina',
			'cl'      => 'Chile',
			'co'      => 'Colombia',
			'pe'      => 'Peru',
			'za'      => 'South Africa',
			'ng'      => 'Nigeria',
			'ke'      => 'Kenya',
			'eg'      => 'Egypt',
			'ma'      => 'Morocco',
			'com'     => __( 'Commercial (.com)', 'cpanel-traffic-stats' ),
			'net'     => __( 'Network (.net)', 'cpanel-traffic-stats' ),
			'org'     => __( 'Organization (.org)', 'cpanel-traffic-stats' ),
			'edu'     => __( 'Education (.edu)', 'cpanel-traffic-stats' ),
			'gov'     => __( 'US Government (.gov)', 'cpanel-traffic-stats' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : strtoupper( $code );
	}
}
