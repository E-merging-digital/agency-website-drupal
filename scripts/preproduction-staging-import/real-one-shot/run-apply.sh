#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE='scripts/preproduction-staging-import/real-one-shot/profile.json'
PROFILE_ID='agency-preprod-real-one-shot-sanitize-v1'
PROD_REMOTE='scripts/production-readonly-snapshot/remote-stream.sh'
PREPROD_REMOTE='scripts/preproduction-staging-import/remote-preprod-stage.sh'
PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
HELPER_PATH='/usr/local/sbin/agency-preprod-staging-db'

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
GITHUB_RUN_ID="${GITHUB_RUN_ID:-}"
GITHUB_RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-}"

[[ "$REQUEST_ID" =~ ^apply-866-[A-Za-z0-9._-]{1,64}$ ]] || { echo 'Invalid #866 APPLY request identity.' >&2; exit 64; }
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid repository identity.' >&2; exit 65; }
[[ "$SOURCE_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid PROD release identity.' >&2; exit 66; }
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]] || { echo 'Unexpected #866 operation profile.' >&2; exit 67; }
[[ "$GITHUB_RUN_ATTEMPT" == '1' ]] || { echo '#866 APPLY cannot be replayed through rerun.' >&2; exit 68; }
[[ "$RUNNER_NAME" == 'agency-browser-runner-01' ]] || { echo 'Wrong trusted runner identity.' >&2; exit 69; }
[[ -n "$RUNNER_TEMP" && -n "$GITHUB_WORKSPACE" ]] || { echo 'Trusted runner paths are required.' >&2; exit 70; }
for path in "$PROFILE" "$PROD_REMOTE" "$PREPROD_REMOTE" "$PROD_TRUST" "$PREPROD_TRUST" "$PROD_SSH_KEY" "$PREPROD_SSH_KEY"; do test -f "$path"; done
[[ "$(git rev-parse HEAD)" == "$REPOSITORY_SHA" ]]
jq -e '.issue_number == 866 and .capability.apply_action == "IMPORT_SANITIZE_PROVE" and .capability.detached_import == "FORBIDDEN" and .apply.runtime_db_switch == "FORBIDDEN" and .apply.activation == "FORBIDDEN"' "$PROFILE" >/dev/null

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
temp_abs="$(realpath -m "$RUNNER_TEMP")"
case "$temp_abs/" in "$workspace_abs/"*) echo 'RUNNER_TEMP must remain outside repository workspace.' >&2; exit 71;; esac
raw="$temp_abs/agency-866-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.sql"
prod_stderr="$temp_abs/agency-866-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.prod.stderr"
preprod_output="$temp_abs/agency-866-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.preprod.out"
preprod_stderr="$temp_abs/agency-866-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.preprod.stderr"
evidence_dir="$workspace_abs/artifacts/preprod-real-one-shot"
evidence="$evidence_dir/evidence.env"
expected_helper="$(jq -r '.capability.helper_sha256' "$PROFILE")"
expected_policy="$(jq -r '.capability.policy_sha256' "$PROFILE")"
expected_sanitizer="$(jq -r '.capability.sanitizer_sha256' "$PROFILE")"

