#!/usr/bin/env bash
set -Eeuo pipefail
PROFILE_ID='agency-preprod-refresh-capability-provision-v1'
BASE='scripts/preproduction-refresh/activation-capability'
PROFILE="$BASE/provisioning/profile.json"
REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
OPERATION_PROFILE="${OPERATION_PROFILE:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_SSH_USER="${PREPROD_SSH_USER:-agency-preprod}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == "$PROFILE_ID" ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == '1' ]]
[[ "${RUNNER_NAME:-}" == 'agency-browser-runner-01' ]]
[[ -f "$PREPROD_SSH_KEY" ]]
test "$(git rev-parse HEAD)" = "$REPOSITORY_SHA"
jq -e '.issue_number == 874 and .plan.preprod_mutation == "NONE" and .plan.helper_execution == "NONE" and .plan.sudo_execution == "NONE"' "$PROFILE" >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh >/dev/null
ssh -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts" "$PREPROD_SSH_USER@$PREPROD_SSH_HOST" \
  "test -d /var/www/agency-preprod && test -f /etc/nginx/sites-available/agency-preprod && printf '%s\n' 'preprod_capability_plan_metadata=PASS'"
printf '%s\n' \
  "request_id_sha256=$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print $1}')" \
  "repository_sha=$REPOSITORY_SHA" \
  'operation_profile=agency-preprod-refresh-capability-provision-v1' \
  'PLAN_MUTATION=NONE' \
  'PLAN_HELPER_EXECUTION=NONE' \
  'PLAN_SUDO_EXECUTION=NONE' \
  'PROD_ACCESS=NONE'
