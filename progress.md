# Vocify CMS Plugins - Development Progress

**Last Updated**: 2026-09-19
**Version**: 1.1.0 (WooCommerce + PrestaShop) — the 2026-09-18 pentest fixes (§6), the 2026-09-19 HMAC timestamp-binding fix (§7) and the four e2e-found bugs of §8 are all in the working tree, commit pending
**Branch:** `main` (trunk — decided by the owner 2026-08-16; the old `vocify-v2` gate is retired and the branch was never created here. Work lands on `main` or a short-lived feature branch, pushed to origin.)

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

## 6. Security fixes (pentest 2026-09-18) ✅ code + verification — ⏳ commit pending

**Source:** owner-authorized penetration test, `pentest-2026-09-18/reports/plugins-sec.md`. Findings 1 (High, mitigation only), 2 and 3 (Medium) are fixed here; finding 4 (WC reflected XSS) is folded into the XSS fix. Findings 5 (timestamp not signed — needs a platform-side change) and all Low/Info items are **not** addressed in this pass and remain with the lead. Full per-file diff summary and verification log: `pentest-2026-09-18/reports/fix-plugins.md`.

| Fix | Status | Notes |
|-----|--------|-------|
| [Medium] Stored XSS in PS back office — `webhook_logs.tpl`, `order_info.tpl` | ✅ | Inline `onclick="…{$log.response\|escape:'javascript'}…"` (HTML-entity bypass) replaced by `data-response="{$log.response\|escape:'html':'UTF-8'}"` + an unobtrusive click listener that alerts the attribute as text. Verified by rendering both templates with Smarty 4.5.7 and the finding's payload: `&#39;` → `&amp;#39;`, no `onclick=` left, attribute decodes back to the original body byte-for-byte. |
| [Medium] Reflected XSS in WC Test Connection — `admin.js`, `class-vocify-admin.php` | ✅ | jQuery `.html()` → `.text()` for all three result messages; PHP no longer returns the raw remote body (JSON `error` field, else tag-stripped 200-char excerpt). |
| [Medium] PS LFI/SSRF via unvalidated webhook URL — `vocifyai.php`, `VocifyWebhookService.php` | ✅ | New `VocifyWebhookService::isAllowedWebhookUrl()`: https only, no embedded credentials, rejects loopback/private/link-local literals (`FILTER_FLAG_NO_PRIV_RANGE\|NO_RES_RANGE`), `localhost`/`.local`/`.internal`, numeric IPv4 aliases (`2130706433`, `0x7f000001`, `127.1`, `0177.0.0.1`), and hostnames that resolve to a non-public address. Enforced on save (`Validate::isAbsoluteUrl` + helper), Test Connection, `sendOrder`, `sendWebhook`, `retryFailedWebhooks`. Both cURL calls now set `CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS = CURLPROTO_HTTPS` and `CURLOPT_FOLLOWLOCATION = false`. |
| [High — mitigation] Endpoint hardening, both plugins | ✅ | WC: new `Vocify_AI_Webhook_Service::is_allowed_webhook_url()` (https + non-empty host, no credentials) enforced on save (custom `sanitize_webhook_url` keeps the old value and shows a settings error), Test Connection, `send_order`, `send_webhook`, `retry_failed_webhooks`. PS: as above. **The default `app.vocify-ai.com` was left unchanged on purpose — `vocify-ai.com` is UNREGISTERED (RDAP 404) and registering it is the owner's job, not a code fix.** |
| Verification | ✅ | `php -l` 32/32 clean (php 8.2-cli in WSL docker); PHPUnit WC 27/27 (68 assertions), PS 31/31 (71 assertions); 40 URL probes green (all `file://`, `gopher://`, `dict://`, `http://`, loopback/private/link-local/numeric-alias cases denied; public https allowed, including the unresolvable default). |
| Commit + push on `main` | ⏳ | Deliberately **not committed** — the lead reviews the working tree first. |

