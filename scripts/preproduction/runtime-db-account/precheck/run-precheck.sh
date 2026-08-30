#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE_ID='agency-preprod-runtime-db-precheck-v1'
BASE='scripts/preproduction/runtime-db-account'
PRECHECK_BASE="$BASE/precheck"
HELPER="$BASE/agency-preprod-runtime-db-account"
MANIFEST="$BASE/capability.json"
PROFILE="$PRECHECK_BASE/profile.json"
REMOTE="$PRECHECK_BASE/remote-precheck-root.py"
TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
FIXED_HELPER='/usr/local/sbin/agency-preprod-runtime-db-account'

REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_PROVISIONING_SSH_KEY="${PREPROD_PROVISIONING_SSH_KEY:-}"
PREPROD_KNOWN_HOSTS_FILE="${PREPROD_KNOWN_HOSTS_FILE:-}"

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == '1' ]]
[[ "${RUNNER_NAME:-}" == 'agency-browser-runner-01' ]]
[[ "${RUNNER_OS:-}" == 'Linux' ]]
[[ "${RUNNER_ARCH:-}" == 'X64' ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ ]]
[[ -n "$PREPROD_SSH_HOST" ]]
[[ -n "${RUNNER_TEMP:-}" ]]

expected_key="$RUNNER_TEMP/agency-895-root-${GITHUB_RUN_ID}.key"
expected_home="$RUNNER_TEMP/agency-895-root-home-${GITHUB_RUN_ID}"
expected_known_hosts="$expected_home/.ssh/known_hosts"
[[ "$PREPROD_PROVISIONING_SSH_KEY" == "$expected_key" ]]
[[ "$PREPROD_KNOWN_HOSTS_FILE" == "$expected_known_hosts" ]]
[[ -f "$PREPROD_PROVISIONING_SSH_KEY" && ! -L "$PREPROD_PROVISIONING_SSH_KEY" ]]
[[ -f "$PREPROD_KNOWN_HOSTS_FILE" && ! -L "$PREPROD_KNOWN_HOSTS_FILE" ]]
test "$(stat -c '%a' "$PREPROD_PROVISIONING_SSH_KEY")" = '600'
test "$(stat -c '%a' "$PREPROD_KNOWN_HOSTS_FILE")" = '600'
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"

for path in "$HELPER" "$MANIFEST" "$PROFILE" "$REMOTE" "$TRUST"; do
  [[ -f "$path" && ! -L "$path" ]]
done

jq -e '
  .issue_number == 895 and
  .profile_id == "agency-preprod-runtime-db-precheck-v1" and
  .implementation_only == true and
  .future_execution.action == "PRECHECK" and
  .future_execution.helper.transient == true and
  .future_execution.sudoers_install == "NONE" and
  .boundaries.data_activation_authority == "DISABLED"
' "$PROFILE" >/dev/null

jq -e '
  .issue_number == 893 and
  .status == "DESIGNED_NOT_INSTALLED_NOT_EXECUTED" and
  .fixed_target.database == "agency_preprod" and
  .fixed_target.user == "agency_preprod" and
  .fixed_target.account_host == "127.0.0.1" and
  .fixed_target.protocol == "TCP_LOOPBACK" and
  .fixed_target.port == 3306 and
  .installation.helper.path == "/usr/local/sbin/agency-preprod-runtime-db-account" and
  .installation.helper.owner == "root" and
  .installation.helper.group == "root" and
  .installation.helper.mode == "0755" and
  .observation.precheck_and_verify == "METADATA_ONLY"
' "$MANIFEST" >/dev/null

expected_digest="$(jq -r '.installation.helper.sha256' "$MANIFEST")"
[[ "$expected_digest" =~ ^[0-9a-f]{64}$ ]]
actual_digest="$(sha256sum "$HELPER" | awk '{print $1}')"
[[ "$actual_digest" == "$expected_digest" ]]

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" \
PREPROD_KNOWN_HOSTS_FILE="$PREPROD_KNOWN_HOSTS_FILE" \
  bash "$TRUST" >/dev/null

ssh_opts=(
  -i "$PREPROD_PROVISIONING_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$PREPROD_KNOWN_HOSTS_FILE"
)
scp_opts=("${ssh_opts[@]}")

suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
remote_dir="/var/tmp/agency-895-precheck-${suffix}"
raw_output="$RUNNER_TEMP/agency-895-precheck-${GITHUB_RUN_ID}.raw"
raw_error="$RUNNER_TEMP/agency-895-precheck-${GITHUB_RUN_ID}.stderr"
evidence_file="$RUNNER_TEMP/agency-895-precheck-${GITHUB_RUN_ID}.evidence"
status_file="$RUNNER_TEMP/agency-895-precheck-${GITHUB_RUN_ID}.status"

for path in "$raw_output" "$raw_error" "$evidence_file" "$status_file"; do
  [[ ! -e "$path" && ! -L "$path" ]]
