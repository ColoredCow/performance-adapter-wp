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
			$this->collect_woo_order_metrics()
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
	 * Collect WooCommerce order table metrics.
	 *
	 * @return array WooCommerce order metrics.
	 */
	public function collect_woo_order_metrics() {
		global $wpdb;

		$db_name = DB_NAME;

		$items_size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT (data_length + index_length) / 1024 / 1024
				FROM information_schema.TABLES
				WHERE table_schema = %s
				AND table_name = %s',
				$db_name,
				$wpdb->prefix . 'woocommerce_order_items'
			)
		);

		$itemmeta_size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT (data_length + index_length) / 1024 / 1024
				FROM information_schema.TABLES
				WHERE table_schema = %s
				AND table_name = %s',
				$db_name,
				$wpdb->prefix . 'woocommerce_order_itemmeta'
			)
		);

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$oldest_order_date = $wpdb->get_var(
				"SELECT MIN(date_created_gmt) FROM {$wpdb->prefix}wc_orders WHERE status != 'trash'"
			);
		} else {
			$oldest_order_date = $wpdb->get_var(
				"SELECT MIN(post_date_gmt) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status != 'trash'"
			);
		}

		return array(
			'woo' => array(
				'order_items_size_mb'    => $items_size ? round( floatval( $items_size ), 4 ) : 0.0,
				'order_itemmeta_size_mb' => $itemmeta_size ? round( floatval( $itemmeta_size ), 4 ) : 0.0,
				'oldest_order_date'      => $oldest_order_date ? gmdate( 'Y-m-d', strtotime( $oldest_order_date ) ) : null,
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
			'timestamp_utc'              => $timestamp,
			'autoloaded_option_count'    => $metrics['autoloaded_option']['count'],
			'autoloaded_option_size'     => $metrics['autoloaded_option']['size_bytes'],
			'site_url'                   => $site_url,
			'woo_order_items_size_mb'    => $metrics['woo']['order_items_size_mb'],
			'woo_order_itemmeta_size_mb' => $metrics['woo']['order_itemmeta_size_mb'],
			'woo_oldest_order_date'      => $metrics['woo']['oldest_order_date'],
		);
	}
}
