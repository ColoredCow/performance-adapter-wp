<?php
/**
 * Admin Settings Class
 *
 * @package ProPerf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProPerf_Admin_Settings
 */
class ProPerf_Admin_Settings {

	/**
	 * Minimum suggested threshold in MB below which archival planning is not meaningful.
	 */
	const MIN_ARCHIVAL_THRESHOLD_MB = 500;

	/**
	 * Register hooks that must fire in any context (CLI, REST, cron, frontend).
	 */
	public static function register_persistent_hooks() {
		add_action( 'update_option_properf_last_archival_date', array( __CLASS__, 'reset_qet_on_archival_change' ), 10, 2 );
		add_action( 'update_option_properf_last_archival_date', array( 'ProPerf_Data_Collector', 'bust_woo_metrics_cache' ) );
		add_action( 'add_option_properf_last_archival_date', array( 'ProPerf_Data_Collector', 'bust_woo_metrics_cache' ) );
		add_action( 'update_option_properf_archival_threshold_years', array( 'ProPerf_Data_Collector', 'bust_woo_metrics_cache' ) );
		add_action( 'add_option_properf_archival_threshold_years', array( 'ProPerf_Data_Collector', 'bust_woo_metrics_cache' ) );
		add_action( 'update_option_properf_order_itemmeta_db_alert_threshold', array( 'ProPerf_Data_Collector', 'bust_woo_metrics_cache' ) );
		add_action( 'add_option_properf_order_itemmeta_db_alert_threshold', array( 'ProPerf_Data_Collector', 'bust_woo_metrics_cache' ) );
		add_action( 'delete_option_properf_order_itemmeta_db_alert_threshold', array( 'ProPerf_Data_Collector', 'bust_woo_metrics_cache' ) );
		add_action( 'activate_' . plugin_basename( PROPERF_DIR . 'properf-wordpress-adapter.php' ), array( 'ProPerf_Data_Collector', 'bust_metrics_cache' ) );
	}

	/**
	 * Register admin-only hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ), 20 );
		add_filter( 'wp_redirect', array( __CLASS__, 'settings_redirect' ) );
		add_action( 'activated_plugin',   array( 'ProPerf_Data_Collector', 'bust_server_metrics_cache' ) );
		add_action( 'deactivated_plugin', array( 'ProPerf_Data_Collector', 'bust_server_metrics_cache' ) );
	}

	/**
	 * Reset QET history when archival date changes so baseline recomputes from post-archival state.
	 *
	 * @param string $old Previous value.
	 * @param string $new New value.
	 */
	public static function reset_qet_on_archival_change( $old, $new ) {
		if ( ! empty( $old ) && $old !== $new ) {
			update_option( 'properf_qet_history', array(), false );
			delete_option( 'properf_baseline_qet_ms' );
		}
	}

	/**
	 * Register plugin settings, sections, and fields.
	 */
	public static function register_settings() {
		self::register_options();
		self::register_sections();
		self::register_fields();
	}

