<?php

/**
 * Plugin Name: ProPerf WordPress Adapter
 * Plugin URI: https://coloredcow.com
 * Description: Collects and displays database health metrics (autoloaded options)
 * Version: 1.0.0
 * Author: ColoredCow
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
    'ProPerf',
    'ProPerf',
    'manage_options',
    'cc-adapter-properf',
    'cc_adapter_render_page'
  );
}

function cc_adapter_render_page()
{
  if (! current_user_can('manage_options')) {
    wp_die('Unauthorized');
  }

  $metrics = cc_adapter_get_live_data();

  $autoloaded_data_metrics = $metrics['autoloaded_option'];

  $count         = $autoloaded_data_metrics['count'];
  $size_bytes    = $autoloaded_data_metrics['size_bytes'];
  $top_size_keys = $autoloaded_data_metrics['top_size_keys'];
?>
  <div class="wrap">
    <h1><?php esc_html_e('ProPerf WordPress Metrics', 'cc-adapter'); ?></h1>

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
          <td><?php echo esc_html( number_format( $count ) ); ?></td>
        </tr>
        <tr>
          <td><strong><?php esc_html_e('Autoloaded Option Size', 'cc-adapter'); ?></strong></td>
          <td><?php printf('%d KB', $size_bytes / 1024 ); ?></td>
        </tr>
      </tbody>
    </table>

    <h2 style="margin-top: 30px;"><?php esc_html_e('Top 10 Autoloaded Options by Size', 'cc-adapter'); ?></h2>
    <?php if (! empty($top_size_keys)) : ?>
      <table class="widefat striped">
        <thead>
          <tr>
            <th><strong><?php esc_html_e('Option Name', 'cc-adapter'); ?></strong></th>
            <th><strong><?php esc_html_e('Size', 'cc-adapter'); ?></strong></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($top_size_keys as $key => $size) : ?>
            <tr>
              <td><?php echo esc_html( $key ); ?></td>
              <td><?php printf('%d KB', $size / 1024 ); ?></td>
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
    );
  }
}

// register_activation_hook(__FILE__, function () {
//   wp_clear_scheduled_hook('cc_adapter_collect_metrics');
//   $time = cc_adapter_get_next_5pm();
//   wp_schedule_event($time, 'daily', 'cc_adapter_collect_metrics');

//   error_log('CC Adapter scheduling hook created (though currently inert). Next run: ' . gmdate('Y-m-d H:i:s', $time) . ' UTC');
// });

// register_deactivation_hook(__FILE__, function () {
//   wp_clear_scheduled_hook('cc_adapter_collect_metrics');
// });

