// ─────────────────────────────────────────────────────────────────────────────
// Run ONE tick of the platform's e-commerce sync phase.
//
// This deliberately calls the platform's own `syncCompletedOrders()` — the
// exact function `POST /api/internal/sync-ecommerce` invokes as phase 4 — and
// not a reimplementation of it. Nothing about the push is stubbed: real
// adapter, real credentials out of live Postgres, real HTTPS to the shop.
//
// It runs as a subprocess rather than an import because it needs the
// platform's own tsconfig path aliases AND `platform/.env` (the platform's
// `env.ts` hard-fails on a missing variable at import time). The harness
// spawns it with:
//
//   node --env-file=<platform>/.env <platform>/node_modules/tsx/dist/cli.mjs \
//        --tsconfig <platform>/tsconfig.json rt/run-platform-sync.mts
//
// with cwd = the platform root. See lib/platform-sync.mjs.
//
// Why not just POST to the deployed /api/internal/sync-ecommerce? That is done
// too, and it is the stronger test of the *deployed* build — but the deployed
// build is whatever was last shipped to the VPS, so it cannot demonstrate a
// fix that is still in the working tree. The suite does both and says which is
// which.
// ─────────────────────────────────────────────────────────────────────────────
import { syncCompletedOrders } from '@/lib/services/ecommerce-sync.service';

const result = await syncCompletedOrders();
// One line of JSON on stdout; the harness parses it.
process.stdout.write(`${JSON.stringify(result)}\n`);
process.exit(0);
