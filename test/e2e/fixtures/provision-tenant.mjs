#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// Provision (or tear down) a throwaway Company + Agent + Integration + ApiKey
// + PackPurchase for one plugin e2e run.
//
// Mirrors `platform/scripts/create-p2-agent.mjs` (raw SQL, no Prisma) and
// `platform/scripts/grant-credits.mjs` (a REAL pack_purchases row, because the
// billing loop spends packs, not `companies.total_credits`).
//
//   node fixtures/provision-tenant.mjs --platform WOOCOMMERCE --domain wc-e2e.vocify.test
//   node fixtures/provision-tenant.mjs --teardown --company <uuid>
//
// Prints one JSON object on stdout; every human-readable line goes to stderr so
// the orchestrator can just JSON.parse() the stdout.
//
// ⚠️ SAFETY — the agent's calling window is set to a window that is CLOSED right
// now, and `--window-check` re-verifies that from the DB after the first order
// lands (`attempts.scheduled_at` must be comfortably in the future). The VPS
// poller claims `scheduled_at <= now()`, so a closed window is what guarantees
// this harness never dials a phone. The number used is a non-routable fake.
// ─────────────────────────────────────────────────────────────────────────────

import crypto from 'node:crypto';
import { connect } from '../lib/platform-db.mjs';

const CALL_CREDIT_COST = 1; // platform/src/lib/billing/credit-balance.ts:21
const PACK_SLUG = 'pack-100';
const GRANT_CREDITS = 25;

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--')
    ? process.argv[i + 1]
    : fallback;
}
const has = (name) => process.argv.includes(`--${name}`);
const log = (...a) => process.stderr.write(a.join(' ') + '\n');

/**
 * A calling window that is closed at `now` and stays closed for hours.
 *
 * Picked relative to the current UTC hour rather than hardcoded, so the harness
 * behaves the same at 02:00 as at 14:00. The window is one hour wide, starting
 * three hours from now — `isWithinCallingWindow()` is false, so
 * `calculateScheduledTime()` returns the NEXT window start, which is at least
 * ~3h away. That is the property the safety check asserts.
 */
function closedWindow(now = new Date()) {
  const startHour = (now.getUTCHours() + 3) % 24;
  const endHour = (startHour + 1) % 24;
  const two = (n) => String(n).padStart(2, '0');
  return { start: `${two(startHour)}:00`, end: `${two(endHour)}:00`, timezone: 'UTC' };
}

async function provision(sql, { platform, domain, tag }) {
  const companyId = crypto.randomUUID();
  const agentId = crypto.randomUUID();
  const integrationId = crypto.randomUUID();
  const apiKeyId = crypto.randomUUID();
  const packPurchaseId = crypto.randomUUID();
  const slug = `e2e-${tag}-${companyId.slice(0, 8)}`;
  const win = closedWindow();

  // The plaintext key is shown once, here; only its SHA-256 is stored, exactly
  // as `POST /api/agents/[id]/api-keys` does.
  const apiKey = `vcf_live_${crypto.randomBytes(32).toString('hex')}`;
  const keyHash = crypto.createHash('sha256').update(apiKey).digest('hex');
  const keyPrefix = apiKey.slice(0, 16);
  // decryptSecret() passes a plaintext value through unchanged (legacy path),
  // which is what every other test script in this workspace relies on.
  const signatureSecret = crypto.randomBytes(32).toString('hex');

  await sql.query('BEGIN');
  try {
    await sql.query(
      `INSERT INTO companies (id, name, slug, subscription_status, subscription_plan,
                              billing_type, total_credits, credits_held, timezone,
                              created_at, updated_at)
       VALUES ($1, $2, $3, 'ACTIVE', 'STARTER', 'PREPAID_PACKS', $4, 0, 'UTC', now(), now())`,
      [companyId, `E2E ${tag} ${slug.slice(-8)}`, slug, GRANT_CREDITS]
    );

    await sql.query(
      `INSERT INTO agents (id, company_id, name, description, type, system_prompt,
                           voice_name, voice_speed, calling_window_start, calling_window_end,
                           timezone, max_call_duration, max_retries_per_order, is_active,
                           flow_spec, record_calls, created_at, updated_at)
       VALUES ($1, $2, $3, $4, 'ORDER_CONFIRMATION', $5,
               'Charon', 1.0, $6, $7, $8, 300, 3, true,
               $9::jsonb, false, now(), now())`,
      [
        agentId,
        companyId,
        `e2e-${tag}-agent`,
        'Throwaway agent for the plugin e2e harness. Never dials: its calling window is deliberately closed.',
        'E2E fixture agent — no call is ever placed by this harness.',
        win.start,
        win.end,
        win.timezone,
        JSON.stringify({ language: 'ar-TN', nodes: {} }),
      ]
    );

    await sql.query(
      `INSERT INTO integrations (id, agent_id, integration_type, platform, platform_name,
                                 store_domain, store_url, config, status, created_at, updated_at)
       VALUES ($1, $2, 'CMS_PLATFORM', $3::"Platform", $4, $5, $6, '{}'::jsonb, 'ACTIVE', now(), now())`,
      [integrationId, agentId, platform, `e2e ${platform}`, domain, `https://${domain}`]
    );

    await sql.query(
      `INSERT INTO api_keys (id, agent_id, name, key_hash, key_prefix, signature_secret,
                             usage_count, is_active, created_at)
       VALUES ($1, $2, 'e2e harness key', $3, $4, $5, 0, true, now())`,
      [apiKeyId, agentId, keyHash, keyPrefix, signatureSecret]
    );

    // A real ACTIVE pack — `reserveCredit`'s spendable predicate sums
    // pack_purchases, not companies.total_credits.
    const pack = await sql.query(
      `SELECT id, "creditAmount", "expiryDays" FROM subscription_packs WHERE slug = $1`,
      [PACK_SLUG]
    );
    if (pack.rows.length !== 1) {
      throw new Error(`subscription_packs has no slug '${PACK_SLUG}' — fixture cannot grant credits`);
    }
    await sql.query(
      `INSERT INTO pack_purchases (id, company_id, pack_id, "creditsAmount", "creditsUsed",
                                   "creditsRemaining", "amountPaid", currency, purchased_at,
                                   expires_at, status, created_at, updated_at)
       VALUES ($1, $2, $3, $4, 0, $4, 0, 'TND', now(), now() + interval '30 days',
               'ACTIVE', now(), now())`,
      [packPurchaseId, companyId, pack.rows[0].id, GRANT_CREDITS]
    );
    await sql.query(
      `INSERT INTO credit_ledger_entries (id, company_id, delta, balance_after, reason,
                                          source_pack_purchase_id, notes, created_at)
       VALUES ($1, $2, $3, $3, 'PACK_PURCHASE', $4, 'plugin e2e harness fixture grant', now())`,
      [crypto.randomUUID(), companyId, GRANT_CREDITS, packPurchaseId]
    );

    await sql.query('COMMIT');
  } catch (err) {
    await sql.query('ROLLBACK').catch(() => {});
    throw err;
  }

  return {
    companyId,
    agentId,
    integrationId,
    apiKeyId,
    packPurchaseId,
    slug,
    apiKey,
    signatureSecret,
    storeDomain: domain,
    platform,
    callingWindow: win,
    creditsGranted: GRANT_CREDITS,
    callCreditCost: CALL_CREDIT_COST,
  };
}

