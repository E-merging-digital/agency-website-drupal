#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROD_PLAN='scripts/preproduction-staging-import/real-one-shot/remote-prod-plan-readonly.sh'
PREPROD_PLAN='scripts/preproduction-refresh/governed-successor/remote-plan-observe.sh'
PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
MAX_OBSERVER_BYTES=4096

diagnostic_emitted=0
prod_stdout=''
prod_stderr=''
preprod_stdout=''
preprod_stderr=''
REMOTE_REASON=''

cleanup() {
  rm -f -- "${prod_stdout:-}" "${prod_stderr:-}" "${preprod_stdout:-}" "${preprod_stderr:-}"
}
trap cleanup EXIT

emit_failure() {
  local stage="$1"
  local reason="$2"
  trap - ERR
  diagnostic_emitted=1
  printf '%s\n' \
    'PLAN_RESULT=FAIL_CLOSED' \
    "PLAN_STAGE=$stage" \
    "PLAN_REASON=$reason"
  exit 1
}

unexpected_failure() {
  local status=$?
  if [[ "$diagnostic_emitted" -eq 0 ]]; then
    diagnostic_emitted=1
    printf '%s\n' \
      'PLAN_RESULT=FAIL_CLOSED' \
      'PLAN_STAGE=LOCAL_CONTRACT' \
      'PLAN_REASON=UNEXPECTED_LOCAL_FAILURE'
  fi
  exit "$status"
}
trap unexpected_failure ERR

parse_remote_failure() {
  local output="$1"
  shift
  local -a lines=()
  local allowed
  mapfile -t lines <<<"$output"
  [[ "${#lines[@]}" -eq 2 ]] || return 1
  [[ "${lines[0]}" == 'PLAN_OBSERVER_RESULT=FAIL_CLOSED' ]] || return 1
  [[ "${lines[1]}" =~ ^PLAN_REASON=([A-Z0-9_]+)$ ]] || return 1
  REMOTE_REASON="${BASH_REMATCH[1]}"
  for allowed in "$@"; do
    [[ "$REMOTE_REASON" == "$allowed" ]] && return 0
  done
  REMOTE_REASON=''
  return 1
}

: "${AUTHORITY_ISSUE:=}"
: "${REQUEST_ID:=}"
: "${REPOSITORY_SHA:=}"
: "${OPERATION_PROFILE:=}"
: "${PROD_SSH_HOST:=}"
: "${PROD_SSH_USER:=}"
: "${PROD_SSH_KEY:=}"
: "${PREPROD_SSH_HOST:=}"
: "${PREPROD_SSH_KEY:=}"

[[ -n "$AUTHORITY_ISSUE" && -n "$REQUEST_ID" && -n "$REPOSITORY_SHA" && -n "$OPERATION_PROFILE" ]] \
  || emit_failure LOCAL_CONTRACT PLAN_CONTEXT_INVALID
[[ -n "$PROD_SSH_HOST" && -n "$PROD_SSH_USER" && -n "$PROD_SSH_KEY" ]] \
  || emit_failure LOCAL_CONTRACT PLAN_CONTEXT_INVALID
[[ -n "$PREPROD_SSH_HOST" && -n "$PREPROD_SSH_KEY" ]] \
  || emit_failure LOCAL_CONTRACT PLAN_CONTEXT_INVALID
[[ "$REQUEST_ID" == "plan-${AUTHORITY_ISSUE}-"*'-r1' ]] \
  || emit_failure LOCAL_CONTRACT PLAN_CONTEXT_INVALID
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || emit_failure LOCAL_CONTRACT PLAN_CONTEXT_INVALID
[[ "${SOURCE_PROD_RELEASE_SHA:-}" == AUTO ]] \
  || emit_failure LOCAL_CONTRACT PLAN_CONTEXT_INVALID
[[ "$OPERATION_PROFILE" == agency-preprod-refresh-simple-v1 ]] \
  || emit_failure LOCAL_CONTRACT PLAN_CONTEXT_INVALID
[[ "${RUNNER_ENVIRONMENT:-}" == github-hosted ]] \
  || emit_failure LOCAL_CONTRACT PLAN_CONTEXT_INVALID
[[ "${GITHUB_RUN_ATTEMPT:-}" == 1 ]] \
  || emit_failure LOCAL_CONTRACT PLAN_CONTEXT_INVALID
checked_out_head="$(git rev-parse HEAD 2>/dev/null || true)"
[[ "$checked_out_head" == "$REPOSITORY_SHA" ]] \
  || emit_failure LOCAL_CONTRACT PLAN_REPOSITORY_IDENTITY

if ! SERVER_HOST="$PROD_SSH_HOST" bash "$PROD_TRUST" PROVISION >/dev/null 2>&1; then
  emit_failure PROD_TRUST PROD_PINNED_TRUST
fi
if ! SERVER_HOST="$PROD_SSH_HOST" bash "$PROD_TRUST" VERIFY_ONLY >/dev/null 2>&1; then
  emit_failure PROD_TRUST PROD_PINNED_TRUST
fi
if ! PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" bash "$PREPROD_TRUST_PROVISION" PROVISION >/dev/null 2>&1; then
  emit_failure PREPROD_TRUST PREPROD_PINNED_TRUST
fi
if ! PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash "$PREPROD_TRUST" >/dev/null 2>&1; then
  emit_failure PREPROD_TRUST PREPROD_PINNED_TRUST
fi

