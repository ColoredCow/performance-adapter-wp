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

// Define plugin constants
define('CC_ADAPTER_DIR', plugin_dir_path(__FILE__));
define('CC_ADAPTER_URL', plugin_dir_url(__FILE__));
define('CC_ADAPTER_VERSION', '1.0.0');

// Include required classes
require_once CC_ADAPTER_DIR . 'includes/class-data-collector.php';
require_once CC_ADAPTER_DIR . 'includes/class-bigquery-client.php';

// Initialize the plugin

function cc_adapter_init()
{
  // Hook to add submenu under Tools
  add_action('admin_menu', 'cc_adapter_add_admin_menu');

  // Register the actual cron job
  add_action('cc_adapter_collect_metrics', 'cc_adapter_collect_and_push_metrics');
}
add_action('plugins_loaded', 'cc_adapter_init');

// Schedule collection on init

add_action('wp', function () {
  if (!wp_next_scheduled('cc_adapter_collect_metrics')) {
    // Schedule for 5:00 PM (17:00) daily
    $time = cc_adapter_get_next_5pm();
    wp_schedule_event($time, 'daily', 'cc_adapter_collect_metrics');
  }
});

// Helper function to get next 5:00 PM timestamp (Indian Standard Time)
function cc_adapter_get_next_5pm()
{
  // Create a timezone object for Indian Standard Time (IST = UTC+5:30)
  $ist_tz = new DateTimeZone('Asia/Kolkata');
  $now = new DateTime('now', $ist_tz);

  // Create 5:00 PM timestamp in IST
  $today_5pm = new DateTime('17:00:00', $ist_tz);

  // If 5:00 PM has already passed today, set to tomorrow
  if ($today_5pm <= $now) {
    $today_5pm->add(new DateInterval('P1D'));
  }

  // Convert to server timestamp
  return $today_5pm->getTimestamp();
}

//Add submenu under Tools

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

// Collect metrics and push to BigQuery

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

//Render the Costwatch admin page

function cc_adapter_render_page()
{
  // Check user capabilities
  if (! current_user_can('manage_options')) {
    wp_die('Unauthorized');
  }

  // Get live data
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
          <td><strong><?php esc_html_e('Timestamp (UTC)', 'cc-adapter'); ?></strong></td>
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

// Get live data for the dashboard

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
  // Verify nonce
  if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'cc_adapter_collect')) {
    wp_send_json_error(array('message' => 'Nonce verification failed'));
  }

  // Check capabilities
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

// Plugin activation hook

register_activation_hook(__FILE__, function () {
  // Clear any existing schedule first
  wp_clear_scheduled_hook('cc_adapter_collect_metrics');

  // Schedule for 5:00 PM (17:00) daily
  $time = cc_adapter_get_next_5pm();
  wp_schedule_event($time, 'daily', 'cc_adapter_collect_metrics');

  error_log('CC Adapter scheduled for 5:00 PM daily. Next run: ' . gmdate('Y-m-d H:i:s', $time) . ' UTC');
});

// Plugin deactivation hook

register_deactivation_hook(__FILE__, function () {
  // Clear the scheduled event
  wp_clear_scheduled_hook('cc_adapter_collect_metrics');
});

// WP-CLI Command

if (defined('WP_CLI') && WP_CLI) {
  class CC_Adapter_CLI_Command
  {
    /**
     * Collect and push metrics to BigQuery
     *
     * ## EXAMPLES
     *
     *     wp cc-perf collect
     */
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
