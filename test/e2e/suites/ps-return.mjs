// ─────────────────────────────────────────────────────────────────────────────
// PrestaShop RETURN-PATH suite — platform → shop.
//
// The PrestaShop counterpart of `suites/wc-return.mjs`. `suites/ps.mjs` proves
// an order placed in a real shop reaches the platform; this proves the call's
// outcome gets back to the merchant, by asserting the order's `current_state`
// **in PrestaShop's own MySQL**, not by trusting the receiver's own reply.
//
// The chain under test:
//
//   POST {store}/index.php?fc=module&module=vocifyai&controller=webhook
//     → PrestaShop Dispatcher (fc=module)
//     → VocifyAIWebhookModuleFrontController        (controllers/front/webhook.php)
//     → VocifyStatusReceiver                        (HMAC, freshness, idempotency)
//     → OrderHistory::changeIdOrderState()
//     → ps_orders.current_state / ps_order_history
//
// ## What this suite proves, and what it does not
//
// It exercises everything on the SHOP side of the wire: that PrestaShop's real
// dispatcher routes to the module front controller, that the HMAC verifies
// against really-transmitted bytes over real HTTP through Apache, that the
// whole rejection matrix answers the right status codes, and that the order
// moves in the shop's own database.
//
// It does NOT prove the platform can reach a merchant over the internet. That
// leg needs a public https origin and is what `docker-compose.rt.yml`'s
// cloudflared tunnel provides on the WooCommerce side. Requests here are made
// from inside the container by `ps/post-result.php`, against the shop's own
// canonical domain pinned to loopback — see that file for why `localhost` is
// not usable.
//
// NO PHONE IS DIALLED and NO PLATFORM DATABASE ROW IS TOUCHED. Unlike
// `wc-return.mjs`, this suite never writes a `calls` or `attempts` row: it
// posts the call result to the shop directly, the way the platform's outbound
// adapter would. There is nothing here that could become a dialable attempt.
// ─────────────────────────────────────────────────────────────────────────────

import { compose } from '../lib/docker.mjs';

const COMPOSE = 'docker-compose.ps-return.yml';
const SHOP = 'prestashop';
const DB = 'db';

/** PrestaShop's built-in order-state pointers, as installed. */
export const STATE = { PAYMENT: 2, PREPARATION: 3, SHIPPED: 4, DELIVERED: 5, CANCELED: 6 };

const clean = (s) =>
  s
    .split('\n')
    .filter((l) => !/Using a password on the command line|Deprecated:|^\s*$/.test(l))
    .join('\n')
    .trim();

/**
 * Run a command in a compose service as a chosen user.
 *
 * ⚠️ PHP here runs as **www-data**, not root, and that is not cosmetic.
 * Booting PrestaShop from a root CLI creates `var/cache/prod/` owned by root;
 * Apache then runs as www-data, cannot write the compiled Symfony container
 * into it, and EVERY front-office request dies with
 * `Cannot rename "/tmp/FrontContainer.php…"` → HTTP 500. Measured here: the
 * first push after a root-run helper answered 500 with nothing wrong in the
 * module at all. The inbound suites never hit this because they make no HTTP
 * request to the shop.
 */
const execAs = (service, user, argv, opts = {}) =>
  compose(COMPOSE, ['exec', '-T', '-u', user, service, ...argv], opts);

async function php(script, args = []) {
  const res = await execAs(SHOP, 'www-data', ['php', script, ...args]);
  if (res.code !== 0) {
    throw new Error(`php ${script} failed: ${clean(res.stderr) || clean(res.stdout)}`);
  }
  return clean(res.stdout);
}

/** Read straight out of MySQL — the merchant's data on disk. */
async function mysql(query) {
  const res = await execAs(DB, 'root', [
    'sh',
    '-c',
    `mysql -uprestashop -pprestashop prestashop -N -B -e "${query.replace(/\n\s*/g, ' ')}" 2>/dev/null`,
  ]);
  if (res.code !== 0) throw new Error(`mysql read failed: ${res.stderr.trim()}`);
  return clean(res.stdout);
}

