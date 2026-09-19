// ─────────────────────────────────────────────────────────────────────────────
// Two ways to make the platform push completed calls to the shop.
//
//   runLocalSyncTick()    — the platform's own `syncCompletedOrders()` from the
//                           WORKING TREE, in a subprocess.
//   runDeployedCronTick() — `POST /api/internal/sync-ecommerce` on the deployed
//                           platform, i.e. whatever build is live on the VPS.
//
// Both are real: same database, same adapters, same HTTPS to the shop. They
// differ only in *which build of the platform* runs, which matters whenever a
// fix has not been deployed yet. The suite reports the two separately rather
// than blurring them into one "the sync works".
// ─────────────────────────────────────────────────────────────────────────────

import { resolve } from 'node:path';
import { run, E2E_DIR } from './docker.mjs';
import { PLATFORM_ROOT, platformEnv } from './platform-db.mjs';

/**
 * Run one sync tick using the platform code in the working tree.
 *
 * @returns {Promise<{ok: boolean, result?: {synced: number, failed: number}, stdout: string, stderr: string}>}
 */
export async function runLocalSyncTick() {
  const r = await run(
    'node',
    [
      `--env-file=${resolve(PLATFORM_ROOT, '.env')}`,
      resolve(PLATFORM_ROOT, 'node_modules/tsx/dist/cli.mjs'),
      '--tsconfig',
      resolve(PLATFORM_ROOT, 'tsconfig.json'),
      resolve(E2E_DIR, 'rt/run-platform-sync.mts'),
    ],
    { cwd: PLATFORM_ROOT }
  );

  // The script prints exactly one JSON line; tsx may print warnings around it.
  const line = r.stdout
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l.startsWith('{') && l.includes('synced'))
    .pop();

  return {
    ok: r.code === 0 && Boolean(line),
    result: line ? JSON.parse(line) : undefined,
    stdout: r.stdout,
    stderr: r.stderr,
  };
}

/**
 * Run one FULL cron tick on the deployed platform (all five phases).
 *
 * Safe to call against live data only because the other four phases are
 * no-ops for anything this harness did not create: reaping needs a
 * `dispatched` attempt, dispatch needs a `PENDING` order, billing needs
 * `billing_status='pending'` AND a non-null outcome (the harness writes
 * `skipped`), and releasing only touches holds on terminal attempts. The
 * caller should still measure those candidate sets before calling it.
 *
 * @param {string} baseUrl Deployed platform origin.
 * @returns {Promise<{status: number, body: any}>}
 */
export async function runDeployedCronTick(baseUrl) {
  const secret = process.env.SYNC_SECRET || platformEnv('SYNC_SECRET') || platformEnv('CRON_SECRET');
  if (!secret) throw new Error('no SYNC_SECRET / CRON_SECRET in env or platform/.env');

  const res = await fetch(`${baseUrl}/api/internal/sync-ecommerce`, {
    method: 'POST',
    headers: { 'x-sync-secret': secret },
  });
  const text = await res.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    body = { raw: text.slice(0, 300) };
  }
  return { status: res.status, body };
}
