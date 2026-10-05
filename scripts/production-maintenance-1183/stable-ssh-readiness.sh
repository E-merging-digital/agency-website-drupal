#!/usr/bin/env bash
set -Eeuo pipefail

required_consecutive="${1:-}"
max_attempts="${2:-}"
delay_seconds="${3:-}"
shift 3

[[ "$required_consecutive" =~ ^[1-9][0-9]*$ ]]
[[ "$max_attempts" =~ ^[1-9][0-9]*$ ]]
[[ "$delay_seconds" =~ ^[0-9]+$ ]]
[[ "$#" -ge 2 ]]
[[ "$1" == '--' ]]
shift

consecutive=0
attempt=0
while (( attempt < max_attempts )); do
  attempt=$((attempt + 1))
  if "$@" true >/dev/null 2>&1; then
    consecutive=$((consecutive + 1))
    if (( consecutive >= required_consecutive )); then
      printf 'STABLE_SSH_READINESS=PASS\n'
      printf 'SSH_READINESS_PROBES=%s\n' "$attempt"
      printf 'SSH_CONSECUTIVE_SUCCESSES=%s\n' "$consecutive"
      exit 0
    fi
  else
    consecutive=0
  fi
  if (( attempt < max_attempts && delay_seconds > 0 )); then
    sleep "$delay_seconds"
  fi
done

printf 'STABLE_SSH_READINESS=FAIL\n' >&2
printf 'SSH_READINESS_PROBES=%s\n' "$attempt" >&2
exit 75
