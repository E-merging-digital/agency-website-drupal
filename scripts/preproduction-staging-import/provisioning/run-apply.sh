#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE='scripts/preproduction-staging-import/provisioning/profile.json'
REMOTE_ROOT='scripts/preproduction-staging-import/provisioning/remote-provision-root.sh'
TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
HELPER='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db'
DIGEST='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db.sha256'
PROFILE_ID='agency-preprod-capability-provision-v1'
ROOT_USER='root'

REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_PROVISIONING_SSH_KEY="${PREPROD_PROVISIONING_SSH_KEY:-}"
GITHUB_WORKSPACE="${GITHUB_WORKSPACE:-}"

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 64; }
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid repository SHA.' >&2; exit 65; }
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]] || { echo 'Unexpected provisioning profile.' >&2; exit 66; }
[[ "$GITHUB_RUN_ATTEMPT" == '1' ]] || { echo 'Provisioning authority is not replayable.' >&2; exit 67; }
[[ "$RUNNER_NAME" == 'agency-browser-runner-01' ]] || { echo 'Wrong trusted runner identity.' >&2; exit 68; }
[[ "$PREPROD_SSH_HOST" =~ ^[A-Za-z0-9._:-]+$ ]] || { echo 'PREPROD host secret is invalid.' >&2; exit 69; }
test -f "$PREPROD_PROVISIONING_SSH_KEY"
test -f "$PROFILE"
test -f "$REMOTE_ROOT"
test -f "$TRUST"
test -f "$HELPER"
test -f "$DIGEST"
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"

expected_digest="$(jq -r '.helper.expected_sha256' "$PROFILE")"
[[ "$expected_digest" == 'ddd2785849da76f3a30dd3cf0ac59d03ed53692ad1e5cd03480a82d86d63a6a3' ]]
[[ "$(tr -d '\r\n' < "$DIGEST")" == "$expected_digest" ]]
[[ "$(sha256sum "$HELPER" | awk '{print $1}')" == "$expected_digest" ]]
jq -e '.issue_number == 851 and .apply.one_shot == true and .apply.import == "FORBIDDEN" and .execution.prod_access == "NONE"' "$PROFILE" >/dev/null

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" \
PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash "$TRUST" >/dev/null

ssh_cmd=(
  ssh
  -i "$PREPROD_PROVISIONING_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15
)
scp_cmd=(
  scp
  -i "$PREPROD_PROVISIONING_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
)

suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
[[ "$suffix" =~ ^[0-9a-f]{12}$ ]]
remote_dir="/var/tmp/agency-851-${suffix}"
remote_output=''
remote_staged=0

cleanup_remote_stage() {
  [[ "$remote_staged" -eq 1 ]] || return 0
  "${ssh_cmd[@]}" "$ROOT_USER@$PREPROD_SSH_HOST" \
    "rm -rf -- '$remote_dir'" >/dev/null 2>&1 || return 1
  remote_staged=0
}

cleanup() {
  local original="$?"
  trap - EXIT HUP INT TERM
  set +e
  cleanup_remote_stage
  local cleanup_status="$?"
  if [[ "$original" -eq 0 && "$cleanup_status" -ne 0 ]]; then
    original=99
  fi
  exit "$original"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# The privileged transport has no owner-controlled remote command, path, user,
# executable or destination. Every remote path is fixed or request-hash derived.
"${ssh_cmd[@]}" "$ROOT_USER@$PREPROD_SSH_HOST" \
  "umask 077; test ! -L '$remote_dir'; rm -rf -- '$remote_dir'; install -d -m 700 '$remote_dir'"
remote_staged=1

"${scp_cmd[@]}" \
  "$HELPER" \
  "$DIGEST" \
  "$REMOTE_ROOT" \
  "$ROOT_USER@$PREPROD_SSH_HOST:$remote_dir/" >/dev/null

"${ssh_cmd[@]}" "$ROOT_USER@$PREPROD_SSH_HOST" \
  "chmod 600 '$remote_dir/agency-preprod-staging-db' '$remote_dir/agency-preprod-staging-db.sha256' '$remote_dir/remote-provision-root.sh'"

remote_output="$("${ssh_cmd[@]}" "$ROOT_USER@$PREPROD_SSH_HOST" \
  "env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /bin/bash '$remote_dir/remote-provision-root.sh' APPLY '$REQUEST_ID' '$REPOSITORY_SHA'")"

for required in \
  'helper_installed=PASS' \
  'helper_owner=root' \
  'helper_group=root' \
  'helper_mode=0755' \
  'helper_symlink=NO' \
  'helper_digest=PASS' \
  'sudoers_syntax=PASS' \
  'sudoers_scope=FIXED_HELPER_ONLY' \
  'direct_mariadb_sudo=FORBIDDEN' \
  'generic_root_executor=NONE' \
  'setenv=FORBIDDEN' \
  'precheck=PASS' \
  'verify_absence=PASS' \
  'staging_db_present_before=NO' \
  'staging_db_present_after=NO' \
  'preprod_runtime_db_touched=NO' \
  'prod_access=NONE' \
  'issue_834_apply=NOT_PERFORMED'; do
  grep -Fxq "$required" <<< "$remote_output"
done

evidence_dir="$GITHUB_WORKSPACE/artifacts/preprod-capability-provisioning"
evidence="$evidence_dir/evidence.env"
mkdir -p "$evidence_dir"
cat > "$evidence.tmp" <<EOF
schema_version=1
request_id=$REQUEST_ID
repository_sha=$REPOSITORY_SHA
operation_profile=$OPERATION_PROFILE
execution_mode=APPLY
helper_installed=PASS
helper_owner=root
helper_group=root
helper_mode=0755
helper_symlink=NO
helper_digest=PASS
sudoers_syntax=PASS
sudoers_scope=FIXED_HELPER_ONLY
direct_mariadb_sudo=FORBIDDEN
generic_root_executor=NONE
setenv=FORBIDDEN
precheck=PASS
verify_absence=PASS
staging_db_present_before=NO
staging_db_present_after=NO
preprod_runtime_db_touched=NO
prod_access=NONE
issue_834_apply=NOT_PERFORMED
EOF
chmod 600 "$evidence.tmp"
mv -f "$evidence.tmp" "$evidence"
chmod 600 "$evidence"

printf '%s\n' \
  'HELPER_INSTALLED=PASS' \
  'SUDOERS_SCOPE=FIXED_HELPER_ONLY' \
  'PRECHECK=PASS' \
  'VERIFY_ABSENCE=PASS' \
  'PROD_ACCESS=NONE' \
  'ISSUE_834_APPLY=NOT_PERFORMED'
