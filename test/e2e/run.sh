#!/usr/bin/env bash
# One-command entry point for the plugin e2e harness. See run.cmd for why tsx.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec node "${HERE}/../../../platform/node_modules/tsx/dist/cli.mjs" "${HERE}/orchestrate.mjs" "$@"
