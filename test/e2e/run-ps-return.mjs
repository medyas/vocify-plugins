// ─────────────────────────────────────────────────────────────────────────────
// Driver for the two PrestaShop return-path suites.
//
//   ./run-ps-return.sh                 both legs, cold start to report
//   ./run-ps-return.sh --keep-up       leave the containers running
//   ./run-ps-return.sh --reuse         skip up/provisioning, run what is up
//   ./run-ps-return.sh --skip-internet shop-side suite only (no platform needed)
//
// Two legs against ONE shop:
//
//   1. `suites/ps-return.mjs` — the shop half of the wire. Posts from inside
//      the container. Needs no platform, no Postgres, no VPS, and is still
//      runnable with all of those down.
//   2. `suites/ps-internet.mjs` — the DEPLOYED platform on the OVH VPS pushing
//      a completed call across the public internet into that same shop. Needs
//      platform/.env for Postgres and a reachable VPS; records BLOCKED rather
//      than throwing when either is missing.
//
// The shop is CONFIGURED DIFFERENTLY for each, in that order: leg 1 points the
// outbound webhook at an unresolvable sink so it makes no live rows, then leg 2
// reconfigures it with the real fixture's credentials and the deployed
// platform's URL. That is what keeps leg 1 offline-runnable while leg 2 is a
// genuine round trip.
//
// Deliberately NOT part of `orchestrate.mjs`: that file's preamble (contract
// probe, tenant fixture, dialable-attempt gate) is built around suites that
// write to the platform's live Postgres, and leg 1 writes nothing there. It is
// also a shared file other agents are editing. Folding these in as a fourth
// `--only` target is a reasonable next step.
// ─────────────────────────────────────────────────────────────────────────────

import crypto from 'node:crypto';
import { resolve } from 'node:path';
import { compose, run, waitFor, E2E_DIR } from './lib/docker.mjs';
import { loadRecorder, writePluginReport } from './lib/report.mjs';
import { runPrestaShopReturnSuite } from './suites/ps-return.mjs';
import { runPrestaShopInternetSuite, assertNothingDialable } from './suites/ps-internet.mjs';

const COMPOSE = 'docker-compose.ps-return.yml';
const SHOP = 'prestashop';
const TUNNEL_CONTAINER = 'vocify-e2e-psrt-tunnel';

// The DEPLOYED platform — a real public hostname with real Let's Encrypt TLS on
// the OVH box. Same default as orchestrate.mjs.
const BASE_URL = process.env.TEST_BASE_URL || 'https://162-19-32-251.sslip.io';
const WEBHOOK_URL = `${BASE_URL}/api/webhooks/ecommerce`;

// Leg-1 credentials. Dev-only literals on purpose: this stack is throwaway, has
// no published port, and leg 1 never talks to anything real.
const LOCAL_API_KEY = 'vcf_test_psreturn0000000000';
const LOCAL_SECRET = 'whsec_ps_return_leg_test';
// Unresolvable, but a PUBLIC https host — which is what
// `VocifyWebhookService::isAllowedWebhookUrl()` requires. That guard is never
// relaxed here; `.invalid` simply never resolves, so the send fails fast at DNS
// and no live row is created by leg 1.
const SINK = 'https://vocify-e2e-sink.invalid/api/webhooks/ecommerce';

const argv = process.argv.slice(2);
const has = (flag) => argv.includes(flag);
const log = (s) => process.stdout.write(`${s}\n`);

const execAs = (user, argvv, opts = {}) =>
  compose(COMPOSE, ['exec', '-T', '-u', user, SHOP, ...argvv], opts);

async function up() {
  log('› bringing up the PrestaShop return-path stack (shop + tunnel)');
  const res = await compose(COMPOSE, ['up', '-d'], { echo: true });
  if (res.code !== 0) throw new Error('compose up failed');
}

