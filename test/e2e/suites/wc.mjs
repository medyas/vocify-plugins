// ─────────────────────────────────────────────────────────────────────────────
// WooCommerce suite.
//
// EVERY positive assertion below is downstream of an order placed through
// WooCommerce's own order API, so the webhook under test is produced by the
// shipped plugin PHP — payload builder, validator, signer and HTTP send. The
// harness never posts to /api/webhooks/ecommerce on the plugin's behalf.
//
// The negative suite is the one place requests are constructed by the harness,
// and even there the bytes are the REAL ones the plugin transmitted (captured
// by the mu-plugin via `http_api_debug`) with one named mutation each. A
// tampered body or a forged timestamp cannot be produced by the plugin itself,
// so this is the only honest way to assert the platform rejects them — and it
// still exercises the plugin's own signature, not a hand-rolled one.
// ─────────────────────────────────────────────────────────────────────────────

import crypto from 'node:crypto';
import { composeExec, sleep } from '../lib/docker.mjs';

const COMPOSE = 'docker-compose.wc.yml';
const SVC = 'wordpress';
const CAPTURE = '/var/www/html/wp-content/uploads/vocify-e2e-capture.jsonl';

// ---- container helpers -----------------------------------------------------

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

/** Every row the plugin wrote to its own log table, newest first. */
const pluginLogs = (limit = 20) =>
  wpJson(
    `global $wpdb; echo json_encode($wpdb->get_results("SELECT id, order_id, status, http_code, response FROM {$wpdb->prefix}vocify_webhook_logs ORDER BY id DESC LIMIT ${limit}", ARRAY_A));`
  );

const failedQueue = () =>
  wpJson(
    `global $wpdb; echo json_encode($wpdb->get_results("SELECT id, order_id, retry_count, error_message FROM {$wpdb->prefix}vocify_failed_webhooks", ARRAY_A));`
  );

async function captures() {
  const res = await composeExec(COMPOSE, SVC, ['cat', CAPTURE]);
  return clean(res.stdout)
    .split('\n')
    .filter(Boolean)
    .map((l) => JSON.parse(l));
}

const resetCaptures = () => composeExec(COMPOSE, SVC, ['bash', '-c', `: > ${CAPTURE}`]);

const setOption = (name, value) =>
  composeExec(COMPOSE, SVC, ['wp', '--allow-root', 'option', 'update', name, value]);

async function placeCheckoutOrder(phone) {
  const argv = ['wp', '--allow-root', 'eval-file', '/e2e/create-order-checkout.php'];
  if (phone !== undefined) argv.push(phone);
  const res = await composeExec(COMPOSE, SVC, argv);
  const m = clean(res.stdout).match(/WC-ORDER-CREATED order_id=(\d+)/);
  if (!m) throw new Error(`order creation failed: ${clean(res.stdout)}\n${clean(res.stderr)}`);
  return m[1];
}

async function setOrderStatus(orderId, status) {
  const res = await composeExec(COMPOSE, SVC, [
    'wp', '--allow-root', 'eval-file', '/e2e/set-status.php', String(orderId), status,
  ]);
  const m = clean(res.stdout).match(/WC-STATUS-CHANGED order_id=(\d+) status=(\S+)/);
  if (!m) throw new Error(`status change failed: ${clean(res.stdout)}\n${clean(res.stderr)}`);
  return m[2];
}

// ---- db helpers ------------------------------------------------------------

const ordersFor = (sql, companyId, externalId) =>
  sql
    .query(
      `SELECT id, external_id, external_platform::text AS platform, agent_id, company_id,
              phone, customer_name, customer_email, total::text, currency, status::text
         FROM orders WHERE company_id = $1 AND external_id = $2`,
      [companyId, externalId]
    )
    .then((r) => r.rows);

const attemptsFor = (sql, orderId) =>
  sql
    .query(
      `SELECT id, status, attempt_number, credits_held, customer_phone,
              to_char(scheduled_at, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS scheduled_at,
              (scheduled_at > (now() AT TIME ZONE 'utc') + interval '1 hour') AS far_future
         FROM attempts WHERE order_id = $1 ORDER BY attempt_number`,
      [orderId]
    )
    .then((r) => r.rows);

const companyCredits = (sql, companyId) =>
  sql
    .query(`SELECT total_credits, credits_held FROM companies WHERE id = $1`, [companyId])
    .then((r) => r.rows[0]);

// ---- replay of a captured, plugin-produced request --------------------------

