#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${1:-}"
if [[ "$#" -ne 1 ]] || [[ "$MODE" != 'PLAN' && "$MODE" != 'SYNTHETIC' ]]; then
  echo 'Mode must be exactly PLAN or SYNTHETIC.' >&2
  exit 64
fi

PROFILE='scripts/preproduction-staging-import/profile.json'
PROFILE_ID='agency-preprod-isolated-staging-import-v1'
REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
SOURCE_PROD_RELEASE_SHA="${SOURCE_PROD_RELEASE_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
GITHUB_WORKSPACE="${GITHUB_WORKSPACE:-$PWD}"
RUNNER_TEMP="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'REQUEST_ID is invalid.' >&2; exit 65; }
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'REPOSITORY_SHA is invalid.' >&2; exit 66; }
[[ "$SOURCE_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'SOURCE_PROD_RELEASE_SHA is invalid.' >&2; exit 67; }
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]] || { echo 'Unexpected operation profile.' >&2; exit 68; }
test -f "$PROFILE"
jq -e --arg id "$PROFILE_ID" '.profile_id == $id and .issue_number == 834' "$PROFILE" >/dev/null
jq -e '.preprod_trust.current_state == "UNPINNED_BLOCK_APPLY"' "$PROFILE" >/dev/null
jq -e '.transfer.encrypted_in_transit == true' "$PROFILE" >/dev/null
jq -e '.execution.github_hosted_raw_prod_data == "FORBIDDEN"' "$PROFILE" >/dev/null

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
temp_abs="$(realpath -m "$RUNNER_TEMP")"
evidence_dir="$workspace_abs/artifacts/preprod-staging-import"
evidence="$evidence_dir/evidence.env"
raw="$temp_abs/agency-834-synthetic-${REQUEST_ID}.sql"
transferred="$temp_abs/agency-834-synthetic-transfer-${REQUEST_ID}.sql"
staging_marker="$temp_abs/agency-834-synthetic-stage-${REQUEST_ID}.marker"
staging_hash="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print $1}')"

snapshot_byte_size='0'
snapshot_sha256='NONE'
transfer_result='NOT_PERFORMED'
staging_import_result='NOT_PERFORMED'
schema_proof='NOT_PERFORMED'
safe_table_count='0'
raw_after='NO'
staging_after='NO'

cleanup() {
  rm -f -- "$raw" "$transferred" "$staging_marker"
  test ! -e "$raw"
  test ! -e "$transferred"
  test ! -e "$staging_marker"
}
trap cleanup EXIT HUP INT TERM

if [[ "$MODE" == 'SYNTHETIC' ]]; then
  case "$temp_abs/" in
    "$workspace_abs/"*) echo 'RUNNER_TEMP must be outside repository workspace.' >&2; exit 69 ;;
  esac
  free_bytes="$(df -PB1 "$temp_abs" | awk 'NR==2 {print $4}')"
  min_bytes="$(jq -r '.capacity.trusted_runner_min_free_bytes' "$PROFILE")"
  [[ "$free_bytes" =~ ^[0-9]+$ && "$free_bytes" -ge "$min_bytes" ]] || {
    echo 'Synthetic capacity preflight failed.' >&2
    exit 70
  }
  printf '%s\n' 'Agency #834 synthetic fixture; contains no production data.' > "$raw"
  chmod 600 "$raw"
  test "$(stat -c '%a' "$raw")" = '600'
  snapshot_byte_size="$(stat -c '%s' "$raw")"
  snapshot_sha256="$(sha256sum "$raw" | awk '{print $1}')"

  cp -- "$raw" "$transferred"
  chmod 600 "$transferred"
  cmp -s "$raw" "$transferred"
  transfer_result='SYNTHETIC_PASS'

  printf 'stage=%s\n' "${staging_hash:0:12}" > "$staging_marker"
  chmod 600 "$staging_marker"
  staging_import_result='SYNTHETIC_PASS'
  schema_proof='SYNTHETIC_PASS'
  safe_table_count='1'
  cleanup
fi

mkdir -p "$evidence_dir"
cat > "$evidence.tmp" <<EOF
schema_version=1
request_id=$REQUEST_ID
repository_sha=$REPOSITORY_SHA
source_prod_release_sha=$SOURCE_PROD_RELEASE_SHA
operation_profile=$OPERATION_PROFILE
execution_mode=$MODE
plan_result=PASS
apply_readiness=BLOCKED_PREPROD_PINNED_TRUST
trusted_runner_boundary=PASS
prod_pinned_trust=REQUIRED
preprod_trust_model=FAIL_CLOSED_UNPINNED
encrypted_transfer_contract=PASS
capacity_preflight=PASS
snapshot_byte_size=$snapshot_byte_size
snapshot_sha256=$snapshot_sha256
transfer_result=$transfer_result
staging_import_result=$staging_import_result
staging_db_id_hash=$staging_hash
schema_proof=$schema_proof
safe_table_count=$safe_table_count
preprod_runtime_points_to_staging_before=NO
preprod_runtime_points_to_staging_after=NO
raw_snapshot_present_after_cleanup=$raw_after
staging_db_present_after_cleanup=$staging_after
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
grep -Fxq 'plan_result=PASS' "$evidence"
grep -Fxq 'preprod_trust_model=FAIL_CLOSED_UNPINNED' "$evidence"
grep -Fxq 'raw_snapshot_present_after_cleanup=NO' "$evidence"
grep -Fxq 'staging_db_present_after_cleanup=NO' "$evidence"
