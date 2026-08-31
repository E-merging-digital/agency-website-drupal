#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

BASE='scripts/preproduction-refresh/governed-successor'
CONTRACT="$BASE/orchestration-contract.py"
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
ABORT='/usr/local/sbin/agency-preprod-refresh-authority-abort'

RECOVERY_AUTHORITY_ISSUE="${RECOVERY_AUTHORITY_ISSUE:-}"
RECOVERY_REQUEST_ID="${RECOVERY_REQUEST_ID:-}"
RECOVERY_REPOSITORY_SHA="${RECOVERY_REPOSITORY_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
TARGET_SUCCESSOR_ISSUE="${TARGET_SUCCESSOR_ISSUE:-}"
TARGET_REQUEST_ID="${TARGET_REQUEST_ID:-}"
TARGET_MAIN_SHA="${TARGET_MAIN_SHA:-}"
TARGET_PROFILE_ID="${TARGET_PROFILE_ID:-}"
TARGET_AUTHORITY_ID="${TARGET_AUTHORITY_ID:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_ROOT_SSH_KEY="${PREPROD_ROOT_SSH_KEY:-}"
RUNNER_TEMP="${RUNNER_TEMP:-}"
GITHUB_RUN_ID="${GITHUB_RUN_ID:-}"

[[ "$RECOVERY_AUTHORITY_ISSUE" =~ ^[0-9]+$ && "$RECOVERY_AUTHORITY_ISSUE" -gt 917 ]]
[[ "$RECOVERY_REQUEST_ID" == recover-abort-"$RECOVERY_AUTHORITY_ISSUE"-*-r1 ]]
[[ "$RECOVERY_REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == 'agency-preprod-governed-refresh-successor-v1' ]]
[[ "$TARGET_SUCCESSOR_ISSUE" =~ ^[0-9]+$ && "$TARGET_SUCCESSOR_ISSUE" -gt 917 ]]
[[ "$TARGET_SUCCESSOR_ISSUE" != "$RECOVERY_AUTHORITY_ISSUE" ]]
[[ "$TARGET_REQUEST_ID" == apply-"$TARGET_SUCCESSOR_ISSUE"-*-r1 ]]
[[ "$TARGET_MAIN_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$TARGET_PROFILE_ID" == 'agency-preprod-refresh-capability-v1' ]]
[[ "$TARGET_AUTHORITY_ID" =~ ^[0-9a-f]{64}$ ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == '1' ]]
[[ "${RUNNER_NAME:-}" == 'agency-browser-runner-01' ]]
[[ -n "$RUNNER_TEMP" && -n "$GITHUB_RUN_ID" && -n "$PREPROD_SSH_HOST" ]]
for path in "$CONTRACT" "$PREPROD_TRUST" "$PREPROD_ROOT_SSH_KEY"; do
  [[ -f "$path" && ! -L "$path" ]]
done
[[ "$(git rev-parse HEAD)" == "$RECOVERY_REPOSITORY_SHA" ]]
python3 "$CONTRACT" verify-repository >/dev/null

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$PREPROD_TRUST" >/dev/null
root_ssh=(ssh -i "$PREPROD_ROOT_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
request_file="$RUNNER_TEMP/agency-914-recover-abort-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.json"
cleanup() { rm -f -- "$request_file"; }
trap cleanup EXIT HUP INT TERM

python3 "$CONTRACT" abort-request \
  --issue "$TARGET_SUCCESSOR_ISSUE" \
  --request-id "$TARGET_REQUEST_ID" \
  --main-sha "$TARGET_MAIN_SHA" \
  --profile-id "$TARGET_PROFILE_ID" \
  --authority-id "$TARGET_AUTHORITY_ID" > "$request_file"

output="$("${root_ssh[@]}" "root@$PREPROD_SSH_HOST" "$ABORT" < "$request_file")"
grep -Fxq 'PRE_INGRESS_ABORT=PASS' <<<"$output"
grep -Fxq 'ABORTED_TERMINAL_STATE=PRESENT' <<<"$output"
grep -Fxq 'ACTIVE_AUTHORITY_AFTER_ABORT=ABSENT' <<<"$output"
grep -Fxq 'EXACT_BINDING=PASS' <<<"$output"
grep -Fxq 'ABSENCE_PROOF=PASS' <<<"$output"
grep -Fxq 'RUNTIME_ROLLBACK_CLAIM=NONE' <<<"$output"

printf '%s\n' \
  'HARD_RUNNER_LOSS_AFTER_ARM=RECOVERABLE' \
  'RECOVERY_EXECUTION_MAIN_BINDING=EXACT_CURRENT_MAIN' \
  'TARGET_TRANSACTION_BINDING=EXACT_HISTORICAL_AUTHORITY' \
  'RECOVERY_PROD_ACCESS=NONE' \
  'RECOVERY_FIXED_HELPER=agency-preprod-refresh-authority-abort_ONLY' \
  'RECOVERY_ABORTED_TERMINAL=PASS' \
  'RECOVERY_ACTIVE_AUTHORITY_AFTER=ABSENT' \
  'ABORTED_IS_NOT_ROLLED_BACK=PASS' \
  'CALLER_GENERIC_EXECUTION=NONE'