async function waitForShop() {
  log('› waiting for the PrestaShop auto-install');

  // Stage 1 — the entrypoint's own completion marker, read out of the container
  // log. Apache is started only after PS_INSTALL_AUTO finishes, so this line is
  // strictly after the install; reading a log is cheap, cannot hang, and cannot
  // be true too early. Same sequence as orchestrate.mjs, for the same reasons.
  //
  // ⚠️ A bootstrap probe alone is NOT enough, and the first version of this
  // driver proved it the expensive way: `require config.inc.php` succeeds
  // mid-install, provisioning then ran while the installer was still going and
  // its `rm -rf install_e2e` deleted the installer's own fixture directory. The
  // catalogue came out empty (`no active product in the catalogue`), the
  // entrypoint's own cleanup then failed, and the container exited 1.
  await waitFor(
    'the PrestaShop entrypoint to finish installing and start Apache',
    async () => {
      const r = await compose(COMPOSE, ['logs', SHOP], { timeoutMs: 30_000 });
      return /resuming normal operations/.test(`${r.stdout}${r.stderr}`);
    },
    { timeoutMs: 420_000, intervalMs: 5000 }
  );

  // Stage 2 — Apache up is still not the app being usable.
  await waitFor(
    'PrestaShop to bootstrap',
    async () => {
      const r = await execAs('www-data', [
        'php', '-r', 'require "/var/www/html/config/config.inc.php"; echo "PS-READY " . _PS_VERSION_;',
      ], { timeoutMs: 30_000 });
      return r.code === 0 && r.stdout.includes('PS-READY');
    },
    { timeoutMs: 180_000, intervalMs: 5000 }
  );

  // Stage 3 — the catalogue. `create-order.php` needs one active product, and
  // an empty catalogue is exactly the symptom a too-early stage 1 produces, so
  // assert it here where the message is unambiguous rather than three minutes
  // later inside a suite.
  await waitFor(
    'at least one active product in the catalogue',
    async () => {
      const r = await execAs('www-data', [
        'php', '-r',
        'require "/var/www/html/config/config.inc.php"; echo "PRODUCTS:" . (int)Db::getInstance()->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "product WHERE active = 1");',
      ], { timeoutMs: 30_000 });
      const m = r.stdout.match(/PRODUCTS:(\d+)/);
      return Boolean(m && Number(m[1]) > 0);
    },
    { timeoutMs: 120_000, intervalMs: 5000 }
  );
}

/** The public https origin the quick tunnel hands out, scraped from its log. */
async function tunnelUrl() {
  log('› waiting for the quick tunnel to publish a hostname');
  const url = await waitFor(
    'cloudflared to print its public URL',
    async () => {
      const logs = await run('docker', ['logs', TUNNEL_CONTAINER]);
      const m = `${logs.stdout}\n${logs.stderr}`.match(/https:\/\/[a-z0-9-]+\.trycloudflare\.com/);
      return m ? m[0] : null;
    },
    { timeoutMs: 180_000, intervalMs: 2000 }
  );
  log(`  shop will be public at ${url}`);
  return url;
}

/**
 * Move an installed PrestaShop onto the tunnel hostname.
 *
 * The heavy documentation of WHICH rows this needs, and which of them were
 * measured to be unnecessary, lives in `ps/repoint-shop.php`. The short
 * version, measured on this stack:
 *
 *   * `ps_shop_url.domain` / `domain_ssl` on the `main` row is NECESSARY AND
 *     SUFFICIENT for the shop to answer the tunnel hostname at all. With the
 *     row stale, a POST to the tunnel answered `302 Location:
 *     https://ps-rt.vocify.test/?fc=module…` — the platform's adapter fetches
 *     with `redirect: 'error'`, so that is a hard delivery failure. With ONLY
 *     that row updated (`--skip-config --skip-cache`), the module front
 *     controller ran and answered its own JSON.
 *   * `PS_SHOP_DOMAIN` / `PS_SHOP_DOMAIN_SSL` in `ps_configuration` are NOT
 *     required by either leg — an assumption that measurement overturned.
 *     `Tools::getShopDomainSsl()`, which builds the outbound `X-Domain`, goes
 *     through `ShopUrl::getMainShopDomainSSL()` and reads `ps_shop_url` too,
 *     not `ps_configuration`. With them deliberately left stale, an order
 *     still reached the platform with HTTP 201. They are written anyway, as
 *     hygiene for a shop that has genuinely moved.
 *   * `physical_uri` needed no change, and NO cache step was required.
 */
async function repointShopAtTunnel(url) {
  const host = new URL(url).host;
  log(`› repointing the shop at ${host}`);
  const r = await execAs('www-data', ['php', '/e2e/repoint-shop.php', host]);
  if (r.code !== 0) throw new Error(`repoint failed: ${r.stderr.trim() || r.stdout.trim()}`);
  const line = r.stdout.split('\n').filter(Boolean).pop();
  const parsed = JSON.parse(line);
  log(`  changed: ${parsed.changed.join(', ')}`);
  return parsed;
}

