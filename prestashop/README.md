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
- ✅ **Order status updated from the call result**: when the call finishes, Vocify AI posts the outcome back and your order moves — confirmed, cancelled, or left alone with a note
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

### Prerequisites

**PHP Extensions Required**:
- PHP 7.1 or higher
- cURL extension
- JSON extension
- OpenSSL extension

**Optional but Recommended**:
- Composer (for phone number validation library)

### Method 1: Installation with Composer (Recommended)

This method includes phone number validation for better accuracy.

1. **Download the Module**
   - Download or clone this repository
   - Navigate to the module directory

2. **Install Dependencies**
   ```bash
   cd vocifyai
   composer install --no-dev --optimize-autoloader
   ```

3. **Create ZIP File**
   - Create a ZIP file of the entire `vocifyai` folder (including `vendor/` directory)

4. **Upload to PrestaShop**
   - Log in to your PrestaShop admin panel
   - Navigate to **Modules** → **Module Manager**
   - Click **Upload a module**
   - Select the `vocifyai.zip` file
   - Click **Install**

5. **Configure the Module**
   - After installation, click **Configure**
   - Enter your Vocify AI API key (obtained from your Vocify AI dashboard)
   - Enable the integration
   - Click **Save**

### Method 2: Manual Installation (Without Composer)

This method works without Composer but uses basic phone number formatting.

1. **Download the Module**
   - Download or clone this repository
   - Create a ZIP file of the `vocifyai` folder (exclude `vendor/` directory)

2. **Upload to PrestaShop**
   - Log in to your PrestaShop admin panel
   - Navigate to **Modules** → **Module Manager**
   - Click **Upload a module**
   - Select the `vocifyai.zip` file
   - Click **Install**

3. **Configure the Module**
   - After installation, click **Configure**
   - Enter your Vocify AI API key
   - Enable the integration
   - Click **Save**

### Method 3: FTP Installation with Composer

1. **Install Dependencies Locally**
   ```bash
   cd vocifyai
   composer install --no-dev --optimize-autoloader
   ```

2. **Upload via FTP**
   - Upload the entire `vocifyai` folder (including `vendor/`) to `/modules/` directory
   - Ensure file permissions are set correctly (typically 755 for folders, 644 for files)

3. **Install from Admin Panel**
   - Log in to PrestaShop admin
   - Navigate to **Modules** → **Module Manager**
   - Search for "Vocify AI"
   - Click **Install**

4. **Configure**
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
6. If a **webhook signing secret** is shown for your API key, copy it into the module's **Webhook Signing Secret** field.

### Module Settings

Access configuration: **Modules** → **Module Manager** → **Vocify AI** → **Configure**

| Setting | Description | Required | Default |
|---------|-------------|----------|---------|
| **API Key** | Your Vocify AI API key from the dashboard | ✅ Yes | - |
| **Webhook Signing Secret** | Signs each webhook request (`X-Signature`); shown once when you create your API key | Recommended | - |
| **Enable Integration** | Turn the integration on/off | ✅ Yes | Disabled |
| **Debug Mode** | Enable verbose logging for troubleshooting | No | Disabled |
| **Webhook URL** | Vocify AI webhook endpoint | ✅ Yes | `https://app.vocify-ai.com/api/webhooks/ecommerce` |
| **Order status after a CONFIRMED call** | Which of your order statuses is applied when the customer confirms | ✅ Yes | Processing in progress (`PS_OS_PREPARATION`) |
| **Order status after a CANCELLED call** | Applied when the customer cancels on the phone | ✅ Yes | Cancelled (`PS_OS_CANCELED`) |
| **Order status after a COMPLETED call** | Applied when the call ends without an explicit yes or no | ✅ Yes | Delivered (`PS_OS_DELIVERED`) |
| **Call Result URL** | Where Vocify AI posts call results (display-only) | - | Generated |
| **Store Domain** | Your store domain (auto-detected, read-only) | - | Auto-detected |
| **Retry Cron URL** | Token-protected URL that re-sends failed webhooks (display-only) | - | Generated |

The three status settings store the numeric order-status **id**, not its name, so renaming or
translating a status — or running a multi-language shop — never breaks the mapping. Outcomes with
no purchase-intent meaning (no answer, failed) are recorded on the order but never change its
status: only the customer saying yes or no moves an order.

### Call Results (the return leg)

When a call finishes, Vocify AI posts the outcome to:

```
POST {store}/index.php?fc=module&module=vocifyai&controller=webhook
X-Vocify-Timestamp: 2026-09-19T13:45:02.000Z
X-Vocify-Signature:  HMAC-SHA256(secret, "{X-Vocify-Timestamp}.{rawBody}")
```

- It is authenticated with the **same Webhook Signing Secret** as the outbound direction — there is
  no second credential to manage. ⚠️ **That field must be filled in for order statuses to update.**
  With no secret configured the endpoint refuses every push (HTTP 503) rather than trusting an
  unsigned one.
- A repeat of the same call is a no-op, and an older result from an earlier call can never rewind an
  order that has already moved on.
- Results are listed on the order's Vocify AI panel in the back office. (That panel now appears on
  PrestaShop 8 — it was previously hooked only on `displayAdminOrderLeft`, which 8.x no longer
  renders, so it never showed up.)
- ⚠️ The store URL registered in your Vocify AI dashboard must be your shop's **canonical domain**.
  PrestaShop answers `302 Moved` to any request whose host does not match the shop URL you
  configured, and Vocify AI treats a redirect as a delivery failure.

### Test Connection

After entering your API key:

1. Click the **Test Connection** button
2. The module checks the webhook URL health endpoint and validates your API key format locally (no fake payload is sent)
3. If successful, you'll see: *"Connection successful! Your API key is valid."*
4. If no signing secret is configured, you'll get a warning — requests still work, but the platform cannot verify their authenticity
5. If it fails, verify your API key and check your server's ability to make HTTPS requests

### Failed Webhook Retry

- Failed webhooks are queued in the database (`{prefix}_vocify_failed_webhooks`)
- Open the **Retry Cron URL** (shown on the config page) in a browser or schedule it with a cron job to re-send queued webhooks; the URL is protected by a per-install token and returns `OK:<count>` of re-sent webhooks
- 4xx errors are never retried (they indicate a configuration issue); 5xx and network errors are

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

The module creates three database tables:

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

### 3. `ps_vocify_call_results`

Call outcomes received back from Vocify AI. This table is both the merchant-visible record shown on
the order page and what makes a repeated push a no-op — hence the UNIQUE index on `call_sid`.

| Column | Type | Description |
|--------|------|-------------|
| `id_result` | INT | Primary key |
| `id_order` | INT | PrestaShop order ID |
| `call_sid` | VARCHAR(191) | Vocify AI call ID — UNIQUE, so a replay cannot apply twice |
| `outcome` | VARCHAR(64) | confirmed, cancelled, completed, no_answer, failed |
| `completed_at` | INT | When the call ended (Unix time); an older result never overwrites a newer one |
| `id_order_state` | INT | The order status in force after this result |
| `note` | TEXT | What the merchant reads on the order |
| `created_at` | DATETIME | When the result was received |

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
