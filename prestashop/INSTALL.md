# Vocify AI PrestaShop Module - Installation Guide

This guide provides detailed installation instructions for the Vocify AI PrestaShop module.

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

- **PrestaShop**: Version 1.7.0 or higher (tested on 1.7.x and 8.x)
- **PHP**: Version 7.1 or higher
- **PHP Extensions**:
  - cURL (for HTTPS webhook requests)
  - JSON (for payload encoding)
  - OpenSSL (for HTTPS and HMAC signatures)

### Optional but Recommended

- **Composer**: For phone number validation library
- **Server Access**: SSH or FTP access to your server

### Check Your PHP Version

```bash
php -v
```

### Check PHP Extensions

```bash
php -m | grep -E 'curl|json|openssl'
```

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

### Step 1: Download the Module

```bash
# Clone the repository
git clone https://github.com/vocify-ai/prestashop-module.git vocifyai

# Or download the ZIP and extract
wget https://github.com/vocify-ai/prestashop-module/archive/main.zip
unzip main.zip
mv prestashop-module-main vocifyai
```

### Step 2: Install Dependencies

```bash
cd vocifyai
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
# Create ZIP for upload to PrestaShop
cd ..
zip -r vocifyai.zip vocifyai/ -x "*.git*" "*.gitignore"
```

**Important**: Include the `vendor/` directory in the ZIP file.

### Step 4: Upload to PrestaShop

1. Log in to your PrestaShop admin panel
2. Navigate to **Modules** → **Module Manager**
3. Click **Upload a module** (top right)
4. Select `vocifyai.zip`
5. Click **Install**

### Step 5: Configure

1. After installation, click **Configure**
2. Enter your Vocify AI API key
3. Enable the integration
4. Click **Save**

---

## Manual Installation

This method works without Composer but uses basic phone number formatting.

### Step 1: Download the Module

Download the latest release from GitHub:
- Go to https://github.com/vocify-ai/prestashop-module/releases
- Download the source code (ZIP)
- Extract the archive

### Step 2: Create Package (Without vendor/)

```bash
# Create ZIP without vendor directory
zip -r vocifyai.zip vocifyai/ -x "*.git*" "*.gitignore" "*vendor/*" "*composer.*"
```

Or manually:
1. Delete the `vendor/` folder if present
2. Delete `composer.json` and `composer.lock` if present
3. Create ZIP of the remaining files

### Step 3: Upload to PrestaShop

1. Log in to your PrestaShop admin panel
2. Navigate to **Modules** → **Module Manager**
3. Click **Upload a module**
4. Select `vocifyai.zip`
5. Click **Install**

### Step 4: Configure

1. Click **Configure** after installation
2. Enter your Vocify AI API key
3. Enable the integration
4. Click **Save**

**Note**: Phone numbers will use basic formatting (removes spaces, dashes, dots) without E.164 validation.

---

## FTP Installation

For users without shell access but want the Composer dependencies.

### Option A: With Composer (Recommended)

1. **Install Dependencies Locally**:
   ```bash
   # On your local machine
   cd vocifyai
   composer install --no-dev --optimize-autoloader
   ```

2. **Upload via FTP**:
   - Connect to your server via FTP (FileZilla, Cyberduck, etc.)
   - Navigate to `/modules/` directory
   - Upload the entire `vocifyai` folder (including `vendor/`)
   - Ensure permissions: 755 for folders, 644 for files

3. **Install via Admin Panel**:
   - Log in to PrestaShop admin
   - Navigate to **Modules** → **Module Manager**
   - Search for "Vocify AI"
   - Click **Install**

4. **Configure**:
   - Click **Configure**
   - Enter your API key
   - Enable the integration

### Option B: Without Composer

1. **Upload via FTP**:
   - Connect to your server via FTP
   - Navigate to `/modules/` directory
   - Upload the `vocifyai` folder (without `vendor/`)
   - Ensure permissions: 755 for folders, 644 for files

2. **Install and Configure** (same as Option A steps 3-4)

---

## Post-Installation Configuration

### 1. Get Your API Key

