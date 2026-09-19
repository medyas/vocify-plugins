// ─────────────────────────────────────────────────────────────────────────────
// Result recording for the plugin e2e harness.
//
// The `Recorder` is the platform's own (`platform/scripts/e2e/lib/report.ts`),
// imported read-only so this harness uses the same four outcomes and the same
// console format as the platform suites — PASS / FAIL / SKIP / BLOCKED, with
// BLOCKED meaning "could not be evaluated", never "failed".
//
// Its sibling `writeReport()` is NOT reused: it requires a `RouteEntry[]` route
// manifest built by scanning `platform/src/app/api/**`, and a route-coverage
// table is meaningless for a harness that exercises exactly one endpoint. The
// small emitter below takes its place.
//
// This module must be loaded under platform's tsx (see orchestrate.mjs), which
// is what lets a .ts file be imported from .mjs.
// ─────────────────────────────────────────────────────────────────────────────

import { writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { PLATFORM_ROOT } from './platform-db.mjs';

export async function loadRecorder() {
  const url = pathToFileURL(resolve(PLATFORM_ROOT, 'scripts/e2e/lib/report.ts')).href;
  const mod = await import(url);
  if (typeof mod.Recorder !== 'function') {
    throw new Error(
      `imported ${url} but it exported no Recorder — run this harness through platform's tsx CLI`
    );
  }
  return mod.Recorder;
}

const esc = (s) => (s === undefined || s === null ? '' : String(s).replace(/\|/g, '\\|').replace(/\n/g, ' '));

export function writePluginReport(file, rec, meta) {
  const t = rec.tally();
  const L = [];
  const p = (s = '') => L.push(s);

  p('# Vocify plugins — end-to-end test report');
  p();
  p(`- **Run ID**: \`${meta.runId}\``);
  p(`- **Webhook target**: ${meta.webhookUrl}`);
  p(`- **Platform**: ${meta.baseUrl}`);
  p(`- **Started**: ${meta.startedAt.toISOString()}`);
  p(`- **Finished**: ${meta.finishedAt.toISOString()}`);
  p(`- **Duration**: ${((meta.finishedAt - meta.startedAt) / 1000).toFixed(1)}s`);
  for (const [k, v] of Object.entries(meta.versions ?? {})) p(`- **${k}**: ${v}`);
  p();
  p('## Headline');
  p();
  p('| Metric | Value |');
  p('|---|---:|');
  p(`| Assertions passed | ${t.PASS} |`);
  p(`| Assertions failed | ${t.FAIL} |`);
  p(`| Skipped (deliberate) | ${t.SKIP} |`);
  p(`| Blocked (untestable) | ${t.BLOCKED} |`);
  p();

  if (meta.notes?.length) {
    p('## Notes on this run');
    p();
    for (const n of meta.notes) p(`- ${n}`);
    p();
  }

  const failures = rec.results.filter((r) => r.outcome === 'FAIL');
  p('## Failures');
  p();
  if (!failures.length) p('None.');
  for (const [i, f] of failures.entries()) {
    p(`### F${i + 1}. ${f.name}`);
    p();
    p(`- **Group**: ${f.group}`);
    if (f.request) p(`- **Sent**: ${f.request}`);
    p(`- **Expected**: ${f.expected ?? '(unspecified)'}`);
    p(`- **Actual**: ${f.actual ?? '(unspecified)'}`);
    if (f.suspect) p(`- **Likely cause**: ${f.suspect}`);
    p();
  }
  p();

  const blocked = rec.results.filter((r) => r.outcome === 'BLOCKED');
  p('## Blocked');
  p();
  if (!blocked.length) p('None.');
  else {
    p('| Group | Check | Reason |');
    p('|---|---|---|');
    for (const b of blocked) p(`| ${esc(b.group)} | ${esc(b.name)} | ${esc(b.actual)} |`);
  }
  p();

  p('## Results by group');
  p();
  p('| Group | PASS | FAIL | SKIP | BLOCKED |');
  p('|---|---:|---:|---:|---:|');
  for (const [g, c] of rec.byGroup()) p(`| ${esc(g)} | ${c.PASS} | ${c.FAIL} | ${c.SKIP} | ${c.BLOCKED} |`);
  p();

  p('## Full assertion log');
  p();
  p('| Outcome | Group | Check | Detail |');
  p('|---|---|---|---|');
  for (const r of rec.results) {
    const detail =
      r.outcome === 'PASS'
        ? esc(r.actual ?? '')
        : esc([r.expected && `expected ${r.expected}`, r.actual && `got ${r.actual}`].filter(Boolean).join('; '));
    p(`| ${r.outcome} | ${esc(r.group)} | ${esc(r.name)} | ${detail} |`);
  }
  p();

  writeFileSync(file, L.join('\n'), 'utf8');
}
