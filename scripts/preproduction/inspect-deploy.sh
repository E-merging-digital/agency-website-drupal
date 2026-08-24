#!/usr/bin/env bash
set -Eeuo pipefail

REQUEST_ID="${1:-}"
CANDIDATE_SHA="${2:-}"
PROJECT_ROOT="${PROJECT_ROOT:-/var/www/agency-preprod}"
REQUEST_DIR="$PROJECT_ROOT/shared/deploy-jobs/$REQUEST_ID"
RESULT_FILE="$REQUEST_DIR/result.env"
PID_FILE="$REQUEST_DIR/pid"
WORKER_SCRIPT="$REQUEST_DIR/worker.sh"

if [[ ! "$REQUEST_ID" =~ ^run-[0-9]+-[0-9]+-[0-9a-f]{40}$ || ! "$CANDIDATE_SHA" =~ ^[0-9a-f]{40}$ || "$REQUEST_ID" != *"-$CANDIDATE_SHA" ]]; then
  printf 'schema_version=1\ncandidate_sha=%s\noutcome=INVALID\nreason=invalid_request_identity\n' "$CANDIDATE_SHA"
  exit 0
fi
if [[ ! -d "$REQUEST_DIR" || -L "$REQUEST_DIR" ]]; then
  printf 'schema_version=1\nrequest_id=%s\ncandidate_sha=%s\noutcome=NOT_STARTED\n' "$REQUEST_ID" "$CANDIDATE_SHA"
  exit 0
fi

field() { sed -n "s/^${1}=//p" "$2" | head -n 1; }
if [[ -s "$RESULT_FILE" && ! -L "$RESULT_FILE" ]]; then
  [[ "$(field request_id "$RESULT_FILE")" == "$REQUEST_ID" ]] || { echo 'outcome=INVALID'; echo 'reason=result_request_mismatch'; exit 0; }
  [[ "$(field candidate_sha "$RESULT_FILE")" == "$CANDIDATE_SHA" ]] || { echo 'outcome=INVALID'; echo 'reason=result_sha_mismatch'; exit 0; }
  outcome="$(field outcome "$RESULT_FILE")"
  [[ "$outcome" =~ ^(SUCCESS|FAILURE|LOCKED)$ ]] || { echo 'outcome=INVALID'; echo 'reason=result_outcome_invalid'; exit 0; }
  cat "$RESULT_FILE"
  exit 0
fi
if [[ -s "$PID_FILE" && ! -L "$PID_FILE" ]]; then
  pid="$(tr -d '\r\n' < "$PID_FILE")"
  if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null; then
    command_line="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"
    if [[ "$command_line" == *"$WORKER_SCRIPT"* ]]; then
      printf 'schema_version=1\nrequest_id=%s\ncandidate_sha=%s\noutcome=RUNNING\nworker_pid=%s\n' "$REQUEST_ID" "$CANDIDATE_SHA" "$pid"
      exit 0
    fi
  fi
  printf 'schema_version=1\nrequest_id=%s\ncandidate_sha=%s\noutcome=LOST\n' "$REQUEST_ID" "$CANDIDATE_SHA"
  exit 0
fi
printf 'schema_version=1\nrequest_id=%s\ncandidate_sha=%s\noutcome=STARTING\n' "$REQUEST_ID" "$CANDIDATE_SHA"
