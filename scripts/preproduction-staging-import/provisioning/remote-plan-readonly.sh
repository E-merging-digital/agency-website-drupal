#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REQUEST_ID="${1:-}"
[[ "$#" -eq 1 ]] || { echo 'Invalid PLAN invocation.' >&2; exit 64; }
[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 65; }

HELPER_PATH='/usr/local/sbin/agency-preprod-staging-db'
BUNDLE_DIR='/usr/local/lib/agency-preprod-staging'
SANITIZER_PATH="$BUNDLE_DIR/agency-preprod-staging-sanitizer.py"
POLICY_PATH="$BUNDLE_DIR/sanitization-policy.json"
PROJECT_SHARED='/var/www/agency-preprod/shared'
EXPECTED_HELPER_DIGEST='a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71'
EXPECTED_SANITIZER_DIGEST='fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f'
EXPECTED_POLICY_DIGEST='cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb'

test -d "$PROJECT_SHARED"
deploy_user="$(id -un)"
shared_owner="$(stat -c '%U' "$PROJECT_SHARED")"
[[ "$deploy_user" =~ ^[A-Za-z_][A-Za-z0-9._-]*$ ]]
[[ "$shared_owner" == "$deploy_user" ]] || {
  echo 'PREPROD deploy identity does not match server-owned shared-directory authority.' >&2
  exit 66
}

classify_file() {
  local path="$1" expected_identity="$2" expected_digest="$3"
  if [[ -L "$path" ]]; then
    printf '%s\n' 'NONCONFORMING_SYMLINK'
  elif [[ ! -e "$path" ]]; then
    printf '%s\n' 'ABSENT'
  elif [[ ! -f "$path" ]]; then
    printf '%s\n' 'NONCONFORMING_TYPE'
  elif [[ "$(stat -c '%u:%g:%a' "$path")" == "$expected_identity" && "$(sha256sum "$path" | awk '{print $1}')" == "$expected_digest" ]]; then
    printf '%s\n' 'EXACT'
  else
    printf '%s\n' 'NONCONFORMING'
  fi
}

helper_state="$(classify_file "$HELPER_PATH" '0:0:755' "$EXPECTED_HELPER_DIGEST")"
sanitizer_state="$(classify_file "$SANITIZER_PATH" '0:0:644' "$EXPECTED_SANITIZER_DIGEST")"
policy_state="$(classify_file "$POLICY_PATH" '0:0:644' "$EXPECTED_POLICY_DIGEST")"

bundle_directory_state='ABSENT'
if [[ -L "$BUNDLE_DIR" ]]; then
  bundle_directory_state='NONCONFORMING_SYMLINK'
elif [[ -e "$BUNDLE_DIR" ]]; then
  if [[ -d "$BUNDLE_DIR" && "$(stat -c '%u:%g:%a' "$BUNDLE_DIR")" == '0:0:755' ]]; then
    bundle_directory_state='EXACT'
  else
    bundle_directory_state='NONCONFORMING'
  fi
fi

# PLAN must not execute the privileged helper. sudo -l is read-only policy
# discovery; raw output is discarded and only a bounded classification remains.
sudoers_effective='NEEDS_PROVISIONING_OR_UNAVAILABLE'
if sudo -n -l -- "$HELPER_PATH" PRECHECK "$REQUEST_ID" 0 >/dev/null 2>&1; then
  sudoers_effective='BOUNDED_HELPER_LISTED'
fi

printf '%s\n' \
  "helper_state=$helper_state" \
  "bundle_directory_state=$bundle_directory_state" \
  "sanitizer_state=$sanitizer_state" \
  "policy_state=$policy_state" \
  "sudoers_effective=$sudoers_effective" \
  'plan_preprod_mutation=NONE' \
  'plan_privileged_helper_execution=NONE' \
  'plan_prod_access=NONE' \
  'plan_issue_834_apply=NOT_PERFORMED'
