
# CC Performance Adapter - Setup Guide

## Overview
The CC Performance Adapter is a WordPress plugin that collects database health metrics and pushes them to Google BigQuery for monitoring and analysis.

### Data Flow
```
WordPress (Metrics Collection) -> BigQuery <- Looker Studio
```

## Local Development Setup

### Quick Start for Developers

If you're setting up this plugin locally for development, follow these quick steps:

#### Prerequisites for Local Environment
- **XAMPP / Local WordPress** (or any local WordPress installation)
- **Git** (for cloning the repository)
- **PHP 7.4+** (usually included with XAMPP)
- **A Google Cloud project** (for testing BigQuery integration)

#### Step 1: Clone the Repository
```bash
cd wp-content/plugins/
git clone https://github.com/ColoredCow/performance-adapter-wp.git
cd performance-adapter-wp
```

#### Step 2: Configure Your Local Environment
1. **Copy the template configuration** (if available) or create your own
2. **Download your Google Cloud service account key** (see Step 1 in the full setup below)
3. **Place the JSON key file** in your WordPress root directory (same level as `wp-config.php`)
4. **Update** `includes/class-bigquery-client.php` with your GCP Project ID and credentials

#### Step 3: Activate the Plugin
1. Go to WordPress Admin Dashboard
2. Navigate to **Plugins**
3. Find "CC Performance Adapter" and click **Activate**

#### Step 4: Test Locally
- Go to **Tools → Costwatch**
- Click "Collect & Push Now" to manually trigger data collection
- Check BigQuery to verify data is being received

#### Development Notes
- **Debug Mode**: Enable WP_DEBUG in `wp-config.php` to see detailed logs:
  ```php
  define('WP_DEBUG', true);
  define('WP_DEBUG_LOG', true);
  define('WP_DEBUG_DISPLAY', false);
  ```
- **Check Logs**: View `wp-content/debug.log` for any errors
- **WP-CLI Testing**: Run `wp cc-perf collect` to manually trigger collection
- **Verify Cron**: Ensure `DISABLE_WP_CRON` is set to `false` in `wp-config.php`

## Prerequisites

Before setting up this plugin on another machine, ensure you have:

1. **WordPress Installation** (v5.0+)
2. **PHP** (v7.4+)
3. **cURL** enabled (for API requests)
4. **Google Cloud Project** with:
   - BigQuery API enabled
   - Service Account with BigQuery Editor permissions
   - Service Account JSON key file

## Step-by-Step Setup Instructions

### 1. **Get Google Cloud Credentials**

