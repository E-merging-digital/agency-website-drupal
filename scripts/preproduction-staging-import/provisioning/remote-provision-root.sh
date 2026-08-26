#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ACTION="${1:-}"
REQUEST_ID="${2:-}"
REPOSITORY_SHA="${3:-}"
if [[ "$#" -ne 3 || "$ACTION" != 'APPLY' ]]; then
  echo 'Invalid fixed provisioning invocation.' >&2
  exit 64
fi
[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 65; }
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid repository identity.' >&2; exit 66; }
[[ "$EUID" -eq 0 ]] || { echo 'Root execution is required.' >&2; exit 67; }

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
HELPER_SOURCE="$SCRIPT_DIR/agency-preprod-staging-db"
DIGEST_SOURCE="$SCRIPT_DIR/agency-preprod-staging-db.sha256"
HELPER_PATH='/usr/local/sbin/agency-preprod-staging-db'
SUDOERS_PATH='/etc/sudoers.d/agency-preprod-staging-db'
PROJECT_SHARED='/var/www/agency-preprod/shared'
EXPECTED_DIGEST='ddd2785849da76f3a30dd3cf0ac59d03ed53692ad1e5cd03480a82d86d63a6a3'
BACKUP_DIR="$SCRIPT_DIR/rollback"

for command_name in sha256sum stat install mktemp mv cp chmod chown visudo id runuser sudo; do
  command -v "$command_name" >/dev/null 2>&1 || {
    echo 'Required fixed provisioning dependency is unavailable.' >&2
    exit 68
  }
done

test -d "$PROJECT_SHARED"
test -f "$HELPER_SOURCE"
test -f "$DIGEST_SOURCE"
[[ ! -L "$HELPER_SOURCE" && ! -L "$DIGEST_SOURCE" ]]

deploy_user="$(stat -c '%U' "$PROJECT_SHARED")"
[[ "$deploy_user" =~ ^[A-Za-z_][A-Za-z0-9._-]*$ ]] || {
  echo 'Server-owned PREPROD deploy identity is invalid.' >&2
  exit 69
}
[[ "$deploy_user" != 'root' ]] || {
  echo 'PREPROD deploy identity must not be root.' >&2
  exit 70
}
id "$deploy_user" >/dev/null 2>&1 || {
  echo 'Server-owned PREPROD deploy identity does not exist.' >&2
  exit 71
}

pinned_digest="$(tr -d '\r\n' < "$DIGEST_SOURCE")"
source_digest="$(sha256sum "$HELPER_SOURCE" | awk '{print $1}')"
[[ "$pinned_digest" == "$EXPECTED_DIGEST" && "$source_digest" == "$EXPECTED_DIGEST" ]] || {
  echo 'Repository helper digest authority mismatch.' >&2
  exit 72
}

desired_policy="${deploy_user} ALL=(root) NOPASSWD: NOSETENV: ${HELPER_PATH}"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

helper_candidate=''
sudo_candidate=''
helper_preexisting=0
sudo_preexisting=0
mutation_started=0
committed=0

restore_helper() {
  local restore mode uid gid
  if [[ "$helper_preexisting" -eq 0 ]]; then
    rm -f -- "$HELPER_PATH"
    return 0
  fi
  test -f "$BACKUP_DIR/helper.before"
  uid="$(stat -c '%u' "$BACKUP_DIR/helper.before")"
  gid="$(stat -c '%g' "$BACKUP_DIR/helper.before")"
  mode="$(stat -c '%a' "$BACKUP_DIR/helper.before")"
  restore="$(mktemp /usr/local/sbin/.agency-preprod-staging-db.851.restore.XXXXXX)"
  cp -- "$BACKUP_DIR/helper.before" "$restore"
  chown "$uid:$gid" "$restore"
  chmod "$mode" "$restore"
  mv -f -- "$restore" "$HELPER_PATH"
}

restore_sudoers() {
  local restore mode uid gid
  if [[ "$sudo_preexisting" -eq 0 ]]; then
    rm -f -- "$SUDOERS_PATH"
    return 0
  fi
  test -f "$BACKUP_DIR/sudoers.before"
  uid="$(stat -c '%u' "$BACKUP_DIR/sudoers.before")"
  gid="$(stat -c '%g' "$BACKUP_DIR/sudoers.before")"
  mode="$(stat -c '%a' "$BACKUP_DIR/sudoers.before")"
  restore="$(mktemp /etc/sudoers.d/.agency-preprod-staging-db.851.restore.XXXXXX)"
  cp -- "$BACKUP_DIR/sudoers.before" "$restore"
  chown "$uid:$gid" "$restore"
  chmod "$mode" "$restore"
  visudo -cf "$restore" >/dev/null
  mv -f -- "$restore" "$SUDOERS_PATH"
}

rollback_on_exit() {
  local status="$?"
  trap - EXIT HUP INT TERM
  set +e
  [[ -z "$helper_candidate" ]] || rm -f -- "$helper_candidate"
  [[ -z "$sudo_candidate" ]] || rm -f -- "$sudo_candidate"
  if [[ "$mutation_started" -eq 1 && "$committed" -ne 1 ]]; then
    restore_sudoers
    restore_helper
  fi
  exit "$status"
}
trap rollback_on_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# Existing helper may be replaced only when it is already a safe root-owned
# regular file. Symlink or writable privilege-bearing prestate fails closed.
if [[ -L "$HELPER_PATH" ]]; then
  echo 'Existing privileged helper is a symlink; refusing mutation.' >&2
  exit 73
