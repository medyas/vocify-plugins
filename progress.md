# Vocify CMS Plugins - Development Progress

**Last Updated**: 2026-09-19
**Version**: WooCommerce 1.1.0 / **PrestaShop 1.2.0** — the 2026-09-18 pentest fixes (§6), the 2026-09-19 HMAC timestamp-binding fix (§7), the four e2e-found bugs of §8, the WooCommerce receiver (§9), the PrestaShop receiver (§10) and its public-internet proof (§11) are all in the working tree; §6-§10 are committed at `6b7c738`, §11 is pending
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

## 9. E2E target moved to the new VPS (2026-09-25) ✅
- The test platform moved `162.19.32.251` → `152.228.210.12`. `test/e2e` defaults (`orchestrate.mjs`, `run-ps-return.mjs`,
  `fixtures/contract-probe.mjs`, `suites/ps-internet.mjs`, `README.md`) now target `https://152-228-210-12.sslip.io`.
  `REPORT*.md` keep the old URL on purpose — they record runs made against the old box. The old URL 308-redirects
  during the 7-day rollback window, but the store plugins' own configured platform URL must be updated by hand.

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

### A fourth fix: the panel was invisible on PrestaShop 8

This module writes no `orders.note` and no `CustomerMessage`, so its own order panel is the ONLY
place a merchant ever sees a call result. That panel was hooked exclusively on
`displayAdminOrderLeft` — which PrestaShop put in `Hook::$deprecated_hooks` ("from 1.7.7.0") and
8.x **dispatches nowhere**: `grep -rl displayAdminOrderLeft` over a whole 8.2.8 install matches only
`classes/Hook.php`'s deprecation list. The panel has therefore been invisible on every 8.x shop
since it was written in 1.0.0, unnoticed while it only showed webhook logs nobody read.

Fixed by also registering **`displayAdminOrderSide`**, the live hook the order page really renders
(`src/PrestaShopBundle/Resources/views/Admin/Sell/Order/Order/view.html.twig:63`, same
`{'id_order': …}` params). `displayAdminOrderLeft` stays registered — it is the live hook on
1.7.0–1.7.6, which this module still claims to support. Both are asserted in the suite, and the
upgrade script registers the new one so an upgraded shop is not left with a working return leg
nobody can see.

Two smaller hardening changes in the same pass: `orderId` is `ctype_digit`-checked before the cast
to int (`(int)"12abc"` is 12, which would have applied a result to a real order instead of 404-ing),
and the controller docblock now states that `VOCIFY_ENABLED` is **deliberately not** checked — that
toggle governs the outbound direction, and a result arriving back is the outcome of a call already
placed.

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

## 9. Return path: platform → shop (2026-09-19) ✅ WooCommerce GREEN — PrestaShop followed in §10

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

1. ✅ **DONE 2026-09-19 (§10)** — the PrestaShop `webhook` front controller exists and is verified
   end to end; `buildModuleWebhookUrl()`'s URL was already correct and its comment now says so.
2. 🟠 Deploy the platform fixes — until then production still pushes nothing (the BLOCKED row above).
3. 🟡 Bump the WooCommerce plugin to **1.2.0** with a CHANGELOG entry: the receiver is a new feature,
   and merchants must re-check that their signing secret is filled in, since it now authenticates
   both directions.
4. 🟡 No PHPUnit test was added for the receiver. Its four rejection branches are asserted by the
   e2e suite against real WordPress, which is stronger than a stub — but a unit test would catch a
   contract drift without Docker.
5. 🟡 `calls` rows already at `sync_attempts = 5` are permanently `failed` and will never retry once
   the fixes deploy. If those orders matter, they need a one-off reset.


**§9 item 1 is now done — see §10.**

---

## 10. PrestaShop return path: the receiver (2026-09-19) ✅ code + e2e verified — ⏳ commit pending

**Closes §9 "Still open" item 1.** §9 shipped WooCommerce's receiver and left PrestaShop with none:
`controllers/front/` held only `cron.php`, so the platform's POST hit PrestaShop's 404 page and a
PrestaShop merchant's orders never moved, whatever the customer said on the phone. This section
brings PrestaShop to parity with the WooCommerce half, on the same contract.

