<?php
/**
 * Plugin Name: ProPerf WordPress Adapter
 * Plugin URI: https://coloredcow.com
 * Description: Collects and displays database health metrics (autoloaded options)
 * Version: 1.0.0
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
define( 'PROPERF_VERSION', '1.0.0' );

/**
 * Load environment variables from .env file.
 */
function properf_load_env() {
	$env_file = ABSPATH . '.env';

	if ( file_exists( $env_file ) ) {
		$lines = file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		foreach ( $lines as $line ) {
			if ( 0 === strpos( trim( $line ), '#' ) ) {
				continue;
			}
			if ( false !== strpos( $line, '=' ) ) {
				list( $name, $value ) = explode( '=', $line, 2 );
				$name  = trim( $name );
				$value = trim( $value, " \t\n\r\0\x0B\"'" );
				putenv( "{$name}={$value}" );
				$_ENV[ $name ] = $value;
			}
		}
	}
}
properf_load_env();

require_once PROPERF_DIR . 'includes/class-data-collector.php';

/**
 * Initialize plugin.
 */
function properf_init() {
	add_action( 'admin_menu', 'properf_add_admin_menu' );
}
add_action( 'plugins_loaded', 'properf_init' );

/**
 * Handle BigQuery push on admin init.
 */
function properf_handle_bigquery_push() {
	if ( isset( $_POST['properf_push_to_bq'] ) && check_admin_referer( 'properf_push_action', 'properf_push_nonce' ) ) {
		require_once PROPERF_DIR . 'includes/class-bigquery-client.php';

		$collector = new ProPerf_Data_Collector();
		$bq_client = new ProPerf_BigQuery_Client();

		if ( $bq_client->push_metrics( $collector->get_data() ) ) {
			add_settings_error( 'properf_messages', 'properf_msg', 'Data successfully pushed to BigQuery!', 'updated' );
		} else {
			$error_message = $bq_client->get_last_error();
			add_settings_error( 'properf_messages', 'properf_msg', 'Failed: ' . $error_message, 'error' );
		}
	}
}
add_action( 'admin_init', 'properf_handle_bigquery_push' );

/**
 * Schedule metrics collection if not already scheduled.
 */
function properf_schedule_metrics_collection() {
	if ( ! wp_next_scheduled( 'properf_collect_metrics' ) ) {
		$time = properf_get_next_5pm();
		wp_schedule_event( $time, 'daily', 'properf_collect_metrics' );
	}
}
add_action( 'wp', 'properf_schedule_metrics_collection' );

/**
 * Get next 5pm timestamp in site timezone.
 *
 * @return int Timestamp for next 5pm.
 */
function properf_get_next_5pm() {
	$tz = get_option( 'timezone_string' ) ?: 'UTC';

	if ( empty( $tz ) ) {
		$gmt_offset = get_option( 'gmt_offset' );
		$tz         = timezone_name_from_abbr( '', $gmt_offset * 3600, false );
		if ( false === $tz ) {
			$tz = 'UTC';
		}
	}

	$target_tz  = new DateTimeZone( $tz );
	$now        = new DateTime( 'now', $target_tz );
	$today_5pm  = new DateTime( '17:00:00', $target_tz );

	if ( $today_5pm <= $now ) {
		$today_5pm->add( new DateInterval( 'P1D' ) );
	}
	return $today_5pm->getTimestamp();
}

/**
 * Add admin menu items.
 */
function properf_add_admin_menu() {
	// Add top-level menu.
	add_menu_page(
		__( 'ProPerf Dashboard', 'properf' ),
		'ProPerf',
		'manage_options',
		'properf',
		'properf_render_dashboard',
		'dashicons-chart-line',
		30
	);

	// Add Dashboard submenu (first submenu item replaces the parent menu title).
	add_submenu_page(
		'properf',
		__( 'ProPerf Dashboard', 'properf' ),
		__( 'Dashboard', 'properf' ),
		'manage_options',
		'properf',
		'properf_render_dashboard'
	);

	// Add Settings submenu.
	add_submenu_page(
		'properf',
		__( 'ProPerf Settings', 'properf' ),
		__( 'Settings', 'properf' ),
		'manage_options',
		'properf-settings',
		'properf_render_settings'
	);
}

