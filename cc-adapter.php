<?php

/**
 * Plugin Name: CC Performance Adapter
 * Plugin URI: https://example.com/cc-adapter
 * Description: Collects and displays database health metrics (autoloaded options)
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

if (! defined('ABSPATH')) {
  exit;
}

define('CC_ADAPTER_DIR', plugin_dir_path(__FILE__));
define('CC_ADAPTER_URL', plugin_dir_url(__FILE__));
define('CC_ADAPTER_VERSION', '1.0.0');
require_once CC_ADAPTER_DIR . 'includes/class-data-collector.php';

function cc_adapter_init()
{
  add_action('admin_menu', 'cc_adapter_add_admin_menu');
}
add_action('plugins_loaded', 'cc_adapter_init');
add_action('wp', function () {
  if (!wp_next_scheduled('cc_adapter_collect_metrics')) {
    $time = cc_adapter_get_next_5pm();
    wp_schedule_event($time, 'daily', 'cc_adapter_collect_metrics');
  }
});

function cc_adapter_get_next_5pm()
{
  $tz = get_option('timezone_string') ?: 'UTC';
  $target_tz = new DateTimeZone($tz);

  $now = new DateTime('now', $target_tz);
  $today_5pm = new DateTime('17:00:00', $target_tz);

  if ($today_5pm <= $now) {
    $today_5pm->add(new DateInterval('P1D'));
  }
  return $today_5pm->getTimestamp();
}


function cc_adapter_add_admin_menu()
{
  add_submenu_page(
    'tools.php',
    'Costwatch',
    'Costwatch',
    'manage_options',
    'cc-adapter-costwatch',
    'cc_adapter_render_page'
  );
}

function cc_adapter_render_page()
{
  if (! current_user_can('manage_options')) {
    wp_die('Unauthorized');
  }

  $data = cc_adapter_get_live_data();

  $top_keys = isset($data['autoloaded_option_top_keys']) && is_array($data['autoloaded_option_top_keys']) ? $data['autoloaded_option_top_keys'] : array();

?>
  <div class="wrap">
    <h1><?php esc_html_e('Costwatch Dashboard', 'cc-adapter'); ?></h1>

    <div style="margin-bottom: 20px;">
      <p>
        <strong>Data Last Collected:</strong> <?php echo esc_html($data['timestamp_utc']); ?> (UTC)
      </p>
    </div>

    <h2><?php esc_html_e('Summary Metrics', 'cc-adapter'); ?></h2>
    <table class="widefat striped">
      <thead>
        <tr>
          <th><?php esc_html_e('Metric', 'cc-adapter'); ?></th>
          <th><?php esc_html_e('Value', 'cc-adapter'); ?></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong><?php esc_html_e('Autoloaded Option Count', 'cc-adapter'); ?></strong></td>
          <td><?php echo esc_html(isset($data['autoloaded_option_count']) ? number_format($data['autoloaded_option_count']) : 'N/A'); ?></td>
        </tr>
        <tr>
          <td><strong><?php esc_html_e('Autoloaded Option Size (Bytes)', 'cc-adapter'); ?></strong></td>
          <td><?php echo esc_html(isset($data['autoloaded_option_size_bytes']) ? number_format($data['autoloaded_option_size_bytes']) . ' bytes' : 'N/A'); ?></td>
        </tr>
        <tr>
          <td><strong><?php esc_html_e('Last Fetched (Live)', 'cc-adapter'); ?></strong></td>
          <td><?php echo esc_html(isset($data['timestamp_utc']) ? $data['timestamp_utc'] : 'N/A'); ?> (UTC)</td>
        </tr>
      </tbody>
    </table>

    <h2 style="margin-top: 30px;"><?php esc_html_e('Autoloaded Option Top Keys', 'cc-adapter'); ?></h2>
    <?php if (! empty($top_keys)) : ?>
      <table class="widefat striped">
        <thead>
          <tr>
            <th><strong><?php esc_html_e('Option Name', 'cc-adapter'); ?></strong></th>
            <th><strong><?php esc_html_e('Size (Bytes)', 'cc-adapter'); ?></strong></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($top_keys as $key => $size) : ?>
            <tr>
              <td><?php echo esc_html($key); ?></td>
              <td><?php echo esc_html(number_format($size) . ' bytes'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else : ?>
      <p><?php esc_html_e('No autoloaded option keys found or an error occurred. Check database permissions.', 'cc-adapter'); ?></p>
    <?php endif; ?>
  </div>

<?php
}

function cc_adapter_get_live_data()
{
  if (! class_exists('CC_Adapter_Data_Collector')) {
    return array(
      'autoloaded_option_count'      => 'Error: Collector Class Missing',
      'autoloaded_option_size_bytes' => 'Error',
      'autoloaded_option_top_keys'   => array(),
      'timestamp_utc'                => gmdate('Y-m-d H:i:s'),
    );
  }

  try {
    $collector = new CC_Adapter_Data_Collector();
    return $collector->get_data();
  } catch (Exception $e) {
    error_log('CC Adapter Error: ' . $e->getMessage());
    return array(
      'autoloaded_option_count'      => 'Error: ' . $e->getMessage(),
      'autoloaded_option_size_bytes' => 'N/A',
      'autoloaded_option_top_keys'   => array(),
      'timestamp_utc'                => gmdate('Y-m-d H:i:s'),
    );
  }
}

register_activation_hook(__FILE__, function () {
  wp_clear_scheduled_hook('cc_adapter_collect_metrics');
  $time = cc_adapter_get_next_5pm();
  wp_schedule_event($time, 'daily', 'cc_adapter_collect_metrics');

  error_log('CC Adapter scheduling hook created (though currently inert). Next run: ' . gmdate('Y-m-d H:i:s', $time) . ' UTC');
});

register_deactivation_hook(__FILE__, function () {
  wp_clear_scheduled_hook('cc_adapter_collect_metrics');
});

if (defined('WP_CLI') && WP_CLI) {
  class CC_Adapter_CLI_Command
  {
  
    public function status()
    {
      WP_CLI::line('Collecting current autoloaded options data...');

      try {
        $data = cc_adapter_get_live_data();

        WP_CLI::line('--- Autoloaded Options Health ---');
        WP_CLI::line(sprintf('Count: %s', number_format($data['autoloaded_option_count'])));
        WP_CLI::line(sprintf('Total Size: %s bytes', number_format($data['autoloaded_option_size_bytes'])));
        WP_CLI::line(sprintf('Timestamp (UTC): %s', $data['timestamp_utc']));
        WP_CLI::line('--- Top 10 Largest Keys ---');

        if (empty($data['autoloaded_option_top_keys'])) {
          WP_CLI::line('No top keys found.');
        } else {
          $keys = array();
          foreach ($data['autoloaded_option_top_keys'] as $key => $size) {
            $keys[] = array('Option Name' => $key, 'Size (Bytes)' => number_format($size));
          }
          WP_CLI\Utils\format_items('table', $keys, array('Option Name', 'Size (Bytes)'));
        }

        WP_CLI::success('Data collected successfully!');
      } catch (Exception $e) {
        WP_CLI::error('Failed to collect metrics: ' . $e->getMessage());
      }
    }
  }
  WP_CLI::add_command('cc-perf', 'CC_Adapter_CLI_Command');
}
