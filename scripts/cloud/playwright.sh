#!/usr/bin/env bash
# Playwright Chromium for the browser suite (tests/Browser, Pest 4 visit()).
# Best-effort: the browser binary is only needed by `composer test:browser`, so
# a failure here never blocks the rest of the setup. The base image is expected
# to already carry Chromium's shared libraries; installing them is the slow,
# root-only apt path, opt-in via PLA_PLAYWRIGHT_WITH_DEPS=1.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
cd "$(pla_project_dir)" || exit 0

# The Playwright CLI lands under node_modules/.bin only after a successful npm
# install, so this track no-ops cleanly when node.sh has not run yet.
if [ ! -x node_modules/.bin/playwright ]; then
    warn 'Playwright is not installed (node_modules/.bin/playwright missing). Skipping browser download.'
    exit 0
fi

if ! node_modules/.bin/playwright --version >/dev/null 2>&1; then
    warn 'Playwright binary is not runnable. Skipping browser download.'
    exit 0
fi

# An environment that pre-provisions browsers says so explicitly:
# PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD (with PLAYWRIGHT_BROWSERS_PATH pointing at
# the provided cache). Honor it and skip; everywhere else `install chromium` is
# a fast no-op exactly when the pinned revision is already cached, which is the
# only correct way to decide — never sniff for chromium-* directories, because
# a cache holding some other revision is not a cache hit.
if [ -n "${PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD:-}" ]; then
    log 'PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD is set; using the environment-provided Chromium.'
    exit 0
fi

log 'Ensuring Playwright Chromium is installed'
if [ "${PLA_PLAYWRIGHT_WITH_DEPS:-}" = '1' ]; then
    npx --no-install playwright install --with-deps chromium \
        || warn 'Playwright Chromium (--with-deps) install failed.'
else
    npx --no-install playwright install chromium \
        || warn 'Playwright Chromium install failed.'
fi
