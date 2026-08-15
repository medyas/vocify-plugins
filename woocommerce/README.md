# Vocify AI - WooCommerce Plugin

**Version:** 1.0.0
**Author:** Vocify AI
**License:** MIT
**WooCommerce Compatibility:** 5.0+
**WordPress Compatibility:** 5.8+
**PHP Version:** 7.4+

---

## Overview

The Vocify AI plugin for WooCommerce enables automated AI-powered voice confirmation calls for your online orders. Enhance customer experience, reduce order cancellations, and improve customer satisfaction with intelligent order confirmation calls.

### Features

- ✅ **Automated Order Confirmation Calls**: AI voice calls sent automatically when orders are created or status changes
- ✅ **Seamless Integration**: Hooks into WooCommerce order events (`woocommerce_new_order`, `woocommerce_order_status_changed`)
- ✅ **Secure Communication**: HMAC-SHA256 signature verification for all webhooks
- ✅ **Retry Logic**: Automatic retry with exponential backoff for failed webhooks
- ✅ **Failed Webhook Queue**: Database-backed queue for failed webhooks with retry tracking
- ✅ **Comprehensive Logging**: Detailed webhook logs viewable in admin panel
- ✅ **Order-Level Insights**: View Vocify AI webhook status directly on order detail pages
- ✅ **Test Connection**: Verify your API key and webhook configuration
- ✅ **Debug Mode**: Enable verbose logging for troubleshooting
- ✅ **HPOS Compatible**: Full support for WooCommerce High-Performance Order Storage
- ✅ **Advanced Phone Validation**: Optional libphonenumber-php integration for E.164 formatting

---

## Installation

### Prerequisites

**Required**:
- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- PHP Extensions: cURL, JSON, OpenSSL

**Optional but Recommended**:
- Composer (for phone number validation library)

### Method 1: Installation with Composer (Recommended)

This method includes advanced phone number validation for better accuracy.

1. **Download the Plugin**
   ```bash
   git clone https://github.com/vocify-ai/woocommerce-plugin.git vocify-ai-woocommerce
   cd vocify-ai-woocommerce
   ```

2. **Install Dependencies**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Create ZIP File**
   ```bash
   cd ..
   zip -r vocify-ai-woocommerce.zip vocify-ai-woocommerce/ -x "*.git*"
   ```

4. **Upload to WordPress**
   - Log in to your WordPress admin panel
   - Navigate to **Plugins** → **Add New** → **Upload Plugin**
   - Select the `vocify-ai-woocommerce.zip` file
   - Click **Install Now**
   - Click **Activate**

5. **Configure the Plugin**
   - After activation, navigate to **Vocify AI** in the admin menu
   - Enter your Vocify AI API key (obtained from your Vocify AI dashboard)
   - Enable the integration
   - Click **Save Settings**

### Method 2: Manual Installation (Without Composer)

This method works without Composer but uses basic phone number formatting.

1. **Download the Plugin**
   - Download the latest release from GitHub
   - Extract the archive

2. **Upload to WordPress**
   - Log in to your WordPress admin panel
   - Navigate to **Plugins** → **Add New** → **Upload Plugin**
   - Select the ZIP file (without `vendor/` directory)
   - Click **Install Now**
   - Click **Activate**

3. **Configure the Plugin**
   - Navigate to **Vocify AI** in the admin menu
   - Enter your API key and enable the integration
   - Click **Save Settings**

### Method 3: FTP Installation

1. **Install Dependencies Locally** (optional, for phone validation)
   ```bash
   cd vocify-ai-woocommerce
   composer install --no-dev --optimize-autoloader
   ```

2. **Upload via FTP**
   - Upload the entire plugin folder to `/wp-content/plugins/`
   - Ensure file permissions are set correctly (755 for folders, 644 for files)

3. **Activate from Admin Panel**
   - Log in to WordPress admin
   - Navigate to **Plugins** → **Installed Plugins**
   - Find "Vocify AI - Order Confirmation Calls"
   - Click **Activate**

4. **Configure**
   - Navigate to **Vocify AI** in the admin menu
   - Enter your API key and enable the integration

---

## Configuration

### Getting Your API Key

