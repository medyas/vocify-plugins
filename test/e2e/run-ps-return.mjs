// ─────────────────────────────────────────────────────────────────────────────
// Standalone driver for the PrestaShop return-path suite.
//
//   ./run-ps-return.sh            bring the stack up, provision, run, report
//   ./run-ps-return.sh --keep-up  leave the containers running afterwards
//   ./run-ps-return.sh --reuse    skip `up`/provisioning, run against what is up
//
// Deliberately NOT wired into `orchestrate.mjs`, for two reasons. The
// orchestrator's whole preamble — platform contract probe, tenant fixture,
// dialable-attempt safety gate — exists because its suites write to the
// platform's live Postgres. This suite writes nothing there: it posts call
// results straight to the shop, the way the platform's outbound adapter would,
// so it needs no tenant, no database and no safety gate. Requiring
// `platform/.env` and a reachable deployed platform to run it would be pure
// ceremony. (The Recorder is still the platform's, because the report format
// should match; that is a read-only import through platform's tsx.)
//
// Folding this into `orchestrate.mjs` as a fourth `--only` target is a
// reasonable next step and is left to whoever owns that file — it is shared,
// and this run does not need it.
// ─────────────────────────────────────────────────────────────────────────────

import { resolve } from 'node:path';
import { compose, run, waitFor, E2E_DIR } from './lib/docker.mjs';
import { loadRecorder, writePluginReport } from './lib/report.mjs';
import { runPrestaShopReturnSuite } from './suites/ps-return.mjs';

const COMPOSE = 'docker-compose.ps-return.yml';
const SHOP = 'prestashop';

// Dev-only literals, on purpose: this stack is throwaway, has no published
// port and is torn down at the end. They are not credentials to anything.
const API_KEY = 'vcf_test_psreturn0000000000';
const SECRET = 'whsec_ps_return_leg_test';
const SINK = 'https://vocify-e2e-sink.invalid/api/webhooks/ecommerce';

const argv = process.argv.slice(2);
const has = (flag) => argv.includes(flag);

const log = (s) => process.stdout.write(`${s}\n`);

async function up() {
  log('› bringing up the PrestaShop return-path stack');
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
      const r = await compose(
        COMPOSE,
        ['exec', '-T', '-u', 'www-data', SHOP, 'php', '-r',
          'require "/var/www/html/config/config.inc.php"; echo "PS-READY " . _PS_VERSION_;'],
        { timeoutMs: 30_000 }
      );
      return r.code === 0 && r.stdout.includes('PS-READY');
    },
    { timeoutMs: 180_000, intervalMs: 5000 }
  );

  // Stage 3 — the catalogue. `create-order.php` needs one active product, and
  // an empty catalogue is exactly the symptom a too-early stage 1 produces, so
  // assert it here where the message is unambiguous rather than three minutes
  // later inside the suite.
  await waitFor(
    'at least one active product in the catalogue',
    async () => {
      const r = await compose(
        COMPOSE,
        ['exec', '-T', '-u', 'www-data', SHOP, 'php', '-r',
          'require "/var/www/html/config/config.inc.php"; echo "PRODUCTS:" . (int)Db::getInstance()->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "product WHERE active = 1");'],
        { timeoutMs: 30_000 }
      );
      const m = r.stdout.match(/PRODUCTS:(\d+)/);
      return Boolean(m && Number(m[1]) > 0);
    },
    { timeoutMs: 120_000, intervalMs: 5000 }
  );
}

/**
 * The shop must answer over HTTP before the suite's first assertion.
 *
 * The first front-office request after a cache wipe compiles the Symfony
 * container, and until it has, requests fail at the transport level. A `HTTP 0`
 * from curl is not a receiver verdict, but the assertions read it as one — the
 * first run of this driver recorded two spurious FAILs that way.
 */
async function warmUp() {
  log('› warming the front office');
  await waitFor(
    'the shop to answer an HTTP request',
    async () => {
      const spec = Buffer.from(JSON.stringify({ body: {}, omitSignature: true }), 'utf8').toString('base64');
      const r = await compose(
        COMPOSE,
        ['exec', '-T', '-u', 'www-data', SHOP, 'php', '/e2e/post-result.php', spec],
        { timeoutMs: 90_000 }
      );
      const m = r.stdout.match(/\{"http":(\d+)/);
      return Boolean(m && Number(m[1]) > 0);
    },
    { timeoutMs: 180_000, intervalMs: 5000 }
  );
}

async function provision() {
  log('› installing and configuring the module');
  const res = await compose(
    COMPOSE,
    [
      'exec', '-T',
      '-e', `VOCIFY_API_KEY=${API_KEY}`,
      '-e', `VOCIFY_SIGNATURE_SECRET=${SECRET}`,
      '-e', `VOCIFY_WEBHOOK_URL=${SINK}`,
      SHOP, 'bash', '/e2e/ps-provision.sh',
    ],
    { echo: true }
  );
  if (res.code !== 0) throw new Error('provisioning failed');

  // ⚠️ ps-provision.sh runs as root and boots PrestaShop, which creates
  // `var/cache/prod` owned by root. Apache runs as www-data and then cannot
  // write the compiled Symfony container into it: EVERY front-office request
  // dies with `Cannot rename "/tmp/FrontContainer.php…"` → HTTP 500, with
  // nothing wrong in the module. Measured on the first run of this suite. The
  // inbound suites never hit it because they make no HTTP request to the shop.
  log('› resetting var/ ownership after the root-run provisioning');
  // `rm -rf .../cache/*` and not a `*` glob joined with `&&`: when the cache
  // happens to be empty the glob does not expand, `rm` fails on the literal
  // path, and the chown that actually matters never runs. Measured — the
  // first clean run of this driver died exactly there.
  const fix = await compose(COMPOSE, [
    'exec', '-T', SHOP, 'sh', '-c',
    'rm -rf /var/www/html/var/cache/prod /var/www/html/var/cache/dev; chown -R www-data:www-data /var/www/html/var',
  ]);
  if (fix.code !== 0) {
    throw new Error(`could not reset var/ ownership: ${fix.stderr.trim() || fix.stdout.trim()}`);
  }
}

async function main() {
  const startedAt = new Date();
  const Recorder = await loadRecorder();
  const rec = new Recorder();
  const notes = [];

  if (!has('--reuse')) {
    await up();
    await waitForShop();
    await provision();
  }
  await warmUp();

  const versions = {};
  const v = await compose(COMPOSE, [
    'exec', '-T', '-u', 'www-data', SHOP, 'php', '-r',
    'require "/var/www/html/config/config.inc.php"; $m = Module::getInstanceByName("vocifyai"); echo _PS_VERSION_ . "|" . ($m ? $m->version : "?");',
  ]);
  const [psVersion, moduleVersion] = (v.stdout.trim().split('\n').pop() ?? '?|?').split('|');
  versions.PrestaShop = psVersion;
  versions['vocifyai module'] = moduleVersion;

  log(`› PrestaShop ${psVersion}, vocifyai ${moduleVersion}`);

  try {
    await runPrestaShopReturnSuite({ rec, secret: SECRET, notes });
  } finally {
    const finishedAt = new Date();
    const reportFile = resolve(E2E_DIR, 'REPORT-PS-RETURN.md');
    writePluginReport(reportFile, rec, {
      runId: `ps-return-${startedAt.toISOString()}`,
      webhookUrl: 'http://<shop>/index.php?fc=module&module=vocifyai&controller=webhook',
      baseUrl: '(not used — this suite touches no platform database)',
      startedAt,
      finishedAt,
      versions,
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