/**
 * Re-send bytes the PLUGIN produced, optionally with one named mutation.
 * `mutate` receives { headers, body } and returns the same shape.
 */
async function replay(capture, webhookUrl, mutate = (x) => x) {
  const { headers, body } = mutate({ headers: { ...capture.headers }, body: capture.body });
  const res = await fetch(webhookUrl, { method: 'POST', headers, body });
  const text = await res.text();
  let parsed;
  try {
    parsed = JSON.parse(text);
  } catch {
    parsed = { raw: text.slice(0, 300) };
  }
  return { status: res.status, body: parsed };
}

const sign = (secret, timestamp, body) =>
  crypto.createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');

// ---- the suite -------------------------------------------------------------

export async function runWooCommerceSuite({ rec, sql, fixture, webhookUrl, notes, slowReplay }) {
  const ok = (name, cond, expected, actual, extra = {}) =>
    cond ? rec.pass(name, { actual, ...extra }) : rec.fail(name, { expected, actual, ...extra });

  // ═══════════════════════════════════════════════════════════════════════
  rec.group('wc/positive — real order through woocommerce_new_order');
  // ═══════════════════════════════════════════════════════════════════════

  await resetCaptures();
  const creditsBefore = await companyCredits(sql, fixture.companyId);
  const orderId = await placeCheckoutOrder();
  // The plugin sends synchronously inside the hook, so the attempt has already
  // happened by the time the command returns. A short settle only covers the
  // JSONL write.
  await sleep(1500);

  const logs = await pluginLogs();
  const mine = logs.filter((l) => String(l.order_id) === String(orderId));
  const success = mine.find((l) => l.status === 'success');

  // Asserted FIRST and deliberately: "no Order row in Postgres" is produced
  // identically by a hook that never fired, a disabled integration, a locally
  // rejected URL and a platform 401. The plugin's own log row separates them.
  ok(
    'plugin logged a delivery attempt for this order',
    mine.length > 0,
    `>=1 row in wp_vocify_webhook_logs for order ${orderId}`,
    mine.length ? JSON.stringify(mine.map((l) => ({ status: l.status, http: l.http_code }))) : 'no rows — the hook did not fire or the plugin bailed before sending'
  );
  ok(
    'plugin observed HTTP 201 from the platform',
    !!success && String(success.http_code) === '201',
    'a success row with http_code 201',
    mine.length ? `${mine[0].status} http_code=${mine[0].http_code} ${String(mine[0].response).slice(0, 180)}` : 'none'
  );
  ok(
    'exactly one delivery attempt (no retry, no duplicate send)',
    mine.length === 1,
    '1 log row for this order',
    `${mine.length} row(s)`
  );

  const fq = await failedQueue();
  ok(
    'failed-webhook retry queue is empty for this order',
    !fq.some((r) => String(r.order_id) === String(orderId)),
    'no wp_vocify_failed_webhooks row',
    `${fq.length} queued row(s) overall`
  );

  const caps = await captures();
  ok(
    'exactly one HTTP request left the container',
    caps.length === 1,
    '1 captured outbound request',
    `${caps.length}`
  );

  const cap = caps[0];
  if (!cap) {
    rec.blocked('signature/headers assertions', 'no outbound request was captured');
  } else {
    ok(
      'X-Domain is the store domain the plugin detected',
      cap.headers['X-Domain'] === fixture.storeDomain,
      fixture.storeDomain,
      String(cap.headers['X-Domain'])
    );
    ok(
      'X-Platform is WOOCOMMERCE',
      cap.headers['X-Platform'] === 'WOOCOMMERCE',
      'WOOCOMMERCE',
      String(cap.headers['X-Platform'])
    );
    ok(
      'X-API-Key is the provisioned key',
      cap.headers['X-API-Key'] === fixture.apiKey,
      'the fixture API key',
      cap.headers['X-API-Key'] === fixture.apiKey ? 'matches' : 'differs'
    );

    // THE assertion the 2026-09-19 signer fix exists for: recompute the digest
    // in Node, over the transmitted timestamp and the transmitted bytes, using
    // the platform's formula — and require the shipped PHP to have produced it.
    const expectedSig = sign(fixture.signatureSecret, cap.headers['X-Timestamp'], cap.body);
    ok(
      'X-Signature == HMAC-SHA256(secret, `${X-Timestamp}.${rawBody}`) recomputed independently',
      cap.headers['X-Signature'] === expectedSig,
      `${expectedSig.slice(0, 16)}… (Node, platform formula)`,
      `${String(cap.headers['X-Signature']).slice(0, 16)}… (shipped PHP signer)`,
      { suspect: 'woocommerce/includes/class-vocify-signer.php' }
    );

    const bodyOnly = crypto.createHmac('sha256', fixture.signatureSecret).update(cap.body).digest('hex');
    ok(
      'X-Signature is NOT the old body-only digest (regression guard)',
      cap.headers['X-Signature'] !== bodyOnly,
      'a timestamp-bound digest',
      cap.headers['X-Signature'] === bodyOnly ? 'body-only HMAC — the pre-2026-09-19 bug is back' : 'timestamp is bound in'
    );

    const skewMs = Math.abs(Date.now() - new Date(cap.headers['X-Timestamp']).getTime());
    ok(
      'X-Timestamp parses and is inside the 300s freshness window',
      Number.isFinite(skewMs) && skewMs < 300_000,
      'a parseable ISO 8601 timestamp within 300s',
      Number.isFinite(skewMs) ? `${(skewMs / 1000).toFixed(1)}s skew` : 'unparseable'
    );

    const payload = JSON.parse(cap.body);
    ok(
      'createdAt uses the Z designator the platform schema accepts',
      /Z$/.test(payload.createdAt),
      'ISO 8601 ending in Z (Zod z.string().datetime() rejects +00:00)',
      String(payload.createdAt),
      { suspect: 'woocommerce/includes/class-vocify-payload-builder.php' }
    );
  }

  // ---- platform-side state ----
  const orders = await ordersFor(sql, fixture.companyId, String(orderId));
  ok(
    'an Order row exists in Postgres for this shop order',
    orders.length === 1,
    `1 orders row with external_id=${orderId}`,
    `${orders.length}`
  );

  if (orders.length !== 1) {
    rec.blocked('order/attempt field assertions', 'no Order row to assert against');
  } else {
    const o = orders[0];
    ok('Order.external_platform is WOOCOMMERCE', o.platform === 'WOOCOMMERCE', 'WOOCOMMERCE', o.platform);
    ok('Order.agent_id is the provisioned agent', o.agent_id === fixture.agentId, fixture.agentId, o.agent_id);
    ok('Order.company_id is the provisioned company', o.company_id === fixture.companyId, fixture.companyId, o.company_id);
    ok('Order.phone is the customer phone the plugin sent', o.phone === '+21600000000', '+21600000000', o.phone);
    ok('Order.customer_name survived the transform', o.customer_name === 'Amina Ben Ali', 'Amina Ben Ali', o.customer_name);
    ok('Order.currency is TND', o.currency === 'TND', 'TND', o.currency);
    ok('Order.total is the WooCommerce line total', Number(o.total) === 129.9, '129.90', o.total);
    ok('Order.status is SCHEDULED (a credit was reserved)', o.status === 'SCHEDULED', 'SCHEDULED', o.status);

    const attempts = await attemptsFor(sql, o.id);
    ok('exactly one Attempt row was created', attempts.length === 1, '1 attempts row', `${attempts.length}`);
    if (attempts.length === 1) {
      const a = attempts[0];
      ok('Attempt.status is pending', a.status === 'pending', 'pending', a.status);
      ok('Attempt.attempt_number is 1', a.attempt_number === 1, '1', String(a.attempt_number));
      ok(
        'Attempt.customer_phone is the Order.phone the plugin sent (customerPhone field-name contract)',
        a.customer_phone === o.phone,
        o.phone,
        a.customer_phone
      );
      ok(
        'Attempt.credits_held is the call credit cost',
        a.credits_held === fixture.callCreditCost,
        String(fixture.callCreditCost),
        String(a.credits_held)
      );
      // SAFETY ASSERTION — keep this permanently. On a box where the VPS
      // poller is live it is the only thing standing between this harness and
      // a real outbound phone call: the poller claims `scheduled_at <= now()`.
      ok(
        'SAFETY: Attempt.scheduled_at is far in the future (closed calling window — nothing can dial)',
        a.far_future === true,
        'scheduled_at > now() + 1 hour',
        `${a.scheduled_at} (agent window ${fixture.callingWindow.start}-${fixture.callingWindow.end} ${fixture.callingWindow.timezone})`,
        { severity: 'critical' }
      );
    }

    const creditsAfter = await companyCredits(sql, fixture.companyId);
    ok(
      'companies.credits_held incremented by exactly the reserved cost',
      creditsAfter.credits_held - creditsBefore.credits_held === fixture.callCreditCost,
      `+${fixture.callCreditCost}`,
      `${creditsBefore.credits_held} -> ${creditsAfter.credits_held}`
    );
  }

  // ═══════════════════════════════════════════════════════════════════════
  rec.group('wc/positive — status change hook + idempotency');
  // ═══════════════════════════════════════════════════════════════════════

  await resetCaptures();
  await setOrderStatus(orderId, 'processing');
  await sleep(1500);

  const statusCaps = await captures();
  ok(
    'a status change to `processing` fires a second webhook',
    statusCaps.length === 1,
    '1 outbound request from woocommerce_order_status_changed',
    `${statusCaps.length}`
  );
  ok(
    'the platform treats the repeat as a duplicate (HTTP 200, not a new order)',
    statusCaps.length === 1 && statusCaps[0].response_code === 200,
    'HTTP 200 "Order already processed"',
    statusCaps.length ? `HTTP ${statusCaps[0].response_code} ${String(statusCaps[0].response_body).slice(0, 120)}` : 'nothing sent'
  );
  const afterDup = await ordersFor(sql, fixture.companyId, String(orderId));
  ok(
    'no duplicate Order row was created',
    afterDup.length === 1,
    '1 orders row',
    `${afterDup.length}`
  );

  await resetCaptures();
  await setOrderStatus(orderId, 'on-hold');
  await sleep(1200);
  const onHoldCaps = await captures();
  ok(
    'a status NOT in the forwarded list (`on-hold`) sends nothing',
    onHoldCaps.length === 0,
    '0 outbound requests',
    `${onHoldCaps.length}`,
    { suspect: 'woocommerce/includes/class-vocify-order-handler.php trigger_statuses' }
  );

  // ═══════════════════════════════════════════════════════════════════════
  rec.group('wc/negative — platform rejects tampered plugin traffic');
  // ═══════════════════════════════════════════════════════════════════════

  if (!cap) {
    rec.blocked('tamper/replay assertions', 'no captured plugin request to mutate');
  } else {
    const expectReject = async (name, mutate, wantStatus, wantCode, expectedDesc) => {
      const r = await replay(cap, webhookUrl, mutate);
      const codeOk = wantCode ? r.body?.code === wantCode : true;
      ok(
        name,
        r.status === wantStatus && codeOk,
        expectedDesc,
        `HTTP ${r.status} ${r.body?.code ?? ''} ${r.body?.error ?? ''}`.trim()
      );
      return r;
    };

    await expectReject(
      'tampered body (same signature) is rejected',
      ({ headers, body }) => ({ headers, body: body.replace('"total":129.9', '"total":1.9') }),
      401,
      'SIGNATURE_MISMATCH',
      'HTTP 401 SIGNATURE_MISMATCH'
    );

    await expectReject(
      'altered X-Timestamp header (signature over the original) is rejected',
      ({ headers, body }) => ({
        headers: { ...headers, 'X-Timestamp': new Date(Date.now() - 1000).toISOString() },
        body,
      }),
      401,
      'SIGNATURE_MISMATCH',
      'HTTP 401 SIGNATURE_MISMATCH — proves the timestamp is bound INTO the digest'
    );

    await expectReject(
      'stale timestamp (correctly signed, 6 minutes old) is rejected',
      ({ headers, body }) => {
        const stale = new Date(Date.now() - 6 * 60 * 1000).toISOString();
        return { headers: { ...headers, 'X-Timestamp': stale, 'X-Signature': sign(fixture.signatureSecret, stale, body) }, body };
      },
      401,
      'TIMESTAMP_INVALID',
      'HTTP 401 TIMESTAMP_INVALID (300s window)'
    );

    await expectReject(
      'wrong API key is rejected',
      ({ headers, body }) => ({ headers: { ...headers, 'X-API-Key': `vcf_live_${'0'.repeat(64)}` }, body }),
      401,
      undefined,
      'HTTP 401'
    );

    // The remaining two are only exercised elsewhere as a hand-built fixture
    // in the preflight contract probe (orchestrate.mjs), which the README
    // states explicitly never counts as plugin evidence. These replay the
    // SAME bytes the shipped plugin signed (`cap`), so they prove the
    // deployed platform rejects the plugin's own unsigned/incomplete
    // traffic, not a synthetic request shaped by the harness. Expected codes
    // read out of platform/src/lib/auth/api-key.ts and
    // platform/src/lib/validations/unified-webhook.schema.ts, not guessed.
    await expectReject(
      'unsigned request (X-Signature stripped from the plugin\'s own bytes) is rejected',
      ({ headers, body }) => {
        const h = { ...headers };
        delete h['X-Signature'];
        return { headers: h, body };
      },
      401,
      'SIGNATURE_MISMATCH',
      'HTTP 401 SIGNATURE_MISMATCH (fail-closed on a missing signature)'
    );

    await expectReject(
      'missing X-Timestamp header (correctly signed body, timestamp entirely absent) is rejected',
      ({ headers, body }) => {
        const h = { ...headers };
        delete h['X-Timestamp'];
        return { headers: h, body };
      },
      // The "missing signature" branch in authenticateApiKey() only fires on
      // `!signature`, so a present-but-unbound signature falls through to the
      // timestamp check — TIMESTAMP_INVALID, not SIGNATURE_MISMATCH — before
      // the HMAC is ever recomputed.
      401,
      'TIMESTAMP_INVALID',
      'HTTP 401 TIMESTAMP_INVALID (timestamp is mandatory, not just bound into the digest)'
    );

    // A missing X-API-Key is a DIFFERENT branch than the wrong-but-present key
    // above: `x-api-key` is a required (non-optional) field in
    // webhookHeadersSchema, so its absence fails header validation itself
    // (HTTP 400) before authenticateApiKey() ever runs — never the 401 an
    // invalid value gets. Asserted directly (not via expectReject) because
    // the response carries no `code` field for this branch.
    const noApiKey = await replay(cap, webhookUrl, ({ headers, body }) => {
      const h = { ...headers };
      delete h['X-API-Key'];
      return { headers: h, body };
    });
    ok(
      'missing X-API-Key header is rejected at header validation (HTTP 400, not 401)',
      noApiKey.status === 400,
      'HTTP 400 "Invalid headers" — a different code path than an invalid-but-present key',
      `HTTP ${noApiKey.status} ${JSON.stringify(noApiKey.body).slice(0, 140)}`
    );

    // A verbatim replay INSIDE the freshness window is not an error and must
    // not be reported as one: the platform dedupes on externalId and answers
    // 200 "Order already processed". Idempotency is the defence here; the
    // timestamp binding is what stops a replay LATER (asserted below).
    const inWindow = await replay(cap, webhookUrl);
    ok(
      'verbatim replay inside the 300s window is idempotent (HTTP 200, no new order)',
      inWindow.status === 200,
      'HTTP 200 "Order already processed"',
      `HTTP ${inWindow.status} ${JSON.stringify(inWindow.body).slice(0, 140)}`
    );
    const afterReplay = await ordersFor(sql, fixture.companyId, String(orderId));
    ok(
      'replay created no second Order row',
      afterReplay.length === 1,
      '1 orders row',
      `${afterReplay.length}`
    );

    if (slowReplay) {
      const capturedAt = new Date(cap.headers['X-Timestamp']).getTime();
      const waitMs = capturedAt + 301_000 - Date.now();
      if (waitMs > 0) {
        process.stdout.write(`  ...waiting ${(waitMs / 1000).toFixed(0)}s to replay the captured request outside the freshness window\n`);
        await sleep(waitMs);
      }
      const late = await replay(cap, webhookUrl);
      ok(
        'GENUINE replay of the captured request after the 300s window is rejected',
        late.status === 401 && late.body?.code === 'TIMESTAMP_INVALID',
        'HTTP 401 TIMESTAMP_INVALID',
        `HTTP ${late.status} ${late.body?.code ?? ''}`
      );
    } else {
      rec.skip(
        'GENUINE replay of the captured request after the 300s window',
        'needs ~5 minutes of wall clock; run with --slow-replay. The crafted stale-timestamp assertion above covers the same platform branch'
      );
      notes.push(
        'The verbatim >300s replay assertion was skipped (`--slow-replay` not set). Its platform branch is still covered by the crafted stale-timestamp case.'
      );
    }
  }

  // ═══════════════════════════════════════════════════════════════════════
  rec.group('wc/negative — the plugin refuses to send (no request leaves)');
  // ═══════════════════════════════════════════════════════════════════════

  // 1. SSRF hardening: a plaintext http:// endpoint must be refused locally.
  await resetCaptures();
  await setOption('vocify_webhook_url', 'http://insecure.example.com/api/webhooks/ecommerce');
  const ssrfOrder = await placeCheckoutOrder();
  await sleep(1200);
  const ssrfCaps = await captures();
  const ssrfLogs = (await pluginLogs()).filter((l) => String(l.order_id) === String(ssrfOrder));
  ok(
    'plaintext http:// webhook URL: nothing leaves the container',
    ssrfCaps.length === 0,
    '0 outbound requests',
    `${ssrfCaps.length}`,
    { severity: 'critical' }
  );
  ok(
    'plaintext http:// webhook URL: the plugin logs the local rejection',
    ssrfLogs.some((l) => l.status === 'error' && /must be an absolute https/i.test(String(l.response))),
    'an error row naming the https requirement',
    ssrfLogs.length ? String(ssrfLogs[0].response).slice(0, 140) : 'no log row'
  );
  ok(
    'plaintext http:// webhook URL: no Order reached the platform',
    (await ordersFor(sql, fixture.companyId, String(ssrfOrder))).length === 0,
    '0 orders rows',
    'checked'
  );
  await setOption('vocify_webhook_url', webhookUrl);

  // 2. The plugin's own payload validator must fail fast on a missing phone.
  await resetCaptures();
  const noPhoneOrder = await placeCheckoutOrder('');
  await sleep(1200);
  const noPhoneCaps = await captures();
  const noPhoneLogs = (await pluginLogs()).filter((l) => String(l.order_id) === String(noPhoneOrder));
  ok(
    'order with no phone: the plugin validates locally and sends nothing',
    noPhoneCaps.length === 0,
    '0 outbound requests (fail fast, not a platform 400)',
    `${noPhoneCaps.length}`
  );
  ok(
    'order with no phone: the local validation error is logged',
    noPhoneLogs.some((l) => l.status === 'error'),
    'an error row from the payload validator',
    noPhoneLogs.length ? `${noPhoneLogs[0].status}: ${String(noPhoneLogs[0].response).slice(0, 120)}` : 'no log row'
  );

  // 3. Wrong API key, driven end-to-end through the plugin.
  await resetCaptures();
  await setOption('vocify_api_key', `vcf_live_${'0'.repeat(64)}`);
  const badKeyOrder = await placeCheckoutOrder();
  await sleep(1500);
  const badKeyCaps = await captures();
  const badKeyLogs = (await pluginLogs()).filter((l) => String(l.order_id) === String(badKeyOrder));
  ok(
    'wrong API key: the plugin sends, and the platform answers 401',
    badKeyCaps.length === 1 && badKeyCaps[0].response_code === 401,
    '1 request, HTTP 401',
    badKeyCaps.length ? `${badKeyCaps.length} request(s), HTTP ${badKeyCaps[0].response_code}` : 'nothing sent'
  );
  ok(
    'wrong API key: the plugin does NOT retry a 4xx',
    badKeyLogs.length === 1,
    '1 log row (client errors are terminal)',
    `${badKeyLogs.length}`
  );
  ok(
    'wrong API key: no Order row was created',
    (await ordersFor(sql, fixture.companyId, String(badKeyOrder))).length === 0,
    '0 orders rows',
    'checked'
  );
  await setOption('vocify_api_key', fixture.apiKey);

  // 4. Domain mismatch, driven end-to-end: proves the plugin reports the
  //    domain it actually detects rather than a hardcoded one.
  await resetCaptures();
  await sql.query(`UPDATE integrations SET store_domain = $2, store_url = $3 WHERE id = $1`, [
    fixture.integrationId,
    'somewhere-else.example.com',
    'https://somewhere-else.example.com',
  ]);
  const badDomainOrder = await placeCheckoutOrder();
  await sleep(1500);
  const badDomainCaps = await captures();
  ok(
    'domain mismatch: the platform answers 403',
    badDomainCaps.length === 1 && badDomainCaps[0].response_code === 403,
    '1 request, HTTP 403',
    badDomainCaps.length ? `HTTP ${badDomainCaps[0].response_code}` : 'nothing sent'
  );
  ok(
    'domain mismatch: no Order row was created',
    (await ordersFor(sql, fixture.companyId, String(badDomainOrder))).length === 0,
    '0 orders rows',
    'checked'
  );
  await sql.query(`UPDATE integrations SET store_domain = $2, store_url = $3 WHERE id = $1`, [
    fixture.integrationId,
    fixture.storeDomain,
    `https://${fixture.storeDomain}`,
  ]);

  return { orderId };
}
