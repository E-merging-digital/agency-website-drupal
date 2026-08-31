#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

BASE='scripts/preproduction-refresh/governed-successor'
CONTRACT="$BASE/orchestration-contract.py"
PROD_REMOTE='scripts/production-readonly-snapshot/remote-stream.sh'
PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
INSTALL='/usr/local/sbin/agency-preprod-refresh-authority-install'
ABORT='/usr/local/sbin/agency-preprod-refresh-authority-abort'
INGRESS='/usr/local/sbin/agency-preprod-refresh-ingress'
CONTROL='/usr/local/sbin/agency-preprod-refresh-control'

AUTHORITY_ISSUE="${AUTHORITY_ISSUE:-}"
REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
SOURCE_PROD_RELEASE_SHA="${SOURCE_PROD_RELEASE_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PROD_SSH_HOST="${PROD_SSH_HOST:-}"
PROD_SSH_USER="${PROD_SSH_USER:-}"
PROD_SSH_KEY="${PROD_SSH_KEY:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_SSH_USER="${PREPROD_SSH_USER:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
PREPROD_ROOT_SSH_KEY="${PREPROD_ROOT_SSH_KEY:-}"
RUNNER_TEMP="${RUNNER_TEMP:-}"
GITHUB_WORKSPACE="${GITHUB_WORKSPACE:-}"
GITHUB_RUN_ID="${GITHUB_RUN_ID:-}"

[[ "$AUTHORITY_ISSUE" =~ ^[0-9]+$ && "$AUTHORITY_ISSUE" -gt 917 ]]
[[ "$REQUEST_ID" == apply-"$AUTHORITY_ISSUE"-*-r1 ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$SOURCE_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == 'agency-preprod-governed-refresh-successor-v1' ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == '1' ]]
[[ "${RUNNER_NAME:-}" == 'agency-browser-runner-01' ]]
[[ "$PREPROD_SSH_USER" == 'agency-preprod' ]]
[[ -n "$RUNNER_TEMP" && -n "$GITHUB_WORKSPACE" && -n "$GITHUB_RUN_ID" ]]
for path in "$CONTRACT" "$PROD_REMOTE" "$PROD_TRUST" "$PREPROD_TRUST" "$PROD_SSH_KEY" "$PREPROD_SSH_KEY" "$PREPROD_ROOT_SSH_KEY"; do
  [[ -f "$path" && ! -L "$path" ]]
done
[[ "$(git rev-parse HEAD)" == "$REPOSITORY_SHA" ]]
python3 "$CONTRACT" verify-repository >/dev/null

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
temp_abs="$(realpath -m "$RUNNER_TEMP")"
case "$temp_abs/" in "$workspace_abs/"*) echo 'RUNNER_TEMP must be outside workspace.' >&2; exit 70;; esac
raw="$temp_abs/agency-914-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.sql"
prod_stderr="$temp_abs/agency-914-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.prod.stderr"
abort_request_file="$temp_abs/agency-914-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.abort.json"
authority_armed=0
snapshot_ready=0
authority_id=''

SERVER_HOST="$PROD_SSH_HOST" bash "$PROD_TRUST" VERIFY_ONLY >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$PREPROD_TRUST" >/dev/null

