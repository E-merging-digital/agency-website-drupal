#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${DRUPAL_2027_PROD_MODE:-}"
PAYLOAD_SHA256="${PAYLOAD_SHA256:-}"
PAYLOAD_FILE="${PAYLOAD_FILE:-}"
SERVER_HOST="${SERVER_HOST:-}"
SERVER_USER="${SERVER_USER:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/drupal-2027-production-publication}"
RUN_ID="${GITHUB_RUN_ID:-0}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"

case "$MODE" in
  dry-run|apply) ;;
  *) echo "Unsupported DRUPAL_2027_PROD_MODE: $MODE" >&2; exit 1 ;;
esac
[[ "$PAYLOAD_SHA256" =~ ^[0-9a-f]{64}$ ]] || {
  echo 'PAYLOAD_SHA256 must be a lowercase SHA-256.' >&2
  exit 1
}
[[ -f "$PAYLOAD_FILE" ]] || {
  echo 'PAYLOAD_FILE is required.' >&2
  exit 1
}
[[ "$RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUN_ATTEMPT" =~ ^[0-9]+$ ]]
[[ -n "$SERVER_HOST" ]]
[[ -n "$SERVER_USER" ]]

actual_sha="$(sha256sum "$PAYLOAD_FILE" | awk '{print $1}')"
[[ "$actual_sha" == "$PAYLOAD_SHA256" ]] || {
  echo 'Local Drupal 2027 approved payload hash mismatch.' >&2
  exit 1
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CANDIDATE_LIBRARY="$SCRIPT_DIR/drupal-2027-preprod-candidate.php"
PHP_RUNNER="$SCRIPT_DIR/drupal-2027-production-publication-runner.php"
for file in "$CANDIDATE_LIBRARY" "$PHP_RUNNER"; do
  [[ -f "$file" ]]
  php -l "$file" >/dev/null
done
mkdir -p "$ARTIFACT_DIR"

remote_target="${SERVER_USER}@${SERVER_HOST}"
remote_stem="/tmp/agency-drupal-2027-prod-1010-${RUN_ID}-${RUN_ATTEMPT}"
remote_runner="${remote_stem}-runner.php"
remote_candidate="${remote_stem}-candidate.php"
remote_payload="${remote_stem}-payload.json"
remote_result="${remote_stem}-result.json"

cleanup_remote() {
  set +e
  ssh "$remote_target" \
    "rm -f '$remote_runner' '$remote_candidate' '$remote_payload' '$remote_result'" \
    >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "$PHP_RUNNER" "$remote_target:$remote_runner" >/dev/null
scp "$CANDIDATE_LIBRARY" "$remote_target:$remote_candidate" >/dev/null
scp "$PAYLOAD_FILE" "$remote_target:$remote_payload" >/dev/null

backup_file=''
if [[ "$MODE" == 'apply' ]]; then
  timestamp="$(date -u +%Y%m%d%H%M%S)"
  backup_stem="/var/www/agency/shared/backups/drupal-2027-prod-1010-${timestamp}.sql"
  backup_file="${backup_stem}.gz"
  ssh "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; mkdir -p /var/www/agency/shared/backups; test -x vendor/bin/drush; vendor/bin/drush sql:dump --gzip --result-file='$backup_stem' >/dev/null; test -s '$backup_file'"
fi

ssh "$remote_target" \
  "set -euo pipefail; cd /var/www/agency/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_DRUPAL_2027_PROD_MODE='$MODE' AGENCY_DRUPAL_2027_PAYLOAD_SHA='$PAYLOAD_SHA256' AGENCY_DRUPAL_2027_PAYLOAD_PATH='$remote_payload' AGENCY_DRUPAL_2027_RESULT_PATH='$remote_result' AGENCY_DRUPAL_2027_LIBRARY_PATH='$remote_candidate' vendor/bin/drush php:script '$remote_runner'"

if [[ "$MODE" == 'apply' ]]; then
  ssh "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; vendor/bin/drush cr >/dev/null"
fi

scp "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null
jq -e \
  '.status == "PASS"
   and .profile == "drupal-2027-landing"
   and .candidate_id == "agency-drupal-2027-landing-1046"
   and .payload_sha256 == "ac96465c5717f78af76e368d8598399cbe997ed63d7cc753d575337c9321af83"
   and .target == "PROD"
   and .bundle == "page"
   and .language_mode == "FR_EN"
   and .aliases.fr == "/fr/drupal-2027"
   and .aliases.en == "/en/drupal-2027"
   and .collision_policy == "FAIL_CLOSED"
   and .content_sync == "NONE"' \
  "$ARTIFACT_DIR/result.json" >/dev/null

if [[ "$MODE" == 'apply' ]]; then
  tmp="$ARTIFACT_DIR/result.tmp.json"
  jq --arg backup_file "$backup_file" \
    '. + {cache_rebuilt:true,backup_file:$backup_file}' \
    "$ARTIFACT_DIR/result.json" > "$tmp"
  mv "$tmp" "$ARTIFACT_DIR/result.json"
fi
