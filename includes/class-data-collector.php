<?php

// Data Collector Class

if (! defined('ABSPATH')) {
  exit;
}

class CC_Adapter_Data_Collector
{

  /**
   * Get all collected data, always calculating fresh data (no cache).
   *
   * @return array
   */
  public function get_data()
  {
    return $this->collect_autoloaded_options();
  }

  /**
   * Collect autoloaded options metrics.
   *
   * @return array
   */
  public function collect_autoloaded_options()
  {
    global $wpdb;

    $autoload_clause = "autoload IN ('yes', 'on', 'auto', 'auto-on')";

    $autoloaded_option_count = intval($wpdb->get_var(
      "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$autoload_clause}"
    ));

    $size_result = $wpdb->get_var(
      "SELECT SUM(OCTET_LENGTH(option_value)) FROM {$wpdb->options} WHERE {$autoload_clause}"
    );
    $autoloaded_option_size_bytes = $size_result ? intval($size_result) : 0;

    $top_options = $wpdb->get_results(
      "SELECT option_name, OCTET_LENGTH(option_value) as size 
			FROM {$wpdb->options} 
			WHERE {$autoload_clause} 
			ORDER BY size DESC LIMIT 10"
    );

    $autoloaded_option_top_keys = array();
    if ($top_options) {
      foreach ($top_options as $option) {
        $autoloaded_option_top_keys[$option->option_name] = intval($option->size);
      }
    }

    $timestamp_utc = gmdate('Y-m-d H:i:s');

    $data = array(
      'autoloaded_option_count'   => $autoloaded_option_count,
      'autoloaded_option_size_bytes' => $autoloaded_option_size_bytes,
      'autoloaded_option_top_keys'   => $autoloaded_option_top_keys,
      'timestamp_utc'       => $timestamp_utc,
    );

    return $data;
  }

  /**
   * Format collected metrics for BigQuery insertion.
   *
   * @param array $metrics The collected metrics array.
   * @return array
   */
  public function format_for_bigquery($metrics)
  {
    $site_url = get_home_url();
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');

    return array(
      'platform'     => 'wordpress',
      'metric_type'   => 'db_health',
      'metric_key'     => 'autoloaded_options',
      'metric_value'   => $metrics['autoloaded_option_size_bytes'],
      'context'     => array(
        'autoloaded_option_count'    => $metrics['autoloaded_option_count'],
        'autoloaded_option_size_bytes' => $metrics['autoloaded_option_size_bytes'],
        'autoloaded_option_top_keys'    => $metrics['autoloaded_option_top_keys'],
        'site_identifier'        => $site_url,
      ),
      'timestamp_utc' => $timestamp,
    );
  }
}
