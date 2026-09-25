# CLAUDE.md — Vocify CMS Plugins

WooCommerce plugin + PrestaShop module. They send orders to the Vocify platform webhook (HMAC-signed), and they take the call result back so the order status changes in the shop.

> Rewritten 2026-09-25 to match the code at `9996268`. The previous version is archived byte-for-byte at `docs/history/CLAUDE-archive-2026-09-25.md`.

## ⚠️ Progress Protocol (READ FIRST)

**`main` is the trunk (owner decision 2026-08-16).** Work goes on `main` directly, or on a short-lived feature branch merged into `main`, and is pushed to origin. The workspace-wide `vocify-v2` gate is retired, and that branch never existed in this repo. `PROGRESS.md` is the single source of truth for what is done and what is next.

**MANDATORY every session:**
1. **Before starting**, read `PROGRESS.md`, find the current task, mark it `🔄 in progress`.
2. **Before claiming ANY task done**, update `PROGRESS.md` in the same turn: tick the box, set status, add a one-line note (date + what changed + commit ref).
3. Never report work complete without the matching `PROGRESS.md` edit.

> **Filename note:** renamed from lowercase `claude.md`/`progress.md` on 2026-09-25 to match the sibling repos (Claude Code auto-loads `CLAUDE.md` only). Code comments cite `PROGRESS.md §N`; those section numbers are kept stable — do not renumber §5–§12.

Git: always pin the repo (`git -C C:/projects/vocify/plugins …`), because agent shells reset cwd. Stage explicit paths and never use `git add -A`, because the untracked `plugins.zip` (~10 MB) sits in the root.

## Layout (real, not aspirational)

```
plugins/
├── woocommerce/                      # WordPress plugin "vocify-ai-woocommerce", v1.1.0 (PHP 7.4+, WP 5.8+, WC 5.0+)
│   ├── vocify-ai-woocommerce.php     # bootstrap: activation hooks at include time; everything WC-dependent in on_plugins_loaded()
│   ├── includes/
│   │   ├── class-vocify-admin.php            # settings page, Test Connection (AJAX), URL sanitising
│   │   ├── class-vocify-order-handler.php    # woocommerce_new_order (10,2) + woocommerce_order_status_changed, order meta box
│   │   ├── class-vocify-payload-builder.php  # WC_Order → unified payload (pure array)
│   │   ├── class-vocify-payload-validator.php
│   │   ├── class-vocify-signer.php           # Vocify_AI_Signer — outbound HMAC + headers
│   │   ├── class-vocify-webhook-service.php  # send, 3× backoff, failed queue, is_allowed_webhook_url()
│   │   └── class-vocify-status-receiver.php  # INBOUND: REST vocify/v1/order-status
│   ├── assets/{css,js}/admin.*
│   └── tests/                        # PHPUnit, no WP bootstrap: 31 tests (builder, validator, signer)
├── prestashop/                       # PrestaShop module "vocifyai", v1.2.0 (PHP 7.1+, PS 1.7.0 → 8.x)
│   ├── vocifyai.php                  # module class: hooks, config form, install/uninstall, double-emit + re-entrancy guards
│   ├── classes/
│   │   ├── VocifyPayloadBuilder.php / VocifyPayloadValidator.php / VocifySigner.php
│   │   ├── VocifyWebhookService.php  # cURL send (https-only protocols, no redirects), isAllowedWebhookUrl() SSRF guard
│   │   └── VocifyStatusReceiver.php  # INBOUND decision logic, CMS-free (so it is unit-testable)
│   ├── controllers/front/
│   │   ├── cron.php                  # token-protected retry of the failed queue
│   │   └── webhook.php               # INBOUND: thin adapter over VocifyStatusReceiver
│   ├── upgrade/upgrade-1.2.0.php     # creates vocify_call_results, seeds state mapping, registers displayAdminOrderSide
│   ├── views/templates/admin/{order_info,webhook_logs}.tpl
│   └── tests/                        # PHPUnit, no PS bootstrap: 68 tests incl. 33 in VocifyStatusReceiverTest
├── test/e2e/                         # Dockerised plugin ↔ DEPLOYED platform harness (see below)
├── docs/
│   ├── CMS_PLUGINS_SPECIFICATION.md  # ⚠️ 2025 spec — its HMAC/header examples are SUPERSEDED (body-only HMAC)
│   ├── engineering_philosophy.md
│   └── history/                      # verbatim archives of CLAUDE.md / PROGRESS.md + review reports
├── VALIDATION_REPORT.md              # ⚠️ historical (2025), prescribes the old body-only HMAC
└── PROGRESS.md
```

Shopify and Magento are **planned only**. No code exists for them.

## Commands

This machine has no local PHP. Run everything through Docker (php 8.2-cli / `composer:2`). Use the WSL Ubuntu dockerd if Docker Desktop's engine is down.

```bash
# per plugin (cd woocommerce | cd prestashop) — inside a composer:2 / php:8.2-cli container
composer install            # dev deps (phpunit) — for testing only
composer test               # vendor/bin/phpunit
composer lint               # php -l over the plugin's PHP files
```

