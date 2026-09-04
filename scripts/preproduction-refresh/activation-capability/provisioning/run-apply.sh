#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROFILE_ID='agency-preprod-refresh-capability-provision-v1'
BASE='scripts/preproduction-refresh/activation-capability'
REMOTE="$BASE/provisioning/remote-provision-root.sh"
PROFILE="$BASE/provisioning/profile.json"
CAPABILITY_PROFILE="$BASE/profile.json"
BUNDLE="$BASE/bundle.json"
VHOST_SELECTOR="$BASE/provisioning/nginx-vhost-selector.py"
VHOST_SELECTOR_BLOB='a17e3f932b9a5e7ec4978f3758ff0bf5bbae9c79'
TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_PROVISIONING_SSH_KEY="${PREPROD_PROVISIONING_SSH_KEY:-}"

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == '1' ]]
[[ "${RUNNER_NAME:-}" == 'agency-browser-runner-01' ]]
[[ -f "$PREPROD_PROVISIONING_SSH_KEY" && ! -L "$PREPROD_PROVISIONING_SSH_KEY" ]]
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"

files=(
  "$BASE/agency-preprod-refresh-control"
  "$BASE/agency-preprod-refresh-ingress"
  "$BASE/agency-preprod-refresh-authority-install"
  "$BASE/agency-preprod-refresh-authority-abort"
  "$BASE/transaction_contract.py"
  "$BASE/admin-reconcile.sh"
  "$BASE/admin-reconcile.php"
  "$BASE/side_effect_hardening.py"
  "$BASE/runtime_state_digest.py"
  "$BASE/data-activation-authority.disabled.json"
  "$BASE/nginx/agency-preprod-refresh-fence.conf"
  "$BASE/nginx/agency-preprod-refresh-internal-readiness.conf"
  "$BASE/provisioning/agency-preprod-refresh-control.sudoers"
  "$BASE/provisioning/agency-preprod-refresh-ingress.sudoers"
  "$VHOST_SELECTOR"
  "$CAPABILITY_PROFILE"
  "$PROFILE"
  "$BUNDLE"
  "$REMOTE"
)
for path in "${files[@]}"; do [[ -f "$path" && ! -L "$path" ]]; done
[[ "$(git rev-parse "HEAD:$VHOST_SELECTOR")" == "$VHOST_SELECTOR_BLOB" ]]

jq -e '
  .issue_number == 874 and .revision_issue == 915 and .abort_revision_issue == 917 and
  .profile_id == "agency-preprod-refresh-capability-provision-v1" and
  .apply.persistent_data_activation_authority_after_apply == "DISABLED" and
  .apply.transaction_authority_after_apply == "ABSENT" and
  .apply.abort_helper_after_apply == "INSTALLED_ROOT_ONLY" and
  .apply.real_data_activation == "FORBIDDEN" and
  .sudo.authority_installer_exposed == false and
  .sudo.abort_helper_exposed == false
' "$PROFILE" >/dev/null
jq -e '
  .issue_number == 874 and .revision_issue == 915 and .abort_revision_issue == 917 and
  .persistent_data_activation_authority_after_provisioning == "DISABLED" and
  .transaction_authority_after_provisioning == "ABSENT" and
  .pre_ingress_abort_after_provisioning == "INSTALLED_ROOT_ONLY" and
  .normal_sudo_exposure_for_abort == "NONE"
' "$BUNDLE" >/dev/null

