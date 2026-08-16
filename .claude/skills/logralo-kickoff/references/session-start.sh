#!/usr/bin/env bash
set -euo pipefail

cd "$CLAUDE_PROJECT_DIR"

# Thin dispatcher: the real work lives in scripts/cloud/setup.sh.
#
# Pairs with this directory's cloud-setup.sh, and like it, this is a starting
# point rather than a copy of what Logralo runs today. Both stayed simple
# because a new project can reach the package registries from anywhere it
# builds. Logralo's own hook has since outgrown this one — it backgrounds the
# bootstrap and hands callers a status file to wait on, because a cold hosted
# session takes minutes. Adopt that shape when a sandbox forces it, not before:
# scripts/cloud/SETUP.md in this repo is the account of what forced it.
#
# Output goes to STDERR: a SessionStart hook's STDOUT is reserved for JSON
# control lines, and mixed human text there is treated as raw context.
#
# Non-fatal on purpose: a failed dependency step (network, missing Flux env
# vars) must not abort the session. Rerun `bash scripts/cloud/setup.sh`
# manually to retry.
bash "$CLAUDE_PROJECT_DIR/scripts/cloud/setup.sh" 1>&2 \
  || echo "bootstrap failed; rerun: bash scripts/cloud/setup.sh" >&2
