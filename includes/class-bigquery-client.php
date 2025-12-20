<?php

if (!defined('ABSPATH')) {
  exit;
}

$autoload_path = plugin_dir_path(dirname(__FILE__)) . 'vendor/autoload.php';

if (file_exists($autoload_path)) {
  require_once $autoload_path;
} else {
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
    if (!class_exists('Google\Cloud\BigQuery\BigQueryClient')) {
      $this->last_error = "Google SDK not loaded. Check vendor folder.";
      return;
    }

    $project_id = $this->get_config_value('BIGQUERY_PROJECT_ID');
    $this->dataset_id = $this->get_config_value('BIGQUERY_DATASET_ID');
    $this->table_id   = $this->get_config_value('BIGQUERY_TABLE_ID');

    $private_key = $this->get_config_value('BIGQUERY_PRIVATE_KEY');
    $client_email = $this->get_config_value('BIGQUERY_CLIENT_EMAIL');

    if (empty($project_id) || empty($private_key) || empty($client_email)) {
      $this->last_error = "BigQuery Configuration Missing: Check .env file.";
      return;
    }
    $keyFile = [
      'type' => 'service_account',
      'project_id' => $project_id,
      'private_key_id' => 'undefined',
      'private_key' => str_replace('\n', "\n", $private_key),
      'client_email' => $client_email,
      'client_id' => 'undefined',
      'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
      'token_uri' => 'https://oauth2.googleapis.com/token',
      'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    ];

    try {
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
    if (isset($formatted_data['metric_count'])) {
      $formatted_data['metric_count'] = (string) $formatted_data['metric_count'];
    }
    if (isset($formatted_data['metric_total_size'])) {
      $formatted_data['metric_total_size'] = (string) $formatted_data['metric_total_size'];
    }

    try {
      $dataset = $this->bigQuery->dataset($this->dataset_id);
      $table = $dataset->table($this->table_id);

      $json_data = json_encode($formatted_data);

      $stream = fopen('php://memory', 'r+');
      fwrite($stream, $json_data);
      rewind($stream);
      $schema = [
        'fields' => [
          ['name' => 'platform', 'type' => 'STRING'],
          ['name' => 'metric_key', 'type' => 'STRING'],
          ['name' => 'timestamp_utc', 'type' => 'TIMESTAMP'],
          ['name' => 'metric_count', 'type' => 'STRING'],     
          ['name' => 'metric_total_size', 'type' => 'STRING'],
          ['name' => 'site_url', 'type' => 'STRING'],
        ]
      ];

      $loadConfig = $table->load($stream)
        ->sourceFormat('NEWLINE_DELIMITED_JSON')
        ->writeDisposition('WRITE_APPEND')
        ->schema($schema)  
        ->autodetect(false);

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