prod_ssh=(ssh -i "$PROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
preprod_ssh=(ssh -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
root_ssh=(ssh -i "$PREPROD_ROOT_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)

cleanup_raw() {
  rm -f -- "$raw" "$prod_stderr"
  [[ ! -e "$raw" && ! -e "$prod_stderr" ]]
}

pre_ingress_abort() {
  [[ "$authority_armed" -eq 1 && "$snapshot_ready" -eq 0 ]]
  cleanup_raw
  python3 "$CONTRACT" abort-request \
    --issue "$AUTHORITY_ISSUE" --request-id "$REQUEST_ID" --main-sha "$REPOSITORY_SHA" \
    --authority-id "$authority_id" > "$abort_request_file"
  local output
  output="$("${root_ssh[@]}" "root@$PREPROD_SSH_HOST" "$ABORT" < "$abort_request_file")"
  rm -f -- "$abort_request_file"
  grep -Fxq 'PRE_INGRESS_ABORT=PASS' <<<"$output"
  grep -Fxq 'ABORTED_TERMINAL_STATE=PRESENT' <<<"$output"
  grep -Fxq 'ACTIVE_AUTHORITY_AFTER_ABORT=ABSENT' <<<"$output"
  grep -Fxq 'RUNTIME_ROLLBACK_CLAIM=NONE' <<<"$output"
  authority_armed=0
  printf '%s\n' 'PRE_INGRESS_FAILURE_TERMINALIZATION=ABORTED' 'ABORTED_IS_NOT_ROLLED_BACK=PASS'
}

finish() {
  local status="$?"
  trap - EXIT HUP INT TERM
  set +e
  rm -f -- "$abort_request_file"
  if [[ "$status" -ne 0 && "$authority_armed" -eq 1 && "$snapshot_ready" -eq 0 ]]; then
    if ! pre_ingress_abort; then
      echo 'Pre-ingress failure could not be proven ABORTED; blind retry forbidden.' >&2
      cleanup_raw >/dev/null 2>&1 || true
      exit 98
    fi
  else
    cleanup_raw >/dev/null 2>&1 || status=97
  fi
  exit "$status"
}
trap finish EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# #834/#866 reviewed read-only snapshot primitive. Raw bytes exist only in
# trusted RUNNER_TEMP and are never emitted, uploaded or stored in workspace.
: > "$raw"
chmod 600 "$raw"
"${prod_ssh[@]}" "$PROD_SSH_USER@$PROD_SSH_HOST" "bash -s -- '$SOURCE_PROD_RELEASE_SHA'" \
  < "$PROD_REMOTE" > "$raw" 2> "$prod_stderr"
rm -f -- "$prod_stderr"
snapshot_bytes="$(stat -c '%s' "$raw")"
snapshot_sha256="$(sha256sum "$raw" | awk '{print $1}')"
[[ "$snapshot_bytes" =~ ^[1-9][0-9]*$ && "$snapshot_sha256" =~ ^[0-9a-f]{64}$ ]]

envelope="$(python3 "$CONTRACT" authority-envelope \
  --issue "$AUTHORITY_ISSUE" --request-id "$REQUEST_ID" --main-sha "$REPOSITORY_SHA" \
  --snapshot-bytes "$snapshot_bytes" --snapshot-sha256 "$snapshot_sha256")"
authority_id="$(python3 "$CONTRACT" authority-id \
  --issue "$AUTHORITY_ISSUE" --request-id "$REQUEST_ID" --main-sha "$REPOSITORY_SHA" \
  --snapshot-bytes "$snapshot_bytes" --snapshot-sha256 "$snapshot_sha256")"
install_output="$(printf '%s\n' "$envelope" | "${root_ssh[@]}" "root@$PREPROD_SSH_HOST" "$INSTALL")"
grep -Fxq 'TRANSACTION_AUTHORITY=ARMED' <<<"$install_output"
authority_armed=1

header="$(python3 "$CONTRACT" ingress-header \
  --issue "$AUTHORITY_ISSUE" --request-id "$REQUEST_ID" --main-sha "$REPOSITORY_SHA" \
  --snapshot-bytes "$snapshot_bytes" --snapshot-sha256 "$snapshot_sha256")"
# The normal deploy identity may sudo exactly the fixed ingress executable only.
ingress_output="$({ printf '%s\n' "$header"; cat "$raw"; } | \
  "${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" "sudo -n -- $INGRESS")"
grep -Fxq 'FIXED_BINARY_INGRESS=PASS' <<<"$ingress_output"
snapshot_ready=1
authority_armed=0
cleanup_raw

operation_json() {
  python3 "$CONTRACT" operation --action "$1" --issue "$AUTHORITY_ISSUE" \
    --request-id "$REQUEST_ID" --main-sha "$REPOSITORY_SHA"
}

import_out="$(operation_json IMPORT_SANITIZE_HARDEN_RETAIN | \
  "${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" "sudo -n -- $CONTROL")"
grep -Fxq 'CANDIDATE_SANITIZED_HARDENED_SEALED=PASS' <<<"$import_out"
grep -Fxq 'RAW_SNAPSHOT_TERMINAL_CLEANUP=PASS' <<<"$import_out"

activate_out="$(operation_json BACKUP_ACTIVATE_CONVERGE_VALIDATE | \
  "${preprod_ssh[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" "sudo -n -- $CONTROL")"
grep -Fxq 'BACKUP_ACTIVATE_CONVERGE_VALIDATE=PASS' <<<"$activate_out"
grep -Fxq 'PREPROD_ADMIN_RECONCILIATION=PASS' <<<"$activate_out"

printf '%s\n' \
  'GOVERNED_SUCCESSOR_APPLY=COMMITTED' \
  'PROD_WRITE=NONE' \
  'RAW_PROD_GITHUB_BOUNDARY=NONE' \
  'CALLER_GENERIC_EXECUTION=NONE' \
  'DATA_ACTIVATION_AUTHORITY=PERSISTENT_DISABLED_TRANSACTION_CONSUMED'
