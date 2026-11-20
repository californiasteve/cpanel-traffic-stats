<?php
/**
 * Scheduled email reports: storage, date rules, sending, and the admin screen.
 *
 * Frequencies:
 *   daily        every day at a time
 *   weekly       on chosen weekdays at a time
 *   monthly_day  on day N of the month (or the last day)
 *   monthly_nth  on the Nth weekday of the month, e.g. the second Saturday or the last Friday
 *
 * A WP-Cron event runs every 15 minutes and sends whatever is due.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email report schedules.
 */
class CTS_Schedules {

	const OPTION = 'cts_schedules';
	const HOOK   = 'cts_report_tick';
	const PAGE   = 'cts-email-reports';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_cts_schedule_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cts_schedule_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_cts_schedule_send', array( __CLASS__, 'handle_send_now' ) );
	}

	/**
	 * Add a 15-minute cron interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_interval( $schedules ) {
		$schedules['cts_quarter_hour'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'cpanel-traffic-stats' ),
		);
		return $schedules;
	}

	/**
	 * Make sure the cron event exists while there are schedules.
	 */
	public static function ensure_cron() {
		$has = (bool) self::all();
		$next = wp_next_scheduled( self::HOOK );
		if ( $has && ! $next ) {
			wp_schedule_event( time() + 60, 'cts_quarter_hour', self::HOOK );
		} elseif ( ! $has && $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	/**
	 * All schedules.
	 *
	 * @return array id => schedule
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Save all schedules.
	 *
	 * @param array $all Schedules.
	 */
	private static function save_all( array $all ) {
		update_option( self::OPTION, $all, false );
		self::ensure_cron();
	}

	/**
	 * Defaults for a new schedule.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'          => '',
			'name'        => __( 'Weekly traffic report', 'cpanel-traffic-stats' ),
			'enabled'     => 1,
			'recipients'  => array( get_option( 'admin_email' ) ),
			'frequency'   => 'weekly',
			'weekdays'    => array( 1 ),
			'month_day'   => '1',
			'nth'         => '2',
			'nth_weekday' => 6,
			'time'        => '08:00',
			'period'      => 'last_7_days',
			'attach'      => array( 'pdf' ),
			'next_run'    => 0,
			'last_run'    => 0,
			'last_status' => '',
		);
	}

	/**
	 * Clean a submitted schedule.
	 *
	 * @param array $in  Raw input.
	 * @param array $old Existing schedule (for id and run history).
	 * @return array|WP_Error
	 */
	public static function sanitize( array $in, array $old = array() ) {
		$s = wp_parse_args( $old, self::defaults() );

		$s['name']    = sanitize_text_field( $in['name'] ?? '' );
		$s['name']    = '' === $s['name'] ? __( 'Traffic report', 'cpanel-traffic-stats' ) : $s['name'];
		$s['enabled'] = empty( $in['enabled'] ) ? 0 : 1;

		$emails = preg_split( '/[\s,;]+/', (string) ( $in['recipients'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		$valid  = array();
		$bad    = array();
		foreach ( $emails as $e ) {
			$clean = sanitize_email( $e );
			if ( $clean && is_email( $clean ) ) {
				$valid[] = $clean;
			} else {
				$bad[] = $e;
			}
		}
		if ( $bad ) {
			/* translators: %s: invalid email addresses */
			return new WP_Error( 'cts_email', sprintf( __( 'These email addresses are not valid: %s', 'cpanel-traffic-stats' ), implode( ', ', $bad ) ) );
		}
		if ( ! $valid ) {
			return new WP_Error( 'cts_email', __( 'Add at least one email address.', 'cpanel-traffic-stats' ) );
		}
		$s['recipients'] = array_values( array_unique( array_slice( $valid, 0, 20 ) ) );

		$s['frequency'] = in_array( $in['frequency'] ?? '', array( 'daily', 'weekly', 'monthly_day', 'monthly_nth' ), true ) ? $in['frequency'] : 'weekly';

		$days = array();
		foreach ( (array) ( $in['weekdays'] ?? array() ) as $d ) {
			if ( is_numeric( $d ) && (int) $d >= 0 && (int) $d <= 6 ) {
				$days[] = (int) $d;
			}
		}
		$days = array_values( array_unique( $days ) );
		sort( $days );
		if ( 'weekly' === $s['frequency'] && ! $days ) {
			return new WP_Error( 'cts_days', __( 'Pick at least one day of the week.', 'cpanel-traffic-stats' ) );
		}
		$s['weekdays'] = $days ? $days : array( 1 );

		$md               = (string) ( $in['month_day'] ?? '1' );
		$s['month_day']   = ( 'last' === $md || ( ctype_digit( $md ) && (int) $md >= 1 && (int) $md <= 31 ) ) ? $md : '1';
		$nth              = (string) ( $in['nth'] ?? '1' );
		$s['nth']         = in_array( $nth, array( '1', '2', '3', '4', 'last' ), true ) ? $nth : '1';
		$nw               = (int) ( $in['nth_weekday'] ?? 6 );
		$s['nth_weekday'] = ( $nw >= 0 && $nw <= 6 ) ? $nw : 6;

		$time      = (string) ( $in['time'] ?? '08:00' );
		$s['time'] = preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ? $time : '08:00';

		$s['period'] = array_key_exists( $in['period'] ?? '', CTS_Report::periods() ) ? $in['period'] : 'last_7_days';

		$attach      = array_intersect( (array) ( $in['attach'] ?? array() ), array( 'pdf', 'csv' ) );
		$s['attach'] = array_values( $attach );

		if ( '' === $s['id'] ) {
			$s['id'] = strtolower( wp_generate_password( 10, false, false ) );
		}
		$s['next_run'] = self::next_run( $s );
		return $s;
	}

	/* ---------------------------------------------------------------------
	 * Date rules
	 * ------------------------------------------------------------------- */

	/**
	 * Next time (Unix timestamp) the schedule should run, strictly after $after.
	 *
	 * @param array                  $s     Schedule.
	 * @param DateTimeImmutable|null $after Defaults to now.
	 * @return int
	 */
	public static function next_run( array $s, $after = null ) {
		$tz    = wp_timezone();
		$after = $after ? $after->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );
		list( $h, $m ) = array_map( 'intval', explode( ':', $s['time'] ) );

		$day = $after->setTime( 0, 0 );
		for ( $i = 0; $i < 400; $i++ ) {
			if ( self::matches( $s, $day ) ) {
				$at = $day->setTime( $h, $m );
				if ( $at > $after ) {
					return $at->getTimestamp();
				}
			}
			$day = $day->modify( '+1 day' );
		}
		return 0;
	}

	/**
	 * Whether a schedule runs on a given date.
	 *
	 * @param array             $s    Schedule.
	 * @param DateTimeImmutable $date Date.
	 * @return bool
	 */
	public static function matches( array $s, DateTimeImmutable $date ) {
		$dow = (int) $date->format( 'w' );
		$dom = (int) $date->format( 'j' );
		$dim = (int) $date->format( 't' );

		switch ( $s['frequency'] ) {
			case 'daily':
				return true;
			case 'weekly':
				return in_array( $dow, array_map( 'intval', (array) $s['weekdays'] ), true );
			case 'monthly_day':
				$target = 'last' === (string) $s['month_day'] ? $dim : min( (int) $s['month_day'], $dim );
				return $dom === $target;
			case 'monthly_nth':
				if ( (int) $s['nth_weekday'] !== $dow ) {
					return false;
				}
				if ( 'last' === (string) $s['nth'] ) {
					return $dom + 7 > $dim;
				}
				return (int) ceil( $dom / 7 ) === (int) $s['nth'];
		}
		return false;
	}

	/**
	 * Plain-language description, e.g. "On the second Saturday of every month at 8:00 am".
	 *
	 * @param array $s Schedule.
	 * @return string
	 */
	public static function describe( array $s ) {
		$names = self::weekday_names();
		$utc   = new DateTimeZone( 'UTC' );
		$at    = DateTimeImmutable::createFromFormat( '!H:i', $s['time'], $utc );
		$time  = $at ? wp_date( get_option( 'time_format', 'g:i a' ), $at->getTimestamp(), $utc ) : $s['time'];
		switch ( $s['frequency'] ) {
			case 'daily':
				/* translators: %s: time */
				return sprintf( __( 'Every day at %s', 'cpanel-traffic-stats' ), $time );
			case 'weekly':
				$days = array();
				foreach ( (array) $s['weekdays'] as $d ) {
					$days[] = $names[ (int) $d ];
				}
				if ( 7 === count( $days ) ) {
					/* translators: %s: time */
					return sprintf( __( 'Every day at %s', 'cpanel-traffic-stats' ), $time );
				}
				/* translators: 1: list of weekdays, 2: time */
				return sprintf( __( 'Every %1$s at %2$s', 'cpanel-traffic-stats' ), self::join_list( $days ), $time );
			case 'monthly_day':
				if ( 'last' === (string) $s['month_day'] ) {
					/* translators: %s: time */
					return sprintf( __( 'On the last day of every month at %s', 'cpanel-traffic-stats' ), $time );
				}
				/* translators: 1: day of month like 1st, 2: time */
				return sprintf( __( 'On the %1$s of every month at %2$s', 'cpanel-traffic-stats' ), self::ordinal_day( (int) $s['month_day'] ), $time );
			case 'monthly_nth':
				$nths = self::nth_names();
				/* translators: 1: first/second/last, 2: weekday, 3: time */
				return sprintf( __( 'On the %1$s %2$s of every month at %3$s', 'cpanel-traffic-stats' ), $nths[ (string) $s['nth'] ], $names[ (int) $s['nth_weekday'] ], $time );
		}
		return '';
	}

	/**
	 * Weekday names indexed 0 (Sunday) to 6.
	 *
	 * @return string[]
	 */
	public static function weekday_names() {
		return array(
			__( 'Sunday', 'cpanel-traffic-stats' ),
			__( 'Monday', 'cpanel-traffic-stats' ),
			__( 'Tuesday', 'cpanel-traffic-stats' ),
			__( 'Wednesday', 'cpanel-traffic-stats' ),
			__( 'Thursday', 'cpanel-traffic-stats' ),
			__( 'Friday', 'cpanel-traffic-stats' ),
			__( 'Saturday', 'cpanel-traffic-stats' ),
		);
	}

	/**
	 * "first" ... "last".
	 *
	 * @return array
	 */
	public static function nth_names() {
		return array(
			'1'    => __( 'first', 'cpanel-traffic-stats' ),
			'2'    => __( 'second', 'cpanel-traffic-stats' ),
			'3'    => __( 'third', 'cpanel-traffic-stats' ),
			'4'    => __( 'fourth', 'cpanel-traffic-stats' ),
			'last' => __( 'last', 'cpanel-traffic-stats' ),
		);
	}

	/**
	 * 1st, 2nd, 3rd, 4th...
	 *
	 * @param int $n Day.
	 * @return string
	 */
	private static function ordinal_day( $n ) {
		$suffix = 'th';
		if ( ! in_array( $n % 100, array( 11, 12, 13 ), true ) ) {
			$suffix = array( 1 => 'st', 2 => 'nd', 3 => 'rd' )[ $n % 10 ] ?? 'th';
		}
		return $n . $suffix;
	}

	/**
	 * "Monday, Wednesday and Friday".
	 *
	 * @param string[] $items Items.
	 * @return string
	 */
	private static function join_list( array $items ) {
		if ( count( $items ) < 2 ) {
			return implode( '', $items );
		}
		$last = array_pop( $items );
		/* translators: 1: comma separated items, 2: last item */
		return sprintf( __( '%1$s and %2$s', 'cpanel-traffic-stats' ), implode( ', ', $items ), $last );
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------- */

	/**
	 * Cron: send every schedule that is due.
	 */
	public static function tick() {
		if ( get_transient( 'cts_tick_lock' ) ) {
			return;
		}
		set_transient( 'cts_tick_lock', 1, 10 * MINUTE_IN_SECONDS );

		$all = self::all();
		$now = time();
		foreach ( $all as $id => $s ) {
			if ( empty( $s['enabled'] ) || empty( $s['next_run'] ) || $s['next_run'] > $now ) {
				continue;
			}
			$result                 = self::send( $s );
			$all[ $id ]['last_run'] = $now;
			$all[ $id ]['last_status'] = is_wp_error( $result ) ? $result->get_error_message() : 'ok';
			// Always schedule from now, so a site that was offline doesn't send a backlog.
			$all[ $id ]['next_run'] = self::next_run( $s );
		}
		update_option( self::OPTION, $all, false );
		delete_transient( 'cts_tick_lock' );
	}

	/**
	 * Build and email one report.
	 *
	 * @param array $s Schedule.
	 * @return true|WP_Error
	 */
	public static function send( array $s ) {
		$stats = new CTS_Stats();
		$range = CTS_Report::range( $s['period'] );
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		$report = CTS_Report::build( $stats, $range[0], $range[1] );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$files = array();
		$dir   = trailingslashit( get_temp_dir() ) . 'cts-' . wp_generate_password( 12, false, false );
		if ( $s['attach'] && wp_mkdir_p( $dir ) ) {
			foreach ( $s['attach'] as $type ) {
				$path = $dir . '/' . CTS_Export::filename( $report, $type );
				$body = 'pdf' === $type ? CTS_Export::pdf( $report ) : CTS_Export::csv( $report );
				if ( false !== file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$files[] = $path;
				}
			}
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: domain, 3: date range */
			__( '[%1$s] Traffic report for %2$s: %3$s', 'cpanel-traffic-stats' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$report['domain'],
			$report['label']
		);
		$sent = wp_mail(
			$s['recipients'],
			$subject,
			CTS_Export::email_html( $report, $s['name'] ),
			array( 'Content-Type: text/html; charset=UTF-8' ),
			$files
		);

		foreach ( $files as $f ) {
			wp_delete_file( $f );
		}
		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		return $sent ? true : new WP_Error( 'cts_mail', __( 'WordPress could not send the email. Check that your site can send mail (an SMTP plugin helps).', 'cpanel-traffic-stats' ) );
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------- */

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_dashboard_page(
			__( 'Email Reports', 'cpanel-traffic-stats' ),
			__( 'Email Reports', 'cpanel-traffic-stats' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Save a schedule.
	 */
	public static function handle_save() {
		self::guard( 'cts_schedule_save' );
		$all = self::all();
		$id  = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$in  = isset( $_POST['schedule'] ) && is_array( $_POST['schedule'] ) ? wp_unslash( $_POST['schedule'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in self::sanitize().
		$s   = self::sanitize( $in, $id && isset( $all[ $id ] ) ? $all[ $id ] : array() );
		if ( is_wp_error( $s ) ) {
			set_transient( 'cts_sched_draft_' . get_current_user_id(), $in, 5 * MINUTE_IN_SECONDS );
			self::redirect( array( 'edit' => $id ? $id : 'new', 'error' => rawurlencode( $s->get_error_message() ) ) );
		}
		$all[ $s['id'] ] = $s;
		self::save_all( $all );
		self::redirect( array( 'saved' => 1 ) );
	}

	/**
	 * Delete a schedule.
	 */
	public static function handle_delete() {
		self::guard( 'cts_schedule_delete' );
		$all = self::all();
		$id  = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		unset( $all[ $id ] );
		self::save_all( $all );
		self::redirect( array( 'deleted' => 1 ) );
	}

	/**
	 * Send a schedule's report right now (doesn't change its next run).
	 */
	public static function handle_send_now() {
		self::guard( 'cts_schedule_send' );
		$all = self::all();
		$id  = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( ! isset( $all[ $id ] ) ) {
			self::redirect( array() );
		}
		$result = self::send( $all[ $id ] );
		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'error' => rawurlencode( $result->get_error_message() ) ) );
		}
		self::redirect( array( 'sent' => rawurlencode( implode( ', ', $all[ $id ]['recipients'] ) ) ) );
	}

	/**
	 * Capability + nonce check.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'cpanel-traffic-stats' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the page.
	 *
	 * @param array $args Query args.
	 */
	private static function redirect( array $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'index.php' ) ) );
		exit;
	}

	/**
	 * Render the Email Reports page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only query args.
		$all     = self::all();
		$edit    = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$error   = isset( $_GET['error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['error'] ) ) ) : '';
		$sent_to = isset( $_GET['sent'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['sent'] ) ) ) : '';
		$saved   = ! empty( $_GET['saved'] );
		$deleted = ! empty( $_GET['deleted'] );
		// phpcs:enable
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap cts-wrap">
			<div class="cts-header">
				<h1><?php esc_html_e( 'Email Reports', 'cpanel-traffic-stats' ); ?></h1>
				<div class="cts-toolbar">
					<a class="button" href="<?php echo esc_url( admin_url( 'index.php?page=' . CTS_Admin::STATS_PAGE ) ); ?>"><?php esc_html_e( 'Traffic Stats', 'cpanel-traffic-stats' ); ?></a>
				</div>
			</div>

			<?php if ( $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php elseif ( $sent_to ) : ?>
				<div class="notice notice-success inline"><p><?php /* translators: %s: email addresses */ printf( esc_html__( 'Report sent to %s.', 'cpanel-traffic-stats' ), esc_html( $sent_to ) ); ?></p></div>
			<?php elseif ( $saved ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Schedule saved.', 'cpanel-traffic-stats' ); ?></p></div>
			<?php elseif ( $deleted ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Schedule deleted.', 'cpanel-traffic-stats' ); ?></p></div>
			<?php endif; ?>

			<?php if ( '' === $edit ) : ?>
				<div class="cts-panel">
					<?php if ( ! $all ) : ?>
						<p><?php esc_html_e( 'No email reports yet. Add one to get your traffic stats sent to you as an email with a PDF or CSV attached.', 'cpanel-traffic-stats' ); ?></p>
					<?php else : ?>
						<div class="cts-scroll">
						<table class="widefat striped cts-table cts-schedules">
							<thead><tr>
								<th scope="col"><?php esc_html_e( 'Report', 'cpanel-traffic-stats' ); ?></th>
								<th scope="col"><?php esc_html_e( 'When', 'cpanel-traffic-stats' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Next email', 'cpanel-traffic-stats' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last sent', 'cpanel-traffic-stats' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'cpanel-traffic-stats' ); ?></span></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $all as $id => $s ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $s['name'] ); ?></strong>
										<?php if ( empty( $s['enabled'] ) ) : ?><span class="cts-badge"><?php esc_html_e( 'Paused', 'cpanel-traffic-stats' ); ?></span><?php endif; ?>
										<div class="cts-sub"><?php echo esc_html( implode( ', ', $s['recipients'] ) ); ?></div>
										<div class="cts-sub">
											<?php
											echo esc_html( CTS_Report::periods()[ $s['period'] ] ?? '' );
											if ( $s['attach'] ) {
												echo ' &middot; ' . esc_html( strtoupper( implode( ' + ', $s['attach'] ) ) );
											}
											?>
										</div>
									</td>
									<td><?php echo esc_html( self::describe( $s ) ); ?></td>
									<td><?php echo ( ! empty( $s['enabled'] ) && $s['next_run'] ) ? esc_html( wp_date( $fmt, $s['next_run'] ) ) : '&mdash;'; ?></td>
									<td>
										<?php
										if ( $s['last_run'] ) {
											echo esc_html( wp_date( $fmt, $s['last_run'] ) );
											if ( 'ok' !== $s['last_status'] ) {
												echo '<div class="cts-sub cts-error">' . esc_html( $s['last_status'] ) . '</div>';
											}
										} else {
											echo '&mdash;';
										}
										?>
									</td>
									<td class="cts-actions">
										<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'edit' => $id ), admin_url( 'index.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'cpanel-traffic-stats' ); ?></a>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="cts_schedule_send" />
											<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
											<?php wp_nonce_field( 'cts_schedule_send' ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Send now', 'cpanel-traffic-stats' ); ?></button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cts-confirm" data-confirm="<?php esc_attr_e( 'Delete this email report?', 'cpanel-traffic-stats' ); ?>">
											<input type="hidden" name="action" value="cts_schedule_delete" />
											<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
											<?php wp_nonce_field( 'cts_schedule_delete' ); ?>
											<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'cpanel-traffic-stats' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
					<p><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'edit' => 'new' ), admin_url( 'index.php' ) ) ); ?>"><?php esc_html_e( 'Add email report', 'cpanel-traffic-stats' ); ?></a></p>
				</div>
				<p class="description"><?php esc_html_e( 'Emails are sent by WordPress\'s scheduler, which runs when someone visits the site. On quiet sites an email can arrive a little late; setting up a real cron job in cPanel for wp-cron.php makes it punctual. Times use the site time zone from Settings > General.', 'cpanel-traffic-stats' ); ?></p>
			<?php else : ?>
				<?php self::render_form( 'new' === $edit ? array() : ( $all[ $edit ] ?? array() ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Add / edit form.
	 *
	 * @param array $existing Schedule being edited, or empty for a new one.
	 */
	private static function render_form( array $existing ) {
		$s     = wp_parse_args( $existing, self::defaults() );
		$draft = get_transient( 'cts_sched_draft_' . get_current_user_id() );
		if ( is_array( $draft ) ) {
			delete_transient( 'cts_sched_draft_' . get_current_user_id() );
			$s = array_merge( $s, $draft );
			if ( is_string( $s['recipients'] ) ) {
				$s['recipients'] = array( $s['recipients'] );
			}
		}
		$names = self::weekday_names();
		$n     = 'schedule';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cts-panel cts-schedule-form">
			<input type="hidden" name="action" value="cts_schedule_save" />
			<input type="hidden" name="id" value="<?php echo esc_attr( $existing['id'] ?? '' ); ?>" />
			<?php wp_nonce_field( 'cts_schedule_save' ); ?>
			<h2><?php echo $existing ? esc_html__( 'Edit email report', 'cpanel-traffic-stats' ) : esc_html__( 'New email report', 'cpanel-traffic-stats' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cts-s-name"><?php esc_html_e( 'Name', 'cpanel-traffic-stats' ); ?></label></th>
					<td><input type="text" id="cts-s-name" class="regular-text" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $s['name'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cts-s-to"><?php esc_html_e( 'Send to', 'cpanel-traffic-stats' ); ?></label></th>
					<td>
						<input type="text" id="cts-s-to" class="large-text" name="<?php echo esc_attr( $n ); ?>[recipients]" value="<?php echo esc_attr( implode( ', ', (array) $s['recipients'] ) ); ?>" />
						<p class="description"><?php esc_html_e( 'One or more email addresses, separated by commas.', 'cpanel-traffic-stats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cts-s-freq"><?php esc_html_e( 'How often', 'cpanel-traffic-stats' ); ?></label></th>
					<td>
						<select id="cts-s-freq" name="<?php echo esc_attr( $n ); ?>[frequency]" data-cts-frequency>
							<option value="daily" <?php selected( $s['frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'cpanel-traffic-stats' ); ?></option>
							<option value="weekly" <?php selected( $s['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly, on certain days', 'cpanel-traffic-stats' ); ?></option>
							<option value="monthly_day" <?php selected( $s['frequency'], 'monthly_day' ); ?>><?php esc_html_e( 'Monthly, on a date (e.g. the 1st)', 'cpanel-traffic-stats' ); ?></option>
							<option value="monthly_nth" <?php selected( $s['frequency'], 'monthly_nth' ); ?>><?php esc_html_e( 'Monthly, on a weekday (e.g. the second Saturday)', 'cpanel-traffic-stats' ); ?></option>
						</select>

						<fieldset class="cts-freq-opt" data-cts-for="weekly">
							<legend class="screen-reader-text"><?php esc_html_e( 'Days of the week', 'cpanel-traffic-stats' ); ?></legend>
							<?php foreach ( $names as $i => $label ) : ?>
								<label class="cts-day"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[weekdays][]" value="<?php echo esc_attr( $i ); ?>" <?php checked( in_array( $i, array_map( 'intval', (array) $s['weekdays'] ), true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>

						<div class="cts-freq-opt" data-cts-for="monthly_day">
							<label for="cts-s-md"><?php esc_html_e( 'On day', 'cpanel-traffic-stats' ); ?></label>
							<select id="cts-s-md" name="<?php echo esc_attr( $n ); ?>[month_day]">
								<?php for ( $d = 1; $d <= 31; $d++ ) : ?>
									<option value="<?php echo esc_attr( $d ); ?>" <?php selected( (string) $s['month_day'], (string) $d ); ?>><?php echo esc_html( self::ordinal_day( $d ) ); ?></option>
								<?php endfor; ?>
								<option value="last" <?php selected( (string) $s['month_day'], 'last' ); ?>><?php esc_html_e( 'Last day', 'cpanel-traffic-stats' ); ?></option>
							</select>
							<span class="description"><?php esc_html_e( 'In shorter months, days 29 to 31 move to the last day.', 'cpanel-traffic-stats' ); ?></span>
						</div>

						<div class="cts-freq-opt" data-cts-for="monthly_nth">
							<label for="cts-s-nth"><?php esc_html_e( 'On the', 'cpanel-traffic-stats' ); ?></label>
							<select id="cts-s-nth" name="<?php echo esc_attr( $n ); ?>[nth]">
								<?php foreach ( self::nth_names() as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) $s['nth'], (string) $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<label for="cts-s-nwd" class="screen-reader-text"><?php esc_html_e( 'Weekday', 'cpanel-traffic-stats' ); ?></label>
							<select id="cts-s-nwd" name="<?php echo esc_attr( $n ); ?>[nth_weekday]">
								<?php foreach ( $names as $i => $label ) : ?>
									<option value="<?php echo esc_attr( $i ); ?>" <?php selected( (int) $s['nth_weekday'], $i ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php esc_html_e( 'of every month', 'cpanel-traffic-stats' ); ?>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cts-s-time"><?php esc_html_e( 'Time', 'cpanel-traffic-stats' ); ?></label></th>
					<td>
						<input type="time" id="cts-s-time" name="<?php echo esc_attr( $n ); ?>[time]" value="<?php echo esc_attr( $s['time'] ); ?>" step="900" />
						<span class="description">
							<?php
							/* translators: %s: time zone */
							printf( esc_html__( 'Site time zone: %s', 'cpanel-traffic-stats' ), esc_html( wp_timezone_string() ) );
							?>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cts-s-period"><?php esc_html_e( 'Report covers', 'cpanel-traffic-stats' ); ?></label></th>
					<td>
						<select id="cts-s-period" name="<?php echo esc_attr( $n ); ?>[period]" data-cts-period>
							<?php foreach ( CTS_Report::periods() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['period'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'cPanel updates stats about once a day, usually overnight, so "Yesterday" works best with a morning send time.', 'cpanel-traffic-stats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Attach', 'cpanel-traffic-stats' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[attach][]" value="pdf" <?php checked( in_array( 'pdf', (array) $s['attach'], true ) ); ?> /> <?php esc_html_e( 'PDF report', 'cpanel-traffic-stats' ); ?></label><br />
						<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[attach][]" value="csv" <?php checked( in_array( 'csv', (array) $s['attach'], true ) ); ?> /> <?php esc_html_e( 'CSV spreadsheet', 'cpanel-traffic-stats' ); ?></label>
						<p class="description"><?php esc_html_e( 'The email itself always includes a summary and the top pages, referrers and search engines.', 'cpanel-traffic-stats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'cpanel-traffic-stats' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> /> <?php esc_html_e( 'Active (untick to pause)', 'cpanel-traffic-stats' ); ?></label></td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save email report', 'cpanel-traffic-stats' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'index.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Cancel', 'cpanel-traffic-stats' ); ?></a>
			</p>
		</form>
		<?php
	}
}
