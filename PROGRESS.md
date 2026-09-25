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
| PHPUnit | ✅ re-run 2026-09-25 | WC **43** tests (was 31; +12 Brain Monkey), PS **68** tests — green on the floor (7.4/7.2) and on 8.3 |
| Static analysis + CI (§13) | ✅ | PHPStan level 5 + PHPCS both plugins (0 findings each), `.github/workflows/ci.yml`; `5e21755`..`2a091a7` |
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
| O5 | ✅ narrowed 2026-09-25 (§13): the receiver's **auth path** (all 6 rejection branches + the authenticated-lookup branch) now has 9 Brain Monkey tests, and the webhook-sending order handler's HMAC/payload path has 3. Still e2e-only: the receiver's order-*mutation* branches (idempotency, ordering, status mapping) — porting PrestaShop's CMS-free-decision-class pattern would let those be unit-tested too. | 🟡 | plugins |
| O6 | Fold `suites/ps-return.mjs` + `suites/ps-internet.mjs` into `orchestrate.mjs` as a 4th `--only` target. | 🟡 | plugins |
| O7 | Contract decisions left open (§8): PrestaShop sends the **localised** state name as `status` where WC sends a slug; no zero-total guard on `woocommerce_new_order`; behaviour when no local signing secret is set (currently sends unsigned, which the platform rejects). | 🟡 | lead |
| O8 | Historical docs still prescribe the old body-only HMAC: `docs/CMS_PLUGINS_SPECIFICATION.md`, `VALIDATION_REPORT.md`, older CHANGELOG entries. Left as dated records; `claude.md` flags them as superseded. | 🟡 | — |
| O9 | `calls` rows already at `sync_attempts = 5` (67 in prod on 2026-09-19) are permanently `failed` and never retried. Resetting them is a platform-side one-off, if those orders matter. | 🟡 | platform |
| O10 | ✅ closed 2026-09-25 (§13): `.github/workflows/ci.yml` runs lint/test/phpcs/phpstan; the `composer lint` scripts now actually fail on a syntax error (they silently didn't before); `--no-dev` release-zip vendor verified dev-tool-free. | 🟡 | plugins |
| O11 | The untracked root `plugins.zip` (~10 MB, 2026-09-19) is **not shippable**: it has dev `vendor/` (phpunit + nikic/php-parser v5, which breaks PrestaShop module installs per §8), `.phpunit.result.cache`, and the wrong root folders. It is now ignored via the root `.gitignore`. The file itself was not deleted. | ℹ️ | owner |
| O12 | `GET /api/webhooks/ecommerce` health behaviour used by Test Connection has never been verified against a live platform. | 🟡 | plugins |

---

## §13. Code-quality harness: PHPStan + WPCS/PHPCompatibility + Brain Monkey + CI (2026-09-25) ✅ `5e21755`..`2a091a7`
Static analysis, tests and CI for both plugins, per `docs/superpowers/plans/2026-09-25-code-quality-harness.md` §"plugins". No local PHP on this machine — everything below was run and verified through Docker (`composer:2`, `php:7.2/7.4/8.2/8.3-cli`), per `claude.md` → Commands.

**Composer-floor bug found and fixed first.** Neither plugin's `composer.json` pinned `config.platform.php`, so `composer install`/`update` resolved against whatever PHP actually ran it (8.x), not the stated floor. Two latent breaks this hid: WooCommerce's **production** dependency `giggsey/libphonenumber-for-php` pulled `giggsey/locale` 2.9.0, which requires PHP `^8.1` — silently incompatible with the plugin's declared `>=7.4.0` floor. PrestaShop's `phpunit/phpunit "^8.5 || ^9.6"` resolves 9.x by default, which needs PHP `>=7.3`; the module's actual **lowest installable** floor, once resolution is pinned, is **PHP 7.2** (`phpunit` 8.5 needs `>=7.2`), not the stated 7.1 — `PHPCompatibility testVersion=7.1-` still statically checks 7.1 syntax. Both `composer.json`s now pin `config.platform.php` (7.4.33 / 7.2.34) so a fresh `composer install` anywhere resolves the same, correct, floor-compatible versions (neither lockfile is committed — both were already gitignored). Two PS tests used PHPUnit 9-only `assertMatchesRegularExpression()` despite the module's declared PHPUnit 8.5 support; switched to the cross-version `assertRegExp()` (PHPUnit 9.6 keeps it, soft-deprecated only). Both `composer.json` `lint` scripts silently exited 0 on a syntax error (`find -exec php -l {} \;` does not propagate the invoked command's exit status) — proved with a planted syntax error (old form: exit 0; `find -print0 | xargs -0 -n1 -- php -l`: exit 124) and fixed in both; PrestaShop's lint also now covers `controllers/` and `upgrade/`, which it silently skipped before.

**WooCommerce.** Added `phpstan/phpstan` ^2.2 + `phpstan/extension-installer` + `szepeviktor/phpstan-wordpress` + `php-stubs/woocommerce-stubs`; `phpstan.neon.dist` level 5, `phpVersion: {min: 70400, max: 80300}`. First run: 11 errors → 4 after fixing 7 real issues (below) → baselined the remaining 4 documented false positives (`phpstan-baseline.neon`; **never add to it, only shrink it**): a cross-file `define()`-constant discovery quirk (`VOCIFY_AI_PLUGIN_URL`), one deliberately-kept defensive `is_array()` guard on a public validator method's untyped param, and one `WC_Order_Item_Product::get_product(): WC_Product|true` stub imprecision (real signature is `WC_Product|false`; our `if ($product && …)` guard is correct, the stub union is not). Real fixes: `WC_Order::add_order_note()`'s `$is_customer_note` is `int` not `bool`; a `WC_Order_Item` → `WC_Order_Item_Product` narrowing docblock for `get_product()`; `wp_get_attachment_url()` wants `int`, `get_image_id()` returns a numeric string; `update_meta_data()`'s value cast to string (round-trips through text storage either way); 3× redundant `isset($x) && $x !== null` → `isset($x)` in the payload validator; and 2× a nullable-`Vocify_AI_Order_Handler`-and-friends property docblock fix (the null guard in `retry_failed_webhooks()` was correct — the docblock lied). Added `wp-coding-standards/wpcs` + `phpcompatibility/phpcompatibility-wp` + `dealerdirect/phpcodesniffer-composer-installer`; `phpcs.xml.dist` = `WordPress-Extra` only (a full `WordPress`/Docs superset was tried first and added ~70 pure docblock-completeness findings with no security value) + `PHPCompatibilityWP` testVersion 7.4-. First full run: **4,906 violations**. Security-sniff triage (the four named sniffs — `EscapeOutput`/`NonceVerification`/`ValidatedSanitizedInput` found **0** issues, already clean from the 2026-09-18 pentest fixes): `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` **4/4 false positives** — every one is `{$table_name}`/`{$table}` built from `$wpdb->prefix` (never user input) with all real dynamic values already through `$wpdb->prepare()` placeholders; recorded as per-line `// phpcs:ignore … -- reason` (3) plus one `phpcs:disable`/`enable` block (multi-line string; a same-line ignore doesn't reach a later line inside one). Other real fixes (not security sniffs, but genuine): `in_array()` without strict mode in the order handler; `parse_url()` → `wp_parse_url()` in the WP-dependent admin class (kept native `parse_url()` in the CMS-free `Vocify_AI_Signer`, with a per-line ignore, because it is unit-tested with no WP bootstrap and `wp_parse_url()` would fatal there); 2× short-ternary → full ternary; 12× camelCase local variable → snake_case in the payload builder (pure locals — the camelCase *array keys* they feed, e.g. `'firstName'`, are the platform's JSON contract and are unchanged); 5× missing class docblocks. `json_encode()` → `wp_json_encode()` for the **3** call sites that are pure logging (never transmitted or signed); the **1** call site that builds the actual signed+transmitted body (`send_webhook()`'s `$raw_body`) was deliberately left as native `json_encode()` with a per-line ignore — matching PrestaShop's encoder is the whole point of the byte-exact HMAC contract, and swapping it is a signing-contract change, not a lint fix. Non-security **style** sniffs (tabs, PEAR/WP paren-and-cast spacing, array-alignment, Yoda conditions, WPCS's `class-vocify-ai-*.php` filename convention, one file mixing one class + one init function) are ruleset-excluded with reasons in `phpcs.xml.dist` — phpcbf would have rewritten ~4,600 of ~4,700 findings, converting the entire codebase from its existing 4-space PSR-2-ish house style (shared with the PrestaShop module) to WPCS's tabs-and-alignment style, for zero behavioural benefit. The genuinely safe, non-house-style residual (11 findings: trailing newlines, sprintf placeholder order, CRLF, a redundant `require_once()` paren, one standalone post-increment) was fixed via `phpcbf` in its own commit. **Final: `composer cs`/`composer analyse` both exit 0.** Added Brain Monkey (`brain/monkey` + `mockery`, no WP bootstrap) covering the two areas O5 flagged as e2e-only: `WebhookServiceSendTest.php` (3 tests — the outbound HMAC/payload send path: exact transmitted bytes, exact `X-Signature`/`X-Timestamp`/`X-Platform`/`X-Domain` headers, the secretless-key and non-https-URL branches) and `StatusReceiverAuthTest.php` (9 tests — the inbound auth path: all 6 rejection branches plus one that reaches the authenticated order-lookup, with `wc_get_order` stubbed). **PHPUnit 31 → 43 tests, 74 → 121 assertions.**

