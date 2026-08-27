#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE='scripts/preproduction-staging-import/provisioning/profile.json'
REMOTE_PLAN='scripts/preproduction-staging-import/provisioning/remote-plan-readonly.sh'
TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
HELPER='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db'
DIGEST='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db.sha256'
PROFILE_ID='agency-preprod-capability-provision-v1'

REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_SSH_USER="${PREPROD_SSH_USER:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
GITHUB_WORKSPACE="${GITHUB_WORKSPACE:-}"

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 64; }
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid repository SHA.' >&2; exit 65; }
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]] || { echo 'Unexpected provisioning profile.' >&2; exit 66; }
[[ "$GITHUB_RUN_ATTEMPT" == '1' ]] || { echo 'Provisioning authority is not replayable.' >&2; exit 67; }
[[ "$RUNNER_NAME" == 'agency-browser-runner-01' ]] || { echo 'Wrong trusted runner identity.' >&2; exit 68; }
[[ "$PREPROD_SSH_HOST" =~ ^[A-Za-z0-9._:-]+$ ]] || { echo 'PREPROD host secret is invalid.' >&2; exit 69; }
[[ "$PREPROD_SSH_USER" =~ ^[A-Za-z_][A-Za-z0-9._-]*$ ]] || { echo 'PREPROD deploy identity secret is invalid.' >&2; exit 70; }
test -f "$PREPROD_SSH_KEY"
test -f "$PROFILE"
test -f "$REMOTE_PLAN"
test -f "$TRUST"
test -f "$HELPER"
test -f "$DIGEST"
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"

expected_digest="$(jq -r '.helper.expected_sha256' "$PROFILE")"
[[ "$expected_digest" == 'ddd2785849da76f3a30dd3cf0ac59d03ed53692ad1e5cd03480a82d86d63a6a3' ]]
[[ "$(tr -d '\r\n' < "$DIGEST")" == "$expected_digest" ]]
[[ "$(sha256sum "$HELPER" | awk '{print $1}')" == "$expected_digest" ]]
jq -e '.issue_number == 851 and .plan.preprod_mutation == "NONE" and .execution.prod_access == "NONE"' "$PROFILE" >/dev/null

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" \
PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash "$TRUST" >/dev/null

ssh_cmd=(
  ssh
  -i "$PREPROD_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15
)

remote_output="$("${ssh_cmd[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
  "bash -s -- '$REQUEST_ID'" < "$REMOTE_PLAN")"

value() {
  local key="$1"
  sed -n "s/^${key}=//p" <<< "$remote_output" | head -n1
}

helper_state="$(value helper_state)"
sudoers_effective="$(value sudoers_effective)"
case "$helper_state" in
  ABSENT|EXACT|NONCONFORMING|NONCONFORMING_SYMLINK|NONCONFORMING_TYPE) ;;
  *) echo 'Unexpected bounded helper state classification.' >&2; exit 71;;
esac
case "$sudoers_effective" in
  BOUNDED_HELPER_LISTED|NEEDS_PROVISIONING_OR_UNAVAILABLE) ;;
  *) echo 'Unexpected bounded sudoers state classification.' >&2; exit 72;;
esac
grep -Fxq 'plan_preprod_mutation=NONE' <<< "$remote_output"
grep -Fxq 'plan_privileged_helper_execution=NONE' <<< "$remote_output"
grep -Fxq 'plan_prod_access=NONE' <<< "$remote_output"
grep -Fxq 'plan_issue_834_apply=NOT_PERFORMED' <<< "$remote_output"

evidence_dir="$GITHUB_WORKSPACE/artifacts/preprod-capability-provisioning"
evidence="$evidence_dir/evidence.env"
mkdir -p "$evidence_dir"
cat > "$evidence.tmp" <<EOF
schema_version=1
request_id=$REQUEST_ID
repository_sha=$REPOSITORY_SHA
operation_profile=$OPERATION_PROFILE
execution_mode=PLAN
plan_result=PASS
preprod_pinned_trust=PASS
helper_source_digest=PASS
helper_state=$helper_state
sudoers_effective_state=$sudoers_effective
preprod_mutation=NONE
privileged_helper_execution=NONE
prod_access=NONE
issue_834_apply=NOT_PERFORMED
apply_readiness=PROJECT_LEAD_ONLY_AFTER_MERGE
EOF
chmod 600 "$evidence.tmp"
mv -f "$evidence.tmp" "$evidence"
chmod 600 "$evidence"

printf '%s\n' \
  'PLAN_RESULT=PASS' \
  'PLAN_MUTATION_FREE=YES' \
  'PREPROD_PINNED_TRUST=PASS' \
  'PROD_ACCESS=NONE' \
  'ISSUE_834_APPLY=NOT_PERFORMED'