elif [[ -e "$HELPER_PATH" ]]; then
  [[ -f "$HELPER_PATH" ]] || { echo 'Existing privileged helper type is unsupported.' >&2; exit 74; }
  helper_identity="$(stat -c '%u:%g:%a' "$HELPER_PATH")"
  case "$helper_identity" in
    0:0:700|0:0:500|0:0:755|0:0:555) ;;
    *) echo 'Existing privileged helper ownership/mode is unsafe.' >&2; exit 75;;
  esac
  cp -a -- "$HELPER_PATH" "$BACKUP_DIR/helper.before"
  chmod 600 "$BACKUP_DIR/helper.before"
  helper_preexisting=1
fi

# An existing sudoers policy is accepted only if it is already the exact desired
# least-privilege line. Unknown or broader prestate is never overwritten.
if [[ -L "$SUDOERS_PATH" ]]; then
  echo 'Existing sudoers policy is a symlink; refusing mutation.' >&2
  exit 76
elif [[ -e "$SUDOERS_PATH" ]]; then
  [[ -f "$SUDOERS_PATH" ]] || { echo 'Existing sudoers policy type is unsupported.' >&2; exit 77; }
  visudo -cf "$SUDOERS_PATH" >/dev/null || { echo 'Existing sudoers policy is invalid.' >&2; exit 78; }
  [[ "$(cat "$SUDOERS_PATH")" == "$desired_policy" ]] || {
    echo 'Existing sudoers policy is not the exact bounded policy.' >&2
    exit 79
  }
  cp -a -- "$SUDOERS_PATH" "$BACKUP_DIR/sudoers.before"
  chmod 600 "$BACKUP_DIR/sudoers.before"
  sudo_preexisting=1
fi

helper_candidate="$(mktemp /usr/local/sbin/.agency-preprod-staging-db.851.XXXXXX)"
chmod 600 "$helper_candidate"
install -o root -g root -m 0755 "$HELPER_SOURCE" "$helper_candidate"
[[ ! -L "$helper_candidate" ]]
[[ "$(stat -c '%u:%g:%a' "$helper_candidate")" == '0:0:755' ]]
[[ "$(sha256sum "$helper_candidate" | awk '{print $1}')" == "$EXPECTED_DIGEST" ]]

sudo_candidate="$(mktemp /etc/sudoers.d/.agency-preprod-staging-db.851.XXXXXX)"
printf '%s\n' "$desired_policy" > "$sudo_candidate"
chown root:root "$sudo_candidate"
chmod 0440 "$sudo_candidate"
[[ "$(stat -c '%u:%g:%a' "$sudo_candidate")" == '0:0:440' ]]
[[ "$(cat "$sudo_candidate")" == "$desired_policy" ]]
! grep -Eq '[*?]' "$sudo_candidate"
! grep -Eiq '(^|[[:space:]])(mariadb|bash|sh|python|env)([[:space:]]|$)' "$sudo_candidate"
! grep -Eq 'NOPASSWD:[[:space:]]*ALL([[:space:]]|$)' "$sudo_candidate"
! grep -Eq '(^|[[:space:]])SETENV:' "$sudo_candidate"
visudo -cf "$sudo_candidate" >/dev/null

# Activate helper first; the deploy user receives no new privilege until the
# already validated sudoers candidate is atomically placed second.
mutation_started=1
mv -f -- "$helper_candidate" "$HELPER_PATH"
helper_candidate=''
mv -f -- "$sudo_candidate" "$SUDOERS_PATH"
sudo_candidate=''

# Final exact identity and policy proof.
[[ ! -L "$HELPER_PATH" && -f "$HELPER_PATH" ]]
[[ "$(stat -c '%u:%g:%a' "$HELPER_PATH")" == '0:0:755' ]]
[[ "$(sha256sum "$HELPER_PATH" | awk '{print $1}')" == "$EXPECTED_DIGEST" ]]
[[ ! -L "$SUDOERS_PATH" && -f "$SUDOERS_PATH" ]]
[[ "$(stat -c '%u:%g:%a' "$SUDOERS_PATH")" == '0:0:440' ]]
[[ "$(cat "$SUDOERS_PATH")" == "$desired_policy" ]]
visudo -cf "$SUDOERS_PATH" >/dev/null

# The only live capability proof allowed here is zero-data PRECHECK and
# VERIFY_ABSENCE through the deploy user's exact bounded sudo authority.
precheck="$(runuser -u "$deploy_user" -- sudo -n -- "$HELPER_PATH" PRECHECK "$REQUEST_ID" 0)"
grep -Fxq 'staging_admin_capability=PASS' <<< "$precheck"
grep -Fxq 'staging_db_present=NO' <<< "$precheck"
absence="$(runuser -u "$deploy_user" -- sudo -n -- "$HELPER_PATH" VERIFY_ABSENCE "$REQUEST_ID" 0)"
grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "$absence"

committed=1
printf '%s\n' \
  'helper_installed=PASS' \
  'helper_owner=root' \
  'helper_group=root' \
  'helper_mode=0755' \
  'helper_symlink=NO' \
  'helper_digest=PASS' \
  'sudoers_syntax=PASS' \
  'sudoers_scope=FIXED_HELPER_ONLY' \
  'direct_mariadb_sudo=FORBIDDEN' \
  'generic_root_executor=NONE' \
  'setenv=FORBIDDEN' \
  'precheck=PASS' \
  'verify_absence=PASS' \
  'staging_db_present_before=NO' \
  'staging_db_present_after=NO' \
  'preprod_runtime_db_touched=NO' \
  'prod_access=NONE' \
  'issue_834_apply=NOT_PERFORMED'