prod_ssh=(ssh -i "$PROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
preprod_ssh=(ssh -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)

preprod_action() {
  local action="$1" bytes="$2"
  "${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" "bash -s -- '$action' '$REQUEST_ID' '$bytes' '$expected_helper'" < "$PREPROD_REMOTE"
}

staging_may_exist=0
cleanup() {
  local original="$?" final="$?"
  trap - EXIT HUP INT TERM
  set +e
  if [[ "$staging_may_exist" -eq 1 ]]; then
    preprod_action CLEANUP 0 >/dev/null 2>&1 || final=98
    absence="$(preprod_action VERIFY_ABSENCE 0 2>/dev/null)" || final=98
    grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "${absence:-}" || final=98
    grep -Fxq 'staging_account_present_after_cleanup=NO' <<< "${absence:-}" || final=98
    grep -Fxq 'preprod_runtime_points_to_staging=NO' <<< "${absence:-}" || final=98
  fi
  rm -f -- "$raw" "$prod_stderr" "$preprod_output" "$preprod_stderr"
  [[ ! -e "$raw" && ! -e "$prod_stderr" && ! -e "$preprod_output" && ! -e "$preprod_stderr" ]] || final=97
  [[ "$original" -ne 0 ]] && final="$original"
  exit "$final"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

SERVER_HOST="$PROD_SSH_HOST" bash "$PROD_TRUST" VERIFY_ONLY >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$PREPROD_TRUST" >/dev/null

runner_free="$(df -PB1 "$temp_abs" | awk 'NR==2 {print $4}')"
runner_min="$(jq -r '.capacity.trusted_runner_min_free_bytes' "$PROFILE")"
[[ "$runner_free" =~ ^[0-9]+$ && "$runner_free" -ge "$runner_min" ]] || { echo 'Trusted runner capacity preflight failed.' >&2; exit 72; }

first_precheck="$(preprod_action PRECHECK 0)"
for required in 'preprod_capacity=PASS' 'preprod_runtime_points_to_staging=NO' 'staging_admin_capability=PASS' 'privileged_helper_identity=PASS' 'privileged_helper_digest=PASS'; do grep -Fxq "$required" <<< "$first_precheck"; done
first_absence="$(preprod_action VERIFY_ABSENCE 0)"
grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "$first_absence"
grep -Fxq 'staging_account_present_after_cleanup=NO' <<< "$first_absence"

: > "$raw"
chmod 600 "$raw"
"${prod_ssh[@]}" "$PROD_SSH_USER@$PROD_SSH_HOST" "bash -s -- '$SOURCE_PROD_RELEASE_SHA'" < "$PROD_REMOTE" > "$raw" 2> "$prod_stderr"
test "$(stat -c '%a' "$raw")" = '600'
snapshot_bytes="$(stat -c '%s' "$raw")"
[[ "$snapshot_bytes" =~ ^[0-9]+$ && "$snapshot_bytes" -gt 0 ]]
snapshot_sha256="$(sha256sum "$raw" | awk '{print $1}')"
[[ "$snapshot_sha256" =~ ^[0-9a-f]{64}$ ]]
rm -f -- "$prod_stderr"

if LC_ALL=C grep -Eiq '^[[:space:]]*(USE[[:space:]]|CREATE[[:space:]]+(DATABASE|SCHEMA|USER)[[:space:]]|DROP[[:space:]]+(DATABASE|SCHEMA|USER)[[:space:]]|ALTER[[:space:]]+(DATABASE|SCHEMA|USER)[[:space:]]|GRANT[[:space:]]|REVOKE[[:space:]]|SET[[:space:]]+GLOBAL[[:space:]]|FLUSH[[:space:]]|INSTALL[[:space:]]+PLUGIN[[:space:]]|UNINSTALL[[:space:]]+PLUGIN[[:space:]]|SHUTDOWN([[:space:]]|;|$))' "$raw"; then
  echo 'Snapshot contains database/server-scoped SQL outside the fixed staging boundary.' >&2
  exit 73
fi

actual_precheck="$(preprod_action PRECHECK "$snapshot_bytes")"
grep -Fxq 'preprod_capacity=PASS' <<< "$actual_precheck"
grep -Fxq 'preprod_runtime_points_to_staging=NO' <<< "$actual_precheck"

staging_may_exist=1
if ! "${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
  "sudo -n -- '$HELPER_PATH' IMPORT_SANITIZE_PROVE '$REQUEST_ID' '$snapshot_bytes'" \
  < "$raw" > "$preprod_output" 2> "$preprod_stderr"; then
  echo 'Atomic PREPROD IMPORT_SANITIZE_PROVE failed; raw diagnostics are not emitted.' >&2
  exit 74
fi

for required in \
  'one_shot_import_sanitize_prove_cleanup=PASS' \
  'exact_byte_import=PASS' \
  'sanitization_policy=agency-preprod-refresh-v1' \
  "sanitization_policy_sha256=$expected_policy" \
  "sanitizer_sha256=$expected_sanitizer" \
  'staging_db_present_after_cleanup=NO' \
  'staging_account_present_after_cleanup=NO'; do
  grep -Fxq "$required" "$preprod_output"
done
safe_table_count="$(sed -n 's/^safe_table_count=//p' "$preprod_output" | head -n1)"
sanitized_state_sha256="$(sed -n 's/^sanitized_state_sha256=//p' "$preprod_output" | head -n1)"
[[ "$safe_table_count" =~ ^[1-9][0-9]*$ ]]
[[ "$sanitized_state_sha256" =~ ^[0-9a-f]{64}$ ]]
rm -f -- "$preprod_output" "$preprod_stderr"

preprod_action CLEANUP 0 >/dev/null
absence="$(preprod_action VERIFY_ABSENCE 0)"
grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "$absence"
grep -Fxq 'staging_account_present_after_cleanup=NO' <<< "$absence"
grep -Fxq 'preprod_runtime_points_to_staging=NO' <<< "$absence"
staging_may_exist=0

rm -f -- "$raw"
test ! -e "$raw"

mkdir -p "$evidence_dir"
cat > "$evidence.tmp" <<EOF
schema_version=1
request_id=$REQUEST_ID
repository_sha=$REPOSITORY_SHA
source_prod_release_sha=$SOURCE_PROD_RELEASE_SHA
operation_profile=$OPERATION_PROFILE
execution_mode=APPLY
fresh_prod_snapshot=PASS
prod_access_mode=READ_ONLY
snapshot_byte_size=$snapshot_bytes
snapshot_sha256=$snapshot_sha256
encrypted_transient_transfer=PASS
import_sanitize_prove=PASS
sanitization_policy=agency-preprod-refresh-v1
sanitization_policy_sha256=$expected_policy
sanitizer_sha256=$expected_sanitizer
safe_table_count=$safe_table_count
sanitized_state_sha256=$sanitized_state_sha256
raw_prod_data_in_github=NONE
raw_snapshot_after=ABSENT
staging_db_after=ABSENT
staging_account_after=ABSENT
preprod_runtime_db_touched=NO
prod_write=NONE
activation=NOT_PERFORMED
public_files=NONE
private_files=NONE
EOF
chmod 600 "$evidence.tmp"
mv -f "$evidence.tmp" "$evidence"
chmod 600 "$evidence"
