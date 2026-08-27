#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE='scripts/preproduction-staging-import/provisioning/profile.json'
REMOTE_PLAN='scripts/preproduction-staging-import/provisioning/remote-plan-readonly.sh'
TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
HELPER='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db'
HELPER_DIGEST='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db.sha256'
SANITIZER='scripts/preproduction-staging-import/privileged/agency-preprod-staging-sanitizer.py'
SANITIZER_DIGEST='scripts/preproduction-staging-import/privileged/agency-preprod-staging-sanitizer.py.sha256'
POLICY='scripts/preproduction-refresh/sanitization-policy.json'
POLICY_DIGEST='scripts/preproduction-staging-import/privileged/sanitization-policy.sha256'
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
for path in "$PREPROD_SSH_KEY" "$PROFILE" "$REMOTE_PLAN" "$TRUST" "$HELPER" "$HELPER_DIGEST" "$SANITIZER" "$SANITIZER_DIGEST" "$POLICY" "$POLICY_DIGEST"; do
  test -f "$path"
done
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"

expected_helper="$(jq -r '.helper.expected_sha256' "$PROFILE")"
expected_sanitizer="$(jq -r '.bundle.sanitizer.expected_sha256' "$PROFILE")"
expected_policy="$(jq -r '.bundle.policy.expected_sha256' "$PROFILE")"
[[ "$expected_helper" == 'a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71' ]]
[[ "$expected_sanitizer" == 'fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f' ]]
[[ "$expected_policy" == 'cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb' ]]
[[ "$(tr -d '\r\n' < "$HELPER_DIGEST")" == "$expected_helper" ]]
[[ "$(sha256sum "$HELPER" | awk '{print $1}')" == "$expected_helper" ]]
[[ "$(tr -d '\r\n' < "$SANITIZER_DIGEST")" == "$expected_sanitizer" ]]
[[ "$(sha256sum "$SANITIZER" | awk '{print $1}')" == "$expected_sanitizer" ]]
[[ "$(tr -d '\r\n' < "$POLICY_DIGEST")" == "$expected_policy" ]]
[[ "$(sha256sum "$POLICY" | awk '{print $1}')" == "$expected_policy" ]]
jq -e '.issue_number == 851 and .revision_issue_number == 859 and .plan.preprod_mutation == "NONE" and .execution.prod_access == "NONE"' "$PROFILE" >/dev/null

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$TRUST" >/dev/null
ssh_cmd=(ssh -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
remote_output="$("${ssh_cmd[@]}" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" "bash -s -- '$REQUEST_ID'" < "$REMOTE_PLAN")"

value() { local key="$1"; sed -n "s/^${key}=//p" <<< "$remote_output" | head -n1; }
for key in helper_state bundle_directory_state sanitizer_state policy_state; do
  state="$(value "$key")"
  case "$state" in ABSENT|EXACT|NONCONFORMING|NONCONFORMING_SYMLINK|NONCONFORMING_TYPE) ;; *) echo 'Unexpected bundle state classification.' >&2; exit 71;; esac
done
sudoers_effective="$(value sudoers_effective)"
case "$sudoers_effective" in BOUNDED_HELPER_LISTED|NEEDS_PROVISIONING_OR_UNAVAILABLE) ;; *) echo 'Unexpected bounded sudoers state classification.' >&2; exit 72;; esac
grep -Fxq 'plan_preprod_mutation=NONE' <<< "$remote_output"
grep -Fxq 'plan_privileged_helper_execution=NONE' <<< "$remote_output"
grep -Fxq 'plan_prod_access=NONE' <<< "$remote_output"
grep -Fxq 'plan_issue_834_apply=NOT_PERFORMED' <<< "$remote_output"

evidence_dir="$GITHUB_WORKSPACE/artifacts/preprod-capability-provisioning"
mkdir -p "$evidence_dir"
cat > "$evidence_dir/evidence.tmp" <<EOF
schema_version=1
request_id=$REQUEST_ID
repository_sha=$REPOSITORY_SHA
operation_profile=$OPERATION_PROFILE
execution_mode=PLAN
plan_result=PASS
preprod_pinned_trust=PASS
helper_bundle_source_digest=PASS
helper_state=$(value helper_state)
bundle_directory_state=$(value bundle_directory_state)
sanitizer_state=$(value sanitizer_state)
policy_state=$(value policy_state)
sudoers_effective_state=$sudoers_effective
preprod_mutation=NONE
privileged_helper_execution=NONE
prod_access=NONE
issue_834_apply=NOT_PERFORMED
apply_readiness=PROJECT_LEAD_ONLY_AFTER_MERGE
EOF
chmod 600 "$evidence_dir/evidence.tmp"
mv -f "$evidence_dir/evidence.tmp" "$evidence_dir/evidence.env"
printf '%s\n' 'PLAN_RESULT=PASS' 'PLAN_MUTATION_FREE=YES' 'HELPER_BUNDLE_DIGEST=PASS' 'PREPROD_PINNED_TRUST=PASS' 'PROD_ACCESS=NONE' 'ISSUE_834_APPLY=NOT_PERFORMED'