done

remote_created=0
cleanup_best_effort() {
  if [[ "$remote_created" -eq 1 ]]; then
    ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" \
      "rm -rf -- '$remote_dir'" >/dev/null 2>&1 || true
  fi
  rm -f -- "$raw_output" "$raw_error"
}
trap cleanup_best_effort EXIT HUP INT TERM

ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" \
  "test ! -e '$FIXED_HELPER' && test ! -L '$FIXED_HELPER'" \
  >/dev/null 2>&1

ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" \
  "umask 077; mkdir -m 700 -- '$remote_dir' && { mkdir -m 700 -- '$remote_dir/source' || { rmdir -- '$remote_dir' 2>/dev/null || true; exit 1; }; }" \
  >/dev/null 2>&1
remote_created=1

scp "${scp_opts[@]}" \
  "$HELPER" \
  "root@$PREPROD_SSH_HOST:$remote_dir/source/agency-preprod-runtime-db-account" \
  >/dev/null 2>&1
scp "${scp_opts[@]}" \
  "$MANIFEST" \
  "root@$PREPROD_SSH_HOST:$remote_dir/source/capability.json" \
  >/dev/null 2>&1
scp "${scp_opts[@]}" \
  "$REMOTE" \
  "root@$PREPROD_SSH_HOST:$remote_dir/remote-precheck-root.py" \
  >/dev/null 2>&1

ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" \
  "chmod 700 '$remote_dir/remote-precheck-root.py' '$remote_dir/source/agency-preprod-runtime-db-account'; chmod 600 '$remote_dir/source/capability.json'" \
  >/dev/null 2>&1

set +e
ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" \
  "env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /usr/bin/python3 -I '$remote_dir/remote-precheck-root.py'" \
  >"$raw_output" 2>"$raw_error"
precheck_rc=$?
set -e
[[ "$precheck_rc" -eq 0 || "$precheck_rc" -eq 1 ]]

ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" \
  "rm -rf -- '$remote_dir' && test ! -e '$remote_dir' && test ! -L '$remote_dir'" \
  >/dev/null 2>&1
remote_created=0

python3 - "$raw_output" "$evidence_file" "$precheck_rc" <<'PY'
from pathlib import Path
import sys

source = Path(sys.argv[1])
target = Path(sys.argv[2])
rc = int(sys.argv[3])

raw = source.read_text(encoding="utf-8")
if len(raw.encode("utf-8")) > 2048:
    raise SystemExit("bounded metadata exceeded")
fields = (
    "TARGET_DATABASE_PRESENT",
    "ACCOUNT_127_PRESENT",
    "ACCOUNT_LOCALHOST_PRESENT",
    "EXPECTED_DB_GRANT",
    "RUNTIME_ACCOUNT_STATE",
)
lines = raw.splitlines()
if len(lines) != len(fields):
    raise SystemExit("metadata line count invalid")
parsed = {}
for expected, line in zip(fields, lines, strict=True):
    if "=" not in line:
        raise SystemExit("metadata line invalid")
    key, value = line.split("=", 1)
    if key != expected or key in parsed:
        raise SystemExit("metadata key invalid")
    parsed[key] = value

allowed = {
    "TARGET_DATABASE_PRESENT": {"YES", "NO", "UNKNOWN_FAIL_CLOSED"},
    "ACCOUNT_127_PRESENT": {"YES", "NO", "UNKNOWN_FAIL_CLOSED"},
    "ACCOUNT_LOCALHOST_PRESENT": {"YES", "NO", "UNKNOWN_FAIL_CLOSED"},
    "EXPECTED_DB_GRANT": {"EXACT", "NOT_EXACT", "UNKNOWN_FAIL_CLOSED"},
    "RUNTIME_ACCOUNT_STATE": {"EXACT", "RECONCILIATION_REQUIRED", "UNSAFE"},
}
for key, value in parsed.items():
    if value not in allowed[key]:
        raise SystemExit("metadata value invalid")

if rc == 0:
    if any(value == "UNKNOWN_FAIL_CLOSED" for value in parsed.values()):
        raise SystemExit("success contains unknown evidence")
elif rc == 1:
    if any(parsed[key] != "UNKNOWN_FAIL_CLOSED" for key in fields[:-1]):
        raise SystemExit("failure is not fail closed")
    if parsed["RUNTIME_ACCOUNT_STATE"] != "UNSAFE":
        raise SystemExit("failure classification invalid")
else:
    raise SystemExit("helper exit class invalid")

with target.open("x", encoding="utf-8") as handle:
    for key in fields:
        handle.write(f"{key}={parsed[key]}\n")
target.chmod(0o600)
PY

printf '%s\n' "$precheck_rc" > "$status_file"
chmod 600 "$status_file"
rm -f -- "$raw_output" "$raw_error"
trap - EXIT HUP INT TERM
