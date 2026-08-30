#!/usr/bin/env bash
set -Eeuo pipefail
PROFILE_ID='agency-preprod-refresh-capability-provision-v1'
BASE='scripts/preproduction-refresh/activation-capability'
PROFILE="$BASE/provisioning/profile.json"
CAPABILITY_PROFILE="$BASE/profile.json"
BUNDLE="$BASE/bundle.json"
OBSERVER="$BASE/provisioning/observe-host-state.sh"
EVALUATOR="$BASE/provisioning/evaluate-plan-evidence.py"
REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_SSH_USER="${PREPROD_SSH_USER:-agency-preprod}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
HELPER_SOURCE="$BASE/$(jq -r '.files.helper.path' "$BUNDLE")"
SUDOERS_SOURCE="$BASE/provisioning/$(basename "$(jq -r '.apply.sudoers_path' "$PROFILE")").sudoers"

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == '1' ]]
[[ "${RUNNER_NAME:-}" == 'agency-browser-runner-01' ]]
[[ "$PREPROD_SSH_USER" == 'agency-preprod' ]]
[[ -f "$PREPROD_SSH_KEY" ]]
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"

for path in "$PROFILE" "$CAPABILITY_PROFILE" "$BUNDLE" "$OBSERVER" "$EVALUATOR" \
  "$HELPER_SOURCE" \
  "$BASE/side_effect_hardening.py" \
  "$BASE/runtime_state_digest.py" \
  "$BASE/data-activation-authority.disabled.json" \
  "$BASE/nginx/agency-preprod-refresh-fence.conf" \
  "$BASE/nginx/agency-preprod-refresh-internal-readiness.conf" \
  "$SUDOERS_SOURCE"; do
  [[ -f "$path" && ! -L "$path" ]]
done

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

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh >/dev/null

observation="$(mktemp)"
cleanup() { rm -f -- "$observation"; }
trap cleanup EXIT HUP INT TERM

ssh -i "$PREPROD_SSH_KEY" \
  -o IdentitiesOnly=yes \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts" \
  "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
  "env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /bin/bash -s --" \
  < "$OBSERVER" > "$observation"

python3 "$EVALUATOR" --observation "$observation" --repository-root .

printf '%s\n' \
  "request_id_sha256=$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print $1}')" \
  "repository_sha=$REPOSITORY_SHA" \
  'operation_profile=agency-preprod-refresh-capability-provision-v1' \
  'EXACT_REPOSITORY_IDENTITY=PASS' \
  'PLAN_EVIDENCE=METADATA_ONLY' \
  'PLAN_MUTATION=NONE' \
  'PLAN_HELPER_EXECUTION=NONE' \
  'PLAN_SUDO_EXECUTION=NONE' \
  'PROD_ACCESS=NONE' \
  'PREPROD_DB_MUTATION=NONE' \
  'PREPROD_BACKUP=NONE' \
  'FENCE_MUTATION=NONE' \
  'NGINX_MUTATION=NONE' \
  'DATA_ACTIVATION_AUTHORITY=DISABLED'