	/**
	 * Register individual setting options.
	 */
	private static function register_options() {
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
				'sanitize_callback' => array( __CLASS__, 'sanitize_private_key' ),
				'default'           => '',
			)
		);

		register_setting(
			'properf_bigquery_settings',
			'properf_archival_threshold_years',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_threshold_years' ),
				'default'           => 2,
			)
		);

		register_setting(
			'properf_bigquery_settings',
			'properf_last_archival_date',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_archival_date' ),
				'default'           => '',
			)
		);

		register_setting(
			'properf_bigquery_settings',
			'properf_order_itemmeta_db_alert_threshold',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_alert_threshold_mb' ),
				'default'           => '',
			)
		);
	}

	/**
	 * Register settings sections.
	 */
	private static function register_sections() {
		add_settings_section(
			'properf_bigquery_section',
			__( 'BigQuery Configuration', 'properf' ),
			array( __CLASS__, 'bigquery_section_callback' ),
			'properf-settings'
		);

		add_settings_section(
			'properf_woo_section',
			__( 'WooCommerce Settings', 'properf' ),
			array( __CLASS__, 'woo_section_callback' ),
			'properf-settings'
		);
	}

	/**
	 * Register settings fields.
	 */
	private static function register_fields() {
		add_settings_field(
			'properf_bigquery_project_id',
			__( 'Project ID', 'properf' ),
			array( __CLASS__, 'bigquery_project_id_field' ),
			'properf-settings',
			'properf_bigquery_section'
		);

		add_settings_field(
			'properf_bigquery_dataset_id',
			__( 'Dataset ID', 'properf' ),
			array( __CLASS__, 'bigquery_dataset_id_field' ),
			'properf-settings',
			'properf_bigquery_section'
		);

		add_settings_field(
			'properf_bigquery_table_id',
			__( 'Table ID', 'properf' ),
			array( __CLASS__, 'bigquery_table_id_field' ),
			'properf-settings',
			'properf_bigquery_section'
		);

		add_settings_field(
			'properf_bigquery_client_email',
			__( 'Client Email', 'properf' ),
			array( __CLASS__, 'bigquery_client_email_field' ),
			'properf-settings',
			'properf_bigquery_section'
		);

		add_settings_field(
			'properf_bigquery_private_key',
			__( 'Private Key', 'properf' ),
			array( __CLASS__, 'bigquery_private_key_field' ),
			'properf-settings',
			'properf_bigquery_section'
		);

		add_settings_field(
			'properf_archival_threshold_years',
			__( 'Retention Window (Years)', 'properf' ),
			array( __CLASS__, 'archival_threshold_years_field' ),
			'properf-settings',
			'properf_woo_section'
		);

		add_settings_field(
			'properf_last_archival_date',
			__( 'Last Archival Date', 'properf' ),
			array( __CLASS__, 'last_archival_date_field' ),
			'properf-settings',
			'properf_woo_section'
		);

		add_settings_field(
			'properf_order_itemmeta_db_alert_threshold',
			__( 'DB Size Alert Threshold', 'properf' ),
			array( __CLASS__, 'order_itemmeta_alert_threshold_mb_field' ),
			'properf-settings',
			'properf_woo_section'
		);
	}

	/**
	 * Sanitize private key field.
	 *
	 * @param string $value Private key value.
	 * @return string Sanitized private key.
	 */
	public static function sanitize_private_key( $value ) {
		return wp_strip_all_tags( $value );
	}

	/**
	 * Sanitize retention window — must be a positive integer between 1 and 20.
	 *
	 * @param mixed $value Submitted value.
	 * @return int Sanitized value.
	 */
	public static function sanitize_threshold_years( $value ) {
		$value = intval( $value );
		if ( $value < 1 || $value > 20 ) {
			if ( is_admin() && ! wp_doing_cron() ) {
				add_settings_error(
					'properf_messages',
					'properf_threshold_years_invalid',
					__( 'Retention window must be between 1 and 20 years. Previous value restored.', 'properf' ),
					'error'
				);
			}
			return intval( get_option( 'properf_archival_threshold_years', 2 ) );
		}
		return $value;
	}

	/**
	 * Sanitize archival date — must be a valid Y-m-d date or empty.
	 *
	 * @param string $value Submitted value.
	 * @return string Sanitized date or empty string.
	 */
	public static function sanitize_archival_date( $value ) {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return '';
		}
		$date = \DateTime::createFromFormat( 'Y-m-d', $value );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			if ( is_admin() && ! wp_doing_cron() ) {
				add_settings_error(
					'properf_messages',
					'properf_archival_date_invalid',
					__( 'Invalid archival date. Must be in YYYY-MM-DD format. Previous value restored.', 'properf' ),
					'error'
				);
			}
			return get_option( 'properf_last_archival_date', '' );
		}
		return $value;
	}

	/**
	 * BigQuery section description.
	 */
	public static function bigquery_section_callback() {
		echo '<p>' . esc_html__( 'Configure your Google Cloud BigQuery credentials. These settings are required for pushing metrics to BigQuery.', 'properf' ) . '</p>';
	}

	/**
	 * WooCommerce section description.
	 */
	public static function woo_section_callback() {
		echo '<p>' . esc_html__( 'Configure WooCommerce-specific settings for this client.', 'properf' ) . '</p>';
	}

	/**
	 * Project ID field renderer.
	 */
	public static function bigquery_project_id_field() {
		$value = get_option( 'properf_bigquery_project_id', '' );
		?>
		<input type="text" id="properf_bigquery_project_id" name="properf_bigquery_project_id" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Your Google Cloud Project ID.', 'properf' ); ?></p>
		<?php
	}

	/**
	 * Dataset ID field renderer.
	 */
	public static function bigquery_dataset_id_field() {
		$value = get_option( 'properf_bigquery_dataset_id', '' );
		?>
		<input type="text" id="properf_bigquery_dataset_id" name="properf_bigquery_dataset_id" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'The BigQuery dataset ID where metrics will be stored.', 'properf' ); ?></p>
		<?php
	}

	/**
	 * Table ID field renderer.
	 */
	public static function bigquery_table_id_field() {
		$value = get_option( 'properf_bigquery_table_id', '' );
		?>
		<input type="text" id="properf_bigquery_table_id" name="properf_bigquery_table_id" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'The BigQuery table ID where metrics will be stored.', 'properf' ); ?></p>
		<?php
	}

	/**
	 * Client Email field renderer.
	 */
	public static function bigquery_client_email_field() {
		$value = get_option( 'properf_bigquery_client_email', '' );
		?>
		<input type="email" id="properf_bigquery_client_email" name="properf_bigquery_client_email" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'The service account email address from your Google Cloud service account JSON key.', 'properf' ); ?></p>
		<?php
	}

	/**
	 * Private Key field renderer.
	 */
	public static function bigquery_private_key_field() {
		$value = get_option( 'properf_bigquery_private_key', '' );
		?>
		<textarea id="properf_bigquery_private_key" name="properf_bigquery_private_key" rows="5" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'The private key from your Google Cloud service account JSON key file. Include the full key including BEGIN and END markers.', 'properf' ); ?></p>
		<?php
	}

	/**
	 * Retention window field renderer.
	 */
	public static function archival_threshold_years_field() {
		$value = intval( get_option( 'properf_archival_threshold_years', 2 ) );
		?>
		<input type="number" id="properf_archival_threshold_years" name="properf_archival_threshold_years" value="<?php echo esc_attr( $value ); ?>" min="1" max="20" step="1" class="small-text" />
		<p class="description"><?php esc_html_e( 'Orders older than this many years will be counted as archival candidates. Default is 2.', 'properf' ); ?></p>
		<?php
	}

	/**
	 * Sanitize alert threshold — raw numeric value in the unit specified by the
	 * properf_order_itemmeta_db_alert_threshold_unit POST field. Converts GB to MB
	 * server-side and stores the result in MB regardless of the submitted unit.
	 * Empty or zero value disables the threshold.
	 *
	 * @param mixed $value Raw numeric value as submitted (in MB or GB depending on unit field).
	 * @return int|string Sanitized MB value or empty string to disable.
	 */
	public static function sanitize_alert_threshold_mb( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$unit = ( ! empty( $_POST ) && isset( $_POST['properf_order_itemmeta_db_alert_threshold_unit'] ) )
			? sanitize_key( wp_unslash( $_POST['properf_order_itemmeta_db_alert_threshold_unit'] ) )
			: 'mb';
		if ( ! in_array( $unit, array( 'mb', 'gb' ), true ) ) {
			$unit = 'mb';
		}
		$numeric = floatval( $value );
		if ( 'gb' === $unit ) {
			$numeric *= 1024;
		}
		$mb = (int) round( $numeric );
		if ( $mb <= 0 ) {
			if ( is_admin() && ! wp_doing_cron() ) {
				$prior = get_option( 'properf_order_itemmeta_db_alert_threshold', '' );
				$msg   = '' !== $prior
					? __( 'DB size alert threshold must be a positive number. Previous value restored.', 'properf' )
					: __( 'DB size alert threshold must be a positive number.', 'properf' );
				add_settings_error(
					'properf_messages',
					'properf_alert_threshold_mb_invalid',
					$msg,
					'error'
				);
				return $prior;
			}
			return get_option( 'properf_order_itemmeta_db_alert_threshold', '' );
		}
		return $mb;
	}

	/**
	 * Last archival date field renderer.
	 */
	public static function last_archival_date_field() {
		$value = get_option( 'properf_last_archival_date', '' );
		?>
		<input type="date" id="properf_last_archival_date" name="properf_last_archival_date" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Optional. Enter the date when orders were last archived for this client. Used as a reference point on the dashboard.', 'properf' ); ?></p>
		<?php
	}

	/**
	 * DB size alert threshold field renderer.
	 * Stores value in MB internally. The unit is submitted as a named form field
	 * and converted to MB server-side in sanitize_alert_threshold_mb().
	 * Pre-fills a suggested value when the field is empty and enough data exists.
	 */
	public static function order_itemmeta_alert_threshold_mb_field() {
		$stored_mb = get_option( 'properf_order_itemmeta_db_alert_threshold', '' );

		$suggestion_notice = '';

		if ( '' === $stored_mb ) {
			$snapshot = get_transient( 'properf_woo_metrics_snapshot' );
			if ( false === $snapshot && function_exists( 'WC' ) && class_exists( 'ProPerf_Data_Collector' ) ) {
				$snapshot = ( new ProPerf_Data_Collector() )->collect_woo_order_metrics();
				set_transient( 'properf_woo_metrics_snapshot', $snapshot, HOUR_IN_SECONDS );
			}
			$woo = isset( $snapshot['woo'] ) ? $snapshot['woo'] : null;

			if ( $woo && ! empty( $woo['total_orders'] ) && $woo['total_orders'] > 0 && ! empty( $woo['orders_older_than_threshold'] ) && $woo['orders_older_than_threshold'] > 0 ) {
				$current_mb  = (float) $woo['order_itemmeta_size_mb'];
				$total       = (int) $woo['total_orders'];
				$older       = (int) $woo['orders_older_than_threshold'];
				$avg_mb      = $current_mb / $total;
				$archivable  = $avg_mb * $older;
				$healthy     = $current_mb - $archivable;
				$suggested   = (int) round( $healthy * 2 );

				if ( $suggested >= self::MIN_ARCHIVAL_THRESHOLD_MB ) {
					$stored_mb         = $suggested;
					$suggestion_notice = sprintf(
						/* translators: 1: itemmeta size MB, 2: total orders, 3: orders older than retention, 4: post-archival estimate MB */
						__( 'Pre-filled from DB snapshot: %1$s MB across %2$s total orders (%3$s older than retention). Estimated post-archival size: ~%4$s MB. Suggested threshold = post-archival × 2.', 'properf' ),
						number_format( $current_mb, 2 ),
						number_format( $total ),
						number_format( $older ),
						number_format( $healthy )
					);
				} elseif ( $healthy > 0 ) {
					$suggestion_notice = __( 'DB is too small for archival planning — no threshold needed yet. Set manually if required.', 'properf' );
				} else {
					$suggestion_notice = __( 'Could not estimate a valid threshold from current data — set manually.', 'properf' );
				}
			} elseif ( $woo && ( empty( $woo['orders_older_than_threshold'] ) || 0 === (int) $woo['orders_older_than_threshold'] ) ) {
				if ( empty( $woo['total_orders'] ) || 0 === (int) $woo['total_orders'] ) {
					$suggestion_notice = __( 'No orders yet — leave empty to disable the alert.', 'properf' );
				} else {
					$suggestion_notice = __( 'No archivable orders found for the current retention window — set threshold manually or leave empty to disable.', 'properf' );
				}
			}
		}
		?>
		<div class="properf-threshold-input-wrap">
			<input
				type="number"
				id="properf_order_itemmeta_db_alert_threshold"
				name="properf_order_itemmeta_db_alert_threshold"
				value="<?php echo esc_attr( $stored_mb ); ?>"
				min="1"
				step="1"
				class="regular-text"
			/>
			<select id="properf_db_alert_threshold_unit" name="properf_order_itemmeta_db_alert_threshold_unit">
				<option value="mb">MB</option>
				<option value="gb">GB</option>
			</select>
		</div>
		<p class="description"><?php esc_html_e( 'Alert when order_item_meta exceeds this size.', 'properf' ); ?></p>
		<?php if ( $suggestion_notice ) : ?>
			<p class="description properf-suggestion-notice"><?php echo esc_html( $suggestion_notice ); ?></p>
		<?php endif; ?>
		<script>
		(function () {
			var input  = document.getElementById( 'properf_order_itemmeta_db_alert_threshold' );
			var select = document.getElementById( 'properf_db_alert_threshold_unit' );
			var form   = input ? input.closest( 'form' ) : null;
			if ( ! form ) return;
			function setUnit( unit ) {
				select.value = unit;
				input.step   = unit === 'gb' ? 'any' : '1';
				input.min    = unit === 'gb' ? '0.0001' : '1';
			}
			// On load: display in GB if value is >= 1024 MB.
			if ( input.value !== '' ) {
				var mb = parseInt( input.value, 10 );
				if ( mb >= 1024 ) {
					input.value = parseFloat( ( mb / 1024 ).toFixed( 4 ) );
					setUnit( 'gb' );
				}
			}
			// Track current unit so the change handler can convert the input value.
			select.dataset.prev = select.value;
			select.addEventListener( 'change', function () {
				var prev = select.dataset.prev;
				if ( input.value !== '' ) {
					if ( prev === 'mb' && select.value === 'gb' ) {
						input.value = parseFloat( ( parseFloat( input.value ) / 1024 ).toFixed( 4 ) );
					} else if ( prev === 'gb' && select.value === 'mb' ) {
						input.value = Math.round( parseFloat( input.value ) * 1024 );
					}
				}
				setUnit( select.value );
				select.dataset.prev = select.value;
			} );
		}());
		</script>
		<?php
	}

	/**
	 * Render Settings page using WP Settings API.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'properf' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ProPerf Settings', 'properf' ); ?></h1>
			<?php settings_errors( 'properf_messages' ); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'properf_bigquery_settings' );
				do_settings_sections( 'properf-settings' );
				submit_button( __( 'Save Settings', 'properf' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add page parameter to settings form redirect so the user lands back on the settings page.
	 *
	 * @param string $location Redirect location.
	 * @return string Modified redirect location.
	 */
	public static function settings_redirect( $location ) {
		if ( isset( $_POST['option_page'] ) && 'properf_bigquery_settings' === sanitize_key( $_POST['option_page'] ) ) {
			$location = add_query_arg( 'page', 'properf-settings', $location );
		}
		return $location;
	}
}