1. Log in to [Vocify AI Dashboard](https://app.vocify-ai.com)
2. Navigate to **Agents** → Select your agent
3. Go to **Integrations** → **Create Integration** → **PrestaShop**
4. Copy the API key (format: `vcf_live_XXXXXXXXXXXXXXXXXXXX`)
5. **Important**: Save it immediately - it's only shown once!

### 2. Configure Module Settings

Access: **Modules** → **Module Manager** → **Vocify AI** → **Configure**

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

### 1. Check Module Status

**Modules** → **Module Manager** → Search "Vocify AI"

- Status should show: **Enabled**
- Version: **1.0.0**

### 2. Create Test Order

1. Create a test order in your PrestaShop store
2. Include a valid phone number (e.g., `+12025551234`)
3. Check webhook logs in module configuration

### 3. Verify Webhook Logs

**Modules** → **Vocify AI** → **Configure** → Scroll to **Recent Webhook Activity**

You should see:
- Status: **Success** (green badge)
- HTTP Code: **201**
- Recent timestamp

### 4. Check Order Detail Page

1. Navigate to **Orders** → Select the test order
2. Look for **"Vocify AI - Order Confirmation Calls"** panel
3. Verify webhook status is displayed

---

## Troubleshooting

### Issue: "API key not configured"

**Solution**:
1. Go to module configuration
2. Enter your API key
3. Click Save
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

### Issue: "No phone number found"

**Solution**:
1. Ensure customers enter phone numbers during checkout
2. Make phone field required in checkout settings
3. Check existing orders have phone numbers

### Issue: "libphonenumber not found" (Debug Mode)

**This is normal if**:
- You installed without Composer
- Module still works with basic phone formatting

**To fix** (optional):
1. Run `composer install` in module directory
2. Re-upload module including `vendor/` folder

### Issue: Webhooks not sending

**Debug Steps**:
1. Enable Debug Mode in module configuration
2. Create test order
3. Check PrestaShop logs: **Advanced Parameters** → **Logs**
4. Look for "Vocify AI" entries
5. Verify integration is enabled

### Issue: Permission denied on install

**Solution**:
```bash
# Fix permissions
chmod 755 /path/to/prestashop/modules/vocifyai
chmod 644 /path/to/prestashop/modules/vocifyai/*.php
chmod -R 755 /path/to/prestashop/modules/vocifyai/classes
chmod -R 755 /path/to/prestashop/modules/vocifyai/views
```

---

## Updating the Module

### From ZIP Upload

1. **Backup Current Module**:
   - FTP: Download `/modules/vocifyai/` folder
   - Database: Export `ps_vocify_*` tables

2. **Uninstall Old Version**:
   - **Modules** → **Vocify AI** → **Uninstall**
   - **Important**: This deletes webhook logs and configuration

3. **Install New Version**:
   - Follow installation steps above
   - Reconfigure API key

### From Git (Developers)

```bash
cd /path/to/prestashop/modules/vocifyai
git pull origin main
composer install --no-dev --optimize-autoloader
```

Then reinstall via PrestaShop admin panel.

---

## Uninstalling

### Clean Uninstall

1. **Modules** → **Module Manager** → **Vocify AI** → **Uninstall**
2. Confirm the action

**What gets removed**:
- Module files (if installed via upload)
- Configuration settings
- Database tables:
  - `ps_vocify_webhook_logs`
  - `ps_vocify_failed_webhooks`

**What remains**:
- PrestaShop system logs (in `/var/logs/`)

---

## Support

### Documentation
- [Main README](README.md)
- [CMS Plugins Specification](../../docs/CMS_PLUGINS_SPECIFICATION.md)
- [Best Practices](../../claude.md)

### Getting Help
- **Email**: developers@vocify-ai.com
- **Documentation**: https://docs.vocify-ai.com/cms-plugins
- **GitHub Issues**: https://github.com/vocify-ai/prestashop-module/issues

### Reporting Issues

When reporting bugs, include:
1. PrestaShop version
2. PHP version
3. Module version (1.0.0)
4. Installation method (Composer/Manual/FTP)
5. Error message from logs
6. Steps to reproduce

---

## Additional Resources

- [PrestaShop Module Development](https://devdocs.prestashop.com/)
- [Composer Documentation](https://getcomposer.org/doc/)
- [libphonenumber-php GitHub](https://github.com/giggsey/libphonenumber-for-php)

---

**Happy Installing! 🚀**

For more information, visit [https://vocify-ai.com](https://vocify-ai.com)
