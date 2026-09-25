// ─────────────────────────────────────────────────────────────────────────────
// PrestaShop INTERNET-LEG suite — the DEPLOYED platform → a PrestaShop shop,
// across the public internet.
//
// `suites/ps-return.mjs` proves the shop half of the wire: it posts from inside
// the container, so the PrestaShop dispatcher, the front controller, the HMAC
// and the state change in MySQL are all real, but nothing about the platform
// reaching a merchant is. This suite closes that gap the way
// `suites/wc-return.mjs` does for WooCommerce:
//
//   an order placed in a REAL shop
//     → the module's outbound hook, signed by the shipped PHP
//     → the DEPLOYED platform at https://152-228-210-12.sslip.io  (orders row)
//     → a synthesised completed Call                              (no dialling)
//     → POST /api/internal/sync-ecommerce on the DEPLOYED platform
//     → the PrestaShop outbound adapter, HMAC + HTTPS
//     → Cloudflare quick tunnel  ← THE PUBLIC INTERNET
//     → VocifyAIWebhookModuleFrontController
//     → ps_orders.current_state
//
// ## How "it crossed the internet" is proven rather than asserted
//
// Apache logs the client address. A request made from inside the container
// (which is what `ps-return.mjs` does) arrives from `127.0.0.1`; a request that
// came through the tunnel arrives from the cloudflared container's address on
// the compose network. So a webhook line in the shop's access log with a
// NON-loopback source, appearing in the window of a deployed cron tick, is
// direct evidence that the push came in from outside. That is asserted here.
//
// ## Safety — nothing here can dial a phone
//
// The fixture agent's calling window is computed closed (`closedWindow()` in
// fixtures/provision-tenant.mjs), the order's `scheduled_at` is read back and
// the run ABORTS if it is less than an hour out, and the attempt is driven to
// a terminal status BEFORE the `calls` row exists, which takes it out of the
// dialler's candidate set entirely. `dialableAttempts()` is re-asserted after
// every write and at the end.
// ─────────────────────────────────────────────────────────────────────────────

import crypto from 'node:crypto';
import { compose, run } from '../lib/docker.mjs';
import { runDeployedCronTick } from '../lib/platform-sync.mjs';

const COMPOSE = 'docker-compose.ps-return.yml';
const SHOP = 'prestashop';
const DB = 'db';
const SHOP_CONTAINER = 'vocify-e2e-psrt-shop';

/** PS_OS_PREPARATION — what outcome `confirmed` maps to by default. */
const STATE_PREPARATION = 3;

const clean = (s) =>
  s
    .split('\n')
    .filter((l) => !/Using a password on the command line|Deprecated:|^\s*$/.test(l))
    .join('\n')
    .trim();

const execAs = (service, user, argv, opts = {}) =>
  compose(COMPOSE, ['exec', '-T', '-u', user, service, ...argv], opts);

async function php(script, args = []) {
  const res = await execAs(SHOP, 'www-data', ['php', script, ...args]);
  if (res.code !== 0) {
    throw new Error(`php ${script} failed: ${clean(res.stderr) || clean(res.stdout)}`);
  }
  return clean(res.stdout);
}

async function mysql(query) {
  const res = await execAs(DB, 'root', [
    'sh',
    '-c',
    `mysql -uprestashop -pprestashop prestashop -N -B -e "${query.replace(/\n\s*/g, ' ')}" 2>/dev/null`,
  ]);
  if (res.code !== 0) throw new Error(`mysql read failed: ${res.stderr.trim()}`);
  return clean(res.stdout);
}

const orderState = async (id) =>
  Number(await mysql(`SELECT current_state FROM ps_orders WHERE id_order = ${id}`));

async function createOrder() {
  const out = await php('/e2e/create-order.php', ['+21600000000']);
  const m = out.match(/PS-ORDER-CREATED order_id=(\d+)/);
  if (!m) throw new Error(`order fixture failed: ${out.slice(0, 400)}`);
  return Number(m[1]);
}

/**
 * Webhook requests in the shop's Apache access log that did NOT come from
 * loopback — i.e. that arrived through the tunnel from outside the machine.
 *
 * This is the evidence that a push crossed the public internet rather than
 * being made from inside the container. cloudflared forwards to
 * `prestashop:80` over the compose network, so a tunnelled request shows the
 * cloudflared container's address; everything `ps-return.mjs` does shows
 * `127.0.0.1`.
 */