**Module version bumped 1.1.0 → 1.2.0** (`vocifyai.php`, `composer.json`), because the new table
needs an upgrade path and an upgrade file only runs when the code version exceeds the installed one.
WooCommerce's matching bump (§9 item 3) is still open and is the lead's call.

### Files

| File | Change |
|---|---|
| `prestashop/classes/VocifyStatusReceiver.php` | **NEW.** All the decision logic: HMAC verification over `"{X-Vocify-Timestamp}.{rawBody}"` with `hash_equals`, a 300 s two-sided freshness window, fail-closed on an unconfigured secret, payload parsing, idempotency/ordering verdicts, the outcome → state mapping, and the merchant-visible note. **Loads without PrestaShop**, which is what makes the branch coverage below possible — `tests/bootstrap.php` deliberately does not bootstrap the CMS, so a `ModuleFrontController` subclass cannot be unit-tested at all. |
| `prestashop/controllers/front/webhook.php` | **NEW.** Thin adapter: read the raw body and headers, read `Configuration`, call the receiver, load the `Order`, apply with `OrderHistory::changeIdOrderState()` + `add()`, emit JSON. Answers `POST {store}/index.php?fc=module&module=vocifyai&controller=webhook`. |
| `prestashop/upgrade/upgrade-1.2.0.php` | **NEW.** Creates `vocify_call_results` and seeds the state mapping on shops that installed 1.1.0. Without it an upgraded merchant gets a receiver whose table does not exist: every push 500s, the platform burns its five retries, the order never moves. |
| `prestashop/vocifyai.php` | Version 1.2.0; requires the receiver; creates/drops `vocify_call_results`; seeds + deletes the state mapping; the double-emission guard and the re-entrancy guard; three `select` settings for the mapping plus a display-only Call Result URL; **registers `displayAdminOrderSide`** (see the third fix below) and renders the panel from either hook; assigns call results to it. |
| `prestashop/views/templates/admin/order_info.tpl` | Renders the call results on the order page — outcome, timestamp, note; escaped, `pre-wrap`, never as markup. |
| `prestashop/tests/VocifyStatusReceiverTest.php` | **NEW**, 33 tests. |
| `prestashop/tests/bootstrap.php` | Loads the new class. |
| `prestashop/{README,INSTALL,CHANGELOG}.md` | The return leg, the new settings, the new table, and the canonical-domain trap. |
| **`platform/src/lib/adapters/outbound/prestashop.adapter.ts`** | ⚠️ **CROSS-REPO.** `buildModuleWebhookUrl()`'s URL was already right; its `⚠️ UNVERIFIED` comment is now `✅ VERIFIED` with the measurement, plus the canonical-domain warning. **No behaviour change**; `tsc --noEmit` exit 0. |
| `test/e2e/docker-compose.ps-return.yml`, `suites/ps-return.mjs`, `run-ps-return.{sh,mjs}`, `ps/post-result.php`, `README.md`, `.gitignore` | The return-path harness — see below. |

### The two decisions the owner delegated

**1. Double webhook emission — real, and fixed.** `PaymentModule::validateOrder()` fires
`actionValidateOrder` (`classes/PaymentModule.php:560`) and applies the order state ~20 lines later
via `OrderHistory::changeIdOrderState()`, firing `actionOrderStatusPostUpdate`; the module forwarded
both, so one new order produced two identical deliveries (§8 recorded this and deliberately left it).
Fixed with a per-request `array<orderId, state>` static: the post-update is skipped when it carries
the state already forwarded. **Keyed on the state, not merely the order** — a payment module may
legitimately call `validateOrder()` and then move the order again in the same request, and a blanket
"already sent this order" flag would silently swallow that. Measured: 1 log row for a new order
(was 2), and a *control* assertion proves a genuinely different transition still emits, so the fix
cannot pass by having killed the hook.

**2. Localised status names — never matched on.** PrestaShop order states live in `order_state_lang`,
so names are per-language and renameable. The mapping is three `Configuration` keys
(`VOCIFY_STATE_CONFIRMED` / `_CANCELLED` / `_COMPLETED`) holding numeric `id_order_state` values,
chosen by the merchant from a `select` of their own statuses, defaulting to PrestaShop's own
pointers (`PS_OS_PREPARATION` / `PS_OS_CANCELED` / `PS_OS_DELIVERED` — themselves ids). A configured
state is validated with `Validate::isLoadedObject()` before use and falls back to the PrestaShop
pointer if the merchant deleted it; if neither resolves, the result is recorded with a `warning`
rather than a 500. Measured three ways: renaming the target state in `order_state_lang` does not
break it, repointing the config moves the order somewhere else, and a deleted state falls back.

