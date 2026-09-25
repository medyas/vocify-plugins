# Vocify CMS Plugins — Progress

**Last updated:** 2026-09-25 · **HEAD:** `main`. All work below is committed. `origin/main` = `9996268` (per the local tracking ref). The 2026-09-25 docs commit on top of it is unpushed.
**Versions in code:** WooCommerce **1.1.0** (`vocify-ai-woocommerce.php`, `composer.json`) · PrestaShop **1.2.0** (`vocifyai.php`, `composer.json`)
**Full history:** the pre-2026-09-25 file is archived verbatim at [`docs/history/PROGRESS-archive-2026-09-25.md`](docs/history/PROGRESS-archive-2026-09-25.md). It holds the long-form evidence (measurements, file tables, traps) for every section summarised here. The review that produced this rewrite is [`docs/history/REVIEW-REPORT-2026-09-25.md`](docs/history/REVIEW-REPORT-2026-09-25.md).

## Protocol

`main` is the trunk (owner decision 2026-08-16). Before starting, find the task here and mark it `🔄`. Before claiming it done, tick it in the same turn with date + what changed + commit ref. Section numbers **§5–§12 are cited from code comments** (`PROGRESS.md §7/§8/§9/§10`) and must not be renumbered. Add new work as §13+.

**Legend:** ✅ done · 🔄 in progress · 📋 planned · ⏸️ on hold · ❌ blocked · 🔴/🟠/🟡 open item by severity

---

## Current status (verified 2026-09-25)

| Area | Status | Evidence / where |
|---|---|---|
| Outbound order webhook, both plugins | ✅ | timestamp-bound HMAC (§7) + the four e2e-found bugs fixed (§8), in `ea012ea` |
| Pentest 2026-09-18 fixes (XSS ×2, PS SSRF/LFI, https-only URLs) | ✅ | `ea012ea` (§6). Was marked "⏳ commit pending", which was stale |
| WooCommerce return-path receiver | ✅ | `ea012ea` (code), e2e harness `f7d4f42` (§9) |
| PrestaShop return-path receiver (module 1.2.0) | ✅ | `6b7c738` (§10) |
| PrestaShop return path over the public internet | ✅ | `d484b38` (§11), measured 2026-09-19 |
| E2E default target → new VPS `152-228-210-12.sslip.io` | ✅ | `9996268` (§12) |
| PHPUnit | ✅ as of 2026-09-19 | WC **31** tests, PS **68** tests (counts re-checked 2026-09-25 by grep of `function test`; suites **not re-run** today) |
| E2E against the **new** VPS | 📋 **not yet run** | last green runs were 2026-09-19 against the old box `162-19-32-251` |
| Shopify, Magento | 📋 planned | no code exists |

---

## Open items

| # | Item | Sev | Owner |
|---|---|---|---|
| O1 | **`vocify-ai.com` is UNREGISTERED** (RDAP 404), yet `https://app.vocify-ai.com/api/webhooks/ecommerce` is the shipped default in both plugins. Whoever registers it would receive every store's API key and customer PII. Register it, or change the default to an owned host, **before any zip ships**. | 🔴 | owner |
| O2 | Re-run the e2e harness (`./run.sh` + `./run-ps-return.sh`) against the new VPS `https://152-228-210-12.sslip.io`. Nothing has run against it yet. | 🟠 | plugins |
| O3 | Store-side configured platform URL: every real shop pointed at the old host must be repointed by hand. **PrestaShop fails immediately** because its cURL sets `FOLLOWLOCATION=false` (§6) and so never follows the old host's 308. **WooCommerce** `wp_remote_post` uses WP's default redirect-following, so it keeps working only for the 7-day 308 rollback window (from 2026-09-25). | 🟠 | owner |
| O4 | WooCommerce version bump → **1.2.0** plus a CHANGELOG entry. The receiver, the HMAC fix and the §8 fixes shipped under an unchanged `1.1.0`, and `CHANGELOG.md` has no entry for them. Merchants must fill in the signing secret, which now authenticates both directions. | 🟡 | lead |
| O5 | No PHPUnit coverage for the WooCommerce receiver. Its branches are covered only by e2e. Porting PrestaShop's pattern (CMS-free decision class + thin adapter) would make it unit-testable. | 🟡 | plugins |
| O6 | Fold `suites/ps-return.mjs` + `suites/ps-internet.mjs` into `orchestrate.mjs` as a 4th `--only` target. | 🟡 | plugins |
| O7 | Contract decisions left open (§8): PrestaShop sends the **localised** state name as `status` where WC sends a slug; no zero-total guard on `woocommerce_new_order`; behaviour when no local signing secret is set (currently sends unsigned, which the platform rejects). | 🟡 | lead |
| O8 | Historical docs still prescribe the old body-only HMAC: `docs/CMS_PLUGINS_SPECIFICATION.md`, `VALIDATION_REPORT.md`, older CHANGELOG entries. Left as dated records; `claude.md` flags them as superseded. | 🟡 | — |
| O9 | `calls` rows already at `sync_attempts = 5` (67 in prod on 2026-09-19) are permanently `failed` and never retried. Resetting them is a platform-side one-off, if those orders matter. | 🟡 | platform |
| O10 | Build/release: no build script and no CI. Add `composer test` + `php -l` on push. Release zips must be built from `--no-dev` vendor. | 🟡 | plugins |
| O11 | The untracked root `plugins.zip` (~10 MB, 2026-09-19) is **not shippable**: it has dev `vendor/` (phpunit + nikic/php-parser v5, which breaks PrestaShop module installs per §8), `.phpunit.result.cache`, and the wrong root folders. It is now ignored via the root `.gitignore`. The file itself was not deleted. | ℹ️ | owner |
| O12 | `GET /api/webhooks/ecommerce` health behaviour used by Test Connection has never been verified against a live platform. | 🟡 | plugins |

