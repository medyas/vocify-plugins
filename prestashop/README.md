# Vocify AI - PrestaShop Module

**Version:** 1.0.0
**Author:** Vocify AI
**License:** MIT
**PrestaShop Compatibility:** 1.7.x, 8.x
**PHP Version:** 7.1+

---

## Overview

The Vocify AI module for PrestaShop enables automated AI-powered voice confirmation calls for your online orders. Enhance customer experience, reduce order cancellations, and improve customer satisfaction with intelligent order confirmation calls.

### Features

- ✅ **Automated Order Confirmation Calls**: AI voice calls sent automatically when orders are created or updated
- ✅ **Seamless Integration**: Hooks into PrestaShop order events (`actionValidateOrder`, `actionOrderStatusPostUpdate`)
- ✅ **Secure Communication**: HMAC-SHA256 signature verification for all webhooks
- ✅ **Retry Logic**: Automatic retry with exponential backoff for failed webhooks
- ✅ **Failed Webhook Queue**: Database-backed queue for failed webhooks with retry tracking
- ✅ **Comprehensive Logging**: Detailed webhook logs viewable in admin panel
- ✅ **Order-Level Insights**: View Vocify AI webhook status directly on order detail pages
- ✅ **Test Connection**: Verify your API key and webhook configuration
- ✅ **Debug Mode**: Enable verbose logging for troubleshooting

---

## Installation

### Method 1: Manual Installation (ZIP Upload)

1. **Download the Module**
   - Download or clone this repository
   - Create a ZIP file of the `vocifyai` folder

2. **Upload to PrestaShop**
   - Log in to your PrestaShop admin panel
   - Navigate to **Modules** → **Module Manager**
   - Click **Upload a module**
   - Select the `vocifyai.zip` file
   - Click **Install**

3. **Configure the Module**
   - After installation, click **Configure**
   - Enter your Vocify AI API key (obtained from your Vocify AI dashboard)
   - Enable the integration
   - Click **Save**

### Method 2: Manual FTP Installation

1. **Upload via FTP**
   - Upload the `vocifyai` folder to `/modules/` directory in your PrestaShop installation
   - Ensure file permissions are set correctly (typically 755 for folders, 644 for files)

2. **Install from Admin Panel**
   - Log in to PrestaShop admin
   - Navigate to **Modules** → **Module Manager**
   - Search for "Vocify AI"
   - Click **Install**

3. **Configure**
   - Click **Configure** after installation
   - Enter your API key and enable the integration

---

## Configuration

### Getting Your API Key

