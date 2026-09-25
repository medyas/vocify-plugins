# Plugins repo documentation review — 2026-09-25

Scope: `C:\projects\vocify\plugins` (git repo, branch `main`, HEAD `9996268`, working tree clean before this work except the untracked `plugins.zip`). This change touches documentation only. The one non-doc file added is a root `.gitignore` for `/*.zip`.

## 1. Filename casing

- `git ls-files` tracks **`progress.md`** and **`claude.md`** in lowercase, one entry each. On this Windows checkout they show on disk as `PROGRESS.md`/`CLAUDE.md` (`core.ignorecase=true`).
- Per the brief ("keep ONLY the name git tracks"), **no `git mv`** was done. Both files keep their tracked lowercase names, and the commit shows `M progress.md` / `M claude.md`.
- ⚠️ **Mismatches for the lead to decide on:**
  - The workspace root `CLAUDE.md` lists `plugins/PROGRESS.md`. On Linux/GitHub that path does not exist.
  - `test/e2e/README.md` links `../../PROGRESS.md`.
  - Code comments cite `PROGRESS.md §7/§8/§9/§10` (in `VocifyPayloadBuilder.php`, `VocifyStatusReceiver.php`, `webhook.php`, `vocifyai.php`, `class-vocify-payload-builder.php`, `class-vocify-status-receiver.php`, and the e2e suites).
  - Claude Code loads `CLAUDE.md` by exact name on case-sensitive filesystems, so on a Linux clone the lowercase `claude.md` would not be picked up automatically.
- Recommendation: `git mv claude.md CLAUDE.md` and `git mv progress.md PROGRESS.md` (two-step via a temp name on Windows) to match the sibling repos. That is a one-commit change and was **not** done here, because it is the lead's call.

## 2. Archive (verbatim)

| Source | Archive | sha256 (working-tree bytes, CRLF) |
|---|---|---|
| `progress.md` | `docs/history/PROGRESS-archive-2026-09-25.md` | `e5e4c948f72c688e7c21c2373be5608760f251cef2dbf5847bc884ab8c8db76d`, identical |
| `claude.md` | `docs/history/CLAUDE-archive-2026-09-25.md` | `4c50e4174631ade6d97387aa749acbc48bc6e810ad3b1d1bfda90ad784643b57`, identical |

The copies were made with `cp`. `git show HEAD:progress.md` hashes differently (`ccafbbf1…`) only because of `core.autocrlf=true`: the repo stores LF. Re-adding CR to the HEAD blob reproduces `e5e4c948…` exactly. After staging, the archive blobs were checked to equal the HEAD blobs of the originals (see §7).

## 3. "⏳ commit pending" claims: all stale

Everything the old file marked "⏳ commit pending / deliberately not committed" **is committed, and `origin/main` == `main`** (per the local tracking ref; not re-fetched). Attribution was done with `git log -S`, not by trusting commit messages:

| Old section | Marker string | First commit |
|---|---|---|
| §6 pentest fixes | `is_allowed_webhook_url`, `isAllowedWebhookUrl`, `CURLPROTO_HTTPS` | `ea012ea` |
| §7 HMAC timestamp binding | `buildSignedMessage` | `ea012ea` |
| §8 bugs 8.1–8.4 | `on_plugins_loaded`, `PLATFORM_DATE_FORMAT`, `orderStatus']` | `ea012ea` |
| §8 harness | `test/e2e/*` | `f7d4f42` |
| §9 WC receiver | `class-vocify-status-receiver.php` | `ea012ea` |
| §10 PS receiver, `displayAdminOrderSide`, re-entrancy guard | `displayAdminOrderSide`, `suppressOutboundWebhooks` | `6b7c738` |
| §11 internet leg | `ps-internet` | `d484b38` |
| duplicate "§9" VPS move | — | `9996268` |

The old header ("§11 is pending") and footer ("status as of 2026-08-14 … commit pending branch decision") were also stale.

Commit-message inaccuracies noted, with no action taken:
- `ea012ea` says "PayloadBuilder: phone field → customerPhone". The code still emits `customer.phone`.
- `ea012ea` says "288 signer + payload-builder assertions". That does not match any count recorded in the progress file.
- `f7d4f42` says "Return-path test suite (33 assertions)".

## 4. Code facts verified for the new `claude.md`