---

## §12. E2E target moved to the new VPS (2026-09-25) ✅ `9996268`
The test platform moved `162.19.32.251` → `152.228.210.12`. `orchestrate.mjs`, `run-ps-return.mjs`, `fixtures/contract-probe.mjs`, `suites/ps-internet.mjs` and `test/e2e/README.md` now default to `https://152-228-210-12.sslip.io` (override: `TEST_BASE_URL`). The gitignored `REPORT*.md` keep the old URL because they record runs against the old box. *(This section was numbered "§9" in the archive, which duplicated §9 below.)*

## §11. PrestaShop return path over the public internet (2026-09-19) ✅ `d484b38`
The deployed platform pushed a synthesised completed call through its PrestaShop adapter, over a Cloudflare quick tunnel, into a real PS 8.2.8 shop: `ps_orders.current_state` 2 → 3, and a cron tick returned `{"synced":1,"failed":0}`. **48 passed / 0 failed** (35 shop-side, 13 internet-leg), cold run. Only `ps_shop_url.domain/domain_ssl` on the `main` row needs repointing (`test/e2e/ps/repoint-shop.php`). Fixed a test-isolation bug: `call_sid` is globally UNIQUE, so the run now uses per-run sid prefixes. No phone dialled, and the fixture was fully removed. The same run showed that the deployed platform build *did* have the `adapterCredentials()` fix, which made the platform's "Not deployed" note stale at the time.

## §10. PrestaShop return path: the receiver (2026-09-19) ✅ `6b7c738`
New `classes/VocifyStatusReceiver.php` (CMS-free decision logic), `controllers/front/webhook.php` (thin adapter), and `upgrade/upgrade-1.2.0.php` (creates `vocify_call_results`, seeds the state mapping, registers `displayAdminOrderSide`). Module bumped **1.1.0 → 1.2.0**. The owner delegated two decisions, both now resolved: the double webhook emission is fixed with a per-request orderId→state guard, and states are mapped by numeric `id_order_state` (`VOCIFY_STATE_*`), never by localised name. Extra fixes in the same pass: a re-entrancy guard (`$suppressOutboundWebhooks`); the order panel was invisible on PS 8 because `displayAdminOrderLeft` is never dispatched there, so it is now also on `displayAdminOrderSide`; and `orderId` gets a `ctype_digit` check. PHPUnit PS **68 tests / 173 assertions**. Upgrade path 1.1.0 → 1.2.0 simulated green.

## §9. Return path: platform → shop, WooCommerce (2026-09-19) ✅ `ea012ea` + `f7d4f42`
Before this, neither plugin had an inbound endpoint, and the platform never reached the network anyway (a `storeUrl` bug on the platform side). No order status had ever been pushed to a shop. New `woocommerce/includes/class-vocify-status-receiver.php` → `POST vocify/v1/order-status`, using the same signing secret, a 300 s window, fail-closed behaviour, idempotency on `callSid`, and a `completedAt` ordering guard (an older call cannot rewind the order). The e2e headline: `wp_posts.post_status` `pending` → `wc-processing`, read from the shop's MySQL. Platform fixes are tracked in `platform/PROGRESS.md`, and §11 later measured them as deployed.

