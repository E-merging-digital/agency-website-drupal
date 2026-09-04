#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${DRUPAL_2027_MODE:-}"
PAYLOAD_SHA256="${PAYLOAD_SHA256:-}"
PAYLOAD_FILE="${PAYLOAD_FILE:-}"
PREPROD_SERVER_HOST="${PREPROD_SERVER_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/drupal-2027-preprod-candidate}"
RUN_ID="${GITHUB_RUN_ID:-0}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"

case "$MODE" in
  inspect|dry-run|apply) ;;
  *) echo "Unsupported DRUPAL_2027_MODE: $MODE" >&2; exit 1 ;;
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
[[ -n "$PREPROD_SERVER_HOST" ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" ]]

actual_sha="$(sha256sum "$PAYLOAD_FILE" | awk '{print $1}')"
[[ "$actual_sha" == "$PAYLOAD_SHA256" ]] || {
  echo 'Local Drupal 2027 candidate payload hash mismatch.' >&2
  exit 1
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CANDIDATE_LIBRARY="$SCRIPT_DIR/drupal-2027-preprod-candidate.php"
PHP_RUNNER="$SCRIPT_DIR/drupal-2027-preprod-candidate-runner.php"
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_VERIFY='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

for file in "$CANDIDATE_LIBRARY" "$PHP_RUNNER"; do
  [[ -f "$file" ]]
  php -l "$file" >/dev/null
done
mkdir -p "$ARTIFACT_DIR"

PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" bash "$PREPROD_TRUST_PROVISION" PROVISION >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" \
  PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash "$PREPROD_TRUST_VERIFY" >/dev/null

ssh_common=(
  -i "$PREPROD_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15
)
remote_target="agency-preprod@$PREPROD_SERVER_HOST"
remote_stem="/tmp/agency-drupal-2027-1012-${RUN_ID}-${RUN_ATTEMPT}"
remote_runner="${remote_stem}-runner.php"
remote_candidate="${remote_stem}-candidate.php"
remote_payload="${remote_stem}-payload.json"
remote_result="${remote_stem}-result.json"

cleanup_remote() {
  set +e
  ssh "${ssh_common[@]}" "$remote_target" \
    "rm -f '$remote_runner' '$remote_candidate' '$remote_payload' '$remote_result'" \
    >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "${ssh_common[@]}" "$PHP_RUNNER" "$remote_target:$remote_runner" >/dev/null
scp "${ssh_common[@]}" "$CANDIDATE_LIBRARY" "$remote_target:$remote_candidate" >/dev/null
scp "${ssh_common[@]}" "$PAYLOAD_FILE" "$remote_target:$remote_payload" >/dev/null

remote_runtime_validate() {
  ssh "${ssh_common[@]}" "$remote_target" \
    "set -euo pipefail; test -x /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh; /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh >/dev/null"
}

remote_runtime_validate

ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; cd /var/www/agency-preprod/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_DRUPAL_2027_MODE='$MODE' AGENCY_DRUPAL_2027_PAYLOAD_SHA='$PAYLOAD_SHA256' AGENCY_DRUPAL_2027_PAYLOAD_PATH='$remote_payload' AGENCY_DRUPAL_2027_RESULT_PATH='$remote_result' AGENCY_DRUPAL_2027_LIBRARY_PATH='$remote_candidate' vendor/bin/drush php:script '$remote_runner'"

if [[ "$MODE" == apply ]]; then
  ssh "${ssh_common[@]}" "$remote_target" \
    "set -euo pipefail; cd /var/www/agency-preprod/current; vendor/bin/drush cr >/dev/null"
fi

remote_runtime_validate
scp "${ssh_common[@]}" "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null
jq -e \
  '.status == "PASS" and .profile == "drupal-2027-landing" and .candidate_id == "agency-drupal-2027-landing-1012" and .target == "PREPROD" and .bundle == "page" and .language == "fr" and .alias == "/fr/drupal-2027" and .collision_policy == "FAIL_CLOSED" and .content_sync == "NONE" and .prod_write == "NONE"' \
  "$ARTIFACT_DIR/result.json" >/dev/null

current_release="$(ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; basename \"\$(readlink -f /var/www/agency-preprod/current)\"")"
[[ "$current_release" =~ ^[A-Za-z0-9._-]+$ ]]

tmp="$ARTIFACT_DIR/result.tmp.json"
jq --arg current_release "$current_release" \
  '. + {preprod_runtime_validation:"PASS",preprod_current_release:$current_release}' \
  "$ARTIFACT_DIR/result.json" > "$tmp"
mv "$tmp" "$ARTIFACT_DIR/result.json"