/** Install the module and point it at a webhook URL with a given credential pair. */
async function provisionShop({ apiKey, secret, webhookUrl }) {
  log('› installing and configuring the module');
  const res = await compose(
    COMPOSE,
    [
      'exec', '-T',
      '-e', `VOCIFY_API_KEY=${apiKey}`,
      '-e', `VOCIFY_SIGNATURE_SECRET=${secret}`,
      '-e', `VOCIFY_WEBHOOK_URL=${webhookUrl}`,
      SHOP, 'bash', '/e2e/ps-provision.sh',
    ],
    { echo: true }
  );
  if (res.code !== 0) throw new Error('provisioning failed');

  // ⚠️ ps-provision.sh runs as root and boots PrestaShop, which creates
  // `var/cache/prod` owned by root. Apache runs as www-data and then cannot
  // write the compiled Symfony container into it: EVERY front-office request
  // dies with `Cannot rename "/tmp/FrontContainer.php…"` → HTTP 500, with
  // nothing wrong in the module. Measured on the first run of this driver.
  //
  // `rm -rf .../cache/prod .../cache/dev` and not a `*` glob joined with `&&`:
  // when the cache happens to be empty the glob does not expand, `rm` fails on
  // the literal path, and the chown that actually matters never runs.
  log('› resetting var/ ownership after the root-run provisioning');
  const fix = await compose(COMPOSE, [
    'exec', '-T', SHOP, 'sh', '-c',
    'rm -rf /var/www/html/var/cache/prod /var/www/html/var/cache/dev; chown -R www-data:www-data /var/www/html/var',
  ]);
  if (fix.code !== 0) {
    throw new Error(`could not reset var/ ownership: ${fix.stderr.trim() || fix.stdout.trim()}`);
  }
}

/** Rewrite the module's settings in place, without reinstalling it. */
async function reconfigureShop({ apiKey, secret, webhookUrl }) {
  for (const [k, v] of [
    ['VOCIFY_API_KEY', apiKey],
    ['VOCIFY_SIGNATURE_SECRET', secret],
    ['VOCIFY_WEBHOOK_URL', webhookUrl],
  ]) {
    const r = await execAs('www-data', ['php', '/e2e/set-config.php', k, v]);
    if (r.code !== 0) throw new Error(`could not set ${k}: ${r.stderr.trim()}`);
  }
}

/**
 * The shop must answer over HTTP before the first assertion.
 *
 * The first front-office request after a cache wipe compiles the Symfony
 * container, and until it has, requests fail at the transport level. A `HTTP 0`
 * from curl is not a receiver verdict, but assertions read it as one — the
 * first run of this driver recorded two spurious FAILs that way.
 */
