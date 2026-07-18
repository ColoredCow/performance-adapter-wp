<?php
/**
 * Live Data Class
 *
 * @package ProPerf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProPerf_Live_Data
 */
class ProPerf_Live_Data {

	/**
	 * Default metrics structure used as a safe fallback.
	 *
	 * @return array Default metrics.
	 */
	private static function default_metrics() {
		return array(
			'autoloaded_option' => array(
				'count'         => 0,
				'size_bytes'    => 0,
				'top_size_keys' => array(),
				'error'         => null,
			),
			'woo'               => array(
				'order_items_size_mb'         => 0.0,
				'order_itemmeta_size_mb'      => 0.0,
				'oldest_order_date'           => null,
				'latest_order_date'           => null,
				'orders_older_than_threshold' => 0,
				'total_orders'                => 0,
				'threshold_years'             => intval( get_option( 'properf_archival_threshold_years', 2 ) ),
				'last_archival_date'          => null,
				'query_execution_ms'          => 0,
				'baseline_qet_ms'             => null,
				'baseline_qet_source'         => null,
				'archival_signal_active'      => false,
				'alert_threshold_mb'          => null,
			),
		);
	}

	/**
	 * Get live data from collector.
	 *
	 * @return array Metrics data.
	 */
	public static function get_live_data() {
		if ( ! class_exists( 'ProPerf_Data_Collector' ) ) {
			$defaults = self::default_metrics();
			$defaults['autoloaded_option']['error'] = __( 'Error: Collector Class Missing', 'properf' );
			return $defaults;
		}

		try {
			$collector = new ProPerf_Data_Collector();
			return $collector->get_data();
		} catch ( Exception $e ) {
			error_log( 'ProPerf Error: ' . $e->getMessage() );
			$defaults = self::default_metrics();
			$defaults['autoloaded_option']['error'] = $e->getMessage();
			return $defaults;
		}
	}
}
