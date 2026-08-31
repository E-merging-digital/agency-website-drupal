#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

BASE='scripts/preproduction-refresh/governed-successor'
PROFILE="$BASE/profile.json"
CONTRACT="$BASE/orchestration-contract.py"
PROD_PLAN='scripts/preproduction-staging-import/real-one-shot/remote-prod-plan-readonly.sh'
PREPROD_PLAN="$BASE/remote-plan-observe.sh"
RUNTIME_DB_PROBE='scripts/preproduction-refresh/activation-capability/provisioning/runtime-db-identity-probe.py'
PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

AUTHORITY_ISSUE="${AUTHORITY_ISSUE:-}"
REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
SOURCE_PROD_RELEASE_SHA="${SOURCE_PROD_RELEASE_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PROD_SSH_HOST="${PROD_SSH_HOST:-}"
PROD_SSH_USER="${PROD_SSH_USER:-}"
PROD_SSH_KEY="${PROD_SSH_KEY:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_SSH_USER="${PREPROD_SSH_USER:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
RUNNER_TEMP="${RUNNER_TEMP:-}"
GITHUB_WORKSPACE="${GITHUB_WORKSPACE:-}"

[[ "$AUTHORITY_ISSUE" =~ ^[0-9]+$ && "$AUTHORITY_ISSUE" -gt 917 ]]
[[ "$REQUEST_ID" == plan-"$AUTHORITY_ISSUE"-*-r1 ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$SOURCE_PROD_RELEASE_SHA" == 'AUTO' ]]
[[ "$OPERATION_PROFILE" == 'agency-preprod-governed-refresh-successor-v1' ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == '1' ]]
[[ "${RUNNER_NAME:-}" == 'agency-browser-runner-01' ]]
[[ -n "$RUNNER_TEMP" && -n "$GITHUB_WORKSPACE" ]]
[[ "$PREPROD_SSH_USER" == 'agency-preprod' ]]
for path in "$PROFILE" "$CONTRACT" "$PROD_PLAN" "$PREPROD_PLAN" "$RUNTIME_DB_PROBE" "$PROD_TRUST" "$PREPROD_TRUST" "$PROD_SSH_KEY" "$PREPROD_SSH_KEY"; do
  [[ -f "$path" && ! -L "$path" ]]
done
[[ "$(git rev-parse HEAD)" == "$REPOSITORY_SHA" ]]
python3 "$CONTRACT" verify-repository >/dev/null
jq -e '.plan.mutation == "NONE" and .plan.prod_db_content_read == "NONE" and .delivery_boundary.data_activation_authority == "DISABLED"' "$PROFILE" >/dev/null

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
temp_abs="$(realpath -m "$RUNNER_TEMP")"
case "$temp_abs/" in "$workspace_abs/"*) echo 'RUNNER_TEMP must be outside workspace.' >&2; exit 70;; esac
runner_free="$(df -PB1 "$temp_abs" | awk 'NR==2 {print $4}')"
[[ "$runner_free" =~ ^[0-9]+$ && "$runner_free" -ge 1073741824 ]]

SERVER_HOST="$PROD_SSH_HOST" bash "$PROD_TRUST" VERIFY_ONLY >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$PREPROD_TRUST" >/dev/null

prod_ssh=(ssh -i "$PROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
preprod_ssh=(ssh -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)

prod_out="$("${prod_ssh[@]}" "$PROD_SSH_USER@$PROD_SSH_HOST" "bash -s -- AUTO" < "$PROD_PLAN")"
for required in 'prod_release_identity=PASS' 'prod_snapshot_route_metadata=PASS' 'prod_db_content_read=NONE' 'prod_snapshot=NOT_PERFORMED' 'prod_write=NONE'; do
  grep -Fxq "$required" <<<"$prod_out"
done
actual_prod="$(sed -n 's/^prod_release_sha=//p' <<<"$prod_out" | head -n1)"
[[ "$actual_prod" =~ ^[0-9a-f]{40}$ ]]

preprod_out="$("${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" "bash -s --" < "$PREPROD_PLAN")"
for required in \
  'control.state=EXACT' 'ingress.state=EXACT' 'authority_installer.state=EXACT' \
  'authority_abort.state=EXACT' 'capability_profile.state=EXACT' 'bundle_manifest.state=EXACT' \
  'transaction_authority_observation=UNOBSERVABLE_UNPRIVILEGED' \
  'transaction_authority_arm_gate=ROOT_INSTALLER_FAIL_CLOSED' \
  'PLAN_MUTATION=NONE' 'SUDO_EXECUTION=NONE' 'PREPROD_DB_MUTATION=NONE'; do
  grep -Fxq "$required" <<<"$preprod_out"
done

runtime_out="$("${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
  "env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/home/agency-preprod python3 -I -" < "$RUNTIME_DB_PROBE")"
grep -Fxq 'runtime_db_probe_state=OBSERVED' <<<"$runtime_out"
grep -Fxq 'runtime_db_name=agency_preprod' <<<"$runtime_out"

evidence_dir="$workspace_abs/artifacts/preprod-governed-successor"
evidence="$evidence_dir/plan.env"
mkdir -p "$evidence_dir"
cat > "$evidence.tmp" <<EOF
schema_version=1
authority_issue=$AUTHORITY_ISSUE
request_id_sha256=$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print $1}')
repository_sha=$REPOSITORY_SHA
observed_prod_release_sha=$actual_prod
operation_profile=$OPERATION_PROFILE
execution_mode=PLAN
trusted_runner_identity=PASS
prod_credential_readiness=PASS
preprod_credential_readiness=PASS
prod_pinned_trust=PASS
preprod_pinned_trust=PASS
installed_915_917_capability_metadata=PASS
persistent_data_activation_authority_expected=DISABLED
transaction_authority_observation=UNOBSERVABLE_UNPRIVILEGED
transaction_authority_apply_arm_gate=ROOT_INSTALLER_FAIL_CLOSED
runtime_db_identity=agency_preprod
capacity_readiness=PASS
expected_mutation_inventory=PASS
rollback_abort_inventory=PASS
prod_db_content_read=NONE
prod_snapshot=NOT_PERFORMED
prod_data_transfer=NONE
transaction_authority_install=NONE
authority_abort=NONE
preprod_db_mutation=NONE
backup=NONE
fence_mutation=NONE
activation=NONE
rollback=NONE
plan_mutation=NONE
prod_write=NONE
raw_prod_github_artifact=NONE
EOF
chmod 600 "$evidence.tmp"
mv -f "$evidence.tmp" "$evidence"
chmod 600 "$evidence"

printf '%s\n' \
  'PLAN_RESULT=PASS' \
  'PLAN_MUTATION=NONE' \
  'PROD_DB_CONTENT_READ=NONE' \
  'PROD_SNAPSHOT=NOT_PERFORMED' \
  'PROD_DATA_TRANSFER=NONE' \
  'TRANSACTION_AUTHORITY_INSTALL=NONE' \
  'AUTHORITY_ABORT=NONE' \
  'PREPROD_DB_MUTATION=NONE' \
  'PROD_WRITE=NONE' \
  'DATA_ACTIVATION_AUTHORITY=DISABLED'