ssh_common=(-o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
prod=(ssh -i "$PROD_SSH_KEY" "${ssh_common[@]}")
preprod=(ssh -i "$PREPROD_SSH_KEY" "${ssh_common[@]}")

prod_stdout="$(mktemp)"
prod_stderr="$(mktemp)"
prod_rc=0
"${prod[@]}" "$PROD_SSH_USER@$PROD_SSH_HOST" 'bash -s -- AUTO' < "$PROD_PLAN" >"$prod_stdout" 2>"$prod_stderr" || prod_rc=$?
prod_size="$(wc -c < "$prod_stdout")"
[[ "$prod_size" =~ ^[0-9]+$ && "$prod_size" -le "$MAX_OBSERVER_BYTES" ]] \
  || emit_failure PROD_OBSERVER OBSERVER_OUTPUT_INVALID
prod_out="$(cat "$prod_stdout")"
rm -f -- "$prod_stdout" "$prod_stderr"
prod_stdout=''
prod_stderr=''

if [[ "$prod_rc" -ne 0 ]]; then
  if parse_remote_failure "$prod_out" \
    PROD_OBSERVER_CONTEXT PROD_CURRENT_RELEASE PROD_PROMOTION_RECEIPT; then
    emit_failure PROD_OBSERVER "$REMOTE_REASON"
  fi
  [[ -z "$prod_out" ]] && emit_failure PROD_OBSERVER PROD_SSH_OBSERVER
  emit_failure PROD_OBSERVER OBSERVER_OUTPUT_INVALID
fi

mapfile -t prod_lines <<<"$prod_out"
[[ "${#prod_lines[@]}" -eq 6 ]] \
  || emit_failure PROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${prod_lines[0]}" == 'prod_release_identity=PASS' ]] \
  || emit_failure PROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${prod_lines[1]}" =~ ^prod_release_sha=([0-9a-f]{40})$ ]] \
  || emit_failure PROD_OBSERVER OBSERVER_OUTPUT_INVALID
actual_prod="${BASH_REMATCH[1]}"
[[ "${prod_lines[2]}" == 'prod_snapshot_route_metadata=PASS' ]] \
  || emit_failure PROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${prod_lines[3]}" == 'prod_db_content_read=NONE' ]] \
  || emit_failure PROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${prod_lines[4]}" == 'prod_snapshot=NOT_PERFORMED' ]] \
  || emit_failure PROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${prod_lines[5]}" == 'prod_write=NONE' ]] \
  || emit_failure PROD_OBSERVER OBSERVER_OUTPUT_INVALID

preprod_stdout="$(mktemp)"
preprod_stderr="$(mktemp)"
preprod_rc=0
"${preprod[@]}" "agency-preprod@$PREPROD_SSH_HOST" 'bash -s --' < "$PREPROD_PLAN" >"$preprod_stdout" 2>"$preprod_stderr" || preprod_rc=$?
preprod_size="$(wc -c < "$preprod_stdout")"
[[ "$preprod_size" =~ ^[0-9]+$ && "$preprod_size" -le "$MAX_OBSERVER_BYTES" ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
preprod_out="$(cat "$preprod_stdout")"
rm -f -- "$preprod_stdout" "$preprod_stderr"
preprod_stdout=''
preprod_stderr=''

if [[ "$preprod_rc" -ne 0 ]]; then
  if parse_remote_failure "$preprod_out" \
    PREPROD_OBSERVER_CONTEXT PREPROD_CURRENT_RELEASE PREPROD_DRUSH_EXECUTABLE \
    PREPROD_BACKUP_PATH PREPROD_RUNTIME_ENV PREPROD_RUNTIME_VALIDATOR \
    PREPROD_ADMIN_RECONCILER PREPROD_CONFIG_SPLIT PREPROD_DRUSH_COMMAND_SET; then
    emit_failure PREPROD_OBSERVER "$REMOTE_REASON"
  fi
  [[ -z "$preprod_out" ]] && emit_failure PREPROD_OBSERVER PREPROD_SSH_OBSERVER
  emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
fi

mapfile -t preprod_lines <<<"$preprod_out"
[[ "${#preprod_lines[@]}" -eq 9 ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${preprod_lines[0]}" == 'PREPROD_STANDARD_DRUSH=PASS' ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${preprod_lines[1]}" == 'PREPROD_BACKUP_PATH=READY' ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${preprod_lines[2]}" == 'PREPROD_RUNTIME_ENV=SERVER_OWNED_PRESENT' ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${preprod_lines[3]}" == 'PREPROD_CONFIG_SPLIT=READY' ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${preprod_lines[4]}" == 'PREPROD_ADMIN_RECONCILER=READY' ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${preprod_lines[5]}" == 'DEPLOY_LOCK_PRESENT=YES' || "${preprod_lines[5]}" == 'DEPLOY_LOCK_PRESENT=NO' ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${preprod_lines[6]}" == 'PLAN_MUTATION=NONE' ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${preprod_lines[7]}" == 'PREPROD_DB_MUTATION=NONE' ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID
[[ "${preprod_lines[8]}" == 'DATA_ACTIVATION_AUTHORITY=DISABLED' ]] \
  || emit_failure PREPROD_OBSERVER OBSERVER_OUTPUT_INVALID

printf '%s\n' \
  'PLAN_RESULT=PASS' \
  "OBSERVED_PROD_RELEASE_SHA=$actual_prod" \
  'PROD_DB_CONTENT_READ=NONE' \
  'PROD_SNAPSHOT=NOT_PERFORMED' \
  'PROD_DATA_TRANSFER=NONE' \
  'PREPROD_DB_MUTATION=NONE' \
  'PROD_WRITE=NONE' \
  'DATA_ACTIVATION_AUTHORITY=DISABLED'
