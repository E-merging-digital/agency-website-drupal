#!/usr/bin/env bash
set -Eeuo pipefail

REQUEST_ID="${1:-}"
EXPECTED_SHA="${2:-}"
EXPECTED_ARTIFACT_SHA256="${3:-}"
EXPECTED_COMPOSER_LOCK_SHA256="${4:-}"
AUTH_COMMENT_ID="${5:-}"
AUTH_BODY_SHA256="${6:-}"
JOBS_DIR="/var/www/agency/shared/deploy-jobs"
REQUEST_DIR="$JOBS_DIR/$REQUEST_ID"
RESULT_FILE="$REQUEST_DIR/result.env"
PID_FILE="$REQUEST_DIR/pid"
WORKER_SCRIPT="$REQUEST_DIR/worker.sh"

emit() {
  local outcome="$1"
  local reason="${2:-}"
  printf 'schema_version=1\n'
  printf 'request_id=%s\n' "$REQUEST_ID"
  printf 'expected_sha=%s\n' "$EXPECTED_SHA"
  printf 'expected_artifact_sha256=%s\n' "$EXPECTED_ARTIFACT_SHA256"
  printf 'expected_composer_lock_sha256=%s\n' "$EXPECTED_COMPOSER_LOCK_SHA256"
  printf 'authorization_comment_id=%s\n' "$AUTH_COMMENT_ID"
  printf 'authorization_body_sha256=%s\n' "$AUTH_BODY_SHA256"
  printf 'outcome=%s\n' "$outcome"
  [[ -z "$reason" ]] || printf 'reason=%s\n' "$reason"
}

[[ "$REQUEST_ID" =~ ^promote-[0-9]+-[0-9]+-[0-9a-f]{40}$ ]] || { emit INVALID invalid_request_id; exit 0; }
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || { emit INVALID invalid_expected_sha; exit 0; }
[[ "$EXPECTED_ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]] || { emit INVALID invalid_artifact_digest; exit 0; }
[[ "$EXPECTED_COMPOSER_LOCK_SHA256" =~ ^[0-9a-f]{64}$ ]] || { emit INVALID invalid_composer_digest; exit 0; }
[[ "$AUTH_COMMENT_ID" =~ ^[0-9]+$ ]] || { emit INVALID invalid_authorization_comment; exit 0; }
[[ "$AUTH_BODY_SHA256" =~ ^[0-9a-f]{64}$ ]] || { emit INVALID invalid_authorization_digest; exit 0; }
[[ "$REQUEST_ID" == *"-$EXPECTED_SHA" ]] || { emit INVALID request_sha_mismatch; exit 0; }

if [[ ! -d "$REQUEST_DIR" || -L "$REQUEST_DIR" ]]; then
  emit NOT_STARTED request_directory_missing
  exit 0
fi

field() {
  local key="$1"
  local file="$2"
  sed -n "s/^${key}=//p" "$file" | head -n 1
}

if [[ -s "$RESULT_FILE" && ! -L "$RESULT_FILE" ]]; then
  [[ "$(field request_id "$RESULT_FILE")" == "$REQUEST_ID" ]] || { emit INVALID result_request_mismatch; exit 0; }
  [[ "$(field expected_sha "$RESULT_FILE")" == "$EXPECTED_SHA" ]] || { emit INVALID result_sha_mismatch; exit 0; }
  [[ "$(field expected_artifact_sha256 "$RESULT_FILE")" == "$EXPECTED_ARTIFACT_SHA256" ]] || { emit INVALID result_artifact_mismatch; exit 0; }
  [[ "$(field expected_composer_lock_sha256 "$RESULT_FILE")" == "$EXPECTED_COMPOSER_LOCK_SHA256" ]] || { emit INVALID result_composer_mismatch; exit 0; }
  [[ "$(field authorization_comment_id "$RESULT_FILE")" == "$AUTH_COMMENT_ID" ]] || { emit INVALID result_authorization_comment_mismatch; exit 0; }
  [[ "$(field authorization_body_sha256 "$RESULT_FILE")" == "$AUTH_BODY_SHA256" ]] || { emit INVALID result_authorization_digest_mismatch; exit 0; }
  outcome="$(field outcome "$RESULT_FILE")"
  [[ "$outcome" =~ ^(SUCCESS|FAILURE|LOCKED)$ ]] || { emit INVALID result_outcome_invalid; exit 0; }
  cat "$RESULT_FILE"
  exit 0
fi

if [[ -s "$PID_FILE" && ! -L "$PID_FILE" ]]; then
  worker_pid="$(tr -d '\r\n' < "$PID_FILE")"
  [[ "$worker_pid" =~ ^[0-9]+$ ]] || { emit INVALID worker_pid_invalid; exit 0; }
  if kill -0 "$worker_pid" 2>/dev/null; then
    command_line="$(tr '\0' ' ' < "/proc/$worker_pid/cmdline" 2>/dev/null || true)"
    [[ "$command_line" == *"$WORKER_SCRIPT"* ]] || { emit LOST worker_pid_reused; exit 0; }
    emit RUNNING
    exit 0
  fi
  emit LOST worker_exited_without_result
  exit 0
fi

emit STARTING worker_pid_not_visible_yet