### A third fix, not asked for but necessary

**Re-entrancy.** `changeIdOrderState()` fires `actionOrderStatusPostUpdate`, which this module
hooks — so applying an inbound result pushed the order straight back OUT to the platform that had
just sent it, with the 3×-backoff retry loop behind it, for every call result received. Guarded with
`VocifyAI::$suppressOutboundWebhooks` around the state change. Asserted: the webhook-log count is
unchanged by the status change the receiver makes.

### Verification

| Check | Result |
|---|---|
| `php -l` | ✅ 11/11 clean (php 8.2-cli in Docker), including the two new files and the upgrade script |
| PHPUnit | ✅ **68 tests / 173 assertions**, up from 35/79. The 35 pre-existing tests still pass; the 33 new ones cover every rejection branch (unconfigured secret → 503, missing signature/timestamp, unparsable and stale and future timestamps, wrong secret, body tampered after signing, body-only signature, signature bound to a different timestamp, non-JSON body, missing orderId/status), idempotency, the ordering guard, and the mapping |
| **e2e, cold run against PrestaShop 8.2.8** | ✅ **48 passed, 0 failed, 0 skipped, 0 blocked** — `run-ps-return.sh`, from an empty database, two legs (§11). 35 shop-side assertions through a real `PaymentModule::validateOrder()` order, real HTTP, Apache and PrestaShop's real dispatcher; 13 internet-leg assertions driven by the **deployed platform on the OVH VPS**. Headline: `ps_orders.current_state` 2 → 3, read straight from MySQL |
| Upgrade path 1.1.0 → 1.2.0 | ✅ Simulated on the live container (drop the table, delete the config, **unregister `displayAdminOrderSide`**, pin `module.version` to 1.1.0): `needsUpgrade=true`, `runUpgradeModule()` `success=true upgraded_to=1.2.0`, table recreated, mapping seeded 3/6/5, hook registered, version bumped — and a push right afterwards returned `200 {"changed":true}` with the panel rendering 5417 bytes |
| Platform `tsc --noEmit` after the adapter comment edit | ✅ exit 0 |

**The internet leg is now proven too — see §11.** When this section was first written it was not,
and the paragraph here said so; that gap is closed.

### Traps found along the way (all in the harness, none in the module)

- **PrestaShop 302s a request whose `Host` is not the canonical shop domain**, before the module
  controller runs. The platform fetches with `redirect: 'error'`, so that is a hard delivery
  failure. Documented in the adapter, the README and INSTALL.md — merchants must register their
  canonical domain.
- **A root-run CLI poisons `var/cache/prod/` for Apache** → every front-office request 500s with
  `Cannot rename "/tmp/FrontContainer.php…"`. Every container-side helper now runs as `www-data`.
- **A bootstrap-only readiness probe passes mid-install**, so provisioning's `rm -rf install_e2e`
  deleted the installer's own fixture directory: empty catalogue, container exit 1. The driver now
  waits for the entrypoint's log marker, then a bootstrap, then one active product.
- **`OrderHistory` across the `shipped` boundary needs an employee.** `set-status.php` boots the
  Symfony kernel, so the stock-movement insert runs and dies on `Column 'id_employee' cannot be
  null`. **The receiver is unaffected** — from a front controller `SymfonyContainer::getInstance()`
  is null and `StockManager::saveMovement()` returns early. Measured separately: a `completed`
  outcome mapped to `PS_OS_DELIVERED` moved an order 2 → 5, `200 {"changed":true}`.

### Still open

1. ✅ **DONE (§11)** — the tunnel + `ps_shop_url` repoint exists, and the deployed platform has
   been measured pushing a call result into a PrestaShop shop across the public internet.
2. 🟡 `suites/ps-return.mjs` is driven by its own runner, not `orchestrate.mjs`. Folding it in as a
   fourth `--only` target is a small change to a shared file and was left to its owner.
3. 🟡 The WooCommerce receiver still has no PHPUnit coverage (§9 item 4). The PrestaShop split —
   decision logic in a CMS-free class, controller as a thin adapter — is the pattern that would make
   it possible there too.
