#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${1:-}"
if [[ "$#" -ne 1 ]] || [[ "$MODE" != 'REAL' && "$MODE" != 'SYNTHETIC' ]]; then
  echo 'Mode must be exactly REAL or SYNTHETIC.' >&2
  exit 64
fi

PROFILE_ID='agency-prod-readonly-snapshot-v1'
PROFILE_PATH='scripts/production-readonly-snapshot/profile.json'
REMOTE_SCRIPT='scripts/production-readonly-snapshot/remote-stream.sh'

require_sha() {
  local value="$1"
  local label="$2"
  if [[ ! "$value" =~ ^[0-9a-f]{40}$ ]]; then
    echo "$label is invalid." >&2
    exit 65
  fi
}

REQUEST_ID="${REQUEST_ID:-}"
AUTHORITY_COMMENT_ID="${AUTHORITY_COMMENT_ID:-}"
AUTHORITY_RUN_ID="${AUTHORITY_RUN_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
SOURCE_PROD_RELEASE_SHA="${SOURCE_PROD_RELEASE_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PROFILE_SHA256="${PROFILE_SHA256:-}"
GITHUB_WORKSPACE="${GITHUB_WORKSPACE:-}"
RUNNER_TEMP="${RUNNER_TEMP:-}"
GITHUB_RUN_ID="${GITHUB_RUN_ID:-}"
GITHUB_RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-}"

