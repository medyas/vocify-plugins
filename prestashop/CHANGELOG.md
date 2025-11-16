# Changelog

All notable changes to the Vocify AI PrestaShop Module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Planned
- Bulk retry for failed webhooks from admin panel
- Phone number validation using libphonenumber-php
- Email notifications for critical webhook failures
- Support for custom order statuses configuration
- Multi-language support for admin interface
- Export webhook logs to CSV
- Webhook analytics dashboard

---

## [1.0.0] - 2025-11-16

### Fixed (Post-Initial Release)
- **CRITICAL**: Fixed PHP 7.1 compatibility issue - Replaced `str_starts_with()` (PHP 8.0+) with `substr()` for phone number validation
- **IMPORTANT**: Corrected transaction ID - Now retrieves actual payment transaction ID instead of carrier ID
- Added missing `financialStatus` field to payload (maps PrestaShop order states to standard values)
- Added missing `fulfillmentStatus` field to payload (tracks shipping status)
- Added missing `paidAt` field to payload (includes payment timestamp when available)
- Ensured currency codes are uppercase (ISO 4217 compliance)
- Ensured country codes are uppercase (ISO 3166-1 alpha-2 compliance)
- Clarified API key storage security in documentation (stored in database, not encrypted by PrestaShop)

### Added
- Initial release of Vocify AI PrestaShop Module
- Core module structure with PrestaShop 1.7.x and 8.x compatibility
- Event hooks integration:
  - `actionValidateOrder` - New order creation
  - `actionOrderStatusPostUpdate` - Order status changes
  - `displayAdminOrderLeft` - Order detail page integration
- Data transformation to unified Vocify AI payload format
- Webhook sending with HTTPS and HMAC-SHA256 signature
- Automatic retry logic with exponential backoff (3 attempts: 0s, 2s, 4s)
- Failed webhook queue with database persistence
- Comprehensive webhook logging system
- Admin configuration interface with settings:
  - API key management
  - Enable/disable toggle
  - Debug mode
  - Webhook URL configuration
  - Store domain display
- Test connection feature to verify API key
- Phone number extraction with priority logic
- Order detail page Vocify AI status panel
- Recent webhook activity viewer in admin panel
- Database tables:
  - `ps_vocify_webhook_logs` - All webhook attempts
  - `ps_vocify_failed_webhooks` - Failed webhook queue
- Security features:
  - Encrypted API key storage
  - HMAC signature verification
  - HTTPS-only communication
  - SQL injection protection
- Error handling:
  - Client errors (4xx) - No retry, log and notify
  - Server errors (5xx) - Retry with backoff
  - Network errors - Retry with backoff
- PrestaShop logger integration
- Index.php security files for directory protection

### Documentation
- Comprehensive README.md with installation and usage instructions
- CHANGELOG.md for version tracking
- MIT License
- Code comments and inline documentation
- Configuration examples

### Technical Details
- PHP 7.1+ compatibility
- PrestaShop 1.7.0+ compatibility
- Bootstrap-based admin UI
- Smarty template integration
- MySQL/MariaDB database support

---

## Version Numbering

This project uses [Semantic Versioning](https://semver.org/):

- **MAJOR** version for incompatible API changes
- **MINOR** version for backwards-compatible functionality additions
- **PATCH** version for backwards-compatible bug fixes

---

## Links

- [GitHub Repository](https://github.com/vocify-ai/prestashop-module)
- [Vocify AI Platform](https://vocify-ai.com)
- [Documentation](https://docs.vocify-ai.com/cms-plugins)
- [Support](mailto:developers@vocify-ai.com)

---

[Unreleased]: https://github.com/vocify-ai/prestashop-module/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/vocify-ai/prestashop-module/releases/tag/v1.0.0
