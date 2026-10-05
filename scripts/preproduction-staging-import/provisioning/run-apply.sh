#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE='scripts/preproduction-staging-import/provisioning/profile.json'
REMOTE_ROOT='scripts/preproduction-staging-import/provisioning/remote-provision-root.sh'
TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
HELPER='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db'
HELPER_DIGEST='scripts/preproduction-staging-import/privileged/agency-preprod-staging-db.sha256'
SANITIZER='scripts/preproduction-staging-import/privileged/agency-preprod-staging-sanitizer.py'
SANITIZER_DIGEST='scripts/preproduction-staging-import/privileged/agency-preprod-staging-sanitizer.py.sha256'
POLICY='scripts/preproduction-refresh/sanitization-policy.json'
POLICY_DIGEST='scripts/preproduction-staging-import/privileged/sanitization-policy.sha256'
APPLY_EVIDENCE_CONTRACT='scripts/preproduction-staging-import/provisioning/apply-evidence-contract.json'
APPLY_EVIDENCE_VALIDATOR='scripts/preproduction-staging-import/provisioning/validate-apply-evidence.py'
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
for path in "$PREPROD_PROVISIONING_SSH_KEY" "$PROFILE" "$REMOTE_ROOT" "$TRUST" "$HELPER" "$HELPER_DIGEST" "$SANITIZER" "$SANITIZER_DIGEST" "$POLICY" "$POLICY_DIGEST" "$APPLY_EVIDENCE_CONTRACT" "$APPLY_EVIDENCE_VALIDATOR"; do
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
jq -e '.issue_number == 851 and .revision_issue_number == 859 and .apply.one_shot == true and .apply.import == "FORBIDDEN" and .apply.import_sanitize_prove == "FORBIDDEN" and .execution.prod_access == "NONE"' "$PROFILE" >/dev/null
jq -e '.provisioning_authority_issue == 861 and .evidence_contract_revision_issue == 864 and .metadata_only == true and .pii_allowed == false and .secrets_allowed == false' "$APPLY_EVIDENCE_CONTRACT" >/dev/null

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$TRUST" >/dev/null
ssh_cmd=(ssh -i "$PREPROD_PROVISIONING_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
scp_cmd=(scp -i "$PREPROD_PROVISIONING_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts")

suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
[[ "$suffix" =~ ^[0-9a-f]{12}$ ]]
remote_dir="/var/tmp/agency-851-${suffix}"
remote_staged=0
remote_proof=''
cleanup_remote_stage() {
  [[ "$remote_staged" -eq 1 ]] || return 0
  "${ssh_cmd[@]}" "$ROOT_USER@$PREPROD_SSH_HOST" "rm -rf -- '$remote_dir'" >/dev/null 2>&1 || return 1
  remote_staged=0
}
cleanup() {
  local original="$?"
  trap - EXIT HUP INT TERM
  set +e
  [[ -z "$remote_proof" ]] || rm -f -- "$remote_proof"
  cleanup_remote_stage
  local cleanup_status="$?"
  if [[ "$original" -eq 0 && "$cleanup_status" -ne 0 ]]; then original=99; fi
  exit "$original"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

"${ssh_cmd[@]}" "$ROOT_USER@$PREPROD_SSH_HOST" "umask 077; test ! -L '$remote_dir'; rm -rf -- '$remote_dir'; install -d -m 700 '$remote_dir'"
remote_staged=1
"${scp_cmd[@]}" "$HELPER" "$HELPER_DIGEST" "$SANITIZER" "$SANITIZER_DIGEST" "$POLICY" "$POLICY_DIGEST" "$REMOTE_ROOT" "$ROOT_USER@$PREPROD_SSH_HOST:$remote_dir/" >/dev/null
"${ssh_cmd[@]}" "$ROOT_USER@$PREPROD_SSH_HOST" "chmod 600 '$remote_dir/'*"

remote_output="$("${ssh_cmd[@]}" "$ROOT_USER@$PREPROD_SSH_HOST" "env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /bin/bash '$remote_dir/remote-provision-root.sh' APPLY '$REQUEST_ID' '$REPOSITORY_SHA'")"
remote_proof="$RUNNER_TEMP/agency-861-apply-remote-${suffix}.env"
printf '%s\n' "$remote_output" > "$remote_proof"
chmod 600 "$remote_proof"
python3 "$APPLY_EVIDENCE_VALIDATOR" remote \
  --contract "$APPLY_EVIDENCE_CONTRACT" \
  --input "$remote_proof" \
  --expected-request-id "$REQUEST_ID" \
  --expected-repository-sha "$REPOSITORY_SHA"

evidence_dir="$GITHUB_WORKSPACE/artifacts/preprod-capability-provisioning"
mkdir -p "$evidence_dir"
python3 "$APPLY_EVIDENCE_VALIDATOR" emit \
  --contract "$APPLY_EVIDENCE_CONTRACT" \
  --input "$remote_proof" \
  --expected-request-id "$REQUEST_ID" \
  --expected-repository-sha "$REPOSITORY_SHA" \
  --expected-operation-profile "$OPERATION_PROFILE" \
  > "$evidence_dir/evidence.tmp"
chmod 600 "$evidence_dir/evidence.tmp"
python3 "$APPLY_EVIDENCE_VALIDATOR" evidence \
  --contract "$APPLY_EVIDENCE_CONTRACT" \
  --input "$evidence_dir/evidence.tmp" \
  --expected-request-id "$REQUEST_ID" \
  --expected-repository-sha "$REPOSITORY_SHA" \
  --expected-operation-profile "$OPERATION_PROFILE"
mv -f "$evidence_dir/evidence.tmp" "$evidence_dir/evidence.env"
rm -f -- "$remote_proof"
remote_proof=''
printf '%s\n' 'HELPER_BUNDLE_INSTALLED=PASS' 'APPLY_EVIDENCE_CONTRACT=PASS' 'SUDOERS_SCOPE=FIXED_HELPER_ONLY' 'PRECHECK=PASS' 'VERIFY_ABSENCE=PASS' 'PROD_ACCESS=NONE' 'ISSUE_834_APPLY=NOT_PERFORMED'
