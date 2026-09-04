#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

BASE='scripts/preproduction-refresh/governed-successor'
WORKER="$BASE/remote-server-to-server-worker.py"
ACTIVATION="$BASE/remote-apply-worker.sh"
REFRESH_JOBS_PREREQUISITE="$BASE/ensure-refresh-jobs-root.py"
CONTROL_OBSERVER="$BASE/observe-server-to-server-worker.py"
CONTROL_POLL="$BASE/poll-server-to-server-worker.sh"
PROD_REMOTE='scripts/production-readonly-snapshot/remote-stream.sh'
PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'
PROD_PIN='scripts/production-ssh-trust/prod-ed25519.pub'
PROD_FINGERPRINT='scripts/production-ssh-trust/prod-ed25519.sha256'
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
REFRESH_JOBS_ROOT='/var/www/agency-preprod/shared/refresh-jobs'
CONTROL_PLANE_HARD_TIMEOUT_SECONDS=5400
REMOTE_CONTROL_TIMEOUT_SECONDS=30

for var in AUTHORITY_ISSUE REQUEST_ID REPOSITORY_SHA SOURCE_PROD_RELEASE_SHA OPERATION_PROFILE \
  PROD_SSH_HOST PROD_SSH_USER PROD_SSH_KEY PREPROD_SSH_HOST PREPROD_SSH_KEY PREPROD_ROOT_KEY \
  RUNNER_TEMP GITHUB_WORKSPACE GITHUB_RUN_ID GITHUB_RUN_ATTEMPT RUNNER_ENVIRONMENT; do
  [[ -n "${!var:-}" ]] || { echo "Missing $var" >&2; exit 64; }
done
[[ "$REQUEST_ID" == "apply-${AUTHORITY_ISSUE}-"*'-r1' ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ && "$SOURCE_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == agency-preprod-refresh-simple-v1 ]]
[[ "$GITHUB_RUN_ATTEMPT" == 1 && "$RUNNER_ENVIRONMENT" == github-hosted ]]
[[ "$(git rev-parse HEAD)" == "$REPOSITORY_SHA" ]]
for path in "$WORKER" "$ACTIVATION" "$REFRESH_JOBS_PREREQUISITE" "$CONTROL_OBSERVER" "$CONTROL_POLL" \
  "$PROD_REMOTE" "$PROD_TRUST" "$PROD_PIN" "$PROD_FINGERPRINT" \
  "$PREPROD_TRUST_PROVISION" "$PREPROD_TRUST" "$PROD_SSH_KEY" "$PREPROD_SSH_KEY" "$PREPROD_ROOT_KEY"; do
  [[ -f "$path" && ! -L "$path" ]]
done

workspace="$(realpath -m "$GITHUB_WORKSPACE")"
temp="$(realpath -m "$RUNNER_TEMP")"
case "$temp/" in "$workspace/"*) echo 'RUNNER_TEMP must be outside workspace' >&2; exit 65;; esac
suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
[[ "$suffix" =~ ^[0-9a-f]{12}$ ]]
local_stage="$temp/agency-938-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
remote_stage="/run/agency-preprod-refresh/$suffix"
remote_staged=0
worker_started=0

mkdir -p \
  "$local_stage/trust-repo/scripts/production-ssh-trust" \
  "$local_stage/scripts/production-readonly-snapshot"
chmod 700 "$local_stage" "$local_stage/trust-repo" "$local_stage/trust-repo/scripts" \
  "$local_stage/trust-repo/scripts/production-ssh-trust" "$local_stage/scripts" \
  "$local_stage/scripts/production-readonly-snapshot"

cleanup() {
  local status=$?
  trap - EXIT HUP INT TERM
  set +e
  rm -rf -- "$local_stage"
  if [[ "$remote_staged" -eq 1 && "$worker_started" -eq 0 ]]; then
    timeout --signal=KILL "${REMOTE_CONTROL_TIMEOUT_SECONDS}s" \
      "${root_ssh[@]}" "root@$PREPROD_SSH_HOST" \
      "rm -rf -- '$remote_stage'" >/dev/null 2>&1 || status=98
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

cp "$WORKER" "$local_stage/remote-server-to-server-worker.py"
cp "$ACTIVATION" "$local_stage/remote-apply-worker.sh"
cp "$PROD_REMOTE" "$local_stage/scripts/production-readonly-snapshot/remote-stream.sh"
cp "$PROD_TRUST" "$local_stage/trust-repo/scripts/production-ssh-trust/manage-known-host.sh"
cp "$PROD_PIN" "$local_stage/trust-repo/scripts/production-ssh-trust/prod-ed25519.pub"
cp "$PROD_FINGERPRINT" "$local_stage/trust-repo/scripts/production-ssh-trust/prod-ed25519.sha256"
chmod 700 \
  "$local_stage/remote-server-to-server-worker.py" \
  "$local_stage/remote-apply-worker.sh" \
  "$local_stage/scripts/production-readonly-snapshot/remote-stream.sh" \
  "$local_stage/trust-repo/scripts/production-ssh-trust/manage-known-host.sh"
chmod 600 \
  "$local_stage/trust-repo/scripts/production-ssh-trust/prod-ed25519.pub" \
  "$local_stage/trust-repo/scripts/production-ssh-trust/prod-ed25519.sha256"

# PREPROD trust is repository-pinned before either PREPROD identity is used.
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" bash "$PREPROD_TRUST_PROVISION" PROVISION >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash "$PREPROD_TRUST" >/dev/null
ssh_common=(
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15
  -o ServerAliveInterval=5
  -o ServerAliveCountMax=2
)
root_ssh=(ssh -i "$PREPROD_ROOT_KEY" "${ssh_common[@]}")
root_scp=(scp -q -i "$PREPROD_ROOT_KEY" "${ssh_common[@]}")
preprod_ssh=(ssh -i "$PREPROD_SSH_KEY" "${ssh_common[@]}")

# JIT host prerequisite: converge/prove only the fixed refresh-jobs directory.
# This occurs before the transient PROD read identity is copied into local_stage
# or staged anywhere on PREPROD. The prerequisite has no PROD/DB capability.
prerequisite="$({
  timeout --signal=KILL "${REMOTE_CONTROL_TIMEOUT_SECONDS}s" \
    "${root_ssh[@]}" "root@$PREPROD_SSH_HOST" '/usr/bin/python3 -I -' \
    < "$REFRESH_JOBS_PREREQUISITE"
})"
[[ "$prerequisite" == 'REFRESH_JOBS_ROOT=READY' ]]
timeout --signal=KILL "${REMOTE_CONTROL_TIMEOUT_SECONDS}s" \
  "${preprod_ssh[@]}" "agency-preprod@$PREPROD_SSH_HOST" \
  "test -d '$REFRESH_JOBS_ROOT' && test ! -L '$REFRESH_JOBS_ROOT' && test \"\$(stat -c '%U:%G:%a' '$REFRESH_JOBS_ROOT')\" = agency-preprod:agency-preprod:700"

