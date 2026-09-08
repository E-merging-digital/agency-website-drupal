#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${HOMEPAGE_BRAND_1015_MODE:-}"
PREPROD_SERVER_HOST="${PREPROD_SERVER_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/homepage-brand-1015}"
RUN_ID="${GITHUB_RUN_ID:-0}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"

case "$MODE" in
  inspect|dry-run|apply) ;;
  *) echo "Unsupported HOMEPAGE_BRAND_1015_MODE: $MODE" >&2; exit 1 ;;
esac
[[ "$RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUN_ATTEMPT" =~ ^[0-9]+$ ]]
[[ -n "$PREPROD_SERVER_HOST" ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" ]]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROFILE_LIBRARY="$SCRIPT_DIR/homepage-brand-1015.php"
PHP_RUNNER="$SCRIPT_DIR/homepage-brand-1015-runner.php"
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_VERIFY='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

for file in "$PROFILE_LIBRARY" "$PHP_RUNNER"; do
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
remote_stem="/tmp/agency-homepage-brand-1015-${RUN_ID}-${RUN_ATTEMPT}"
remote_runner="${remote_stem}-runner.php"
remote_profile="${remote_stem}-profile.php"
remote_result="${remote_stem}-result.json"

cleanup_remote() {
  set +e
  ssh "${ssh_common[@]}" "$remote_target" \
    "rm -f '$remote_runner' '$remote_profile' '$remote_result'" \
    >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "${ssh_common[@]}" "$PHP_RUNNER" "$remote_target:$remote_runner" >/dev/null
scp "${ssh_common[@]}" "$PROFILE_LIBRARY" "$remote_target:$remote_profile" >/dev/null

remote_runtime_validate() {
  ssh "${ssh_common[@]}" "$remote_target" \
    "set -euo pipefail; test -x /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh; /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh >/dev/null"
}

remote_runtime_validate

set +e
ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; cd /var/www/agency-preprod/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_HOMEPAGE_BRAND_1015_MODE='$MODE' AGENCY_HOMEPAGE_BRAND_1015_RESULT_PATH='$remote_result' AGENCY_HOMEPAGE_BRAND_1015_LIBRARY_PATH='$remote_profile' vendor/bin/drush php:script '$remote_runner'"
drush_status=$?
set -e

if (( drush_status != 0 )); then
  if ssh "${ssh_common[@]}" "$remote_target" "test -f '$remote_result'"; then
    receipt_copy_status=0
    scp "${ssh_common[@]}" "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null || receipt_copy_status=$?
    if (( receipt_copy_status != 0 )); then
      echo "Failed to retrieve structured remote result before propagating Drush exit status $drush_status." >&2
    fi
  fi
  exit "$drush_status"
fi

if [[ "$MODE" == apply ]]; then
  ssh "${ssh_common[@]}" "$remote_target" \
    "set -euo pipefail; cd /var/www/agency-preprod/current; vendor/bin/drush cr >/dev/null"
fi

remote_runtime_validate
scp "${ssh_common[@]}" "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null

jq -e --arg mode "$MODE" \
  '.status == "PASS"
   and .profile == "homepage-brand-1015"
   and .issue_number == 1015
   and .target == "PREPROD"
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
     end
   )
   and .prod_write == "NONE"' \
  "$ARTIFACT_DIR/result.json" >/dev/null

current_release="$(ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; basename \"\$(readlink -f /var/www/agency-preprod/current)\"")"
[[ "$current_release" =~ ^[A-Za-z0-9._-]+$ ]]

tmp="$ARTIFACT_DIR/result.tmp.json"
jq --arg current_release "$current_release" \
  '. + {preprod_runtime_validation:"PASS",preprod_current_release:$current_release}' \
  "$ARTIFACT_DIR/result.json" > "$tmp"
mv "$tmp" "$ARTIFACT_DIR/result.json"