1. Log in to your [Vocify AI Dashboard](https://app.vocify-ai.com)
2. Navigate to **Agents** → Select your agent
3. Go to **Integrations** → **Create Integration** → **Select WooCommerce**
4. Copy the generated API key (format: `vcf_live_XXXXXXXXXXXXXXXXXXXX`)
5. **Important**: Save this key immediately - it's only shown once!
6. If a **webhook signing secret** is shown for your API key, copy it into the plugin's **Webhook Signing Secret** field.

### Plugin Settings

Access configuration: **Vocify AI** menu in WordPress admin

| Setting | Description | Required | Default |
|---------|-------------|----------|---------|
| **API Key** | Your Vocify AI API key from the dashboard | ✅ Yes | - |
| **Webhook Signing Secret** | Signs each webhook request (`X-Signature`); shown once when you create your API key | Recommended | - |
| **Enable Integration** | Turn the integration on/off | ✅ Yes | Disabled |
| **Debug Mode** | Enable verbose logging for troubleshooting | No | Disabled |
| **Webhook URL** | Vocify AI webhook endpoint | ✅ Yes | `https://app.vocify-ai.com/api/webhooks/ecommerce` |
| **Store Domain** | Your store domain (auto-detected, read-only) | - | Auto-detected |

### Test Connection

After entering your API key:

1. Click the **Test Connection** button
2. The plugin checks the webhook URL health endpoint and validates your API key format locally (no fake payload is sent)
3. If successful, you'll see: *"Connection successful! Your API key is valid."*
4. If no signing secret is configured, you'll get a warning — requests still work, but the platform cannot verify their authenticity
5. If it fails, verify your API key and check your server's ability to make HTTPS requests

### Failed Webhook Retry

- Failed webhooks are queued in the database (`{prefix}_vocify_failed_webhooks`)
- A **WP-Cron job runs hourly** (`vocify_retry_failed_webhooks`) and automatically re-sends queued webhooks
- 4xx errors are never retried (they indicate a configuration issue); 5xx and network errors are

---

## How It Works

### Order Flow

```
┌─────────────────┐         ┌──────────────────┐         ┌─────────────────────┐
│                 │         │                  │         │                     │
│  WooCommerce    │  Order  │  Vocify AI       │ Webhook │  Vocify AI Platform │
│  Order Created  │ ──────> │  Plugin          │ ──────> │  API                │
│                 │  Event  │                  │  HTTPS  │                     │
└─────────────────┘         └──────────────────┘         └─────────────────────┘
                                     │                              │
                                     │                              │
                                     ▼                              ▼
                           ┌─────────────────┐         ┌──────────────────────┐
                           │ Transform order │         │ Schedule AI voice    │
                           │ to unified      │         │ confirmation call    │
                           │ payload format  │         │ to customer          │
                           └─────────────────┘         └──────────────────────┘
```

### WooCommerce Hooks

The plugin listens to these WooCommerce hooks:

1. **`woocommerce_new_order`**: Triggered when a new order is created
2. **`woocommerce_order_status_changed`**: Triggered when an order status changes
3. **`add_meta_boxes`**: Displays Vocify AI information on order detail page

**Status Change Triggers**: Webhooks are sent when orders transition to:
- `processing` (Payment received)
- `completed` (Order fulfilled)
- `cancelled` (Order cancelled)
- `refunded` (Order refunded)
- `failed` (Payment failed)

### Data Transformation

The plugin extracts order data from WooCommerce and transforms it to the [unified payload format](../../docs/CMS_PLUGINS_SPECIFICATION.md#standardized-payload-format) required by Vocify AI.

**Phone Number Priority** (in order of preference):
1. Billing phone
2. Shipping phone

**Phone Number Validation**:
- **With Composer**: Uses `libphonenumber-php` for accurate E.164 formatting and validation
  - Validates phone numbers based on country code
  - Automatically formats to international E.164 format (`+12025551234`)
  - Handles various input formats (with/without spaces, dashes, parentheses)
- **Without Composer**: Basic formatting (removes spaces, dashes, dots)
  - Fallback option if Composer dependencies are not installed
  - Still functional but less accurate

**Critical**: Orders without a valid phone number will be logged but may fail to create AI calls.

---

## Features in Detail

### Webhook Logging

All webhook attempts are logged in the database and viewable in the admin panel.

**View Logs**:
- Navigate to **Vocify AI** in the admin menu
- Scroll down to **Recent Webhook Activity**
- View status, HTTP codes, responses, and timestamps

**Log Types**:
- ✅ **Success**: Webhook delivered successfully
- ❌ **Failed**: All retry attempts exhausted
- ⚠️ **Error**: Client error (4xx) - no retry

### Failed Webhook Queue

If a webhook fails after 3 retry attempts, it's added to the failed webhook queue.

**Retry Strategy**:
- Attempt 1: Immediate
- Attempt 2: Wait 2 seconds
- Attempt 3: Wait 4 seconds

**Retry Conditions**:
- Server errors (5xx): Retry
- Network errors: Retry
- Client errors (4xx): **Do not retry** (indicates configuration issue)

### Order Detail Page Integration

View Vocify AI webhook status directly on order pages:

1. Navigate to **WooCommerce** → **Orders** → Select an order
2. Look for the **"Vocify AI - Order Confirmation Calls"** meta box (right sidebar)
3. View webhook history, status, and response details

---

## Troubleshooting

### Common Issues

#### 1. "Integration not configured" Message

**Solution**:
- Navigate to **Vocify AI** in the admin menu
- Enter your API key from Vocify AI dashboard
- Enable the integration
- Click **Save Settings**

#### 2. "Connection failed" Error

**Possible Causes**:
- Invalid API key
- Server firewall blocking outbound HTTPS requests
- cURL not enabled on server

**Solutions**:
- Verify API key format: `vcf_live_XXXXXXXXXXXXXXXXXXXX`
- Check server firewall settings
- Ensure cURL extension is installed: `php -m | grep curl`

#### 3. Webhooks Not Sending

**Check**:
1. Is the integration enabled? (**Vocify AI** → **Enable Integration** → Yes)
2. Check webhook logs for error messages
3. Enable Debug Mode and check WooCommerce logs
4. Verify phone number is present in order billing/shipping data

#### 4. "No phone number found" Warning

**Solution**:
- Ensure customers provide phone numbers during checkout
- Make billing phone field required in WooCommerce settings
- Update existing orders with customer phone numbers

### Debug Mode

Enable Debug Mode for detailed logging:

1. Navigate to **Vocify AI** in the admin menu
2. Enable **Debug Mode**
3. Click **Save Settings**
4. Reproduce the issue
5. Check WooCommerce logs: **WooCommerce** → **Status** → **Logs**
6. Select the log file starting with `vocify-ai`

### Log File Location

WooCommerce logs are stored in:
```
/wp-content/uploads/wc-logs/
```

You can also view logs in admin panel:
**WooCommerce** → **Status** → **Logs** → Select `vocify-ai-*` file

---

## Database Tables

The plugin creates two database tables:

### 1. `{prefix}_vocify_webhook_logs`

Stores all webhook attempts (successful and failed).

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Primary key |
| `order_id` | BIGINT | WooCommerce order ID |
| `status` | VARCHAR(20) | success, failed, error |
| `http_code` | INT | HTTP response code |
| `response` | TEXT | Response from Vocify AI API |
| `error_message` | TEXT | Error message if failed |
| `created_at` | DATETIME | Timestamp |

### 2. `{prefix}_vocify_failed_webhooks`

Queue for failed webhooks requiring retry.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Primary key |
| `order_id` | BIGINT | WooCommerce order ID |
| `payload` | LONGTEXT | JSON payload |
| `error_message` | TEXT | Last error message |
| `retry_count` | INT | Number of retry attempts |
| `last_retry_at` | DATETIME | Last retry timestamp |
| `created_at` | DATETIME | First failure timestamp |

---

## Uninstallation

### Clean Uninstall

1. Navigate to **Plugins** → **Installed Plugins**
2. Find "Vocify AI - Order Confirmation Calls"
3. Click **Deactivate**
4. Click **Delete**
5. Confirm the action

**What Gets Removed**:
- ✅ Plugin files
- ✅ Database tables (`{prefix}_vocify_webhook_logs`, `{prefix}_vocify_failed_webhooks`)
- ✅ Plugin configuration settings

**What Remains**:
- WooCommerce system logs (if Debug Mode was enabled)
- Order notes added by the plugin

---

## Security

### Data Protection

- **API Key Storage**: Stored in WordPress options table. Ensure database access is properly secured.
- **HTTPS Only**: All webhook requests use HTTPS
- **HMAC Signature**: Every webhook includes SHA-256 signature for verification
- **No PII in Logs**: Phone numbers and emails are not logged in plain text

### Best Practices

1. ✅ Use strong, unique API keys
2. ✅ Enable HTTPS on your WordPress site
3. ✅ Regularly review webhook logs for suspicious activity
4. ✅ Keep the plugin updated to the latest version
5. ✅ Restrict database access to authorized personnel
6. ❌ Never share your API key
7. ❌ Never commit API keys to version control

---

## Compatibility

| Platform | Version | Compatible | Tested |
|----------|---------|------------|--------|
| **WordPress** | 6.0+ | ✅ Yes | ✅ Yes |
| **WordPress** | 5.8 - 5.9 | ✅ Yes | ⚠️ Limited |
| **WooCommerce** | 8.0+ (HPOS) | ✅ Yes | ✅ Yes |
| **WooCommerce** | 5.0 - 7.9 | ✅ Yes | ✅ Yes |
| **WooCommerce** | 4.x | ❌ No | - |

**PHP Requirements**:
- PHP 7.4 or higher
- cURL extension enabled
- JSON extension enabled
- OpenSSL extension enabled

**Optional Dependencies**:
- Composer (for libphonenumber-php)

---

## Support

### Documentation

- [Installation Guide](INSTALL.md)
- [Changelog](CHANGELOG.md)
- [Main CMS Plugins Specification](../../docs/CMS_PLUGINS_SPECIFICATION.md)
- [Best Practices Guide](../../claude.md)

### Getting Help

- **Email**: developers@vocify-ai.com
- **Documentation**: https://docs.vocify-ai.com/cms-plugins
- **GitHub Issues**: https://github.com/vocify-ai/woocommerce-plugin/issues

### Reporting Bugs

When reporting bugs, please include:
1. WordPress version
2. WooCommerce version
3. PHP version
4. Plugin version
5. Error message from logs
6. Steps to reproduce

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

---

## License

This plugin is licensed under the [MIT License](LICENSE).

```
MIT License

Copyright (c) 2025 Vocify AI

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## Contributing

We welcome contributions! Please see [claude.md](../../claude.md) for development guidelines and best practices.

---

**Made with ❤️ by Vocify AI**

For more information, visit [https://vocify-ai.com](https://vocify-ai.com)
