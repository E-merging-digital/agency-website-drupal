#!/usr/bin/env bash
set -Eeuo pipefail
PROFILE_ID='agency-preprod-refresh-capability-provision-v1'
BASE='scripts/preproduction-refresh/activation-capability'
PROFILE="$BASE/provisioning/profile.json"
CAPABILITY_PROFILE="$BASE/profile.json"
BUNDLE="$BASE/bundle.json"
OBSERVER="$BASE/provisioning/observe-host-state.sh"
EVALUATOR="$BASE/provisioning/evaluate-plan-evidence.py"
VHOST_SELECTOR="$BASE/provisioning/nginx-vhost-selector.py"
VHOST_SELECTOR_BLOB='a17e3f932b9a5e7ec4978f3758ff0bf5bbae9c79'
RUNTIME_DB_PROBE="$BASE/provisioning/runtime-db-identity-probe.py"
RUNTIME_DB_PROBE_BLOB='197a2da8173f902eb5f43b96f2f14399d00df220'
REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_SSH_USER="${PREPROD_SSH_USER:-agency-preprod}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
PREPROD_KNOWN_HOSTS_FILE="${PREPROD_KNOWN_HOSTS_FILE:-$HOME/.ssh/known_hosts}"
HELPER_SOURCE="$BASE/$(jq -r '.files.helper.path' "$BUNDLE")"
SUDOERS_SOURCE="$BASE/provisioning/agency-preprod-refresh-control.sudoers"
SUDOERS_SOURCE_BLOB='193f05b9dc0422d62e0104e95a8f5444f34ec17c'
SUDOERS_SOURCE_SHA256='d3997b2f9b3a0b615d082bbc65b7a49abef38f3e1523506a23083f9d4f217b9b'

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == '1' ]]
[[ "${RUNNER_OS:-}" == 'Linux' ]]
[[ "${RUNNER_ARCH:-}" == 'X64' ]]
[[ "${RUNNER_ENVIRONMENT:-}" == 'github-hosted' ]]
[[ -n "${RUNNER_TEMP:-}" && -d "$RUNNER_TEMP" ]]
[[ "$PREPROD_SSH_USER" == 'agency-preprod' ]]
[[ "$PREPROD_SSH_KEY" == "$RUNNER_TEMP/"* ]]
[[ "$PREPROD_KNOWN_HOSTS_FILE" == "$RUNNER_TEMP/"* ]]
[[ -f "$PREPROD_SSH_KEY" && ! -L "$PREPROD_SSH_KEY" ]]
[[ -f "$PREPROD_KNOWN_HOSTS_FILE" && ! -L "$PREPROD_KNOWN_HOSTS_FILE" ]]
[[ "$(stat -c '%a' "$PREPROD_SSH_KEY")" == '600' ]]
[[ "$(stat -c '%a' "$PREPROD_KNOWN_HOSTS_FILE")" == '600' ]]
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"

for path in "$PROFILE" "$CAPABILITY_PROFILE" "$BUNDLE" "$OBSERVER" "$EVALUATOR" \
  "$VHOST_SELECTOR" "$RUNTIME_DB_PROBE" \
  "$HELPER_SOURCE" \
  "$BASE/side_effect_hardening.py" \
  "$BASE/runtime_state_digest.py" \
  "$BASE/data-activation-authority.disabled.json" \
  "$BASE/nginx/agency-preprod-refresh-fence.conf" \
  "$BASE/nginx/agency-preprod-refresh-internal-readiness.conf" \
  "$SUDOERS_SOURCE"; do
  [[ -f "$path" && ! -L "$path" ]]
done
[[ "$(git rev-parse "HEAD:$VHOST_SELECTOR")" == "$VHOST_SELECTOR_BLOB" ]]
[[ "$(git rev-parse "HEAD:$RUNTIME_DB_PROBE")" == "$RUNTIME_DB_PROBE_BLOB" ]]
[[ "$(git rev-parse "HEAD:$SUDOERS_SOURCE")" == "$SUDOERS_SOURCE_BLOB" ]]
[[ "$(sha256sum "$SUDOERS_SOURCE" | awk '{print $1}')" == "$SUDOERS_SOURCE_SHA256" ]]