4. ⏳ Commit + push on `main` — deliberately **not** committed; the lead reviews the working tree.

The e2e stack was torn down (`down -v`); no `vocify-e2e-*` container is left running.

---

## 11. PrestaShop return path over the PUBLIC INTERNET (2026-09-19) ✅ measured — ⏳ commit pending

**Closes §10 "Still open" item 1.** §10 proved the shop half of the wire by posting from inside the
container. This proves the other half: the **deployed platform on the OVH VPS** reaching a
PrestaShop shop across the public internet and being accepted — the same shape as the WooCommerce
suite's "the DEPLOYED platform can push this call to the shop" + "THE HEADLINE" pair.

**Result: 48 passed, 0 failed, 0 skipped, 0 blocked** on a cold run from an empty database
(35 shop-side, 13 internet-leg). Nothing in the module changed; this is harness work plus one
measured correction to §10's documentation.

### What the internet leg actually exercises

```
an order placed in a REAL shop (PaymentModule::validateOrder())
  → the module's outbound hook, signed by the shipped PHP
  → the DEPLOYED platform at https://162-19-32-251.sslip.io   → orders + attempts rows
  → a synthesised completed Call                              → NO phone dialled
  → POST /api/internal/sync-ecommerce on the DEPLOYED platform
  → the platform's PrestaShop outbound adapter (HMAC + HTTPS)
  → Cloudflare quick tunnel                                   ← THE PUBLIC INTERNET
  → VocifyAIWebhookModuleFrontController
  → ps_orders.current_state 2 → 3
```

Measured evidence from the cold run:

```
deployed cron tick           HTTP 200 {"synced":1,"failed":0}
shop access log              172.18.0.4 - D2C5CE0C6C8DD51DC9B9A9768228354D
                             "POST /index.php?fc=module&module=vocifyai&controller=webhook" 200
calls row                    {"outcome":"confirmed","sync_status":"synced","synced_at":"...T17:46:55.118Z"}
ps_orders.current_state      2 → 3
```

`172.18.0.4` is the cloudflared container — everything the shop-side suite does logs `127.0.0.1`, so
a non-loopback source is proof the request came in from outside. `D2C5CE0C…` is stronger still: it is
the Basic-auth **username**, which only the platform's PrestaShop adapter sends
(`getBasicAuthHeader()` → `credentials.apiKey`). The harness never sends that header, so the line
identifies the request as the platform adapter's rather than merely as "something external". Both
are assertions, not observations.

### ⚠️ The deployed VPS build is NEWER than platform/PROGRESS.md:1750 says

That line reads *"Not deployed. The VPS runs the previous build, so production still pushes
nothing."* **Stale as of 2026-09-19.** The internet leg passed on its FIRST attempt with
**production-shaped credentials** — `integrations.credentials` holding only `{apiKey}`, with the URL
left in the `store_url` column exactly as the integration wizard writes it. That only works if the
deployed build has `adapterCredentials()` from platform commit `4b51231`; without it
`assertSafeExternalUrl(undefined)` throws in the adapter constructor and nothing leaves the Worker.
The suite carries a fallback that supplies `storeUrl` inside `credentials` to isolate a stale build
from a PrestaShop problem — it was never needed.

### What the repoint actually requires — measured, and smaller than assumed

PrestaShop cannot be installed against the tunnel hostname the way WordPress is: `PS_INSTALL_AUTO`
runs from the container entrypoint, before cloudflared has a hostname. So the shop is installed
against a placeholder and moved afterwards. The brief listed `ps_shop_url` (`domain`, `domain_ssl`,
`physical_uri`), `PS_SHOP_DOMAIN` / `PS_SHOP_DOMAIN_SSL`, and a cache clear. **Three of those five
turned out to be unnecessary.**

