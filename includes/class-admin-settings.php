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
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ), 20 );
		add_filter( 'wp_redirect', array( __CLASS__, 'settings_redirect' ) );
		add_action( 'update_option_properf_last_archival_date', array( __CLASS__, 'reset_qet_on_archival_change' ), 10, 2 );
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
		// Register all settings.

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

		// Register sections.

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

		// Register fields.

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
			add_settings_error(
				'properf_messages',
				'properf_threshold_years_invalid',
				__( 'Retention window must be between 1 and 20 years. Previous value restored.', 'properf' ),
				'error'
			);
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
			add_settings_error(
				'properf_messages',
				'properf_archival_date_invalid',
				__( 'Invalid archival date. Must be in YYYY-MM-DD format. Previous value restored.', 'properf' ),
				'error'
			);
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
		if ( isset( $_POST['option_page'] ) && 'properf_bigquery_settings' === $_POST['option_page'] ) {
			$location = add_query_arg( 'page', 'properf-settings', $location );
		}
		return $location;
	}
}