async function warmUp() {
  log('› warming the front office');
  await waitFor(
    'the shop to answer an HTTP request',
    async () => {
      const spec = Buffer.from(JSON.stringify({ body: {}, omitSignature: true }), 'utf8').toString('base64');
      const r = await execAs('www-data', ['php', '/e2e/post-result.php', spec], { timeoutMs: 90_000 });
      const m = r.stdout.match(/\{"http":(\d+)/);
      return Boolean(m && Number(m[1]) > 0);
    },
    { timeoutMs: 180_000, intervalMs: 5000 }
  );
}

async function main() {
  const startedAt = new Date();
  const Recorder = await loadRecorder();
  const rec = new Recorder();
  const notes = [];
  let sql = null;
  let fixture = null;
  let storeUrl = null;
  let adapterApiKey = null;

  if (!has('--reuse')) {
    await up();
    await waitForShop();
  }
  storeUrl = await tunnelUrl();

  if (!has('--reuse')) {
    await provisionShop({ apiKey: LOCAL_API_KEY, secret: LOCAL_SECRET, webhookUrl: SINK });
  }
  await repointShopAtTunnel(storeUrl);
  await warmUp();

  const v = await execAs('www-data', [
    'php', '-r',
    'require "/var/www/html/config/config.inc.php"; $m = Module::getInstanceByName("vocifyai"); echo _PS_VERSION_ . "|" . ($m ? $m->version : "?");',
  ]);
  const [psVersion, moduleVersion] = (v.stdout.trim().split('\n').pop() ?? '?|?').split('|');
  log(`› PrestaShop ${psVersion}, vocifyai ${moduleVersion}`);

  try {
    // ── leg 1: the shop half of the wire, no platform involved ────────────
    await runPrestaShopReturnSuite({ rec, secret: LOCAL_SECRET, notes });

    // ── leg 2: the DEPLOYED platform, across the public internet ──────────
    if (has('--skip-internet')) {
      rec.group('internet leg — DEPLOYED platform → PrestaShop, over the public internet');
      rec.skip('the internet leg', '--skip-internet');
    } else {
      rec.group('internet leg — DEPLOYED platform → PrestaShop, over the public internet');
      try {
        const { connect } = await import('./lib/platform-db.mjs');
        sql = await connect();
        const { provision } = await import('./fixtures/provision-tenant.mjs');

        log('› provisioning the platform-side fixture');
        fixture = await provision(sql, {
          platform: 'PRESTASHOP',
          domain: new URL(storeUrl).host,
          tag: 'psrt',
        });
        // What the outbound adapter needs. `store_url` is the column the fixed
        // sync service reads; `credentials` holds only the platform secret,
        // which is the shape the integration wizard really writes — no
        // `storeUrl` inside it. Keeping that shape honest is what lets the
        // suite tell a stale VPS build apart from a PrestaShop problem.
        // Uppercase hex, the shape of a real PrestaShop WebService key — and
        // the thing that lets the suite prove WHO made the push: the adapter
        // sends it as the Basic-auth username, which Apache logs.
        adapterApiKey = crypto.randomBytes(16).toString('hex').toUpperCase();
        await sql.query(
          `UPDATE integrations SET store_url = $2, credentials = $3::jsonb, updated_at = now() WHERE id = $1`,
          [fixture.integrationId, storeUrl, JSON.stringify({ apiKey: adapterApiKey })]
        );
        log(`  company ${fixture.companyId}`);
        log(`  agent   ${fixture.agentId} — calling window ${fixture.callingWindow.start}-${fixture.callingWindow.end} ${fixture.callingWindow.timezone} (CLOSED now)`);

        await reconfigureShop({
          apiKey: fixture.apiKey,
          secret: fixture.signatureSecret,
          webhookUrl: WEBHOOK_URL,
        });

        await runPrestaShopInternetSuite({
          rec, sql, fixture, storeUrl, baseUrl: BASE_URL, notes, adapterApiKey,
        });
      } catch (err) {
        rec.blocked('the internet leg', `could not run: ${err.message}`);
        log(`  internet leg BLOCKED: ${err.message}`);
      }
    }
  } finally {
    // ── safety + fixture cleanup, whatever happened above ─────────────────
    if (sql) {
      try {
        await assertNothingDialable(rec, sql, 'at the end of the run');
      } catch (err) {
        rec.blocked('SAFETY: nothing dialable at the end of the run', err.message);
      }
      if (fixture) {
        try {
          const { teardown } = await import('./fixtures/provision-tenant.mjs');
          const counts = await teardown(sql, fixture.companyId);
          rec.record({
            name: 'the platform fixture is fully removed from live Supabase',
            outcome: counts._companies_remaining === 0 ? 'PASS' : 'FAIL',
            expected: '0 companies rows left for the fixture',
            actual: JSON.stringify(counts),
            severity: 'critical',
          });
          log(`› fixture torn down: ${JSON.stringify(counts)}`);
        } catch (err) {
          rec.record({
            name: 'the platform fixture is fully removed from live Supabase',
            outcome: 'FAIL',
            expected: 'teardown succeeds',
            actual: err.message,
            severity: 'critical',
          });
        }
      }
      // The LAST word on safety, after the fixture is gone.
      try {
        await assertNothingDialable(rec, sql, 'after teardown');
      } catch {
        /* reported above */
      }
      await sql.end().catch(() => {});
    }

    const finishedAt = new Date();
    const reportFile = resolve(E2E_DIR, 'REPORT-PS-RETURN.md');
    writePluginReport(reportFile, rec, {
      runId: `ps-return-${startedAt.toISOString()}`,
      webhookUrl: `${storeUrl ?? '<shop>'}/index.php?fc=module&module=vocifyai&controller=webhook`,
      baseUrl: has('--skip-internet') ? '(internet leg skipped)' : BASE_URL,
      startedAt,
      finishedAt,
      versions: { PrestaShop: psVersion, 'vocifyai module': moduleVersion },
      notes,
    });
    log(`› report written to ${reportFile}`);

    if (!has('--keep-up') && !has('--reuse')) {
      log('› tearing the stack down');
      await compose(COMPOSE, ['down', '-v'], { echo: true });
    }
  }

  const t = rec.tally();
  log(`\n› ${t.PASS} passed, ${t.FAIL} failed, ${t.SKIP} skipped, ${t.BLOCKED} blocked`);
  process.exit(t.FAIL > 0 ? 1 : 0);
}

main().catch((err) => {
  process.stderr.write(`\nHARNESS ERROR: ${err.stack ?? err.message}\n`);
  process.exit(2);
});