/**
 * The order's state read from `ps_orders` — not from `new Order()` in the same
 * PHP process that may have just written it, and not from the receiver's own
 * JSON reply. This is what the headline claim rests on.
 */
const orderState = async (id) =>
  Number(await mysql(`SELECT current_state FROM ps_orders WHERE id_order = ${id}`));

const noteFor = (id, callSid) =>
  mysql(`SELECT note FROM ps_vocify_call_results WHERE id_order = ${id} AND call_sid = '${callSid}'`);

const resultRowsFor = async (id, callSid) =>
  Number(
    await mysql(
      `SELECT COUNT(*) FROM ps_vocify_call_results WHERE id_order = ${id} AND call_sid = '${callSid}'`
    )
  );

const webhookLogCount = async (id) =>
  Number(await mysql(`SELECT COUNT(*) FROM ps_vocify_webhook_logs WHERE id_order = ${id}`));

const setConfig = (key, value) => php('/e2e/set-config.php', [key, value]);

/** POST one call result through Apache and PrestaShop's real dispatcher. */
async function post(spec) {
  const out = await php('/e2e/post-result.php', [
    Buffer.from(JSON.stringify(spec), 'utf8').toString('base64'),
  ]);
  const line = out.split('\n').filter(Boolean).pop() ?? '';
  try {
    return JSON.parse(line);
  } catch {
    throw new Error(`expected JSON from post-result.php, got: ${out.slice(0, 300)}`);
  }
}

/** Place a real order through PaymentModule::validateOrder(). */
async function createOrder() {
  const out = await php('/e2e/create-order.php', ['+21600000000']);
  const m = out.match(/PS-ORDER-CREATED order_id=(\d+)/);
  if (!m) throw new Error(`order fixture failed: ${out.slice(0, 400)}`);
  return Number(m[1]);
}

/**
 * Body-only HMAC, computed inside the container, so the negative case can use
 * the merchant's real secret without it ever appearing in a harness variable.
 */
async function bodyOnlyHmac(rawBody) {
  const res = await execAs(SHOP, 'www-data', [
    'php',
    '-r',
    'require "/var/www/html/config/config.inc.php"; echo hash_hmac("sha256", base64_decode($argv[1]), Configuration::get("VOCIFY_SIGNATURE_SECRET"));',
    '--',
    Buffer.from(rawBody, 'utf8').toString('base64'),
  ]);
  return clean(res.stdout);
}

/**
 * Render the module's back-office order panel through PrestaShop's own
 * `Hook::exec`, and return the HTML.
 *
 * ⚠️ This assertion exists because the panel is the ONLY place a merchant sees
 * a call result — this module writes no `orders.note` and no `CustomerMessage`.
 * A row in `vocify_call_results` that never reaches a page satisfies every
 * other assertion in this suite while leaving the merchant with nothing, and
 * that is not hypothetical: until 2026-09-19 the module hooked only
 * `displayAdminOrderLeft`, which PrestaShop deprecated in 1.7.7.0 and 8.x
 * dispatches NOWHERE (`grep -rl displayAdminOrderLeft` over the whole install
 * matches only `classes/Hook.php`'s deprecation list). The panel had been
 * invisible on every 8.x shop since it was written.
 *
 * `displayAdminOrderSide` is what the order page really renders —
 * `src/PrestaShopBundle/Resources/views/Admin/Sell/Order/Order/view.html.twig:63`,
 * with the same `{'id_order': …}` params.
 */
async function renderOrderPanel(orderId, hook = 'displayAdminOrderSide') {
  const res = await execAs(SHOP, 'www-data', [
    'php',
    '-r',
    'require "/var/www/html/config/config.inc.php";' +
      '$c = Context::getContext(); $c->employee = new Employee(1);' +
      '$c->language = new Language((int)Configuration::get("PS_LANG_DEFAULT"));' +
      'echo Hook::exec($argv[1], array("id_order" => (int)$argv[2]));',
    '--',
    hook,
    String(orderId),
  ]);
  return res.stdout;
}

const iso = (offsetSeconds = 0) => new Date(Date.now() + offsetSeconds * 1000).toISOString();

