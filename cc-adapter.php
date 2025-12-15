<?php

/**
 * Plugin Name: CC Performance Adapter
 * Plugin URI: https://example.com/cc-adapter
 * Description: Collects database health metrics and pushes them to BigQuery
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
require_once CC_ADAPTER_DIR . 'includes/class-bigquery-client.php';

function cc_adapter_init()
{
  add_action('admin_menu', 'cc_adapter_add_admin_menu');
  add_action('cc_adapter_collect_metrics', 'cc_adapter_collect_and_push_metrics');
}
add_action('plugins_loaded', 'cc_adapter_init');
add_action('wp', function () {
  if (!wp_next_scheduled('cc_adapter_collect_metrics')) {
    // Schedule for 5:00 PM (17:00) daily
    $time = cc_adapter_get_next_5pm();
    wp_schedule_event($time, 'daily', 'cc_adapter_collect_metrics');
  }
});
function cc_adapter_get_next_5pm()
{
  $ist_tz = new DateTimeZone('Asia/Kolkata');
  $now = new DateTime('now', $ist_tz);
  $today_5pm = new DateTime('17:00:00', $ist_tz);
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

function cc_adapter_collect_and_push_metrics()
{
  if (! class_exists('CC_Adapter_Data_Collector') || ! class_exists('CC_Adapter_BigQuery_Client')) {
    return;
  }

  try {
    $collector = new CC_Adapter_Data_Collector();
    $metrics = $collector->collect_autoloaded_options();

    $bigquery = new CC_Adapter_BigQuery_Client();
    $bigquery->push_metrics($metrics);
  } catch (Exception $e) {
    error_log('CC Adapter Error: ' . $e->getMessage());
  }
}

function cc_adapter_render_page()
{
  if (! current_user_can('manage_options')) {
    wp_die('Unauthorized');
  }
  $data = cc_adapter_get_live_data();

?>
  <div class="wrap">
    <h1><?php esc_html_e('Costwatch Dashboard', 'cc-adapter'); ?></h1>

    <div style="margin-bottom: 20px;">
      <p>
        <strong>Last Updated:</strong> <?php echo esc_html($data['timestamp_utc']); ?>
      </p>
      <button class="button button-primary" id="cc-adapter-collect-now">
        <?php esc_html_e('Collect & Push Now', 'cc-adapter'); ?>
      </button>
      <span id="cc-adapter-status" style="margin-left: 10px;"></span>
    </div>

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
          <td><strong><?php esc_html_e('Autoloaded Option Top Keys', 'cc-adapter'); ?></strong></td>
          <td>
            <?php
            if (isset($data['autoloaded_option_top_keys']) && is_array($data['autoloaded_option_top_keys']) && ! empty($data['autoloaded_option_top_keys'])) {
              echo '<ul style="margin: 0; padding-left: 20px;">';
              foreach ($data['autoloaded_option_top_keys'] as $key => $size) {
                echo '<li>' . esc_html($key) . ': ' . esc_html(number_format($size)) . ' bytes</li>';
              }
              echo '</ul>';
            } else {
              echo esc_html('N/A');
            }
            ?>
          </td>
        </tr>
        <tr>
          <td><strong><?php esc_html_e('Last Fetched', 'cc-adapter'); ?></strong></td>
          <td><?php echo esc_html(isset($data['timestamp_utc']) ? $data['timestamp_utc'] : 'N/A'); ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <script>
    document.getElementById('cc-adapter-collect-now').addEventListener('click', function() {
      var btn = this;
      var status = document.getElementById('cc-adapter-status');
      btn.disabled = true;
      status.textContent = 'Collecting...';
      status.style.color = 'blue';

      var formData = new FormData();
      formData.append('action', 'cc_adapter_collect_now');
      formData.append('nonce', '<?php echo wp_create_nonce('cc_adapter_collect'); ?>');

      fetch(ajaxurl, {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            status.textContent = 'Data collected and pushed to BigQuery!';
            status.style.color = 'green';
            setTimeout(function() {
              location.reload();
            }, 2000);
          } else {
            status.textContent = 'Error: ' + (data.data || 'Unknown error');
            status.style.color = 'red';
            console.error('Error response:', data);
          }
          btn.disabled = false;
        })
        .catch(error => {
          status.textContent = 'Error: ' + error.message;
          status.style.color = 'red';
          console.error('Fetch error:', error);
          btn.disabled = false;
        });
    });
  </script>
<?php
}

function cc_adapter_get_live_data()
{
  if (! class_exists('CC_Adapter_Data_Collector')) {
    return array(
      'autoloaded_option_count'      => 'Error',
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

//AJAX endpoint for manual collection

add_action('wp_ajax_cc_adapter_collect_now', function () {
  if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'cc_adapter_collect')) {
    wp_send_json_error(array('message' => 'Nonce verification failed'));
  }
  if (! current_user_can('manage_options')) {
    wp_send_json_error(array('message' => 'Insufficient permissions'));
  }

  try {
    cc_adapter_collect_and_push_metrics();
    wp_send_json_success(array('message' => 'Data collected and pushed'));
  } catch (Exception $e) {
    error_log('CC Adapter AJAX Error: ' . $e->getMessage());
    wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
  }
});

register_activation_hook(__FILE__, function () {
  wp_clear_scheduled_hook('cc_adapter_collect_metrics');
  $time = cc_adapter_get_next_5pm();
  wp_schedule_event($time, 'daily', 'cc_adapter_collect_metrics');

  error_log('CC Adapter scheduled for 5:00 PM daily. Next run: ' . gmdate('Y-m-d H:i:s', $time) . ' UTC');
});

register_deactivation_hook(__FILE__, function () {
  wp_clear_scheduled_hook('cc_adapter_collect_metrics');
});


if (defined('WP_CLI') && WP_CLI) {
  class CC_Adapter_CLI_Command
  {
    public function collect()
    {
      WP_CLI::line('Starting metric collection...');

      try {
        cc_adapter_collect_and_push_metrics();
        WP_CLI::success('Metrics collected and pushed to BigQuery successfully!');
      } catch (Exception $e) {
        WP_CLI::error('Failed to collect metrics: ' . $e->getMessage());
      }
    }
  }

  WP_CLI::add_command('cc-perf', 'CC_Adapter_CLI_Command');
}