# Only after the fixed PREPROD jobs root is proven safe may the request-scoped
# read-only PROD identity be copied/staged for the detached PREPROD worker.
cp "$PROD_SSH_KEY" "$local_stage/prod-read.key"
chmod 600 "$local_stage/prod-read.key"

# Control-plane traffic contains fixed scripts, identities and metadata only.
# No PROD connection and no raw PROD byte exists on this GitHub-hosted runner.
# Existing #914/#938 evidence invariants remain authoritative:
# RAW_PROD_ON_GITHUB_HOSTED=NONE
# RAW_PROD_ROUTE=PROD_TO_PREPROD_DIRECT
timeout --signal=KILL "${REMOTE_CONTROL_TIMEOUT_SECONDS}s" \
  "${root_ssh[@]}" "root@$PREPROD_SSH_HOST" \
  "umask 077; test ! -e '$remote_stage'; install -d -o root -g root -m 700 '$remote_stage'"
remote_staged=1
timeout --signal=KILL "${REMOTE_CONTROL_TIMEOUT_SECONDS}s" \
  "${root_scp[@]}" -r "$local_stage/." "root@$PREPROD_SSH_HOST:$remote_stage/"
timeout --signal=KILL "${REMOTE_CONTROL_TIMEOUT_SECONDS}s" \
  "${root_ssh[@]}" "root@$PREPROD_SSH_HOST" \
  "chmod 700 '$remote_stage' '$remote_stage/remote-server-to-server-worker.py' '$remote_stage/remote-apply-worker.sh' '$remote_stage/scripts/production-readonly-snapshot/remote-stream.sh' '$remote_stage/trust-repo/scripts/production-ssh-trust/manage-known-host.sh'; chmod 600 '$remote_stage/prod-read.key' '$remote_stage/trust-repo/scripts/production-ssh-trust/prod-ed25519.pub' '$remote_stage/trust-repo/scripts/production-ssh-trust/prod-ed25519.sha256'; test \"\$(stat -c '%U:%G:%a' '$remote_stage/prod-read.key')\" = root:root:600"

# Detach the fixed PREPROD preparation worker before any PROD raw stream exists.
# The worker itself owns staging cleanup and then execs the existing #914
# activation/rollback worker as the PREPROD deploy user.
timeout --signal=KILL "${REMOTE_CONTROL_TIMEOUT_SECONDS}s" \
  "${root_ssh[@]}" "root@$PREPROD_SSH_HOST" \
  "nohup setsid --wait /usr/bin/python3 -I '$remote_stage/remote-server-to-server-worker.py' '$REQUEST_ID' '$REPOSITORY_SHA' '$SOURCE_PROD_RELEASE_SHA' '$PROD_SSH_HOST' '$PROD_SSH_USER' </dev/null >'$remote_stage/bootstrap.log' 2>&1 &"
worker_started=1
rm -rf -- "$local_stage"

# The outer timeout is the true total wall-clock boundary. The polling helper
# separately hard-bounds every individual SSH metadata/process observation.
set +e
timeout --signal=KILL "${CONTROL_PLANE_HARD_TIMEOUT_SECONDS}s" env \
  REQUEST_ID="$REQUEST_ID" \
  REPOSITORY_SHA="$REPOSITORY_SHA" \
  PREPROD_SSH_HOST="$PREPROD_SSH_HOST" \
  PREPROD_ROOT_KEY="$PREPROD_ROOT_KEY" \
  PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  CONTROL_OBSERVER="$CONTROL_OBSERVER" \
  bash "$CONTROL_POLL"
poll_status=$?
set -e
if [[ "$poll_status" -eq 124 || "$poll_status" -eq 137 ]]; then
  printf '%s\n' 'CONTROL_PLANE_STATE=BOUND_EXHAUSTED_NO_TERMINAL_METADATA' >&2
  exit 75
fi
exit "$poll_status"