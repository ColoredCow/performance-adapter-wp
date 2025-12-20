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

function cc_adapter_load_env()
{
  $env_file = ABSPATH . '.env';

  if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      if (strpos(trim($line), '#') === 0) continue;
      if (strpos($line, '=') !== false) {
        list($name, $value) = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
      }
    }
  }
}
cc_adapter_load_env();

require_once CC_ADAPTER_DIR . 'includes/class-data-collector.php';

function cc_adapter_init()
{
  add_action('admin_menu', 'cc_adapter_add_admin_menu');
}
add_action('plugins_loaded', 'cc_adapter_init');

add_action('admin_init', function () {
  if (isset($_POST['cc_push_to_bq']) && check_admin_referer('cc_push_action', 'cc_push_nonce')) {
    require_once CC_ADAPTER_DIR . 'includes/class-bigquery-client.php';

    $collector = new CC_Adapter_Data_Collector();
    $bq_client = new CC_Adapter_BigQuery_Client();

    if ($bq_client->push_metrics($collector->get_data())) {
      add_settings_error('cc_messages', 'cc_msg', 'Data successfully pushed to BigQuery!', 'updated');
    } else {
      $error_message = $bq_client->get_last_error();
      add_settings_error('cc_messages', 'cc_msg', 'Failed: ' . $error_message, 'error');
    }
  }
});

add_action('wp', function () {
  if (!wp_next_scheduled('cc_adapter_collect_metrics')) {
    $time = cc_adapter_get_next_5pm();
    wp_schedule_event($time, 'daily', 'cc_adapter_collect_metrics');
  }
});

function cc_adapter_get_next_5pm()
{
  $tz = get_option('timezone_string') ?: 'UTC';

  if (empty($tz)) {
    $gmt_offset = get_option('gmt_offset');
    $tz = timezone_name_from_abbr('', $gmt_offset * 3600, false);
    if (false === $tz) $tz = 'UTC';
  }

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

  $last_sync = get_option('cc_adapter_bq_last_sync');

  if ($last_sync) {
    $format = get_option('date_format') . ' ' . get_option('time_format');
    $sync_time = wp_date($format, $last_sync);
    $tz_string = get_option('timezone_string');

    if ($tz_string) {
      $tz_display = $tz_string;
    } else {
      $gmt_offset = get_option('gmt_offset');
      $sign = ($gmt_offset < 0) ? '-' : '+';
      $hours = (int) abs($gmt_offset);
      $minutes = (abs($gmt_offset) * 60) % 60;

      if ($minutes === 0) {
        $tz_display = sprintf('UTC%s%d', $sign, $hours);
      } else {
        $tz_display = sprintf('UTC%s%d:%02d', $sign, $hours, $minutes);
      }
    }

    $last_pushed_display = $sync_time . ' (' . $tz_display . ')';
  } else {
    $last_pushed_display = 'Never';
  }
?>
  <div class="wrap">
    <h1><?php esc_html_e('ProPerf WordPress Metrics', 'cc-adapter'); ?></h1>

    <p><strong>Last pushed to BigQuery:</strong> <?php echo esc_html($last_pushed_display); ?></p>

    <?php settings_errors('cc_messages'); ?>

    <form method="post" style="margin-bottom: 20px;">
      <?php wp_nonce_field('cc_push_action', 'cc_push_nonce'); ?>
      <input type="submit" name="cc_push_to_bq" class="button button-primary" value="Push to BigQuery">
    </form>

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
          <td><?php echo esc_html(number_format($count)); ?></td>
        </tr>
        <tr>
          <td><strong><?php esc_html_e('Autoloaded Option Size', 'cc-adapter'); ?></strong></td>
          <td><?php printf('%.2f KB', $size_bytes / 1024); ?></td>
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
              <td><?php echo esc_html($key); ?></td>
              <td><?php printf('%.2f KB', $size / 1024); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else : ?>
      <p><?php esc_html_e('No autoloaded option keys found or an error occurred.', 'cc-adapter'); ?></p>
    <?php endif; ?>
  </div>
<?php
}

function cc_adapter_get_live_data()
{
  if (! class_exists('CC_Adapter_Data_Collector')) {
    return array(
      'autoloaded_option' => array(
        'count'      => 'Error: Collector Class Missing',
        'size_bytes' => 0,
        'top_size_keys'   => array(),
      )
    );
  }

  try {
    $collector = new CC_Adapter_Data_Collector();
    return $collector->get_data();
  } catch (Exception $e) {
    error_log('CC Adapter Error: ' . $e->getMessage());
    return array(
      'autoloaded_option' => array(
        'count'      => 'Error: ' . $e->getMessage(),
        'size_bytes' => 0,
        'top_size_keys'   => array(),
      )
    );
  }
}