**PrestaShop.** `prestashop/php-dev-tools` (the package that sounded relevant) is PrestaShop's **coding-standards/PHP-CS-Fixer** tool (namespace `PrestaShop\CodingStandards\`) — checked via `composer show --all`: it ships no PHPStan config or core stubs and only `suggests` an EOL `phpstan/phpstan ^0.12`. No official PrestaShop PHPStan-stubs package exists. Wrote minimal hand-rolled stubs instead (`.phpstan/core-stubs.php` + `.phpstan/constants.php`, loaded via `scanFiles`/`bootstrapFiles`, kept outside `classes/`/`tests/` so the `autoload-dev` classmap never picks them up) covering exactly the ~20 PrestaShop core classes/methods/constants the module's `grep`-verified real code (not comments) touches. `phpstan.neon.dist` level 5, `phpVersion: 70100` (PHPStan resolved to the 1.x branch here, whose `phpVersion` is a single int, unlike WC's 2.x object form). First run: **23 errors, all from stub imprecision** (no baseline needed — every one was a real stub fix): 5 properties (`Customer::$phone`/`$phone_mobile`, `ObjectModel::$id`, `Context::$link`) needed to be nullable, matching the `isset()` guards the real code already has around them; `Db::getValue()` needed `|null` in its return union (a `MAX()` aggregate over zero rows is SQL `NULL`, which `lastCompletedAt()` already checks for); a missing global `pSQL()` stub (6 findings); and `Module`/`ModuleFrontController`'s stubbed methods needed their **native** return-type hints removed (PrestaShop's real base classes don't declare them, precisely so untyped module overrides like `VocifyAI::install()` stay valid). One genuine code simplification found along the way, not a stub artifact: `if ($orderPayments && count($orderPayments) > 0)` → `if ($orderPayments)` (redundant given `getOrderPaymentCollection()`'s array-or-false return; the truthy check alone already excludes both `false` and an empty array) — 3 occurrences in `VocifyWebhookService.php`. **Final: `composer analyse` exits 0, no baseline file.** Added `phpcompatibility/php-compatibility` + `squizlabs/php_codesniffer` + `dealerdirect/phpcodesniffer-composer-installer`; `phpcs.xml.dist` = `PHPCompatibility` only, testVersion 7.1- (no PrestaShop-specific WPCS ruleset exists to layer on). **0 findings** — the module was already PHP-7.1-clean.

**CI.** New `.github/workflows/ci.yml`: 2×2 matrix, `woocommerce` (php 7.4, 8.3) and `prestashop` (php 7.2 — the lowest installable, not the stated 7.1; 8.3), each: checkout → `shivammathur/setup-php` → `ramsey/composer-install` (cached) → lint → phpunit on both rows, phpcs + phpstan on the 8.3 row only. Validated with `rhysd/actionlint` via Docker (0 findings) — **not executed** here; there is no GitHub Actions runner in this environment.

**Release zips.** Verified with a `--no-dev` install into a throwaway `vendor-nodev-check/` (via `COMPOSER_VENDOR_DIR`, so the real dev `vendor/` was untouched): neither plugin's `--no-dev` classmap contains `phpstan`, `mockery`, `phpcodesniffer`, `brain/monkey`, or `patchwork`. Both `INSTALL.md`s' "Create Distribution Package" zip commands now also exclude the new config files (`phpstan.neon.dist`, `phpstan-baseline.neon`, `.phpstan/`, `phpcs.xml.dist`) — harmless if shipped, just clutter.

Closes O10 (build/release: CI + a real lint gate now exist). Narrows O5 to PHPUnit coverage of the WooCommerce receiver's order-*mutation* branches only (idempotency, ordering, status mapping still need a `WC_Order` test double or e2e; the auth path is now unit-tested).

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

- **2026-09-25** — `5e21755`..`2a091a7` code-quality harness (§13): PHPStan level 5 both plugins (WC baselined 4, PS 0 findings, no baseline), WPCS/PHPCompatibility (WC 4906→0 findings, PS 0 findings), Brain Monkey tests for the WC HMAC send path + receiver auth path (31→43 tests), GitHub Actions CI, composer-platform-pin bugfixes (real PHP floors: WC needed the pin or a prod dep silently required 8.1; PS's real lowest-installable floor is 7.2, not 7.1).
- **2026-09-25** — `9996268` e2e default target → new VPS. Docs rewrite (this file + `claude.md`), archive in `docs/history/`, root `.gitignore` for `plugins.zip`.
- **2026-09-19** — `d484b38` PS internet-leg proof · `6b7c738` PS receiver, module 1.2.0 · `f7d4f42` e2e harness · `ea012ea` HMAC timestamp binding + pentest fixes + §8 bugs + WC receiver.
- **2026-08-16** — `32895b3` branch decision: `main` is trunk.
- **2026-08-15** — `a8e8c23` v1.1.0 contract sync committed.
- **2025-11-16** — v1.0.0 of both plugins (`81a26a2`, `c6b8b71`, PR #1).
