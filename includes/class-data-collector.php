<?php

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
    // Now just an alias for the core collection method.
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

    // Autoload clause handles various ways options can be set to autoload
    $autoload_clause = "autoload IN ('yes', 'on', 'auto', 'auto-on')";

    // 1. Count
    $autoloaded_option_count = intval($wpdb->get_var(
      "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$autoload_clause}"
    ));

    // 2. Total Size
    $size_result = $wpdb->get_var(
      "SELECT SUM(OCTET_LENGTH(option_value)) FROM {$wpdb->options} WHERE {$autoload_clause}"
    );
    $autoloaded_option_size_bytes = $size_result ? intval($size_result) : 0;

    // 3. Top 10 Keys by Size
    $top_options = $wpdb->get_results(
      "SELECT option_name, OCTET_LENGTH(option_value) as size 
             FROM {$wpdb->options} 
             WHERE {$autoload_clause} 
             ORDER BY size DESC LIMIT 10"
    );

    $autoloaded_option_top_keys = array();
    if ($top_options) {
      foreach ($top_options as $option) {
        // Ensure the option name is clean before using it as a key
        $key = sanitize_key($option->option_name);
        $autoloaded_option_top_keys[$key] = intval($option->size);
      }
    }

    $timestamp_utc = gmdate('Y-m-d H:i:s');

    $data = array(
      'autoloaded_option_count'      => $autoloaded_option_count,
      'autoloaded_option_size_bytes' => $autoloaded_option_size_bytes,
      'autoloaded_option_top_keys'   => $autoloaded_option_top_keys,
      'timestamp_utc'                => $timestamp_utc,
    );

    return $data;
  }

  // NOTE: The public function format_for_bigquery() has been removed.
}
