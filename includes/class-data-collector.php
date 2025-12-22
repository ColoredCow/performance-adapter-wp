<?php
/**
 * Data Collector Class
 *
 * @package ProPerf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProPerf_Data_Collector
 */
class ProPerf_Data_Collector {

	/**
	 * Get collected data.
	 *
	 * @return array Collected metrics.
	 */
	public function get_data() {
		return $this->collect_autoloaded_options();
	}

	/**
	 * Collect autoloaded options metrics.
	 *
	 * @return array Autoloaded options data.
	 */
	public function collect_autoloaded_options() {
		global $wpdb;
		$autoload_clause = "autoload IN ('yes', 'on', 'auto', 'auto-on')";

		$autoloaded_option_count = intval(
			$wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE {$autoload_clause}"
			)
		);

		$size_result = $wpdb->get_var(
			"SELECT SUM(OCTET_LENGTH(option_value)) FROM {$wpdb->options} WHERE {$autoload_clause}"
		);
		$autoloaded_option_size_bytes = $size_result ? intval( $size_result ) : 0;

		$top_options = $wpdb->get_results(
			"SELECT option_name, OCTET_LENGTH(option_value) as size 
			FROM {$wpdb->options} 
			WHERE {$autoload_clause} 
			ORDER BY size DESC LIMIT 10"
		);

		$autoloaded_option_top_keys = array();
		if ( $top_options ) {
			foreach ( $top_options as $option ) {
				$key = sanitize_key( $option->option_name );
				$autoloaded_option_top_keys[ $key ] = intval( $option->size );
			}
		}

		return array(
			'autoloaded_option' => array(
				'count'         => $autoloaded_option_count,
				'size_bytes'    => $autoloaded_option_size_bytes,
				'top_size_keys' => $autoloaded_option_top_keys,
			),
		);
	}

	/**
	 * Format metrics for BigQuery.
	 *
	 * @param array $metrics Metrics data.
	 * @return array Formatted data for BigQuery.
	 */
	public function format_for_bigquery( $metrics ) {
		$site_url = get_site_url();
		$timestamp = gmdate( 'Y-m-d H:i:s' );

		return array(
			'timestamp_utc'          => $timestamp,
			'autoloaded_option_count' => $metrics['autoloaded_option']['count'],
			'autoloaded_option_size'  => $metrics['autoloaded_option']['size_bytes'],
			'site_url'               => $site_url,
		);
	}
}
