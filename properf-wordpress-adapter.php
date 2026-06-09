<?php
/**
 * Plugin Name: ProPerf WordPress Adapter
 * Plugin URI: https://coloredcow.com
 * Description: Collects and displays database health metrics (autoloaded options)
 * Version: 1.0.2
 * Author: ColoredCow
 * License: GPL v2 or later
 *
 * @package ProPerf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PROPERF_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROPERF_URL', plugin_dir_url( __FILE__ ) );
define( 'PROPERF_VERSION', '1.0.2' );

require_once PROPERF_DIR . 'includes/class-data-collector.php';
require_once PROPERF_DIR . 'includes/class-live-data.php';
require_once PROPERF_DIR . 'includes/class-admin-settings.php';
require_once PROPERF_DIR . 'includes/class-admin-dashboard.php';

/**
 * Plugin activation: ensure cron is scheduled.
 */
function properf_activate_plugin() {
	properf_schedule_metrics_collection();
}
register_activation_hook( __FILE__, 'properf_activate_plugin' );

/**
 * Plugin deactivation: clean up cron.
 */
function properf_deactivate_plugin() {
	$timestamp = wp_next_scheduled( 'properf_collect_metrics' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'properf_collect_metrics' );
	}
}
register_deactivation_hook( __FILE__, 'properf_deactivate_plugin' );

/**
 * Initialize plugin — wire admin dashboard and settings.
 */
function properf_init() {
	if ( is_admin() ) {
		ProPerf_Admin_Dashboard::init();
		ProPerf_Admin_Settings::init();
	}
}
add_action( 'plugins_loaded', 'properf_init' );

/**
 * Schedule metrics collection if not already scheduled.
 */
function properf_schedule_metrics_collection() {
	if ( false === wp_next_scheduled( 'properf_collect_metrics' ) ) {
		$time = properf_get_next_midnight();
		wp_schedule_event( $time, 'daily', 'properf_collect_metrics' );
	}
}
add_action( 'wp', 'properf_schedule_metrics_collection' );

/**
 * Handle scheduled metrics collection and push to BigQuery.
 */
function properf_collect_and_push_metrics() {
	require_once PROPERF_DIR . 'includes/class-bigquery-client.php';

	$collector = new ProPerf_Data_Collector();
	$metrics   = $collector->get_data();
	$collector->record_qet_reading( $metrics['woo']['query_execution_ms'] );
	$bq_client = new ProPerf_BigQuery_Client();

	$success = $bq_client->push_metrics( $metrics );

	update_option( 'properf_bq_last_sync', time(), false );
	update_option( 'properf_bq_last_sync_status', $success ? 'success' : 'error', false );

	if ( $success ) {
		delete_option( 'properf_bq_last_sync_error' );
		error_log(
			'ProPerf: Metrics successfully pushed to BigQuery at ' . gmdate( 'Y-m-d H:i:s' )
		);
	} else {
		$error_message = $bq_client->get_last_error();
		update_option( 'properf_bq_last_sync_error', $error_message, false );

		error_log(
			'ProPerf Error: Failed to push metrics to BigQuery - ' . $error_message
		);
	}
}
add_action( 'properf_collect_metrics', 'properf_collect_and_push_metrics' );

/**
 * Get next midnight timestamp in site timezone.
 *
 * @return int Timestamp for next midnight (00:00:00).
 */
function properf_get_next_midnight() {
	$tz = get_option( 'timezone_string' );

	if ( empty( $tz ) ) {
		$gmt_offset = get_option( 'gmt_offset' );
		$tz         = timezone_name_from_abbr( '', (float) $gmt_offset * 3600, false );
		if ( false === $tz ) {
			$tz = 'UTC';
		}
	}

	$target_tz      = new DateTimeZone( $tz );
	$now            = new DateTime( 'now', $target_tz );
	$today_midnight = new DateTime( '00:00:00', $target_tz );

	if ( $today_midnight <= $now ) {
		$today_midnight->add( new DateInterval( 'P1D' ) );
	}
	return $today_midnight->getTimestamp();
}
