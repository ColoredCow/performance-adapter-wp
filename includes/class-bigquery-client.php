<?php


if (! defined('ABSPATH')) {
  exit;
}

class CC_Adapter_BigQuery_Client
{

  private $project_id = 'pro-perf';
  private $dataset_id = 'proactive_perf';
  private $table_id = 'performance_metrics';
  private $credentials;
  private $access_token;

  public function __construct()
  {
    $this->credentials = array(
      'type'                => 'service_account',
      'project_id'          => 'pro-perf',
      'private_key_id'      => 'decf719f52509a282fc04045dacff8e56d4c11bf',
      'private_key'         => "-----BEGIN PRIVATE KEY-----\nMIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCoczULIoE88JUl\nyHtoGYwc6wZBGV90Ga1ve+AZhZkmI7wwATxSHp0PGDScyq7hoKnqXgqBaMT3yvuD\nOssklE33Gpr2Q8xJKFp9aOgfpxGt4HZp20iDZ8PXiwEFzT24osLVVtca6vgqMcEA\nwettDSZhexsJsvYq5LkNfXX7vJRSv2D/Ui4/+8J2sb2nI0yG5EOwDAluNNPBOKYr\nJMfathPzVESuMvi3HmBz57U0vMHOiUAObs2uNBw7fZ50xTehcv5d58WmywLE5XOT\nPv0X3gceOCTMmdTbxC6I5Th7FmW5Hlh2MrgoeLDk4OdKeGSESTDP0LzvQtLrLq9R\nKe40uP+LAgMBAAECggEAEgvNmfTHXaz0fYi918gs34g6Mk0ykbCSiQf/WRyb7J8V\ncRsgyDdpYg2YzVdVZuycZ3RNsdF0kItZaJSq4K9WrutVwJ5Ay3GcSCUuAP4YAcWz\nSeHpIdLDA1tr76AuRZKCRvK3trWHgpWz9I3R1+v5uaXDnsViY/P+8zgGpMJuLXMQ\nsrgB6UQUm3uLm/hjBZ2Qy1ZVqYS4qlHkkjOIRFigucEj2bwusp5ebf/r7MH95JZ9\nsFx8LAUyM/12HZYkvGU6txp2ohkbJTcO9YKD4bX0/Fr9V7VagdFGz/GqLikWtkzr\nYqKLzflajcWXUHmm6360AWlefZ2E18w5S7vWqwgsuQKBgQDb+ROerO8NDyE6HZ0K\nhnRbE3QEOcelQVtNjxBoJQCaR4OJulGPvBqhvmNPAyVvHI32RQSXacCcOe1D+Is+\nMBc7vjMZcAImanyrbZ8g+dXvcn6oYn9vYk+FMLV4G1D4qX5aw9hRnwaYhUfVwsFk\nkqjh6uqViTLIP/AJM328bk+HNQKBgQDECea8vayQFVZH1H2Ug+XZMRlZ6VZQMFtm\nPjj29XLImPgo/+lqe5POAnnKnzL0rMo8l8ihFS7Vd2KNo8dAx4T+qinxugVg/Yh2\nA4PhEWKaMYqIrT2ltt2c3c1x/LGrEZt7LceWnIJyiht9dJLQpbCD+os4m/1bPQ3S\nozprD8eDvwKBgGFwNXamF8XrG8bIc1XENSpatZthlMPo7W6vno7jRR8R6nxJofNP\nWWSoFwla1Wwgc+nQrLX9TCpnpmfjYpqLZt854xyzduBZbxvolQJgaJmGWABykQxf\nueW/q8KmJvne6m9+LQYKsTtCXo2blVrddB2Ol5bhjTMSz1rkCiA7pNK5AoGAI0Ug\nxU5e0KF2H4BEg8bjQJtL01he1hiNKS0CtLPeTebvpvi79xN6uTLK1MClu02nKRWp\n3AlinrdW/OK9g5MiA2t8FmiAdT3IImtpe8HT+qf1I7f/gmQPJRzmzJ5JHN0TGytW\nYGuSMKdWYNDrZSyaQHSAPdQa1iJ67S2+4eo53CMCgYBj730mAu8O9oC/8+Qg7kIb\ntW17D3OIkH3RlqJWCNB0tUh0Fr8xka/gXuAcLVKWMaKzeOXGlEWsFNs1r9poXfJr\nEW0gEbRFVAwovYo9mdapcOSnjjO0+sEyzkDD5eNkdqqi2hHhJhjH6VSVK4lOY989\ndTxHRqEciAAM+hKEWKrZHw==\n-----END PRIVATE KEY-----\n",
      'client_email'        => 'wordpress-adapter@pro-perf.iam.gserviceaccount.com',
      'client_id'           => '116325854949354250253',
      'auth_uri'            => 'https://accounts.google.com/o/oauth2/auth',
      'token_uri'           => 'https://oauth2.googleapis.com/token',
      'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    );
  }

  private function get_access_token()
  {
    // Check if token is cached
    $cached_token = get_transient('cc_adapter_bq_token');
    if ($cached_token) {
      return $cached_token;
    }

    $jwt = $this->create_jwt();

    $response = wp_remote_post(
      $this->credentials['token_uri'],
      array(
        'body' => array(
          'grant_type'            => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
          'assertion'             => $jwt,
        ),
      )
    );

    if (is_wp_error($response)) {
      error_log('CC Adapter: Failed to get BigQuery access token: ' . $response->get_error_message());
      return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (! isset($body['access_token'])) {
      error_log('CC Adapter: No access token in response: ' . print_r($body, true));
      return false;
    }

    // Cache for 50 minutes (tokens last 1 hour)
    set_transient('cc_adapter_bq_token', $body['access_token'], 50 * MINUTE_IN_SECONDS);

    return $body['access_token'];
  }

  private function create_jwt()
  {
    $header = array(
      'alg' => 'RS256',
      'typ' => 'JWT',
    );

    $now = time();
    $payload = array(
      'iss'   => $this->credentials['client_email'],
      'scope' => 'https://www.googleapis.com/auth/bigquery',
      'aud'   => $this->credentials['token_uri'],
      'exp'   => $now + 3600,
      'iat'   => $now,
    );

    $header_encoded = $this->base64url_encode(json_encode($header));
    $payload_encoded = $this->base64url_encode(json_encode($payload));

    $signature_input = $header_encoded . '.' . $payload_encoded;

    $private_key = openssl_pkey_get_private($this->credentials['private_key']);
    if (! $private_key) {
      error_log('CC Adapter: Failed to load private key');
      return false;
    }

    openssl_sign($signature_input, $signature, $private_key, 'sha256');
    $signature_encoded = $this->base64url_encode($signature);

    return $signature_input . '.' . $signature_encoded;
  }

 
  private function base64url_encode($data)
  {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  public function push_metrics($metrics)
  {
    $collector = new CC_Adapter_Data_Collector();
    $formatted_data = $collector->format_for_bigquery($metrics);

    $access_token = $this->get_access_token();
    if (! $access_token) {
      error_log('CC Adapter: Failed to get access token for BigQuery');
      return false;
    }

    $url = "https://www.googleapis.com/bigquery/v2/projects/{$this->project_id}/datasets/{$this->dataset_id}/tables/{$this->table_id}/insertAll";

    $response = wp_remote_post(
      $url,
      array(
        'headers' => array(
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type'  => 'application/json',
        ),
        'body'    => json_encode(array(
          'rows' => array(
            array(
              'json' => $formatted_data,
            ),
          ),
        )),
      )
    );

    if (is_wp_error($response)) {
      error_log('CC Adapter: Failed to push metrics to BigQuery: ' . $response->get_error_message());
      return false;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code >= 200 && $http_code < 300) {
      error_log('CC Adapter: Metrics successfully pushed to BigQuery');
      return true;
    } else {
      $body = wp_remote_retrieve_body($response);
      error_log('CC Adapter: BigQuery API error (HTTP ' . $http_code . '): ' . $body);
      return false;
    }
  }
}
