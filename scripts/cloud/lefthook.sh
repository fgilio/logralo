#!/usr/bin/env bash
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
cd "$(pla_project_dir)" || exit 1

# Print the path to a usable lefthook binary, or nothing if none is on PATH.
resolve_lefthook() {
  if [ -x node_modules/.bin/lefthook ]; then
    printf 'node_modules/.bin/lefthook\n'
  elif command -v lefthook >/dev/null 2>&1; then
    printf 'lefthook\n'
  fi
}

# The sandbox has no system or brew lefthook, so install it from the public npm
# registry (the lefthook package ships the binary) when it is not already on
# PATH, then install the git hooks with whatever binary resolved.
lefthook_bin="$(resolve_lefthook)"

if [ -z "$lefthook_bin" ]; then
  if command -v npm >/dev/null 2>&1; then
    log 'Installing lefthook'
    npm install -g lefthook --no-fund --no-audit >/dev/null 2>&1 \
      || warn 'Could not install lefthook. Git hooks will not run.'
    lefthook_bin="$(resolve_lefthook)"
  else
    warn 'npm is not available to install lefthook. Git hooks will not run.'
  fi
fi

if [ -z "$lefthook_bin" ]; then
  warn 'lefthook is not installed, so the git hooks were not installed.'
  exit 0
fi

log 'Installing the git hooks'
"$lefthook_bin" install || warn 'lefthook install failed.'
