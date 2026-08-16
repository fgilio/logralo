#!/usr/bin/env bash
# Block until the background web bootstrap (scripts/cloud/setup.sh) finishes.
# Run this before commands that need the provisioned environment, such as tests,
# and anything the repo's bootstrap sets up. It returns 0 right away when setup
# is done or was never started, and non-zero when setup failed, so chaining
# `await.sh && <test command>` stops instead of running against a broken
# environment.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$here/lib.sh"

if [ ! -f "$CLOUD_SETUP_STATUS_FILE" ]; then
  exit 0
fi

deadline=$(( $(date +%s) + ${LOGRALO_CLOUD_SETUP_AWAIT_TIMEOUT:-1200} ))
announced=0
while true; do
  # The status file can disappear mid-wait: the light (local) path clears a
  # stale marker instead of writing a terminal status. Gone means no bootstrap
  # is in flight anymore -- same conclusion as the pre-loop check, so stop
  # polling instead of spinning to the timeout on the "running" fallback.
  if [ ! -f "$CLOUD_SETUP_STATUS_FILE" ]; then
    exit 0
  fi

  status="$(cat "$CLOUD_SETUP_STATUS_FILE" 2>/dev/null || echo running)"

  if [ "$status" = 'done' ]; then
    # Setup finished, but in repos that run service daemons (they ship an
    # ensure-services.sh module) the daemons may not have survived into this
    # session: a container reclaimed for inactivity and resumed, or an OOM kill,
    # comes back with the filesystem intact while the status file still reads
    # 'done'. Reconcile before reporting ready, so callers run against live
    # services instead of a stale status file. Repos without service daemons
    # have nothing to reconcile: a finished bootstrap stays finished across a
    # resumed container, so report ready immediately.
    if [ -f "$here/ensure-services.sh" ]; then
      exec bash "$here/ensure-services.sh"
    fi
    exit 0
  fi

  case "$status" in
    failed:*)
      warn "The web environment setup failed (${status}). Recent log:"
      # Send the log to stderr (>&2 first, so it duplicates the real stderr
      # before 2>/dev/null silences tail's own errors).
      tail -n 25 "$CLOUD_SETUP_LOG_FILE" >&2 2>/dev/null
      exit 1
      ;;
  esac

  if [ "$announced" -eq 0 ]; then
    # CLOUD_WAIT_HINT names what this repo's bootstrap provisions. It lives
    # in lib.sh's config block so this file carries no project specifics.
    echo "Waiting for the web environment to finish setting up (${CLOUD_WAIT_HINT:-dependencies})."
    announced=1
  fi

  if [ "$(date +%s)" -ge "$deadline" ]; then
    # Fail closed (124, the GNU timeout convention): a caller chaining
    # `await.sh && <test command>` must stop on an unready environment instead
    # of producing flaky failures against a half-provisioned toolchain. A cold
    # bootstrap that legitimately needs longer (a multi-GB image pull) can
    # raise LOGRALO_CLOUD_SETUP_AWAIT_TIMEOUT.
    warn "Timed out after ${LOGRALO_CLOUD_SETUP_AWAIT_TIMEOUT:-1200}s waiting for setup. The bootstrap is still running; see $CLOUD_SETUP_LOG_FILE."
    exit 124
  fi
  sleep 3
done
