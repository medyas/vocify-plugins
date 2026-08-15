# Vocify CMS Plugins - Development Progress

**Last Updated**: 2026-08-14
**Version**: 1.1.0 (WooCommerce + PrestaShop)
**Branch:** `vocify-v2` (all commits here, pushed to origin) — ⚠️ NOTE: repo currently on `main`; `vocify-v2` doesn't exist yet, pending branch decision before commit.

> **Update rule (enforced by CLAUDE.md):** this file is the single source of truth for plugin status. Mark a feature 🚧 when you start it; before claiming any feature done, set its row to ✅ with a note, in the same turn as the work. Plugins have no v2 spec rewrite — the webhook contract in `claude.md` is stable; the platform's switch to synchronous intake + LiveKit is invisible to plugins.

---

## Overview

This document tracks the development progress of all Vocify AI e-commerce CMS plugins.

### Target Platforms

1. **Shopify** - Shopify App/Plugin
2. **WooCommerce** - WordPress Plugin
3. **PrestaShop** - PrestaShop Module
4. **Magento** - Magento 2.x Extension

---

## Development Status

### Legend

- ✅ **Completed** - Feature is implemented and tested
- 🚧 **In Progress** - Currently being developed
- 📋 **Planned** - Scheduled for development
- ⏸️ **On Hold** - Paused temporarily
- ❌ **Blocked** - Blocked by dependencies or issues

---

## 5. v1.1.0 Platform-Contract Sync (2026-08-14) ✅

**What changed and why:** the platform's webhook auth moved to per-agent signing secrets (`signatureSecret`, HMAC-SHA256 over the raw body, verified in `lib/auth/api-key.ts`), and the unified headers are now `X-Platform` / `X-API-Key` / `X-Domain` / `X-Timestamp` / optional `X-Signature`. Both plugins dated from pre-contract code: wrong `X-Vocify-*` headers, HMAC over the API key, a WC order handler indexing a boolean result as an array, and a **call to an undefined `format_phone()`** in the WC builder (would fatal-error every webhook).

| Deliverable | Status | Notes |
|-------------|--------|-------|
| WooCommerce → unified headers + signing secret | ✅ | `Vocify_AI_Signer`; X-Signature only when secret set (unverifiable sig = 401) |
| WooCommerce payload builder/validator (schema mirror) | ✅ | `class-vocify-payload-builder.php`, `class-vocify-payload-validator.php`; libphonenumber E.164 + fallback |
| WooCommerce service/queue/retry dedupe | ✅ | result arrays, 3× backoff, hourly WP-Cron retry, service owns DB logging |
| WooCommerce admin: signing secret + status bar + GET health test | ✅ | key regex `vcf_(live|test)_[a-zA-Z0-9]{16,}$` |
| PrestaShop same contract work | ✅ | `VocifySigner`, `VocifyPayloadBuilder`, `VocifyPayloadValidator`, result-array service, curl headers |
| PrestaShop retry cron endpoint | ✅ | `controllers/front/cron.php`, per-install token, `hash_equals`, `OK:<count>` |
| PHPUnit suites (no CMS bootstrap) | ✅ | WC 27/27, PS 31/31 — run via Docker `composer:2`, php 8.2 CLI |
| Docs (README/INSTALL/CHANGELOG) v1.1.0 | ✅ | signing secret + cron + tests documented |
| Commit + push on `vocify-v2` | 🔄 | blocked on branch decision (repo on `main`), commit ref TBD |

**Validation:** `php -l` over all 32 plugin PHP files clean; PHPUnit WC 27 tests/68 assertions, PS 31 tests/71 assertions green.

**Blockers found along the way:**
- `app.vocify-ai.com` has **no DNS record** (2026-08-14, checked 1.1.1.1) — README/default webhook URL is a placeholder until the domain is live; Test Connection explicitly surfaces HTTP codes so a dead domain is visible, not silent.
- `GET /api/webhooks/ecommerce` behavior unverifiable against live platform (no reachable host; WSL :3000 is a different node service `dist/index.js`). Test Connection treats non-200 as failure + surfaces code — safe either way.