**Release zips:** build them from a `composer install --no-dev --optimize-autoloader` tree. A dev `vendor/` must never ship. It pulls nikic/php-parser v5, which shadows the v4 that PrestaShop needs, and then `prestashop:module install` dies for *every* module on the shop (progress.md §8). The zip root folder must be the plugin slug: `vocify-ai-woocommerce/` for WordPress, `vocifyai/` for PrestaShop. See `woocommerce/INSTALL.md` "Create Distribution Package". The repo has no build script. ⚠️ The untracked root `plugins.zip` is **not** a release artifact: it contains dev `vendor/` (phpunit, php-parser) and `.phpunit.result.cache`, and its roots are `woocommerce/`/`prestashop/`.

## Outbound contract: shop → platform

`POST {webhook_url}` (default `https://app.vocify-ai.com/api/webhooks/ecommerce`, see the domain warning below). The platform verifies it in `platform/src/lib/auth/api-key.ts`.

```
Content-Type: application/json
X-Platform:   WOOCOMMERCE | PRESTASHOP
X-API-Key:    vcf_live_… / vcf_test_…   (per-agent key; plugin regex vcf_(live|test)_[a-zA-Z0-9]{16,})
X-Domain:     store host, lowercased, "www." stripped
X-Timestamp:  gmdate('c')  e.g. 2026-09-19T12:05:39+00:00
X-Signature:  hex HMAC-SHA256(signatureSecret, X-Timestamp + "." + rawBody)
```

```php
$rawBody   = json_encode($payload);       // sign and send the SAME bytes
$timestamp = gmdate('c');                 // generated ONCE, used for header AND signature
$signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $signatureSecret);
```

- The key is the **per-agent signing secret** from the dashboard, **never the API key**. The timestamp is **bound into** the signed message (fix of 2026-09-19, progress.md §7). The platform enforces a **300 s two-sided** freshness window.
- **The signing secret is mandatory in practice.** The signers still omit `X-Signature` when no secret is configured, but the platform fails closed: a secretless key is rejected (`SIGNATURE_REQUIRED`), and an unsigned request to a key that has a secret is rejected too (`SIGNATURE_MISMATCH`).
- **Two date formats, on purpose.** `X-Timestamp` may be `+00:00`, and the platform accepts it. Payload dates (`createdAt`, optional dates) **must** use a literal `Z`, `PLATFORM_DATE_FORMAT = 'Y-m-d\TH:i:s\Z'`, because Zod `z.string().datetime()` rejects `+00:00` (§8.2, which broke every webhook).
- Responses: `201` created · `200` duplicate/idempotent · `400` bad payload/headers · `401` bad key/signature/timestamp · `403` domain mismatch · `5xx` retried. **4xx is never retried.** Retries: 3 attempts, backoff 2 s/4 s/8 s, then the failed queue (WC: hourly WP-Cron `vocify_retry_failed_webhooks`; PS: `controllers/front/cron.php` with `VOCIFY_CRON_TOKEN`).
- Required payload fields: `orderId`, `orderNumber`, `customer.{firstName,lastName,email,phone}` (phone E.164 via libphonenumber with a fallback, **calls fail without it**), `items[]` (≥1), `totals.total`, `currency` (ISO 4217), `shippingAddress.{address1,city,country}`, `createdAt`. The validator refuses to send an incomplete payload and logs it.
- PrestaShop sends one webhook per new order. `actionOrderStatusPostUpdate` is skipped when it carries the state `actionValidateOrder` already forwarded (keyed on the state, not the order).
- Known quirk: PrestaShop `status` is the **localised display name** of the order state, while WooCommerce sends a machine slug (`processing`). Undecided contract question (§8).

**SSRF / URL guard (pentest 2026-09-18).** Both plugins accept only an absolute `https://` URL with a host and no embedded credentials, checked on save, Test Connection, send and retry. PrestaShop additionally rejects private/loopback/link-local/reserved hosts, numeric IPv4 aliases, `localhost`/`.local`/`.internal`, and hostnames that resolve to non-public addresses. Its cURL is locked to `CURLPROTO_HTTPS` with `FOLLOWLOCATION=false`. Consequence: **no local/Docker platform URL is ever accepted.** Test against a real public https host.

## Inbound contract: platform → shop (call result)

After a call completes, the platform's e-commerce sync cron (~60 s) POSTs the outcome to the shop.

| | WooCommerce | PrestaShop |
|---|---|---|
| Endpoint | `POST {store}/?rest_route=/vocify/v1/order-status` (or `/wp-json/vocify/v1/order-status`) | `POST {store}/index.php?fc=module&module=vocifyai&controller=webhook` |
| Code | `class-vocify-status-receiver.php` | `controllers/front/webhook.php` → `classes/VocifyStatusReceiver.php` |
| Outcome → status | `confirmed→processing`, `cancelled→cancelled`, `completed→completed`; filter `vocify_status_map` | `VOCIFY_STATE_CONFIRMED/_CANCELLED/_COMPLETED` = numeric `id_order_state`, defaults `PS_OS_PREPARATION/PS_OS_CANCELED/PS_OS_DELIVERED`, deleted state falls back |
| Idempotency store | order meta | table `vocify_call_results` (`call_sid` UNIQUE, global) |

