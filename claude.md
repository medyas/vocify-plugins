# Vocify CMS Plugins - Development Best Practices

This document outlines the best practices for developing and maintaining the Vocify AI e-commerce CMS plugins.

## Development Philosophy

### Core Principles

1. **#KISS (Keep It Simple, Stupid)**: Choose the simplest solution that solves the problem
2. **#DRY (Don't Repeat Yourself)**: Centralize shared logic and avoid code duplication
3. **#YAGNI (You Aren't Gonna Need It)**: Don't add functionality until it's actually needed
4. **#BestCodeIsNoCode**: Every line of code is a liability - less code = fewer bugs
5. **#FewerMovingParts**: When in doubt, choose the solution with fewer moving parts

### Supporting Rules

- **#RuleOfThree**: Wait until you've written something 3 times before abstracting it
- **#SimplestThingFirst**: Start with the obvious solution, optimize later if needed
- **#MakeItWork → #MakeItRight → #MakeItFast**: In that order
- **#TwoWeekTest**: If you won't understand your code after 2 weeks away, simplify it
- **#BoringTechnology**: Use proven, stable, well-documented tools
- **#DeleteCodeLiberally**: Remove dead code immediately - Git remembers everything

## Plugin Development Standards

### 1. Code Organization

Each plugin should follow this structure:

```
<platform-name>/
├── src/                    # Core plugin source code
│   ├── models/            # Data models
│   ├── services/          # Business logic (webhook, HMAC, etc.)
│   └── utils/             # Utility functions
├── config/                # Configuration files
├── tests/                 # Unit and integration tests
├── docs/                  # Plugin-specific documentation
├── README.md              # Installation and usage guide
├── CHANGELOG.md           # Version history
└── LICENSE                # MIT License
```

### 2. Security Best Practices

**API Key Management**:
- ✅ Store API keys securely using platform configuration (note: some platforms like PrestaShop store config in database plain text - ensure database security)
- ✅ Use HTTPS for all webhook requests
- ✅ Implement HMAC-SHA256 signature verification
- ✅ Log authentication failures for monitoring
- ✅ Restrict database access to authorized users only
- ❌ Never log API keys in plain text
- ❌ Never expose API keys in client-side code
- ❌ Never include API keys in URLs
- ❌ Never commit API keys to version control

**Data Protection**:
- Sanitize all user inputs
- Validate phone numbers and email addresses
- Use parameterized queries to prevent SQL injection
- Implement CSRF protection for admin forms

### 3. Webhook Implementation

**Standard Headers** (All platforms):
```
X-Platform: SHOPIFY|WOOCOMMERCE|PRESTASHOP|MAGENTO
X-API-Key: vcf_live_XXXXXXXXXXXXXXXXXXXX
X-Domain: store-domain.com
X-Signature: hmac-sha256-signature
X-Timestamp: ISO-8601-timestamp
Content-Type: application/json
```

**HMAC Signature Generation**:
```php
// PHP example (adapt for other languages)
$rawBody = json_encode($payload);
$signature = hash_hmac('sha256', $rawBody, $apiKey);
```

**Retry Logic**:
- Max attempts: 3
- Exponential backoff: 2s, 4s, 8s
- Don't retry 4xx errors (client errors)
- Retry 5xx errors and network failures

### 4. Error Handling

**Error Response Codes**:

| Status | Meaning | Action |
|--------|---------|--------|
| 200 | Order already processed | Log as duplicate, no retry |
| 201 | Success | Mark as sent, clear errors |
| 400 | Bad request | Log error, notify admin, **do not retry** |
| 401 | Unauthorized | Check API key, **do not retry** |
| 403 | Forbidden | Check domain, **do not retry** |
| 429 | Rate limit | Retry after specified delay |
| 500-599 | Server error | Retry with exponential backoff |

**Logging Best Practices**:
- **INFO**: Successful webhook delivery
- **WARNING**: Retrying after failure
- **ERROR**: Failed after all retries, configuration errors
- **DO NOT LOG**: Full API keys, customer PII (phone, email in plain text)

Example log messages:
```
[INFO] Vocify: Order #1234 sent successfully (jobId: job_abc123)
[WARNING] Vocify: Webhook failed for order #1234 (attempt 1/3), retrying in 2s
[ERROR] Vocify: Webhook failed for order #1234 after 3 attempts - Error: 500
[ERROR] Vocify: API key validation failed - Check settings
```

### 5. Data Transformation

**Phone Number Priority** (in order):
1. Customer primary phone
2. Billing phone
3. Shipping phone
4. Customer mobile phone

**Phone Number Validation**:
- Preferred format: E.164 (`+12025551234`)
- Use validation libraries: `libphonenumber` (PHP, JS, Python)
  - **PHP**: `giggsey/libphonenumber-for-php` via Composer
  - **JavaScript**: `libphonenumber-js` via npm
  - **Python**: `phonenumbers` via pip
- Implementation approach:
  - Make validation library optional (Composer/npm dependency)
  - Include graceful fallback to basic formatting if library unavailable
  - Detect country from shipping/billing address for accurate parsing
