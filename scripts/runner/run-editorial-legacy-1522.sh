#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

mode="${AGENCY_LEGACY_1522_MODE:?}"
case "$mode" in inspect|dry-run|apply) ;; *) exit 1 ;; esac
allowlist="${AGENCY_LEGACY_1522_ALLOWLIST:-}"
if [[ "$mode" == apply ]]; then
  [[ "$allowlist" =~ ^2:([0-9a-f]{64}|HOLD),3:([0-9a-f]{64}|HOLD),4:([0-9a-f]{64}|HOLD)$ ]]
else
  [[ -z "$allowlist" ]]
fi
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" =~ ^[1-9][0-9]*$ ]]
[[ "${PREPROD_SERVER_HOST:-}" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "${PREPROD_SSH_KEY:-}" ]]

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$root/scripts/runner/editorial-legacy-1522.php"
candidate="$root/assets/editorial/issue-1522-legacy-revisions.json"
images=(issue-1522-drupal-10-vers-11.webp issue-1522-drupal-symfony-laravel.webp issue-1522-refonte-site-checklist.webp)
test -f "$candidate"
test -f "$script"
php -l "$script" >/dev/null
mkdir -p artifacts/editorial-legacy-1522

PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" bash "$root/scripts/preproduction-ssh-trust/manage-known-host.sh" PROVISION >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash "$root/scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh" >/dev/null

ssh_args=(-i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
target="agency-preprod@$PREPROD_SERVER_HOST"
stem="/tmp/agency-legacy-1522-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
remote_script="${stem}-runner.php"
remote_json="${stem}-candidate.json"
remote_assets="${stem}-assets"
remote_result="${stem}-result.json"

cleanup() {
  set +e
  ssh "${ssh_args[@]}" "$target" "rm -f '$remote_script' '$remote_json' '$remote_result' '$remote_assets'/*.webp; rmdir '$remote_assets' 2>/dev/null || true" >/dev/null 2>&1
}
trap cleanup EXIT

ssh "${ssh_args[@]}" "$target" "set -e; mkdir -m 700 '$remote_assets'" >/dev/null
scp "${ssh_args[@]}" "$script" "$target:$remote_script" >/dev/null
scp "${ssh_args[@]}" "$candidate" "$target:$remote_json" >/dev/null
for image in "${images[@]}"; do
  scp "${ssh_args[@]}" "$root/assets/editorial/$image" "$target:$remote_assets/$image" >/dev/null
done

runtime_check='cd /var/www/agency-preprod/current && test -x scripts/preproduction/validate-runtime.sh && scripts/preproduction/validate-runtime.sh >/dev/null'
ssh "${ssh_args[@]}" "$target" "set -e; $runtime_check"
ssh "${ssh_args[@]}" "$target" \
  "set -e; cd /var/www/agency-preprod/current; test -x vendor/bin/drush; AGENCY_LEGACY_1522_MODE='$mode' AGENCY_LEGACY_1522_ALLOWLIST='$allowlist' AGENCY_LEGACY_1522_CANDIDATE='$remote_json' AGENCY_LEGACY_1522_ASSETS='$remote_assets' AGENCY_LEGACY_1522_RESULT='$remote_result' vendor/bin/drush php:script '$remote_script'"
if [[ "$mode" == apply ]]; then
  ssh "${ssh_args[@]}" "$target" "set -e; cd /var/www/agency-preprod/current; vendor/bin/drush cr >/dev/null"
fi
ssh "${ssh_args[@]}" "$target" "set -e; $runtime_check"
scp "${ssh_args[@]}" "$target:$remote_result" artifacts/editorial-legacy-1522/result.json >/dev/null
jq -e '
  ([.articles[] | select(.status == "PASS")] | length) as $passed |
  .target == "PREPROD" and .prod_write == "NONE" and .held_article == "IA_PREPROD_IDENTITY" and
  ([.articles[].candidate_index] == [2,3,4]) and
  (.status == (if $passed == 3 then "PASS" elif $passed > 0 then "PARTIAL" else "CONTROLLED_STOP" end)) and
  ([.articles[] | if .status == "PASS" then (.proof | test("^[0-9a-f]{64}$")) else (.status == "CONTROLLED_STOP" and .proof == "HOLD") end] | all) and
  (.eligible == ([.articles[] | "\(.candidate_index):\(.proof)"] | join(",")))
' artifacts/editorial-legacy-1522/result.json >/dev/null
