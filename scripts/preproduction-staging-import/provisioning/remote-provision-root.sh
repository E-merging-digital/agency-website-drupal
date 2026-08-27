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
HELPER_DIGEST_SOURCE="$SCRIPT_DIR/agency-preprod-staging-db.sha256"
SANITIZER_SOURCE="$SCRIPT_DIR/agency-preprod-staging-sanitizer.py"
SANITIZER_DIGEST_SOURCE="$SCRIPT_DIR/agency-preprod-staging-sanitizer.py.sha256"
POLICY_SOURCE="$SCRIPT_DIR/sanitization-policy.json"
POLICY_DIGEST_SOURCE="$SCRIPT_DIR/sanitization-policy.sha256"
HELPER_PATH='/usr/local/sbin/agency-preprod-staging-db'
BUNDLE_DIR='/usr/local/lib/agency-preprod-staging'
SANITIZER_PATH="$BUNDLE_DIR/agency-preprod-staging-sanitizer.py"
POLICY_PATH="$BUNDLE_DIR/sanitization-policy.json"
SUDOERS_PATH='/etc/sudoers.d/agency-preprod-staging-db'
PROJECT_SHARED='/var/www/agency-preprod/shared'
EXPECTED_HELPER_DIGEST='a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71'
EXPECTED_SANITIZER_DIGEST='fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f'
EXPECTED_POLICY_DIGEST='cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb'
BACKUP_DIR="$SCRIPT_DIR/rollback"

for command_name in sha256sum stat install mktemp mv cp chmod chown visudo id runuser sudo; do
  command -v "$command_name" >/dev/null 2>&1 || { echo 'Required fixed provisioning dependency is unavailable.' >&2; exit 68; }
done

test -d "$PROJECT_SHARED"
for path in "$HELPER_SOURCE" "$HELPER_DIGEST_SOURCE" "$SANITIZER_SOURCE" "$SANITIZER_DIGEST_SOURCE" "$POLICY_SOURCE" "$POLICY_DIGEST_SOURCE"; do
  test -f "$path"
  [[ ! -L "$path" ]]
done

for pair in \
  "$HELPER_SOURCE:$HELPER_DIGEST_SOURCE:$EXPECTED_HELPER_DIGEST" \
  "$SANITIZER_SOURCE:$SANITIZER_DIGEST_SOURCE:$EXPECTED_SANITIZER_DIGEST" \
  "$POLICY_SOURCE:$POLICY_DIGEST_SOURCE:$EXPECTED_POLICY_DIGEST"; do
  IFS=: read -r source digest_source expected <<< "$pair"
  pinned="$(tr -d '\r\n' < "$digest_source")"
  actual="$(sha256sum "$source" | awk '{print $1}')"
  [[ "$pinned" == "$expected" && "$actual" == "$expected" ]] || { echo 'Repository helper bundle digest authority mismatch.' >&2; exit 69; }
done

deploy_user="$(stat -c '%U' "$PROJECT_SHARED")"
[[ "$deploy_user" =~ ^[A-Za-z_][A-Za-z0-9._-]*$ ]] || { echo 'Server-owned PREPROD deploy identity is invalid.' >&2; exit 70; }
[[ "$deploy_user" != 'root' ]] || { echo 'PREPROD deploy identity must not be root.' >&2; exit 71; }
id "$deploy_user" >/dev/null 2>&1 || { echo 'Server-owned PREPROD deploy identity does not exist.' >&2; exit 72; }
desired_policy="${deploy_user} ALL=(root) NOPASSWD: NOSETENV: ${HELPER_PATH}"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
helper_candidate=''
sanitizer_candidate=''
policy_candidate=''
sudo_candidate=''
helper_preexisting=0
bundle_dir_preexisting=0
sanitizer_preexisting=0
policy_preexisting=0
sudo_preexisting=0
mutation_started=0
committed=0

backup_regular_if_present() {
  local path="$1" backup="$2" allowed_modes="$3"
  if [[ -L "$path" ]]; then return 20; fi
  if [[ ! -e "$path" ]]; then return 1; fi
  [[ -f "$path" ]] || return 21
  identity="$(stat -c '%u:%g:%a' "$path")"
  [[ "$identity" =~ ^0:0:($allowed_modes)$ ]] || return 22
  cp -a -- "$path" "$backup"
  chmod 600 "$backup"
  return 0
}

