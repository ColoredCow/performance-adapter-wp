<?php
/**
 * Admin Dashboard Class
 *
 * @package ProPerf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProPerf_Admin_Dashboard
 */
class ProPerf_Admin_Dashboard {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_bigquery_push' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue admin stylesheet on ProPerf pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_properf' !== $hook && 'properf_page_properf-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'properf-admin', PROPERF_URL . 'assets/css/admin.css', array(), PROPERF_VERSION );
	}

	/**
	 * Add admin menu and submenu pages.
	 */
	public static function add_admin_menu() {
		add_menu_page(
			__( 'ProPerf Dashboard', 'properf' ),
			'ProPerf',
			'manage_options',
			'properf',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-line',
			30
		);

		add_submenu_page(
			'properf',
			__( 'ProPerf Dashboard', 'properf' ),
			__( 'Dashboard', 'properf' ),
			'manage_options',
			'properf',
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			'properf',
			__( 'ProPerf Settings', 'properf' ),
			__( 'Settings', 'properf' ),
			'manage_options',
			'properf-settings',
			array( 'ProPerf_Admin_Settings', 'render_settings' )
		);
	}

	/**
	 * Handle manual BigQuery push form submission.
	 */
	public static function handle_bigquery_push() {
		if ( ! isset( $_POST['properf_push_to_bq'] ) ) {
			return;
		}
		check_admin_referer( 'properf_push_action', 'properf_push_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'properf' ) );
		}

		$result = ( new ProPerf_Data_Collector() )->collect_and_push();

		if ( $result['success'] ) {
			set_transient( self::push_notice_key(), array( 'type' => 'success' ), 60 );
		} else {
			set_transient( self::push_notice_key(), array( 'type' => 'error', 'message' => $result['error'] ), 60 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=properf' ) );
		exit;
	}

	/**
	 * Show settings-saved notice after settings form is submitted.
	 */
	public static function handle_settings_notices() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( 'properf' !== $page && 'properf-settings' !== $page ) {
			return;
		}

		$push_notice = get_transient( self::push_notice_key() );
		if ( $push_notice ) {
			delete_transient( self::push_notice_key() );
			if ( 'success' === $push_notice['type'] ) {
				add_settings_error(
					'properf_messages',
					'properf_push_success',
					esc_html__( 'Data successfully pushed to BigQuery!', 'properf' ),
					'updated'
				);
			} else {
				add_settings_error(
					'properf_messages',
					'properf_push_error',
					esc_html__( 'Failed: ', 'properf' ) . esc_html( $push_notice['message'] ),
					'error'
				);
			}
		}

		if (
			isset( $_GET['settings-updated'] ) &&
			'properf-settings' === $page
		) {
			add_settings_error(
				'properf_messages',
				'properf_settings_saved',
				esc_html__( 'Settings saved successfully.', 'properf' ),
				'updated'
			);
		}
	}

	/**
	 * Render the ProPerf Dashboard admin page.
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'properf' ) );
		}

		$metrics                 = ProPerf_Live_Data::get_live_data();
		$autoloaded_data_metrics = $metrics['autoloaded_option'];

		$autoload_count = $autoloaded_data_metrics['count'];
		$autoload_error = $autoloaded_data_metrics['error'] ?? null;
		$size_bytes     = $autoloaded_data_metrics['size_bytes'];
		$top_size_keys  = $autoloaded_data_metrics['top_size_keys'];

		$woo_metrics = $metrics['woo'];
		$oldest_date = $woo_metrics['oldest_order_date'];
		$latest_date = $woo_metrics['latest_order_date'];

		$orders_older_than_threshold = $woo_metrics['orders_older_than_threshold'];
		$total_orders                = $woo_metrics['total_orders'];
		$threshold_years             = $woo_metrics['threshold_years'];
		$last_archival_date          = $woo_metrics['last_archival_date'];
		$baseline_qet_ms             = $woo_metrics['baseline_qet_ms'];
		$raw_threshold          = get_option( 'properf_order_itemmeta_db_alert_threshold', '' );
		$alert_threshold_mb     = ( '' !== $raw_threshold && (int) $raw_threshold > 0 ) ? (int) $raw_threshold : null;
		$archival_signal_active = null !== $alert_threshold_mb
			&& (float) $woo_metrics['order_itemmeta_size_mb'] >= (float) $alert_threshold_mb
			&& $orders_older_than_threshold > 0;

		$last_sync = get_option( 'properf_bq_last_sync' );

		if ( $last_sync ) {
			$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$sync_time = wp_date( $format, $last_sync );
			$tz_string = get_option( 'timezone_string' );

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
			$last_pushed_display = __( 'Never', 'properf' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ProPerf WordPress Metrics', 'properf' ); ?></h1>

			<p><strong><?php esc_html_e( 'Last pushed to BigQuery:', 'properf' ); ?></strong> <?php echo esc_html( $last_pushed_display ); ?></p>

			<?php settings_errors( 'properf_messages' ); ?>

			<form method="post" class="properf-push-form">
				<?php wp_nonce_field( 'properf_push_action', 'properf_push_nonce' ); ?>
				<input type="submit" name="properf_push_to_bq" class="button button-primary" value="<?php esc_attr_e( 'Push to BigQuery', 'properf' ); ?>">
			</form>

			<?php if ( function_exists( 'WC' ) && null !== $alert_threshold_mb ) : ?>
				<div style="margin:16px 0;padding:12px 16px;border-left:4px solid <?php echo $archival_signal_active ? '#b32d2e' : '#0a7227'; ?>;background:<?php echo $archival_signal_active ? '#fdf2f2' : '#f0faf3'; ?>;">
					<?php if ( $archival_signal_active ) : ?>
						<strong style="color:#b32d2e;font-size:14px;">&#9888; <?php esc_html_e( 'Archival recommended', 'properf' ); ?></strong>
						<span style="color:#5c3232;margin-left:8px;"><?php echo esc_html( sprintf( __( 'Order itemmeta is %s MB — past the %s MB threshold with archivable orders present.', 'properf' ), number_format( $woo_metrics['order_itemmeta_size_mb'], 2 ), number_format( $alert_threshold_mb ) ) ); ?></span>
					<?php else : ?>
						<strong style="color:#0a7227;font-size:14px;">&#10003; <?php esc_html_e( 'DB health: OK', 'properf' ); ?></strong>
						<span style="color:#1a3d1a;margin-left:8px;"><?php echo esc_html( sprintf( __( 'Order itemmeta is %s MB — below the %s MB threshold.', 'properf' ), number_format( $woo_metrics['order_itemmeta_size_mb'], 2 ), number_format( $alert_threshold_mb ) ) ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

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
						<td><?php echo $autoload_error ? esc_html( $autoload_error ) : esc_html( number_format( $autoload_count ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Autoloaded Option Size', 'properf' ); ?></strong></td>
						<td><?php echo esc_html( sprintf( '%.2f KB', $size_bytes / 1024 ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2 class="properf-section-heading"><?php esc_html_e( 'Top 10 Autoloaded Options by Size', 'properf' ); ?></h2>
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
								<td><?php echo esc_html( sprintf( '%.2f KB', $size / 1024 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No autoloaded option keys found or an error occurred.', 'properf' ); ?></p>
			<?php endif; ?>

			<h2 class="properf-section-heading"><?php esc_html_e( 'WooCommerce Order Metrics', 'properf' ); ?></h2>
			<?php if ( function_exists( 'WC' ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Metric', 'properf' ); ?></th>
							<th><?php esc_html_e( 'Value', 'properf' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Order Items Table Size', 'properf' ); ?></strong></td>
							<td><?php echo esc_html( number_format( $woo_metrics['order_items_size_mb'], 2 ) . ' MB' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Order Itemmeta Table Size', 'properf' ); ?></strong></td>
							<td><?php echo esc_html( number_format( $woo_metrics['order_itemmeta_size_mb'], 2 ) . ' MB' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Oldest Order Date', 'properf' ); ?></strong></td>
							<td><?php echo esc_html( $oldest_date ? $oldest_date : '—' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Latest Order Date', 'properf' ); ?></strong></td>
							<td><?php echo esc_html( $latest_date ? $latest_date : '—' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Total Orders', 'properf' ); ?></strong></td>
							<td><?php echo esc_html( number_format( $total_orders ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php echo esc_html( sprintf( __( 'Orders Older Than %d Years', 'properf' ), $threshold_years ) ); ?></strong></td>
							<td><?php echo esc_html( number_format( $orders_older_than_threshold ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Last Archival Date', 'properf' ); ?></strong></td>
							<td>
								<?php echo $last_archival_date ? esc_html( $last_archival_date ) : esc_html__( 'Never', 'properf' ); ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=properf-settings' ) ); ?>" class="button button-secondary properf-update-link"><?php esc_html_e( 'Update Archival Date', 'properf' ); ?></a>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Current Query Execution Time', 'properf' ); ?></strong></td>
							<td><?php echo esc_html( $woo_metrics['query_execution_ms'] . ' ms' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Baseline Query Execution Time', 'properf' ); ?></strong></td>
							<td><?php
								if ( null !== $baseline_qet_ms ) {
									$source_label = self::format_baseline_label( $woo_metrics['baseline_qet_source'] );
									echo esc_html( $baseline_qet_ms . ' ms' ) . ' <span class="properf-baseline-source">(' . esc_html( $source_label ) . ')</span>';
								} else {
									echo '<span class="properf-baseline-unavailable">' . esc_html__( 'Not enough data to calculate baseline yet', 'properf' ) . '</span>';
								}
							?></td>
						</tr>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'WooCommerce is not active on this site.', 'properf' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Per-user transient key for push result notices.
	 *
	 * @return string Transient key.
	 */
	private static function push_notice_key() {
		return 'properf_push_notice_' . get_current_user_id();
	}

	/**
	 * Format a human-readable label for a baseline QET source string.
	 *
	 * @param string|null $source Source string from get_baseline_qet().
	 * @return string Translated label.
	 */
	private static function format_baseline_label( $source ) {
		if ( 'post-archival' === $source ) {
			return __( 'stable baseline', 'properf' );
		}
		if ( 0 === strpos( $source ?? '', 'post-archival-pending:' ) ) {
			$days = (int) explode( ':', $source )[1];
			/* translators: %d = number of days of data collected so far out of 10 */
			return sprintf( __( 'building new baseline — day %d of 10', 'properf' ), $days );
		}
		$days = ( 0 === strpos( $source ?? '', 'lowest-10:' ) )
			? (int) explode( ':', $source )[1]
			: 10;
		/* translators: %d = number of daily readings used */
		return sprintf( __( 'based on %d days of data', 'properf' ), $days );
	}
}
