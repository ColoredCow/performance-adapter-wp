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

		$db_name  = DB_NAME;
		$use_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

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

		if ( $use_hpos ) {
			$oldest_order_date = $wpdb->get_var(
				"SELECT MIN(date_created_gmt) FROM {$wpdb->prefix}wc_orders WHERE status != 'trash'"
			);
			$orders_older_than_2y = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders
				WHERE date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 YEAR)
				AND status != 'trash'"
			);
		} else {
			$oldest_order_date = $wpdb->get_var(
				"SELECT MIN(post_date_gmt) FROM {$wpdb->posts}
				WHERE post_type = 'shop_order' AND post_status != 'trash'"
			);
			$orders_older_than_2y = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 YEAR)
				AND post_type = 'shop_order' AND post_status != 'trash'"
			);
		}

		$qet_start = microtime( true );
		if ( $use_hpos ) {
			$wpdb->get_var(
				"SELECT COUNT(oi.order_item_id)
				FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
				WHERE o.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 YEAR)"
			);
		} else {
			$wpdb->get_var(
				"SELECT COUNT(oi.order_item_id)
				FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id
				WHERE o.post_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 YEAR)
				AND o.post_type = 'shop_order'
				AND o.post_status != 'trash'"
			);
		}
		$query_execution_ms = (int) round( ( microtime( true ) - $qet_start ) * 1000 );

		$last_archival_date = get_option( 'properf_last_archival_date', null ) ?: null;
		$baseline           = $this->get_baseline_qet( $last_archival_date );

		return array(
			'woo' => array(
				'order_items_size_mb'    => $items_size ? round( floatval( $items_size ), 4 ) : 0.0,
				'order_itemmeta_size_mb' => $itemmeta_size ? round( floatval( $itemmeta_size ), 4 ) : 0.0,
				'oldest_order_date'      => $oldest_order_date ? gmdate( 'Y-m-d', strtotime( $oldest_order_date ) ) : null,
				'orders_older_than_2y'   => $orders_older_than_2y,
				'last_archival_date'     => $last_archival_date,
				'query_execution_ms'     => $query_execution_ms,
				'baseline_qet_ms'        => $baseline['ms'],
				'baseline_qet_source'    => $baseline['source'],
			),
		);
	}

	/**
	 * Record a QET reading for baseline computation. Call after each successful push.
	 * Stores at most one reading per calendar day.
	 *
	 * @param int $qet_ms QET in milliseconds.
	 */
	public function record_qet_reading( $qet_ms ) {
		$today   = gmdate( 'Y-m-d' );
		$history = get_option( 'properf_qet_history', array() );

		$last = ! empty( $history ) ? end( $history ) : null;
		if ( $last && gmdate( 'Y-m-d', $last['ts'] ) === $today ) {
			return;
		}

		$history[] = array(
			'ts' => time(),
			'ms' => (int) $qet_ms,
		);

		if ( count( $history ) > 30 ) {
			$history = array_slice( $history, -30 );
		}

		update_option( 'properf_qet_history', $history, false );
	}

	/**
	 * Compute baseline QET from stored history.
	 * Uses avg of first 5 post-archival readings if available, else avg of last 10.
	 *
	 * @param string|null $last_archival_date Date string Y-m-d or null.
	 * @return int|null Baseline in ms, or null if no history yet.
	 */
	private function get_baseline_qet( $last_archival_date ) {
		$history = get_option( 'properf_qet_history', array() );

		if ( empty( $history ) ) {
			return array( 'ms' => null, 'source' => null );
		}

		if ( $last_archival_date ) {
			$archival_ts   = strtotime( $last_archival_date );
			$post_archival = array_values(
				array_filter(
					$history,
					function ( $r ) use ( $archival_ts ) {
						return $r['ts'] >= $archival_ts;
					}
				)
			);

			if ( count( $post_archival ) >= 5 ) {
				$readings = array_slice( $post_archival, 0, 5 );
				return array(
					'ms'     => (int) round( array_sum( array_column( $readings, 'ms' ) ) / 5 ),
					'source' => 'post-archival',
				);
			}
		}

		$last_ten = array_slice( $history, -10 );
		return array(
			'ms'     => (int) round( array_sum( array_column( $last_ten, 'ms' ) ) / count( $last_ten ) ),
			'source' => 'rolling',
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
			'timestamp_utc'              => $timestamp,
			'autoloaded_option_count'    => $metrics['autoloaded_option']['count'],
			'autoloaded_option_size'     => $metrics['autoloaded_option']['size_bytes'],
			'site_url'                   => $site_url,
			'woo_order_items_size_mb'    => $metrics['woo']['order_items_size_mb'],
			'woo_order_itemmeta_size_mb' => $metrics['woo']['order_itemmeta_size_mb'],
			'woo_oldest_order_date'      => $metrics['woo']['oldest_order_date'],
			'woo_orders_older_than_2y'   => $metrics['woo']['orders_older_than_2y'],
			'woo_last_archival_date'     => $metrics['woo']['last_archival_date'],
			'woo_query_execution_ms'     => $metrics['woo']['query_execution_ms'],
			'woo_baseline_qet_ms'        => $metrics['woo']['baseline_qet_ms'],
		);
	}
}
