<?php

if (!defined('ABSPATH')) {
  exit;
}

/**
 * COMPOSER AUTOLOAD FIX
 * This is the critical part you were missing. It loads the Google SDK files.
 */
$autoload_path = plugin_dir_path(dirname(__FILE__)) . 'vendor/autoload.php';

if (file_exists($autoload_path)) {
  require_once $autoload_path;
} else {
  // Stop execution safely if Composer wasn't run
  error_log('CC Adapter Error: vendor/autoload.php not found. Please run "composer install" in the plugin directory.');
  return;
}

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\Core\Exception\GoogleException;

class CC_Adapter_BigQuery_Client
{
  private $bigQuery;
  private $dataset_id;
  private $table_id;
  private $last_error = '';
  private $is_ready = false;

  public function __construct()
  {
    // 1. Check if the SDK class exists to avoid fatal errors
    if (!class_exists('Google\Cloud\BigQuery\BigQueryClient')) {
      $this->last_error = "Google SDK not loaded. Check vendor folder.";
      return;
    }

    // 2. Get Config Values
    $project_id = $this->get_config_value('BIGQUERY_PROJECT_ID');
    $this->dataset_id = $this->get_config_value('BIGQUERY_DATASET_ID');
    $this->table_id   = $this->get_config_value('BIGQUERY_TABLE_ID');

    $private_key = $this->get_config_value('BIGQUERY_PRIVATE_KEY');
    $client_email = $this->get_config_value('BIGQUERY_CLIENT_EMAIL');

    if (empty($project_id) || empty($private_key) || empty($client_email)) {
      $this->last_error = "BigQuery Configuration Missing: Check .env file.";
      return;
    }

    // 3. Format Key for SDK
    // The SDK normally reads a JSON file, but we can pass an array manually
    $keyFile = [
      'type' => 'service_account',
      'project_id' => $project_id,
      'private_key_id' => 'undefined', // SDK allows this to be vague if key is valid
      'private_key' => str_replace('\n', "\n", $private_key), // Fix line breaks
      'client_email' => $client_email,
      'client_id' => 'undefined',
      'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
      'token_uri' => 'https://oauth2.googleapis.com/token',
      'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    ];

    try {
      // 4. Initialize the SDK Client
      $this->bigQuery = new BigQueryClient([
        'projectId' => $project_id,
        'keyFile'   => $keyFile
      ]);
      $this->is_ready = true;
    } catch (Exception $e) {
      $this->last_error = "BigQuery Init Error: " . $e->getMessage();
    }
  }

  public function push_metrics($metrics)
  {
    if (!$this->is_ready || !empty($this->last_error)) {
      return false;
    }

    $collector = new CC_Adapter_Data_Collector();
    $formatted_data = $collector->format_for_bigquery($metrics);

    // 1. Force Data Types to String (Safety Step)
    if (isset($formatted_data['metric_count'])) {
      $formatted_data['metric_count'] = (string) $formatted_data['metric_count'];
    }
    if (isset($formatted_data['metric_total_size'])) {
      $formatted_data['metric_total_size'] = (string) $formatted_data['metric_total_size'];
    }

    try {
      $dataset = $this->bigQuery->dataset($this->dataset_id);
      $table = $dataset->table($this->table_id);

      // 2. Prepare Data
      $json_data = json_encode($formatted_data);

      $stream = fopen('php://memory', 'r+');
      fwrite($stream, $json_data);
      rewind($stream);

      // 3. Define the Schema Explicitly
      // This prevents BigQuery from "guessing" (and failing)
      $schema = [
        'fields' => [
          ['name' => 'platform', 'type' => 'STRING'],
          ['name' => 'metric_key', 'type' => 'STRING'],
          ['name' => 'timestamp_utc', 'type' => 'TIMESTAMP'],
          ['name' => 'metric_count', 'type' => 'STRING'],      // We force this to be STRING
          ['name' => 'metric_total_size', 'type' => 'STRING'], // We force this to be STRING
          ['name' => 'site_url', 'type' => 'STRING'],
        ]
      ];

      // 4. Configure Load Job with Schema
      $loadConfig = $table->load($stream)
        ->sourceFormat('NEWLINE_DELIMITED_JSON')
        ->writeDisposition('WRITE_APPEND')
        ->schema($schema)    // <--- Applying strict schema
        ->autodetect(false); // <--- Disabling "guessing"

      // 5. Run Job
      if ($loadConfig instanceof \Google\Cloud\BigQuery\LoadJobConfiguration) {
        $job = $table->runJob($loadConfig);
      } else {
        $job = $loadConfig;
      }

      $job->waitUntilComplete(['maxRetries' => 3]);

      $info = $job->info();

      if (isset($info['status']['errorResult'])) {
        $this->last_error = "Load Job Failed: " . $info['status']['errorResult']['message'];
        return false;
      }

      update_option('cc_adapter_bq_last_sync', time());
      return true;
    } catch (GoogleException $e) {
      $this->last_error = "Google SDK Error: " . $e->getMessage();
      return false;
    } catch (Exception $e) {
      $this->last_error = "General Error: " . $e->getMessage();
      return false;
    }
  }
  public function get_last_error()
  {
    return $this->last_error;
  }

  private function get_config_value($key, $default = null)
  {
    if (defined($key)) return constant($key);
    if (getenv($key) !== false) return getenv($key);
    return isset($_ENV[$key]) ? $_ENV[$key] : $default;
  }
}
