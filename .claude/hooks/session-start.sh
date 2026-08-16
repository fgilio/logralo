#!/usr/bin/env bash
set -euo pipefail

cd "$CLAUDE_PROJECT_DIR"

source "$CLAUDE_PROJECT_DIR/scripts/cloud/lib.sh"

# Thin dispatcher: the real work lives in scripts/cloud/setup.sh.
#
# Output goes to STDERR, and to the log file await.sh tails when it reports a
# failure: a SessionStart hook's STDOUT is reserved for JSON control lines, and
# mixed human text there is treated as raw context.
#
# Non-fatal on purpose: a failed step (network, a missing snapshot, absent Flux
# env vars) must not abort the session. Rerun `bash scripts/cloud/setup.sh`
# manually to retry.
#
# Local sessions are expected to manage their own PHP and dependencies, but
# still benefit from the git hooks the shared setup installs.
if cloud_session; then
    # Claim the status file before backgrounding, so an await.sh that runs
    # before setup.sh has started still sees 'running' and waits, rather than
    # reading "no bootstrap in flight" and greenlighting a bare checkout.
    printf 'running\n' > "$CLOUD_SETUP_STATUS_FILE"

    # Backgrounded: a cold bootstrap installs PHP, restores vendor/, installs
    # node modules and builds assets, which is minutes of work. Blocking the
    # hook on it would stall the session before the first prompt. await.sh is
    # how anything that needs the finished environment synchronizes with it.
    nohup bash "$CLAUDE_PROJECT_DIR/scripts/cloud/setup.sh" \
        >"$CLOUD_SETUP_LOG_FILE" 2>&1 &
    echo "Bootstrapping the environment in the background. Run 'bash scripts/cloud/await.sh' before tests; log: $CLOUD_SETUP_LOG_FILE" >&2
else
    # No need to pass the skip-deps override: setup.sh calls the same
    # cloud_session and reaches the light path on its own.
    bash "$CLAUDE_PROJECT_DIR/scripts/cloud/setup.sh" 1>&2 \
        || echo "bootstrap failed; rerun: bash scripts/cloud/setup.sh" >&2
fi
