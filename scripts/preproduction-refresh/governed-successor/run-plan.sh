#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROD_PLAN='scripts/preproduction-staging-import/real-one-shot/remote-prod-plan-readonly.sh'
PREPROD_PLAN='scripts/preproduction-refresh/governed-successor/remote-plan-observe.sh'
PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

: "${AUTHORITY_ISSUE:?}" "${REQUEST_ID:?}" "${REPOSITORY_SHA:?}" "${OPERATION_PROFILE:?}"
: "${PROD_SSH_HOST:?}" "${PROD_SSH_USER:?}" "${PROD_SSH_KEY:?}"
: "${PREPROD_SSH_HOST:?}" "${PREPROD_SSH_KEY:?}"
[[ "$REQUEST_ID" == "plan-${AUTHORITY_ISSUE}-"*'-r1' ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "${SOURCE_PROD_RELEASE_SHA:-}" == AUTO ]]
[[ "$OPERATION_PROFILE" == agency-preprod-refresh-simple-v1 ]]
[[ "${RUNNER_ENVIRONMENT:-}" == github-hosted ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == 1 ]]
[[ "$(git rev-parse HEAD)" == "$REPOSITORY_SHA" ]]

SERVER_HOST="$PROD_SSH_HOST" bash "$PROD_TRUST" PROVISION >/dev/null
SERVER_HOST="$PROD_SSH_HOST" bash "$PROD_TRUST" VERIFY_ONLY >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" bash "$PREPROD_TRUST_PROVISION" PROVISION >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$PREPROD_TRUST" >/dev/null
ssh_common=(-o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
prod=(ssh -i "$PROD_SSH_KEY" "${ssh_common[@]}")
preprod=(ssh -i "$PREPROD_SSH_KEY" "${ssh_common[@]}")

prod_out="$("${prod[@]}" "$PROD_SSH_USER@$PROD_SSH_HOST" 'bash -s -- AUTO' < "$PROD_PLAN")"
for expected in prod_release_identity=PASS prod_db_content_read=NONE prod_snapshot=NOT_PERFORMED prod_write=NONE; do
  grep -Fxq "$expected" <<<"$prod_out"
done
actual_prod="$(sed -n 's/^prod_release_sha=//p' <<<"$prod_out" | head -n1)"
[[ "$actual_prod" =~ ^[0-9a-f]{40}$ ]]

preprod_out="$("${preprod[@]}" "agency-preprod@$PREPROD_SSH_HOST" 'bash -s --' < "$PREPROD_PLAN")"
for expected in PREPROD_STANDARD_DRUSH=PASS PREPROD_BACKUP_PATH=READY PREPROD_CONFIG_SPLIT=READY PLAN_MUTATION=NONE DATA_ACTIVATION_AUTHORITY=DISABLED; do
  grep -Fxq "$expected" <<<"$preprod_out"
done

printf '%s\n' \
  'PLAN_RESULT=PASS' \
  "OBSERVED_PROD_RELEASE_SHA=$actual_prod" \
  'PROD_DB_CONTENT_READ=NONE' \
  'PROD_SNAPSHOT=NOT_PERFORMED' \
  'PROD_DATA_TRANSFER=NONE' \
  'PREPROD_DB_MUTATION=NONE' \
  'PROD_WRITE=NONE' \
  'DATA_ACTIVATION_AUTHORITY=DISABLED'
