# Changelog

All notable changes to the Vocify AI WooCommerce Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Planned

- Bulk retry for failed webhooks from admin panel
- Email notifications for critical webhook failures
- Support for custom order statuses configuration
- Multi-language support for admin interface
- Export webhook logs to CSV
- Webhook analytics dashboard
- Integration with WooCommerce Subscriptions
- Support for WooCommerce Blocks checkout

---

## [1.0.0] - 2025-11-16

### Added

**Initial Release** - Complete WooCommerce plugin implementation

#### Core Features

- **WordPress/WooCommerce Integration**
  - Full WooCommerce 5.0+ compatibility
  - WordPress 5.8+ compatibility
  - PHP 7.4+ support
  - High-Performance Order Storage (HPOS) compatibility for WooCommerce 8.x

- **Event Hooks**
  - `woocommerce_new_order` - New order creation
  - `woocommerce_order_status_changed` - Order status changes
  - `add_meta_boxes` - Order detail page integration
  - Status change triggers: processing, completed, cancelled, refunded, failed

- **Data Transformation**
  - Transform WooCommerce orders to unified Vocify AI payload format
  - Financial status mapping (pending, paid, refunded, partially_refunded, voided)
  - Fulfillment status mapping (unfulfilled, fulfilled)
  - Extract customer data, order items, totals, and metadata

- **Phone Number Validation**
  - Integrated `libphonenumber-php` for accurate phone number validation
  - E.164 format conversion (`+12025551234`)
  - Country detection from billing/shipping address
  - Graceful fallback to basic formatting if library not installed
  - Optional Composer dependency (plugin works with or without it)

- **Webhook System**
  - HTTPS webhook sending to Vocify AI platform
  - HMAC-SHA256 signature generation and verification
  - Automatic retry logic with exponential backoff (3 attempts: 0s, 2s, 4s)
  - Failed webhook queue with database persistence
  - Comprehensive webhook logging

- **Admin Interface**
  - Clean, modern admin settings page
  - API key configuration with show/hide toggle
  - Enable/disable integration toggle
  - Debug mode for verbose logging
  - Webhook URL configuration
  - Store domain display (auto-detected)
  - Test connection feature to verify API key
  - Recent webhook activity viewer (last 20 webhooks)
  - Responsive design for mobile devices

- **Order Detail Integration**
  - Meta box on order detail page showing Vocify AI status
  - Display webhook history for each order
  - Show success/failure status with color-coded badges
  - Display HTTP codes and error messages
  - HPOS-compatible order page integration

- **Database Tables**
  - `{prefix}_vocify_webhook_logs` - All webhook attempts
  - `{prefix}_vocify_failed_webhooks` - Failed webhook queue
  - Proper indexes for performance
  - Automatic cleanup on uninstall

- **Security Features**
  - API key storage in WordPress options (ensure database security)
  - HMAC signature verification
  - HTTPS-only communication
  - SQL injection protection (prepared statements)
  - XSS protection (escaped output)
  - CSRF protection (nonces for AJAX)
  - Input sanitization and validation

- **Error Handling**
  - Client errors (4xx) - No retry, log and notify
  - Server errors (5xx) - Retry with backoff
  - Network errors - Retry with backoff
  - Exception handling with detailed logging
  - Order notes for webhook status

- **Logging**
  - Integration with WooCommerce logger
  - Debug mode for verbose logging
  - Webhook logs viewable in admin panel
  - WooCommerce system logs integration
  - Error tracking and reporting

- **Composer Support**
  - `composer.json` for dependency management
  - Autoloader for plugin classes
  - Production-optimized dependencies

#### Assets

- **Admin CSS**
  - Modern, clean styling
  - Status badges (success, error, warning)
  - Responsive layout
  - Loading spinners
  - Info boxes and sidebars

- **Admin JavaScript**
  - AJAX test connection functionality
  - API key show/hide toggle
  - Form validation
  - Webhook URL validation
  - User-friendly error messages

#### Documentation

- Comprehensive `README.md` with installation and usage instructions
- Detailed `INSTALL.md` with multiple installation methods
- `CHANGELOG.md` for version tracking
- MIT License
- Code comments and inline documentation
- Configuration examples
- Troubleshooting guide

#### Technical Details

- WordPress coding standards compliance
- WooCommerce coding standards compliance
- Singleton pattern for main plugin class
- Object-oriented architecture
- PSR-4 autoloading support
- WPDB prepared statements for security
- `wp_remote_post()` for HTTP requests
- WordPress admin UI components

---

## Version Numbering

This project uses [Semantic Versioning](https://semver.org/):

- **MAJOR** version for incompatible API changes
- **MINOR** version for backwards-compatible functionality additions
- **PATCH** version for backwards-compatible bug fixes

---

## Links

- [GitHub Repository](https://github.com/vocify-ai/woocommerce-plugin)
- [Vocify AI Platform](https://vocify-ai.com)
- [Documentation](https://docs.vocify-ai.com/cms-plugins)
- [Support](mailto:developers@vocify-ai.com)

---

[Unreleased]: https://github.com/vocify-ai/woocommerce-plugin/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/vocify-ai/woocommerce-plugin/releases/tag/v1.0.0