---

## 1. PrestaShop Module

**Status**: ✅ v1.1.0 shipped (2026-08-14) — contract-synced, tested, documented
**Target Version**: 1.1.0
**PHP Version**: 7.1+
**PrestaShop Compatibility**: 1.7.x, 8.x

### Core Features

| Feature | Status | Notes |
|---------|--------|-------|
| Module structure | ✅ | Complete (v1.0.0 shipped 2025-11-16) |
| Event hooks integration | ✅ | `actionValidateOrder`, `actionOrderStatusPostUpdate` (token-gated, result-array safe) |
| Data transformation | ✅ | `VocifyPayloadBuilder` — pure-array, fallbacks, enum clamping, ISO dates |
| Webhook sending | ✅ | cURL, unified headers, 3× backoff (2s/4s/8s), 4xx never retried |
| HMAC signature | ✅ | `VocifySigner` — `signatureSecret`, raw-body HMAC-SHA256 |
| Configuration UI | ✅ | Signing secret field, store domain + cron URL rows, status badge |
| API key management | ✅ | `VOCIFY_API_KEY`; regex `vcf_(live|test)_...` validation |
| Phone number validation | ✅ | libphonenumber E.164, phone_mobile-first chain, fallback strip |
| Error logging | ✅ | `vocify_webhook_logs` + PrestaShopLogger |
| Retry mechanism | ✅ | Failed queue + token-protected cron re-send |
| Failed webhooks queue | ✅ | `_DB_PREFIX_vocify_failed_webhooks` |
| Test connection | ✅ | GET health + local key-format check (no fake payload) |
| Installation script | ✅ | Tables + config incl. `VOCIFY_CRON_TOKEN` |
| Uninstallation script | ✅ | Clean removal |

### Documentation

| Document | Status | Notes |
|----------|--------|-------|
| README.md | ✅ | v1.1.0: signing secret, test connection, retry cron |
| CHANGELOG.md | ✅ | 1.1.0 entry added 2026-08-14 |
| User Guide | 📋 Planned | Step-by-step configuration |
| Technical Documentation | 📋 Planned | Developer guide |

### Testing

| Test Type | Status | Notes |
|-----------|--------|-------|
| Unit tests | ✅ | 31 tests / 71 assertions (builder, validator, signer) |
| Integration tests | 📋 Planned | PrestaShop hooks (needs live store) |
| Manual testing | 📋 Planned | Real store testing |
| Edge cases | ✅ | In unit suite: phone fallback, address fallback, enum clamp, date omission |

---

## 2. WooCommerce Plugin

**Status**: ✅ v1.1.0 shipped (2026-08-14) — contract-synced, tested, documented
**Target Version**: 1.1.0
**PHP Version**: 7.4+
**WordPress Compatibility**: 5.8+
**WooCommerce Compatibility**: 5.0+

### Core Features

| Feature | Status | Notes |
|---------|--------|-------|
| Plugin structure | ✅ | Complete (v1.0.0 shipped 2025-11-16) |
| Event hooks integration | ✅ | `woocommerce_new_order`, `woocommerce_order_status_changed` (enabled-guard restored) |
| Data transformation | ✅ | `Vocify_AI_Payload_Builder` — pure-array, fallbacks, enum clamping, ISO dates |
| Webhook sending | ✅ | `wp_remote_post`, unified headers, 3× backoff (2s/4s/8s), 4xx never retried |
| HMAC signature | ✅ | `Vocify_AI_Signer` — `signatureSecret`, raw-body HMAC-SHA256 |
| Configuration UI | ✅ | Signing secret field + status bar (Not configured/Active/Active-no-secret/Disabled) |
| API key management | ✅ | `vocify_api_key` option; regex `vcf_(live|test)_...` validation |
| Phone number validation | ✅ | libphonenumber E.164 (`format_phone` **added** — was missing, fatal bug), fallback strip |
| Error logging | ✅ | `vocify_webhook_logs` + WC logger; handler no longer double-logs |
| Retry mechanism | ✅ | Hourly WP-Cron `vocify_retry_failed_webhooks` re-sends queue |
| Admin notices | ✅ | Status bar + order meta box |
| Test connection | ✅ | GET health + local key-format check (no fake payload) |

