#!/usr/bin/env bash
set -u -o pipefail

failed_run="${1:-}"
failed_request="${2:-}"
runner_temp="${RUNNER_TEMP:-}"

if [[ ! "$failed_run" =~ ^[1-9][0-9]*$ ]]; then
  exit 64
fi
if [[ ! "$failed_request" =~ ^seed-956-[A-Za-z0-9._-]{8,40}-r1$ ]]; then
  exit 64
fi
if [[ -z "$runner_temp" || "$runner_temp" != /* ]]; then
  exit 64
fi

failed_ddev_project="agency-seed-956-${failed_run}"

raw_state='CHECK_FAILED'
sanitize_diagnostic_state='CHECK_FAILED'
known_hosts_state='CHECK_FAILED'
generation_worktree_state='CHECK_FAILED'
proof_worktree_state='CHECK_FAILED'
reader_key_state='CHECK_FAILED'
reader_public_key_state='CHECK_FAILED'
proof_cache_state='CHECK_FAILED'
ddev_state='CHECK_FAILED'
container_state='CHECK_FAILED'
volume_state='CHECK_FAILED'

path_state() {
  local target="$1"
  if ! command -v python3 >/dev/null 2>&1; then
    printf 'CHECK_FAILED'
    return
  fi
  TARGET_PATH="$target" python3 -c '
import os
path = os.environ["TARGET_PATH"]
try:
    os.lstat(path)
except FileNotFoundError:
    print("ABSENT", end="")
except OSError:
    print("CHECK_FAILED", end="")
else:
    print("PRESENT", end="")
' 2>/dev/null || printf 'CHECK_FAILED'
}

if [[ -d "$runner_temp" && -x "$runner_temp" ]]; then
  raw_state="$(path_state "$runner_temp/$failed_request.raw-preprod.sql")"
  sanitize_diagnostic_state="$(path_state "$runner_temp/$failed_request.sql-sanitize.diagnostic")"
  known_hosts_state="$(path_state "$runner_temp/$failed_request.known_hosts")"
  generation_worktree_state="$(path_state "$runner_temp/$failed_request-generation")"
  proof_worktree_state="$(path_state "$runner_temp/$failed_request-proof")"
  reader_key_state="$(path_state "$runner_temp/$failed_request.reader")"
  reader_public_key_state="$(path_state "$runner_temp/$failed_request.reader.pub")"
  proof_cache_state="$(path_state "$runner_temp/$failed_request-proof-cache")"
fi

if command -v ddev >/dev/null 2>&1 && command -v python3 >/dev/null 2>&1; then
  ddev_json="$(ddev list -j 2>/dev/null)"
  ddev_status=$?
  if [[ $ddev_status -eq 0 ]]; then
    printf '%s' "$ddev_json" | TARGET_PROJECT="$failed_ddev_project" python3 -c '
import json, os, sys
try:
    payload = json.load(sys.stdin)
    raw = payload.get("raw")
    if not isinstance(raw, list):
        raise ValueError("invalid ddev json")
    target = os.environ["TARGET_PROJECT"]
    present = any(isinstance(item, dict) and item.get("name") == target for item in raw)
    raise SystemExit(0 if present else 3)
except Exception:
    raise SystemExit(4)
' >/dev/null 2>&1
    parse_status=$?
    case "$parse_status" in
      0) ddev_state='PRESENT' ;;
      3) ddev_state='ABSENT' ;;
      *) ddev_state='CHECK_FAILED' ;;
    esac
  fi
fi

if command -v docker >/dev/null 2>&1; then
  container_ids="$(docker ps -aq --filter "label=com.ddev.site-name=$failed_ddev_project" 2>/dev/null)"
  container_status=$?
  if [[ $container_status -eq 0 ]]; then
    [[ -n "$container_ids" ]] && container_state='PRESENT' || container_state='ABSENT'
  fi

  volume_names="$(docker volume ls -q --filter "label=com.docker.compose.project=ddev-$failed_ddev_project" 2>/dev/null)"
  volume_status=$?
  if [[ $volume_status -eq 0 ]]; then
    [[ -n "$volume_names" ]] && volume_state='PRESENT' || volume_state='ABSENT'
  fi
fi

if [[ "$ddev_state" == 'PRESENT' || "$container_state" == 'PRESENT' || "$volume_state" == 'PRESENT' ]]; then
  failed_ddev_state='PRESENT'
elif [[ "$ddev_state" == 'ABSENT' && "$container_state" == 'ABSENT' && "$volume_state" == 'ABSENT' ]]; then
  failed_ddev_state='ABSENT'
else
  failed_ddev_state='CHECK_FAILED'
fi

cleanup_gate='PASS'
for state in \
  "$raw_state" \
  "$sanitize_diagnostic_state" \
  "$known_hosts_state" \
  "$generation_worktree_state" \
  "$proof_worktree_state" \
  "$reader_key_state" \
  "$reader_public_key_state" \
  "$proof_cache_state" \
  "$ddev_state" \
  "$container_state" \
  "$volume_state" \
  "$failed_ddev_state"; do
  if [[ "$state" != 'ABSENT' ]]; then
    cleanup_gate='NOT_PROVEN'
  fi
done

printf 'FAILED_REQUEST_RAW_MATERIAL=%s\n' "$raw_state"
printf 'FAILED_REQUEST_SANITIZE_DIAGNOSTIC=%s\n' "$sanitize_diagnostic_state"
printf 'FAILED_REQUEST_KNOWN_HOSTS=%s\n' "$known_hosts_state"
printf 'FAILED_REQUEST_GENERATION_WORKTREE=%s\n' "$generation_worktree_state"
printf 'FAILED_REQUEST_PROOF_WORKTREE=%s\n' "$proof_worktree_state"
printf 'FAILED_REQUEST_READER_KEY=%s\n' "$reader_key_state"
printf 'FAILED_REQUEST_READER_PUBLIC_KEY=%s\n' "$reader_public_key_state"
printf 'FAILED_REQUEST_PROOF_CACHE=%s\n' "$proof_cache_state"
printf 'DDEV_LIST_PROJECT=%s\n' "$ddev_state"
printf 'DOCKER_CONTAINER_PROJECT=%s\n' "$container_state"
printf 'DOCKER_VOLUME_PROJECT=%s\n' "$volume_state"
printf 'FAILED_DDEV_PROJECT=%s\n' "$failed_ddev_state"
printf 'CONTENT_READ=NONE\n'
printf 'DELETE=NONE\n'
printf 'PREPROD_ACCESS=NONE\n'
printf 'PROD_ACCESS=NONE\n'
printf 'CLEANUP_GATE=%s\n' "$cleanup_gate"