check_digest() {
  local key="$1" path="$2" expected
  expected="$(jq -r --arg key "$key" '.digests[$key]' "$PROFILE")"
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]]
  [[ "$(sha256sum "$path" | awk '{print $1}')" == "$expected" ]]
}
check_digest helper "$BASE/agency-preprod-refresh-control"
check_digest ingress "$BASE/agency-preprod-refresh-ingress"
check_digest authority_installer "$BASE/agency-preprod-refresh-authority-install"
check_digest authority_abort "$BASE/agency-preprod-refresh-authority-abort"
check_digest transaction_contract "$BASE/transaction_contract.py"
check_digest admin_reconcile "$BASE/admin-reconcile.sh"
check_digest admin_reconcile_php "$BASE/admin-reconcile.php"
check_digest side_effect_hardening "$BASE/side_effect_hardening.py"
check_digest runtime_state_digest "$BASE/runtime_state_digest.py"
check_digest disabled_authority_state "$BASE/data-activation-authority.disabled.json"
check_digest control_sudoers "$BASE/provisioning/agency-preprod-refresh-control.sudoers"
check_digest ingress_sudoers "$BASE/provisioning/agency-preprod-refresh-ingress.sudoers"
check_digest fence_snippet "$BASE/nginx/agency-preprod-refresh-fence.conf"
check_digest internal_readiness "$BASE/nginx/agency-preprod-refresh-internal-readiness.conf"
check_digest capability_profile "$CAPABILITY_PROFILE"
check_digest bundle_manifest "$BUNDLE"

PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$TRUST" >/dev/null
ssh_opts=(-i "$PREPROD_PROVISIONING_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts")
suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
remote_dir="/var/tmp/agency-915-${suffix}"
cleanup() { ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" "rm -rf -- '$remote_dir'" >/dev/null 2>&1 || true; }
trap cleanup EXIT HUP INT TERM
ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" "umask 077; rm -rf -- '$remote_dir'; install -d -m 700 '$remote_dir/source'"
scp_opts=(-i "$PREPROD_PROVISIONING_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts")
scp "${scp_opts[@]}" \
  "$BASE/agency-preprod-refresh-control" \
  "$BASE/agency-preprod-refresh-ingress" \
  "$BASE/agency-preprod-refresh-authority-install" \
  "$BASE/agency-preprod-refresh-authority-abort" \
  "$BASE/transaction_contract.py" \
  "$BASE/admin-reconcile.sh" \
  "$BASE/admin-reconcile.php" \
  "$BASE/side_effect_hardening.py" \
  "$BASE/runtime_state_digest.py" \
  "$BASE/data-activation-authority.disabled.json" \
  "$BASE/bundle.json" \
  "$BASE/nginx/agency-preprod-refresh-fence.conf" \
  "$BASE/nginx/agency-preprod-refresh-internal-readiness.conf" \
  "$BASE/provisioning/agency-preprod-refresh-control.sudoers" \
  "$BASE/provisioning/agency-preprod-refresh-ingress.sudoers" \
  "$VHOST_SELECTOR" \
  "root@$PREPROD_SSH_HOST:$remote_dir/source/" >/dev/null
scp "${scp_opts[@]}" "$CAPABILITY_PROFILE" "root@$PREPROD_SSH_HOST:$remote_dir/source/capability-profile.json" >/dev/null
scp "${scp_opts[@]}" "$PROFILE" "root@$PREPROD_SSH_HOST:$remote_dir/source/provisioning-profile.json" >/dev/null
scp "${scp_opts[@]}" "$REMOTE" "root@$PREPROD_SSH_HOST:$remote_dir/remote-provision-root.sh" >/dev/null
profile_sha="$(sha256sum "$PROFILE" | awk '{print $1}')"
ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" \
  "chmod 700 '$remote_dir/remote-provision-root.sh'; env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /bin/bash '$remote_dir/remote-provision-root.sh' APPLY '$REQUEST_ID' '$REPOSITORY_SHA' '$profile_sha'"
printf '%s\n' \
  'CAPABILITY_PROVISIONING=PASS' \
  'BASE_REVISION_ISSUE=915' \
  'ABORT_REVISION_ISSUE=917' \
  'PERSISTENT_DATA_ACTIVATION_AUTHORITY=DISABLED' \
  'TRANSACTION_AUTHORITY=ABSENT' \
  'ABORT_HELPER=INSTALLED_ROOT_ONLY' \
  'NORMAL_SUDO_EXPOSURE=NONE' \
  'REAL_DATA_ACTIVATION=FORBIDDEN' \
  'PROD_ACCESS=NONE'
