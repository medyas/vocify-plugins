#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// WHICH SIGNING CONTRACT DOES THE TARGET ACTUALLY ENFORCE?
//
// Not a plugin test. This answers one question about the DEPLOYED platform
// before any plugin conclusion is drawn from it:
//
//   does it verify HMAC over `{X-Timestamp}.{rawBody}` (the 2026-09-18
//   contract) or over the body alone (the contract before it)?
//
// It matters because a plugin that is correct against the new contract fails
// against an old deployment in a way that looks exactly like a plugin bug. The
// GET health handler cannot answer it — that string is hardcoded and says
// nothing about what the POST verifier does. Only a signed POST with a real
// key does, because an unknown key is rejected as INVALID_KEY before signature
// verification is ever reached.
//
// Four requests, each with its own orderId so idempotent dedupe cannot mask a
// result:
//   1. body-only signature      — the OLD contract
//   2. timestamp-bound          — the NEW contract
//   3. no X-Signature at all    — fail-closed check
//   4. correctly signed, stale  — freshness-window check
//
//   node fixtures/contract-probe.mjs --url https://host/api/webhooks/ecommerce
// ─────────────────────────────────────────────────────────────────────────────

import crypto from 'node:crypto';
import { connect } from '../lib/platform-db.mjs';
import { provision, teardown } from './provision-tenant.mjs';
import { probePayload } from './preflight-probe.mjs';

const hmac = (secret, message) => crypto.createHmac('sha256', secret).update(message).digest('hex');

async function post(url, fixture, payload, { signAs, timestamp, omitSignature }) {
  const rawBody = JSON.stringify(payload);
  const ts = timestamp ?? new Date().toISOString();
  const headers = {
    'Content-Type': 'application/json',
    'X-Platform': fixture.platform,
    'X-API-Key': fixture.apiKey,
    'X-Domain': fixture.storeDomain,
    'X-Timestamp': ts,
  };
  if (!omitSignature) {
    headers['X-Signature'] =
      signAs === 'body-only'
        ? hmac(fixture.signatureSecret, rawBody)
        : hmac(fixture.signatureSecret, `${ts}.${rawBody}`);
  }
  const res = await fetch(url, { method: 'POST', headers, body: rawBody });
  const text = await res.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    body = { raw: text.slice(0, 200) };
  }
  return { status: res.status, code: body.code, error: body.error, body };
}

export async function determineContract(sql, fixture, url, log = () => {}) {
  const tag = Date.now();
  const results = {};

  results.bodyOnly = await post(url, fixture, probePayload(`CONTRACT-OLD-${tag}`), { signAs: 'body-only' });
  log(`  body-only signature (OLD contract)  -> HTTP ${results.bodyOnly.status} ${results.bodyOnly.code ?? ''}`);

  results.timestampBound = await post(url, fixture, probePayload(`CONTRACT-NEW-${tag}`), { signAs: 'timestamp-bound' });
  log(`  timestamp-bound signature (NEW)     -> HTTP ${results.timestampBound.status} ${results.timestampBound.code ?? ''}`);

  results.unsigned = await post(url, fixture, probePayload(`CONTRACT-NOSIG-${tag}`), { omitSignature: true });
  log(`  no X-Signature at all               -> HTTP ${results.unsigned.status} ${results.unsigned.code ?? ''}`);

  const stale = new Date(Date.now() - 6 * 60 * 1000).toISOString();
  results.stale = await post(url, fixture, probePayload(`CONTRACT-STALE-${tag}`), {
    signAs: 'timestamp-bound',
    timestamp: stale,
  });
  log(`  correctly signed, 6-minute-old ts   -> HTTP ${results.stale.status} ${results.stale.code ?? ''}`);

  const newOk = results.timestampBound.status === 201;
  const oldOk = results.bodyOnly.status === 201 || results.bodyOnly.status === 200;
  const unsignedOk = results.unsigned.status === 201 || results.unsigned.status === 200;

  let verdict;
  if (newOk && !oldOk && !unsignedOk) verdict = 'NEW';
  else if (oldOk && !newOk) verdict = 'OLD';
  else if (unsignedOk) verdict = 'UNSIGNED-ACCEPTED';
  else verdict = 'INDETERMINATE';

  return { verdict, results, newOk, oldOk, unsignedOk };
}

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
}

if (import.meta.url === `file:///${process.argv[1].replace(/\\/g, '/')}`) {
  const url = arg('url', 'https://152-228-210-12.sslip.io/api/webhooks/ecommerce');
  const log = (s) => process.stderr.write(`${s}\n`);
  const sql = await connect();
  let fixture = null;
  try {
    fixture = await provision(sql, {
      platform: 'WOOCOMMERCE',
      domain: 'wc-e2e.vocify.test',
      tag: 'contract',
    });
    log(`fixture company ${fixture.companyId} (calling window ${fixture.callingWindow.start}-${fixture.callingWindow.end} UTC — CLOSED)`);
    log(`target ${url}`);

    // Clock skew matters: the freshness check is two-sided and 300s wide, so a
    // badly-skewed harness clock would look like a rejected signature.
    const head = await fetch(url, { method: 'GET' });
    const serverTime = head.headers.get('date');
    if (serverTime) {
      const skewMs = Date.now() - new Date(serverTime).getTime();
      log(`clock skew harness vs target: ${(skewMs / 1000).toFixed(1)}s`);
    }

    const out = await determineContract(sql, fixture, url, log);
    log(`\nVERDICT: ${out.verdict}`);
    process.stdout.write(JSON.stringify(out, null, 2) + '\n');
    process.exitCode = out.verdict === 'NEW' ? 0 : 1;
  } finally {
    if (fixture) {
      const counts = await teardown(sql, fixture.companyId);
      log(`teardown: companies remaining ${counts._companies_remaining}`);
    }
    await sql.end().catch(() => {});
  }
}