#### 1.1 Create a Google Cloud Project
- Go to [Google Cloud Console](https://console.cloud.google.com/)
- Create a new project (e.g., "WordPress Performance")
- Note your Project ID

#### 1.2 Enable BigQuery API
- In Cloud Console, search for "BigQuery API"
- Click "Enable" to activate it

#### 1.3 Create a Service Account
- Go to "IAM & Admin" → "Service Accounts"
- Click "Create Service Account"
- Fill in details:
  - **Service Account Name**: `wordpress-adapter`
  - **Description**: WordPress Performance Metrics
- Click "Create and Continue"

#### 1.4 Grant Permissions
- Grant the role: **"BigQuery Editor"**
- Click "Continue" then "Done"

#### 1.5 Create and Download Key
- Click on the created service account
- Go to "Keys" tab
- Click "Add Key" → "Create new key"
- Select **JSON** format
- Click "Create"
- **Save this file securely** - you'll need it for Step 3

### 2. **Set Up BigQuery Dataset and Table**

#### 2.1 Create Dataset
- Go to BigQuery console
- Click "Create Dataset"
- **Dataset ID**: `proactive_perf`
- Keep other defaults, click "Create dataset"

#### 2.2 Create Table
- Select the `proactive_perf` dataset
- Click "Create Table"
- **Table name**: `performance_metrics`
- **Schema**: Copy and paste the following JSON schema when creating the table:

```json
[
  {
    "name": "timestamp_utc",
    "type": "TIMESTAMP",
    "mode": "NULLABLE"
  },
  {
    "name": "autoloaded_option_count",
    "type": "INTEGER",
    "mode": "NULLABLE"
  },
  {
    "name": "autoloaded_option_size",
    "type": "INTEGER",
    "mode": "NULLABLE"
  },
  {
    "name": "site_url",
    "type": "STRING",
    "mode": "NULLABLE"
  }
]
```

### 3. **Install Plugin on New Machine**

#### 3.1 Upload Plugin Files
- Copy the code from the same repo to your local machine.

#### 3.2 Add Google Service Account Key
- Rename your downloaded JSON key file to match the pattern for BigQuery.
REPLACE 'YOUR_SERVICE_ACCOUNT_KEY.json' with the actual file name ***
  - Example: `wordpress-adapter-1a2b3c4d5e6f.json`
- Place it in the **WordPress root directory** (same level as `wp-config.php`)
- **Important**: Add this filename to `.gitignore`

#### 3.3 Update Plugin Configuration
Edit `wp-content/plugins/properf/includes/class-bigquery-client.php`:

Find the `__construct()` method and update:

```php
private $project_id = 'YOUR_PROJECT_ID';           // Replace with your actual GCP Project ID
private $dataset_id = 'proactive_perf';             // Keep unless you renamed it
private $table_id = 'performance_metrics';          // Keep unless you renamed it
```

And update the credentials array:

```php
$this->credentials = array(
  'type'                => 'service_account',
  'project_id'          => 'YOUR_PROJECT_ID',
  'private_key_id'      => 'YOUR_PRIVATE_KEY_ID',
  'private_key'         => "YOUR_PRIVATE_KEY",
  'client_email'        => 'YOUR_SERVICE_ACCOUNT_EMAIL',
  'client_id'           => 'YOUR_CLIENT_ID',
  'auth_uri'            => 'https://accounts.google.com/o/oauth2/auth',
  'token_uri'           => 'https://oauth2.googleapis.com/token',
  'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
);
```

**Get these values from your downloaded JSON key file.**

### 4. **Activate Plugin**

- Go to WordPress Admin → **Plugins**
- Find "CC Performance Adapter"
- Click **"Activate"**
- You'll see a log message confirming the daily schedule at 5:00 PM IST

### 5. **Test the Setup**

#### 5.1 Via WordPress Admin
- Go to **Tools** → **Costwatch**
- Click "Collect & Push Now" button
- You should see: "Data collected and pushed to BigQuery!"

#### 5.2 Via WP-CLI (if available)
```bash
wp cc-perf collect
```

#### 5.3 Verify in BigQuery
- Go to BigQuery Console
- Query your table:
```sql
SELECT * FROM `YOUR_PROJECT_ID.proactive_perf.performance_metrics`
LIMIT 10;
```

You should see your WordPress metrics!

## Configuration Details

### Scheduled Collection
- **Default Time**: 5:00 PM IST (Indian Standard Time)
- **Frequency**: Daily
- **Location**: Managed by WordPress cron

To change the collection time, edit `properf_get_next_5pm()` in `properf-wordpress-adapter.php`:
```php
$today_5pm = new DateTime('17:00:00', $ist_tz);  // Change 17:00 to your desired time
```

### Metrics Collected
- **Autoloaded Options Count**: Number of database options set to autoload
- **Autoloaded Options Size**: Total bytes of autoloaded data
- **Top 5 Options**: The 5 largest autoloaded options by size
- **Timestamp**: UTC timestamp of collection

## Troubleshooting

### Issue: "Failed to get BigQuery access token"
**Solutions**:
- Verify service account email has BigQuery Editor permissions
- Check that the `private_key` in credentials is correctly formatted (includes newlines)
- Ensure token_uri is correct: `https://oauth2.googleapis.com/token`

### Issue: "BigQuery API error (HTTP 400/403)"
**Solutions**:
- Verify `project_id`, `dataset_id`, and `table_id` are correct
- Ensure the table schema matches the data being inserted
- Check that BigQuery API is enabled in your GCP project

### Issue: Plugin doesn't collect automatically
**Solutions**:
- Ensure WordPress cron is enabled (check `define('DISABLE_WP_CRON', false);` in wp-config.php)
- Click "Collect & Push Now" manually to test functionality
- Check WordPress error logs: `wp-content/debug.log`

### Issue: "Private key loading failed"
**Solutions**:
- Ensure the private key string includes proper newline characters: `\n`
- The key should start with `-----BEGIN PRIVATE KEY-----` and end with `-----END PRIVATE KEY-----`

## File Structure

```
wp-content/plugins/properf/
├── properf-wordpress-adapter.php  # Main plugin file
├── includes/
│   ├── class-data-collector.php   # Collects metrics from WordPress
│   └── class-bigquery-client.php  # Handles BigQuery API communication
└── README.md                       # This file
```

## Connecting to LookerStudio (Optional)

Once data is flowing to BigQuery, you can visualize it in LookerStudio:

1. Go to [LookerStudio](https://lookerstudio.google.com/)
2. Click "Create" → "Report"
3. Search for BigQuery connector
4. Select your project and table
5. Create visualizations from your performance metrics

## Plugin Features

✅ **Automatic Daily Collection** - Runs at scheduled time
✅ **Manual Collection** - "Collect & Push Now" button in Tools menu
✅ **Admin Dashboard** - View current metrics in WordPress admin
✅ **WP-CLI Support** - `wp cc-perf collect` command
✅ **Error Logging** - All errors logged to WordPress error log
✅ **Token Caching** - Optimized BigQuery API token management

## Security Notes

⚠️ **CRITICAL**: 
- Never commit service account keys to version control
- Add service account filename to `.gitignore`
- Use environment variables for sensitive data in production
- Restrict BigQuery API to specific tables/datasets at the IAM level

## Support

For issues or questions:
1. Check WordPress error log: `wp-content/debug.log`
2. Enable WordPress debug in `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```
3. Check BigQuery logs for API errors

## Version
- **Current Version**: 1.0.0
- **Requires**: WordPress 5.0+, PHP 7.4+
- **License**: GPL v2 or later
=======
# Performance Adapter for WordPress

A WordPress plugin that collects important WordPress metrics and sends them to a data warehouse.

## Description

Performance Adapter for WordPress is designed to gather key performance and operational metrics from WordPress sites and transmit them to a centralized data warehouse. This plugin is part of the ColoredCow Proactive Performance tool ecosystem.

## Author

ColoredCow

## Installation

1. Upload the plugin files to the `/wp-content/plugins/performance-adapter-wp` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

