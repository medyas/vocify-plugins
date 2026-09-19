// ─────────────────────────────────────────────────────────────────────────────
// PrestaShop suite.
//
// Positive assertions are downstream of a real order created by
// `PaymentModule::validateOrder()` — the exact method the front-office
// OrderController calls, and the one that fires `actionValidateOrder`. The
// webservice API is deliberately NOT used: it writes order rows without firing
// that hook, so an order created through it would exercise none of the module.
//
// Difference from the WooCommerce suite: PrestaShop's sendWebhook() is a bare
// curl_exec(), so there is no interception point equivalent to WordPress's
// `http_api_debug`. Outcome comes from the module's own log table, and the
// bytes used for the tamper/replay matrix are reproduced by calling the
// module's own transformOrder()/VocifySigner (see ps/capture-request.php).
// That distinction is recorded in the report rather than papered over.
// ─────────────────────────────────────────────────────────────────────────────

import crypto from 'node:crypto';
import { composeExec, sleep } from '../lib/docker.mjs';

const COMPOSE = 'docker-compose.ps.yml';
const SVC = 'prestashop';

const clean = (s) => s.split('\n').filter((l) => l.trim() !== '').join('\n').trim();

async function php(script, args = []) {
  const res = await composeExec(COMPOSE, SVC, ['php', `/e2e/${script}`, ...args.map(String)]);
  if (res.code !== 0) {
    const detail = clean(res.stderr) || clean(res.stdout) || '(no output)';
    throw new Error(`php /e2e/${script} ${args.join(' ')} exited ${res.code}${res.timedOut ? ' (timed out)' : ''}: ${detail}`);
  }
  return clean(res.stdout);
}

const lastJson = (out) => JSON.parse(out.split('\n').filter(Boolean).pop());

const moduleState = async () => lastJson(await php('logs.php'));
const clearLogs = () => php('clear-logs.php');
const setConfig = (key, value) => php('set-config.php', [key, value]);

async function placeOrder(phone) {
  const out = await php('create-order.php', phone === undefined ? [] : [phone]);
  const m = out.match(/PS-ORDER-CREATED order_id=(\d+) reference=(\S+)/);
  if (!m) throw new Error(`order creation failed: ${out}`);
  return { id: m[1], reference: m[2] };
}

const capture = async (idOrder) => lastJson(await php('capture-request.php', [idOrder]));

const sign = (secret, timestamp, body) =>
  crypto.createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');