restore_file() {
  local path="$1" backup="$2" preexisting="$3" mode="$4"
  if [[ "$preexisting" -eq 0 ]]; then rm -f -- "$path"; return 0; fi
  install -o root -g root -m "$mode" "$backup" "$path"
}

rollback_on_exit() {
  local status="$?"
  trap - EXIT HUP INT TERM
  set +e
  [[ -z "$helper_candidate" ]] || rm -f -- "$helper_candidate"
  [[ -z "$sanitizer_candidate" ]] || rm -f -- "$sanitizer_candidate"
  [[ -z "$policy_candidate" ]] || rm -f -- "$policy_candidate"
  [[ -z "$sudo_candidate" ]] || rm -f -- "$sudo_candidate"
  if [[ "$mutation_started" -eq 1 && "$committed" -ne 1 ]]; then
    restore_file "$HELPER_PATH" "$BACKUP_DIR/helper.before" "$helper_preexisting" 0755
    restore_file "$SANITIZER_PATH" "$BACKUP_DIR/sanitizer.before" "$sanitizer_preexisting" 0644
    restore_file "$POLICY_PATH" "$BACKUP_DIR/policy.before" "$policy_preexisting" 0644
    if [[ "$sudo_preexisting" -eq 0 ]]; then rm -f -- "$SUDOERS_PATH"; else install -o root -g root -m 0440 "$BACKUP_DIR/sudoers.before" "$SUDOERS_PATH"; fi
    if [[ "$bundle_dir_preexisting" -eq 0 ]]; then rmdir "$BUNDLE_DIR" 2>/dev/null || true; fi
  fi
  exit "$status"
}
trap rollback_on_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

if backup_regular_if_present "$HELPER_PATH" "$BACKUP_DIR/helper.before" '700|500|755|555'; then helper_preexisting=1; else rc=$?; [[ "$rc" -eq 1 ]] || { echo 'Existing privileged helper is unsafe.' >&2; exit 73; }; fi
if [[ -L "$BUNDLE_DIR" ]]; then echo 'Existing bundle directory is a symlink.' >&2; exit 74; fi
if [[ -e "$BUNDLE_DIR" ]]; then
  [[ -d "$BUNDLE_DIR" && "$(stat -c '%u:%g:%a' "$BUNDLE_DIR")" == '0:0:755' ]] || { echo 'Existing bundle directory is unsafe.' >&2; exit 75; }
  bundle_dir_preexisting=1
fi
if backup_regular_if_present "$SANITIZER_PATH" "$BACKUP_DIR/sanitizer.before" '644'; then sanitizer_preexisting=1; else rc=$?; [[ "$rc" -eq 1 ]] || { echo 'Existing sanitizer bundle file is unsafe.' >&2; exit 76; }; fi
if backup_regular_if_present "$POLICY_PATH" "$BACKUP_DIR/policy.before" '644'; then policy_preexisting=1; else rc=$?; [[ "$rc" -eq 1 ]] || { echo 'Existing policy bundle file is unsafe.' >&2; exit 77; }; fi

if [[ -L "$SUDOERS_PATH" ]]; then echo 'Existing sudoers policy is a symlink.' >&2; exit 78; fi
if [[ -e "$SUDOERS_PATH" ]]; then
  [[ -f "$SUDOERS_PATH" ]] || { echo 'Existing sudoers policy type is unsupported.' >&2; exit 79; }
  visudo -cf "$SUDOERS_PATH" >/dev/null || { echo 'Existing sudoers policy is invalid.' >&2; exit 80; }
  [[ "$(cat "$SUDOERS_PATH")" == "$desired_policy" ]] || { echo 'Existing sudoers policy is not exact.' >&2; exit 81; }
  cp -a -- "$SUDOERS_PATH" "$BACKUP_DIR/sudoers.before"
  chmod 600 "$BACKUP_DIR/sudoers.before"
  sudo_preexisting=1
fi

if [[ "$bundle_dir_preexisting" -eq 0 ]]; then
  install -d -o root -g root -m 0755 "$BUNDLE_DIR"
  mutation_started=1
