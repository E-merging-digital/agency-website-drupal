#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE='scripts/preproduction-staging-import/profile.json'
PROFILE_ID='agency-preprod-isolated-staging-import-v1'
PROD_REMOTE='scripts/production-readonly-snapshot/remote-stream.sh'
PREPROD_REMOTE='scripts/preproduction-staging-import/remote-preprod-stage.sh'
PREPROD_PRIVILEGED_HELPER_SOURCE='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db'
PREPROD_PRIVILEGED_HELPER_DIGEST='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db.sha256'
PREPROD_PRIVILEGED_HELPER_PATH='/usr/local/sbin/agency-preprod-staging-db'

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

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 64; }
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid repository SHA.' >&2; exit 65; }
[[ "$SOURCE_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid PROD release SHA.' >&2; exit 66; }
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]] || { echo 'Unexpected operation profile.' >&2; exit 67; }
[[ "$GITHUB_RUN_ATTEMPT" == '1' ]] || { echo 'APPLY authority is not replayable.' >&2; exit 68; }
[[ "$RUNNER_NAME" == 'agency-browser-runner-01' ]] || { echo 'Wrong trusted runner identity.' >&2; exit 69; }
[[ -n "$RUNNER_TEMP" && -n "$GITHUB_WORKSPACE" ]] || { echo 'Runner paths are required.' >&2; exit 70; }
[[ "$PROD_SSH_HOST" =~ ^[A-Za-z0-9._:-]+$ && "$PREPROD_SSH_HOST" =~ ^[A-Za-z0-9._:-]+$ ]] || { echo 'Server-owned host identity is invalid.' >&2; exit 71; }
[[ "$PROD_SSH_USER" =~ ^[A-Za-z0-9._-]+$ && "$PREPROD_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'Server-owned SSH user identity is invalid.' >&2; exit 72; }
test -f "$PROD_SSH_KEY"
test -f "$PREPROD_SSH_KEY"
test -f "$PROFILE"
test -f "$PROD_REMOTE"
test -f "$PREPROD_REMOTE"
test -f "$PREPROD_PRIVILEGED_HELPER_SOURCE"
test -f "$PREPROD_PRIVILEGED_HELPER_DIGEST"

jq -e '.preprod_trust.current_state == "PINNED"' "$PROFILE" >/dev/null || {
  echo 'PREPROD pinned SSH trust is not established; real APPLY remains blocked.' >&2
  exit 73
}

expected_helper_sha256="$(cat "$PREPROD_PRIVILEGED_HELPER_DIGEST")"
[[ "$expected_helper_sha256" =~ ^[0-9a-f]{64}$ ]] || {
  echo 'Repository privileged-helper digest is invalid.' >&2
  exit 74
}
actual_source_sha256="$(sha256sum "$PREPROD_PRIVILEGED_HELPER_SOURCE" | awk '{print $1}')"
[[ "$actual_source_sha256" == "$expected_helper_sha256" ]] || {
  echo 'Repository privileged-helper source does not match pinned digest.' >&2
  exit 75
}

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
temp_abs="$(realpath -m "$RUNNER_TEMP")"
case "$temp_abs/" in "$workspace_abs/"*) echo 'RUNNER_TEMP must be outside repository workspace.' >&2; exit 76;; esac
raw="$temp_abs/agency-834-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.sql"
prod_stderr="$temp_abs/agency-834-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.prod.stderr"
preprod_output="$temp_abs/agency-834-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.preprod.out"
preprod_stderr="$temp_abs/agency-834-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.preprod.stderr"
remote_suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
evidence_dir="$workspace_abs/artifacts/preprod-staging-import"
evidence="$evidence_dir/evidence.env"

SNAPSHOT_BYTE_SIZE='0'
SNAPSHOT_SHA256='NONE'
TRANSFER_RESULT='NOT_PERFORMED'
STAGING_IMPORT_RESULT='NOT_PERFORMED'
STAGING_DB_ID_HASH="$(printf '%s' "agency_preprod_stage_${remote_suffix}" | sha256sum | awk '{print $1}')"
SCHEMA_PROOF='NOT_PERFORMED'
SAFE_TABLE_COUNT='0'
RAW_AFTER='UNKNOWN'
STAGING_AFTER='UNKNOWN'
RUNTIME_BEFORE='UNKNOWN'
RUNTIME_AFTER='UNKNOWN'
STAGING_MAY_EXIST=0

prod_ssh=(ssh -i "$PROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
preprod_ssh=(ssh -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)

preprod_action() {
  local action="$1" bytes="$2"
  "${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
    "bash -s -- '$action' '$REQUEST_ID' '$bytes' '$expected_helper_sha256'" < "$PREPROD_REMOTE"
}

remote_cleanup() {
  [[ "$STAGING_MAY_EXIST" -eq 1 ]] || return 0
  local absence
  preprod_action CLEANUP 0 >/dev/null || return 1
  absence="$(preprod_action VERIFY_ABSENCE 0)" || return 1
  grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "$absence" || return 1
  grep -Fxq 'preprod_runtime_points_to_staging=NO' <<< "$absence" || return 1
}

cleanup() {
  local original="$?"
  local final="$original"
  trap - EXIT HUP INT TERM
  set +e

  remote_cleanup
  local remote_status="$?"

  rm -f -- "$raw" "$prod_stderr" "$preprod_output" "$preprod_stderr"
  local local_status="$?"
  if [[ "$local_status" -ne 0 || -e "$raw" || -e "$prod_stderr" || -e "$preprod_output" || -e "$preprod_stderr" ]]; then
    final=97
  fi
  if [[ "$STAGING_MAY_EXIST" -eq 1 && "$remote_status" -ne 0 ]]; then
    final=98
  fi

  exit "$final"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# Trust must be validated before any SSH connection. No network-derived TOFU.
SERVER_HOST="$PROD_SSH_HOST" bash scripts/production-ssh-trust/manage-known-host.sh VERIFY_ONLY >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh >/dev/null

# Capacity preflight before any PROD DB read.
runner_free="$(df -PB1 "$temp_abs" | awk 'NR==2 {print $4}')"
runner_min="$(jq -r '.capacity.trusted_runner_min_free_bytes' "$PROFILE")"
[[ "$runner_free" =~ ^[0-9]+$ && "$runner_free" -ge "$runner_min" ]] || { echo 'Trusted runner capacity preflight failed.' >&2; exit 77; }

# Before any PROD DB read, PREPROD must prove that the fixed installed
# privileged helper is root-owned, non-root-writable and byte-identical to the
# repository-pinned #849 authority. PRECHECK performs no PREPROD DB mutation.
first_precheck="$(preprod_action PRECHECK 0)"
grep -Fxq 'preprod_capacity=PASS' <<< "$first_precheck"
grep -Fxq 'preprod_runtime_points_to_staging=NO' <<< "$first_precheck"
grep -Fxq 'staging_admin_capability=PASS' <<< "$first_precheck"
grep -Fxq 'privileged_helper_identity=PASS' <<< "$first_precheck"
grep -Fxq 'privileged_helper_digest=PASS' <<< "$first_precheck"

: > "$raw"
chmod 600 "$raw"
"${prod_ssh[@]}" "$PROD_SSH_USER@$PROD_SSH_HOST" \
  "bash -s -- '$SOURCE_PROD_RELEASE_SHA'" < "$PROD_REMOTE" > "$raw" 2> "$prod_stderr"
test "$(stat -c '%a' "$raw")" = '600'
SNAPSHOT_BYTE_SIZE="$(stat -c '%s' "$raw")"
[[ "$SNAPSHOT_BYTE_SIZE" =~ ^[0-9]+$ && "$SNAPSHOT_BYTE_SIZE" -gt 0 ]]
SNAPSHOT_SHA256="$(sha256sum "$raw" | awk '{print $1}')"
[[ "$SNAPSHOT_SHA256" =~ ^[0-9a-f]{64}$ ]]
rm -f -- "$prod_stderr"

# Reject database/server-scoped statements before any transfer. Defense in depth:
# the privileged helper imports through an ephemeral MariaDB account whose
# grants are limited to the internally-derived staging database.
if LC_ALL=C grep -Eiq \
  '^[[:space:]]*(USE[[:space:]]|CREATE[[:space:]]+(DATABASE|SCHEMA|USER)[[:space:]]|DROP[[:space:]]+(DATABASE|SCHEMA|USER)[[:space:]]|ALTER[[:space:]]+(DATABASE|SCHEMA|USER)[[:space:]]|GRANT[[:space:]]|REVOKE[[:space:]]|SET[[:space:]]+GLOBAL[[:space:]]|FLUSH[[:space:]]|INSTALL[[:space:]]+PLUGIN[[:space:]]|UNINSTALL[[:space:]]+PLUGIN[[:space:]]|SHUTDOWN([[:space:]]|;|$))' \
  "$raw"; then
  echo 'Snapshot contains database/server-scoped SQL outside the fixed staging boundary.' >&2
  exit 78
fi

# Re-check PREPROD capacity and exact installed helper identity with the actual
# snapshot size before any import.
actual_precheck="$(preprod_action PRECHECK "$SNAPSHOT_BYTE_SIZE")"
grep -Fxq 'preprod_capacity=PASS' <<< "$actual_precheck"
grep -Fxq 'preprod_runtime_points_to_staging=NO' <<< "$actual_precheck"
grep -Fxq 'staging_admin_capability=PASS' <<< "$actual_precheck"
grep -Fxq 'privileged_helper_identity=PASS' <<< "$actual_precheck"
grep -Fxq 'privileged_helper_digest=PASS' <<< "$actual_precheck"
RUNTIME_BEFORE='NO'

# Encrypted SSH stdin stream: raw SQL is consumed only by the fixed #849 helper.
# The deploy account supplies no DB name, SQL command, shell, executable, path,
# host or credential to the privileged process.
STAGING_MAY_EXIST=1
if ! "${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
  "sudo -n -- '$PREPROD_PRIVILEGED_HELPER_PATH' IMPORT '$REQUEST_ID' '$SNAPSHOT_BYTE_SIZE'" \
  < "$raw" > "$preprod_output" 2> "$preprod_stderr"; then
  echo 'Bounded PREPROD staging import failed; raw diagnostics are not emitted.' >&2
  exit 79
fi
TRANSFER_RESULT='PASS'
STAGING_IMPORT_RESULT="$(sed -n 's/^staging_import_result=//p' "$preprod_output" | head -n1)"
STAGING_DB_ID_HASH="$(sed -n 's/^staging_db_id_hash=//p' "$preprod_output" | head -n1)"
SCHEMA_PROOF="$(sed -n 's/^schema_proof=//p' "$preprod_output" | head -n1)"
SAFE_TABLE_COUNT="$(sed -n 's/^safe_table_count=//p' "$preprod_output" | head -n1)"
STAGING_AFTER="$(sed -n 's/^staging_db_present_after_cleanup=//p' "$preprod_output" | tail -n1)"
[[ "$STAGING_IMPORT_RESULT" == 'PASS' && "$SCHEMA_PROOF" == 'PASS' ]]
[[ "$SAFE_TABLE_COUNT" =~ ^[0-9]+$ && "$SAFE_TABLE_COUNT" -gt 0 ]]
[[ "$STAGING_AFTER" == 'NO' ]]
rm -f -- "$preprod_output" "$preprod_stderr"

# Independent idempotent cleanup and absence proof remain mandatory even though
# IMPORT itself has a privileged finalizer.
preprod_action CLEANUP 0 >/dev/null
absence="$(preprod_action VERIFY_ABSENCE 0)"
grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "$absence"
grep -Fxq 'preprod_runtime_points_to_staging=NO' <<< "$absence"
STAGING_AFTER='NO'
RUNTIME_AFTER='NO'
STAGING_MAY_EXIST=0

rm -f -- "$raw"
test ! -e "$raw"
RAW_AFTER='NO'

mkdir -p "$evidence_dir"
cat > "$evidence.tmp" <<EOF
schema_version=1
request_id=$REQUEST_ID
repository_sha=$REPOSITORY_SHA
source_prod_release_sha=$SOURCE_PROD_RELEASE_SHA
operation_profile=$OPERATION_PROFILE
execution_mode=APPLY
plan_result=PASS
apply_readiness=PASS
trusted_runner_boundary=PASS
prod_pinned_trust=PASS
preprod_trust_model=PINNED
encrypted_transfer_contract=PASS
capacity_preflight=PASS
snapshot_byte_size=$SNAPSHOT_BYTE_SIZE
snapshot_sha256=$SNAPSHOT_SHA256
transfer_result=$TRANSFER_RESULT
staging_import_result=$STAGING_IMPORT_RESULT
staging_db_id_hash=$STAGING_DB_ID_HASH
schema_proof=$SCHEMA_PROOF
safe_table_count=$SAFE_TABLE_COUNT
preprod_runtime_points_to_staging_before=$RUNTIME_BEFORE
preprod_runtime_points_to_staging_after=$RUNTIME_AFTER
raw_snapshot_present_after_cleanup=$RAW_AFTER
staging_db_present_after_cleanup=$STAGING_AFTER
prod_write_path=NONE
github_hosted_raw_path=NONE
raw_github_artifact_path=NONE
sanitization_path=NONE
activation_path=NONE
EOF
chmod 600 "$evidence.tmp"
mv -f "$evidence.tmp" "$evidence"
chmod 600 "$evidence"
expected="$(jq -r '.evidence.allowlist[]' "$PROFILE" | sort)"
actual="$(cut -d= -f1 "$evidence" | sort)"
test "$expected" = "$actual"