async function replay(cap, webhookUrl, mutate = (x) => x) {
  const { headers, body } = mutate({ headers: { ...cap.headers }, body: cap.body });
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

const ordersFor = (sql, companyId, externalId) =>
  sql
    .query(
      `SELECT id, external_id, external_platform::text AS platform, agent_id, company_id,
              phone, customer_name, currency, status::text
         FROM orders WHERE company_id = $1 AND external_id = $2`,
      [companyId, externalId]
    )
    .then((r) => r.rows);

const attemptsFor = (sql, orderId) =>
  sql
    .query(
      `SELECT status, attempt_number, credits_held,
              to_char(scheduled_at, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS scheduled_at,
              (scheduled_at > (now() AT TIME ZONE 'utc') + interval '1 hour') AS far_future
         FROM attempts WHERE order_id = $1 ORDER BY attempt_number`,
      [orderId]
    )
    .then((r) => r.rows);

export async function runPrestaShopSuite({ rec, sql, fixture, webhookUrl, notes }) {
  const ok = (name, cond, expected, actual, extra = {}) =>
    cond ? rec.pass(name, { actual, ...extra }) : rec.fail(name, { expected, actual, ...extra });

  notes.push(
    'PrestaShop outcome assertions read the module\'s own `vocify_webhook_logs` table rather than an intercepted request: `VocifyWebhookService::sendWebhook()` is a bare curl_exec() with no filter hook. The tamper/replay bytes are reproduced by calling the module\'s own transformOrder()/VocifySigner with the same inputs sendWebhook() uses.'
  );

  // ═══════════════════════════════════════════════════════════════════════
  rec.group('ps/positive — real order through actionValidateOrder');
  // ═══════════════════════════════════════════════════════════════════════

  await clearLogs();
  const before = (await sql.query(`SELECT credits_held FROM companies WHERE id = $1`, [fixture.companyId])).rows[0];
  const order = await placeOrder();
  await sleep(1500);

  const state = await moduleState();
  const mine = state.logs.filter((l) => String(l.id_order) === String(order.id));
  const codes = mine.map((l) => String(l.http_code));

  ok(
    'module logged a delivery attempt for this order',
    mine.length > 0,
    `>=1 row in vocify_webhook_logs for order ${order.id}`,
    mine.length ? JSON.stringify(mine.map((l) => ({ status: l.status, http: l.http_code }))) : 'no rows — actionValidateOrder did not fire or the module bailed before sending'
  );

  // `some`, not "the newest row": ONE PrestaShop order legitimately produces
  // TWO webhooks back to back. `PaymentModule::validateOrder()` fires
  // `actionValidateOrder` (the 201) and then immediately applies the order
  // state via OrderHistory, firing `actionOrderStatusPostUpdate` — which the
  // module forwards on EVERY status update by design. The second is an
  // idempotent 200. Asserting on the latest row alone reads that 200 as a
  // missing 201.
  ok(
    'module observed HTTP 201 from the platform',
    codes.includes('201'),
    'a log row with http_code 201',
    mine.length ? JSON.stringify(mine.map((l) => ({ status: l.status, http: l.http_code }))) : 'none'
  );
  ok(
    'the immediate follow-up webhook is an idempotent 200, not a second order',
    codes.filter((c) => c === '201').length === 1 && codes.every((c) => c === '201' || c === '200'),
    'exactly one 201, any additional deliveries 200',
    `codes ${JSON.stringify(codes)}`
  );
  notes.push(
    `A single PrestaShop order emits ${mine.length} webhook(s) back to back: \`actionValidateOrder\` (201) and then \`actionOrderStatusPostUpdate\` (200, idempotent), because \`validateOrder()\` applies the order state immediately after firing the first hook and the module forwards every status update. WooCommerce emits one. Not a defect — but it doubles a merchant's webhook volume and is worth a deliberate decision.`
  );
  ok(
    'retry queue is empty for this order',
    !state.failed.some((f) => String(f.id_order) === String(order.id)),
    'no vocify_failed_webhooks row',
    `${state.failed.length} queued row(s) overall`
  );

  // ---- the request the module signs ----
  const cap = await capture(order.id);
  ok(
    'X-Domain is the shop domain the module detected',
    cap.headers['X-Domain'] === fixture.storeDomain,
    fixture.storeDomain,
    String(cap.headers['X-Domain'])
  );
  ok('X-Platform is PRESTASHOP', cap.headers['X-Platform'] === 'PRESTASHOP', 'PRESTASHOP', String(cap.headers['X-Platform']));
  const expectedSig = sign(fixture.signatureSecret, cap.headers['X-Timestamp'], cap.body);
  ok(
    'X-Signature == HMAC-SHA256(secret, `${X-Timestamp}.${rawBody}`) recomputed independently',
    cap.headers['X-Signature'] === expectedSig,
    `${expectedSig.slice(0, 16)}… (Node, platform formula)`,
    `${String(cap.headers['X-Signature']).slice(0, 16)}… (shipped PHP signer)`,
    { suspect: 'prestashop/classes/VocifySigner.php' }
  );
  const bodyOnly = crypto.createHmac('sha256', fixture.signatureSecret).update(cap.body).digest('hex');
  ok(
    'X-Signature is NOT the old body-only digest (regression guard)',
    cap.headers['X-Signature'] !== bodyOnly,
    'a timestamp-bound digest',
    cap.headers['X-Signature'] === bodyOnly ? 'body-only HMAC — the pre-2026-09-19 bug is back' : 'timestamp is bound in'
  );
  const payload = JSON.parse(cap.body);
  ok(
    'createdAt uses the Z designator the platform schema accepts',
    /Z$/.test(payload.createdAt),
    'ISO 8601 ending in Z',
    String(payload.createdAt),
    { suspect: 'prestashop/classes/VocifyPayloadBuilder.php' }
  );
  ok(
    'status is non-empty at actionValidateOrder time',
    typeof payload.status === 'string' && payload.status !== '',
    'a non-empty order status',
    JSON.stringify(payload.status),
    { suspect: 'prestashop/vocifyai.php hookActionValidateOrder' }
  );

  // ---- platform-side state ----
  const orders = await ordersFor(sql, fixture.companyId, String(order.id));
  ok('an Order row exists in Postgres for this shop order', orders.length === 1, `1 orders row with external_id=${order.id}`, `${orders.length}`);

  if (orders.length !== 1) {
    rec.blocked('ps order/attempt field assertions', 'no Order row to assert against');
  } else {
    const o = orders[0];
    ok('Order.external_platform is PRESTASHOP', o.platform === 'PRESTASHOP', 'PRESTASHOP', o.platform);
    ok('Order.agent_id is the provisioned agent', o.agent_id === fixture.agentId, fixture.agentId, o.agent_id);
    ok('Order.phone is the customer phone the module sent', o.phone === '+21600000000', '+21600000000', o.phone);
    ok('Order.customer_name survived the transform', o.customer_name === 'Amina Ben Ali', 'Amina Ben Ali', o.customer_name);
    ok('Order.status is SCHEDULED (a credit was reserved)', o.status === 'SCHEDULED', 'SCHEDULED', o.status);

    const attempts = await attemptsFor(sql, o.id);
    ok('exactly one Attempt row was created', attempts.length === 1, '1 attempts row', `${attempts.length}`);
    if (attempts.length === 1) {
      ok('Attempt.credits_held is the call credit cost', attempts[0].credits_held === fixture.callCreditCost, String(fixture.callCreditCost), String(attempts[0].credits_held));
      ok(
        'SAFETY: Attempt.scheduled_at is far in the future (closed calling window — nothing can dial)',
        attempts[0].far_future === true,
        'scheduled_at > now() + 1 hour',
        attempts[0].scheduled_at,
        { severity: 'critical' }
      );
    }

    const after = (await sql.query(`SELECT credits_held FROM companies WHERE id = $1`, [fixture.companyId])).rows[0];
    ok(
      'companies.credits_held incremented by exactly the reserved cost',
      after.credits_held - before.credits_held === fixture.callCreditCost,
      `+${fixture.callCreditCost}`,
      `${before.credits_held} -> ${after.credits_held}`
    );
  }

  // ═══════════════════════════════════════════════════════════════════════
  rec.group('ps/positive — status change hook + idempotency');
  // ═══════════════════════════════════════════════════════════════════════

  await clearLogs();
  await php('set-status.php', [order.id, 'PS_OS_PREPARATION']);
  await sleep(1500);
  const afterStatus = await moduleState();
  const statusLogs = afterStatus.logs.filter((l) => String(l.id_order) === String(order.id));
  ok(
    'a status change fires a second webhook (actionOrderStatusPostUpdate)',
    statusLogs.length >= 1,
    '>=1 log row from the status hook',
    `${statusLogs.length}`
  );
  ok(
    'the platform treats the repeat as a duplicate (HTTP 200, not a new order)',
    statusLogs.length >= 1 && String(statusLogs[0].http_code) === '200',
    'HTTP 200 "Order already processed"',
    statusLogs.length ? `HTTP ${statusLogs[0].http_code}` : 'nothing sent'
  );
  ok(
    'no duplicate Order row was created',
    (await ordersFor(sql, fixture.companyId, String(order.id))).length === 1,
    '1 orders row',
    'checked'
  );

  // ═══════════════════════════════════════════════════════════════════════
  rec.group('ps/negative — platform rejects tampered module traffic');
  // ═══════════════════════════════════════════════════════════════════════

  const expectReject = async (name, mutate, wantStatus, wantCode, expectedDesc) => {
    const r = await replay(cap, webhookUrl, mutate);
    ok(
      name,
      r.status === wantStatus && (wantCode ? r.body?.code === wantCode : true),
      expectedDesc,
      `HTTP ${r.status} ${r.body?.code ?? ''} ${r.body?.error ?? ''}`.trim()
    );
  };

  await expectReject(
    'tampered body (same signature) is rejected',
    ({ headers, body }) => ({ headers, body: body.replace('"quantity":1', '"quantity":99') }),
    401, 'SIGNATURE_MISMATCH', 'HTTP 401 SIGNATURE_MISMATCH'
  );
  await expectReject(
    'altered X-Timestamp header (signature over the original) is rejected',
    ({ headers, body }) => ({ headers: { ...headers, 'X-Timestamp': new Date(Date.now() - 1000).toISOString() }, body }),
    401, 'SIGNATURE_MISMATCH', 'HTTP 401 SIGNATURE_MISMATCH — proves the timestamp is bound INTO the digest'
  );
  await expectReject(
    'stale timestamp (correctly signed, 6 minutes old) is rejected',
    ({ headers, body }) => {
      const stale = new Date(Date.now() - 6 * 60 * 1000).toISOString();
      return { headers: { ...headers, 'X-Timestamp': stale, 'X-Signature': sign(fixture.signatureSecret, stale, body) }, body };
    },
    401, 'TIMESTAMP_INVALID', 'HTTP 401 TIMESTAMP_INVALID (300s window)'
  );
  await expectReject(
    'wrong API key is rejected',
    ({ headers, body }) => ({ headers: { ...headers, 'X-API-Key': `vcf_live_${'0'.repeat(64)}` }, body }),
    401, undefined, 'HTTP 401'
  );

  const inWindow = await replay(cap, webhookUrl);
  ok(
    'verbatim replay inside the 300s window is idempotent (HTTP 200, no new order)',
    inWindow.status === 200,
    'HTTP 200 "Order already processed"',
    `HTTP ${inWindow.status}`
  );

  // ═══════════════════════════════════════════════════════════════════════
  rec.group('ps/negative — the module refuses to send (no request leaves)');
  // ═══════════════════════════════════════════════════════════════════════

  // PrestaShop's isAllowedWebhookUrl() is stricter than WooCommerce's: https
  // AND a publicly-routable host. Both halves are exercised.
  for (const [label, url, needle] of [
    ['plaintext http://', 'http://insecure.example.com/api/webhooks/ecommerce', /https:\/\/ URL to a public host/i],
    ['https:// to a private address', 'https://192.168.1.10/api/webhooks/ecommerce', /https:\/\/ URL to a public host/i],
    ['https:// to localhost', 'https://localhost/api/webhooks/ecommerce', /https:\/\/ URL to a public host/i],
  ]) {
    await clearLogs();
    await setConfig('VOCIFY_WEBHOOK_URL', url);
    const o = await placeOrder();
    await sleep(1200);
    const st = await moduleState();
    const rows = st.logs.filter((l) => String(l.id_order) === String(o.id));
    ok(
      `SSRF guard: ${label} is refused locally`,
      rows.some((r) => r.status === 'error' && needle.test(String(r.response) + String(r.error_message))),
      'an error row naming the https/public-host requirement, and no delivery',
      rows.length ? `${rows[0].status}: ${String(rows[0].response).slice(0, 140)}` : 'no log row',
      { severity: 'critical' }
    );
    ok(
      `SSRF guard: ${label} — no Order reached the platform`,
      (await ordersFor(sql, fixture.companyId, String(o.id))).length === 0,
      '0 orders rows',
      'checked'
    );
  }
  await setConfig('VOCIFY_WEBHOOK_URL', webhookUrl);

  // Wrong API key, driven end-to-end through the module.
  await clearLogs();
  await setConfig('VOCIFY_API_KEY', `vcf_live_${'0'.repeat(64)}`);
  const badKeyOrder = await placeOrder();
  await sleep(1500);
  const badKeyState = await moduleState();
  const badKeyLogs = badKeyState.logs.filter((l) => String(l.id_order) === String(badKeyOrder.id));
  ok(
    'wrong API key: the module sends, and the platform answers 401',
    badKeyLogs.some((l) => String(l.http_code) === '401'),
    'a log row with http_code 401',
    badKeyLogs.length ? `http_code=${badKeyLogs[0].http_code}` : 'nothing sent'
  );
  ok(
    'wrong API key: no Order row was created',
    (await ordersFor(sql, fixture.companyId, String(badKeyOrder.id))).length === 0,
    '0 orders rows',
    'checked'
  );
  await setConfig('VOCIFY_API_KEY', fixture.apiKey);

  // Domain mismatch, driven end-to-end.
  await clearLogs();
  await sql.query(`UPDATE integrations SET store_domain = $2, store_url = $3 WHERE id = $1`, [
    fixture.integrationId, 'somewhere-else.example.com', 'https://somewhere-else.example.com',
  ]);
  const badDomainOrder = await placeOrder();
  await sleep(1500);
  const badDomainState = await moduleState();
  const badDomainLogs = badDomainState.logs.filter((l) => String(l.id_order) === String(badDomainOrder.id));
  ok(
    'domain mismatch: the platform answers 403',
    badDomainLogs.some((l) => String(l.http_code) === '403'),
    'a log row with http_code 403',
    badDomainLogs.length ? `http_code=${badDomainLogs[0].http_code}` : 'nothing sent'
  );
  ok(
    'domain mismatch: no Order row was created',
    (await ordersFor(sql, fixture.companyId, String(badDomainOrder.id))).length === 0,
    '0 orders rows',
    'checked'
  );
  await sql.query(`UPDATE integrations SET store_domain = $2, store_url = $3 WHERE id = $1`, [
    fixture.integrationId, fixture.storeDomain, `https://${fixture.storeDomain}`,
  ]);

  return { orderId: order.id };
}
