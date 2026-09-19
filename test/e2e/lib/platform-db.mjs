// ─────────────────────────────────────────────────────────────────────────────
// Postgres access for the plugin e2e harness.
//
// The platform owns the schema and the connection string. This file borrows
// BOTH read-only: `pg` is loaded out of `platform/node_modules` (the same trick
// `platform/scripts/grant-credits.mjs` uses, so the plugins repo gains no
// dependency of its own) and `DATABASE_URL` is parsed out of `platform/.env`.
//
// Nothing under `platform/` is ever written by this harness.
// ─────────────────────────────────────────────────────────────────────────────

import fs from 'node:fs';
import { resolve } from 'node:path';
import { createRequire } from 'node:module';

export const PLATFORM_ROOT = resolve(process.env.VOCIFY_PLATFORM_ROOT || 'C:/projects/vocify/platform');

const require = createRequire(import.meta.url);

function pgClientCtor() {
  const { Client } = require(resolve(PLATFORM_ROOT, 'node_modules/pg/lib/index.js'));
  return Client;
}

/** Read one key out of platform/.env without pulling in dotenv. */
export function platformEnv(key) {
  const file = resolve(PLATFORM_ROOT, '.env');
  const text = fs.readFileSync(file, 'utf8');
  const m = text.match(new RegExp(`^${key}=(.*)$`, 'm'));
  if (!m) return undefined;
  return m[1].trim().replace(/^["']|["']$/g, '');
}

export function databaseUrl() {
  const url =
    process.env.DATABASE_URL_DIRECT ||
    process.env.DATABASE_URL ||
    platformEnv('DATABASE_URL_DIRECT') ||
    platformEnv('DATABASE_URL');
  if (!url) throw new Error('no DATABASE_URL_DIRECT / DATABASE_URL in env or platform/.env');
  return url;
}

export async function connect() {
  const Client = pgClientCtor();
  const connectionString = databaseUrl();
  const needsSsl = /supabase|amazonaws|\bsslmode=require\b/.test(connectionString);
  const client = new Client({
    connectionString,
    ssl: needsSsl ? { rejectUnauthorized: false } : undefined,
  });
  await client.connect();
  return client;
}
