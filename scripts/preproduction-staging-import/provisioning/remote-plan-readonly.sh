#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REQUEST_ID="${1:-}"
[[ "$#" -eq 1 ]] || { echo 'Invalid PLAN invocation.' >&2; exit 64; }
[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 65; }

HELPER_PATH='/usr/local/sbin/agency-preprod-staging-db'
PROJECT_SHARED='/var/www/agency-preprod/shared'
EXPECTED_DIGEST='ddd2785849da76f3a30dd3cf0ac59d03ed53692ad1e5cd03480a82d86d63a6a3'

test -d "$PROJECT_SHARED"
deploy_user="$(id -un)"
shared_owner="$(stat -c '%U' "$PROJECT_SHARED")"
[[ "$deploy_user" =~ ^[A-Za-z_][A-Za-z0-9._-]*$ ]]
[[ "$shared_owner" == "$deploy_user" ]] || {
  echo 'PREPROD deploy identity does not match server-owned shared-directory authority.' >&2
  exit 66
}

helper_state='ABSENT'
if [[ -L "$HELPER_PATH" ]]; then
  helper_state='NONCONFORMING_SYMLINK'
elif [[ -e "$HELPER_PATH" ]]; then
  if [[ ! -f "$HELPER_PATH" ]]; then
    helper_state='NONCONFORMING_TYPE'
  else
    identity="$(stat -c '%u:%g:%a' "$HELPER_PATH")"
    digest="$(sha256sum "$HELPER_PATH" | awk '{print $1}')"
    if [[ "$identity" == '0:0:755' && "$digest" == "$EXPECTED_DIGEST" ]]; then
      helper_state='EXACT'
    else
      helper_state='NONCONFORMING'
    fi
  fi
fi

# PLAN must not execute the privileged helper. `sudo -l` is a read-only policy
# query; its raw output is discarded and only a bounded classification remains.
sudoers_effective='NEEDS_PROVISIONING_OR_UNAVAILABLE'
if sudo -n -l -- "$HELPER_PATH" PRECHECK "$REQUEST_ID" 0 >/dev/null 2>&1; then
  sudoers_effective='BOUNDED_HELPER_LISTED'
fi

printf '%s\n' \
  "helper_state=$helper_state" \
  "sudoers_effective=$sudoers_effective" \
  'plan_preprod_mutation=NONE' \
  'plan_privileged_helper_execution=NONE' \
  'plan_prod_access=NONE' \
  'plan_issue_834_apply=NOT_PERFORMED'
