#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${EDITORIAL_MODE:-}"
ISSUE_NUMBER="${ISSUE_NUMBER:-}"
PAYLOAD_SHA256="${PAYLOAD_SHA256:-}"
PAYLOAD_FILE="${PAYLOAD_FILE:-}"
SERVER_HOST="${SERVER_HOST:-}"
SERVER_USER="${SERVER_USER:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/editorial-publication}"
RUN_ID="${GITHUB_RUN_ID:-0}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"

case "$MODE" in
  inspect|dry-run|apply) ;;
  *) echo "Unsupported EDITORIAL_MODE: $MODE" >&2; exit 1 ;;
esac

[[ "$ISSUE_NUMBER" =~ ^[1-9][0-9]*$ ]] || {
  echo 'ISSUE_NUMBER must be a positive integer.' >&2
  exit 1
}
[[ "$RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUN_ATTEMPT" =~ ^[0-9]+$ ]]
[[ -n "$SERVER_HOST" ]]
[[ -n "$SERVER_USER" ]]

if [[ "$MODE" != 'inspect' ]]; then
  [[ "$PAYLOAD_SHA256" =~ ^[0-9a-f]{64}$ ]] || {
    echo 'PAYLOAD_SHA256 must be a lowercase SHA-256.' >&2
    exit 1
  }
  [[ -f "$PAYLOAD_FILE" ]] || {
    echo 'PAYLOAD_FILE is required for dry-run/apply.' >&2
    exit 1
  }
  actual_sha="$(sha256sum "$PAYLOAD_FILE" | awk '{print $1}')"
  [[ "$actual_sha" == "$PAYLOAD_SHA256" ]] || {
    echo 'Local editorial payload hash mismatch.' >&2
    exit 1
  }
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIBRARY_SCRIPT="$SCRIPT_DIR/editorial-publication.php"
PHP_SCRIPT="$SCRIPT_DIR/editorial-publication-pathauto.php"
[[ -f "$LIBRARY_SCRIPT" ]]
[[ -f "$PHP_SCRIPT" ]]
php -l "$LIBRARY_SCRIPT" >/dev/null
php -l "$PHP_SCRIPT" >/dev/null
mkdir -p "$ARTIFACT_DIR"

remote_stem="/tmp/agency-editorial-${ISSUE_NUMBER}-${RUN_ID}-${RUN_ATTEMPT}"
remote_script="${remote_stem}.php"
remote_library="${remote_stem}-library.php"
remote_payload="${remote_stem}.json"
remote_result="${remote_stem}-result.json"
remote_preapply="${remote_stem}-preapply.json"
remote_target="${SERVER_USER}@${SERVER_HOST}"

cleanup_remote() {
  set +e
  ssh "$remote_target" \
    "rm -f '$remote_script' '$remote_library' '$remote_payload' '$remote_result' '$remote_preapply'" \
    >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "$PHP_SCRIPT" "$remote_target:$remote_script" >/dev/null
scp "$LIBRARY_SCRIPT" "$remote_target:$remote_library" >/dev/null
if [[ "$MODE" != 'inspect' ]]; then
  scp "$PAYLOAD_FILE" "$remote_target:$remote_payload" >/dev/null
fi

remote_run() {
  local run_mode="$1"
  local remote_output="$2"
  local payload_path=''
  local payload_sha=''

  if [[ "$run_mode" != 'inspect' ]]; then
    payload_path="$remote_payload"
    payload_sha="$PAYLOAD_SHA256"
  fi

  ssh "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_EDITORIAL_MODE='$run_mode' AGENCY_EDITORIAL_ISSUE='$ISSUE_NUMBER' AGENCY_EDITORIAL_PAYLOAD_SHA='$payload_sha' AGENCY_EDITORIAL_PAYLOAD_PATH='$payload_path' AGENCY_EDITORIAL_RESULT_PATH='$remote_output' AGENCY_EDITORIAL_LIBRARY_PATH='$remote_library' vendor/bin/drush php:script '$remote_script'"
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
    scp "$remote_target:$remote_preapply" "$ARTIFACT_DIR/preapply.json" >/dev/null
    jq -e '.status == "PASS" and (.verdict == "READY" or .verdict == "REPAIR_REQUIRED" or .verdict == "IDEMPOTENT")' \
      "$ARTIFACT_DIR/preapply.json" >/dev/null

    preapply_verdict="$(jq -r '.verdict' "$ARTIFACT_DIR/preapply.json")"
    backup_file=''
    if [[ "$preapply_verdict" == 'READY' || "$preapply_verdict" == 'REPAIR_REQUIRED' ]]; then
      timestamp="$(date -u +%Y%m%d%H%M%S)"
      backup_stem="/var/www/agency/shared/backups/editorial-issue-${ISSUE_NUMBER}-${timestamp}.sql"
      backup_file="${backup_stem}.gz"
      ssh "$remote_target" \
        "set -euo pipefail; cd /var/www/agency/current; mkdir -p /var/www/agency/shared/backups; vendor/bin/drush sql:dump --gzip --result-file='$backup_stem' >/dev/null; test -s '$backup_file'"
    fi

    remote_run apply "$remote_result"
    ssh "$remote_target" \
      "set -euo pipefail; cd /var/www/agency/current; vendor/bin/drush cr >/dev/null"
    ;;
esac

scp "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.status == "PASS"' "$ARTIFACT_DIR/result.json" >/dev/null

if [[ "$MODE" == 'apply' ]]; then
  tmp_result="$ARTIFACT_DIR/result.tmp.json"
  jq \
    --arg backup_file "${backup_file:-}" \
    '. + {cache_rebuilt:true, backup_file:(if $backup_file == "" then null else $backup_file end)}' \
    "$ARTIFACT_DIR/result.json" > "$tmp_result"
  mv "$tmp_result" "$ARTIFACT_DIR/result.json"
fi
