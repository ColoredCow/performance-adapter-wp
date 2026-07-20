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

	const WOO_METRICS_CACHE_KEY = 'properf_woo_metrics_snapshot';

	/**
	 * Get collected data.
	 *
	 * @return array Collected metrics.
	 */
	public function get_data() {
		return array_merge(
			$this->collect_autoloaded_options(),
			$this->collect_woo_order_metrics(),
			$this->collect_server_metrics()
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
				'error'         => null,
			),
		);
	}

	/**
	 * Collect WooCommerce order table metrics.
	 *
	 * @return array WooCommerce order metrics.
	 */
	public function collect_woo_order_metrics() {
		if ( ! function_exists( 'WC' ) ) {
			return array(
				'woo' => array(
					'order_items_size_mb'         => null,
					'order_itemmeta_size_mb'       => null,
					'oldest_order_date'            => null,
					'latest_order_date'            => null,
					'orders_older_than_threshold'  => null,
					'total_orders'                 => null,
					'threshold_years'              => intval( get_option( 'properf_archival_threshold_years', 2 ) ),
					'last_archival_date'           => null,
					'query_execution_ms'           => null,
					'baseline_qet_ms'              => null,
					'baseline_qet_source'          => null,
					'archival_signal_active'       => false,
					'alert_threshold_mb'           => null,
				),
			);
		}

		// Cached for 1 hour; invalidated on push and on relevant settings changes.
		$cached = get_transient( self::WOO_METRICS_CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

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

		$threshold_years = intval( get_option( 'properf_archival_threshold_years', 2 ) );

		if ( $use_hpos ) {
			$oldest_order_date  = $wpdb->get_var(
				"SELECT MIN(date_created_gmt) FROM {$wpdb->prefix}wc_orders WHERE status != 'trash'"
			);
			$latest_order_date  = $wpdb->get_var(
				"SELECT MAX(date_created_gmt) FROM {$wpdb->prefix}wc_orders WHERE status != 'trash'"
			);
			$orders_older_than_threshold = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders
					WHERE date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d YEAR)
					AND status != 'trash'",
					$threshold_years
				)
			);
			$total_orders = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE status != 'trash'"
			);
		} else {
			$oldest_order_date  = $wpdb->get_var(
				"SELECT MIN(post_date_gmt) FROM {$wpdb->posts}
				WHERE post_type = 'shop_order' AND post_status != 'trash'"
			);
			$latest_order_date  = $wpdb->get_var(
				"SELECT MAX(post_date_gmt) FROM {$wpdb->posts}
				WHERE post_type = 'shop_order' AND post_status != 'trash'"
			);
			$orders_older_than_threshold = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					WHERE post_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d YEAR)
					AND post_type = 'shop_order' AND post_status != 'trash'",
					$threshold_years
				)
			);
			$total_orders = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = 'shop_order' AND post_status != 'trash'"
			);
		}

		$qet_start = microtime( true );
		if ( $use_hpos ) {
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(oi.order_item_id)
					FROM {$wpdb->prefix}woocommerce_order_items oi
					INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
					WHERE o.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d YEAR)
					AND o.status != 'trash'",
					$threshold_years
				)
			);
		} else {
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(oi.order_item_id)
					FROM {$wpdb->prefix}woocommerce_order_items oi
					INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id
					WHERE o.post_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d YEAR)
					AND o.post_type = 'shop_order'
					AND o.post_status != 'trash'",
					$threshold_years
				)
			);
		}
		$query_execution_ms = (int) round( ( microtime( true ) - $qet_start ) * 1000 );

		$last_archival_date = get_option( 'properf_last_archival_date', null ) ?: null;
		$baseline           = $this->get_baseline_qet( $last_archival_date );

		$alert_threshold_mb     = get_option( 'properf_order_itemmeta_db_alert_threshold', '' );
		$itemmeta_mb            = null !== $itemmeta_size ? round( floatval( $itemmeta_size ), 4 ) : null;
		$archival_signal_active = false;
		if ( null !== $itemmeta_mb && '' !== $alert_threshold_mb && (int) $alert_threshold_mb > 0 ) {
			$archival_signal_active = $itemmeta_mb >= (float) $alert_threshold_mb && $orders_older_than_threshold > 0;
		}

		$result = array(
			'woo' => array(
				'order_items_size_mb'         => $items_size ? round( floatval( $items_size ), 4 ) : 0.0,
				'order_itemmeta_size_mb'      => $itemmeta_mb,
				'oldest_order_date'           => $oldest_order_date ? gmdate( 'Y-m-d', strtotime( $oldest_order_date . ' UTC' ) ) : null,
				'latest_order_date'           => $latest_order_date ? gmdate( 'Y-m-d', strtotime( $latest_order_date . ' UTC' ) ) : null,
				'orders_older_than_threshold' => $orders_older_than_threshold,
				'total_orders'                => $total_orders,
				'threshold_years'             => $threshold_years,
				'last_archival_date'          => $last_archival_date,
				'query_execution_ms'          => $query_execution_ms,
				'baseline_qet_ms'             => $baseline['ms'],
				'baseline_qet_source'         => $baseline['source'],
				'archival_signal_active'      => $archival_signal_active,
				'alert_threshold_mb'          => '' !== $alert_threshold_mb ? (int) $alert_threshold_mb : null,
			),
		);

		set_transient( self::WOO_METRICS_CACHE_KEY, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Collect server-level metrics: plugin counts, hook callback count, and DB table sizes.
	 *
	 * @return array Server metrics keyed under 'server'.
	 */
	public function collect_server_metrics() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		validate_active_plugins();
		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$active_count   = count( $active_plugins );
		$inactive_count = count( $all_plugins ) - $active_count;

		global $wp_filter;
		$hook_count = 0;
		foreach ( $wp_filter as $hook ) {
			foreach ( $hook->callbacks as $priority_callbacks ) {
				$hook_count += count( $priority_callbacks );
			}
		}

		global $wpdb;
		$db_name = DB_NAME;
		$tables  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME as table_name,
					ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 4) as size_mb
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s
				ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
				$db_name
			)
		);

		$db_table_sizes    = array();
		$total_db_size_mb  = 0.0;
		$had_invalid_names = false;
		if ( $tables ) {
			foreach ( $tables as $table ) {
				$raw_name = $table->table_name;
				$clean    = iconv( 'UTF-8', 'UTF-8//IGNORE', $raw_name );
				if ( $clean !== $raw_name ) {
					$had_invalid_names = true;
					if ( '' === $clean ) {
						continue;
					}
				}
				$size_mb                      = floatval( $table->size_mb );
				$db_table_sizes[ $clean ]     = $size_mb;
				$total_db_size_mb            += $size_mb;
			}
		}
		if ( $had_invalid_names ) {
			update_option( 'properf_table_name_encoding_warning', gmdate( 'Y-m-d H:i:s' ) );
		} else {
			delete_option( 'properf_table_name_encoding_warning' );
		}

		return array(
			'server' => array(
				'active_plugin_count'   => $active_count,
				'inactive_plugin_count' => $inactive_count,
				'hook_count'            => $hook_count,
				'db_table_sizes'        => $db_table_sizes,
				'total_db_size_mb'      => round( $total_db_size_mb, 4 ),
			),
		);
	}

	/**
	 * Record a QET reading for baseline computation. Call after each successful push.
	 * Multiple same-day pushes are averaged into a single daily entry.
	 *
	 * Note: concurrent pushes (e.g. cron mid-run + manual click) can both read
	 * properf_qet_history and overwrite each other — WP options have no atomic update.
	 * Low risk at daily cron frequency; revisit if cron is raised.
	 *
	 * @param int $qet_ms QET in milliseconds.
	 */
	public function record_qet_reading( $qet_ms ) {
		if ( null === $qet_ms ) {
			return;
		}

		$today   = gmdate( 'Y-m-d' );
		$history = get_option( 'properf_qet_history', array() );

		$last = ! empty( $history ) ? end( $history ) : null;
		if ( $last && gmdate( 'Y-m-d', $last['ts'] ) === $today ) {
			$existing_count                    = isset( $last['count'] ) ? $last['count'] : 1;
			$new_count                         = $existing_count + 1;
			$history[ count( $history ) - 1 ] = array(
				'ts'    => $last['ts'],
				'ms'    => (int) round( ( $last['ms'] * $existing_count + $qet_ms ) / $new_count ),
				'count' => $new_count,
			);
			update_option( 'properf_qet_history', $history, false );
			return;
		}

		$history[] = array(
			'ts'    => time(),
			'ms'    => (int) $qet_ms,
			'count' => 1,
		);

		if ( count( $history ) > 30 ) {
			$history = array_slice( $history, -30 );
		}

		update_option( 'properf_qet_history', $history, false );
	}

	/**
	 * Refresh the baseline fields in the cached snapshot after a new QET reading is recorded.
	 * Keeps the rest of the snapshot intact so the dashboard reflects the post-push baseline immediately.
	 * Returns the updated baseline array, or null if no snapshot was cached.
	 *
	 * Most calls are no-ops (locked baseline returns the same value either way).
	 * Matters at lock-in (10th post-archival reading) and when the lowest-10
	 * fallback shifts down — both move the baseline in ways the just-computed
	 * snapshot won't reflect.
	 *
	 * @return array|null {ms: int|null, source: string|null}, or null if no cached snapshot exists.
	 */
	private function refresh_cached_baseline() {
		$cached = get_transient( self::WOO_METRICS_CACHE_KEY );
		if ( false === $cached ) {
			return null;
		}
		$baseline = $this->get_baseline_qet( $cached['woo']['last_archival_date'] );
		$cached['woo']['baseline_qet_ms']     = $baseline['ms'];
		$cached['woo']['baseline_qet_source'] = $baseline['source'];
		set_transient( self::WOO_METRICS_CACHE_KEY, $cached, HOUR_IN_SECONDS );
		return $baseline;
	}

	/**
	 * Collect metrics, record QET, push to BigQuery, and persist sync status.
	 * Returns result so callers can handle their own notifications.
	 *
	 * @return array { bool $success, string $error }
	 */
	public function collect_and_push() {
		require_once PROPERF_DIR . 'includes/class-bigquery-client.php';

		delete_transient( self::WOO_METRICS_CACHE_KEY );

		try {
			$metrics = $this->get_data();
		} catch ( Exception $e ) {
			error_log( 'ProPerf Error: collect_and_push failed during data collection — ' . $e->getMessage() );
			return array( 'success' => false, 'error' => $e->getMessage() );
		}

		$this->record_qet_reading( $metrics['woo']['query_execution_ms'] );
		$baseline = $this->refresh_cached_baseline();
		if ( null !== $baseline ) {
			$metrics['woo']['baseline_qet_ms']     = $baseline['ms'];
			$metrics['woo']['baseline_qet_source'] = $baseline['source'];
		}
		$bq_client = new ProPerf_BigQuery_Client();
		$success   = $bq_client->push_metrics( $metrics );

		update_option( 'properf_bq_last_sync', time(), false );
		update_option( 'properf_bq_last_sync_status', $success ? 'success' : 'error', false );

		$error = '';
		if ( $success ) {
			delete_option( 'properf_bq_last_sync_error' );
		} else {
			$error = $bq_client->get_last_error();
			update_option( 'properf_bq_last_sync_error', $error, false );
		}

		return array( 'success' => $success, 'error' => $error );
	}

	/**
	 * Compute baseline QET. Locks after 10 post-archival readings once available.
	 * Falls back to avg of 10 lowest readings from rolling history.
	 *
	 * @param string|null $last_archival_date Date string Y-m-d or null.
	 * @return array {ms: int|null, source: string|null}
	 */
	private function get_baseline_qet( $last_archival_date ) {
		$locked = get_option( 'properf_baseline_qet_ms', null );
		if ( null !== $locked ) {
			return array(
				'ms'     => (int) $locked,
				'source' => 'post-archival',
			);
		}

		$history = get_option( 'properf_qet_history', array() );

		if ( empty( $history ) ) {
			return array( 'ms' => null, 'source' => null );
		}

		if ( $last_archival_date ) {
			$archival_ts   = strtotime( $last_archival_date . ' UTC' );
			$post_archival = array_values(
				array_filter(
					$history,
					function ( $r ) use ( $archival_ts ) {
						return $r['ts'] >= $archival_ts;
					}
				)
			);

			if ( count( $post_archival ) >= 10 ) {
				$readings   = array_slice( $post_archival, 0, 10 );
				$locked_ms  = (int) round( array_sum( array_column( $readings, 'ms' ) ) / 10 );
				update_option( 'properf_baseline_qet_ms', $locked_ms, false );
				return array(
					'ms'     => $locked_ms,
					'source' => 'post-archival',
				);
			}

			if ( ! empty( $post_archival ) ) {
				$all_ms = array_column( $post_archival, 'ms' );
				return array(
					'ms'     => (int) round( array_sum( $all_ms ) / count( $all_ms ) ),
					'source' => 'post-archival-pending:' . count( $post_archival ),
				);
			}
		}

		$all_ms = array_column( $history, 'ms' );
		sort( $all_ms );
		$lowest = array_slice( $all_ms, 0, 10 );
		return array(
			'ms'     => (int) round( array_sum( $lowest ) / count( $lowest ) ),
			'source' => 'lowest-10:' . count( $lowest ),
		);
	}

	/**
	 * Delete the cached WooCommerce metrics snapshot.
	 * Called by settings hooks when archival date or threshold changes.
	 */
	public static function bust_metrics_cache() {
		delete_transient( self::WOO_METRICS_CACHE_KEY );
	}

	/**
	 * Format metrics for BigQuery.
	 *
	 * @param array $metrics Metrics data.
	 * @return array Formatted data for BigQuery.
	 */
	public function format_for_bigquery( $metrics ) {
		$site_url    = get_site_url();
		$timestamp   = gmdate( 'Y-m-d H:i:s' );
		$table_sizes = array();
		foreach ( $metrics['server']['db_table_sizes'] as $name => $size_mb ) {
			$table_sizes[] = array(
				'name'    => $name,
				'size_mb' => $size_mb,
			);
		}

		return array(
			'timestamp_utc'                   => $timestamp,
			'autoloaded_option_count'         => $metrics['autoloaded_option']['count'],
			'autoloaded_option_size'          => $metrics['autoloaded_option']['size_bytes'],
			'site_url'                        => $site_url,
			'woo_order_items_size_mb'         => $metrics['woo']['order_items_size_mb'],
			'woo_order_itemmeta_size_mb'      => $metrics['woo']['order_itemmeta_size_mb'],
			'woo_oldest_order_date'           => $metrics['woo']['oldest_order_date'],
			'woo_latest_order_date'           => $metrics['woo']['latest_order_date'],
			'woo_orders_older_than_threshold' => $metrics['woo']['orders_older_than_threshold'],
			'woo_total_orders'                => $metrics['woo']['total_orders'],
			'woo_last_archival_date'          => $metrics['woo']['last_archival_date'],
			'woo_query_execution_ms'          => $metrics['woo']['query_execution_ms'],
			'woo_baseline_qet_ms'             => $metrics['woo']['baseline_qet_ms'],
			'woo_archival_signal_active'      => $metrics['woo']['archival_signal_active'],
			'woo_alert_threshold_mb'          => $metrics['woo']['alert_threshold_mb'],
			'active_plugin_count'             => $metrics['server']['active_plugin_count'],
			'inactive_plugin_count'           => $metrics['server']['inactive_plugin_count'],
			'hook_count'                      => $metrics['server']['hook_count'],
			'total_db_size_mb'                => $metrics['server']['total_db_size_mb'],
			'db_table_sizes'                  => $table_sizes,
		);
	}
}