## §8. Plugin ↔ platform e2e harness (2026-09-19) ✅ fixes `ea012ea`, harness `f7d4f42`
`test/e2e/` exercises a real shop → the shipped signer → the **deployed** platform → `orders`/`attempts` rows. First cold run: **118 passed, 0 failed, 1 blocked** (the block was §9's then-undeployed platform build). It found four bugs, three of them invisible to PHPUnit:
- **8.1 🔴 WC** registered no hooks on a normal install, because of an include-time `class_exists('WooCommerce')` gate. Fixed by moving everything WC-dependent into `on_plugins_loaded()`.
- **8.2 🔴 both** `createdAt` used `+00:00`, which the platform's Zod rejects, so every webhook got a 400. Fixed with `PLATFORM_DATE_FORMAT` (literal `Z`). PHPUnit had been asserting the broken format.
- **8.3 🟠 WC** `woocommerce_new_order` re-fetched the order before items were saved. Fixed by using the passed `WC_Order` (hook `10, 2`).
- **8.4 🟠 PS** `actionValidateOrder` fires before the state is applied. Fixed by seeding the state from `$params['orderStatus']`.

### Observations (recorded 2026-09-19, deliberately not fixed then; cited from code)
- **One PS order emitted two webhooks** (`actionValidateOrder` 201, then `actionOrderStatusPostUpdate` 200). Now **fixed in §10** with the per-request orderId→state guard.
- **PS `status` is the localised order-state name**, because `OrderState` has no `slug` in PS 8 and so `isset($orderState->slug)` is dead code. WC sends a slug. **Open: O7.**
- **`woocommerce_new_order` can fire with `total = 0`** (gateway-built orders), and there is no guard. **Open: O7.**
- **A dev `vendor/` breaks PrestaShop outright** (nikic/php-parser v5 shadows PS's v4). Release zips must be `--no-dev`: see `claude.md` → Commands and O11.
- Full text: archive §8 "Observations recorded, deliberately NOT fixed".

## §7. HMAC contract fix — timestamp binding (2026-09-19) ✅ `ea012ea`
Both signers now sign `"{timestamp}.{rawBody}"` with the per-agent signing secret. One timestamp is shared between the `X-Timestamp` header and the signature. This matches the platform's fail-closed, timestamp-bound contract from 2026-09-18; without it every webhook would have 401'd. Known-answer + negative KATs were added, and Node and PHP produce the identical digest. PHPUnit WC 31/31, PS 35/35 at the time. `claude.md`'s HMAC example was corrected in the same commit.

## §6. Security fixes, pentest 2026-09-18 ✅ `ea012ea`
PS stored XSS in `webhook_logs.tpl`/`order_info.tpl` (inline `onclick` replaced by an escaped `data-` attribute + listener). WC reflected XSS in Test Connection (`.html()`→`.text()`, raw remote body no longer returned). PS LFI/SSRF (`isAllowedWebhookUrl()`: https only, no credentials, no private/loopback/numeric-alias/resolving-to-private hosts; cURL https-only, no redirects). WC `is_allowed_webhook_url()` (https + host, no credentials). Verified with `php -l`, PHPUnit and 40 URL probes. Finding 5 (unsigned timestamp) was closed by §7. **The default domain was left alone on purpose: see O1.**

## §5. v1.1.0 platform-contract sync (2026-08-14) ✅ `a8e8c23`
Unified headers (`X-Platform`/`X-API-Key`/`X-Domain`/`X-Timestamp`/`X-Signature`) replaced the old `X-Vocify-*`. New payload builder/validator/signer on both plugins, libphonenumber E.164, 3× backoff + failed queue (WC hourly WP-Cron, PS token cron controller), and PHPUnit suites. Fixed a fatal missing `format_phone()` in WC. (The `a8e8c23` commit message notes that PHPUnit was not run *at commit time*; §7/§8 later ran it.)

---

## Plugin feature matrix

| Feature | WooCommerce 1.1.0 | PrestaShop 1.2.0 |
|---|---|---|
| Order hooks | `woocommerce_new_order` (10,2), `woocommerce_order_status_changed` | `actionValidateOrder`, `actionOrderStatusPostUpdate` (dedup by state) |
| Payload builder + local validator | ✅ | ✅ |
| Timestamp-bound HMAC signer | ✅ | ✅ |
| Retry: 3× backoff + failed queue | ✅ hourly WP-Cron | ✅ `controllers/front/cron.php` + `VOCIFY_CRON_TOKEN` |
| Webhook URL SSRF guard | ✅ https + host + no creds | ✅ full (private/loopback/aliases/DNS) |
| Settings: API key, signing secret, URL, enable, debug, Test Connection | ✅ | ✅ (+ 3 state-mapping selects, Call Result URL) |
| Inbound call-result receiver | ✅ REST `vocify/v1/order-status` | ✅ front controller `webhook` |
| Merchant-visible call result | ✅ order note + meta box | ✅ order panel (`displayAdminOrderSide`/`Left`) |
| PHPUnit | ✅ 31 tests (no receiver tests, see O5) | ✅ 68 tests |
| E2E (Docker, deployed platform) | ✅ inbound + return (2026-09-19) | ✅ inbound + return + internet (2026-09-19) |
| User guide / technical docs | 📋 (README/INSTALL exist) | 📋 (README/INSTALL exist) |

**Shopify app** 📋 and **Magento 2 extension** 📋: planned only, no scaffolding. The original 2025 target dates are in the archive and have passed.

---

## Changelog (short)

- **2026-09-25** — `9996268` e2e default target → new VPS. Docs rewrite (this file + `claude.md`), archive in `docs/history/`, root `.gitignore` for `plugins.zip`.
- **2026-09-19** — `d484b38` PS internet-leg proof · `6b7c738` PS receiver, module 1.2.0 · `f7d4f42` e2e harness · `ea012ea` HMAC timestamp binding + pentest fixes + §8 bugs + WC receiver.
- **2026-08-16** — `32895b3` branch decision: `main` is trunk.
- **2026-08-15** — `a8e8c23` v1.1.0 contract sync committed.
- **2025-11-16** — v1.0.0 of both plugins (`81a26a2`, `c6b8b71`, PR #1).