### Documentation

| Document | Status | Notes |
|----------|--------|-------|
| README.md | ✅ | v1.1.0: signing secret, test connection, retry cron |
| CHANGELOG.md | ✅ | 1.1.0 entry added 2026-08-14 |
| User Guide | 📋 Planned | Step-by-step configuration |

### Testing

| Test Type | Status | Notes |
|-----------|--------|-------|
| Unit tests | ✅ | 27 tests / 68 assertions (builder, validator, signer) |
| Integration tests | 📋 Planned | WordPress hooks (needs WP test env) |
| Manual testing | 📋 Planned | Real store testing |
| Edge cases | ✅ | In unit suite: phone fallback, address fallback, enum clamp, date omission |

---

## 3. Shopify App

**Status**: 📋 Planned
**Target Version**: 1.0.0
**Language**: Node.js (TypeScript)
**Shopify API**: Admin REST API / GraphQL

### Core Features

| Feature | Status | Notes |
|---------|--------|-------|
| Shopify app structure | 📋 Planned | Shopify CLI scaffolding |
| OAuth authentication | 📋 Planned | Shopify OAuth flow |
| Webhook subscriptions | 📋 Planned | `ORDERS_CREATE`, `ORDERS_UPDATED` |
| Data transformation | 📋 Planned | Transform Shopify orders to unified payload |
| Webhook sending | 📋 Planned | Axios with retry logic |
| HMAC signature | 📋 Planned | SHA-256 signature generation |
| Configuration UI | 📋 Planned | Shopify embedded app UI |
| API key management | 📋 Planned | Secure storage |
| Phone number validation | 📋 Planned | libphonenumber-js integration |
| Error logging | 📋 Planned | Application logging |
| Retry mechanism | 📋 Planned | Queue-based retries |
| App installation flow | 📋 Planned | Shopify app installation |

### Documentation

| Document | Status | Notes |
|----------|--------|-------|
| README.md | 📋 Planned | Installation and usage guide |
| CHANGELOG.md | 📋 Planned | Version history |
| App Listing | 📋 Planned | Shopify App Store listing |

---

## 4. Magento Extension

**Status**: 📋 Planned
**Target Version**: 1.0.0
**PHP Version**: 7.4+
**Magento Compatibility**: 2.4.x

### Core Features

| Feature | Status | Notes |
|---------|--------|-------|
| Magento module structure | 📋 Planned | Magento 2 module scaffolding |
| Event observers | 📋 Planned | `sales_order_place_after`, `sales_order_save_after` |
| Data transformation | 📋 Planned | Transform Magento orders to unified payload |
| Webhook sending | 📋 Planned | HTTP client with retry logic |
| HMAC signature | 📋 Planned | SHA-256 signature generation |
| Configuration UI | 📋 Planned | System configuration page |
| API key management | 📋 Planned | Encrypted config storage |
| Phone number validation | 📋 Planned | libphonenumber-php integration |
| Error logging | 📋 Planned | Magento logger integration |
| Retry mechanism | 📋 Planned | Cron-based retries |
| Composer package | 📋 Planned | Packagist distribution |
| ACL configuration | 📋 Planned | Admin permissions |

### Documentation

| Document | Status | Notes |
|----------|--------|-------|
| README.md | 📋 Planned | Installation and usage guide |
| CHANGELOG.md | 📋 Planned | Version history |
| Composer documentation | 📋 Planned | Installation via composer |

---

## Common Shared Components

### Utilities (Cross-platform)