if [[ ! "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]]; then
  echo 'REQUEST_ID is invalid.' >&2
  exit 66
fi
if [[ ! "$AUTHORITY_COMMENT_ID" =~ ^[0-9]+$ ]]; then
  echo 'AUTHORITY_COMMENT_ID is invalid.' >&2
  exit 67
fi
if [[ ! "$AUTHORITY_RUN_ID" =~ ^[0-9]+$ ]]; then
  echo 'AUTHORITY_RUN_ID is invalid.' >&2
  exit 68
fi
if [[ ! "$GITHUB_RUN_ID" =~ ^[0-9]+$ ]] || [[ ! "$GITHUB_RUN_ATTEMPT" =~ ^[0-9]+$ ]]; then
  echo 'GitHub run identity is invalid.' >&2
  exit 69
fi
if [[ "$GITHUB_RUN_ATTEMPT" != '1' ]]; then
  echo 'Snapshot authority is not replayable; run_attempt must be 1.' >&2
  exit 70
fi
require_sha "$REPOSITORY_SHA" 'REPOSITORY_SHA'
require_sha "$SOURCE_PROD_RELEASE_SHA" 'SOURCE_PROD_RELEASE_SHA'
if [[ "$OPERATION_PROFILE" != "$PROFILE_ID" ]]; then
  echo 'OPERATION_PROFILE is not the reviewed snapshot profile.' >&2
  exit 71
fi
if [[ ! "$PROFILE_SHA256" =~ ^[0-9a-f]{64}$ ]]; then
  echo 'PROFILE_SHA256 is invalid.' >&2
  exit 72
fi
if [[ -z "$GITHUB_WORKSPACE" || -z "$RUNNER_TEMP" ]]; then
  echo 'GITHUB_WORKSPACE and RUNNER_TEMP are required.' >&2
  exit 73
fi
if [[ ! -f "$PROFILE_PATH" || ! -f "$REMOTE_SCRIPT" ]]; then
  echo 'Repository-owned snapshot tooling is incomplete.' >&2
  exit 74
fi
if [[ "$(git rev-parse HEAD)" != "$REPOSITORY_SHA" ]]; then
  echo 'Checked-out repository SHA does not match snapshot authority.' >&2
  exit 75
fi
actual_profile_sha="$(sha256sum "$PROFILE_PATH" | awk '{print $1}')"
if [[ "$actual_profile_sha" != "$PROFILE_SHA256" ]]; then
  echo 'Snapshot profile digest does not match snapshot authority.' >&2
  exit 76
fi

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
runner_temp_abs="$(realpath -m "$RUNNER_TEMP")"
case "$runner_temp_abs/" in
  "$workspace_abs/"*)
    echo 'RUNNER_TEMP must be outside the repository workspace.' >&2
    exit 77
    ;;
esac

RAW_PATH="$runner_temp_abs/agency-prod-readonly-snapshot-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.sql"
REMOTE_STDERR_PATH="$runner_temp_abs/agency-prod-readonly-snapshot-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.stderr"
EVIDENCE_DIR="$workspace_abs/artifacts/prod-readonly-snapshot"
EVIDENCE_PATH="$EVIDENCE_DIR/evidence.env"

SNAPSHOT_BYTE_SIZE='0'
SNAPSHOT_SHA256='NONE'
SNAPSHOT_CREATED='FAIL'
RAW_MATERIAL_MODE='NOT_CREATED'
SNAPSHOT_CLEANUP='NOT_RUN'
RAW_SNAPSHOT_PRESENT_AFTER_CLEANUP='UNKNOWN'

write_evidence() {
  mkdir -p "$EVIDENCE_DIR"
  local tmp="$EVIDENCE_PATH.tmp"
  cat > "$tmp" <<EOF_EVIDENCE
schema_version=1
request_id=$REQUEST_ID
authority_comment_id=$AUTHORITY_COMMENT_ID
authority_run_id=$AUTHORITY_RUN_ID
repository_sha=$REPOSITORY_SHA
source_prod_release_sha=$SOURCE_PROD_RELEASE_SHA
operation_profile=$OPERATION_PROFILE
profile_sha256=$PROFILE_SHA256
execution_mode=$MODE
snapshot_byte_size=$SNAPSHOT_BYTE_SIZE
snapshot_sha256=$SNAPSHOT_SHA256
snapshot_created=$SNAPSHOT_CREATED
raw_material_mode=$RAW_MATERIAL_MODE
snapshot_cleanup=$SNAPSHOT_CLEANUP
raw_snapshot_present_after_cleanup=$RAW_SNAPSHOT_PRESENT_AFTER_CLEANUP
prod_write_path=NONE
preprod_path=NONE
raw_prod_artifact_in_github=NONE
EOF_EVIDENCE
  chmod 600 "$tmp"
  mv -f "$tmp" "$EVIDENCE_PATH"
  chmod 600 "$EVIDENCE_PATH"
}

cleanup_and_finalize() {
  local original_status="$?"
  local final_status="$original_status"
  trap - EXIT HUP INT TERM
  set +e

  rm -f -- "$RAW_PATH" "$REMOTE_STDERR_PATH"
  local remove_status="$?"
  if [[ "$remove_status" -eq 0 && ! -e "$RAW_PATH" && ! -L "$RAW_PATH" ]]; then
    SNAPSHOT_CLEANUP='PASS'
    RAW_SNAPSHOT_PRESENT_AFTER_CLEANUP='NO'
  else
    SNAPSHOT_CLEANUP='FAIL'
    RAW_SNAPSHOT_PRESENT_AFTER_CLEANUP='YES'
    final_status=97
  fi

  write_evidence
  if [[ "$?" -ne 0 ]]; then
    final_status=98
  fi

  exit "$final_status"
}

trap cleanup_and_finalize EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

if [[ -e "$RAW_PATH" || -L "$RAW_PATH" || -e "$REMOTE_STDERR_PATH" || -L "$REMOTE_STDERR_PATH" ]]; then
  echo 'Unexpected pre-existing raw snapshot material for this run.' >&2
  exit 78
fi

: > "$RAW_PATH"
chmod 600 "$RAW_PATH"
RAW_MATERIAL_MODE="$(stat -c '%a' "$RAW_PATH")"
if [[ "$RAW_MATERIAL_MODE" != '600' ]]; then
  echo 'Raw snapshot material is not mode 0600.' >&2
  exit 79
fi

if [[ "$MODE" == 'SYNTHETIC' ]]; then
  printf '%s\n' 'agency synthetic snapshot proof fixture - no production data' > "$RAW_PATH"
  chmod 600 "$RAW_PATH"
  RAW_MATERIAL_MODE="$(stat -c '%a' "$RAW_PATH")"
  if [[ "${AGENCY_SNAPSHOT_SYNTHETIC_FAIL_AFTER_WRITE:-0}" == '1' ]]; then
    echo 'Synthetic failure injected after raw material creation.' >&2
    exit 80
  fi
else
  if [[ -n "${AGENCY_SNAPSHOT_SYNTHETIC_FAIL_AFTER_WRITE:-}" ]]; then
    echo 'Synthetic test controls are forbidden in REAL mode.' >&2
    exit 81
  fi
  PROD_SSH_HOST="${PROD_SSH_HOST:-}"
  PROD_SSH_USER="${PROD_SSH_USER:-}"
  if [[ ! "$PROD_SSH_HOST" =~ ^[A-Za-z0-9._:-]+$ ]]; then
    echo 'Server-owned PROD SSH host is invalid.' >&2
    exit 82
  fi
  if [[ ! "$PROD_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo 'Server-owned PROD SSH user is invalid.' >&2
    exit 83
  fi

  ssh \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=yes \
    -o ConnectTimeout=15 \
    -o ServerAliveInterval=5 \
    -o ServerAliveCountMax=3 \
    "$PROD_SSH_USER@$PROD_SSH_HOST" \
    "bash -s -- '$SOURCE_PROD_RELEASE_SHA'" \
    < "$REMOTE_SCRIPT" \
    > "$RAW_PATH" \
    2> "$REMOTE_STDERR_PATH"
fi

RAW_MATERIAL_MODE="$(stat -c '%a' "$RAW_PATH")"
if [[ "$RAW_MATERIAL_MODE" != '600' ]]; then
  echo 'Raw snapshot material permissions changed unexpectedly.' >&2
  exit 84
fi

SNAPSHOT_BYTE_SIZE="$(stat -c '%s' "$RAW_PATH")"
if [[ ! "$SNAPSHOT_BYTE_SIZE" =~ ^[0-9]+$ ]] || [[ "$SNAPSHOT_BYTE_SIZE" -le 0 ]]; then
  echo 'Snapshot stream is empty.' >&2
  exit 85
fi
SNAPSHOT_SHA256="$(sha256sum "$RAW_PATH" | awk '{print $1}')"
if [[ ! "$SNAPSHOT_SHA256" =~ ^[0-9a-f]{64}$ ]]; then
  echo 'Snapshot SHA-256 is invalid.' >&2
  exit 86
fi

SNAPSHOT_CREATED='PASS'
