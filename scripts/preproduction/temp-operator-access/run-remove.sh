#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE_ID='agency-preprod-temp-key-remove-v1'
BASE='scripts/preproduction/temp-operator-access'
PROFILE="$BASE/remove-profile.json"
REMOTE="$BASE/remote-remove-key.py"
TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
SSH_USER='agency-preprod'

REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
PREPROD_KNOWN_HOSTS_FILE="${PREPROD_KNOWN_HOSTS_FILE:-}"

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == '1' ]]
[[ "${RUNNER_ENVIRONMENT:-}" == 'github-hosted' ]]
[[ "${RUNNER_OS:-}" == 'Linux' ]]
[[ "${RUNNER_ARCH:-}" == 'X64' ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ ]]
[[ -n "$PREPROD_SSH_HOST" && -n "${RUNNER_TEMP:-}" ]]
[[ "$PREPROD_SSH_KEY" == "$RUNNER_TEMP/agency-912-key-${GITHUB_RUN_ID}."*".key" ]]
trust_home="${PREPROD_KNOWN_HOSTS_FILE%/.ssh/known_hosts}"
[[ "$trust_home" == "$RUNNER_TEMP/agency-912-home-${GITHUB_RUN_ID}."* ]]
[[ "$PREPROD_KNOWN_HOSTS_FILE" == "$trust_home/.ssh/known_hosts" ]]
[[ -f "$PREPROD_SSH_KEY" && ! -L "$PREPROD_SSH_KEY" ]]
[[ -f "$PREPROD_KNOWN_HOSTS_FILE" && ! -L "$PREPROD_KNOWN_HOSTS_FILE" ]]
test "$(stat -c '%a' "$PREPROD_SSH_KEY")" = '600'
test "$(stat -c '%a' "$PREPROD_KNOWN_HOSTS_FILE")" = '600'
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"

jq -e '
 .issue_number == 912 and
 .profile_id == "agency-preprod-temp-key-remove-v1" and
 .implementation_only == true and
 .fixed_target.ssh_user == "agency-preprod" and
 .fixed_target.authorized_keys == "/home/agency-preprod/.ssh/authorized_keys" and
 .fixed_target.fingerprint == "SHA256:xMkx4EF3Hu7b77DAYNwSW5iO0/VHwT/k2Ff25FhNxYg" and
 .fixed_target.action == "REMOVE" and
 .future_execution.root_secret == "FORBIDDEN" and
 .future_execution.provisioning_root_secret == "FORBIDDEN" and
 .future_execution.sudo == "FORBIDDEN" and
 .boundaries.data_activation_authority == "DISABLED"
' "$PROFILE" >/dev/null

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" \
PREPROD_KNOWN_HOSTS_FILE="$PREPROD_KNOWN_HOSTS_FILE" \
  bash "$TRUST" >/dev/null

ssh_opts=(
  -i "$PREPROD_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$PREPROD_KNOWN_HOSTS_FILE"
)
scp_opts=("${ssh_opts[@]}")

suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
remote_dir="/home/agency-preprod/.agency-912-${suffix}"
remote_script="$remote_dir/remote-remove-key.py"
raw="$RUNNER_TEMP/agency-912-${GITHUB_RUN_ID}.raw"
err="$RUNNER_TEMP/agency-912-${GITHUB_RUN_ID}.stderr"
for path in "$raw" "$err"; do
  [[ ! -e "$path" && ! -L "$path" ]]
done

remote_created=0
cleanup() {
  if [[ "$remote_created" -eq 1 ]]; then
    ssh "${ssh_opts[@]}" "$SSH_USER@$PREPROD_SSH_HOST" \
      "rm -rf -- '$remote_dir'" >/dev/null 2>&1 || true
  fi
  rm -f -- "$raw" "$err"
}
trap cleanup EXIT
trap 'cleanup; exit 129' HUP
trap 'cleanup; exit 130' INT
trap 'cleanup; exit 143' TERM

ssh "${ssh_opts[@]}" "$SSH_USER@$PREPROD_SSH_HOST" \
  "test ! -e '$remote_dir' && test ! -L '$remote_dir' && mkdir -m 700 -- '$remote_dir'" \
  >/dev/null 2>&1
remote_created=1

scp "${scp_opts[@]}" "$REMOTE" "$SSH_USER@$PREPROD_SSH_HOST:$remote_script" >/dev/null 2>&1
ssh "${ssh_opts[@]}" "$SSH_USER@$PREPROD_SSH_HOST" \
  "chmod 700 -- '$remote_script' && env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/home/agency-preprod /usr/bin/python3 -I '$remote_script'" \
  >"$raw" 2>"$err"

ssh "${ssh_opts[@]}" "$SSH_USER@$PREPROD_SSH_HOST" \
  "rm -rf -- '$remote_dir' && test ! -e '$remote_dir' && test ! -L '$remote_dir'" \
  >/dev/null 2>&1
remote_created=0

python3 - "$raw" <<'PY'
from pathlib import Path
import sys
raw = Path(sys.argv[1]).read_text(encoding="utf-8")
expected_prefix = (
    "SSH_USER=agency-preprod\n"
    "TEMP_KEY_FINGERPRINT=SHA256:xMkx4EF3Hu7b77DAYNwSW5iO0/VHwT/k2Ff25FhNxYg\n"
)
if not raw.startswith(expected_prefix):
    raise SystemExit(1)
lines = raw.splitlines()
if len(lines) != 5:
    raise SystemExit(1)
if lines[2] not in {"KEY_PRESENT_BEFORE=YES", "KEY_PRESENT_BEFORE=NO"}:
    raise SystemExit(1)
if lines[3] != "KEY_PRESENT_AFTER=NO" or lines[4] != "RESULT=PASS":
    raise SystemExit(1)
PY

printf '%s\n' \
  "REQUEST_ID=$REQUEST_ID" \
  "MAIN_SHA=$REPOSITORY_SHA" \
  "SSH_USER=$SSH_USER" \
  'TEMP_KEY_FINGERPRINT=SHA256:xMkx4EF3Hu7b77DAYNwSW5iO0/VHwT/k2Ff25FhNxYg' \
  'HOST_TRUST=PASS'
cat "$raw"
rm -f -- "$raw" "$err"
trap - EXIT HUP INT TERM