async function externalWebhookHits() {
  const logs = await run('docker', ['logs', SHOP_CONTAINER]);
  return `${logs.stdout}\n${logs.stderr}`
    .split('\n')
    .filter((l) => l.includes('controller=webhook') && !l.startsWith('127.0.0.1'))
    .filter((l) => /^\d+\.\d+\.\d+\.\d+ /.test(l));
}

const dialableAttempts = async (sql) =>
  (
    await sql.query(
      `SELECT count(*)::int AS n FROM attempts WHERE status='pending' AND scheduled_at <= now()`
    )
  ).rows[0].n;

const callRow = async (sql, callId) =>
  (
    await sql.query(
      `SELECT outcome, sync_status, sync_attempts, synced_at FROM calls WHERE id = $1`,
      [callId]
    )
  ).rows[0];

/**
 * Turn the pending Attempt this order already has into a COMPLETED call.
 *
 * Reimplemented here rather than imported: `wc-return.mjs`'s version is not
 * exported and that file is being edited concurrently by another agent. The
 * ordering is copied from it deliberately — the attempt is driven terminal
 * BEFORE the `calls` row exists, so there is no window in which the dialler
 * could claim it.
 *
 * `billing_status='skipped'` on purpose: billing and sync are independent
 * phases of the same tick, and letting the billing phase act on this row would
 * make a sync failure and a billing failure indistinguishable.
 */
async function synthesiseCompletedCall(sql, fixture, orderId, outcome) {
  const latest = (
    await sql.query(
      `SELECT a.id, a.attempt_number, a.customer_phone, (c.id IS NOT NULL) AS has_call
         FROM attempts a LEFT JOIN calls c ON c.attempt_id = a.id
        WHERE a.order_id = $1 ORDER BY a.attempt_number DESC LIMIT 1`,
      [orderId]
    )
  ).rows[0];
  if (!latest) throw new Error(`no attempt for order ${orderId}`);

  const callId = crypto.randomUUID();
  let attemptId = latest.id;

  await sql.query('BEGIN');
  try {
    if (latest.has_call) {
      // `calls.attempt_id` is UNIQUE, so a second call needs a second attempt —
      // which is also what a real retry looks like. Terminal and credit-free
      // from the moment it exists, and scheduled where the first one was, so it
      // can never become dialable.
      attemptId = crypto.randomUUID();
      await sql.query(
        `INSERT INTO attempts (id, order_id, source, agent_id, company_id, customer_phone,
                               status, scheduled_at, attempt_number, credits_held,
                               completed_at, created_at, updated_at)
         SELECT $1, a.order_id, a.source, a.agent_id, a.company_id, a.customer_phone,
                'completed', a.scheduled_at, a.attempt_number + 1, 0,
                now(), now(), now()
           FROM attempts a WHERE a.id = $2`,
        [attemptId, latest.id]
      );
    } else {
      await sql.query(
        `UPDATE attempts SET status='completed', completed_at=now(), updated_at=now() WHERE id=$1`,
        [latest.id]
      );
    }
    await sql.query(
      `INSERT INTO calls (id, attempt_id, company_id, direction, agent_id, customer_phone,
                          outcome, duration_s, started_at, ended_at,
                          sync_status, sync_attempts, billing_status, created_at)
       VALUES ($1,$2,$3,'outbound',$4,$5,$6,42, now() - interval '42 seconds', now(),
               'pending', 0, 'skipped', now())`,
      [callId, attemptId, fixture.companyId, fixture.agentId, latest.customer_phone, outcome]
    );
    await sql.query('COMMIT');
  } catch (err) {
    await sql.query('ROLLBACK').catch(() => {});
    throw err;
  }
  return { callId, attemptId };
}

// ---- the suite -------------------------------------------------------------

