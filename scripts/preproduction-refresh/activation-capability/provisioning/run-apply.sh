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
VHOST_SELECTOR_SHA256='e621f2d070132e924e6c0ef6b6f2c2dca2806a1ea4662cd9cd30544ffb9ea5fe'
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
[[ -f "$PREPROD_PROVISIONING_SSH_KEY" ]]
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"
for path in "$REMOTE" "$PROFILE" "$CAPABILITY_PROFILE" "$BUNDLE" "$VHOST_SELECTOR" "$BASE/agency-preprod-refresh-control" "$BASE/side_effect_hardening.py" "$BASE/runtime_state_digest.py" "$BASE/data-activation-authority.disabled.json" "$BASE/nginx/agency-preprod-refresh-fence.conf" "$BASE/nginx/agency-preprod-refresh-internal-readiness.conf" "$BASE/provisioning/agency-preprod-refresh-control.sudoers"; do
  [[ -f "$path" && ! -L "$path" ]]
done
[[ "$(sha256sum "$VHOST_SELECTOR" | awk '{print $1}')" == "$VHOST_SELECTOR_SHA256" ]]
for pair in \
  "helper:$BASE/agency-preprod-refresh-control" \
  "side_effect_hardening:$BASE/side_effect_hardening.py" \
  "runtime_state_digest:$BASE/runtime_state_digest.py" \
  "disabled_authority_state:$BASE/data-activation-authority.disabled.json" \
  "fence_snippet:$BASE/nginx/agency-preprod-refresh-fence.conf" \
  "internal_readiness:$BASE/nginx/agency-preprod-refresh-internal-readiness.conf" \
  "capability_profile:$CAPABILITY_PROFILE"; do
  key="${pair%%:*}"; path="${pair#*:}"
  expected="$(jq -r --arg key "$key" '.digests[$key]' "$PROFILE")"
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]]
  [[ "$(sha256sum "$path" | awk '{print $1}')" == "$expected" ]]
done
jq -e '.issue_number == 874 and .profile_id == "agency-preprod-refresh-capability-provision-v1" and .apply.data_activation_authority_after_apply == "DISABLED"' "$PROFILE" >/dev/null
jq -e '.issue_number == 874 and .data_activation_authority_after_provisioning == "DISABLED"' "$BUNDLE" >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$TRUST" >/dev/null
ssh_opts=(-i "$PREPROD_PROVISIONING_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts")
suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
remote_dir="/var/tmp/agency-874-${suffix}"
cleanup() { ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" "rm -rf -- '$remote_dir'" >/dev/null 2>&1 || true; }
trap cleanup EXIT HUP INT TERM
ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" "umask 077; rm -rf -- '$remote_dir'; install -d -m 700 '$remote_dir/source'"
scp_opts=(-i "$PREPROD_PROVISIONING_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts")
scp "${scp_opts[@]}" \
  "$BASE/agency-preprod-refresh-control" \
  "$BASE/side_effect_hardening.py" \
  "$BASE/runtime_state_digest.py" \
  "$BASE/data-activation-authority.disabled.json" \
  "$BASE/bundle.json" \
  "$BASE/nginx/agency-preprod-refresh-fence.conf" \
  "$BASE/nginx/agency-preprod-refresh-internal-readiness.conf" \
  "$BASE/provisioning/agency-preprod-refresh-control.sudoers" \
  "$VHOST_SELECTOR" \
  "root@$PREPROD_SSH_HOST:$remote_dir/source/" >/dev/null
scp "${scp_opts[@]}" "$CAPABILITY_PROFILE" "root@$PREPROD_SSH_HOST:$remote_dir/source/capability-profile.json" >/dev/null
scp "${scp_opts[@]}" "$PROFILE" "root@$PREPROD_SSH_HOST:$remote_dir/source/provisioning-profile.json" >/dev/null
scp "${scp_opts[@]}" "$REMOTE" "root@$PREPROD_SSH_HOST:$remote_dir/remote-provision-root.sh" >/dev/null
ssh "${ssh_opts[@]}" "root@$PREPROD_SSH_HOST" "chmod 700 '$remote_dir/remote-provision-root.sh'; env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /bin/bash '$remote_dir/remote-provision-root.sh' APPLY '$REQUEST_ID' '$REPOSITORY_SHA'"
printf '%s\n' 'CAPABILITY_PROVISIONING=PASS' 'DATA_ACTIVATION_AUTHORITY=DISABLED' 'REAL_DATA_ACTIVATION=FORBIDDEN' 'PROD_ACCESS=NONE'
