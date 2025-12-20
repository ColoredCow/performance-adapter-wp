<?php

if (! defined('ABSPATH')) {
  exit;
}

class CC_Adapter_Data_Collector
{
  public function get_data()
  {
    return $this->collect_autoloaded_options();
  }

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
        $key = sanitize_key($option->option_name);
        $autoloaded_option_top_keys[$key] = intval($option->size);
      }
    }

<<<<<<< Updated upstream
    $timestamp_utc = gmdate('Y-m-d H:i:s');

    $data = array(
      'autoloaded_option_count'      => $autoloaded_option_count,
      'autoloaded_option_size_bytes' => $autoloaded_option_size_bytes,
      'autoloaded_option_top_keys'   => $autoloaded_option_top_keys,
      'timestamp_utc'                => $timestamp_utc,
=======
    return array(
      'autoloaded_option' => array(
        'count'         => $autoloaded_option_count,
        'size_bytes'    => $autoloaded_option_size_bytes,
        'top_size_keys' => $autoloaded_option_top_keys,
      )
>>>>>>> Stashed changes
    );
  }

  /**
   * Format data for BigQuery Schema
   */
  public function format_for_bigquery($metrics)
  {
    $site_url = get_site_url();
    $timestamp = gmdate('Y-m-d H:i:s');

    // Create the vertical numbered list for metric_key
    $vertical_keys = "";
    if (!empty($metrics['autoloaded_option']['top_size_keys'])) {
      $i = 1;
      foreach ($metrics['autoloaded_option']['top_size_keys'] as $key => $size) {
        $vertical_keys .= "{$i}. {$key} (" . round($size / 1024, 2) . " KB)\n";
        $i++;
      }
    }

    // Return SINGLE row
    return array(
      'platform'          => 'WordPress',
      'metric_key'        => trim($vertical_keys),
      'metric_count'      => (string) $metrics['autoloaded_option']['count'],
      'metric_total_size' => round($metrics['autoloaded_option']['size_bytes'] / 1024, 2) . " KB",
      'site_url'          => $site_url,
      'timestamp_utc'     => $timestamp,
    );
  }
}
