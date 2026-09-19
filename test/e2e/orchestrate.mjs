#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// Vocify plugin end-to-end harness — one command, cold start to report.
//
//   cd plugins/test/e2e
//   node ../../../platform/node_modules/tsx/dist/cli.mjs orchestrate.mjs
//
// (tsx is only needed so the platform's TypeScript `Recorder` can be imported
// read-only; the harness itself is plain ESM. `./run.cmd` wraps the above.)
//
// Flags:
//   --keep-up        leave the CMS containers running for interactive debugging
//   --keep-fixture   leave the throwaway Company/Agent/ApiKey in Postgres
//   --slow-replay    additionally replay a captured request AFTER the 300s
//                    freshness window (adds ~5 min of wall clock)
//   --skip-build     assume the shop is already provisioned (fast re-runs)
//   --only wc|ps|rt  run just one suite (default: all three)
//
// What it does, in order:
//   1. preflight: the deployed platform's health, clock skew, Docker engine
//   2. provision a throwaway Company/Agent/Integration/ApiKey/Pack in Postgres
//   3. CONTRACT DETERMINATION + FIXTURE PREFLIGHT — hand-built requests that
//      establish which signing contract the TARGET enforces and that the
//      fixture is sound. Recorded in their own group; never counted as plugin
//      evidence. Aborts before any container starts if the target is stale or
//      if the fixture's calling window is not actually closed.
//   4. docker compose up + head-less WordPress/WooCommerce provisioning
//   5. the WooCommerce suite (real orders through the shop's own hooks)
//   6. the same again for PrestaShop (its own fixture, its own compose stack)
//   7. the RETURN path (platform -> shop): a third stack whose shop has a real
//      public https origin, so the platform can actually reach it
//   8. REPORT.md, then teardown of everything this run created
// ─────────────────────────────────────────────────────────────────────────────

import crypto from 'node:crypto';
import { resolve } from 'node:path';
import { connect } from './lib/platform-db.mjs';
import { loadRecorder, writePluginReport } from './lib/report.mjs';
import { compose, composeExec, run, waitFor, E2E_DIR } from './lib/docker.mjs';
import { provision, teardown } from './fixtures/provision-tenant.mjs';
import { determineContract } from './fixtures/contract-probe.mjs';
import { runWooCommerceSuite } from './suites/wc.mjs';
import { runPrestaShopSuite } from './suites/ps.mjs';
import { runWooCommerceReturnSuite } from './suites/wc-return.mjs';

const has = (f) => process.argv.includes(`--${f}`);

// The DEPLOYED platform. A real public hostname with real Let's Encrypt TLS
// (Caddy + sslip.io on the OVH box), which is what makes it usable here: it
// satisfies BOTH plugins' SSRF guards unmodified — literal `https://` for
// WooCommerce, and a public IP that survives PrestaShop's `gethostbyname()`
// private-range rejection. No tunnel, no ephemeral URL, no scraping step.
const BASE_URL = process.env.TEST_BASE_URL || 'https://162-19-32-251.sslip.io';
const WEBHOOK_URL = `${BASE_URL}/api/webhooks/ecommerce`;

// ⚠️ ONE constant, two consumers. The WordPress site URL's HOST is what the
// plugin reports as X-Domain (extract_domain() drops the port), and it must
// equal `integrations.store_domain` byte-for-byte after the platform's
// normalization — which strips scheme/www/trailing slash but NOT a port.
// Deriving both from here is what prevents the domain-mismatch 403 that reads
// like a signing failure but is a fixture bug.
const WC_DOMAIN = 'wc-e2e.vocify.test';
// The port in this URL is cosmetic — WordPress only stores it, nothing
// connects to it, and `extract_domain()` strips it before it becomes X-Domain.
// It is kept so the site URL looks like a real one in the admin.
const WC_SITE_URL = `http://${WC_DOMAIN}:58080`;
const WC_COMPOSE = 'docker-compose.wc.yml';