fi
helper_candidate="$(mktemp /usr/local/sbin/.agency-preprod-staging-db.859.XXXXXX)"
sanitzer_dir="$BUNDLE_DIR"
sanitizer_candidate="$(mktemp "$sanitzer_dir/.agency-preprod-staging-sanitizer.859.XXXXXX")"
policy_candidate="$(mktemp "$BUNDLE_DIR/.sanitization-policy.859.XXXXXX")"
sudo_candidate="$(mktemp /etc/sudoers.d/.agency-preprod-staging-db.859.XXXXXX)"
install -o root -g root -m 0755 "$HELPER_SOURCE" "$helper_candidate"
install -o root -g root -m 0644 "$SANITIZER_SOURCE" "$sanitizer_candidate"
install -o root -g root -m 0644 "$POLICY_SOURCE" "$policy_candidate"
printf '%s\n' "$desired_policy" > "$sudo_candidate"
chown root:root "$sudo_candidate"
chmod 0440 "$sudo_candidate"

[[ "$(sha256sum "$helper_candidate" | awk '{print $1}')" == "$EXPECTED_HELPER_DIGEST" ]]
[[ "$(sha256sum "$sanitizer_candidate" | awk '{print $1}')" == "$EXPECTED_SANITIZER_DIGEST" ]]
[[ "$(sha256sum "$policy_candidate" | awk '{print $1}')" == "$EXPECTED_POLICY_DIGEST" ]]
[[ "$(cat "$sudo_candidate")" == "$desired_policy" ]]
! grep -Eq '[*?]' "$sudo_candidate"
! grep -Eiq '(^|[[:space:]])(mariadb|bash|sh|python|env)([[:space:]]|$)' "$sudo_candidate"
! grep -Eq 'NOPASSWD:[[:space:]]*ALL([[:space:]]|$)' "$sudo_candidate"
! grep -Eq '(^|[[:space:]])SETENV:' "$sudo_candidate"
visudo -cf "$sudo_candidate" >/dev/null

mutation_started=1
mv -f -- "$sanitizer_candidate" "$SANITIZER_PATH"; sanitizer_candidate=''
mv -f -- "$policy_candidate" "$POLICY_PATH"; policy_candidate=''
mv -f -- "$helper_candidate" "$HELPER_PATH"; helper_candidate=''
mv -f -- "$sudo_candidate" "$SUDOERS_PATH"; sudo_candidate=''

[[ "$(stat -c '%u:%g:%a' "$BUNDLE_DIR")" == '0:0:755' ]]
[[ "$(stat -c '%u:%g:%a' "$HELPER_PATH")" == '0:0:755' ]]
[[ "$(stat -c '%u:%g:%a' "$SANITIZER_PATH")" == '0:0:644' ]]
[[ "$(stat -c '%u:%g:%a' "$POLICY_PATH")" == '0:0:644' ]]
[[ "$(stat -c '%u:%g:%a' "$SUDOERS_PATH")" == '0:0:440' ]]
[[ "$(sha256sum "$HELPER_PATH" | awk '{print $1}')" == "$EXPECTED_HELPER_DIGEST" ]]
[[ "$(sha256sum "$SANITIZER_PATH" | awk '{print $1}')" == "$EXPECTED_SANITIZER_DIGEST" ]]
[[ "$(sha256sum "$POLICY_PATH" | awk '{print $1}')" == "$EXPECTED_POLICY_DIGEST" ]]
visudo -cf "$SUDOERS_PATH" >/dev/null

precheck="$(runuser -u "$deploy_user" -- sudo -n -- "$HELPER_PATH" PRECHECK "$REQUEST_ID" 0)"
grep -Fxq 'staging_admin_capability=PASS' <<< "$precheck"
grep -Fxq 'staging_db_present=NO' <<< "$precheck"
absence="$(runuser -u "$deploy_user" -- sudo -n -- "$HELPER_PATH" VERIFY_ABSENCE "$REQUEST_ID" 0)"
grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "$absence"
grep -Fxq 'staging_account_present_after_cleanup=NO' <<< "$absence"

committed=1
printf '%s\n' \
  'helper_installed=PASS' \
  'sanitizer_installed=PASS' \
  'policy_installed=PASS' \
  'bundle_digest=PASS' \
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
