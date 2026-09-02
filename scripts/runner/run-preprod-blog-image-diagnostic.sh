#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ISSUE_NUMBER="${ISSUE_NUMBER:-}"
PREPROD_SERVER_HOST="${PREPROD_SERVER_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/preprod-blog-image-diagnostic}"

[[ "$ISSUE_NUMBER" == '966' ]] || {
  echo 'This diagnostic is bound to issue #966.' >&2
  exit 1
}
[[ -n "$PREPROD_SERVER_HOST" ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" ]]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIAGNOSTIC="$SCRIPT_DIR/preprod-blog-image-diagnostic.php"
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_VERIFY='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

[[ -f "$DIAGNOSTIC" ]]
php -l "$DIAGNOSTIC" >/dev/null
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

remote_runtime_validate() {
  ssh "${ssh_common[@]}" "$remote_target" \
    'set -euo pipefail; test -x /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh; /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh >/dev/null'
}

remote_runtime_validate

# Drush php:eval expects code without an opening PHP tag. The source is fixed and
# repository-owned; base64 transports it as one shell-safe read-only argument.
php_code="$(tail -n +2 "$DIAGNOSTIC")"
encoded_code="$(printf '%s' "$php_code" | base64 -w 0)"
[[ "$encoded_code" =~ ^[A-Za-z0-9+/=]+$ ]]

printf -v remote_command \
  "set -euo pipefail; cd /var/www/agency-preprod/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; code=\$(printf '%%s' '%s' | base64 -d); vendor/bin/drush php:eval \"\$code\"" \
  "$encoded_code"

ssh "${ssh_common[@]}" "$remote_target" "$remote_command" > "$ARTIFACT_DIR/result.json"

jq -e '
  .schema_version == 1
  and .status == "PASS"
  and .target == "PREPROD"
  and .prod_access == "NONE"
  and .prod_write == "NONE"
  and .preprod_destructive_mutation == "NONE"
  and (.sample | type == "object")
  and (.scope | type == "object")
  and (.root_cause | type == "string")
' "$ARTIFACT_DIR/result.json" >/dev/null

remote_runtime_validate

current_release="$(ssh "${ssh_common[@]}" "$remote_target" \
  'set -euo pipefail; basename "$(readlink -f /var/www/agency-preprod/current)"')"
[[ "$current_release" =~ ^[A-Za-z0-9._-]+$ ]]

tmp="$ARTIFACT_DIR/result.tmp.json"
jq --arg current_release "$current_release" \
  '. + {preprod_runtime_validation:"PASS",preprod_current_release:$current_release}' \
  "$ARTIFACT_DIR/result.json" > "$tmp"
mv "$tmp" "$ARTIFACT_DIR/result.json"