// ---- the suite -------------------------------------------------------------

export async function runPrestaShopReturnSuite({ rec, secret, notes }) {
  rec.group('return path — platform → PrestaShop');

  // Every callSid this suite sends is prefixed with a per-run token.
  //
  // ⚠️ `vocify_call_results.call_sid` is UNIQUE — that index is what makes a
  // replayed push a no-op — and it is GLOBAL, not per-order. With fixed sids a
  // second run against the same shop (`--reuse`) answers every push
  // "Already applied (duplicate call result)" and 15 assertions fail for a
  // reason that has nothing to do with the module. Measured, on the first
  // re-run. A cold run never sees it, which is exactly what makes it worth
  // pinning here rather than in the driver.
  const run = Math.random().toString(36).slice(2, 8);
  const sid = (name) => `rt-${run}-${name}`;

  notes.push(
    "The PrestaShop return-path suite posts from INSIDE the shop container against the shop's own canonical domain (pinned to loopback with CURLOPT_RESOLVE), so it proves the shop half of the wire only. It does NOT prove the platform can reach a merchant over the internet — that needs a public https origin, as docker-compose.rt.yml provides for WooCommerce."
  );
  notes.push(
    'No platform database row is written by this suite at all: call results are posted to the shop directly, the way the platform adapter would. Nothing here can become a dialable attempt.'
  );

  // ── 0. the endpoint exists and the dispatcher routes to it ───────────────
  // Before 2026-09-19 it did not: `controllers/front/` held only cron.php, so
  // this answered PrestaShop's 404 page and no PrestaShop order ever moved.
  const unsigned = await post({ body: { orderId: '0' }, omitSignature: true });
  rec.record({
    name: 'the module ships a front controller the PrestaShop dispatcher routes to',
    // `>= 200` matters: curl reports a transport failure as HTTP 0, and
    // "not a 404" would otherwise be satisfied by the shop not answering at
    // all. The first run of this suite recorded two spurious results that way,
    // before the driver grew its front-office warm-up.
    outcome: unsigned.http >= 200 && unsigned.http !== 404 && unsigned.http < 500 ? 'PASS' : 'FAIL',
    expected: 'a real HTTP status that is neither a 404 page nor a 5xx',
    actual: `HTTP ${unsigned.http} ${JSON.stringify(unsigned.body)}${unsigned.error ? ` curl: ${unsigned.error}` : ''}`,
    suspect:
      unsigned.http === 404
        ? 'controllers/front/webhook.php is missing — see plugins/PROGRESS.md §10'
        : undefined,
    severity: 'critical',
  });
  rec.record({
    name: 'an unsigned push is rejected (fail-closed)',
    outcome: unsigned.http === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${unsigned.http} ${unsigned.body?.code ?? ''}`,
    severity: 'critical',
  });

  // A negative control for the routing assertion: an unknown controller name
  // must NOT resolve, otherwise "not a 404" would prove nothing at all.
  const bogus = await post({
    body: {},
    omitSignature: true,
    query: 'fc=module&module=vocifyai&controller=definitely-not-a-controller',
  });
  rec.record({
    name: 'CONTROL: an unknown module controller does NOT resolve',
    outcome: bogus.http >= 200 && bogus.http !== 401 && bogus.http !== 200 ? 'PASS' : 'FAIL',
    expected: 'not the receiver — proving the route above was matched by controller name',
    actual: `HTTP ${bogus.http}`,
  });

  const getRequest = await post({ body: {}, method: 'GET', omitSignature: true });
  rec.record({
    name: 'GET is refused; this endpoint is POST-only',
    outcome: getRequest.http === 405 ? 'PASS' : 'FAIL',
    expected: 'HTTP 405',
    actual: `HTTP ${getRequest.http} ${getRequest.body?.code ?? ''}`,
  });

  // ── 1. a real order, placed through the shop's own checkout code ─────────
  const orderId = await createOrder();
  const startState = await orderState(orderId);
  rec.record({
    name: 'a real order exists to move, created through PaymentModule::validateOrder()',
    outcome: orderId > 0 && startState > 0 ? 'PASS' : 'FAIL',
    expected: 'an order in a non-zero state',
    actual: `order ${orderId} current_state=${startState}`,
    severity: 'critical',
  });

  // ── 2. DECISION 1 — one order must emit ONE outbound webhook, not two ────
  //
  // `PaymentModule::validateOrder()` fires `actionValidateOrder` and then
  // applies the same state a few lines later, firing
  // `actionOrderStatusPostUpdate`. The module used to forward both, doubling
  // every merchant's webhook volume for no information (PROGRESS.md §8,
  // "Observations recorded, deliberately NOT fixed").
  const afterCreate = await webhookLogCount(orderId);
  rec.record({
    name: 'DECISION 1: creating one order emits exactly ONE outbound webhook, not two',
    outcome: afterCreate === 1 ? 'PASS' : 'FAIL',
    expected: '1 row in ps_vocify_webhook_logs',
    actual: `${afterCreate} row(s)`,
    request:
      'validateOrder() fires actionValidateOrder AND actionOrderStatusPostUpdate for the same state',
    severity: 'critical',
  });

  // The hook that used to produce the duplicate must still be LIVE, or the
  // assertion above would pass for the wrong reason — a dead hook and a
  // suppressed one look identical from the log table.
  //
  // PS_OS_ERROR, and not PS_OS_SHIPPING: crossing the `shipped` boundary makes
  // OrderHistory::changeIdOrderState() record a stock movement, and
  // `set-status.php` boots the Symfony kernel, so that insert runs and dies on
  // `Column 'id_employee' cannot be null` — there is no employee in a CLI
  // context. Measured. (The RECEIVER is unaffected: from a front controller
  // SymfonyContainer::getInstance() is null and StockManager::saveMovement()
  // returns early, so a merchant mapping an outcome onto Shipped/Delivered
  // works — separately measured, order 2 -> 5, HTTP 200 changed:true.)
  await php('/e2e/set-status.php', [String(orderId), 'PS_OS_ERROR']);
  const afterShip = await webhookLogCount(orderId);
  rec.record({
    name: 'CONTROL: a genuinely different status change still DOES emit a webhook',
    outcome: afterShip === afterCreate + 1 ? 'PASS' : 'FAIL',
    expected: `${afterCreate + 1} rows — the duplicate is suppressed, real transitions are not`,
    actual: `${afterShip} row(s)`,
    suspect:
      afterShip === afterCreate
        ? 'the de-duplication is too broad and swallows real transitions'
        : undefined,
    severity: 'critical',
  });

  const stateBeforeMatrix = await orderState(orderId);

  // ── 3. the negative matrix, against the shipped receiver ─────────────────
  const fresh = (status, callSid, completedAt = iso(-30)) => ({
    orderId: String(orderId),
    status,
    callData: { callSid, duration: 42, completedAt, notes: 'e2e' },
  });

  const wrongSecret = await post({
    body: fresh('cancelled', sid('neg-wrong-secret')),
    secret: 'not-the-secret',
  });
  rec.record({
    name: 'a signature made with the wrong secret is rejected',
    outcome: wrongSecret.http === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${wrongSecret.http} ${wrongSecret.body?.code ?? ''}`,
    severity: 'critical',
  });

  // The pre-2026-09-19 shape: HMAC over the body alone, timestamp riding
  // alongside and unauthenticated — which makes the freshness window
  // decorative, since a captured request replays forever with a fresh one.
  const bodyOnlyRaw = JSON.stringify(fresh('cancelled', sid('neg-body-only')));
  const bodyOnly = await post({
    body: bodyOnlyRaw,
    signature: await bodyOnlyHmac(bodyOnlyRaw),
  });
  rec.record({
    name: 'a body-only signature (unbound timestamp) is rejected',
    outcome: bodyOnly.http === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401 — the timestamp must be inside the signed message',
    actual: `HTTP ${bodyOnly.http} ${bodyOnly.body?.code ?? ''}`,
    severity: 'critical',
  });

  const stale = await post({
    body: fresh('cancelled', sid('neg-stale')),
    timestamp: new Date(Date.now() - 6 * 60 * 1000).toISOString(),
  });
  rec.record({
    name: 'a correctly signed push with a 6-minute-old timestamp is rejected',
    outcome: stale.http === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${stale.http} ${stale.body?.code ?? ''}`,
  });

  const future = await post({
    body: fresh('cancelled', sid('neg-future')),
    timestamp: new Date(Date.now() + 6 * 60 * 1000).toISOString(),
  });
  rec.record({
    name: 'the freshness window is two-sided: a 6-minute-future timestamp is rejected too',
    outcome: future.http === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${future.http} ${future.body?.code ?? ''}`,
  });

  const noTs = await post({ body: fresh('cancelled', sid('neg-no-ts')), omitTimestamp: true });
  rec.record({
    name: 'a push with no timestamp header is rejected',
    outcome: noTs.http === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${noTs.http} ${noTs.body?.code ?? ''}`,
  });

  const badTs = await post({ body: fresh('cancelled', sid('neg-bad-ts')), timestamp: 'not-a-date' });
  rec.record({
    name: 'an unparsable timestamp is rejected',
    outcome: badTs.http === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${badTs.http} ${badTs.body?.code ?? ''}`,
  });

  const originalRaw = JSON.stringify(fresh('cancelled', sid('neg-tampered')));
  const tampered = await post({
    body: originalRaw.replace('"cancelled"', '"confirmed"'),
    signBody: originalRaw,
  });
  rec.record({
    name: 'a body altered after signing is rejected',
    outcome: tampered.http === 401 ? 'PASS' : 'FAIL',
    expected: 'HTTP 401',
    actual: `HTTP ${tampered.http} ${tampered.body?.code ?? ''}`,
    severity: 'critical',
  });

  const notJson = await post({ body: 'not json at all' });
  rec.record({
    name: 'a correctly signed body that is not JSON is a 400, not a 500',
    outcome: notJson.http === 400 ? 'PASS' : 'FAIL',
    expected: 'HTTP 400',
    actual: `HTTP ${notJson.http} ${notJson.body?.code ?? ''}`,
  });

  const incomplete = await post({ body: { orderId: String(orderId) } });
  rec.record({
    name: 'a payload with no status is a 400',
    outcome: incomplete.http === 400 ? 'PASS' : 'FAIL',
    expected: 'HTTP 400',
    actual: `HTTP ${incomplete.http} ${incomplete.body?.code ?? ''}`,
  });

  const unknown = await post({
    body: { ...fresh('confirmed', sid('neg-unknown')), orderId: '99999999' },
  });
  rec.record({
    name: 'an order the shop does not have answers 404, not 500',
    outcome: unknown.http === 404 ? 'PASS' : 'FAIL',
    expected: 'HTTP 404',
    actual: `HTTP ${unknown.http} ${unknown.body?.code ?? ''}`,
    severity: 'critical',
  });

  // Fail closed. Clear the merchant's signing secret for one request: a shop
  // with no secret must refuse everything rather than accept unsigned pushes.
  await setConfig('VOCIFY_SIGNATURE_SECRET', '');
  const unconfigured = await post({ body: fresh('confirmed', sid('neg-no-secret')), secret: '' });
  await setConfig('VOCIFY_SIGNATURE_SECRET', secret);
  rec.record({
    name: 'the receiver fails closed when the merchant has no signing secret configured',
    outcome: unconfigured.http === 503 ? 'PASS' : 'FAIL',
    expected: 'HTTP 503 — never "no secret configured means no check"',
    actual: `HTTP ${unconfigured.http} ${unconfigured.body?.code ?? ''}`,
    severity: 'critical',
  });

  const stateAfterMatrix = await orderState(orderId);
  rec.record({
    name: 'REGRESSION GUARD: none of the rejected pushes moved the order',
    outcome: stateAfterMatrix === stateBeforeMatrix ? 'PASS' : 'FAIL',
    expected: `current_state still ${stateBeforeMatrix} after 11 rejected requests`,
    actual: `current_state=${stateAfterMatrix}`,
    severity: 'critical',
  });

  // ── 4. THE HEADLINE: a confirmed call moves the order ────────────────────
  const confirmedAt = iso(-300);
  const confirmed = await post({ body: fresh('confirmed', sid('rt-confirmed'), confirmedAt) });
  const afterConfirmed = await orderState(orderId);
  rec.record({
    name: "THE HEADLINE: the order's state changed in PrestaShop's own database",
    outcome:
      confirmed.http === 200 &&
      confirmed.body?.changed === true &&
      afterConfirmed === STATE.PREPARATION
        ? 'PASS'
        : 'FAIL',
    expected: `HTTP 200 changed:true, ps_orders.current_state = ${STATE.PREPARATION} (PS_OS_PREPARATION)`,
    actual: `HTTP ${confirmed.http} ${JSON.stringify(confirmed.body)}, current_state=${afterConfirmed}`,
    request: "read straight from ps_orders, not from the receiver's reply",
    severity: 'critical',
  });

  const historyRows = Number(
    await mysql(
      `SELECT COUNT(*) FROM ps_order_history WHERE id_order = ${orderId} AND id_order_state = ${STATE.PREPARATION}`
    )
  );
  rec.record({
    name: 'the change is a real OrderHistory transition, not a raw column write',
    outcome: historyRows >= 1 ? 'PASS' : 'FAIL',
    expected: 'a ps_order_history row for the new state',
    actual: `${historyRows} row(s)`,
  });

  const note = await noteFor(orderId, sid('rt-confirmed'));
  rec.record({
    name: 'the merchant gets a note saying what happened on the call',
    outcome: note.includes('CONFIRMED') && note.includes(sid('rt-confirmed')) ? 'PASS' : 'FAIL',
    expected: 'a stored note naming the outcome and the call id',
    actual: note.slice(0, 160) || '(no note)',
  });

  // ...and that note actually reaches a page the merchant looks at. See
  // renderOrderPanel(): the stored row is not the deliverable, the rendered
  // panel is, and the hook this module used to rely on renders nothing on 8.x.
  const panel = await renderOrderPanel(orderId);
  rec.record({
    name: 'THE NOTE IS VISIBLE: the order page panel renders the call result',
    outcome:
      panel.includes('Call Results') && panel.includes('CONFIRMED') && panel.includes(sid('rt-confirmed'))
        ? 'PASS'
        : 'FAIL',
    expected: "Hook::exec('displayAdminOrderSide') returns the panel, naming the outcome and call id",
    actual: panel.length ? `${panel.length} bytes, outcome ${panel.includes('CONFIRMED') ? 'present' : 'ABSENT'}` : '(empty — the hook rendered nothing)',
    suspect: panel.length
      ? undefined
      : 'the module is hooked only on displayAdminOrderLeft, which PrestaShop 8 dispatches nowhere',
    severity: 'critical',
  });
  rec.record({
    name: 'CONTROL: the legacy displayAdminOrderLeft hook is still wired for 1.7.x shops',
    outcome: (await renderOrderPanel(orderId, 'displayAdminOrderLeft')).includes('Call Results')
      ? 'PASS'
      : 'FAIL',
    expected: 'the same panel — registered for 1.7.0-1.7.6, where it is the live hook',
    actual: `${(await renderOrderPanel(orderId, 'displayAdminOrderLeft')).length} bytes`,
  });

  // ── 5. re-entrancy — applying a result must not echo back out ────────────
  //
  // OrderHistory::changeIdOrderState() fires actionOrderStatusPostUpdate,
  // which this module hooks. Without the guard the shop posts the result
  // straight back to the platform that just sent it, with a 3×-backoff retry
  // loop behind it, for every call result received.
  const logsAfterApply = await webhookLogCount(orderId);
  rec.record({
    name: 'applying an inbound call result does NOT fire an outbound webhook back at the platform',
    outcome: logsAfterApply === afterShip ? 'PASS' : 'FAIL',
    expected: `${afterShip} rows — unchanged by the status change the receiver just made`,
    actual: `${logsAfterApply} row(s)`,
    suspect:
      logsAfterApply > afterShip
        ? 'VocifyAI::$suppressOutboundWebhooks is not covering changeIdOrderState()'
        : undefined,
    severity: 'critical',
  });

  // ── 6. idempotency ───────────────────────────────────────────────────────
  const replay = await post({ body: fresh('cancelled', sid('rt-confirmed'), confirmedAt) });
  const afterReplay = await orderState(orderId);
  rec.record({
    name: 'a repeat of the same callSid is a no-op, even with a different status',
    outcome:
      replay.http === 200 && replay.body?.changed === false && afterReplay === STATE.PREPARATION
        ? 'PASS'
        : 'FAIL',
    expected: 'HTTP 200 changed:false, state unchanged',
    actual: `HTTP ${replay.http} ${JSON.stringify(replay.body)}, current_state=${afterReplay}`,
    severity: 'critical',
  });
  rec.record({
    name: 'the replay did not record a second call-result row',
    outcome: (await resultRowsFor(orderId, sid('rt-confirmed'))) === 1 ? 'PASS' : 'FAIL',
    expected: 'exactly one row for callSid rt-confirmed',
    actual: `${await resultRowsFor(orderId, sid('rt-confirmed'))} row(s)`,
  });

  // ── 7. a cancellation really cancels ─────────────────────────────────────
  const cancel = await post({ body: fresh('cancelled', sid('rt-cancelled'), iso(-200)) });
  const afterCancel = await orderState(orderId);
  rec.record({
    name: "outcome 'cancelled' cancels the order in the shop",
    outcome: cancel.http === 200 && afterCancel === STATE.CANCELED ? 'PASS' : 'FAIL',
    expected: `HTTP 200, current_state = ${STATE.CANCELED} (PS_OS_CANCELED)`,
    actual: `HTTP ${cancel.http}, current_state=${afterCancel}`,
    severity: 'critical',
  });

  // ── 8. an outcome with no purchase-intent meaning ────────────────────────
  const noAnswer = await post({ body: fresh('no_answer', sid('rt-no-answer'), iso(-100)) });
  const afterNoAnswer = await orderState(orderId);
  rec.record({
    name: "an outcome with no purchase-intent meaning ('no_answer') notes but does not move the order",
    outcome:
      noAnswer.http === 200 &&
      noAnswer.body?.changed === false &&
      afterNoAnswer === STATE.CANCELED
        ? 'PASS'
        : 'FAIL',
    expected: `HTTP 200 changed:false, state unchanged at ${STATE.CANCELED}`,
    actual: `HTTP ${noAnswer.http} ${JSON.stringify(noAnswer.body)}, current_state=${afterNoAnswer}`,
  });
  rec.record({
    name: "a 'no_answer' is still recorded for the merchant to see",
    outcome: (await noteFor(orderId, sid('rt-no-answer'))).includes('NO ANSWER') ? 'PASS' : 'FAIL',
    expected: 'a stored note naming the outcome',
    actual: (await noteFor(orderId, sid('rt-no-answer'))).slice(0, 160) || '(no note)',
  });
  rec.record({
    name: "the 'no_answer' note reaches the order page, not just the database",
    outcome: (await renderOrderPanel(orderId)).includes('NO ANSWER') ? 'PASS' : 'FAIL',
    expected: 'the rendered panel names the no_answer outcome',
    actual: (await renderOrderPanel(orderId)).includes('NO ANSWER') ? 'present' : 'ABSENT from the panel',
    severity: 'critical',
  });

  // ── 9. ordering ──────────────────────────────────────────────────────────
  //
  // A DIFFERENT call whose result is older than one already applied. An order
  // can have several calls (a retry makes a second Attempt and a second Call)
  // and the platform retries a failed sync five times, so a delayed retry of
  // the FIRST call can land after the second has been applied. Deduping on
  // callSid alone does not catch it: the sid is new.
  const stalePush = await post({ body: fresh('confirmed', sid('rt-old-call'), iso(-3600)) });
  const afterStalePush = await orderState(orderId);
  rec.record({
    name: 'an older result from a DIFFERENT call cannot rewind the order',
    outcome:
      stalePush.http === 200 &&
      stalePush.body?.changed === false &&
      afterStalePush === STATE.CANCELED
        ? 'PASS'
        : 'FAIL',
    expected: `HTTP 200 changed:false, state still ${STATE.CANCELED}`,
    actual: `HTTP ${stalePush.http} ${JSON.stringify(stalePush.body)}, current_state=${afterStalePush}`,
    severity: 'critical',
  });

  // ── 10. DECISION 2 — the mapping is by state ID, not by localised name ───
  //
  // PrestaShop order states live in `order_state_lang`: the names are
  // per-language and a merchant can rename them at will. Rename the state the
  // receiver targets, and a receiver that matched on the display name stops
  // working. One that resolves by id does not notice.
  const renamedOrder = await createOrder();
  await mysql(
    `UPDATE ps_order_state_lang SET name = 'Rebaptise par le marchand' WHERE id_order_state = ${STATE.PREPARATION}`
  );
  const afterRename = await post({
    body: {
      orderId: String(renamedOrder),
      status: 'confirmed',
      callData: { callSid: sid('rt-renamed-state'), duration: 9, completedAt: iso(-30) },
    },
  });
  const renamedState = await orderState(renamedOrder);
  await mysql(
    `UPDATE ps_order_state_lang SET name = 'Processing in progress' WHERE id_order_state = ${STATE.PREPARATION} AND name = 'Rebaptise par le marchand'`
  );
  rec.record({
    name: "DECISION 2: renaming the target order status does not break the mapping",
    outcome: afterRename.http === 200 && renamedState === STATE.PREPARATION ? 'PASS' : 'FAIL',
    expected: `HTTP 200, current_state = ${STATE.PREPARATION} even though its display name changed`,
    actual: `HTTP ${afterRename.http} ${JSON.stringify(afterRename.body)}, current_state=${renamedState}`,
    request: `UPDATE ps_order_state_lang SET name='Rebaptise par le marchand' WHERE id_order_state=${STATE.PREPARATION}`,
    severity: 'critical',
  });

  // And the merchant can repoint the mapping without touching code.
  const repointedOrder = await createOrder();
  await setConfig('VOCIFY_STATE_CONFIRMED', String(STATE.DELIVERED));
  const repointed = await post({
    body: {
      orderId: String(repointedOrder),
      status: 'confirmed',
      callData: { callSid: sid('rt-repointed'), duration: 9, completedAt: iso(-30) },
    },
  });
  const repointedState = await orderState(repointedOrder);
  await setConfig('VOCIFY_STATE_CONFIRMED', String(STATE.PREPARATION));
  rec.record({
    name: 'DECISION 2: the outcome → status mapping is merchant-configurable',
    outcome: repointed.http === 200 && repointedState === STATE.DELIVERED ? 'PASS' : 'FAIL',
    expected: `current_state = ${STATE.DELIVERED} after pointing VOCIFY_STATE_CONFIRMED at it`,
    actual: `HTTP ${repointed.http}, current_state=${repointedState}`,
  });

  // A configured state that no longer exists must not 500, and must not move
  // the order somewhere arbitrary — it falls back to PrestaShop's own pointer.
  const orphanOrder = await createOrder();
  await setConfig('VOCIFY_STATE_CONFIRMED', '99999');
  const orphaned = await post({
    body: {
      orderId: String(orphanOrder),
      status: 'confirmed',
      callData: { callSid: sid('rt-deleted-state'), duration: 9, completedAt: iso(-30) },
    },
  });
  const orphanState = await orderState(orphanOrder);
  await setConfig('VOCIFY_STATE_CONFIRMED', String(STATE.PREPARATION));
  rec.record({
    name: 'a configured status that no longer exists falls back instead of failing',
    outcome: orphaned.http === 200 && orphanState === STATE.PREPARATION ? 'PASS' : 'FAIL',
    expected: `HTTP 200 and the PS_OS_PREPARATION fallback (${STATE.PREPARATION})`,
    actual: `HTTP ${orphaned.http} ${JSON.stringify(orphaned.body)}, current_state=${orphanState}`,
  });
}
