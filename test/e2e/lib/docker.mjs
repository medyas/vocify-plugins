// Thin wrappers around `docker` / `docker compose`. Node's spawn is used
// rather than a shell so Windows path arguments are not MSYS-mangled (a real
// trap when driving Docker from Git Bash: `/e2e/x.sh` becomes
// `C:/Program Files/Git/e2e/x.sh`).

import { spawn } from 'node:child_process';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

export const E2E_DIR = resolve(dirname(fileURLToPath(import.meta.url)), '..');

export function run(cmd, args, opts = {}) {
  return new Promise((res) => {
    const child = spawn(cmd, args, {
      cwd: opts.cwd ?? E2E_DIR,
      env: { ...process.env, ...(opts.env ?? {}) },
      windowsHide: true,
      shell: false,
    });
    let stdout = '';
    let stderr = '';
    let settled = false;
    const done = (r) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      res(r);
    };
    // A readiness poll must never be able to hang the whole run. `docker
    // compose exec` into a container that is mid-install can block
    // indefinitely, and waitFor() only re-checks its deadline BETWEEN calls —
    // so one hung exec stalls everything. Kill it and let the caller retry.
    const timer = opts.timeoutMs
      ? setTimeout(() => {
          child.kill('SIGKILL');
          done({ code: -2, stdout, stderr: `${stderr}\n[killed after ${opts.timeoutMs}ms]`, timedOut: true });
        }, opts.timeoutMs)
      : null;
    child.stdout.on('data', (d) => {
      stdout += d;
      if (opts.echo) process.stdout.write(d);
    });
    child.stderr.on('data', (d) => {
      stderr += d;
      if (opts.echo) process.stderr.write(d);
    });
    child.on('error', (err) => done({ code: -1, stdout, stderr: String(err) }));
    child.on('close', (code) => done({ code, stdout, stderr }));
  });
}

export const compose = (file, args, opts) =>
  run('docker', ['compose', '-f', file, ...args], opts);

/** Run a command inside a compose service, with env injected. */
export const composeExec = (file, service, argv, env = {}, opts = {}) => {
  const envArgs = Object.entries(env).flatMap(([k, v]) => ['-e', `${k}=${v}`]);
  return compose(file, ['exec', '-T', ...envArgs, service, ...argv], opts);
};

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function waitFor(label, fn, { timeoutMs = 180_000, intervalMs = 3000 } = {}) {
  const deadline = Date.now() + timeoutMs;
  let last = '';
  while (Date.now() < deadline) {
    try {
      const out = await fn();
      if (out) return out;
    } catch (err) {
      last = err.message;
    }
    await sleep(intervalMs);
  }
  throw new Error(`timed out after ${timeoutMs}ms waiting for ${label}${last ? ` (last error: ${last})` : ''}`);
}
