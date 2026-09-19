# Plugin ↔ platform end-to-end harness

Proves the **round trip**, over a real HTTP boundary in both directions: an order
placed in a **real shop** fires the plugin's own hook, is signed by the **shipped
PHP signer**, is accepted by a **running platform** and lands as `orders` +
`attempts` rows in Postgres — and then, when the call completes, the outcome
comes back and **changes that order's status in the shop's own database**.

Unit tests cannot do this. The three bugs this harness found on its first run
(see `../../PROGRESS.md` §8) were all invisible to PHPUnit — one of them was
being actively *asserted* by a passing unit test.

## Run it

```
cd plugins/test/e2e
./run.sh            # or run.cmd on Windows
```

Flags: `--only wc|ps|rt`, `--keep-up`, `--keep-fixture`, `--skip-build`,
`--slow-replay`.

## The two directions

| Direction | Suite | What it proves |
|---|---|---|
| shop → platform | `suites/wc.mjs`, `suites/ps.mjs` | a real order fires the plugin's hook, the shipped PHP signs it, the platform stores it |
| **platform → shop** | `suites/wc-return.mjs` | **a completed call changes the order's status in the shop's own MySQL** |

The return-path suite runs against its own stack (`docker-compose.rt.yml`, project
`vocify-e2e-rt`, port 58081) for a reason the inbound suites do not have: the
**platform** has to reach the **shop**, so the shop needs a real public https
origin. `assertSafeExternalUrl()` (platform) refuses anything else, and that
guard is never relaxed, patched or bypassed here. The compose file therefore
includes a `cloudflared` quick tunnel, and the shop is *installed* with the
tunnel hostname as its `siteurl`/`home` so WordPress serves it canonically.

It drives the sync two ways and reports them separately:

* `POST /api/internal/sync-ecommerce` on the **deployed** platform — the real
  cron path, but whatever build is live on the VPS;
* the platform's own `syncCompletedOrders()` from the **working tree**, run in a
  subprocess (`rt/run-platform-sync.mts`) because it needs the platform's
  tsconfig aliases and `.env`.

A working-tree fix that has not been deployed shows up as a `BLOCKED` row naming
the measured numbers, never as a pass.

Output: console log plus `REPORT.md` (gitignored). Exit code 0 = all green,
1 = at least one failed assertion, 2 = the harness itself could not run.

## What it needs

| Requirement | Why |
|---|---|
| A reachable deployed platform | Default `https://162-19-32-251.sslip.io`. Override with `TEST_BASE_URL`. |
| `platform/.env` with `DATABASE_URL` | Fixtures and readback against the same database the platform uses. Nothing under `platform/` is ever written. |
| Docker | WordPress/WooCommerce and PrestaShop stacks. |
| Outbound internet from the containers | The shops POST to the real public endpoint — see below. |

`tsx` and `pg` are borrowed from `platform/node_modules`, so the plugins repo
gains no dependency of its own.

## Why the real deployed platform, not a local one

Both plugins' 2026-09-18 SSRF hardening refuses any webhook URL that is not an
absolute `https://` one, and PrestaShop's `isAllowedWebhookUrl()` *additionally*
rejects any host that resolves to a private or loopback address — which every
Docker-network address is, and so is `host.docker.internal`. There is no local
URL either plugin will accept without patching its shipped security code, and
patching it would defeat the purpose of the exercise.

The deployed platform is a real public hostname with real Let's Encrypt TLS
(Caddy + sslip.io on the OVH box), so both guards run exactly as they would at a
merchant. It also means the harness tests the build that actually ships.

The first thing every run does is establish **which signing contract that
deployment enforces** (`fixtures/contract-probe.mjs`): a plugin that is correct
against the timestamp-bound contract fails against an older deployment in a way
that is indistinguishable from a plugin bug. If the target does not enforce the
new contract the run aborts before a single container starts, rather than
producing a red result that would be blamed on the plugins.

## Safety: this harness cannot place a phone call

Three independent guards, the last of which is asserted on every run:

1. The fixture agent's calling window is computed to be **closed right now**
   (one hour wide, starting three hours out), so `calculateScheduledTime()`
   returns the next window start.
