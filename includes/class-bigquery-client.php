<?php
/**
 * BigQuery Client Class
 *
 * @package ProPerf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$autoload_path = plugin_dir_path( dirname( __FILE__ ) ) . 'vendor/autoload.php';

if ( file_exists( $autoload_path ) ) {
	require_once $autoload_path;
} else {
	error_log( 'ProPerf Error: vendor/autoload.php not found. Please run "composer install" in the plugin directory.' );
	return;
}

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\Core\Exception\GoogleException;

/**
 * Class ProPerf_BigQuery_Client
 */
class ProPerf_BigQuery_Client {

	/**
	 * BigQuery client instance.
	 *
	 * @var BigQueryClient
	 */
	private $bigQuery;

	/**
	 * Dataset ID.
	 *
	 * @var string
	 */
	private $dataset_id;

	/**
	 * Table ID.
	 *
	 * @var string
	 */
	private $table_id;

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Whether client is ready.
	 *
	 * @var bool
	 */
	private $is_ready = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! class_exists( 'Google\Cloud\BigQuery\BigQueryClient' ) ) {
			$this->last_error = 'Google SDK not loaded. Check vendor folder.';
			return;
		}

		$project_id      = $this->get_config_value( 'BIGQUERY_PROJECT_ID' );
		$this->dataset_id = $this->get_config_value( 'BIGQUERY_DATASET_ID' );
		$this->table_id   = $this->get_config_value( 'BIGQUERY_TABLE_ID' );

		$private_key  = $this->get_config_value( 'BIGQUERY_PRIVATE_KEY' );
		$client_email = $this->get_config_value( 'BIGQUERY_CLIENT_EMAIL' );

		if ( empty( $project_id ) || empty( $private_key ) || empty( $client_email ) ) {
			$this->last_error = 'BigQuery Configuration Missing';
			return;
		}
		$key_file = array(
			'type'                        => 'service_account',
			'project_id'                  => $project_id,
			'private_key_id'              => 'undefined',
			'private_key'                 => str_replace( '\n', "\n", $private_key ),
			'client_email'                 => $client_email,
			'client_id'                   => 'undefined',
			'auth_uri'                    => 'https://accounts.google.com/o/oauth2/auth',
			'token_uri'                   => 'https://oauth2.googleapis.com/token',
			'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
		);

		try {
			$this->bigQuery = new BigQueryClient(
				array(
					'projectId' => $project_id,
					'keyFile'   => $key_file,
				)
			);
			$this->is_ready = true;
		} catch ( Exception $e ) {
			$this->last_error = 'BigQuery Init Error: ' . $e->getMessage();
		}
	}

	/**
	 * Push metrics to BigQuery.
	 *
	 * @param array $metrics Metrics data.
	 * @return bool Success status.
	 */
	public function push_metrics( $metrics ) {
		if ( ! $this->is_ready || ! empty( $this->last_error ) ) {
			return false;
		}

		$collector      = new ProPerf_Data_Collector();
		$formatted_data = $collector->format_for_bigquery( $metrics );
		if ( isset( $formatted_data['metric_count'] ) ) {
			$formatted_data['metric_count'] = (string) $formatted_data['metric_count'];
		}
		if ( isset( $formatted_data['metric_total_size'] ) ) {
			$formatted_data['metric_total_size'] = (string) $formatted_data['metric_total_size'];
		}

		try {
			$dataset = $this->bigQuery->dataset( $this->dataset_id );
			$table   = $dataset->table( $this->table_id );

			$json_data = wp_json_encode( $formatted_data );

			$stream = fopen( 'php://memory', 'r+' );
			fwrite( $stream, $json_data );
			rewind( $stream );
			$schema = array(
				'fields' => array(
					array( 'name' => 'platform', 'type' => 'STRING' ),
					array( 'name' => 'metric_key', 'type' => 'STRING' ),
					array( 'name' => 'timestamp_utc', 'type' => 'TIMESTAMP' ),
					array( 'name' => 'metric_count', 'type' => 'STRING' ),
					array( 'name' => 'metric_total_size', 'type' => 'STRING' ),
					array( 'name' => 'site_url', 'type' => 'STRING' ),
				),
			);

			$load_config = $table->load( $stream )
				->sourceFormat( 'NEWLINE_DELIMITED_JSON' )
				->writeDisposition( 'WRITE_APPEND' )
				->schema( $schema )
				->autodetect( false );

			if ( $load_config instanceof \Google\Cloud\BigQuery\LoadJobConfiguration ) {
				$job = $table->runJob( $load_config );
			} else {
				$job = $load_config;
			}

			$job->waitUntilComplete( array( 'maxRetries' => 3 ) );

			$info = $job->info();

			if ( isset( $info['status']['errorResult'] ) ) {
				$this->last_error = 'Load Job Failed: ' . $info['status']['errorResult']['message'];
				return false;
			}

			update_option( 'properf_bq_last_sync', time() );
			return true;
		} catch ( GoogleException $e ) {
			$this->last_error = 'Google SDK Error: ' . $e->getMessage();
			return false;
		} catch ( Exception $e ) {
			$this->last_error = 'General Error: ' . $e->getMessage();
			return false;
		}
	}

	/**
	 * Get last error message.
	 *
	 * @return string Error message.
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Get config value from constants, environment variables, or $_ENV.
	 *
	 * @param string $key Config key.
	 * @param mixed  $default Default value.
	 * @return mixed Config value.
	 */
	private function get_config_value( $key, $default = null ) {
		if ( defined( $key ) ) {
			return constant( $key );
		}
		if ( false !== getenv( $key ) ) {
			return getenv( $key );
		}
		return isset( $_ENV[ $key ] ) ? $_ENV[ $key ] : $default;
	}
}