| Change | Required? | Evidence |
|---|---|---|
| `ps_shop_url.domain` / `domain_ssl` on the **`main`** row | ✅ **YES — and alone it is enough** | Before: `POST` to the tunnel → `302 Location: https://ps-rt.vocify.test/?fc=module…`, before any controller runs; the adapter fetches with `redirect: 'error'`, so that is a hard delivery failure. After this UPDATE **with `--skip-config --skip-cache`**: the module front controller ran and answered its own JSON. `Shop::initialize()` → `findShopByHost()` is `WHERE su.domain = ? OR su.domain_ssl = ?` (classes/shop/Shop.php:1359) |
| `PS_SHOP_DOMAIN` / `PS_SHOP_DOMAIN_SSL` | ❌ **NO** | Assumed necessary for the outbound `X-Domain` and **measured wrong**: with both left at the stale placeholder, an order still reached the platform with **HTTP 201**. `Tools::getShopDomainSsl()` does not read `ps_configuration` — it calls `ShopUrl::getMainShopDomainSSL()` (Tools.php:395), which is `SELECT domain, domain_ssl FROM ps_shop_url WHERE main = 1` (ShopUrl.php:178). Both legs read the same table. Written anyway as hygiene |
| `physical_uri` | ❌ NO | Already `/`, which `findShopByHost()`'s prefix match always satisfies |
| Cache clear | ❌ NO | The whole repoint was measured working with `--skip-cache`. `ShopUrl`'s main-domain memo is a per-request static, not a file cache. Kept anyway — cheap, and a raw SQL write to `ps_configuration` WOULD need it |
| `PS_SSL_ENABLED` | ❌ left at 0, deliberately | The tunnel terminates TLS and forwards http, so Apache sees http while the world sees https. `sslRedirection()` exempts POST unconditionally (FrontController.php:858) and `getShopDomainSsl(true)` returns `https://` regardless, so turning it on only adds a way for a GET to bounce |

⚠️ **UPDATE the existing `main` row; never INSERT a second one.** A non-`main` row matches
`findShopByHost()` and then trips the `!$is_main_uri` branch at Shop.php:379, producing a 302 that
looks exactly like the one being removed.

### Files

| File | Change |
|---|---|
| `test/e2e/suites/ps-internet.mjs` | **NEW.** The internet leg, 13 assertions. |
| `test/e2e/ps/repoint-shop.php` | **NEW.** Moves an installed shop onto the tunnel hostname; carries the measured table above. |
| `test/e2e/docker-compose.ps-return.yml` | Adds the `cloudflared` quick tunnel (`vocify-e2e-psrt-tunnel`). |
| `test/e2e/run-ps-return.mjs` | Scrapes the tunnel hostname, repoints, runs BOTH legs, provisions + tears down the platform fixture, asserts the safety invariant three times. |
| `test/e2e/suites/ps-return.mjs` | Per-run `callSid` prefix — see the isolation bug below. |
| `test/e2e/README.md` | The internet leg and the repoint. |

### A test-isolation bug found and fixed in §10's own suite

`vocify_call_results.call_sid` is UNIQUE and **global, not per-order** — that index is what makes a
replayed push a no-op. §10's suite used fixed sids (`rt-confirmed`, `rt-cancelled`, …), so a second
run against the same shop answered every push *"Already applied (duplicate call result)"* and **15
assertions failed** for a reason with nothing to do with the module. A cold run never sees it, which
is exactly why it survived. Every sid is now prefixed with a per-run token.

### Safety

No phone was dialled and nothing dialable was created. The fixture agent's calling window is
computed closed; the order's `scheduled_at` was read back at **1.2 h out** and the run aborts under
an hour; the attempt is driven terminal BEFORE the `calls` row exists. Asserted three times — after
the write, at the end of the suite, and after teardown — all
`SELECT count(*) FROM attempts WHERE status IN ('pending','scheduled') AND scheduled_at <= now()` =
**0**, re-verified independently afterwards. The fixture was fully removed
(`_companies_remaining: 0`), no `e2e-psrt-*` / `e2e-psdom-*` company remains, and every container was
torn down. `safe-url.ts` and every signature/freshness check are untouched — the tunnel is what
satisfies `assertSafeExternalUrl()`, not a relaxed guard.

### Still open

1. 🟡 `suites/ps-return.mjs` + `suites/ps-internet.mjs` run from their own driver, not
   `orchestrate.mjs`. Folding them in as a fourth `--only` target is a small change to a shared file
   and was left to its owner.
2. 🟡 platform/PROGRESS.md:1750's "Not deployed" note is stale and should be corrected by whoever
   owns that file — the deploy has happened, measured above.
3. ⏳ Commit + push on `main` — deliberately **not** committed; the lead reviews the working tree.

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