---

## 7. HMAC contract fix — timestamp binding (2026-09-19) ✅ code + verification — ⏳ commit pending

**Source:** deploy-blocking bug found after the platform's 2026-09-18 security fix (§6, pentest finding 5) landed on the platform side. The platform (`platform/src/lib/auth/api-key.ts`) now (a) computes `HMAC-SHA256(secret, "${X-Timestamp}.${rawBody}")` — timestamp bound into the signed message — instead of body-only, (b) requires `X-Timestamp` (ISO 8601) within a 300s two-sided freshness window, and (c) **fails closed**: a key with no `signatureSecret` is rejected instead of allowed through. Both plugins still signed body-only and generated `X-Timestamp` as a header-only cosmetic value never fed into the signature — every live WooCommerce/PrestaShop webhook would have 401'd the moment the platform deployed.

| Fix | Status | Notes |
|-----|--------|-------|
| WooCommerce `Vocify_AI_Signer` | ✅ | `sign()` is now `sign($timestamp, $raw_body, $secret)`, hashing `build_signed_message()` = `"{$timestamp}.{$raw_body}"`. `build_headers()` generates `gmdate('c')` **once** into a local `$timestamp`, uses it for both the `X-Timestamp` header and the signature — closes the "two separate timestamps" variant of this bug class. `class-vocify-webhook-service.php` call site unchanged (already captured `$raw_body` once and reused it for both signing and the POST body). |
| PrestaShop `VocifySigner` | ✅ | Same shape: `sign($timestamp, $rawBody, $secret)` over `buildSignedMessage()`; `buildHeaders()` shares one `$timestamp` between header and signature. `VocifyWebhookService::sendWebhook()` call site unchanged (already single `$rawBody` var for both signing and `CURLOPT_POSTFIELDS`). |
| PHPUnit known-answer tests, both plugins | ✅ | Added `testKnownAnswerVector` (fixed secret/timestamp/body → literal hex digest computed independently via `node -e` + cross-checked with `php -r 'hash_hmac(...)'`, not via the class under test), `testSignatureNoLongerMatchesWhenTimestampIsAltered` (negative KAT — stale/altered timestamp → different literal digest), `testSignatureNoLongerMatchesBodyOnlyHmac` (guards against regressing to the exact old bug), `testBuildSignedMessageFormat` (pins the `.` separator), `testHeaderTimestampIsTheSameOneFoldedIntoTheSignature` (catches the header/signature timestamp-mismatch bug class specifically). |
| Doc comment correction, both signers | ✅ | The `buildHeaders()`/`build_headers()` comment previously said "an unsigned request is the correct fallback for keys that have no signatureSecret configured" — true pre-fail-closed, false now: the platform rejects a secretless key's requests unconditionally (`SIGNATURE_REQUIRED`) and rejects an unsigned request to a key that DOES have a secret (`SIGNATURE_MISMATCH`). Comment rewritten to say this; plugin **behavior** (whether to hard-refuse sending with no local secret) was deliberately left unchanged — that's a merchant-visible call for the lead, not made here. |
| `plugins/CLAUDE.md` "HMAC Signature Generation" example | ✅ | Was itself prescribing the old bug (`hash_hmac('sha256', $rawBody, $apiKey)` — body-only, keyed on the API key). Since this file is always-loaded live guidance for this repo, left-wrong it would regenerate the bug for the next session. Updated to the timestamp-bound form; Standard Headers block now notes X-Signature/X-Timestamp are required (fail-closed), not optional. |
| Verification | ✅ | `php -l` clean on all changed files (php 8.2-cli via Docker in WSL Ubuntu — Docker Desktop's own engine was down, native WSL dockerd used instead). PHPUnit full suites: WC 31/31 (74 assertions, was 27/27 pre-fix), PS 35/35 (79 assertions, was 31/31 pre-fix) — re-confirmed green after the follow-up comment/doc edits too. Cross-language check: `node -e` (crypto) and `php -r` (hash_hmac) produce the byte-identical digest `9b1f531f…c613c` for the fixed vector — full comparison in `plugin-hmac-fix-report.md`. Broader `grep -rn "hash_hmac\|X-Signature" --include=*.md --include=*.txt` also run: found the same stale body-only pattern in `VALIDATION_REPORT.md`, `docs/CMS_PLUGINS_SPECIFICATION.md`, and both plugins' `CHANGELOG.md` — left as-is (dated historical records), see report §6. |
| Version | ℹ️ | Signer docblocks briefly read `1.2.0`, reverted to `1.1.0` to match `composer.json`/plugin headers/this file. Whether this fix ships as 1.1.1 or folds into the pending pentest-fix commit's version bump is the lead's call at commit time. |
| Commit + push on `main` | ⏳ | Deliberately **not committed** — the lead reviews the working tree first, per this session's instructions. |

---

## 8. Plugin ↔ platform end-to-end test harness (2026-09-19) ✅ both plugins GREEN against the deployed VPS

**Why:** §7's HMAC fix was proven only by a known-answer unit test. Nothing had ever exercised the shipped PHP across a real HTTP boundary against a running platform. The harness built here does: a real order placed in a real shop, through the shop's own hooks, signed by the shipped signer, accepted by the **deployed** platform at `https://162-19-32-251.sslip.io`, landing as `orders` + `attempts` rows in Supabase. **It found four real bugs — three of which PHPUnit could not see, and one of which PHPUnit was actively asserting.**

**Result: 86 assertions passed, 0 failed** across the two inbound suites (48 WooCommerce, 38 PrestaShop), plus 5 in the `preflight` group that establish what the target enforces. Verified on a cold `./run.sh --slow-replay`, which also ran §9's return-path suite in the same pass: **118 passed, 0 failed, 0 skipped, 1 blocked** overall in 834s. The single `BLOCKED` belongs to §9 (the deployed build predates a working-tree fix) — nothing in §8 is skipped or blocked.

**Harness:** `plugins/test/e2e/` (new, additive). One command: `./run.sh`. Design notes in `plugins/test/e2e/README.md`. Nothing under `platform/` is written — its `Recorder`, `pg` and `tsx` are borrowed read-only.

### The deployed platform enforces the new contract — measured, not assumed

Every run begins by establishing which signing contract the TARGET enforces, because a plugin that is correct against the timestamp-bound contract fails against an older deployment in a way indistinguishable from a plugin bug. Against deployed commit `c108474`:

```
  body-only signature (OLD contract)  -> HTTP 401 SIGNATURE_MISMATCH
  timestamp-bound signature (NEW)     -> HTTP 201
  no X-Signature at all               -> HTTP 401 SIGNATURE_MISMATCH
  correctly signed, 6-minute-old ts   -> HTTP 401 TIMESTAMP_INVALID
VERDICT: NEW
```

The run aborts before a single container starts if that verdict is anything else.

### Bugs found and fixed

| # | Severity | Bug | Fix |
|---|----------|-----|-----|
| 8.1 | 🔴 **Critical (WC)** | **The WooCommerce plugin registered NO hooks at all on a normal install — zero webhooks would ever have been sent.** `register_activation_hook()` and every `add_action()` sat behind an `is_woocommerce_active()` early-return evaluated at **file-include time**. WordPress includes active plugins in path order, so `vocify-ai-woocommerce/…` is always included **before** `woocommerce/woocommerce.php`, and `class_exists('WooCommerce')` is false. Measured on WP 6.x + WC 11.1.1: `order_handler === null`, no Vocify callback on `woocommerce_new_order`, and `wp_vocify_webhook_logs` / `wp_vocify_failed_webhooks` never created — so the log panel stays empty and the retry queue is permanently dead even after WooCommerce is activated, because an activation hook fires once. | `vocify-ai-woocommerce.php`: activation/deactivation hooks and `before_woocommerce_init` are now registered unconditionally at include time; everything needing WooCommerce moved into a new `on_plugins_loaded()` on the `plugins_loaded` hook, where `class_exists('WooCommerce')` is finally a meaningful test. |
| 8.2 | 🔴 **Critical (both)** | **Every webhook was rejected with HTTP 400 `{"createdAt":["Invalid ISO datetime"]}`.** Both plugins emitted `gmdate('c')` → `2026-09-19T12:05:39+00:00`; the platform validates these fields with Zod `z.string().datetime()`, which accepts **only** a literal `Z` designator (confirmed against the platform's own zod 4.1.12). Same instant, same standard, different spelling. **The PHPUnit suites were asserting the broken format**, which is precisely why unit tests were green while nothing worked. | A `PLATFORM_DATE_FORMAT` constant used by both date sites in each payload builder (`createdAt`, `addOptionalDate`). Test assertions corrected. |
| 8.3 | 🟠 **Major (WC)** | **`woocommerce_new_order` webhooks always failed with "Failed to transform order data".** `handle_new_order()` accepted one argument and re-read the order with `wc_get_order($order_id)`. That hook fires from inside `WC_Abstract_Order::save()` → `$data_store->create()`, **before** `save_items()`, so the re-read order has zero line items and the payload builder correctly refuses to build it. Measured inside the hook: passed object 1 item, re-fetched object 0 items. Orders only reached the platform when a later status change happened to fall in the forwarded list. | Hook registered with `10, 2`; `handle_new_order($order_id, $order = null)` now uses the `WC_Order` WooCommerce passes, falling back to a lookup only for callers that fire the action with an id alone. |
| 8.4 | 🟠 **Major (PS)** | **`actionValidateOrder` webhooks always failed with "Payload validation failed: status must be a non-empty string".** `hookActionValidateOrder()` read `$order->current_state`, but `PaymentModule::validateOrder()` fires the hook at `classes/PaymentModule.php:560` and applies the status roughly twenty lines later via `OrderHistory::changeIdOrderState()`. `new OrderState(0)` is unloaded, so `status` came out empty. PrestaShop passes the state it is about to apply in the same `$params` array; the module ignored it. Measured on PrestaShop 8.2.8. | `vocifyai.php`: when `$order->current_state` is empty, seed it from `$params['orderStatus']` on the in-memory object before transforming. |

### Observations recorded, deliberately NOT fixed

- **One PrestaShop order emits TWO webhooks back to back** — `actionValidateOrder` (201) then `actionOrderStatusPostUpdate` (200, idempotent), because `validateOrder()` applies the order state immediately after firing the first hook and the module forwards *every* status update by design. WooCommerce emits one. Not a defect, but it doubles a merchant's webhook volume and deserves a deliberate decision. Asserted as "exactly one 201, any additional deliveries 200".
- **`isset($orderState->slug)` in `VocifyWebhookService::transformOrder()` is dead code.** `OrderState` has no `slug` property in PrestaShop 8 (verified: `property_exists() === false`), so it always falls through to `$orderState->name` — a **localised display name** ("Payment accepted"). PrestaShop therefore sends translated, human-readable statuses where WooCommerce sends machine slugs (`processing`). A contract decision for the lead, not a blind edit.
- **`woocommerce_new_order` can legitimately fire with `total = 0`.** `WC_Abstract_Order::calculate_totals()` saves the order before writing the computed total back, so a payment gateway that builds an order and calls it fires the hook with subtotal 129.9 and total 0 (measured, WC 11.1.1). The plugin forwards whatever the order says and has no zero-total guard.
- **A dev checkout's `vendor/` breaks PrestaShop outright.** `vocifyai.php:16` loads `vendor/autoload.php` unconditionally; with dev dependencies installed that pulls nikic/php-parser v5, shadowing the v4 PrestaShop depends on, and `bin/console prestashop:module install` dies with an undefined-method error on `PhpParser\ParserFactory` — **no module can be installed at all**. `vendor/` is gitignored and not shipped, so a released zip is unaffected; the harness mounts an empty directory over it to reproduce the shipped state.

### Verification

| Check | Result |
|-------|--------|
| Full cold run against the deployed VPS | ✅ **118 passed, 0 failed, 0 skipped, 1 blocked** in 834s — 86 inbound assertions (this section), 5 preflight, 27 return-path (§9). The one `BLOCKED` is §9's. |
| Genuine replay outside the freshness window | ✅ A request the plugin actually transmitted, captured and re-sent 301s later: HTTP 401 `TIMESTAMP_INVALID`. Run with `--slow-replay`; the crafted stale-timestamp case covers the same branch on every run. |
| WooCommerce happy path | ✅ Real order → `woocommerce_new_order` → shipped signer → HTTP **201** → `orders` + `attempts` rows in live Supabase, `credits_held` incremented, `scheduled_at` inside a closed calling window. |
| PrestaShop happy path | ✅ Real order via `PaymentModule::validateOrder()` → `actionValidateOrder` → shipped signer → HTTP **201** → same row assertions. |
| Signature over the wire, both plugins | ✅ `X-Signature` recomputed independently in Node from the **transmitted** `X-Timestamp` and the **transmitted** bytes matches the shipped PHP digest, and is asserted **not** to equal the old body-only HMAC. First evidence §7's fix works outside a unit test. |
| Negative matrix, both plugins | ✅ tampered body → 401 `SIGNATURE_MISMATCH`; altered timestamp → 401; stale (6 min) timestamp → 401 `TIMESTAMP_INVALID`; wrong API key → 401 (WC: with no retry — 4xx is terminal); domain mismatch → 403; in-window replay → 200 idempotent, no duplicate row. |
| SSRF hardening (the 2026-09-18 fix) | ✅ WooCommerce refuses `http://` with **zero** packets leaving the container. PrestaShop refuses `http://`, `https://` to a private address, and `https://` to localhost — all three, all locally. |
| Local payload validation | ✅ An order with no phone is refused by the plugin's own validator; nothing is sent, and the failure is logged. |
| PHPUnit after all fixes | ✅ WooCommerce 31/31 (74 assertions), PrestaShop 35/35 (79 assertions); `php -l` clean on every changed file. |
| Commit + push | ⏳ Deliberately **not committed** — working tree only, per this session's instructions. |

---

## 9. Return path: platform → shop (2026-09-19) ✅ WooCommerce GREEN — 🔴 PrestaShop still has no receiver

**Question asked:** when an AI call completes, does the merchant's order status change in their shop?

**Answer before this session: no, and it never had.** Two independent, fatal breaks, both measured
end to end against a Dockerised WooCommerce 11.1.1 behind a real public https origin, with the
deployed platform on the OVH VPS:

| # | Break | Evidence |
|---|---|---|
| 9.1 🔴 | **Neither plugin implemented the endpoint the platform posts to.** `grep -rn register_rest_route woocommerce/ prestashop/` → no matches. The only inbound-looking hook in either repo is `wp_ajax_vocify_test_connection`, an outbound probe behind a nonce. | `POST {store}/?rest_route=/vocify/v1/order-status` → `404 {"code":"rest_no_route"}` at the wire. |
| 9.2 🔴 | **The platform never reached the network anyway.** `ecommerce-sync.service.ts` passed `integration.credentials` to the adapter, but every CMS adapter needs `credentials.storeUrl` and the platform keeps the URL in the `integrations.store_url` **column** — the wizard writes only `{consumerKey, consumerSecret}`. `assertSafeExternalUrl(undefined)` threw inside the constructor. | Deployed cron tick: `sync {synced:0, failed:1}` with **zero** lines added to the shop's Apache access log. Adapter called directly: `{"success":false,"error":"Store URL must be a public https:// address"}`. |

Production data agreed: of 73 `calls` rows with a real outcome, **67 are `sync_status='failed'` with the
retry budget exhausted**, and all **6** `synced` rows are `outcome='customer_callback'` — the
"no e-commerce semantics, skip the push" short-circuit. No status has ever been pushed to any shop.

**Fixed in the working tree (plugins):**

- **`woocommerce/includes/class-vocify-status-receiver.php` — NEW.** Registers
  `POST vocify/v1/order-status`. Authenticates with HMAC-SHA256 over
  `"{X-Vocify-Timestamp}.{rawBody}"` using the **same** `vocify_signature_secret` the merchant
  already pastes in for the outbound direction (no second credential, and the platform has no UI
  that fills `integrations.webhook_secret` — it is NULL on every integration in production).
  300 s two-sided freshness window, `hash_equals`, fail-closed when no secret is configured.
  Idempotent on `callData.callSid` via order meta. `confirmed → processing`,
  `cancelled → cancelled`, `completed → completed` (filter: `vocify_status_map`); every other
  outcome writes a merchant-visible order note and leaves the status alone, because it says nothing
  about whether the customer still wants the order.
- **`woocommerce/vocify-ai-woocommerce.php`** — requires the new class and hooks
  `rest_api_init` from `init_hooks()`.
- ⚠️ **PrestaShop has no receiver and this session did not build one.** `controllers/front/`
  still contains only `cron.php`. The platform's PrestaShop adapter was additionally wrong in two
  ways — module slug `vocify` instead of `vocifyai`, and the friendly-URL-only `/module/<m>/<c>`
  form — both corrected platform-side, but **unverified**, because nothing answers that URL yet.
  Until a `webhook` front controller exists, a PrestaShop merchant's orders never change.

**Fixed in the working tree (platform):** see `platform/PROGRESS.md` — the `storeUrl` bug, the
order-status-before-push divergence, a missing SSRF guard on the outbound PrestaShop adapter, and
body-only outbound signatures on all four adapters.

**Harness:** `plugins/test/e2e/suites/wc-return.mjs` + `docker-compose.rt.yml` (project
`vocify-e2e-rt`, port 58081) + `lib/platform-sync.mjs` + `rt/run-platform-sync.mts`, wired into
`orchestrate.mjs` as a third suite (`./run.sh --only rt`). The shop is installed with a Cloudflare
quick-tunnel hostname as its `siteurl`, which is what lets the platform reach it — the SSRF guard
was never relaxed, patched or bypassed.

**Cold run, 2026-09-19 — default `./run.sh`, all three suites:**

```
› 118 passed, 0 failed, 1 skipped, 1 blocked
  PASS  THE HEADLINE: the order's status changed in WooCommerce's own database
  PASS  the merchant gets an order note saying what happened on the call
  PASS  a repeat of the same callSid is a no-op, even with a different status
  PASS  an older result from a DIFFERENT call cannot rewind the order
  PASS  a body-only signature (unbound timestamp) is rejected
  PASS  REGRESSION GUARD: a failed push does NOT flip the platform Order status
  BLKD  whether the DEPLOYED platform can push this call to the shop
        measured {"synced":0,"failed":1} — the deployed build predates the fix
```

(The return-path rows alone are 28 passed + 1 blocked in the run above:
`./run.sh --only rt`. A couple of its checks are branch-conditional, so the
exact row count moves by one or two between runs -- a concurrent run by the
sibling agent recorded 27 + 1 for the same suite, with the same 118 total.)

The headline assertion reads `wp_posts.post_status` straight out of the shop's MySQL
(`pending` → `wc-processing`), not `wc_get_order()` and not `calls.sync_status`.

**One more bug, found in the receiver written this session and fixed before it
shipped:** deduping on `callData.callSid` alone lets an OLDER result rewind an
order. An order can have several calls (a retry makes a second Attempt and a
second Call) and the platform retries a failed sync five times, so call A
`confirmed` → call B `cancelled` → a delayed retry of A would flip `cancelled`
back to `processing`. The receiver now also stores `callData.completedAt` and
refuses a strictly older result with `200 {"changed": false}`. Asserted.

⚠️ **The deployed cron runs on a ~60 s schedule — measured, and it matters here.**
A probe row left completely untouched on live Supabase climbed
`sync_attempts` 0→1→2→3 on its own, and its order went `SCHEDULED` → `CONFIRMED`
at the same tick that failed the push. Two consequences. First, the platform's
divergence bug has already left **21 live orders** showing a status their shop
was never told about (20 `CONFIRMED` + 1 `CANCELLED`, 2026-08-13 -> 2026-09-18),
all belonging to one company named "Demo Company" (created 2025-11-29) — likely
the project's own demo tenant, inferred from the name and not confirmed. That set is **frozen, not growing per minute**: the
cron's candidate query (`ecommerce-sync.service.ts:306-309`) takes only
`syncStatus: 'pending'` AND `syncAttempts < MAX`, so the 67 exhausted `failed`
rows are never revisited -- new damage accrues once per NEW completed call, not
once a tick. Second, any harness that leaves a `pending` call with an outcome
lying around is racing the VPS. The return-path suite handles this explicitly (it starts that
fixture call at `sync_attempts = 4` so exactly one tick can act on it, and
reports a lost race as BLOCKED rather than FAIL).

**Still open:**

1. 🔴 Build the PrestaShop `webhook` front controller to the same contract, then re-point
   `buildModuleWebhookUrl()` at whatever it actually answers.
2. 🟠 Deploy the platform fixes — until then production still pushes nothing (the BLOCKED row above).
3. 🟡 Bump the WooCommerce plugin to **1.2.0** with a CHANGELOG entry: the receiver is a new feature,
   and merchants must re-check that their signing secret is filled in, since it now authenticates
   both directions.
4. 🟡 No PHPUnit test was added for the receiver. Its four rejection branches are asserted by the
   e2e suite against real WordPress, which is stronger than a stub — but a unit test would catch a
   contract drift without Docker.
5. 🟡 `calls` rows already at `sync_attempts = 5` are permanently `failed` and will never retry once
   the fixes deploy. If those orders matter, they need a one-off reset.

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
| Commit + push on `main` | ✅ | already on `main` — `a8e8c23` (the "blocked on branch decision" note was stale; owner resolved 2026-08-16: trunk is `main`) |

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
| `app.vocify-ai.com` has no DNS record (checked 2026-08-14) — **and `vocify-ai.com` itself is UNREGISTERED** (RDAP 404, pentest 2026-09-18): whoever registers it receives every store's API key + customer PII | Both | 🔴 **Owner action** | Register `vocify-ai.com` before any plugin zip ships (or change the shipped default to an owned hostname). Code-side mitigation landed 2026-09-18 (§6): https + valid host enforced, response bodies no longer executable in either back office. |
| `GET /api/webhooks/ecommerce` health behavior unverified (no reachable host) | Both | 🚧 | Verify once platform dev server or production domain is reachable |
| ~~Repo on `main`, `vocify-v2` branch doesn't exist — claude.md hard gate~~ | Both | ✅ Resolved | Owner decided 2026-08-16: trunk is `main`; commit there |

---

## Next Steps

### Immediate
1. ✅ WooCommerce + PrestaShop v1.1.0 contract sync (2026-08-14)
2. ✅ PHPUnit suites green (WC 27/27, PS 31/31 via Docker composer)
3. ✅ Docs + CHANGELOGs updated
4. ✅ Committed on `main` — `a8e8c23` (branch question resolved 2026-08-16: trunk is `main`)
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
