#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// FIXTURE PREFLIGHT — **NOT A PLUGIN ASSERTION.**
//
// Sends ONE hand-built signed webhook through the tunnel with the fixture's
// credentials. It deliberately bypasses every line of plugin PHP, so it proves
// nothing whatsoever about the plugins. Its only job is to make later failures
// diagnosable: if this returns 201 and the `orders`/`attempts` rows appear,
// then tunnel → platform → API-key auth → rate limit → domain match → payload
// schema → credit reservation are all known-good, and any subsequent
// WordPress/PrestaShop failure is unambiguously plugin-side.
//
// The harness records its result as `preflight` and NEVER counts it toward the
// plugin verdict. Driving real orders through the shop's own hooks is what the
// actual suites do.
//
//   node fixtures/preflight-probe.mjs --fixture <json> --url <webhook url>
// ─────────────────────────────────────────────────────────────────────────────

import crypto from 'node:crypto';

export function signedRequest({ apiKey, signatureSecret, storeDomain, platform }, payload, opts = {}) {
  const rawBody = JSON.stringify(payload);
  const timestamp = opts.timestamp ?? new Date().toISOString();
  const signature = crypto
    .createHmac('sha256', signatureSecret)
    .update(`${timestamp}.${rawBody}`)
    .digest('hex');
  return {
    rawBody,
    headers: {
      'Content-Type': 'application/json',
      'X-Platform': platform,
      'X-API-Key': apiKey,
      'X-Domain': storeDomain,
      'X-Timestamp': timestamp,
      'X-Signature': signature,
    },
  };
}

export function probePayload(orderId) {
  const now = new Date().toISOString();
  return {
    orderId,
    orderNumber: orderId,
    orderKey: `wc_order_${orderId}`,
    status: 'processing',
    financialStatus: 'paid',
    fulfillmentStatus: 'unfulfilled',
    currency: 'TND',
    createdAt: now,
    customer: {
      firstName: 'Preflight',
      lastName: 'Fixture',
      email: 'preflight@e2e.invalid',
      // Non-routable fake: +216 is Tunisia, 00000000 is not an assignable
      // subscriber number. Nothing can dial it even if a poller tried.
      phone: '+21600000000',
    },
    items: [{ id: 'WH-100', name: 'Wireless Headphones', quantity: 1, price: 129.9, total: 129.9 }],
    totals: { subtotal: 129.9, total: 129.9 },
    shippingAddress: { address1: '1 Avenue Habib Bourguiba', city: 'Tunis', country: 'TN' },
  };
}

export async function runPreflight(fixture, webhookUrl, orderId) {
  const payload = probePayload(orderId);
  const { rawBody, headers } = signedRequest(fixture, payload);
  const res = await fetch(webhookUrl, { method: 'POST', headers, body: rawBody });
  const text = await res.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    body = text.slice(0, 400);
  }
  return { status: res.status, body, orderId };
}

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
}

if (import.meta.url === `file:///${process.argv[1].replace(/\\/g, '/')}`) {
  const fixture = JSON.parse(arg('fixture'));
  const url = arg('url');
  const orderId = arg('order-id', `PREFLIGHT-${Date.now()}`);
  runPreflight(fixture, url, orderId).then((r) => {
    process.stdout.write(JSON.stringify(r) + '\n');
    process.exit(r.status === 201 ? 0 : 1);
  });
}