// Same one-constant rule on the PrestaShop side: PS_DOMAIN in
// docker-compose.ps.yml feeds Tools::getShopDomainSsl(), which the module
// reports as X-Domain.
const PS_DOMAIN = 'ps-e2e.vocify.test';
const PS_COMPOSE = 'docker-compose.ps.yml';

// The return-path shop. Unlike the two above it has NO fixed domain constant:
// the platform has to reach it, so its hostname is whatever the Cloudflare
// quick tunnel hands out, and the shop is installed with that hostname as its
// siteurl. See docker-compose.rt.yml.
const RT_COMPOSE = 'docker-compose.rt.yml';

const log = (s) => process.stdout.write(`${s}\n`);

async function preflight() {
  log('› preflight');
  const health = await fetch(`${BASE_URL}/api/health`).catch(() => null);
  if (!health || health.status !== 200) {
    throw new Error(
      `platform not answering at ${BASE_URL}/api/health (override with TEST_BASE_URL)`
    );
  }
  log(`  platform: 200 at ${BASE_URL}`);

  // The freshness window is two-sided and 300s wide, so a badly skewed harness
  // clock produces rejections that read exactly like a broken signature.
  const serverDate = health.headers.get('date');
  if (serverDate) {
    const skewMs = Date.now() - new Date(serverDate).getTime();
    log(`  clock skew vs target: ${(skewMs / 1000).toFixed(1)}s`);
    if (Math.abs(skewMs) > 120_000) {
      throw new Error(
        `harness clock is ${(skewMs / 1000).toFixed(0)}s from the target's — inside the 300s window but close enough to make results untrustworthy`
      );
    }
  }

  const docker = await run('docker', ['version', '--format', '{{.Server.Version}}']);
  if (docker.code !== 0) throw new Error(`docker engine unreachable: ${docker.stderr.trim()}`);
  log(`  docker engine: ${docker.stdout.trim()}`);

  // wp-cli is fetched once and mounted read-only into the container, so the
  // container itself needs no download step.
  const phar = resolve(E2E_DIR, '.cache/wp-cli.phar');
  const { existsSync } = await import('node:fs');
  if (!existsSync(phar)) {
    log('  fetching wp-cli.phar into .cache/ (once)');
    const dl = await run('curl', ['-sSL', '-o', phar, 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar']);
    if (dl.code !== 0 || !existsSync(phar)) throw new Error('could not download wp-cli.phar');
  }
  log('  wp-cli.phar: cached');
}

async function provisionShop(fixture, webhookUrl) {
  log('› docker compose up (WooCommerce)');
  const up = await compose(WC_COMPOSE, ['up', '-d']);
  if (up.code !== 0) throw new Error(`compose up failed: ${up.stderr.trim()}`);

  log('› waiting for WordPress to answer');
  // Readiness is the presence of wp-config.php — there is no published port to
  // probe, and the site URL points at a host that does not resolve anyway.
  // Nor is "php runs" enough: the container accepts exec
  // as soon as the entrypoint starts, but that entrypoint still has to copy
  // WordPress core in and write wp-config.php. Provisioning against the gap
  // fails with "Error: 'wp-config.php' not found."
  await waitFor('the wordpress entrypoint to write wp-config.php', async () => {
    const r = await composeExec(WC_COMPOSE, 'wordpress', [
      'php', '-r', 'echo file_exists("/var/www/html/wp-config.php") ? "ready" : "waiting";',
    ]);
    return r.code === 0 && r.stdout.includes('ready');
  });

  log('› provisioning WordPress + WooCommerce + the plugin under test');
  const prov = await composeExec(
    WC_COMPOSE,
    'wordpress',
    ['bash', '/e2e/wp-provision.sh'],
    {
      VOCIFY_SITE_URL: WC_SITE_URL,
      VOCIFY_WEBHOOK_URL: webhookUrl,
      VOCIFY_API_KEY: fixture.apiKey,
      VOCIFY_SIGNATURE_SECRET: fixture.signatureSecret,
    }
  );
  const marker = `${prov.stdout}\n${prov.stderr}`.match(/WC-PROVISION-OK.*/);
  if (prov.code !== 0 || !marker) {
    throw new Error(`WordPress provisioning failed:\n${prov.stdout}\n${prov.stderr}`);
  }
  log(`  ${marker[0]}`);
  return Object.fromEntries(
    marker[0]
      .replace('WC-PROVISION-OK ', '')
      .split(' ')
      .map((kv) => kv.split('='))
  );
}

async function provisionPsShop(fixture, webhookUrl) {
  log('› docker compose up (PrestaShop)');
  const up = await compose(PS_COMPOSE, ['up', '-d']);
  if (up.code !== 0) throw new Error(`compose up failed: ${up.stderr.trim()}`);

  // Readiness signal: PS_INSTALL_AUTO deletes its own install directory when
  // it finishes (measured ~40s on this image — contrary to PrestaShop/docker#204,
  // which says it is left behind). `config/settings.inc.php` and
  // `app/config/parameters.php` exist from the very first second as templates,
  // so neither is usable as a signal.
  //
  // Deliberately a plain `test -d`, not a PHP bootstrap: bootstrapping the app
  // mid-install blocks on the database and the exec never returns, which
  // silently stalled this loop past its own 7-minute deadline. A cheap file
  // test plus a per-exec timeout keeps the poll honest.
  log('› waiting for the PrestaShop auto-install');
  // Stage 1 — read the entrypoint's own completion marker out of the container
  // log. Apache is started only after PS_INSTALL_AUTO finishes, so this line
  // is strictly after the install.
  //
  // Two readiness signals were tried and rejected before this one:
  //   • "the installer removed its directory" — absence does not mean
  //     finished, it also means NOT STARTED. The check passed at 5s, before
  //     the entrypoint had copied any files, and the shop then reported
  //     `Error: "install" directory is missing`.
  //   • "the app bootstraps" as the FIRST check — bootstrapping mid-install
  //     blocks on the database and the exec never returns, and waitFor() only
  //     re-checks its deadline between calls, so one hung exec stalled the
  //     whole run past its own timeout.
  // Reading the log is cheap, cannot hang, and cannot be true too early.
  await waitFor(
    'the PrestaShop entrypoint to finish installing and start Apache',
    async () => {
      const r = await compose(PS_COMPOSE, ['logs', 'prestashop'], { timeoutMs: 30_000 });
      return /resuming normal operations/.test(`${r.stdout}${r.stderr}`);
    },
    { timeoutMs: 420_000, intervalMs: 5000 }
  );

  // Stage 2 — Apache being up is still not the same as the app being usable.
  // Provisioning against that gap produced an empty
  // `PS-PROVISION-OK ps= vocify= domain=` that the orchestrator accepted as
  // success, and an order fixture that then failed with no message at all.
  // Now that the install is genuinely finished this bootstrap cannot hang the
  // way an early one would, and the per-exec timeout covers it regardless.
  await waitFor(
    'PrestaShop to bootstrap',
    async () => {
      const r = await composeExec(
        PS_COMPOSE,
        'prestashop',
        ['php', '-r', 'require_once "/var/www/html/config/config.inc.php"; echo "PSVER:" . _PS_VERSION_;'],
        {},
        { timeoutMs: 30_000 }
      );
      return r.code === 0 && r.stdout.includes('PSVER:');
    },
    { timeoutMs: 180_000, intervalMs: 5000 }
  );

  log('› installing and configuring the module under test');
  const prov = await composeExec(PS_COMPOSE, 'prestashop', ['bash', '/e2e/ps-provision.sh'], {
    VOCIFY_WEBHOOK_URL: webhookUrl,
    VOCIFY_API_KEY: fixture.apiKey,
    VOCIFY_SIGNATURE_SECRET: fixture.signatureSecret,
  });
  const marker = `${prov.stdout}\n${prov.stderr}`.match(/PS-PROVISION-OK.*/);
  if (prov.code !== 0 || !marker) {
    throw new Error(`PrestaShop provisioning failed:\n${prov.stdout}\n${prov.stderr}`);
  }
  log(`  ${marker[0]}`);
  return Object.fromEntries(
    marker[0].replace('PS-PROVISION-OK ', '').split(' ').map((kv) => kv.split('='))
  );
}

/**
 * Bring up the return-path shop and give the platform a way in.
 *
 * Order matters: the tunnel hostname is not known until cloudflared has
 * connected, and BOTH the WordPress install (`siteurl`) and the platform
 * fixture (`store_domain` / `store_url`) have to use it.
 */
async function provisionReturnShop(sql, webhookUrl) {
  log('› docker compose up (return-path WooCommerce + tunnel)');
  const up = await compose(RT_COMPOSE, ['up', '-d']);
  if (up.code !== 0) throw new Error(`compose up failed: ${up.stderr.trim()}`);

  log('› waiting for the quick tunnel to publish a hostname');
  const storeUrl = await waitFor(
    'cloudflared to print its public URL',
    async () => {
      const logs = await run('docker', ['logs', 'vocify-e2e-rt-tunnel']);
      const m = `${logs.stdout}\n${logs.stderr}`.match(/https:\/\/[a-z0-9-]+\.trycloudflare\.com/);
      return m ? m[0] : null;
    },
    { timeoutMs: 120_000, intervalMs: 2000 }
  );
  const storeHost = new URL(storeUrl).host;
  log(`  shop is public at ${storeUrl}`);

  log('› provisioning the return-path fixture');
  const fixture = await provision(sql, {
    platform: 'WOOCOMMERCE',
    domain: storeHost,
    tag: 'rt',
  });
  // What the outbound adapter needs. `store_url` is the column the fixed
  // sync service reads; `credentials` holds only the platform secrets, which
  // is the shape the integration wizard really writes.
  await sql.query(
    `UPDATE integrations SET store_url = $2, credentials = $3::jsonb, updated_at = now() WHERE id = $1`,
    [
      fixture.integrationId,
      storeUrl,
      JSON.stringify({
        consumerKey: `ck_${crypto.randomBytes(20).toString('hex')}`,
        consumerSecret: `cs_${crypto.randomBytes(20).toString('hex')}`,
      }),
    ]
  );
  log(`  company ${fixture.companyId} (return path)`);

  await waitFor('the wordpress entrypoint to write wp-config.php', async () => {
    const r = await composeExec(RT_COMPOSE, 'wordpress', [
      'php', '-r', 'echo file_exists("/var/www/html/wp-config.php") ? "ready" : "waiting";',
    ]);
    return r.code === 0 && r.stdout.includes('ready');
  });

  log('› provisioning the return-path shop');
  const prov = await composeExec(RT_COMPOSE, 'wordpress', ['bash', '/e2e/wp-provision.sh'], {
    VOCIFY_SITE_URL: storeUrl,
    VOCIFY_WEBHOOK_URL: webhookUrl,
    VOCIFY_API_KEY: fixture.apiKey,
    VOCIFY_SIGNATURE_SECRET: fixture.signatureSecret,
  });
  const marker = `${prov.stdout}\n${prov.stderr}`.match(/WC-PROVISION-OK.*/);
  if (prov.code !== 0 || !marker) {
    throw new Error(`return-path shop provisioning failed:\n${prov.stdout}\n${prov.stderr}`);
  }
  log(`  ${marker[0]}`);

  return { fixture, storeUrl };
}

async function main() {
  const startedAt = new Date();
  const runId = crypto.randomUUID().slice(0, 8);
  const notes = [];
  let fixture = null;
  let psFixtureId = null;
  let rtFixtureId = null;
  let sql = null;
  const onlyIdx = process.argv.indexOf('--only');
  const only = onlyIdx > -1 ? process.argv[onlyIdx + 1] : null;
  let Recorder;
  let rec;

  try {
    Recorder = await loadRecorder();
    rec = new Recorder();

    await preflight();

    const webhookUrl = WEBHOOK_URL;
    log(`› webhook target: ${webhookUrl}`);
    notes.push(
      `Webhook target is the DEPLOYED platform at ${BASE_URL} over real Let's Encrypt TLS. That is what lets the shipped SSRF guards run unmodified: WooCommerce requires a literal https:// URL, and PrestaShop additionally rejects any host resolving to a private range — which every Docker-network address is.`
    );

    sql = await connect();

    log('› provisioning the platform-side fixture');
    fixture = await provision(sql, { platform: 'WOOCOMMERCE', domain: WC_DOMAIN, tag: 'wc' });
    log(`  company ${fixture.companyId}`);
    log(`  agent   ${fixture.agentId} — calling window ${fixture.callingWindow.start}-${fixture.callingWindow.end} ${fixture.callingWindow.timezone} (CLOSED now)`);

    // ---- contract determination + fixture preflight (NOT plugin assertions) ----
    //
    // Which signing contract does the TARGET enforce? A plugin that is correct
    // against the 2026-09-18 timestamp-bound contract fails against an older
    // deployment in a way indistinguishable from a plugin bug, so this is
    // settled first, against the same fixture the suites will use. The GET
    // health handler cannot answer it — that string is hardcoded.
    rec.group('preflight — target contract + fixture, proves nothing about the plugins');
    const contract = await determineContract(sql, fixture, webhookUrl, (s) => log(s));

    rec.record({
      name: 'deployed platform enforces the timestamp-bound signing contract',
      outcome: contract.verdict === 'NEW' ? 'PASS' : 'FAIL',
      expected: 'timestamp-bound accepted (201), body-only rejected, unsigned rejected',
      actual: `verdict ${contract.verdict}: new=${contract.results.timestampBound.status}, old=${contract.results.bodyOnly.status} ${contract.results.bodyOnly.code ?? ''}, unsigned=${contract.results.unsigned.status} ${contract.results.unsigned.code ?? ''}`,
      severity: 'critical',
    });
    if (contract.verdict !== 'NEW') {
      throw new Error(
        `the deployed platform does NOT enforce the timestamp-bound contract (verdict ${contract.verdict}). ` +
          'Refusing to run the plugin suites against it — a red result here would be a stale deployment, not a plugin bug.'
      );
    }
    rec.pass('body-only signature (the pre-2026-09-18 contract) is rejected', {
      actual: `HTTP ${contract.results.bodyOnly.status} ${contract.results.bodyOnly.code}`,
    });
    rec.pass('unsigned request is rejected (fail-closed)', {
      actual: `HTTP ${contract.results.unsigned.status} ${contract.results.unsigned.code}`,
    });
    rec.pass('correctly signed but stale timestamp is rejected', {
      actual: `HTTP ${contract.results.stale.status} ${contract.results.stale.code}`,
    });
    rec.pass('hand-built signed request reaches the platform (fixture is sound)', {
      actual: `HTTP 201, order ${contract.results.timestampBound.body.orderId}, scheduledFor ${contract.results.timestampBound.body.scheduledFor}`,
    });

    // SAFETY GATE, before any shop container starts. The VPS runs the dialler,
    // so an attempt with `scheduled_at <= now()` would be claimed and a real
    // phone would ring. Verify the closed calling window actually pushed this
    // order out of reach before creating any more.
    const probeScheduled = new Date(contract.results.timestampBound.body.scheduledFor);
    const hoursOut = (probeScheduled.getTime() - Date.now()) / 3_600_000;
    if (!(hoursOut > 1)) {
      throw new Error(
        `SAFETY ABORT: the preflight order is scheduled ${hoursOut.toFixed(2)}h out (${probeScheduled.toISOString()}). ` +
          'The fixture calling window is not closed as intended and the dialler could claim it. No shop containers started.'
      );
    }
    log(`  SAFETY: preflight order scheduled ${hoursOut.toFixed(1)}h out — outside the dialler's reach`);

    notes.push(
      'The `preflight` group is hand-built requests that deliberately bypass all plugin PHP. They establish which contract the target enforces and that the fixture is sound, so a later plugin failure is unambiguously plugin-side. They are never evidence that a plugin works.'
    );
    notes.push(
      'Robustness observation (not asserted, no fix made): `woocommerce_new_order` fires from inside `WC_Abstract_Order::save()` BEFORE `save_items()`, and `WC_Abstract_Order::calculate_totals()` saves before writing the computed total back. A payment gateway that builds an order and calls `calculate_totals()` therefore fires the hook with `total = 0` (measured on WooCommerce 11.1.1: subtotal 129.9, total 0). The plugin forwards whatever the order says at that moment and has no zero-total guard.'
    );

    // ---- WooCommerce ----
    // Don't build a WooCommerce shop nobody is going to test.
    const versions =
      only === 'ps' || only === 'rt'
        ? {}
        : has('skip-build')
          ? { note: '--skip-build: shop assumed already provisioned' }
          : await provisionShop(fixture, webhookUrl);

    if (only === 'ps' || only === 'rt') {
      rec.group('woocommerce');
      rec.skip('WooCommerce end-to-end suite', `--only ${only}`);
    } else {
      await runWooCommerceSuite({
        rec,
        sql,
        fixture,
        webhookUrl,
        notes,
        slowReplay: has('slow-replay'),
      });
    }

    // ---- PrestaShop ----
    if (only === 'wc' || only === 'rt') {
      rec.group('prestashop');
      rec.skip('PrestaShop end-to-end suite', '--only wc');
    } else {
      const psFixture = await provision(sql, { platform: 'PRESTASHOP', domain: PS_DOMAIN, tag: 'ps' });
      psFixtureId = psFixture.companyId;
      log(`  company ${psFixture.companyId} (PrestaShop)`);
      const psVersions = await provisionPsShop(psFixture, webhookUrl);
      Object.assign(versions, psVersions);
      await runPrestaShopSuite({ rec, sql, fixture: psFixture, webhookUrl, notes, slowReplay: has('slow-replay') });
    }

    // ---- RETURN PATH: platform -> shop ----
    if (only === 'wc' || only === 'ps') {
      rec.group('return path — platform → WooCommerce');
      rec.skip('WooCommerce return-path suite', `--only ${only}`);
    } else {
      const rt = await provisionReturnShop(sql, webhookUrl);
      rtFixtureId = rt.fixture.companyId;
      await runWooCommerceReturnSuite({
        rec,
        sql,
        fixture: rt.fixture,
        storeUrl: rt.storeUrl,
        baseUrl: BASE_URL,
        notes,
      });
    }

    const finishedAt = new Date();
    const reportFile = resolve(E2E_DIR, 'REPORT.md');
    writePluginReport(reportFile, rec, {
      runId,
      baseUrl: BASE_URL,
      webhookUrl,
      startedAt,
      finishedAt,
      versions,
      notes,
    });

    const t = rec.tally();
    log('');
    log(`› ${t.PASS} passed, ${t.FAIL} failed, ${t.SKIP} skipped, ${t.BLOCKED} blocked`);
    log(`› report: ${reportFile}`);
    process.exitCode = t.FAIL > 0 ? 1 : 0;
  } catch (err) {
    log(`\nFATAL: ${err.message}`);
    process.exitCode = 2;
  } finally {
    if (!has('keep-up')) {
      log('› tearing down containers');
      await compose(WC_COMPOSE, ['down', '-v']);
      await compose(PS_COMPOSE, ['down', '-v']);
      await compose(RT_COMPOSE, ['down', '-v']);
    } else {
      log('› --keep-up: containers left running');
    }
    if (sql && !has('keep-fixture')) {
      log('› tearing down the platform fixtures');
      for (const companyId of [fixture?.companyId, psFixtureId, rtFixtureId].filter(Boolean)) {
        const counts = await teardown(sql, companyId);
        if (counts._companies_remaining !== 0) {
          log(`  WARNING: ${counts._companies_remaining} companies row(s) survived teardown — ${companyId}`);
          process.exitCode = process.exitCode || 1;
        } else {
          log(`  removed company ${companyId} (${counts.orders} order(s), ${counts.attempts} attempt(s))`);
        }
      }
    } else if (fixture) {
      log(`› --keep-fixture: companies ${[fixture.companyId, psFixtureId, rtFixtureId].filter(Boolean).join(', ')} left in Postgres`);
    }
    await sql?.end().catch(() => {});
  }
}

main();
