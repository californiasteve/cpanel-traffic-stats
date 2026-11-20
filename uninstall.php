<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package CpanelTrafficStats
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cts_settings' );
delete_option( 'cts_cache_gen' );
delete_option( 'cts_schedules' );
wp_clear_scheduled_hook( 'cts_report_tick' );
// Cached stats are transients and expire on their own.
