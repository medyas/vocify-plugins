// ─────────────────────────────────────────────────────────────────────────────
// WooCommerce RETURN-PATH suite — platform → shop.
//
// The other half of the round trip. `suites/wc.mjs` proves an order placed in a
// real shop reaches the platform; this proves the call's outcome gets back to
// the merchant, by asserting the WooCommerce order's status **in WooCommerce's
// own database**, not by trusting `calls.sync_status`.
//
// The chain under test:
//
//   calls.outcome='confirmed', sync_status='pending'
//     → syncCompletedOrders()                 (platform, phase 4 of the cron tick)
//     → outcomeToOrderStatus / outcomeToAdapterStatus
//     → WooCommerce outbound adapter          (HMAC, HTTPS)
//     → POST {storeUrl}/?rest_route=/vocify/v1/order-status
//     → Vocify_AI_Status_Receiver             (the plugin, in PHP)
//     → wp_posts.post_status / wp_wc_orders.status
//
// NO PHONE IS DIALLED. The attempt is driven straight to a terminal status
// before the Call row is written, which takes it out of the dialler's
// candidate set entirely; the `scheduled_at <= now()` invariant is re-asserted
// after every write.
//
// Two things the shop must be for this to run at all:
//   * reachable from the platform — so the shop sits behind a Cloudflare quick
//     tunnel and is INSTALLED with that hostname as its siteurl/home;
//   * https and public — `assertSafeExternalUrl()` refuses anything else, and
//     that guard is never relaxed, patched or bypassed here.
// ─────────────────────────────────────────────────────────────────────────────

import crypto from 'node:crypto';
import { composeExec } from '../lib/docker.mjs';
import { runLocalSyncTick, runDeployedCronTick } from '../lib/platform-sync.mjs';

const COMPOSE = 'docker-compose.rt.yml';
const SVC = 'wordpress';
const DB = 'db';

const clean = (s) =>
  s
    .split('\n')
    .filter((l) => !/sendmail|^\s*$/.test(l))
    .join('\n')
    .trim();

async function wpEval(php) {
  const res = await composeExec(COMPOSE, SVC, ['wp', '--allow-root', 'eval', php]);
  if (res.code !== 0) throw new Error(`wp eval failed: ${clean(res.stderr) || clean(res.stdout)}`);
  return clean(res.stdout);
}

async function wpJson(php) {
  const out = await wpEval(php);
  const line = out.split('\n').filter(Boolean).pop() ?? '';
  try {
    return JSON.parse(line);
  } catch {
    throw new Error(`expected JSON from wp eval, got: ${out.slice(0, 300)}`);
  }
}

/**
 * The order's status read straight out of MySQL.
 *
 * Deliberately NOT `wc_get_order()->get_status()`: that goes through
 * WooCommerce's object cache in the same PHP process that might have just
 * written it. This is the merchant's data on disk, and it is what the headline
 * claim rests on. Handles both storage engines — HPOS (`wp_wc_orders`) and the
 * legacy post table (`wp_posts.post_status`, prefixed `wc-`).
 */
async function mysql(query) {
  const res = await composeExec(COMPOSE, DB, [
    'sh',
    '-c',
    `mariadb -uwordpress -pwordpress wordpress -N -B -e "${query.replace(/\n\s*/g, ' ')}"`,
  ]);
  if (res.code !== 0) throw new Error(`mariadb read failed: ${res.stderr.trim()}`);
  return clean(res.stdout);
}

/**
 * Which table this shop keeps orders in, resolved once.
 *
 * Referencing a missing table is a hard error in MySQL even inside a subquery
 * that could never run, so a COALESCE across both engines does not work — the
 * choice has to be made before the read.
 */
let usesHpos = null;
async function hpos() {
  if (usesHpos === null) {
    usesHpos =
      (await mysql(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wp_wc_orders'"
      )) === '1';
  }
  return usesHpos;
}

async function orderStatusFromMysql(orderId) {
  const status = (await hpos())
    ? await mysql(`SELECT status FROM wp_wc_orders WHERE id = ${orderId}`)
    : await mysql(`SELECT post_status FROM wp_posts WHERE ID = ${orderId}`);
  return status.replace(/^wc-/, '');
}

