<?php

if (!defined('ABSPATH')) {
  exit;
}

class CC_Adapter_BigQuery_Client
{
  private $project_id;
  private $dataset_id;
  private $table_id;
  private $credentials;
  private $last_error = '';

  public function __construct()
  {
    $this->project_id = $this->get_config_value('BIGQUERY_PROJECT_ID');
    $this->dataset_id = $this->get_config_value('BIGQUERY_DATASET_ID');
    $this->table_id   = $this->get_config_value('BIGQUERY_TABLE_ID');

    $raw_key = $this->get_config_value('BIGQUERY_PRIVATE_KEY');

    $this->credentials = array(
      'client_email' => $this->get_config_value('BIGQUERY_CLIENT_EMAIL'),
      'private_key'  => str_replace('\n', "\n", $raw_key),
      'token_uri'    => 'https://oauth2.googleapis.com/token',
    );

    if (empty($this->project_id) || empty($this->credentials['private_key'])) {
      $this->last_error = "BigQuery Configuration Missing: Check your .env file.";
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

  private function base64url_encode($data)
  {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  private function create_jwt()
  {
    if (empty($this->credentials['private_key'])) return false;

    $header = $this->base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $now = time();
    $payload = $this->base64url_encode(json_encode([
      'iss'   => $this->credentials['client_email'],
      'scope' => 'https://www.googleapis.com/auth/bigquery',
      'aud'   => $this->credentials['token_uri'],
      'exp'   => $now + 3600,
      'iat'   => $now,
    ]));

    $signature_input = $header . '.' . $payload;
    $private_key = openssl_pkey_get_private($this->credentials['private_key']);

    if (!$private_key) {
      $this->last_error = "OpenSSL Error: Unable to read Private Key.";
      return false;
    }

    openssl_sign($signature_input, $signature, $private_key, 'sha256');
    return $signature_input . '.' . $this->base64url_encode($signature);
  }

  private function get_access_token()
  {
    $cached_token = get_transient('cc_adapter_bq_token');
    if ($cached_token) return $cached_token;

    $jwt = $this->create_jwt();
    if (!$jwt) return false;

    $response = wp_remote_post($this->credentials['token_uri'], array(
      'sslverify' => false,
      'body' => array(
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt
      ),
    ));

    if (is_wp_error($response)) {
      $this->last_error = $response->get_error_message();
      return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['access_token'])) {
      $this->last_error = "OAuth Error: " . ($body['error_description'] ?? 'Unknown');
      return false;
    }

    set_transient('cc_adapter_bq_token', $body['access_token'], 50 * MINUTE_IN_SECONDS);
    return $body['access_token'];
  }

  public function push_metrics($metrics)
  {
    if (!empty($this->last_error)) return false;

    $collector = new CC_Adapter_Data_Collector();
    // This returns a SINGLE formatted array based on your new schema
    $formatted_data = $collector->format_for_bigquery($metrics);

    $access_token = $this->get_access_token();
    if (!$access_token) return false;

    $url = "https://www.googleapis.com/upload/bigquery/v2/projects/{$this->project_id}/jobs?uploadType=multipart";
    $boundary = wp_generate_password(24, false);

    $job_config = [
      'configuration' => [
        'load' => [
          'destinationTable' => [
            'projectId' => $this->project_id,
            'datasetId' => $this->dataset_id,
            'tableId'   => $this->table_id,
          ],
          'sourceFormat'     => 'NEWLINE_DELIMITED_JSON',
          'writeDisposition' => 'WRITE_APPEND',
          'autodetect'       => false,
          'schema' => [
            'fields' => [
              ['name' => 'platform', 'type' => 'STRING'],
              ['name' => 'metric_key', 'type' => 'STRING'],
              ['name' => 'timestamp_utc', 'type' => 'TIMESTAMP'],
              ['name' => 'metric_count', 'type' => 'STRING'],
              ['name' => 'metric_total_size', 'type' => 'STRING'],
              ['name' => 'site_url', 'type' => 'STRING'],
            ]
          ]
        ]
      ]
    ];

    // Construct Multipart Body
    $body = "--" . $boundary . "\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= json_encode($job_config) . "\r\n";
    $body .= "--" . $boundary . "\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";

    // UPDATED: Wrap the single formatted row in an array to ensure the loop works
    // and sends valid JSON rows (NDJSON)
    $rows_to_push = array($formatted_data);

    foreach ($rows_to_push as $row) {
      $body .= json_encode($row) . "\n";
    }

    $body .= "\r\n--" . $boundary . "--";

    $response = wp_remote_post($url, array(
      'headers' => array(
        'Authorization'  => 'Bearer ' . $access_token,
        'Content-Type'   => 'multipart/related; boundary=' . $boundary,
        'Content-Length' => strlen($body),
      ),
      'body'      => $body,
      'timeout'   => 30,
      'sslverify' => false,
    ));

    if (is_wp_error($response)) {
      $this->last_error = $response->get_error_message();
      return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code === 200) {
      update_option('cc_adapter_bq_last_sync', time());
      return true;
    }

    $this->last_error = "BigQuery Job Failed: HTTP " . $status_code;
    return false;
  }
}
