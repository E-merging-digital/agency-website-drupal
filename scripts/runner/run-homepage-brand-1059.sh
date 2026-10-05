#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${HOMEPAGE_BRAND_1059_MODE:-}"
PREPROD_SERVER_HOST="${PREPROD_SERVER_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/homepage-brand-1059}"
RUN_ID="${GITHUB_RUN_ID:-0}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"

case "$MODE" in
  inspect|dry-run|apply) ;;
  *) echo "Unsupported HOMEPAGE_BRAND_1059_MODE: $MODE" >&2; exit 1 ;;
esac
[[ "$RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUN_ATTEMPT" =~ ^[0-9]+$ ]]
[[ -n "$PREPROD_SERVER_HOST" ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" ]]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_RUNNER="$SCRIPT_DIR/homepage-brand-1059.php"
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_VERIFY='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

php -l "$PHP_RUNNER" >/dev/null
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
remote_stem="/tmp/agency-homepage-brand-1059-${RUN_ID}-${RUN_ATTEMPT}"
remote_runner="${remote_stem}-runner.php"
remote_result="${remote_stem}-result.json"

cleanup_remote() {
  set +e
  ssh "${ssh_common[@]}" "$remote_target" "rm -f '$remote_runner' '$remote_result'" >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "${ssh_common[@]}" "$PHP_RUNNER" "$remote_target:$remote_runner" >/dev/null

remote_runtime_validate() {
  ssh "${ssh_common[@]}" "$remote_target" \
    "set -euo pipefail; test -x /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh; /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh >/dev/null"
}

remote_runtime_validate
set +e
ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; cd /var/www/agency-preprod/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_HOMEPAGE_BRAND_1059_MODE='$MODE' AGENCY_HOMEPAGE_BRAND_1059_RESULT_PATH='$remote_result' vendor/bin/drush php:script '$remote_runner'"
drush_status=$?
set -e

if (( drush_status != 0 )); then
  if ssh "${ssh_common[@]}" "$remote_target" "test -f '$remote_result'"; then
    scp "${ssh_common[@]}" "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null || true
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
   and .profile == "homepage-brand-1059"
   and .profile_sha256 == "e7f6e184a31048b4fcea7f126b6e24e9aa23db46522cd2537f441fd2f3218390"
   and .issue_number == 1059
   and .target == "PREPROD"
   and .bundle == "page"
   and .language == "en"
   and .front == "/node/5"
   and .node.id == 5
   and .fr_mutated == "NO"
   and .content_sync == "RELEASED_UNCHANGED"
   and .prod_access == "NONE"
   and .prod_write == "NONE"
   and (
     if $mode == "inspect" then
       (.en_node_translation == "EXISTS" or .en_node_translation == "MISSING")
       and (.en_hero_translation == "EXISTS" or .en_hero_translation == "MISSING")
       and (.en_axes_translation == "EXISTS" or .en_axes_translation == "MISSING")
     else
       .en_node_translation == "EXISTS"
       and .en_hero_translation == "EXISTS"
       and .en_axes_translation == "EXISTS"
       and (.verdict == "UPDATE_READY" or .verdict == "IDEMPOTENT" or .verdict == "APPLIED")
     end
   )' "$ARTIFACT_DIR/result.json" >/dev/null

current_release="$(ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; basename \"\$(readlink -f /var/www/agency-preprod/current)\"")"
[[ "$current_release" =~ ^[A-Za-z0-9._-]+$ ]]

tmp="$ARTIFACT_DIR/result.tmp.json"
jq --arg current_release "$current_release" \
  '. + {preprod_runtime_validation:"PASS",preprod_current_release:$current_release}' \
  "$ARTIFACT_DIR/result.json" > "$tmp"
mv "$tmp" "$ARTIFACT_DIR/result.json"
