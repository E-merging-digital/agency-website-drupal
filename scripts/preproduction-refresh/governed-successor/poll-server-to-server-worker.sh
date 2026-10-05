#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

: "${REQUEST_ID:?Missing REQUEST_ID}"
: "${REPOSITORY_SHA:?Missing REPOSITORY_SHA}"
: "${PREPROD_SSH_HOST:?Missing PREPROD_SSH_HOST}"
: "${PREPROD_ROOT_KEY:?Missing PREPROD_ROOT_KEY}"
: "${PREPROD_KNOWN_HOSTS_FILE:?Missing PREPROD_KNOWN_HOSTS_FILE}"
: "${CONTROL_OBSERVER:?Missing CONTROL_OBSERVER}"

[[ "$REQUEST_ID" =~ ^apply-[0-9]+-[A-Za-z0-9._-]{8,40}-r1$ ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ -f "$PREPROD_ROOT_KEY" && ! -L "$PREPROD_ROOT_KEY" ]]
[[ -f "$PREPROD_KNOWN_HOSTS_FILE" && ! -L "$PREPROD_KNOWN_HOSTS_FILE" ]]
[[ -f "$CONTROL_OBSERVER" && ! -L "$CONTROL_OBSERVER" ]]

PER_OBSERVATION_HARD_TIMEOUT_SECONDS=20
POLL_INTERVAL_SECONDS=10
ssh_common=(
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$PREPROD_KNOWN_HOSTS_FILE"
  -o ConnectTimeout=15
  -o ServerAliveInterval=5
  -o ServerAliveCountMax=2
)
root_ssh=(ssh -i "$PREPROD_ROOT_KEY" "${ssh_common[@]}")

while true; do
  set +e
  observation="$(
    timeout --signal=KILL "${PER_OBSERVATION_HARD_TIMEOUT_SECONDS}s" \
      "${root_ssh[@]}" "root@$PREPROD_SSH_HOST" \
      "/usr/bin/python3 -I - '$REQUEST_ID' '$REPOSITORY_SHA'" \
      < "$CONTROL_OBSERVER"
  )"
  observation_status=$?
  set -e
  if (( observation_status != 0 )); then
    printf '%s\n' 'PREPROD_WORKER_STATE=UNOBSERVABLE_FAIL_CLOSED' >&2
    printf 'CONTROL_PLANE_OBSERVATION_STATUS=%s\n' "$observation_status" >&2
    exit 76
  fi

  mapfile -t lines <<< "$observation"
  # #945 originally guarded: if (( ${#lines[@]} != 3 )); then
  # #949 extends that exact bounded semantic record by one validated detail line.
  if (( ${#lines[@]} != 4 )); then
    printf '%s\n' 'PREPROD_WORKER_STATE=UNOBSERVABLE_FAIL_CLOSED' >&2
    exit 76
  fi
  [[ "${lines[0]}" == terminal_metadata=* ]] || exit 76
  [[ "${lines[1]}" == outcome=* ]] || exit 76
  [[ "${lines[2]}" == detail=* ]] || exit 76
  [[ "${lines[3]}" == worker_process=* ]] || exit 76
  terminal="${lines[0]#terminal_metadata=}"
  outcome="${lines[1]#outcome=}"
  detail="${lines[2]#detail=}"
  worker="${lines[3]#worker_process=}"

  if [[ "$terminal" == PRESENT ]]; then
    [[ "$outcome" =~ ^(COMMITTED|ROLLED_BACK|HUMAN_RECOVERY_REQUIRED)$ ]] || exit 76
    [[ "$detail" =~ ^[A-Z0-9_]+$ ]] || exit 76
    printf 'PREPROD_WORKER_OUTCOME=%s\n' "$outcome"
    printf 'PREPROD_WORKER_DETAIL=%s\n' "$detail"
    printf '%s\n' \
      'CONTROL_PLANE=GITHUB_HOSTED_METADATA_SECRETS_SCRIPTS_ONLY' \
      'RAW_PROD_ON_GITHUB_HOSTED=NONE' \
      'RAW_PROD_ROUTE=PROD_TO_PREPROD_DIRECT' \
      'PROD_WRITE=NONE' \
      'PROD_READ_IDENTITY=READ_ONLY_REQUEST_SCOPED_TRANSIENT' \
      'SANITIZED_ONLY_ACTIVATION=PASS' \
      'EXISTING_REMOTE_APPLY_WORKER=REUSED'
    case "$outcome" in
      COMMITTED) exit 0 ;;
      ROLLED_BACK) exit 80 ;;
      HUMAN_RECOVERY_REQUIRED) exit 90 ;;
    esac
  fi

  if [[ "$terminal" != ABSENT || "$outcome" != NONE || "$detail" != NONE ]]; then
    printf '%s\n' 'PREPROD_WORKER_STATE=UNOBSERVABLE_FAIL_CLOSED' >&2
    exit 76
  fi
  case "$worker" in
    DEAD)
      printf '%s\n' 'PREPROD_WORKER_STATE=WORKER_DEAD_NO_TERMINAL_METADATA' >&2
      exit 75
      ;;
    ALIVE)
      ;;
    UNOBSERVABLE)
      printf '%s\n' 'PREPROD_WORKER_STATE=UNOBSERVABLE_FAIL_CLOSED' >&2
      exit 76
      ;;
    *)
      printf '%s\n' 'PREPROD_WORKER_STATE=UNOBSERVABLE_FAIL_CLOSED' >&2
      exit 76
      ;;
  esac
  sleep "$POLL_INTERVAL_SECONDS"
done
