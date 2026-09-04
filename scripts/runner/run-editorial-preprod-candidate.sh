#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${EDITORIAL_MODE:-}"
ISSUE_NUMBER="${ISSUE_NUMBER:-}"
PAYLOAD_SHA256="${PAYLOAD_SHA256:-}"
PAYLOAD_FILE="${PAYLOAD_FILE:-}"
PREPROD_SERVER_HOST="${PREPROD_SERVER_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/editorial-preprod-candidate}"
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
[[ -n "$PREPROD_SERVER_HOST" ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" ]]

if [[ "$MODE" != inspect ]]; then
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
PUBLICATION_LIBRARY="$SCRIPT_DIR/editorial-publication.php"
CANDIDATE_LIBRARY="$SCRIPT_DIR/editorial-preprod-candidate.php"
PHP_RUNNER="$SCRIPT_DIR/editorial-preprod-candidate-runner.php"
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_VERIFY='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

for file in "$PUBLICATION_LIBRARY" "$CANDIDATE_LIBRARY" "$PHP_RUNNER"; do
  [[ -f "$file" ]]
done
php -l "$PUBLICATION_LIBRARY" >/dev/null
php -l "$CANDIDATE_LIBRARY" >/dev/null
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
remote_stem="/tmp/agency-editorial-preprod-${ISSUE_NUMBER}-${RUN_ID}-${RUN_ATTEMPT}"
remote_runner="${remote_stem}-runner.php"
remote_publication="${remote_stem}-publication.php"
remote_candidate="${remote_stem}-candidate.php"
remote_payload="${remote_stem}-payload.json"
remote_result="${remote_stem}-result.json"

cleanup_remote() {
  set +e
  ssh "${ssh_common[@]}" "$remote_target" \
    "rm -f '$remote_runner' '$remote_publication' '$remote_candidate' '$remote_payload' '$remote_result'" \
    >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "${ssh_common[@]}" "$PHP_RUNNER" "$remote_target:$remote_runner" >/dev/null
scp "${ssh_common[@]}" "$PUBLICATION_LIBRARY" "$remote_target:$remote_publication" >/dev/null
scp "${ssh_common[@]}" "$CANDIDATE_LIBRARY" "$remote_target:$remote_candidate" >/dev/null
if [[ "$MODE" != inspect ]]; then
  scp "${ssh_common[@]}" "$PAYLOAD_FILE" "$remote_target:$remote_payload" >/dev/null
fi

remote_runtime_validate() {
  ssh "${ssh_common[@]}" "$remote_target" \
    "set -euo pipefail; test -x /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh; /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh >/dev/null"
}

remote_runtime_validate

payload_path=''
payload_sha=''
if [[ "$MODE" != inspect ]]; then
  payload_path="$remote_payload"
  payload_sha="$PAYLOAD_SHA256"
fi

ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; cd /var/www/agency-preprod/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_EDITORIAL_MODE='$MODE' AGENCY_EDITORIAL_ISSUE='$ISSUE_NUMBER' AGENCY_EDITORIAL_PAYLOAD_SHA='$payload_sha' AGENCY_EDITORIAL_PAYLOAD_PATH='$payload_path' AGENCY_EDITORIAL_RESULT_PATH='$remote_result' AGENCY_EDITORIAL_LIBRARY_PATH='$remote_publication' AGENCY_EDITORIAL_CANDIDATE_LIBRARY_PATH='$remote_candidate' vendor/bin/drush php:script '$remote_runner'"

if [[ "$MODE" == apply ]]; then
  ssh "${ssh_common[@]}" "$remote_target" \
    "set -euo pipefail; cd /var/www/agency-preprod/current; vendor/bin/drush cr >/dev/null"
fi

remote_runtime_validate
scp "${ssh_common[@]}" "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.status == "PASS" and .target == "PREPROD" and .prod_write == "NONE"' \
  "$ARTIFACT_DIR/result.json" >/dev/null

current_release="$(ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; basename \"\$(readlink -f /var/www/agency-preprod/current)\"")"
[[ "$current_release" =~ ^[A-Za-z0-9._-]+$ ]]

tmp="$ARTIFACT_DIR/result.tmp.json"
jq --arg current_release "$current_release" \
  '. + {preprod_runtime_validation:"PASS",preprod_current_release:$current_release}' \
  "$ARTIFACT_DIR/result.json" > "$tmp"
mv "$tmp" "$ARTIFACT_DIR/result.json"
