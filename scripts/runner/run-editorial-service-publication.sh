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
[[ "$ISSUE_NUMBER" == '1117' ]] || {
  echo 'Service PROD V1 supports only issue #1117.' >&2
  exit 1
}
[[ "$RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUN_ATTEMPT" =~ ^[0-9]+$ ]]
[[ -n "$SERVER_HOST" ]]
[[ -n "$SERVER_USER" ]]

if [[ "$MODE" != inspect ]]; then
  [[ "$PAYLOAD_SHA256" =~ ^[0-9a-f]{64}$ ]] || {
    echo 'PAYLOAD_SHA256 must be lowercase SHA-256.' >&2
    exit 1
  }
  [[ -f "$PAYLOAD_FILE" ]]
  [[ "$(sha256sum "$PAYLOAD_FILE" | awk '{print $1}')" == "$PAYLOAD_SHA256" ]]
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVICE_LIBRARY="$SCRIPT_DIR/editorial-service-publication.php"
RUNTIME_SCRIPT="$SCRIPT_DIR/editorial-service-publication-runtime.php"
for required in "$SERVICE_LIBRARY" "$RUNTIME_SCRIPT"; do
  [[ -f "$required" ]]
done
php -l "$SERVICE_LIBRARY" >/dev/null
php -l "$RUNTIME_SCRIPT" >/dev/null
mkdir -p "$ARTIFACT_DIR"

remote_stem="/tmp/agency-editorial-service-${ISSUE_NUMBER}-${RUN_ID}-${RUN_ATTEMPT}"
remote_library="${remote_stem}-publication.php"
remote_runtime="${remote_stem}-runtime.php"
remote_payload="${remote_stem}-payload.json"
remote_result="${remote_stem}-result.json"
remote_preapply="${remote_stem}-preapply.json"
remote_target="${SERVER_USER}@${SERVER_HOST}"
ssh_opts=(-o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=15 -o ServerAliveCountMax=4)

cleanup_remote() {
  set +e
  ssh "${ssh_opts[@]}" "$remote_target" \
    "rm -f '$remote_library' '$remote_runtime' '$remote_payload' '$remote_result' '$remote_preapply'" \
    >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "${ssh_opts[@]}" "$SERVICE_LIBRARY" "$remote_target:$remote_library" >/dev/null
scp "${ssh_opts[@]}" "$RUNTIME_SCRIPT" "$remote_target:$remote_runtime" >/dev/null
if [[ "$MODE" != inspect ]]; then
  scp "${ssh_opts[@]}" "$PAYLOAD_FILE" "$remote_target:$remote_payload" >/dev/null
fi

run_remote() {
  local mode="$1"
  local payload_path=''
  local payload_sha=''
  if [[ "$mode" != inspect ]]; then
    payload_path="$remote_payload"
    payload_sha="$PAYLOAD_SHA256"
  fi
  ssh "${ssh_opts[@]}" "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_EDITORIAL_MODE='$mode' AGENCY_EDITORIAL_ISSUE='$ISSUE_NUMBER' AGENCY_EDITORIAL_PAYLOAD_SHA='$payload_sha' AGENCY_EDITORIAL_PAYLOAD_PATH='$payload_path' AGENCY_EDITORIAL_RESULT_PATH='$remote_result' AGENCY_EDITORIAL_SERVICE_LIBRARY_PATH='$remote_library' timeout 180s vendor/bin/drush php:script '$remote_runtime'"
}

if [[ "$MODE" == apply ]]; then
  run_remote dry-run
  ssh "${ssh_opts[@]}" "$remote_target" \
    "set -euo pipefail; cp '$remote_result' '$remote_preapply'"
  scp "${ssh_opts[@]}" "$remote_target:$remote_preapply" "$ARTIFACT_DIR/preapply.json" >/dev/null
  jq -e '.status == "PASS" and (.verdict == "READY" or .verdict == "IDEMPOTENT") and .target == "PROD" and .content_sync == "NONE" and .db_copy == "NONE"' \
    "$ARTIFACT_DIR/preapply.json" >/dev/null

  if jq -e '.verdict == "READY"' "$ARTIFACT_DIR/preapply.json" >/dev/null; then
    timestamp="$(date -u +%Y%m%d%H%M%S)"
    backup_stem="/var/www/agency/shared/backups/editorial-service-${ISSUE_NUMBER}-${timestamp}.sql"
    backup_file="${backup_stem}.gz"
    ssh "${ssh_opts[@]}" "$remote_target" \
      "set -euo pipefail; cd /var/www/agency/current; mkdir -p /var/www/agency/shared/backups; vendor/bin/drush sql:dump --gzip --result-file='$backup_stem' >/dev/null; test -s '$backup_file'"
  else
    backup_file='NONE_IDEMPOTENT'
  fi

  run_remote apply
  ssh "${ssh_opts[@]}" "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; vendor/bin/drush cr >/dev/null"
else
  run_remote "$MODE"
  backup_file='NONE'
fi

scp "${ssh_opts[@]}" "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null
jq -e --arg mode "$MODE" '
  .status == "PASS"
  and .target == "PROD"
  and .candidate_kind == "service"
  and .content_sync == "NONE"
  and .db_copy == "NONE"
  and (
    ($mode == "inspect" and .verdict == "READY")
    or ($mode == "dry-run" and (.verdict == "READY" or .verdict == "IDEMPOTENT"))
    or ($mode == "apply" and (.verdict == "APPLIED" or .verdict == "IDEMPOTENT"))
  )
' "$ARTIFACT_DIR/result.json" >/dev/null

tmp_result="$ARTIFACT_DIR/result.tmp.json"
jq --arg backup_file "$backup_file" '. + {backup_file:$backup_file}' \
  "$ARTIFACT_DIR/result.json" > "$tmp_result"
mv "$tmp_result" "$ARTIFACT_DIR/result.json"
