<?php

// Data Collector Class

if (! defined('ABSPATH')) {
  exit;
}

class CC_Adapter_Data_Collector
{

  //Get all collected data
  
  public function get_data()
  {
    $cached = get_transient('cc_adapter_metrics');

    if ($cached) {
      return $cached;
    }

    return $this->collect_autoloaded_options();
  }

  // Collect autoloaded options metrics
 
  public function collect_autoloaded_options()
  {
    global $wpdb;

    // Get autoloaded options count
    $autoloaded_option_count = intval($wpdb->get_var(
      "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'"
    ));

    // Get autoloaded options total size
    $size_result = $wpdb->get_var(
      "SELECT SUM(OCTET_LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
    );
    $autoloaded_option_size_bytes = $size_result ? intval($size_result) : 0;

    // Get top 5 autoloaded options by size with their sizes
    $top_options = $wpdb->get_results(
      "SELECT option_name, OCTET_LENGTH(option_value) as size FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY size DESC LIMIT 5"
    );

    $autoloaded_option_top_keys = array();
    if ($top_options) {
      foreach ($top_options as $option) {
        $autoloaded_option_top_keys[$option->option_name] = intval($option->size);
      }
    }

    // Get current UTC timestamp
    $timestamp_utc = gmdate('Y-m-d H:i:s');

    $data = array(
      'autoloaded_option_count'      => $autoloaded_option_count,
      'autoloaded_option_size_bytes' => $autoloaded_option_size_bytes,
      'autoloaded_option_top_keys'   => $autoloaded_option_top_keys,
      'timestamp_utc'                => $timestamp_utc,
    );

    // Cache for 1 hour
    set_transient('cc_adapter_metrics', $data, HOUR_IN_SECONDS);

    return $data;
  }

  // Format data for BigQuery
  
  public function format_for_bigquery($metrics)
  {
    $site_url = get_home_url();
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');

    return array(
      'platform'     => 'wordpress',
      'metric_type'  => 'db_health',
      'metric_key'   => 'autoloaded_options',
      'metric_value' => $metrics['autoloaded_option_size_bytes'],
      'context'      => array(
        'autoloaded_option_count'    => $metrics['autoloaded_option_count'],
        'autoloaded_option_size_bytes' => $metrics['autoloaded_option_size_bytes'],
        'autoloaded_option_top_keys' => $metrics['autoloaded_option_top_keys'],
        'site_identifier'           => $site_url,
      ),
      'timestamp_utc' => $timestamp,
    );
  }
}