export async function runPrestaShopInternetSuite({ rec, sql, fixture, storeUrl, baseUrl, notes, adapterApiKey }) {
  rec.group('internet leg — DEPLOYED platform → PrestaShop, over the public internet');

  notes.push(
    `The PrestaShop shop is published at ${storeUrl} by a Cloudflare quick tunnel. assertSafeExternalUrl() requires a public https origin and was never relaxed, patched or bypassed — the tunnel is what satisfies it.`
  );
  notes.push(
    'PrestaShop cannot be installed against the tunnel hostname the way WordPress is, because PS_INSTALL_AUTO runs from the container entrypoint before cloudflared has a hostname. The shop is installed against a placeholder and repointed afterwards; run-ps-return.mjs documents exactly which rows that needs.'
  );

  // ── 0. the shop is reachable from outside, with no redirect ──────────────
  //
  // This is the assertion the whole repointing dance exists for.
  // `Shop::initialize()` answers `302 Location: <canonical>` to any request
  // whose Host is not the configured shop URL, BEFORE the module controller
  // runs — and the platform's adapter fetches with `redirect: 'error'`, so a
  // 302 is a hard delivery failure. An unsigned push is used as the probe
  // because a 401 from the receiver proves the controller itself answered.
  const probe = await fetch(`${storeUrl}/index.php?fc=module&module=vocifyai&controller=webhook`, {
    method: 'POST',
    redirect: 'error',
    headers: { 'Content-Type': 'application/json' },
    body: '{}',
  }).catch((e) => ({ error: e }));

  const probeBody = probe.error ? null : await probe.json().catch(() => null);
  rec.record({
    name: 'the shop answers the platform at integrations.store_url, with NO redirect',
    outcome: !probe.error && probe.status === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401 from the receiver itself (fail-closed on an unsigned push)',
    actual: probe.error
      ? `fetch failed: ${probe.error.message}`
      : `HTTP ${probe.status} ${JSON.stringify(probeBody)}`,
    suspect: probe.error
      ? 'a 302 here means ps_shop_url was not repointed at the tunnel hostname — redirect:"error" turns it into a hard failure, exactly as the platform adapter would see it'
      : undefined,
    severity: 'critical',
  });

  // ── 1. an order placed in the shop reaches the DEPLOYED platform ─────────
  //
  // Through the module's own hook and the shipped signer, not a hand-built
  // request. This is also what proves PS_SHOP_DOMAIN_SSL had to be repointed:
  // `sendWebhook()` builds X-Domain from `Tools::getShopDomainSsl(true)`, and
  // the platform 403s when it does not match `integrations.store_domain`.
  const shopOrderId = await createOrder();

  const platformOrder = (
    await sql.query(
      `SELECT id, external_id, status FROM orders WHERE company_id=$1 AND external_id=$2`,
      [fixture.companyId, String(shopOrderId)]
    )
  ).rows[0];

  rec.record({
    name: 'an order placed in the shop reaches the DEPLOYED platform (inbound leg)',
    outcome: platformOrder ? 'PASS' : 'FAIL',
    expected: `an orders row with external_id ${shopOrderId}`,
    actual: platformOrder
      ? `${platformOrder.id} status=${platformOrder.status}`
      : `no row — check the shop's own webhook log: ${(await php('/e2e/logs.php')).slice(0, 300)}`,
    severity: 'critical',
  });
  if (!platformOrder) return;

  // ── 2. SAFETY GATE ───────────────────────────────────────────────────────
  const attempt = (
    await sql.query(
      `SELECT id, status, scheduled_at FROM attempts WHERE order_id=$1 ORDER BY attempt_number DESC LIMIT 1`,
      [platformOrder.id]
    )
  ).rows[0];
  const hoursOut = attempt ? (new Date(attempt.scheduled_at).getTime() - Date.now()) / 3_600_000 : -1;
  if (!(hoursOut > 1)) {
    throw new Error(
      `SAFETY ABORT: attempt ${attempt?.id} is scheduled ${hoursOut.toFixed(2)}h out. ` +
        'The fixture calling window is not closed as intended and the dialler could claim it.'
    );
  }
  rec.record({
    name: 'SAFETY: the order is scheduled outside the dialler\'s reach',
    outcome: 'PASS',
    expected: 'scheduled_at more than an hour out',
    actual: `${hoursOut.toFixed(1)}h out (${new Date(attempt.scheduled_at).toISOString()})`,
    severity: 'critical',
  });

  const stateBefore = await orderState(shopOrderId);
  rec.pass('the shop order starts in a status the return path must change', {
    actual: `ps order ${shopOrderId} current_state=${stateBefore}`,
  });

  // ── 3. a completed call, no phone dialled ────────────────────────────────
  const { callId } = await synthesiseCompletedCall(sql, fixture, platformOrder.id, 'confirmed');
  rec.record({
    name: 'SAFETY: no attempt is dialable after writing the completed call',
    outcome: (await dialableAttempts(sql)) === 0 ? 'PASS' : 'FAIL',
    expected: '0 rows matching status=pending AND scheduled_at <= now()',
    actual: `${await dialableAttempts(sql)} row(s)`,
    severity: 'critical',
  });

  // ── 4. the DEPLOYED platform pushes it, across the internet ──────────────
  const hitsBefore = (await externalWebhookHits()).length;
  const deployed = await runDeployedCronTick(baseUrl);
  // The deployed cron also runs itself every ~60s against the same database, so
  // give a scheduled tick a moment to land if this manual one saw nothing.
  await new Promise((r) => setTimeout(r, 4000));

  const hitsAfterDeployed = await externalWebhookHits();
  const stateAfterDeployed = await orderState(shopOrderId);
  const callAfterDeployed = await callRow(sql, callId);

  rec.pass('the deployed cron endpoint authenticates and runs its phases', {
    actual: `HTTP ${deployed.status} ${JSON.stringify(deployed.body?.sync ?? deployed.body)}`,
  });

  // THE HEADLINE. Asserted on the shop's own MySQL and on the access log, not
  // on the tick's return value: the deployed cron runs on its own schedule
  // against the same database, so a manual tick can legitimately report
  // `{synced:0}` because a scheduled one got there first — which is still the
  // deployed platform doing the work.
  const deployedPushed =
    stateAfterDeployed === STATE_PREPARATION && hitsAfterDeployed.length > hitsBefore;

  if (deployedPushed) {
    rec.record({
      name: 'THE HEADLINE: the DEPLOYED platform moved the order, across the public internet',
      outcome: 'PASS',
      expected: `ps_orders.current_state = ${STATE_PREPARATION}, reached via the tunnel`,
      actual: `current_state ${stateBefore} → ${stateAfterDeployed}; access log: ${hitsAfterDeployed[hitsAfterDeployed.length - 1]}`,
      request: `POST ${baseUrl}/api/internal/sync-ecommerce`,
      severity: 'critical',
    });
    const lastHit = hitsAfterDeployed[hitsAfterDeployed.length - 1];
    rec.record({
      name: 'the push arrived from OUTSIDE the container, not from loopback',
      outcome: 'PASS',
      expected: 'a webhook line in the shop access log with a non-loopback source address',
      actual: `${hitsAfterDeployed.length - hitsBefore} new external hit(s); ${lastHit}`,
      severity: 'critical',
    });
    // Stronger than "not loopback": Apache logs the Basic-auth username, and
    // the ONLY thing that sends one here is the platform's PrestaShop adapter
    // (`getBasicAuthHeader()` → `credentials.apiKey` + ':'). The harness never
    // sends that header, so its presence identifies the request as the
    // platform adapter's rather than merely as "something from outside".
    rec.record({
      name: "the request was made by the platform's own PrestaShop adapter",
      outcome: adapterApiKey && lastHit.includes(adapterApiKey) ? 'PASS' : 'FAIL',
      expected: `the access-log auth-user field carries credentials.apiKey (${adapterApiKey})`,
      actual: lastHit,
      severity: 'critical',
    });
    rec.record({
      name: 'calls.sync_status becomes synced with syncedAt set',
      outcome: callAfterDeployed.sync_status === 'synced' && callAfterDeployed.synced_at ? 'PASS' : 'FAIL',
      expected: "sync_status='synced', synced_at not null",
      actual: JSON.stringify(callAfterDeployed),
    });
    return { deployedPushed: true, shopOrderId, callId, platformOrderId: platformOrder.id };
  }

  // ── 5. it did not. Separate "the VPS build is stale" from "PrestaShop is
  //       broken" — they look identical from the tick's return value. ───────
  //
  // The known stale-build signature is platform/PROGRESS.md's P0: the deployed
  // `syncOneCall()` passed `integration.credentials` to the adapter without
  // merging in the `integrations.store_url` COLUMN, so
  // `assertSafeExternalUrl(undefined)` threw inside the constructor and NOTHING
  // left the Worker — `{synced:0, failed:1}` with zero lines in the shop's
  // access log. Putting `storeUrl` INSIDE `credentials` is precisely the
  // workaround for that one bug, and changes nothing else about the push, so
  // re-running with it isolates the question.
  rec.blocked(
    'whether the DEPLOYED build can push to a PrestaShop shop with production-shaped credentials',
    `measured ${JSON.stringify(deployed.body?.sync ?? deployed.body)}, order still ${stateAfterDeployed}, ` +
      `${hitsAfterDeployed.length - hitsBefore} external webhook hit(s). The deployed VPS build predates ` +
      "platform commit 4b51231, whose adapterCredentials() merges integrations.store_url into the adapter's " +
      'credentials; without it assertSafeExternalUrl(undefined) throws before any request leaves. ' +
      'Re-run after a platform deploy. The next assertion isolates whether PrestaShop itself is reachable.'
  );

  await sql.query(
    `UPDATE integrations SET credentials = credentials || jsonb_build_object('storeUrl', $2::text), updated_at = now()
      WHERE id = $1`,
    [fixture.integrationId, storeUrl]
  );
  await sql.query(
    `UPDATE calls SET sync_status='pending', sync_attempts=0, synced_at=NULL WHERE id=$1`,
    [callId]
  );
  await sql.query(`UPDATE orders SET status='SCHEDULED' WHERE id=$1`, [platformOrder.id]);

  const hitsBeforeRetry = (await externalWebhookHits()).length;
  const retried = await runDeployedCronTick(baseUrl);
  await new Promise((r) => setTimeout(r, 4000));
  const hitsAfterRetry = await externalWebhookHits();
  const stateAfterRetry = await orderState(shopOrderId);
  const callAfterRetry = await callRow(sql, callId);

  rec.record({
    name: 'THE HEADLINE: the DEPLOYED platform moved the order, across the public internet',
    outcome:
      stateAfterRetry === STATE_PREPARATION && hitsAfterRetry.length > hitsBeforeRetry
        ? 'PASS'
        : 'FAIL',
    expected: `ps_orders.current_state = ${STATE_PREPARATION}, reached via the tunnel`,
    actual: `current_state ${stateBefore} → ${stateAfterRetry}; ${hitsAfterRetry.length - hitsBeforeRetry} new external hit(s)` +
      `${hitsAfterRetry.length ? `; last: ${hitsAfterRetry[hitsAfterRetry.length - 1]}` : ''}`,
    request: `POST ${baseUrl}/api/internal/sync-ecommerce (with storeUrl supplied inside credentials, working around the un-deployed platform fix)`,
    suspect:
      stateAfterRetry === STATE_PREPARATION
        ? undefined
        : `deployed tick returned ${JSON.stringify(retried.body?.sync ?? retried.body)}; call is ${JSON.stringify(callAfterRetry)}`,
    severity: 'critical',
  });
  rec.record({
    name: 'the push arrived from OUTSIDE the container, not from loopback',
    outcome: hitsAfterRetry.length > hitsBeforeRetry ? 'PASS' : 'FAIL',
    expected: 'a webhook line in the shop access log with a non-loopback source address',
    actual: hitsAfterRetry.length
      ? `${hitsAfterRetry.length - hitsBeforeRetry} new external hit(s); last: ${hitsAfterRetry[hitsAfterRetry.length - 1]}`
      : 'none — nothing ever reached the shop',
    severity: 'critical',
  });
  rec.record({
    name: 'calls.sync_status becomes synced with syncedAt set',
    outcome: callAfterRetry.sync_status === 'synced' && callAfterRetry.synced_at ? 'PASS' : 'FAIL',
    expected: "sync_status='synced', synced_at not null",
    actual: JSON.stringify(callAfterRetry),
  });

  return {
    deployedPushed: stateAfterRetry === STATE_PREPARATION,
    shopOrderId,
    callId,
    platformOrderId: platformOrder.id,
  };
}

/**
 * Assertions that must hold however the suite above ended. Kept separate so
 * the driver can run them even when the suite bailed early.
 */
export async function assertNothingDialable(rec, sql, label) {
  const n = await dialableAttempts(sql);
  rec.record({
    name: `SAFETY: nothing dialable ${label}`,
    outcome: n === 0 ? 'PASS' : 'FAIL',
    expected: '0 rows matching status=pending AND scheduled_at <= now()',
    actual: `${n} row(s)`,
    severity: 'critical',
  });
  return n;
}
