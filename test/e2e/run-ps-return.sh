#!/usr/bin/env bash
# Entry point for the PrestaShop return-path suite. Wrapped in platform's tsx
# for the same reason run.sh is: the Recorder it reports through is a .ts file.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec node "${HERE}/../../../platform/node_modules/tsx/dist/cli.mjs" "${HERE}/run-ps-return.mjs" "$@"