/**
 * Render Dashboard page.
 */
function properf_render_dashboard() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	$metrics                = properf_get_live_data();
	$autoloaded_data_metrics = $metrics['autoloaded_option'];

	$count         = $autoloaded_data_metrics['count'];
	$size_bytes    = $autoloaded_data_metrics['size_bytes'];
	$top_size_keys = $autoloaded_data_metrics['top_size_keys'];

	$last_sync = get_option( 'properf_bq_last_sync' );

	if ( $last_sync ) {
		$format     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$sync_time  = wp_date( $format, $last_sync );
		$tz_string  = get_option( 'timezone_string' );

		if ( $tz_string ) {
			$tz_display = $tz_string;
		} else {
			$gmt_offset = get_option( 'gmt_offset' );
			$sign       = ( $gmt_offset < 0 ) ? '-' : '+';
			$hours      = (int) abs( $gmt_offset );
			$minutes    = ( abs( $gmt_offset ) * 60 ) % 60;

			if ( 0 === $minutes ) {
				$tz_display = sprintf( 'UTC%s%d', $sign, $hours );
			} else {
				$tz_display = sprintf( 'UTC%s%d:%02d', $sign, $hours, $minutes );
			}
		}

		$last_pushed_display = $sync_time . ' (' . $tz_display . ')';
	} else {
		$last_pushed_display = 'Never';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'ProPerf WordPress Metrics', 'properf' ); ?></h1>

		<p><strong>Last pushed to BigQuery:</strong> <?php echo esc_html( $last_pushed_display ); ?></p>

		<?php settings_errors( 'properf_messages' ); ?>

		<form method="post" style="margin-bottom: 20px;">
			<?php wp_nonce_field( 'properf_push_action', 'properf_push_nonce' ); ?>
			<input type="submit" name="properf_push_to_bq" class="button button-primary" value="Push to BigQuery">
		</form>

		<h2><?php esc_html_e( 'Summary Metrics', 'properf' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Metric', 'properf' ); ?></th>
					<th><?php esc_html_e( 'Value', 'properf' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Autoloaded Option Count', 'properf' ); ?></strong></td>
					<td><?php echo esc_html( number_format( $count ) ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Autoloaded Option Size', 'properf' ); ?></strong></td>
					<td><?php printf( '%.2f KB', $size_bytes / 1024 ); ?></td>
				</tr>
			</tbody>
		</table>

		<h2 style="margin-top: 30px;"><?php esc_html_e( 'Top 10 Autoloaded Options by Size', 'properf' ); ?></h2>
		<?php if ( ! empty( $top_size_keys ) ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><strong><?php esc_html_e( 'Option Name', 'properf' ); ?></strong></th>
						<th><strong><?php esc_html_e( 'Size', 'properf' ); ?></strong></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $top_size_keys as $key => $size ) : ?>
						<tr>
							<td><?php echo esc_html( $key ); ?></td>
							<td><?php printf( '%.2f KB', $size / 1024 ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No autoloaded option keys found or an error occurred.', 'properf' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render Settings page.
 */
function properf_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'ProPerf Settings', 'properf' ); ?></h1>
		<p><?php esc_html_e( 'Configure ProPerf plugin settings here.', 'properf' ); ?></p>
		<!-- Settings form will be added here -->
	</div>
	<?php
}

/**
 * Get live data from collector.
 *
 * @return array Metrics data.
 */
function properf_get_live_data() {
	if ( ! class_exists( 'ProPerf_Data_Collector' ) ) {
		return array(
			'autoloaded_option' => array(
				'count'         => 'Error: Collector Class Missing',
				'size_bytes'    => 0,
				'top_size_keys' => array(),
			),
		);
	}

	try {
		$collector = new ProPerf_Data_Collector();
		return $collector->get_data();
	} catch ( Exception $e ) {
		error_log( 'ProPerf Error: ' . $e->getMessage() );
		return array(
			'autoloaded_option' => array(
				'count'         => 'Error: ' . $e->getMessage(),
				'size_bytes'    => 0,
				'top_size_keys' => array(),
			),
		);
	}
}
