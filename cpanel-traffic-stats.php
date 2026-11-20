<?php
/**
 * Plugin Name:       cPanel Traffic Stats
 * Plugin URI:        https://github.com/californiasteve/cpanel-traffic-stats
 * Description:       Shows your website traffic from cPanel's AWStats and Webalizer right inside the WordPress dashboard. Reads the stats files directly on the same server, or remotely through a cPanel API token. Download reports as PDF or CSV, or have them emailed on a schedule.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            California Steve Web Design
 * Author URI:        https://californiastevewebdesign.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cpanel-traffic-stats
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CTS_VERSION', '1.2.0' );
define( 'CTS_FILE', __FILE__ );
define( 'CTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTS_URL', plugin_dir_url( __FILE__ ) );

require_once CTS_DIR . 'includes/class-cts-awstats-parser.php';
require_once CTS_DIR . 'includes/class-cts-webalizer-parser.php';
require_once CTS_DIR . 'includes/class-cts-settings.php';
require_once CTS_DIR . 'includes/class-cts-source.php';
require_once CTS_DIR . 'includes/class-cts-stats.php';
require_once CTS_DIR . 'includes/class-cts-admin.php';
require_once CTS_DIR . 'includes/class-cts-report.php';
require_once CTS_DIR . 'includes/class-cts-pdf.php';
require_once CTS_DIR . 'includes/class-cts-export.php';
require_once CTS_DIR . 'includes/class-cts-schedules.php';

add_action(
	'plugins_loaded',
	static function () {
		CTS_Settings::init();
		CTS_Admin::init();
		CTS_Schedules::init();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( false === get_option( CTS_Settings::OPTION ) ) {
			add_option( CTS_Settings::OPTION, CTS_Settings::defaults(), '', false );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		CTS_Stats::clear_cache();
		wp_clear_scheduled_hook( 'cts_report_tick' );
	}
);
