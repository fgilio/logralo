#!/usr/bin/env bash
# Writes .cloud/config.json for a Laravel Cloud app, non-interactively.
#
# Stand-in for `cloud repo:config`, which always prompts for the application
# (no --application flag). Useful in scripts and for bootstrapping new repos.
#
# Usage:
#   CLOUD_ORG_ID=org-... cloud-link.sh <app-slug-or-name> [target-dir]
#
# target-dir defaults to the current git repo root (or cwd if not a repo).
# Requires: cloud CLI (authenticated with the personal account), jq.

set -euo pipefail

# Franco's personal Laravel Cloud org. Find the id after `cloud auth` via
# `cloud application:list -n --json --fields=organizationId`, or in the
# Laravel Cloud dashboard URL.
CLOUD_ORG_ID="${CLOUD_ORG_ID:-}"

if [[ -z $CLOUD_ORG_ID ]]; then
    echo "error: set CLOUD_ORG_ID to the personal Laravel Cloud org id (org-...)" >&2
    exit 78
fi

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "usage: CLOUD_ORG_ID=org-... $(basename "$0") <app-slug-or-name> [target-dir]" >&2
    exit 64
fi

needle=$1
target=${2:-}

if [[ -z $target ]]; then
    if target=$(git rev-parse --show-toplevel 2>/dev/null); then
        :
    else
        target=$(pwd)
    fi
fi

if [[ ! -d $target ]]; then
    echo "error: target directory does not exist: $target" >&2
    exit 66
fi

# `cloud application:list` resolves the API token via .cloud/config.json
# in the cwd's git root (or cwd). Run it from a scratch dir we seed with
# the org id so token resolution succeeds without prompting.
scratch=$(mktemp -d)
trap 'rm -rf "$scratch"' EXIT
mkdir -p "$scratch/.cloud"
jq -n --arg org "$CLOUD_ORG_ID" '{organization_id: $org}' \
    >"$scratch/.cloud/config.json"

apps=$(cd "$scratch" && cloud application:list -n --json --fields=id,name,slug,organizationId)

match=$(jq --arg n "$needle" --arg org "$CLOUD_ORG_ID" '
    [.[] | select(.organizationId == $org and (.slug == $n or .name == $n))] as $hits
    | if ($hits | length) == 0 then
        error("no app matches \"" + $n + "\" in org " + $org)
      elif ($hits | length) > 1 then
        error("ambiguous: \"" + $n + "\" matches " + (($hits | map(.slug) | join(", "))))
      else
        $hits[0]
      end
' <<<"$apps")

app_id=$(jq -r '.id' <<<"$match")
app_name=$(jq -r '.name' <<<"$match")

config_dir=$target/.cloud
config_file=$config_dir/config.json

mkdir -p "$config_dir"

# Merge into the existing file rather than overwrite. Matches the real
# `cloud repo:config` (LocalConfig::setMany uses array_merge), so keys we
# don't manage (e.g. `environment_id`) are preserved across reruns.
existing='{}'
if [[ -f $config_file ]]; then
    existing=$(jq '.' "$config_file")
fi

# 4-space indent + trailing newline matches `cloud repo:config` output.
jq --indent 4 \
    --arg org "$CLOUD_ORG_ID" \
    --arg app "$app_id" \
    '. + {organization_id: $org, application_id: $app}' \
    <<<"$existing" \
    >"$config_file"

echo "wrote $config_file"
echo "  app: $app_name ($app_id)"
echo "  org: $CLOUD_ORG_ID"