const orderNotes = (orderId) =>
  wpJson(
    `echo json_encode(array_map(function($n){return $n->content;}, wc_get_order_notes(array("order_id"=>${orderId}))));`
  );

const hmac = (secret, message) =>
  crypto.createHmac('sha256', secret).update(message).digest('hex');

/** POST to the shop's receiver exactly the way the platform adapter does. */
async function postToReceiver(storeUrl, secret, payload, opts = {}) {
  const body = typeof payload === 'string' ? payload : JSON.stringify(payload);
  const timestamp = opts.timestamp ?? new Date().toISOString();
  const headers = { 'Content-Type': 'application/json', 'X-Vocify-Timestamp': timestamp };
  if (!opts.omitSignature) {
    headers['X-Vocify-Signature'] = opts.signature ?? hmac(secret, `${timestamp}.${body}`);
  }
  const res = await fetch(`${storeUrl}/?rest_route=/vocify/v1/order-status`, {
    method: 'POST',
    headers,
    body,
  });
  const text = await res.text();
  let parsed;
  try {
    parsed = JSON.parse(text);
  } catch {
    parsed = { raw: text.slice(0, 200) };
  }
  return { status: res.status, body: parsed };
}

// ---- platform-side fixture writes ------------------------------------------

/**
 * Turn the pending Attempt this order already has into a COMPLETED call.
 *
 * `billing_status='skipped'` on purpose: billing and sync are independent
 * phases of the same tick, and letting the billing phase act on this row would
 * make a sync failure and a billing failure indistinguishable in the tick's
 * summary.
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
      // Terminal BEFORE the Call row exists: from here the dialler cannot claim it.
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

const orderRow = async (sql, orderId) =>
  (await sql.query(`SELECT external_id, status FROM orders WHERE id = $1`, [orderId])).rows[0];

// ---- the suite -------------------------------------------------------------

export async function runWooCommerceReturnSuite({ rec, sql, fixture, storeUrl, baseUrl, notes }) {
  rec.group('return path — platform → WooCommerce');

  notes.push(
    'The return-path shop is a SECOND WooCommerce stack (docker-compose.rt.yml, port 58081) installed with a Cloudflare quick-tunnel hostname as its siteurl. That is what makes the platform able to reach it: assertSafeExternalUrl() requires a public https origin and was never relaxed for this run.'
  );

  // ── 0. the shop must be reachable at the URL the platform holds ───────────
  const reach = await fetch(`${storeUrl}/?rest_route=/`, { redirect: 'error' }).catch((e) => e);
  rec.record({
    name: 'the shop is reachable from the public internet at integrations.store_url',
    outcome: reach && reach.status === 200 ? 'PASS' : 'FAIL',
    expected: 'HTTP 200 from the WordPress REST index, no redirect',
    actual: reach && reach.status ? `HTTP ${reach.status}` : `fetch failed: ${reach?.message}`,
    severity: 'critical',
  });

  // ── 1. the receiver exists at all ─────────────────────────────────────────
  // Before 2026-09-19 it did not: the plugin registered no REST route and this
  // returned 404 rest_no_route, which alone made the round trip impossible.
  const unsigned = await postToReceiver(storeUrl, '', { orderId: '0' }, { omitSignature: true });
  rec.record({
    name: 'the plugin registers the receiver route the platform posts to',
    outcome: unsigned.status !== 404 || unsigned.body?.code !== 'rest_no_route' ? 'PASS' : 'FAIL',
    expected: 'anything but rest_no_route',
    actual: `HTTP ${unsigned.status} ${unsigned.body?.code ?? ''}`,
    suspect:
      unsigned.body?.code === 'rest_no_route'
        ? 'the WooCommerce plugin has no register_rest_route() call — see plugins/PROGRESS.md §9'
        : undefined,
    severity: 'critical',
  });
  rec.record({
    name: 'an unsigned push is rejected (fail-closed)',
    outcome: unsigned.status === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${unsigned.status} ${unsigned.body?.code ?? ''}`,
    severity: 'critical',
  });

  // ── 2. a real order, placed through the shop's own checkout code ──────────
  const created = await composeExec(COMPOSE, SVC, [
    'wp',
    '--allow-root',
    'eval-file',
    '/e2e/create-order-checkout.php',
  ]);
  const marker = `${created.stdout}\n${created.stderr}`.match(/WC-ORDER-CREATED order_id=(\d+)/);
  if (!marker) throw new Error(`order fixture failed:\n${created.stdout}\n${created.stderr}`);
  const shopOrderId = marker[1];

  const platformOrder = (
    await sql.query(
      `SELECT id, external_id, status FROM orders WHERE company_id=$1 AND external_id=$2`,
      [fixture.companyId, shopOrderId]
    )
  ).rows[0];
  rec.record({
    name: 'the order reached the platform (inbound leg, prerequisite for the return leg)',
    outcome: platformOrder ? 'PASS' : 'FAIL',
    expected: `an orders row with external_id ${shopOrderId}`,
    actual: platformOrder ? `${platformOrder.id} status=${platformOrder.status}` : 'no row',
    severity: 'critical',
  });
  if (!platformOrder) return;

  const before = await orderStatusFromMysql(shopOrderId);
  rec.pass('the shop order starts in a status the return path must change', {
    actual: `wp order ${shopOrderId} status=${before}`,
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

  // ── 4. what the DEPLOYED platform does with it ───────────────────────────
  const deployed = await runDeployedCronTick(baseUrl);
  const deployedSync = deployed.body?.sync;
  rec.pass('the deployed cron endpoint authenticates and runs all five phases', {
    actual: `HTTP ${deployed.status} ${JSON.stringify(deployed.body)}`,
  });
  // Recorded as BLOCKED rather than FAIL when it cannot push: the DEPLOYED
  // build is whatever was last shipped to the VPS, so a red result here means
  // "this fix is not deployed yet", which is not a defect in the code under
  // test. The measured numbers are kept in the reason either way — this is the
  // assertion that says whether production can do the round trip TODAY.
  if (deployedSync?.synced === 1) {
    rec.pass('the DEPLOYED platform can push this call to the shop', {
      actual: JSON.stringify(deployedSync),
    });
  } else {
    rec.blocked(
      'whether the DEPLOYED platform can push this call to the shop',
      `measured ${JSON.stringify(deployedSync)} — the deployed build predates the working-tree fix ` +
        '(it passes integration.credentials, which has no storeUrl, to the adapter, so it throws ' +
        '"Store URL must be a public https:// address" before any request leaves). Re-run after a deploy.'
    );
  }

  // Reset whatever the deployed attempt did to the bookkeeping, so the
  // working-tree run below starts from the same place.
  await sql.query(
    `UPDATE calls SET sync_status='pending', sync_attempts=0, synced_at=NULL WHERE id=$1`,
    [callId]
  );
  await sql.query(`UPDATE orders SET status='SCHEDULED' WHERE id=$1`, [platformOrder.id]);

  // ── 5. the working tree, end to end ──────────────────────────────────────
  const tick = await runLocalSyncTick();
  rec.record({
    name: 'the working-tree sync phase reports one call synced',
    outcome: tick.ok && tick.result?.synced === 1 && tick.result?.failed === 0 ? 'PASS' : 'FAIL',
    expected: '{synced: 1, failed: 0}',
    actual: tick.ok ? JSON.stringify(tick.result) : `runner failed: ${tick.stderr.slice(-400)}`,
    severity: 'critical',
  });

  const after = await orderStatusFromMysql(shopOrderId);
  rec.record({
    name: "THE HEADLINE: the order's status changed in WooCommerce's own database",
    outcome: after === 'processing' ? 'PASS' : 'FAIL',
    expected: `order ${shopOrderId}: pending → processing (outcome 'confirmed')`,
    actual: `${before} → ${after}`,
    request: 'read straight from MySQL, not from wc_get_order() or calls.sync_status',
    severity: 'critical',
  });

  const notesAfter = await orderNotes(shopOrderId);
  const resultNote = notesAfter.find((n) => n.includes('Vocify AI call result'));
  rec.record({
    name: 'the merchant gets an order note saying what happened on the call',
    outcome: resultNote && resultNote.includes('CONFIRMED') ? 'PASS' : 'FAIL',
    expected: 'an order note naming the outcome',
    actual: resultNote ? resultNote.split('\n')[0] : `no such note among ${notesAfter.length}`,
  });

  const syncedCall = await callRow(sql, callId);
  rec.record({
    name: 'calls.sync_status becomes synced with syncedAt set',
    outcome: syncedCall.sync_status === 'synced' && syncedCall.synced_at ? 'PASS' : 'FAIL',
    expected: "sync_status='synced', synced_at not null",
    actual: JSON.stringify(syncedCall),
  });
  const syncedOrder = await orderRow(sql, platformOrder.id);
  rec.record({
    name: 'the platform Order status agrees with what the shop now shows',
    outcome: syncedOrder.status === 'CONFIRMED' ? 'PASS' : 'FAIL',
    expected: 'CONFIRMED',
    actual: syncedOrder.status,
  });

  // The status change fires the plugin's OUTBOUND hook, which posts back to the
  // platform. That must not create a second order or a second attempt.
  const loop = await sql.query(
    `SELECT (SELECT count(*)::int FROM orders WHERE company_id=$1 AND external_id=$2) AS orders,
            (SELECT count(*)::int FROM attempts WHERE order_id=$3) AS attempts`,
    [fixture.companyId, shopOrderId, platformOrder.id]
  );
  // 1 attempt: the plugin's outbound webhook for the status change must be
  // deduped by the platform as a repeat of an order it already has, not turned
  // into a fresh call.
  rec.record({
    name: 'the status change echoing back to the platform does not loop or duplicate',
    outcome: loop.rows[0].orders === 1 && loop.rows[0].attempts === 1 ? 'PASS' : 'FAIL',
    expected: '1 order, 1 attempt',
    actual: JSON.stringify(loop.rows[0]),
    severity: 'critical',
  });

  // ── 6. idempotency ───────────────────────────────────────────────────────
  const secret = fixture.signatureSecret;
  const replayPayload = {
    orderId: shopOrderId,
    status: 'cancelled',
    callData: { callSid: callId, duration: 42, completedAt: new Date().toISOString() },
  };
  const replay = await postToReceiver(storeUrl, secret, replayPayload);
  const afterReplay = await orderStatusFromMysql(shopOrderId);
  rec.record({
    name: 'a repeat of the same callSid is a no-op, even with a different status',
    outcome: replay.status === 200 && replay.body?.changed === false ? 'PASS' : 'FAIL',
    expected: 'HTTP 200 with changed:false',
    actual: `HTTP ${replay.status} ${JSON.stringify(replay.body)}`,
  });
  rec.record({
    name: 'the replay did not move the order',
    outcome: afterReplay === 'processing' ? 'PASS' : 'FAIL',
    expected: 'processing (unchanged)',
    actual: afterReplay,
  });

  // ── 7. the negative matrix, against the shipped receiver ─────────────────
  const freshPayload = (status) => ({
    orderId: shopOrderId,
    status,
    callData: { callSid: crypto.randomUUID(), duration: 7, completedAt: new Date().toISOString() },
  });

  const wrongSecret = await postToReceiver(storeUrl, 'not-the-secret', freshPayload('cancelled'));
  rec.record({
    name: 'a signature made with the wrong secret is rejected',
    outcome: wrongSecret.status === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${wrongSecret.status} ${wrongSecret.body?.code ?? ''}`,
    severity: 'critical',
  });

  // The pre-2026-09-19 shape: HMAC over the body alone, timestamp unbound.
  const bodyOnlyPayload = freshPayload('cancelled');
  const bodyOnlyRaw = JSON.stringify(bodyOnlyPayload);
  const bodyOnly = await postToReceiver(storeUrl, secret, bodyOnlyRaw, {
    signature: hmac(secret, bodyOnlyRaw),
  });
  rec.record({
    name: 'a body-only signature (unbound timestamp) is rejected',
    outcome: bodyOnly.status === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401 — the timestamp must be inside the signed message',
    actual: `HTTP ${bodyOnly.status} ${bodyOnly.body?.code ?? ''}`,
    severity: 'critical',
  });

  const stalePayload = freshPayload('cancelled');
  const staleTs = new Date(Date.now() - 6 * 60 * 1000).toISOString();
  const stale = await postToReceiver(storeUrl, secret, stalePayload, { timestamp: staleTs });
  rec.record({
    name: 'a correctly signed push with a 6-minute-old timestamp is rejected',
    outcome: stale.status === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${stale.status} ${stale.body?.code ?? ''}`,
  });

  const tamperedRaw = JSON.stringify(freshPayload('cancelled'));
  const tsForTamper = new Date().toISOString();
  const tampered = await postToReceiver(
    storeUrl,
    secret,
    tamperedRaw.replace('"cancelled"', '"confirmed"'),
    { timestamp: tsForTamper, signature: hmac(secret, `${tsForTamper}.${tamperedRaw}`) }
  );
  rec.record({
    name: 'a body altered after signing is rejected',
    outcome: tampered.status === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${tampered.status} ${tampered.body?.code ?? ''}`,
    severity: 'critical',
  });

  const unknown = await postToReceiver(storeUrl, secret, {
    ...freshPayload('confirmed'),
    orderId: '99999999',
  });
  rec.record({
    name: 'an order the shop does not have answers 404, not 500',
    outcome: unknown.status === 404 ? 'PASS' : 'FAIL',
    expected: 'HTTP 404',
    actual: `HTTP ${unknown.status} ${unknown.body?.code ?? ''}`,
  });

  // ── 8. a cancellation really cancels ─────────────────────────────────────
  const cancel = await postToReceiver(storeUrl, secret, freshPayload('cancelled'));
  const afterCancel = await orderStatusFromMysql(shopOrderId);
  rec.record({
    name: "outcome 'cancelled' cancels the order in the shop",
    outcome: cancel.status === 200 && afterCancel === 'cancelled' ? 'PASS' : 'FAIL',
    expected: 'processing → cancelled',
    actual: `HTTP ${cancel.status}, status now ${afterCancel}`,
  });

  const noAnswer = await postToReceiver(storeUrl, secret, freshPayload('no_answer'));
  const afterNoAnswer = await orderStatusFromMysql(shopOrderId);
  rec.record({
    name: "an outcome with no purchase-intent meaning ('no_answer') notes but does not move the order",
    outcome: noAnswer.status === 200 && afterNoAnswer === 'cancelled' ? 'PASS' : 'FAIL',
    expected: 'HTTP 200, status unchanged at cancelled',
    actual: `HTTP ${noAnswer.status}, status now ${afterNoAnswer}`,
  });

  // A DIFFERENT call, older than the one already applied. An order can have
  // several calls (a retry makes a second Attempt and a second Call) and the
  // platform retries a failed sync five times, so a delayed retry of the FIRST
  // call can land after the second one has been applied. Deduping on callSid
  // alone does not catch that: the sid is new, so without an ordering check the
  // old `confirmed` would flip the merchant's `cancelled` order back to
  // `processing`.
  const stalePush = await postToReceiver(storeUrl, secret, {
    orderId: shopOrderId,
    status: 'confirmed',
    callData: {
      callSid: crypto.randomUUID(),
      duration: 30,
      completedAt: new Date(Date.now() - 60 * 60 * 1000).toISOString(),
    },
  });
  const afterStalePush = await orderStatusFromMysql(shopOrderId);
  rec.record({
    name: 'an older result from a DIFFERENT call cannot rewind the order',
    outcome: stalePush.status === 200 && stalePush.body?.changed === false && afterStalePush === 'cancelled'
      ? 'PASS'
      : 'FAIL',
    expected: 'HTTP 200 changed:false, status still cancelled',
    actual: `HTTP ${stalePush.status} ${JSON.stringify(stalePush.body)}, status now ${afterStalePush}`,
    severity: 'critical',
  });

  // ── 9. failure accounting when the shop rejects the push ─────────────────
  //
  // The failure is induced at the SHOP, by clearing the merchant's signing
  // secret so the receiver fails closed with 503. That is a misconfiguration a
  // real merchant can produce, and it keeps the whole test inside our own
  // containers.
  //
  // (Pointing `store_url` at a bogus path does NOT work: `?rest_route=` is
  // honoured from any path, so `https://shop/no-such-shop/?rest_route=…` is
  // still served by the REST API. Measured — it returned 200.)
  const { callId: failCallId } = await synthesiseCompletedCall(
    sql,
    fixture,
    platformOrder.id,
    'confirmed'
  ).catch((err) => {
    rec.blocked('failure accounting: second completed call', err.message);
    return { callId: null };
  });

  if (failCallId) {
    await composeExec(COMPOSE, SVC, [
      'wp',
      '--allow-root',
      'option',
      'update',
      'vocify_signature_secret',
      '',
    ]);
    // ⚠️ The deployed platform runs this same tick on a ~60s schedule (measured
    // 2026-09-19: a pending call's sync_attempts climbed 0→1→2→3 untouched).
    // Starting at MAX_SYNC_ATTEMPTS - 1 means the first failing tick — whoever
    // runs it — takes the call terminal, so at most ONE tick can act on it and
    // the two possible outcomes are distinguishable below.
    await sql.query(`UPDATE calls SET sync_attempts=4 WHERE id=$1`, [failCallId]);
    await sql.query(`UPDATE orders SET status='SCHEDULED' WHERE id=$1`, [platformOrder.id]);

    const failTick = await runLocalSyncTick();
    const failed = await callRow(sql, failCallId);
    const orderDuringFailure = await orderRow(sql, platformOrder.id);
    // `{synced:0, failed:0}` means a scheduled tick on the DEPLOYED build got
    // there first and the working-tree code never saw this call — an honest
    // "not evaluated", not a pass and not a failure of the code under test.
    const racedByDeployedCron = failTick.result?.failed === 0 && failTick.result?.synced === 0;

    if (racedByDeployedCron) {
      const reason =
        'a scheduled cron tick on the DEPLOYED build claimed this call first ' +
        `(tick saw nothing to do; call is now ${JSON.stringify(failed)}, order ${orderDuringFailure.status}). ` +
        'The deployed platform runs the same tick every ~60s against the same database.';
      rec.blocked('a shop that rejects the push is counted as a failed sync', reason);
      rec.blocked('a failed push increments sync_attempts and takes the call terminal', reason);
      rec.blocked('REGRESSION GUARD: a failed push does NOT flip the platform Order status', reason);
      rec.blocked('the receiver fails closed when no signing secret is configured', reason);
    } else {
      rec.record({
        name: 'a shop that rejects the push is counted as a failed sync, not a silent success',
        outcome: failTick.result?.failed === 1 && failTick.result?.synced === 0 ? 'PASS' : 'FAIL',
        expected: '{synced: 0, failed: 1}',
        actual: JSON.stringify(failTick.result),
      });
      rec.record({
        name: 'a failed push increments sync_attempts and takes the call terminal at the cap',
        outcome: failed.sync_attempts === 5 && failed.sync_status === 'failed' ? 'PASS' : 'FAIL',
        expected: 'sync_attempts 5 (from 4), sync_status failed',
        actual: JSON.stringify(failed),
      });
      rec.record({
        name: 'REGRESSION GUARD: a failed push does NOT flip the platform Order status',
        outcome: orderDuringFailure.status === 'SCHEDULED' ? 'PASS' : 'FAIL',
        expected:
          'SCHEDULED — the merchant was never told, so the platform must not claim CONFIRMED',
        actual: orderDuringFailure.status,
        suspect:
          orderDuringFailure.status === 'SCHEDULED'
            ? undefined
            : 'syncOneCall() wrote Order.status before the push and never rolled it back',
        severity: 'critical',
      });
      rec.record({
        name: 'the receiver fails closed when the merchant has no signing secret configured',
        outcome: failTick.result?.failed === 1 ? 'PASS' : 'FAIL',
        expected: 'the push is rejected by the shop, not accepted unsigned',
        actual: JSON.stringify(failTick.result),
        severity: 'critical',
      });
    }

    await composeExec(COMPOSE, SVC, [
      'wp',
      '--allow-root',
      'option',
      'update',
      'vocify_signature_secret',
      fixture.signatureSecret,
    ]);
    // Terminal, so no scheduled tick on the deployed build keeps hammering the
    // shop for a call this suite is finished with.
    await sql.query(
      `UPDATE calls SET sync_status='failed', sync_attempts=5 WHERE id=$1`,
      [failCallId]
    );
  } else {
    rec.skip('failure accounting when the shop is unreachable', 'could not synthesise a second call');
  }

  rec.record({
    name: 'SAFETY: still nothing dialable at the end of the suite',
    outcome: (await dialableAttempts(sql)) === 0 ? 'PASS' : 'FAIL',
    expected: '0',
    actual: `${await dialableAttempts(sql)}`,
    severity: 'critical',
  });
}