/**
 * Delete everything the fixture created.
 *
 * `credit_ledger_entries` is deleted EXPLICITLY rather than relying on the
 * cascade: `platform/scripts/e2e/lib/db.ts`'s `cleanupCompanies()` omits that
 * table and swallows the resulting FK error, which silently leaves fixture
 * companies behind in live Supabase. The caller asserts the companies row is
 * gone afterwards rather than trusting these counts.
 */
export async function teardown(sql, companyId) {
  const counts = {};
  const statements = [
    ['call_metadata', `DELETE FROM call_metadata WHERE company_id = $1`],
    ['calls', `DELETE FROM calls WHERE company_id = $1`],
    ['attempts', `DELETE FROM attempts WHERE company_id = $1`],
    ['orders', `DELETE FROM orders WHERE company_id = $1`],
    ['credit_ledger_entries', `DELETE FROM credit_ledger_entries WHERE company_id = $1`],
    ['pack_purchases', `DELETE FROM pack_purchases WHERE company_id = $1`],
    ['transactions', `DELETE FROM transactions WHERE company_id = $1`],
    ['invoices', `DELETE FROM invoices WHERE company_id = $1`],
    ['activity_logs', `DELETE FROM activity_logs WHERE company_id = $1`],
    [
      'api_keys',
      `DELETE FROM api_keys WHERE agent_id IN (SELECT id FROM agents WHERE company_id = $1)`,
    ],
    [
      'integrations',
      `DELETE FROM integrations WHERE agent_id IN (SELECT id FROM agents WHERE company_id = $1)`,
    ],
    [
      'retry_configs',
      `DELETE FROM retry_configs WHERE agent_id IN (SELECT id FROM agents WHERE company_id = $1)`,
    ],
    ['dnc', `DELETE FROM dnc WHERE company_id = $1`],
    ['agents', `DELETE FROM agents WHERE company_id = $1`],
    ['company_users', `DELETE FROM company_users WHERE company_id = $1`],
    ['companies', `DELETE FROM companies WHERE id = $1`],
  ];
  for (const [label, stmt] of statements) {
    try {
      const res = await sql.query(stmt, [companyId]);
      counts[label] = res.rowCount ?? 0;
    } catch (err) {
      counts[label] = `ERROR: ${err.message}`;
    }
  }
  const left = await sql.query(`SELECT count(*)::int AS n FROM companies WHERE id = $1`, [companyId]);
  counts._companies_remaining = left.rows[0].n;
  return counts;
}

async function main() {
  const sql = await connect();
  try {
    if (has('teardown')) {
      const companyId = arg('company');
      if (!companyId) throw new Error('--teardown needs --company <uuid>');
      const counts = await teardown(sql, companyId);
      log('teardown:', JSON.stringify(counts));
      if (counts._companies_remaining !== 0) {
        throw new Error(`teardown left ${counts._companies_remaining} companies row(s) behind`);
      }
      process.stdout.write(JSON.stringify(counts) + '\n');
      return;
    }

    const platform = arg('platform', 'WOOCOMMERCE');
    const domain = arg('domain');
    if (!domain) throw new Error('--domain <store domain, no scheme, no port> is required');
    const tag = arg('tag', platform.toLowerCase().slice(0, 2));

    const fixture = await provision(sql, { platform, domain, tag });
    log(`provisioned company ${fixture.companyId} agent ${fixture.agentId}`);
    log(`  store domain   : ${fixture.storeDomain}`);
    log(`  calling window : ${fixture.callingWindow.start}-${fixture.callingWindow.end} ${fixture.callingWindow.timezone} (CLOSED now — nothing dials)`);
    log(`  api key prefix : ${fixture.apiKey.slice(0, 16)}…`);
    process.stdout.write(JSON.stringify(fixture) + '\n');
  } finally {
    await sql.end().catch(() => {});
  }
}

if (import.meta.url === `file:///${process.argv[1].replace(/\\/g, '/')}`) {
  main().catch((err) => {
    log('FATAL:', err.message);
    process.exit(1);
  });
}

export { provision, closedWindow };
