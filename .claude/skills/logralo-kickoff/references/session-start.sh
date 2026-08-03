#!/usr/bin/env bash
set -euo pipefail

cd "$CLAUDE_PROJECT_DIR"

# Thin dispatcher: the real work lives in scripts/cloud/setup.sh.
#
# Output goes to STDERR: a SessionStart hook's STDOUT is reserved for JSON
# control lines, and mixed human text there is treated as raw context.
#
# Non-fatal on purpose: a failed dependency step (network, missing Flux env
# vars) must not abort the session. Rerun `bash scripts/cloud/setup.sh`
# manually to retry.
bash "$CLAUDE_PROJECT_DIR/scripts/cloud/setup.sh" 1>&2 \
  || echo "bootstrap failed; rerun: bash scripts/cloud/setup.sh" >&2