jq -e '
  .issue_number == 874 and
  .parent_issue == 816 and
  .profile_id == "agency-preprod-refresh-capability-provision-v1" and
  .plan.preprod_mutation == "NONE" and
  .plan.helper_execution == "NONE" and
  .plan.sudo_execution == "NONE" and
  .plan.state_detection == "METADATA_ONLY" and
  .apply.data_activation_authority_after_apply == "DISABLED" and
  .apply.real_data_activation == "FORBIDDEN"
' "$PROFILE" >/dev/null
jq -e '
  .issue_number == 874 and
  .parent_issue == 816 and
  .activation.runtime_database == "agency_preprod" and
  .sudoers.deploy_identity == "agency-preprod" and
  .data_activation_authority.installed_state == "DISABLED" and
  .data_activation_authority.issue_874_grants_activation_authority == false
' "$CAPABILITY_PROFILE" >/dev/null
jq -e '
  .issue_number == 874 and
  .data_activation_authority_after_provisioning == "DISABLED"
' "$BUNDLE" >/dev/null

for pair in \
  "helper:$HELPER_SOURCE" \
  "side_effect_hardening:$BASE/side_effect_hardening.py" \
  "runtime_state_digest:$BASE/runtime_state_digest.py" \
  "disabled_authority_state:$BASE/data-activation-authority.disabled.json" \
  "fence_snippet:$BASE/nginx/agency-preprod-refresh-fence.conf" \
  "internal_readiness:$BASE/nginx/agency-preprod-refresh-internal-readiness.conf" \
  "capability_profile:$CAPABILITY_PROFILE"; do
  key="${pair%%:*}"; path="${pair#*:}"
  expected="$(jq -r --arg key "$key" '.digests[$key]' "$PROFILE")"
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]]
  [[ "$(sha256sum "$path" | awk '{print $1}')" == "$expected" ]]
done

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$PREPROD_KNOWN_HOSTS_FILE" \
  bash scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh >/dev/null

observation="$(mktemp)"
cleanup() { rm -f -- "$observation"; }
trap cleanup EXIT HUP INT TERM

ssh -i "$PREPROD_SSH_KEY" \
  -o IdentitiesOnly=yes \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$PREPROD_KNOWN_HOSTS_FILE" \
  "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
  "env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /bin/bash -s --" \
  < "$OBSERVER" > "$observation"

ssh -i "$PREPROD_SSH_KEY" \
  -o IdentitiesOnly=yes \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$PREPROD_KNOWN_HOSTS_FILE" \
  "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
  "env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin python3 -I - OBSERVE" \
  < "$VHOST_SELECTOR" >> "$observation"

ssh -i "$PREPROD_SSH_KEY" \
  -o IdentitiesOnly=yes \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$PREPROD_KNOWN_HOSTS_FILE" \
  "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
  "env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/home/agency-preprod python3 -I -" \
  < "$RUNTIME_DB_PROBE" >> "$observation"

python3 "$EVALUATOR" --observation "$observation" --repository-root .

printf '%s\n' \
  "request_id_sha256=$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print $1}')" \
  "repository_sha=$REPOSITORY_SHA" \
  'operation_profile=agency-preprod-refresh-capability-provision-v1' \
  'EXACT_REPOSITORY_IDENTITY=PASS' \
  'PLAN_RUNNER=ubuntu-24.04' \
  'PLAN_RUNNER_ENVIRONMENT=github-hosted' \
  'PREPROD_SSH_USER=agency-preprod' \
  'ROOT_REMOTE_EXECUTION=FORBIDDEN' \
  'PINNED_HOST_TRUST=PASS' \
  'PLAN_EVIDENCE=METADATA_ONLY' \
  'PLAN_MUTATION=NONE' \
  'PLAN_HELPER_EXECUTION=NONE' \
  'PLAN_SUDO_EXECUTION=NONE' \
  'PROD_ACCESS=NONE' \
  'RAW_PROD_DATA_ON_GITHUB_HOSTED=FORBIDDEN' \
  'PREPROD_DB_MUTATION=NONE' \
  'PREPROD_BACKUP=NONE' \
  'FENCE_MUTATION=NONE' \
  'NGINX_MUTATION=NONE' \
  'DATA_ACTIVATION_AUTHORITY=DISABLED'