2. Before any shop container starts, the orchestrator reads back the preflight
   order's `scheduledFor` and **aborts the whole run** unless it is more than an
   hour out. The dialler claims `scheduled_at <= now()`, so this is the gate
   that matters.
3. Every suite then re-asserts it per order — *"SAFETY: Attempt.scheduled_at is
   far in the future"*, recorded at `critical` severity.
4. Phone numbers are `+21600000000` — a non-assignable subscriber number.

This matters more now than it did against a local dev server: the target is the
live box that runs the dialler.

## Layout

```
orchestrate.mjs          one-command driver: preflight → fixture → contract
                         determination → safety gate → compose up → provision →
                         suites → report → teardown
run.sh / run.cmd         entry points (wrap orchestrate.mjs in platform's tsx)
docker-compose.wc.yml    MariaDB + WordPress; plugin bind-mounted read-only
docker-compose.ps.yml    MySQL + PrestaShop 8; module bind-mounted read-only
docker-compose.rt.yml    return-path shop + cloudflared quick tunnel (public https origin)
rt/run-platform-sync.mts one tick of the platform's own syncCompletedOrders()
fixtures/
  provision-tenant.mjs   throwaway Company/Agent/Integration/ApiKey/Pack (raw SQL)
  contract-probe.mjs     which signing contract does the TARGET enforce?
  preflight-probe.mjs    payload shape + the hand-built signed request helper
lib/                     Postgres access, docker wrappers, report emitter
suites/wc.mjs            WooCommerce assertions (shop → platform)
suites/wc-return.mjs     RETURN path assertions (platform → shop)
suites/ps.mjs            PrestaShop assertions
wc/                      WP-CLI provisioning, order fixtures, capture mu-plugin
ps/                      PrestaShop provisioning, validateOrder() fixture, helpers
```

## The rule the harness is built around

**Orders are driven through the shop's own code, never by posting to the webhook
endpoint.** WooCommerce orders go through WooCommerce's order API so
`woocommerce_new_order` fires for real; PrestaShop orders go through
`PaymentModule::validateOrder()` so `actionValidateOrder` fires for real. Posting
to `/api/webhooks/ecommerce` directly would bypass every line of PHP under test.

The one deliberate exception is labelled as such: the `preflight` group sends
hand-built requests before any container starts, to establish which contract the
target enforces and that the fixture is sound, purely so that a later failure is
unambiguously plugin-side. Those assertions are recorded in their own group and
never count as plugin evidence.

The negative suites mutate and replay requests, but the bytes they start from
are the ones the plugin produced — captured on the WooCommerce side by a
harness-owned mu-plugin hooking `http_api_debug`, and reproduced on the
PrestaShop side by calling the module's own `transformOrder()`/`VocifySigner`
(PrestaShop's `sendWebhook()` is a bare `curl_exec()` with no interception
point). A tampered body or a forged timestamp cannot be produced by the plugin
itself, so this is the only honest way to assert that the platform rejects them.

## Readiness signals, and why they are what they are

Both shops taught the same lesson twice: "the container accepts `exec`" is not
"the app is usable", and a readiness probe that can hang is worse than one that
is merely slow.

- **WordPress**: wait for the entrypoint to write `wp-config.php`. Probing
  earlier fails with `Error: 'wp-config.php' not found.`
- **WooCommerce**: after activating it, wait for `woocommerce_db_version` to be
  set. Activation only *schedules* `WC_Install::install()`; acting during it
  produced `Could not activate the 'woocommerce' plugin` and
  `Could not update option 'woocommerce_currency'` on a run where the database
  was healthy and the files were fine.
- **PrestaShop**: wait for Apache's `resuming normal operations` in the
  container log, then for a successful app bootstrap. "The installer removed its
  directory" is not a signal — absence also means *not started*, and that check
  passed at 5 seconds. Bootstrapping as the first check is worse: mid-install it
  blocks forever, and `waitFor()` only re-checks its deadline between calls.

Every `docker` invocation in a polling loop carries a per-exec timeout for the
same reason.