Common to both:
- Headers `X-Vocify-Timestamp` + `X-Vocify-Signature` = hex HMAC-SHA256(**the same `signatureSecret`**, `"{X-Vocify-Timestamp}.{rawBody}"`), compared with `hash_equals`, **300 s** two-sided window.
- **Fail-closed:** no secret configured → `503 vocify_not_configured`. Missing/unparsable/stale timestamp or bad signature → `401`. Non-JSON or missing `orderId`/`status` → `400`. Unknown order → `404`.
- Idempotent on `callData.callSid`. A strictly **older** `callData.completedAt` than the last applied one answers `200 {"changed": false}`, so a delayed retry of an old call cannot rewind the order. Any other outcome (no_answer, voicemail…) adds a merchant-visible note and leaves the status alone.
- PrestaShop suppresses its own outbound hook while it applies an inbound state (`VocifyAI::$suppressOutboundWebhooks`), so it does not echo the result back. The order panel renders on `displayAdminOrderSide` (PS 8) **and** `displayAdminOrderLeft` (1.7.0–1.7.6).
- ⚠️ **PrestaShop 302s any request whose `Host` is not the canonical shop domain** (`ps_shop_url` main row), before the controller runs. The platform fetches with `redirect: 'error'`, so a merchant with a non-canonical domain silently never receives results.
- `VOCIFY_ENABLED` / `vocify_enabled` governs the **outbound** direction only. Inbound results are accepted regardless.

## E2E harness (`test/e2e/`)

A real shop (Docker) fires the plugin's own hook, the shipped PHP signs the order, the **deployed** platform accepts it, and the rows are read back from Postgres. The return path is also exercised: a completed call changes the order in the shop's own MySQL, reaching the shop via a Cloudflare quick tunnel.

```bash
cd plugins/test/e2e
./run.sh                      # or run.cmd — orchestrate.mjs: suites wc, ps, rt
./run.sh --only wc|ps|rt      # also --keep-up --keep-fixture --skip-build --slow-replay
./run-ps-return.sh            # PrestaShop return path: ps-return + ps-internet (--keep-up --reuse --skip-internet)
```

- **Target:** `TEST_BASE_URL`, default **`https://152-228-210-12.sslip.io`** (the OVH test VPS since 2026-09-25; it was `162-19-32-251` before). `REPORT*.md` are gitignored and still show the old host from the 2026-09-19 runs.
- **Needs:** Docker, outbound internet from the containers, `platform/.env` (`DATABASE_URL`), and `platform/node_modules` (it borrows `tsx`, `pg` and the Recorder read-only; nothing under `platform/` is written).
- ⚠️ **It writes to the live Supabase DB.** It creates a throwaway tenant, then orders, attempts and calls, and tears them down. Guards against dialling: the fixture's calling window is closed, the run aborts unless `scheduled_at` is >1 h out, every suite re-asserts it, and the phone is `+21600000000`. Still, a leftover `pending` call races the deployed cron. Read `test/e2e/README.md` before running, and treat any live-call risk as owner-gated.
- Exit code 0 = green, 1 = assertion failed, 2 = harness could not run. A deployed build that lacks a fix shows as `BLOCKED`, never as a pass.

## Known traps / open owner items

- 🔴 **`vocify-ai.com` is UNREGISTERED** (RDAP 404, pentest 2026-09-18), and `https://app.vocify-ai.com/api/webhooks/ecommerce` is the **default shipped in both plugins**. Whoever registers it would receive every store's API key and customer PII. The owner must register the domain, or the default must change to an owned hostname, before any zip ships. "Staging" (`staging.vocify-ai.com`) does not exist. The sslip.io host is a test VPS, not production.
- WooCommerce: `woocommerce_new_order` fires before `save_items()`, so use the passed `WC_Order` and never re-fetch it (§8.3). It can also fire with `total = 0`, and there is no guard for that.
- WooCommerce: nothing may be gated on `class_exists('WooCommerce')` at include time. Plugins load in path order (§8.1).
- PrestaShop: `actionValidateOrder` fires before the state is applied, so use `$params['orderStatus']` (§8.4).
- Never loosen the SSRF guard, freshness window, or fail-closed behaviour to make a test pass. The e2e harness satisfies them with real public https instead.

## Standards (kept short)

#KISS · #FewerMovingParts · #YAGNI · #BoringTechnology · #TwoWeekTest. Other rules:
- Never log API keys, secrets, or customer PII in plain text. Escape everything rendered in the back office (PS: `escape:'html'`, no inline JS handlers; WC: `.text()`, not `.html()`). Both were XSS findings in 2026-09-18.
- Every contract change needs a known-answer PHPUnit test (a literal digest computed outside the class under test) **and** an e2e run. PHPUnit was green while §8.2 broke every webhook.
- SemVer. Bump the version in the plugin header/`$this->version` **and** `composer.json`, and add a CHANGELOG entry. A PrestaShop schema change needs an `upgrade/upgrade-X.Y.Z.php`.
