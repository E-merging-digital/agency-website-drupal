#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

GITHUB_WORKSPACE="${GITHUB_WORKSPACE:-}"
RUNNER_TEMP="${RUNNER_TEMP:-}"
GITHUB_RUN_ID="${GITHUB_RUN_ID:-}"
GITHUB_RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-}"

if [[ -z "$GITHUB_WORKSPACE" || -z "$RUNNER_TEMP" ]]; then
  echo 'GITHUB_WORKSPACE and RUNNER_TEMP are required.' >&2
  exit 64
fi
if [[ ! "$GITHUB_RUN_ID" =~ ^[0-9]+$ ]] || [[ ! "$GITHUB_RUN_ATTEMPT" =~ ^[0-9]+$ ]]; then
  echo 'GitHub run identity is invalid.' >&2
  exit 65
fi

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
runner_temp_abs="$(realpath -m "$RUNNER_TEMP")"
case "$runner_temp_abs/" in
  "$workspace_abs/"*)
    echo 'RUNNER_TEMP must be outside the repository workspace.' >&2
    exit 66
    ;;
esac

raw_path="$runner_temp_abs/agency-prod-readonly-snapshot-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.sql"
stderr_path="$runner_temp_abs/agency-prod-readonly-snapshot-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.stderr"

rm -f -- "$raw_path" "$stderr_path"

if [[ -e "$raw_path" || -L "$raw_path" || -e "$stderr_path" || -L "$stderr_path" ]]; then
  echo 'Trusted runner cleanup could not prove raw snapshot absence.' >&2
  exit 67
fi

printf '%s\n' 'RAW_SNAPSHOT_PRESENT_AFTER_CLEANUP=NO'