1. Log in to your [Vocify AI Dashboard](https://app.vocify-ai.com)
2. Navigate to **Agents** → Select your agent
3. Go to **Integrations** → **Create Integration** → **Select PrestaShop**
4. Copy the generated API key (format: `vcf_live_XXXXXXXXXXXXXXXXXXXX`)
5. **Important**: Save this key immediately - it's only shown once!

### Module Settings

Access configuration: **Modules** → **Module Manager** → **Vocify AI** → **Configure**

| Setting | Description | Required | Default |
|---------|-------------|----------|---------|
| **API Key** | Your Vocify AI API key from the dashboard | ✅ Yes | - |
| **Enable Integration** | Turn the integration on/off | ✅ Yes | Disabled |
| **Debug Mode** | Enable verbose logging for troubleshooting | No | Disabled |
| **Webhook URL** | Vocify AI webhook endpoint | ✅ Yes | `https://app.vocify-ai.com/api/webhooks/ecommerce` |
| **Store Domain** | Your store domain (auto-detected, read-only) | - | Auto-detected |

### Test Connection

After entering your API key:
1. Click the **Test Connection** button
2. If successful, you'll see: *"Connection successful! Your API key is valid."*
3. If it fails, verify your API key and check your server's ability to make HTTPS requests

---

## How It Works

### Order Flow

```
┌─────────────────┐         ┌──────────────────┐         ┌─────────────────────┐
│                 │         │                  │         │                     │
│  PrestaShop     │  Order  │  Vocify AI       │ Webhook │  Vocify AI Platform │
│  Order Created  │ ──────> │  Module          │ ──────> │  API                │
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

### Event Hooks

The module listens to these PrestaShop hooks:

1. **`actionValidateOrder`**: Triggered when a new order is created
2. **`actionOrderStatusPostUpdate`**: Triggered when an order status changes
3. **`displayAdminOrderLeft`**: Displays Vocify AI information on order detail page

### Data Transformation

The module extracts order data from PrestaShop and transforms it to the [unified payload format](../../docs/CMS_PLUGINS_SPECIFICATION.md#standardized-payload-format) required by Vocify AI.

**Phone Number Priority** (in order of preference):
1. Customer mobile phone
2. Customer phone
3. Delivery address mobile phone
4. Delivery address phone
5. Billing address mobile phone
6. Billing address phone

**Critical**: Orders without a valid phone number will be logged but may fail to create AI calls.

---

## Features in Detail

### Webhook Logging

All webhook attempts are logged in the database and viewable in the admin panel.

**View Logs**:
- Navigate to **Modules** → **Vocify AI** → **Configure**
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

1. Navigate to **Orders** → Select an order
2. Look for the **"Vocify AI - Order Confirmation Calls"** panel
3. View webhook history, status, and response details

---

## Troubleshooting

### Common Issues

#### 1. "API key not configured" Error

**Solution**:
- Navigate to module configuration
- Enter your API key from Vocify AI dashboard
- Click Save

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
1. Is the integration enabled? (Configuration → Enable Integration → Yes)
2. Check webhook logs for error messages
3. Enable Debug Mode and check PrestaShop logs
4. Verify phone number is present in customer/order data

#### 4. "No phone number found" Warning

**Solution**:
- Ensure customers provide phone numbers during checkout
- Make phone field required in checkout form
- Update existing orders with customer phone numbers

### Debug Mode

Enable Debug Mode for detailed logging:

1. Navigate to module configuration
2. Enable **Debug Mode**
3. Reproduce the issue
4. Check PrestaShop logs: **Advanced Parameters** → **Logs**
5. Look for entries with "Vocify AI" prefix

### Log File Location

PrestaShop logs are stored in:
```
/var/logs/
```

You can also view logs in admin panel:
**Advanced Parameters** → **Logs**

---

## Database Tables

The module creates two database tables:

### 1. `ps_vocify_webhook_logs`

Stores all webhook attempts (successful and failed).

| Column | Type | Description |
|--------|------|-------------|
| `id_log` | INT | Primary key |
| `id_order` | INT | PrestaShop order ID |
| `status` | VARCHAR(20) | success, failed, error |
| `http_code` | INT | HTTP response code |
| `response` | TEXT | Response from Vocify AI API |
| `error_message` | TEXT | Error message if failed |
| `created_at` | DATETIME | Timestamp |

### 2. `ps_vocify_failed_webhooks`

Queue for failed webhooks requiring retry.

| Column | Type | Description |
|--------|------|-------------|
| `id_webhook` | INT | Primary key |
| `id_order` | INT | PrestaShop order ID |
| `payload` | TEXT | JSON payload |
| `error_message` | TEXT | Last error message |
| `retry_count` | INT | Number of retry attempts |
| `last_retry_at` | DATETIME | Last retry timestamp |
| `created_at` | DATETIME | First failure timestamp |

---

## Uninstallation

### Clean Uninstall

1. Navigate to **Modules** → **Module Manager**
2. Search for "Vocify AI"
3. Click **Uninstall**
4. Confirm the action

**What Gets Removed**:
- ✅ Module configuration settings
- ✅ Database tables (`ps_vocify_webhook_logs`, `ps_vocify_failed_webhooks`)
- ✅ All webhook logs and failed webhook queue data

**What Remains**:
- PrestaShop system logs (if Debug Mode was enabled)

---

## Security

### Data Protection

- **API Key Storage**: Stored in PrestaShop configuration table (`ps_configuration`). Note: PrestaShop's Configuration class stores values in plain text in the database. Ensure database access is properly secured.
- **HTTPS Only**: All webhook requests use HTTPS
- **HMAC Signature**: Every webhook includes SHA-256 signature for verification
- **No PII in Logs**: Phone numbers and emails are not logged in plain text
- **Database Security**: Ensure proper database access controls and server security to protect sensitive configuration data

### Best Practices

1. ✅ Use strong, unique API keys
2. ✅ Enable HTTPS on your PrestaShop store
3. ✅ Regularly review webhook logs for suspicious activity
4. ✅ Keep the module updated to the latest version
5. ❌ Never share your API key
6. ❌ Never commit API keys to version control

---

## Compatibility

| PrestaShop Version | Compatible | Tested |
|--------------------|------------|--------|
| 8.x | ✅ Yes | ✅ Yes |
| 1.7.8+ | ✅ Yes | ✅ Yes |
| 1.7.0 - 1.7.7 | ✅ Yes | ⚠️ Limited |
| 1.6.x | ❌ No | - |

**PHP Requirements**:
- PHP 7.1 or higher
- cURL extension enabled
- JSON extension enabled
- OpenSSL extension enabled

---

## Support

### Documentation

- [Main CMS Plugins Specification](../../docs/CMS_PLUGINS_SPECIFICATION.md)
- [Best Practices Guide](../../claude.md)
- [Development Progress](../../progress.md)

### Getting Help

- **Email**: developers@vocify-ai.com
- **Documentation**: https://docs.vocify-ai.com/cms-plugins
- **GitHub Issues**: https://github.com/vocify-ai/prestashop-module/issues
- **Slack Community**: https://vocify-ai.slack.com

### Reporting Bugs

When reporting bugs, please include:
1. PrestaShop version
2. PHP version
3. Module version
4. Error message from logs
5. Steps to reproduce

---

## Changelog

### Version 1.0.0 (2025-11-16)

**Initial Release**

- ✅ Core module structure
- ✅ Event hooks integration (`actionValidateOrder`, `actionOrderStatusPostUpdate`)
- ✅ Data transformation to unified payload format
- ✅ Webhook sending with HMAC signature
- ✅ Retry logic with exponential backoff
- ✅ Failed webhook queue
- ✅ Comprehensive webhook logging
- ✅ Admin configuration interface
- ✅ Test connection feature
- ✅ Order detail page integration
- ✅ Debug mode

---

## License

This module is licensed under the [MIT License](LICENSE).

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