- **Layout.** The old "src/models/services/utils/config" tree was fictional and has been replaced with the real one: WC `includes/` (7 classes incl. the status receiver); PS `classes/`, `controllers/front/{cron,webhook}.php`, `upgrade/upgrade-1.2.0.php`, `views/templates/admin/*.tpl`.
- **Versions.** WC `1.1.0` (header + `VOCIFY_AI_VERSION`); PS `1.2.0` (`$this->version`, `composer.json`). The WC CHANGELOG has no entry for the 2026-09 work.
- **HMAC outbound.** `hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret)`, with one `gmdate('c')` shared by the header and the signature. `X-Signature` is omitted when no secret is set, and the platform fails closed.
- **Payload dates.** `PLATFORM_DATE_FORMAT = 'Y-m-d\TH:i:s\Z'` in both builders (X-Timestamp stays `gmdate('c')`).
- **Inbound.** WC `register_rest_route('vocify/v1', …/order-status)`; PS `fc=module&module=vocifyai&controller=webhook`. Headers `X-Vocify-Timestamp`/`X-Vocify-Signature`, `TIMESTAMP_TOLERANCE = 300`, `hash_equals`, `503 vocify_not_configured`. The WC `vocify_status_map` filter and PS `VOCIFY_STATE_*` / `PS_OS_*` defaults are documented.
- **Hooks.** WC `woocommerce_new_order` (10,2) and `woocommerce_order_status_changed` (10,4), with an hourly `vocify_retry_failed_webhooks`. PS `actionValidateOrder`, `actionOrderStatusPostUpdate`, `displayAdminOrderSide`, `displayAdminOrderLeft`.
- **Commands.** `composer test` / `composer lint` (scripts exist in both `composer.json`). There is no build script. Zip instructions exist only in `woocommerce/INSTALL.md`.
- **E2E.** `run.sh`/`run.cmd` (`--only wc|ps|rt`, `--keep-up`, `--keep-fixture`, `--skip-build`, `--slow-replay`) and `run-ps-return.sh` (`--keep-up`, `--reuse`, `--skip-internet`). Default `https://152-228-210-12.sslip.io` in `orchestrate.mjs:53`, `run-ps-return.mjs:45` and `contract-probe.mjs:101`. It borrows `platform/node_modules/tsx` and `platform/.env`, and it writes to the live DB with no-dial guards.
- **Test counts** by grep of `function test`: WC 10+11+10 = **31**, PS 13+11+11+33 = **68**. These match the last recorded runs. **PHPUnit and e2e were not re-run in this review** (docs-only task), and the new file says so.
- **Default webhook URL** `https://app.vocify-ai.com/...` is hard-coded in `class-vocify-admin.php` (4×), `vocify-ai-woocommerce.php:280` and `vocifyai.php:244`, on a domain the pentest found unregistered. It is flagged 🔴 in both docs. `staging.vocify-ai.com` was removed from the Quick Reference, because nothing backs it.

## 5. What changed in the docs

- **`claude.md`** was rewritten. It now has: the Progress Protocol in the same form as the sibling repos, plus a filename-case note and a "don't renumber §5–§12" rule; the real layout; commands, including the release-zip rule (`--no-dev`, slug root folder); the outbound contract with the two-date-format distinction; a new **inbound return-path contract** section (previously absent entirely); the e2e harness with its live-DB warning; known traps; and a short standards list. The generic boilerplate (SLA promises, admin-UI wish-lists, marketplace lists) was dropped. It is still in the archive.
- **`progress.md`** was rewritten from 60 KB to about 12 KB. It now has a header, protocol, legend, "Current status (verified 2026-09-25)", 12 open items, summarised §5–§12 with commit refs (section numbers kept stable for the code comments, and the duplicate "§9" VPS-move section renamed **§12**), a feature matrix, a short changelog, and a link to the archive.
- **`.gitignore`** (new, root): `/*.zip`.

## 6. `plugins.zip` (untracked, repo root)

10,287,199 bytes, 6050 entries, dated 2026-09-19 21:01. Roots are `prestashop/` and `woocommerce/`. **It should be gitignored, and now is** (`/*.zip`). It is not a usable release artifact:
- It contains dev `vendor/` for both plugins, including `phpunit/*` and `nikic/php-parser`. Per progress §8, a dev vendor makes `prestashop:module install` fail for **every** module on the shop.
- It contains `.phpunit.result.cache`.
- Its root folders are `woocommerce/`/`prestashop/`, not the slugs `vocify-ai-woocommerce/`/`vocifyai/` that the CMS uploaders expect.
- No `.env` entries (checked).

The file was **not deleted**. If a merchant or tester received this zip, it should be replaced with a `--no-dev` build.

## 7. Commit

A single docs commit on `main` (not pushed) containing: `claude.md`, `progress.md`, `.gitignore`, `docs/history/{PROGRESS,CLAUDE}-archive-2026-09-25.md`, and this report. Staging used explicit paths only. The archive blobs were verified to equal `HEAD:progress.md` / `HEAD:claude.md` (`git rev-parse HEAD:progress.md` vs `git hash-object` of the staged archive).

## 8. Cross-repo notes for the lead

- The "Not deployed" line at `platform/PROGRESS.md:1750` that the old §11 called stale is no longer present in `platform/PROGRESS.md` (grep 2026-09-25), so nothing is left to flag.
- Store-side platform URLs pointed at the old VPS: PrestaShop never follows redirects (`CURLOPT_FOLLOWLOCATION=false`), so a PS shop still on the old host **fails immediately** despite the 308. WooCommerce follows redirects (WP default), so it keeps working until the 7-day window closes. Tracked as O3.
- `progress.md` §8 keeps a short "Observations" subsection, because `prestashop/vocifyai.php:57` and `test/e2e/suites/ps-return.mjs:272` cite it.
