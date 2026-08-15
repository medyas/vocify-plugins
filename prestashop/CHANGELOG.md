# Changelog

All notable changes to the Vocify AI PrestaShop Module will be documented in this file.

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

---

## [1.1.0] - 2026-08-14

### Changed

- **Webhook contract alignment**: Requests now use the platform's unified headers (`X-Platform`, `X-API-Key`, `X-Domain`, `X-Timestamp`) instead of the old `X-Vocify-*` headers.
- **Signing secret**: `X-Signature` is now HMAC-SHA256 over the exact raw JSON body using the per-agent **webhook signing secret** (`signatureSecret`) from the dashboard — not the API key. The header is only sent when a secret is configured.
- **Test connection**: No longer posts a fake payload; performs a GET health check on the webhook URL, validates the API key format (`vcf_live_...`/`vcf_test_...`), and warns when no signing secret is configured.
- **Local validation**: New `VocifyPayloadValidator` mirrors the platform's Zod schema so invalid payloads fail fast with readable errors.
- **Automatic retry**: New token-protected front controller cron (`module=vocifyai&fc=module&controller=cron&token=...`) re-sends previously failed webhooks; the cron URL is shown on the config page.
- Phone priority follows the documented chain: customer mobile → customer phone → delivery mobile/phone → invoice mobile/phone.

### Added

- `classes/VocifySigner.php` — HMAC signing + header building (unit-testable, no PrestaShop bootstrap).
- `classes/VocifyPayloadBuilder.php` — pure-array payload mapping (address/phone fallbacks, enum clamping, ISO dates).
- `classes/VocifyPayloadValidator.php` — schema mirror with human-readable errors.
- `controllers/front/cron.php` — retry cron endpoint protected by a per-install token (`hash_equals` comparison, 403 on mismatch).
- Settings fields for the webhook signing secret; config page now shows store domain and retry cron URL.
- PHPUnit test suite (`tests/`, `phpunit.xml`, `composer.json` dev deps) — 31 tests covering builder, validator, signer.

### Fixed

- **CRITICAL**: `formatPhone()` E.164 conversion now emitted even when libphonenumber is absent (separator-strip fallback was missing).
- `transformOrder()` no longer returns `false` on missing data — callers receive the structured result array from `sendOrder()`.
- Duplicate logging: the webhook service owns all `vocify_webhook_logs`/queue persistence.
- PrestaShop phone chain now uses `phone_mobile` first, matching the documented priority.

---

## [1.0.0] - 2025-11-16

### Added (Enhancement)
- **Phone Number Validation**: Integrated `libphonenumber-php` for accurate phone number validation and E.164 formatting
  - Automatic country detection from shipping address
  - Validates phone numbers based on country-specific rules
  - Formats to international E.164 standard (`+12025551234`)
  - Graceful fallback to basic formatting if library not installed
  - Optional Composer dependency (module works with or without it)
- Added `composer.json` for dependency management
- Added `.gitignore` to exclude vendor directory from version control

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

[Unreleased]: https://github.com/vocify-ai/prestashop-module/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/vocify-ai/prestashop-module/releases/tag/v1.1.0
[1.0.0]: https://github.com/vocify-ai/prestashop-module/releases/tag/v1.0.0