| Component | Status | Notes |
|-----------|--------|-------|
| Phone number validator | 📋 Planned | Shared validation logic |
| HMAC signature generator | 📋 Planned | Reusable across platforms |
| Retry logic handler | 📋 Planned | Common retry strategy |
| Webhook payload validator | 📋 Planned | Schema validation |
| Currency code validator | 📋 Planned | ISO 4217 validation |
| Country code validator | 📋 Planned | ISO 3166-1 alpha-2 validation |

### Testing Tools

| Tool | Status | Notes |
|------|--------|-------|
| Payload validation script | 📋 Planned | Node.js validation tool |
| Test order generator | 📋 Planned | Generate test payloads |
| HMAC verifier | 📋 Planned | Verify signature generation |

---

## Release Timeline

### Phase 1: PrestaShop (Current)
- **Target Date**: Week of 2025-11-18
- **Deliverables**:
  - ✅ Folder structure created
  - ✅ Best practices documentation (claude.md)
  - ✅ Progress tracking (progress.md)
  - 🚧 PrestaShop module v1.0.0
  - 📋 Documentation and user guide
  - 📋 Testing and validation

### Phase 2: WooCommerce
- **Target Date**: Week of 2025-11-25
- **Deliverables**:
  - WooCommerce plugin v1.0.0
  - Documentation and user guide
  - WordPress.org submission

### Phase 3: Shopify
- **Target Date**: Week of 2025-12-02
- **Deliverables**:
  - Shopify app v1.0.0
  - Documentation and user guide
  - Shopify App Store submission (optional)

### Phase 4: Magento
- **Target Date**: Week of 2025-12-09
- **Deliverables**:
  - Magento extension v1.0.0
  - Composer package
  - Documentation and user guide

---

## Known Issues & Blockers

| Issue | Platform | Status | Resolution |
|-------|----------|--------|------------|
| `app.vocify-ai.com` has no DNS record (checked 2026-08-14) | Both | ⚠️ External | Domain must be live before Test Connection / webhooks succeed; plugin surfaces HTTP codes so failures are visible |
| `GET /api/webhooks/ecommerce` health behavior unverified (no reachable host) | Both | 🚧 | Verify once platform dev server or production domain is reachable |
| Repo on `main`, `vocify-v2` branch doesn't exist — claude.md hard gate | Both | 🚧 | Create `vocify-v2` and commit there (pending user decision) |

---

## Next Steps

### Immediate
1. ✅ WooCommerce + PrestaShop v1.1.0 contract sync (2026-08-14)
2. ✅ PHPUnit suites green (WC 27/27, PS 31/31 via Docker composer)
3. ✅ Docs + CHANGELOGs updated
4. 🔄 Commit on `vocify-v2` (branch decision pending)
5. 📋 Live-store smoke test once `app.vocify-ai.com` resolves
6. 📋 Integration tests with real CMS bootstrap (WP/PrestaShop test envs)

### Short Term
1. CI/CD: run `composer test` + `php -l` on push (GitHub Actions)
2. Re-sync `claude.md` webhook contract docs (headers section names old `X-Vocify-*`-era wording if any)
3. Shopify scaffold (still planned; out of current scope)

---

## Resources & Links

### Documentation
- [CMS Plugins Specification](./docs/CMS_PLUGINS_SPECIFICATION.md)
- [Engineering Philosophy](./docs/engineering_philosophy.md)
- [Best Practices](./claude.md)

### External Resources
- [PrestaShop Module Development](https://devdocs.prestashop.com/)
- [WooCommerce Plugin Development](https://woocommerce.github.io/code-reference/)
- [Shopify App Development](https://shopify.dev/docs/apps)
- [Magento 2 Development](https://developer.adobe.com/commerce/php/development/)

### Tools
- [libphonenumber-php](https://github.com/giggsey/libphonenumber-for-php) - Phone validation
- [webhook.site](https://webhook.site) - Webhook testing
- [ngrok](https://ngrok.com) - Local webhook testing

---

## Contributing

For guidelines on contributing to the plugins, see [claude.md](./claude.md).

---

**Note**: This document is updated regularly as development progresses. Last update reflects current status as of 2026-08-14 (v1.1.0 sync, commit pending branch decision).