- Include country code or configure default country

**Required Fields**:
- `orderId`: Platform-specific unique ID
- `orderNumber`: Human-readable order number
- `customer.firstName`, `customer.lastName`
- `customer.email`
- `customer.phone` (CRITICAL - AI calls fail without valid phone)
- `items[]`: At least one item
- `totals.total`
- `currency`: ISO 4217 (3 uppercase letters, e.g., "USD")
- `shippingAddress`: At minimum address1, city, country
- `createdAt`: ISO 8601 datetime

### 6. Testing Requirements

**Test Checklist**:
- [ ] Plugin installs without errors
- [ ] Configuration page is accessible
- [ ] API key can be saved and retrieved securely
- [ ] Store domain is auto-detected correctly
- [ ] Enable/disable toggle works
- [ ] Order creation triggers webhook
- [ ] Webhook payload matches unified schema
- [ ] All required fields are populated
- [ ] Phone number is extracted and validated
- [ ] Currency and country codes are correct format
- [ ] Dates are in ISO 8601 format
- [ ] HMAC signature is generated correctly
- [ ] Invalid API key returns 401 error
- [ ] Network failures are retried
- [ ] Failed webhooks are logged
- [ ] Virtual products (no shipping) work correctly

**Test Order Characteristics**:
- Customer phone: `+12025551234`
- 2+ products in cart
- Shipping address with all fields populated
- Discount code applied
- Payment completed

### 7. Admin UI Standards

**Configuration Fields** (Minimum):
1. **API Key** (password/text input, required)
   - Label: "Vocify AI API Key"
   - Description: "Enter your API key from the Vocify AI dashboard"
   - Validation: Required, format check (`vcf_live_*`)

2. **Store Domain** (display-only)
   - Auto-detected from platform
   - Show for verification purposes

3. **Enable/Disable Toggle**
   - Turn integration on/off without losing configuration
   - Default: Disabled (require explicit activation)

4. **Test Connection Button**
   - Sends test webhook to verify setup
   - Displays success/error message

5. **Status Indicator**
   - Green: Active and working
   - Red: Error or disabled
   - Yellow: Not configured

6. **Webhook Logs** (Optional but recommended)
   - Recent webhook attempts with status
   - Filter by date, status
   - View error details

### 8. Version Control & Distribution

**Semantic Versioning** (SemVer):
```
MAJOR.MINOR.PATCH
1.0.0
```
- **MAJOR**: Breaking changes (schema changes, API updates)
- **MINOR**: New features (additional hooks, UI improvements)
- **PATCH**: Bug fixes, security patches

**Required Files**:
- `README.md`: Installation instructions, screenshots
- `CHANGELOG.md`: Version history and changes
- `LICENSE`: MIT License
- User guide with step-by-step configuration
- Link to main CMS specification document

**Distribution Channels**:
- **Shopify**: Shopify App Store or custom app
- **WooCommerce**: WordPress.org Plugin Directory
- **PrestaShop**: PrestaShop Addons Marketplace
- **Magento**: Magento Marketplace or Composer

### 9. Support & Maintenance

**Compatibility**:
- Support latest platform version + 2 previous major versions
- Test on all supported versions before release

**Updates**:
- Security patches: Within 48 hours
- Bug fixes: Within 1 week
- Feature updates: Monthly release cycle

**Issue Tracking**:
- Use GitHub Issues for bug reports
- Label issues: `bug`, `enhancement`, `security`, `platform:<name>`
- Respond to issues within 24-48 hours

**Documentation**:
- Keep README updated with each release
- Update CHANGELOG for all changes
- Maintain user guides with screenshots
- Document any platform-specific quirks

## Common Pitfalls to Avoid

1. **Hardcoding Values**: Use configuration for all environment-specific values
2. **Ignoring Webhooks Failures**: Always log and retry failed webhooks
3. **Missing Phone Validation**: Invalid phone numbers cause AI call failures
4. **Incorrect Currency/Country Codes**: Must match ISO standards exactly
5. **Timezone Issues**: Always use ISO 8601 with timezone information
6. **Not Handling Duplicates**: Platform may send same order multiple times
7. **Over-Engineering**: Start simple, add complexity only when needed
8. **Skipping Tests**: Test edge cases (virtual products, refunds, guest checkout)

## Quick Reference

### Webhook Endpoint
```
Production: https://app.vocify-ai.com/api/webhooks/ecommerce
Staging: https://staging.vocify-ai.com/api/webhooks/ecommerce
```

### Platform Values (Case-Sensitive)
```
SHOPIFY
WOOCOMMERCE
PRESTASHOP
MAGENTO
```

### Essential Links
- Main Specification: `/docs/CMS_PLUGINS_SPECIFICATION.md`
- Engineering Philosophy: `/docs/engineering_philosophy.md`
- Progress Tracking: `/progress.md`

---

**Remember**: The best code is no code. Keep it simple, secure, and maintainable.
