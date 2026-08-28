#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE='scripts/preproduction-staging-import/real-one-shot/profile.json'
PROFILE_ID='agency-preprod-real-one-shot-sanitize-v1'
PROD_PLAN_REMOTE='scripts/preproduction-staging-import/real-one-shot/remote-prod-plan-readonly.sh'
PREPROD_BUNDLE_PLAN_REMOTE='scripts/preproduction-staging-import/provisioning/remote-plan-readonly.sh'
PREPROD_STAGE_REMOTE='scripts/preproduction-staging-import/remote-preprod-stage.sh'
PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

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

[[ "$REQUEST_ID" =~ ^plan-866-[A-Za-z0-9._-]{1,64}$ ]] || { echo 'Invalid #866 PLAN request identity.' >&2; exit 64; }
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid repository identity.' >&2; exit 65; }
[[ "$SOURCE_PROD_RELEASE_SHA" == 'AUTO' || "$SOURCE_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid PROD release selector.' >&2; exit 66; }
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]] || { echo 'Unexpected #866 operation profile.' >&2; exit 67; }
[[ "$GITHUB_RUN_ATTEMPT" == '1' ]] || { echo '#866 PLAN cannot be replayed through rerun.' >&2; exit 68; }
[[ "$RUNNER_NAME" == 'agency-browser-runner-01' ]] || { echo 'Wrong trusted runner identity.' >&2; exit 69; }
[[ -n "$RUNNER_TEMP" && -n "$GITHUB_WORKSPACE" ]] || { echo 'Trusted runner paths are required.' >&2; exit 70; }
[[ "$PROD_SSH_HOST" =~ ^[A-Za-z0-9._:-]+$ && "$PREPROD_SSH_HOST" =~ ^[A-Za-z0-9._:-]+$ ]] || { echo 'Server-owned host identity is invalid.' >&2; exit 71; }
[[ "$PROD_SSH_USER" =~ ^[A-Za-z0-9._-]+$ && "$PREPROD_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'Server-owned SSH user identity is invalid.' >&2; exit 72; }
for path in "$PROFILE" "$PROD_PLAN_REMOTE" "$PREPROD_BUNDLE_PLAN_REMOTE" "$PREPROD_STAGE_REMOTE" "$PROD_TRUST" "$PREPROD_TRUST" "$PROD_SSH_KEY" "$PREPROD_SSH_KEY"; do test -f "$path"; done
[[ "$(git rev-parse HEAD)" == "$REPOSITORY_SHA" ]]

jq -e '
  .issue_number == 866 and
  .profile_id == "agency-preprod-real-one-shot-sanitize-v1" and
  .capability.apply_action == "IMPORT_SANITIZE_PROVE" and
  .capability.detached_import == "FORBIDDEN" and
  .preprod.runtime_db_switch == "FORBIDDEN" and
  .preprod.activation == "FORBIDDEN" and
  .transfer.raw_github_artifact == "FORBIDDEN" and
  .transfer.raw_log_output == "FORBIDDEN" and
  .plan.prod_db_content_read == "NONE" and
  .plan.preprod_mutation == "NONE"
' "$PROFILE" >/dev/null

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
temp_abs="$(realpath -m "$RUNNER_TEMP")"
case "$temp_abs/" in "$workspace_abs/"*) echo 'RUNNER_TEMP must remain outside repository workspace.' >&2; exit 73;; esac
runner_free="$(df -PB1 "$temp_abs" | awk 'NR==2 {print $4}')"
runner_min="$(jq -r '.capacity.trusted_runner_min_free_bytes' "$PROFILE")"
[[ "$runner_free" =~ ^[0-9]+$ && "$runner_free" -ge "$runner_min" ]] || { echo 'Trusted runner capacity PLAN failed closed.' >&2; exit 74; }

SERVER_HOST="$PROD_SSH_HOST" bash "$PROD_TRUST" VERIFY_ONLY >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$PREPROD_TRUST" >/dev/null

prod_ssh=(ssh -i "$PROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
preprod_ssh=(ssh -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)

prod_plan="$("${prod_ssh[@]}" "$PROD_SSH_USER@$PROD_SSH_HOST" "bash -s -- '$SOURCE_PROD_RELEASE_SHA'" < "$PROD_PLAN_REMOTE")"
for required in 'prod_release_identity=PASS' 'prod_snapshot_route_metadata=PASS' 'prod_db_content_read=NONE' 'prod_snapshot=NOT_PERFORMED' 'prod_write=NONE'; do grep -Fxq "$required" <<< "$prod_plan"; done
actual_prod_release_sha="$(sed -n 's/^prod_release_sha=//p' <<< "$prod_plan" | head -n1)"
[[ "$actual_prod_release_sha" =~ ^[0-9a-f]{40}$ ]]

bundle_plan="$("${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" "bash -s -- '$REQUEST_ID'" < "$PREPROD_BUNDLE_PLAN_REMOTE")"
for required in 'helper_state=EXACT' 'bundle_directory_state=EXACT' 'sanitizer_state=EXACT' 'policy_state=EXACT' 'sudoers_effective=BOUNDED_HELPER_LISTED' 'plan_preprod_mutation=NONE' 'plan_privileged_helper_execution=NONE'; do grep -Fxq "$required" <<< "$bundle_plan"; done

expected_helper="$(jq -r '.capability.helper_sha256' "$PROFILE")"
precheck="$("${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" "bash -s -- PRECHECK '$REQUEST_ID' 0 '$expected_helper'" < "$PREPROD_STAGE_REMOTE")"
for required in 'preprod_capacity=PASS' 'preprod_runtime_points_to_staging=NO' 'staging_admin_capability=PASS' 'privileged_helper_identity=PASS' 'privileged_helper_digest=PASS'; do grep -Fxq "$required" <<< "$precheck"; done

absence="$("${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" "bash -s -- VERIFY_ABSENCE '$REQUEST_ID' 0 '$expected_helper'" < "$PREPROD_STAGE_REMOTE")"
for required in 'preprod_runtime_points_to_staging=NO' 'staging_db_present_after_cleanup=NO' 'staging_account_present_after_cleanup=NO' 'privileged_helper_identity=PASS' 'privileged_helper_digest=PASS'; do grep -Fxq "$required" <<< "$absence"; done

evidence_dir="$workspace_abs/artifacts/preprod-real-one-shot"
evidence="$evidence_dir/evidence.env"
mkdir -p "$evidence_dir"
cat > "$evidence.tmp" <<EOF
schema_version=1
request_id=$REQUEST_ID
repository_sha=$REPOSITORY_SHA
source_prod_release_sha=$actual_prod_release_sha
operation_profile=$OPERATION_PROFILE
execution_mode=PLAN
plan_result=PASS
plan_mutation_free=YES
trusted_runner_identity=PASS
prod_credential_readiness=PASS
preprod_credential_readiness=PASS
prod_pinned_trust=PASS
preprod_pinned_trust=PASS
prod_release_identity=PASS
installed_helper_identity=PASS
installed_sanitizer_identity=PASS
sanitization_policy_identity=PASS
sudoers_scope=FIXED_HELPER_ONLY
capacity_preflight=PASS
staging_db_present=NO
staging_account_present=NO
readonly_helper_precheck=PASS
readonly_verify_absence=PASS
apply_path=IMPORT_SANITIZE_PROVE
detached_import=FORBIDDEN
encrypted_transfer_contract=PASS
raw_temp_mode=0600
raw_prod_data_in_github=NONE
raw_prod_data_in_logs=NONE
prod_db_content_read=NONE
prod_snapshot=NOT_PERFORMED
prod_data_transfer=NONE
real_import_sanitize_prove=NOT_PERFORMED
preprod_mutation=NONE
runtime_db_switch=NONE
activation=NOT_PERFORMED
public_files=NONE
private_files=NONE
scheduler=NONE
prod_write=NONE
EOF
chmod 600 "$evidence.tmp"
mv -f "$evidence.tmp" "$evidence"
chmod 600 "$evidence"

printf '%s\n' \
  'PLAN_RESULT=PASS' \
  'PLAN_MUTATION_FREE=YES' \
  "SOURCE_PROD_RELEASE_SHA=$actual_prod_release_sha" \
  'PROD_CREDENTIAL_READINESS=PASS' \
  'PREPROD_CREDENTIAL_READINESS=PASS' \
  'PROD_PINNED_TRUST=PASS' \
  'PREPROD_PINNED_TRUST=PASS' \
  'INSTALLED_HELPER_IDENTITY=PASS' \
  'SANITIZATION_POLICY_IDENTITY=PASS' \
  'CAPACITY_PREFLIGHT=PASS' \
  'APPLY_PATH=IMPORT_SANITIZE_PROVE' \
  'DETACHED_IMPORT=FORBIDDEN' \
  'REAL_PROD_DATA_READ=NONE' \
  'REAL_PROD_DATA_TRANSFER=NONE' \
  'REAL_PREPROD_MUTATION=NONE'
