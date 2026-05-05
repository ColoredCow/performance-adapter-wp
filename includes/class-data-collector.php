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
		return array_merge(
			$this->collect_autoloaded_options(),
			$this->collect_plugin_metrics(),
			$this->collect_hook_metrics(),
			$this->collect_db_table_metrics()
		);
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
	 * Collect plugin metrics.
	 *
	 * Counts active and inactive plugins installed on the site.
	 *
	 * @return array Plugin metrics.
	 */
	public function collect_plugin_metrics() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$active_count   = count( $active_plugins );
		$total_count    = count( $all_plugins );
		$inactive_count = $total_count - $active_count;

		return array(
			'plugins' => array(
				'active_count'   => $active_count,
				'inactive_count' => $inactive_count,
				'total_count'    => $total_count,
			),
		);
	}

	/**
	 * Collect hook metrics.
	 *
	 * Counts registered hooks via $wp_filter.
	 *
	 * @return array Hook metrics.
	 */
	public function collect_hook_metrics() {
		global $wp_filter;
		$hook_count = is_array( $wp_filter ) ? count( $wp_filter ) : 0;

		return array(
			'hooks' => array(
				'registered_count' => $hook_count,
			),
		);
	}

	/**
	 * Collect database table size metrics.
	 *
	 * Reports total DB size and top 10 tables by size.
	 *
	 * @return array Database size metrics.
	 */
	public function collect_db_table_metrics() {
		global $wpdb;

		$total_size = $wpdb->get_var(
			'SELECT SUM(data_length + index_length)
			 FROM information_schema.tables
			 WHERE table_schema = DATABASE()'
		);

		$top_tables = $wpdb->get_results(
			'SELECT table_name, ROUND(data_length + index_length) AS size_bytes
			 FROM information_schema.tables
			 WHERE table_schema = DATABASE()
			 ORDER BY size_bytes DESC LIMIT 10'
		);

		$top_tables_map = array();
		if ( $top_tables ) {
			foreach ( $top_tables as $table ) {
				$top_tables_map[ $table->table_name ] = intval( $table->size_bytes );
			}
		}

		return array(
			'database' => array(
				'total_size_bytes' => $total_size ? intval( $total_size ) : 0,
				'top_tables'       => $top_tables_map,
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
		$site_url  = get_site_url();
		$timestamp = gmdate( 'Y-m-d H:i:s' );

		return array(
			'timestamp_utc'           => $timestamp,
			'site_url'                => $site_url,
			// Autoload.
			'autoloaded_option_count' => $metrics['autoloaded_option']['count'],
			'autoloaded_option_size'  => $metrics['autoloaded_option']['size_bytes'],
			// Plugins.
			'active_plugin_count'     => $metrics['plugins']['active_count'],
			'inactive_plugin_count'   => $metrics['plugins']['inactive_count'],
			'total_plugin_count'      => $metrics['plugins']['total_count'],
			// Hooks.
			'hook_count'              => $metrics['hooks']['registered_count'],
			// Database.
			'db_total_size_bytes'     => $metrics['database']['total_size_bytes'],
		);
	}
}
