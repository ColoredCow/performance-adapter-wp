<?php
/**
 * Plugin Name: ProPerf WordPress Adapter
 * Plugin URI: https://coloredcow.com
 * Description: Collects and displays database health metrics (autoloaded options)
 * Version: 1.0.1
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

require_once PROPERF_DIR . 'includes/class-data-collector.php';

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
 * Initialize plugin.
 */
function properf_init() {
	add_action( 'admin_menu', 'properf_add_admin_menu' );
	add_action( 'admin_init', 'properf_register_settings', 20 );
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

		$success = $bq_client->push_metrics( $collector->get_data() );

		// Persist execution info (shared with cron)
		update_option( 'properf_bq_last_sync', time(), false );
		update_option( 'properf_bq_last_sync_status', $success ? 'success' : 'error', false );

		if ( $success ) {
			delete_option( 'properf_bq_last_sync_error' );
			add_settings_error(
				'properf_messages',
				'properf_msg',
				'Data successfully pushed to BigQuery!',
				'updated'
			);
		} else {
			$error_message = $bq_client->get_last_error();
			update_option( 'properf_bq_last_sync_error', $error_message, false );

			add_settings_error(
				'properf_messages',
				'properf_msg',
				'Failed: ' . $error_message,
				'error'
			);
		}
	}

	// Show success message when settings are saved.
	if (
		isset( $_GET['settings-updated'] ) &&
		isset( $_GET['page'] ) &&
		'properf-settings' === $_GET['page']
	) {
		add_settings_error(
			'properf_messages',
			'properf_settings_saved',
			__( 'Settings saved successfully.', 'properf' ),
			'updated'
		);
	}
}
add_action( 'admin_init', 'properf_handle_bigquery_push' );


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
	$bq_client = new ProPerf_BigQuery_Client();

	$success = $bq_client->push_metrics( $collector->get_data() );

	// Persist cron execution info.
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
	$tz = $tz ? $tz : 'UTC';

	if ( empty( $tz ) ) {
		$gmt_offset = get_option( 'gmt_offset' );
		$tz         = timezone_name_from_abbr( '', $gmt_offset * 3600, false );
		if ( false === $tz ) {
			$tz = 'UTC';
		}
	}

	$target_tz    = new DateTimeZone( $tz );
	$now          = new DateTime( 'now', $target_tz );
	$today_midnight = new DateTime( '00:00:00', $target_tz );

	if ( $today_midnight <= $now ) {
		$today_midnight->add( new DateInterval( 'P1D' ) );
	}
	return $today_midnight->getTimestamp();
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

	$metrics                 = properf_get_live_data();
	$autoloaded_data_metrics = $metrics['autoloaded_option'];
	$plugin_metrics          = $metrics['plugins'];
	$hook_metrics            = $metrics['hooks'];
	$db_metrics              = $metrics['database'];
	$woo_metrics             = $metrics['woo_orders'];

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
					<th><?php esc_html_e( 'Target', 'properf' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Autoloaded Option Count', 'properf' ); ?></strong></td>
					<td><?php echo esc_html( number_format( $count ) ); ?></td>
					<td>—</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Autoloaded Option Size', 'properf' ); ?></strong></td>
					<td><?php printf( '%.2f KB', $size_bytes / 1024 ); ?></td>
					<td>&lt; 500 KB</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Active Plugins', 'properf' ); ?></strong></td>
					<td><?php echo esc_html( number_format( $plugin_metrics['active_count'] ) ); ?></td>
					<td>&lt; 15</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Inactive Plugins', 'properf' ); ?></strong></td>
					<td><?php echo esc_html( number_format( $plugin_metrics['inactive_count'] ) ); ?></td>
					<td>0</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Registered Hooks', 'properf' ); ?></strong></td>
					<td><?php echo esc_html( number_format( $hook_metrics['registered_count'] ) ); ?></td>
					<td>&lt; 50,000</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Total Database Size', 'properf' ); ?></strong></td>
					<td><?php printf( '%.2f MB', $db_metrics['total_size_bytes'] / 1024 / 1024 ); ?></td>
					<td>&lt; 10 GB</td>
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

		<h2 style="margin-top: 30px;"><?php esc_html_e( 'Top 10 Database Tables by Size', 'properf' ); ?></h2>
		<?php if ( ! empty( $db_metrics['top_tables'] ) ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><strong><?php esc_html_e( 'Table Name', 'properf' ); ?></strong></th>
						<th><strong><?php esc_html_e( 'Size', 'properf' ); ?></strong></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $db_metrics['top_tables'] as $table_name => $table_size ) : ?>
						<tr>
							<td><?php echo esc_html( $table_name ); ?></td>
							<td><?php printf( '%.2f MB', $table_size / 1024 / 1024 ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No database table data found or an error occurred.', 'properf' ); ?></p>
		<?php endif; ?>

		<h2 style="margin-top: 30px;"><?php esc_html_e( 'WooCommerce Order Metrics', 'properf' ); ?></h2>
		<?php if ( ! $woo_metrics['woo_active'] ) : ?>
			<p><?php esc_html_e( 'WooCommerce is not active on this site.', 'properf' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Metric', 'properf' ); ?></th>
						<th><?php esc_html_e( 'Value', 'properf' ); ?></th>
						<th><?php esc_html_e( 'Target', 'properf' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Order Items Table Size', 'properf' ); ?></strong></td>
						<td><?php printf( '%.2f MB', $woo_metrics['order_items_size_bytes'] / 1024 / 1024 ); ?></td>
						<td>—</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Order Itemmeta Table Size', 'properf' ); ?></strong></td>
						<td><?php printf( '%.2f MB', $woo_metrics['order_itemmeta_size_bytes'] / 1024 / 1024 ); ?></td>
						<td>—</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Oldest Order Age', 'properf' ); ?></strong></td>
						<td><?php echo esc_html( number_format( $woo_metrics['oldest_order_age_days'] ) ) . ' days'; ?></td>
						<td>&lt; 365 days</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Archival Trigger', 'properf' ); ?></strong></td>
						<td>
							<?php if ( $woo_metrics['archival_trigger'] ) : ?>
								<span style="color: #d63638; font-weight: bold;"><?php esc_html_e( 'Yes — orders older than 1 year detected', 'properf' ); ?></span>
							<?php else : ?>
								<span style="color: #00a32a;"><?php esc_html_e( 'No', 'properf' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php esc_html_e( 'No', 'properf' ); ?></td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
<?php
}

/**
 * Register plugin settings.
 */
function properf_register_settings() {
	register_setting(
		'properf_bigquery_settings',
		'properf_bigquery_project_id',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	register_setting(
		'properf_bigquery_settings',
		'properf_bigquery_dataset_id',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	register_setting(
		'properf_bigquery_settings',
		'properf_bigquery_table_id',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	register_setting(
		'properf_bigquery_settings',
		'properf_bigquery_client_email',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => '',
		)
	);

	register_setting(
		'properf_bigquery_settings',
		'properf_bigquery_private_key',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'properf_sanitize_private_key',
			'default'           => '',
		)
	);

	add_settings_section(
		'properf_bigquery_section',
		__( 'BigQuery Configuration', 'properf' ),
		'properf_bigquery_section_callback',
		'properf-settings'
	);

	add_settings_field(
		'properf_bigquery_project_id',
		__( 'Project ID', 'properf' ),
		'properf_bigquery_project_id_field',
		'properf-settings',
		'properf_bigquery_section'
	);

	add_settings_field(
		'properf_bigquery_dataset_id',
		__( 'Dataset ID', 'properf' ),
		'properf_bigquery_dataset_id_field',
		'properf-settings',
		'properf_bigquery_section'
	);

	add_settings_field(
		'properf_bigquery_table_id',
		__( 'Table ID', 'properf' ),
		'properf_bigquery_table_id_field',
		'properf-settings',
		'properf_bigquery_section'
	);

	add_settings_field(
		'properf_bigquery_client_email',
		__( 'Client Email', 'properf' ),
		'properf_bigquery_client_email_field',
		'properf-settings',
		'properf_bigquery_section'
	);

	add_settings_field(
		'properf_bigquery_private_key',
		__( 'Private Key', 'properf' ),
		'properf_bigquery_private_key_field',
		'properf-settings',
		'properf_bigquery_section'
	);
}

/**
 * Sanitize private key field.
 *
 * @param string $value Private key value.
 * @return string Sanitized private key.
 */
function properf_sanitize_private_key( $value ) {
	// Remove any potential malicious content but preserve the key structure.
	return wp_strip_all_tags( $value );
}

/**
 * BigQuery section callback.
 */
function properf_bigquery_section_callback() {
	echo '<p>' . esc_html__( 'Configure your Google Cloud BigQuery credentials. These settings are required for pushing metrics to BigQuery.', 'properf' ) . '</p>';
}

/**
 * Project ID field.
 */
function properf_bigquery_project_id_field() {
	$value = get_option( 'properf_bigquery_project_id', '' );
	?>
	<input type="text" name="properf_bigquery_project_id" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
	<p class="description"><?php esc_html_e( 'Your Google Cloud Project ID.', 'properf' ); ?></p>
	<?php
}

/**
 * Dataset ID field.
 */
function properf_bigquery_dataset_id_field() {
	$value = get_option( 'properf_bigquery_dataset_id', '' );
	?>
	<input type="text" name="properf_bigquery_dataset_id" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
	<p class="description"><?php esc_html_e( 'The BigQuery dataset ID where metrics will be stored.', 'properf' ); ?></p>
	<?php
}

/**
 * Table ID field.
 */
function properf_bigquery_table_id_field() {
	$value = get_option( 'properf_bigquery_table_id', '' );
	?>
	<input type="text" name="properf_bigquery_table_id" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
	<p class="description"><?php esc_html_e( 'The BigQuery table ID where metrics will be stored.', 'properf' ); ?></p>
	<?php
}

/**
 * Client Email field.
 */
function properf_bigquery_client_email_field() {
	$value = get_option( 'properf_bigquery_client_email', '' );
	?>
	<input type="email" name="properf_bigquery_client_email" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
	<p class="description"><?php esc_html_e( 'The service account email address from your Google Cloud service account JSON key.', 'properf' ); ?></p>
	<?php
}

/**
 * Private Key field.
 */
function properf_bigquery_private_key_field() {
	$value = get_option( 'properf_bigquery_private_key', '' );
	?>
	<textarea name="properf_bigquery_private_key" rows="5" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
	<p class="description"><?php esc_html_e( 'The private key from your Google Cloud service account JSON key file. Include the full key including BEGIN and END markers.', 'properf' ); ?></p>
	<?php
}

/**
 * Render Settings page.
 */
function properf_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	// Ensure settings are registered.
	global $wp_settings_sections;
	if ( ! isset( $wp_settings_sections['properf-settings'] ) ) {
		properf_register_settings();
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'ProPerf Settings', 'properf' ); ?></h1>
		<?php settings_errors( 'properf_messages' ); ?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'properf_bigquery_settings' );
			?>
			<?php properf_bigquery_section_callback(); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="properf_bigquery_project_id"><?php esc_html_e( 'Project ID', 'properf' ); ?></label>
						</th>
						<td>
							<?php properf_bigquery_project_id_field(); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="properf_bigquery_dataset_id"><?php esc_html_e( 'Dataset ID', 'properf' ); ?></label>
						</th>
						<td>
							<?php properf_bigquery_dataset_id_field(); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="properf_bigquery_table_id"><?php esc_html_e( 'Table ID', 'properf' ); ?></label>
						</th>
						<td>
							<?php properf_bigquery_table_id_field(); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="properf_bigquery_client_email"><?php esc_html_e( 'Client Email', 'properf' ); ?></label>
						</th>
						<td>
							<?php properf_bigquery_client_email_field(); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="properf_bigquery_private_key"><?php esc_html_e( 'Private Key', 'properf' ); ?></label>
						</th>
						<td>
							<?php properf_bigquery_private_key_field(); ?>
						</td>
					</tr>
				</tbody>
			</table>
			<?php
			submit_button( __( 'Save Settings', 'properf' ) );
			?>
		</form>
	</div>
	<?php
}

/**
 * Add page parameter to settings form redirect.
 *
 * @param string $location Redirect location.
 * @return string Modified redirect location.
 */
function properf_settings_redirect( $location ) {
	if ( isset( $_POST['option_page'] ) && 'properf_bigquery_settings' === $_POST['option_page'] ) {
		$location = add_query_arg( 'page', 'properf-settings', $location );
	}
	return $location;
}
add_filter( 'wp_redirect', 'properf_settings_redirect' );

/**
 * Get live data from collector.
 *
 * @return array Metrics data.
 */
function properf_get_live_data() {
	$empty_response = array(
		'autoloaded_option' => array(
			'count'         => 0,
			'size_bytes'    => 0,
			'top_size_keys' => array(),
		),
		'plugins'           => array(
			'active_count'   => 0,
			'inactive_count' => 0,
			'total_count'    => 0,
		),
		'hooks'             => array(
			'registered_count' => 0,
		),
		'database'          => array(
			'total_size_bytes' => 0,
			'top_tables'       => array(),
		),
	);

	if ( ! class_exists( 'ProPerf_Data_Collector' ) ) {
		return $empty_response;
	}

	try {
		$collector = new ProPerf_Data_Collector();
		return $collector->get_data();
	} catch ( Exception $e ) {
		error_log( 'ProPerf Error: ' . $e->getMessage() );
		return $empty_response;
	}
}
