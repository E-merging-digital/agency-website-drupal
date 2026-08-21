#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${EDITORIAL_IMAGE_MODE:-}"
ISSUE_NUMBER="${ISSUE_NUMBER:-}"
PROFILE_SHA256="${PROFILE_SHA256:-}"
PROFILE_FILE="${PROFILE_FILE:-}"
ASSET_SHA256="${ASSET_SHA256:-}"
ASSET_FILE="${ASSET_FILE:-}"
SERVER_HOST="${SERVER_HOST:-}"
SERVER_USER="${SERVER_USER:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/editorial-feature-image}"
RUN_ID="${GITHUB_RUN_ID:-0}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"

case "$MODE" in
  inspect|dry-run|apply) ;;
  *) echo "Unsupported EDITORIAL_IMAGE_MODE: $MODE" >&2; exit 1 ;;
esac
[[ "$ISSUE_NUMBER" =~ ^[1-9][0-9]*$ ]] || { echo 'ISSUE_NUMBER must be positive numeric.' >&2; exit 1; }
[[ "$RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUN_ATTEMPT" =~ ^[0-9]+$ ]]
[[ "$PROFILE_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$ASSET_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ -f "$PROFILE_FILE" ]]
[[ -f "$ASSET_FILE" ]]
actual_profile_sha="$(python3 - "$PROFILE_FILE" "$ISSUE_NUMBER" <<'PY_PROFILE'
import hashlib
import json
import sys
from pathlib import Path

data = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
profile = data.get('profiles', {}).get(sys.argv[2])
if not isinstance(profile, dict):
    raise SystemExit(2)
canonical = json.dumps(profile, ensure_ascii=False, sort_keys=True, separators=(',', ':')) + '\n'
print(hashlib.sha256(canonical.encode('utf-8')).hexdigest())
PY_PROFILE
)"
[[ "$actual_profile_sha" == "$PROFILE_SHA256" ]]
[[ -n "$SERVER_HOST" ]]
[[ -n "$SERVER_USER" ]]
[[ "$(sha256sum "$ASSET_FILE" | awk '{print $1}')" == "$ASSET_SHA256" ]]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_SCRIPT="$SCRIPT_DIR/editorial-feature-image.php"
[[ -f "$PHP_SCRIPT" ]]
php -l "$PHP_SCRIPT" >/dev/null
mkdir -p "$ARTIFACT_DIR"

remote_stem="/tmp/agency-editorial-image-${ISSUE_NUMBER}-${RUN_ID}-${RUN_ATTEMPT}"
remote_script="${remote_stem}.php"
remote_profile="${remote_stem}-profile.json"
remote_asset="${remote_stem}-asset.png"
remote_result="${remote_stem}-result.json"
remote_preapply="${remote_stem}-preapply.json"
remote_target="${SERVER_USER}@${SERVER_HOST}"

ssh_opts=(-o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=15 -o ServerAliveCountMax=4)

cleanup_remote() {
  set +e
  ssh "${ssh_opts[@]}" "$remote_target" \
    "rm -f '$remote_script' '$remote_profile' '$remote_asset' '$remote_result' '$remote_preapply'" \
    >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "${ssh_opts[@]}" "$PHP_SCRIPT" "$remote_target:$remote_script" >/dev/null
scp "${ssh_opts[@]}" "$PROFILE_FILE" "$remote_target:$remote_profile" >/dev/null
scp "${ssh_opts[@]}" "$ASSET_FILE" "$remote_target:$remote_asset" >/dev/null

remote_run() {
  local run_mode="$1"
  local remote_output="$2"
  ssh "${ssh_opts[@]}" "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_EDITORIAL_IMAGE_MODE='$run_mode' AGENCY_EDITORIAL_IMAGE_ISSUE='$ISSUE_NUMBER' AGENCY_EDITORIAL_IMAGE_PROFILE_PATH='$remote_profile' AGENCY_EDITORIAL_IMAGE_ASSET_PATH='$remote_asset' AGENCY_EDITORIAL_IMAGE_RESULT_PATH='$remote_output' timeout 120s vendor/bin/drush php:script '$remote_script'"
}

case "$MODE" in
  inspect)
    remote_run inspect "$remote_result"
    ;;
  dry-run)
    remote_run dry-run "$remote_result"
    ;;
  apply)
    remote_run dry-run "$remote_preapply"
    scp "${ssh_opts[@]}" "$remote_target:$remote_preapply" "$ARTIFACT_DIR/preapply.json" >/dev/null
    jq -e '.status == "PASS" and (.verdict == "READY" or .verdict == "REPAIR_REQUIRED" or .verdict == "IDEMPOTENT")' \
      "$ARTIFACT_DIR/preapply.json" >/dev/null

    preapply_verdict="$(jq -r '.verdict' "$ARTIFACT_DIR/preapply.json")"
    backup_file=''
    if [[ "$preapply_verdict" == 'READY' || "$preapply_verdict" == 'REPAIR_REQUIRED' ]]; then
      timestamp="$(date -u +%Y%m%d%H%M%S)"
      backup_stem="/var/www/agency/shared/backups/editorial-image-issue-${ISSUE_NUMBER}-${timestamp}.sql"
      backup_file="${backup_stem}.gz"
      ssh "${ssh_opts[@]}" "$remote_target" \
        "set -euo pipefail; cd /var/www/agency/current; mkdir -p /var/www/agency/shared/backups; timeout 120s vendor/bin/drush sql:dump --gzip --result-file='$backup_stem' >/dev/null; test -s '$backup_file'"
    fi

    remote_run apply "$remote_result"
    ;;
esac

scp "${ssh_opts[@]}" "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.status == "PASS"' "$ARTIFACT_DIR/result.json" >/dev/null

if [[ "$MODE" == 'apply' ]]; then
  tmp_result="$ARTIFACT_DIR/result.tmp.json"
  jq --arg backup_file "${backup_file:-}" \
    '. + {backup_file:(if $backup_file == "" then null else $backup_file end)}' \
    "$ARTIFACT_DIR/result.json" > "$tmp_result"
  mv "$tmp_result" "$ARTIFACT_DIR/result.json"
fi
