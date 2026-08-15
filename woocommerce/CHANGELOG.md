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

## [1.1.0] - 2026-08-14

### Changed

- **Webhook contract alignment**: Requests now use the platform's unified headers (`X-Platform`, `X-API-Key`, `X-Domain`, `X-Timestamp`) instead of the old `X-Vocify-*` headers.
- **Signing secret**: `X-Signature` is now HMAC-SHA256 over the exact raw JSON body using the per-agent **webhook signing secret** (`signatureSecret`) from the dashboard — not the API key. The header is only sent when a secret is configured (an unverifiable signature hard-fails with 401).
- **Test connection**: No longer posts a fake payload; performs a GET health check on the webhook URL, validates the API key format (`vcf_live_...`/`vcf_test_...`), and warns when no signing secret is configured.
- **Local validation**: New `Vocify_AI_Payload_Validator` mirrors the platform's Zod schema so invalid payloads fail fast with readable errors instead of opaque 400s.
- **Status bar**: Admin settings now show Not configured / Active / Active - no signing secret / Disabled.
- **Automatic retry**: Hourly WP-Cron job re-sends previously failed webhooks (`vocify_retry_failed_webhooks`).

### Added

- `includes/class-vocify-signer.php` — HMAC signing + header building (unit-testable, no WP bootstrap).
- `includes/class-vocify-payload-builder.php` — pure-array payload mapping (address/phone fallbacks, enum clamping, ISO dates).
- `includes/class-vocify-payload-validator.php` — schema mirror with human-readable errors.
- Settings field for the webhook signing secret.
- PHPUnit test suite (`tests/`, `phpunit.xml`, `composer.json` dev deps) — 27 tests covering builder, validator, signer.

### Fixed

- **CRITICAL**: `send_order_webhook()` indexed the old boolean `send_order()` result as an array — any webhook outcome would emit a PHP warning and the failure branch silently ran. The service now returns a structured result array.
- **CRITICAL**: `format_phone()` was called but never defined in the payload builder — every order would fatal-error. Implemented (libphonenumber E.164 with separator-strip fallback).
- Duplicate logging: the order handler no longer writes its own `vocify_webhook_logs`/queue rows — the webhook service owns all persistence.
- Order-creation guard restored: `handle_new_order()` checks the integration toggle before sending.

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

[Unreleased]: https://github.com/vocify-ai/woocommerce-plugin/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/vocify-ai/woocommerce-plugin/releases/tag/v1.1.0
[1.0.0]: https://github.com/vocify-ai/woocommerce-plugin/releases/tag/v1.0.0
