# Vocify AI WooCommerce Plugin - Installation Guide

This guide provides detailed installation instructions for the Vocify AI WooCommerce plugin.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Installation Methods](#installation-methods)
3. [Composer Installation (Recommended)](#composer-installation-recommended)
4. [Manual Installation](#manual-installation)
5. [FTP Installation](#ftp-installation)
6. [Post-Installation Configuration](#post-installation-configuration)
7. [Verifying Installation](#verifying-installation)
8. [Troubleshooting](#troubleshooting)

---

## Prerequisites

### Required

- **WordPress**: Version 5.8 or higher
- **WooCommerce**: Version 5.0 or higher (8.0+ recommended for HPOS support)
- **PHP**: Version 7.4 or higher
- **PHP Extensions**:
  - cURL (for HTTPS webhook requests)
  - JSON (for payload encoding)
  - OpenSSL (for HTTPS and HMAC signatures)

### Optional but Recommended

- **Composer**: For phone number validation library
- **Server Access**: SSH or FTP access to your server

### Check Your Environment

#### Check WordPress Version
Navigate to **Dashboard** → **Updates** to see your WordPress version.

#### Check WooCommerce Version
Navigate to **WooCommerce** → **Status** → **System Status** to see your WooCommerce version.

#### Check PHP Version

```bash
php -v
```

Or check in WordPress admin: **WooCommerce** → **Status** → **System Status** → **Server Environment** → **PHP Version**

#### Check PHP Extensions

```bash
php -m | grep -E 'curl|json|openssl'
```

Or check in WordPress admin: **WooCommerce** → **Status** → **System Status** → **Server Environment**

---

## Installation Methods

Choose one of the following methods based on your setup:

| Method | Best For | Phone Validation | Difficulty |
|--------|----------|------------------|------------|
| **Composer** | Developers with server access | ✅ Advanced (E.164) | Medium |
| **Manual** | Quick setup without dependencies | ⚠️ Basic | Easy |
| **FTP** | Users without shell access | ✅ Advanced (with Composer) | Medium |

---

## Composer Installation (Recommended)

This method provides the best phone number validation using `libphonenumber-php`.

### Step 1: Download the Plugin

```bash
# Clone the repository
git clone https://github.com/vocify-ai/woocommerce-plugin.git vocify-ai-woocommerce

# Or download the ZIP and extract
wget https://github.com/vocify-ai/woocommerce-plugin/archive/main.zip
unzip main.zip
mv woocommerce-plugin-main vocify-ai-woocommerce
```

### Step 2: Install Dependencies

```bash
cd vocify-ai-woocommerce
composer install --no-dev --optimize-autoloader
```

**What this does**:
- Installs `giggsey/libphonenumber-for-php` for phone validation
- Creates `vendor/` directory with all dependencies
- Optimizes autoloader for production

**Expected output**:
```
Loading composer repositories with package information
Installing dependencies from lock file
Package operations: X installs, 0 updates, 0 removals
  - Installing giggsey/locale (x.x.x): Extracting archive
  - Installing giggsey/libphonenumber-for-php (x.x.x): Extracting archive
Generating optimized autoload files
```

### Step 3: Create Distribution Package

```bash
# Create ZIP for upload to WordPress
cd ..
zip -r vocify-ai-woocommerce.zip vocify-ai-woocommerce/ -x "*.git*" "*.gitignore"
```

**Important**: Include the `vendor/` directory in the ZIP file.

### Step 4: Upload to WordPress

1. Log in to your WordPress admin panel
2. Navigate to **Plugins** → **Add New**
3. Click **Upload Plugin** (top of page)
4. Click **Choose File** and select `vocify-ai-woocommerce.zip`
5. Click **Install Now**
6. Wait for the upload and installation to complete
7. Click **Activate Plugin**

### Step 5: Configure

1. Navigate to **Vocify AI** in the WordPress admin menu
2. Enter your Vocify AI API key
3. Enable the integration
4. Click **Save Settings**
5. Click **Test Connection** to verify

---

## Manual Installation

This method works without Composer but uses basic phone number formatting.

### Step 1: Download the Plugin

Download the latest release from GitHub:
- Go to https://github.com/vocify-ai/woocommerce-plugin/releases
- Download the source code (ZIP)
- Extract the archive

### Step 2: Create Package (Without vendor/)

```bash
# Create ZIP without vendor directory
zip -r vocify-ai-woocommerce.zip vocify-ai-woocommerce/ -x "*.git*" "*.gitignore" "*vendor/*" "*composer.*"
```

Or manually:
1. Delete the `vendor/` folder if present
2. Delete `composer.json` and `composer.lock` if present
3. Create ZIP of the remaining files

### Step 3: Upload to WordPress

1. Log in to your WordPress admin panel
2. Navigate to **Plugins** → **Add New**
3. Click **Upload Plugin**
4. Select `vocify-ai-woocommerce.zip`
5. Click **Install Now**
6. Click **Activate Plugin**

### Step 4: Configure

1. Navigate to **Vocify AI** in the admin menu
2. Enter your Vocify AI API key
3. Enable the integration
4. Click **Save Settings**

**Note**: Phone numbers will use basic formatting (removes spaces, dashes, dots) without E.164 validation.

---

## FTP Installation

For users without shell access but want the Composer dependencies.

### Option A: With Composer (Recommended)

1. **Install Dependencies Locally**:
   ```bash
   # On your local machine
   cd vocify-ai-woocommerce
   composer install --no-dev --optimize-autoloader
   ```

2. **Upload via FTP**:
   - Connect to your server via FTP (FileZilla, Cyberduck, etc.)
   - Navigate to `/wp-content/plugins/` directory
   - Upload the entire `vocify-ai-woocommerce` folder (including `vendor/`)
   - Ensure permissions: 755 for folders, 644 for files

3. **Activate via Admin Panel**:
   - Log in to WordPress admin
   - Navigate to **Plugins** → **Installed Plugins**
   - Find "Vocify AI - Order Confirmation Calls"
   - Click **Activate**

4. **Configure**:
   - Navigate to **Vocify AI** in the admin menu
   - Enter your API key
   - Enable the integration
   - Click **Save Settings**

### Option B: Without Composer

1. **Upload via FTP**:
   - Connect to your server via FTP
   - Navigate to `/wp-content/plugins/` directory
   - Upload the `vocify-ai-woocommerce` folder (without `vendor/`)
   - Ensure permissions: 755 for folders, 644 for files

2. **Activate and Configure** (same as Option A steps 3-4)

---

## Post-Installation Configuration

### 1. Get Your API Key

1. Log in to [Vocify AI Dashboard](https://app.vocify-ai.com)
2. Navigate to **Agents** → Select your agent
3. Go to **Integrations** → **Create Integration** → **WooCommerce**
4. Copy the API key (format: `vcf_live_XXXXXXXXXXXXXXXXXXXX`)
5. **Important**: Save it immediately - it's only shown once!

### 2. Configure Plugin Settings

Access: **Vocify AI** in the WordPress admin menu

| Setting | Value | Required |
|---------|-------|----------|
| **API Key** | Paste your API key | ✅ Yes |
| **Enable Integration** | Toggle ON | ✅ Yes |
| **Debug Mode** | OFF (enable only for troubleshooting) | No |
| **Webhook URL** | Leave default | ✅ Yes |

### 3. Test Connection

1. Click the **Test Connection** button
2. If successful, you'll see: "Connection successful! Your API key is valid."
3. If it fails, verify:
   - API key is correct
   - Server has outbound HTTPS access
   - cURL extension is enabled

---

## Verifying Installation

### 1. Check Plugin Status

**Plugins** → **Installed Plugins** → Search "Vocify AI"

- Status should show: **Active**
- Version: **1.0.0**

### 2. Create Test Order

1. Create a test order in your WooCommerce store
2. Include a valid phone number in billing information (e.g., `+12025551234`)
3. Complete the order

### 3. Verify Webhook Logs

**Vocify AI** → Scroll to **Recent Webhook Activity**

You should see:
- Status: **Success** (green badge)
- HTTP Code: **201** or **200**
- Recent timestamp

### 4. Check Order Detail Page

1. Navigate to **WooCommerce** → **Orders** → Select the test order
2. Look for **"Vocify AI - Order Confirmation Calls"** meta box in the right sidebar
3. Verify webhook status is displayed

### 5. Check WooCommerce Logs (If Debug Mode Enabled)

1. Navigate to **WooCommerce** → **Status** → **Logs**
2. Select the log file starting with `vocify-ai`
3. Verify webhook activity is logged

---

## Troubleshooting

### Issue: "WooCommerce is required"

**Solution**:
1. Install and activate WooCommerce plugin
2. Ensure WooCommerce is active before activating Vocify AI plugin

### Issue: "API key not configured"

**Solution**:
1. Navigate to **Vocify AI** in the admin menu
2. Enter your API key
3. Click **Save Settings**
4. Ensure format is: `vcf_live_XXXXXXXXXXXXXXXXXXXX`

### Issue: "Connection failed"

**Possible Causes**:
- Invalid API key
- Server firewall blocking outbound HTTPS
- cURL not enabled

**Solutions**:
1. Verify API key format
2. Check server firewall settings:
   ```bash
   curl -I https://app.vocify-ai.com/api/webhooks/ecommerce
   ```
3. Verify cURL is installed:
   ```bash
   php -m | grep curl
   ```
4. Check WooCommerce System Status: **WooCommerce** → **Status** → **System Status**

### Issue: "No phone number found"

**Solution**:
1. Ensure customers enter phone numbers during checkout
2. Make billing phone field required: **WooCommerce** → **Settings** → **Advanced** → **Checkout**
3. Check existing orders have phone numbers

### Issue: "libphonenumber not found" (Debug Mode)

**This is normal if**:
- You installed without Composer
- Plugin still works with basic phone formatting

**To fix** (optional):
1. Run `composer install` in plugin directory
2. Re-upload plugin including `vendor/` folder via FTP
3. Or reinstall using Composer method above

### Issue: Webhooks not sending

**Debug Steps**:
1. Enable Debug Mode in **Vocify AI** settings
2. Create test order
3. Check WooCommerce logs: **WooCommerce** → **Status** → **Logs**
4. Look for "Vocify AI" or "vocify-ai" entries
5. Verify integration is enabled
6. Check webhook logs in **Vocify AI** settings

### Issue: Permission denied on install

**Solution**:
```bash
# Fix permissions (via SSH)
cd /path/to/wordpress/wp-content/plugins
chmod 755 vocify-ai-woocommerce
chmod 644 vocify-ai-woocommerce/*.php
chmod -R 755 vocify-ai-woocommerce/includes
chmod -R 755 vocify-ai-woocommerce/assets
```

Or via FTP: Set folder permissions to 755 and file permissions to 644.

### Issue: Fatal error on activation

**Possible Causes**:
- PHP version too old (< 7.4)
- WooCommerce not active
- File upload incomplete

**Solutions**:
1. Check PHP version meets requirements (7.4+)
2. Activate WooCommerce first
3. Re-upload plugin files
4. Check error logs: **WooCommerce** → **Status** → **Logs**

---

## Updating the Plugin

### From WordPress Admin

1. **Backup Current Plugin**:
   - FTP: Download `/wp-content/plugins/vocify-ai-woocommerce/` folder
   - Database: Export `{prefix}_vocify_*` tables

2. **Deactivate and Delete**:
   - **Plugins** → **Installed Plugins** → **Vocify AI** → **Deactivate**
   - Click **Delete**

3. **Install New Version**:
   - Follow installation steps above
   - Reconfigure API key

### From Git (Developers)

```bash
cd /path/to/wordpress/wp-content/plugins/vocify-ai-woocommerce
git pull origin main
composer install --no-dev --optimize-autoloader
```

Then deactivate and reactivate the plugin via WordPress admin panel.

---

## Uninstalling

### Clean Uninstall

1. **Plugins** → **Installed Plugins** → **Vocify AI** → **Deactivate**
2. Click **Delete**
3. Confirm the action

**What gets removed**:
- Plugin files
- Configuration settings
- Database tables:
  - `{prefix}_vocify_webhook_logs`
  - `{prefix}_vocify_failed_webhooks`

**What remains**:
- WooCommerce system logs (in `/wp-content/uploads/wc-logs/`)
- Order notes added by the plugin

---

## Support

### Documentation

- [Main README](README.md)
- [Changelog](CHANGELOG.md)
- [CMS Plugins Specification](../../docs/CMS_PLUGINS_SPECIFICATION.md)
- [Best Practices](../../claude.md)

### Getting Help

- **Email**: developers@vocify-ai.com
- **Documentation**: https://docs.vocify-ai.com/cms-plugins
- **GitHub Issues**: https://github.com/vocify-ai/woocommerce-plugin/issues

### Reporting Issues

When reporting bugs, include:
1. WordPress version
2. WooCommerce version
3. PHP version
4. Plugin version (1.0.0)
5. Installation method (Composer/Manual/FTP)
6. Error message from logs
7. Steps to reproduce

---

## Additional Resources

- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WooCommerce Developer Documentation](https://woocommerce.com/document/woocommerce-developer-documentation/)
- [Composer Documentation](https://getcomposer.org/doc/)
- [libphonenumber-php GitHub](https://github.com/giggsey/libphonenumber-for-php)

---

**Happy Installing! 🚀**

For more information, visit [https://vocify-ai.com](https://vocify-ai.com)
