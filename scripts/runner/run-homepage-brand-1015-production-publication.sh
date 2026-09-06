#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${HOMEPAGE_BRAND_1015_PROD_MODE:-}"
SERVER_HOST="${SERVER_HOST:-}"
SERVER_USER="${SERVER_USER:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/homepage-brand-1015-production-publication}"
RUN_ID="${GITHUB_RUN_ID:-0}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"

case "$MODE" in
  dry-run|apply) ;;
  *) echo "Unsupported HOMEPAGE_BRAND_1015_PROD_MODE: $MODE" >&2; exit 1 ;;
esac
[[ "$RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUN_ATTEMPT" =~ ^[0-9]+$ ]]
[[ -n "$SERVER_HOST" ]]
[[ -n "$SERVER_USER" ]]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROFILE_LIBRARY="$SCRIPT_DIR/homepage-brand-1015.php"
PHP_RUNNER="$SCRIPT_DIR/homepage-brand-1015-production-publication-runner.php"
for file in "$PROFILE_LIBRARY" "$PHP_RUNNER"; do
  [[ -f "$file" ]]
  php -l "$file" >/dev/null
done
mkdir -p "$ARTIFACT_DIR"

remote_target="${SERVER_USER}@${SERVER_HOST}"
remote_stem="/tmp/agency-homepage-brand-1015-prod-${RUN_ID}-${RUN_ATTEMPT}"
remote_runner="${remote_stem}-runner.php"
remote_profile="${remote_stem}-profile.php"
remote_result="${remote_stem}-result.json"

cleanup_remote() {
  set +e
  ssh "$remote_target" \
    "rm -f '$remote_runner' '$remote_profile' '$remote_result'" \
    >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "$PHP_RUNNER" "$remote_target:$remote_runner" >/dev/null
scp "$PROFILE_LIBRARY" "$remote_target:$remote_profile" >/dev/null

backup_file=''
if [[ "$MODE" == 'apply' ]]; then
  timestamp="$(date -u +%Y%m%d%H%M%S)"
  backup_stem="/var/www/agency/shared/backups/homepage-brand-1015-prod-${timestamp}.sql"
  backup_file="${backup_stem}.gz"
  ssh "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; mkdir -p /var/www/agency/shared/backups; test -x vendor/bin/drush; vendor/bin/drush sql:dump --gzip --result-file='$backup_stem' >/dev/null; test -s '$backup_file'"
fi

set +e
ssh "$remote_target" \
  "set -euo pipefail; cd /var/www/agency/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_HOMEPAGE_BRAND_1015_PROD_MODE='$MODE' AGENCY_HOMEPAGE_BRAND_1015_PROD_RESULT_PATH='$remote_result' AGENCY_HOMEPAGE_BRAND_1015_LIBRARY_PATH='$remote_profile' vendor/bin/drush php:script '$remote_runner'"
drush_status=$?
set -e

if (( drush_status != 0 )); then
  if ssh "$remote_target" "test -f '$remote_result'"; then
    receipt_copy_status=0
    scp "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null || receipt_copy_status=$?
    if (( receipt_copy_status != 0 )); then
      echo "Failed to retrieve structured PROD result before propagating Drush exit status $drush_status." >&2
    fi
  fi
  exit "$drush_status"
fi

if [[ "$MODE" == 'apply' ]]; then
  ssh "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; vendor/bin/drush cr >/dev/null"
fi

scp "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null

jq -e --arg mode "$MODE" \
  '.status == "PASS"
   and .profile == "homepage-brand-1015"
   and .profile_sha256 == "bfbc8ac2d56af551509af254c797abe4437b6739b3a1d38ca369309717619da7"
   and .issue_number == 1015
   and .target == "PROD"
   and .bundle == "page"
   and .language == "fr"
   and .front == "/node/5"
   and .node.id == 5
   and (.content_sync_before == "active" or .content_sync_before == "released")
   and (
     if $mode == "apply" then
       .content_sync == "RELEASED"
       and .content_sync_after == "released"
       and (
         (.content_sync_before == "active" and .content_sync_reconciliation == "APPLIED")
         or (.content_sync_before == "released" and .content_sync_reconciliation == "NOT_REQUIRED")
       )
       and (.verdict == "APPLIED" or .verdict == "IDEMPOTENT")
       and (
         (.verdict == "APPLIED" and .prod_write == "MATERIALIZED")
         or (.verdict == "IDEMPOTENT" and .prod_write == "NONE")
       )
     else
       .content_sync_after == "NOT_APPLICABLE"
       and (
         (.content_sync_before == "active"
          and .content_sync == "ACTIVE_RECONCILIATION_REQUIRED"
          and .content_sync_reconciliation == "REQUIRED")
         or (.content_sync_before == "released"
             and .content_sync == "RELEASED"
             and .content_sync_reconciliation == "NOT_REQUIRED")
       )
       and (.verdict == "UPDATE_READY" or .verdict == "IDEMPOTENT")
       and .prod_write == "NONE"
     end
   )' \
  "$ARTIFACT_DIR/result.json" >/dev/null

if [[ "$MODE" == 'apply' ]]; then
  tmp="$ARTIFACT_DIR/result.tmp.json"
  jq --arg backup_file "$backup_file" \
    '. + {cache_rebuilt:true,backup_file:$backup_file,backup_verified:true}' \
    "$ARTIFACT_DIR/result.json" > "$tmp"
  mv "$tmp" "$ARTIFACT_DIR/result.json"
else
  tmp="$ARTIFACT_DIR/result.tmp.json"
  jq \
    '. + {cache_rebuilt:false,backup_file:"NONE",backup_verified:false}' \
    "$ARTIFACT_DIR/result.json" > "$tmp"
  mv "$tmp" "$ARTIFACT_DIR/result.json"
fi
