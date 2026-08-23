#!/usr/bin/env bash
set -Eeuo pipefail

REQUEST_ID="${1:-}"
EXPECTED_SHA="${2:-}"
JOBS_DIR="/var/www/agency/shared/deploy-jobs"
REQUEST_DIR="$JOBS_DIR/$REQUEST_ID"
RESULT_FILE="$REQUEST_DIR/result.env"
PID_FILE="$REQUEST_DIR/pid"
WORKER_SCRIPT="$REQUEST_DIR/worker.sh"

if [[ ! "$REQUEST_ID" =~ ^run-[0-9]+-[0-9]+-[0-9a-f]{40}$ ]]; then
  echo "outcome=INVALID"
  echo "reason=invalid_request_id"
  exit 0
fi

if [[ ! "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo "outcome=INVALID"
  echo "reason=invalid_expected_sha"
  exit 0
fi

if [[ "$REQUEST_ID" != *"-$EXPECTED_SHA" ]]; then
  echo "outcome=INVALID"
  echo "reason=request_sha_mismatch"
  exit 0
fi

if [[ ! -d "$REQUEST_DIR" || -L "$REQUEST_DIR" ]]; then
  echo "schema_version=1"
  echo "request_id=$REQUEST_ID"
  echo "expected_sha=$EXPECTED_SHA"
  echo "outcome=NOT_STARTED"
  echo "reason=request_directory_missing"
  exit 0
fi

field() {
  local key="$1"
  local file="$2"
  sed -n "s/^${key}=//p" "$file" | head -n 1
}

if [[ -s "$RESULT_FILE" && ! -L "$RESULT_FILE" ]]; then
  result_request="$(field request_id "$RESULT_FILE")"
  result_sha="$(field expected_sha "$RESULT_FILE")"
  result_outcome="$(field outcome "$RESULT_FILE")"
  result_exit="$(field exit_code "$RESULT_FILE")"

  if [[ "$result_request" != "$REQUEST_ID" ]]; then
    echo "schema_version=1"
    echo "request_id=$REQUEST_ID"
    echo "expected_sha=$EXPECTED_SHA"
    echo "outcome=INVALID"
    echo "reason=result_request_mismatch"
    exit 0
  fi

  if [[ "$result_sha" != "$EXPECTED_SHA" ]]; then
    echo "schema_version=1"
    echo "request_id=$REQUEST_ID"
    echo "expected_sha=$EXPECTED_SHA"
    echo "outcome=INVALID"
    echo "reason=result_sha_mismatch"
    exit 0
  fi

  if [[ ! "$result_outcome" =~ ^(SUCCESS|FAILURE|LOCKED)$ ]]; then
    echo "schema_version=1"
    echo "request_id=$REQUEST_ID"
    echo "expected_sha=$EXPECTED_SHA"
    echo "outcome=INVALID"
    echo "reason=result_outcome_invalid"
    exit 0
  fi

  if [[ ! "$result_exit" =~ ^[0-9]+$ ]]; then
    echo "schema_version=1"
    echo "request_id=$REQUEST_ID"
    echo "expected_sha=$EXPECTED_SHA"
    echo "outcome=INVALID"
    echo "reason=result_exit_invalid"
    exit 0
  fi

  cat "$RESULT_FILE"
  exit 0
fi

if [[ -s "$PID_FILE" && ! -L "$PID_FILE" ]]; then
  worker_pid="$(tr -d '\r\n' < "$PID_FILE")"

  if [[ ! "$worker_pid" =~ ^[0-9]+$ ]]; then
    echo "schema_version=1"
    echo "request_id=$REQUEST_ID"
    echo "expected_sha=$EXPECTED_SHA"
    echo "outcome=INVALID"
    echo "reason=worker_pid_invalid"
    exit 0
  fi

  if kill -0 "$worker_pid" 2>/dev/null; then
    command_line="$(tr '\0' ' ' < "/proc/$worker_pid/cmdline" 2>/dev/null || true)"
    if [[ "$command_line" == *"$WORKER_SCRIPT"* ]]; then
      echo "schema_version=1"
      echo "request_id=$REQUEST_ID"
      echo "expected_sha=$EXPECTED_SHA"
      echo "outcome=RUNNING"
      echo "worker_pid=$worker_pid"
      exit 0
    fi

    echo "schema_version=1"
    echo "request_id=$REQUEST_ID"
    echo "expected_sha=$EXPECTED_SHA"
    echo "outcome=LOST"
    echo "reason=worker_pid_reused"
    echo "worker_pid=$worker_pid"
    exit 0
  fi

  echo "schema_version=1"
  echo "request_id=$REQUEST_ID"
  echo "expected_sha=$EXPECTED_SHA"
  echo "outcome=LOST"
  echo "reason=worker_exited_without_result"
  echo "worker_pid=$worker_pid"
  exit 0
fi

echo "schema_version=1"
echo "request_id=$REQUEST_ID"
echo "expected_sha=$EXPECTED_SHA"
echo "outcome=STARTING"
echo "reason=worker_pid_not_visible_yet"
