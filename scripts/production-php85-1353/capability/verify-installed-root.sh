#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

EXPECTED_USER="${1:-}"
EXPECTED_HELPER_SHA256="${2:-}"
EXPECTED_PLAN_SHA256="${3:-}"
EXPECTED_SUDOERS_SHA256="${4:-}"

HELPER='/usr/local/sbin/agency-prod-php85-1353-apply'
LIB_DIR='/usr/local/lib/agency-prod-php85-1353'
PLAN_SCRIPT="$LIB_DIR/remote-plan.sh"
SUDOERS='/etc/sudoers.d/agency-prod-php85-1353'

[[ "$#" -eq 4 ]]
[[ "$(id -u)" -eq 0 ]]
[[ "$EXPECTED_USER" =~ ^[A-Za-z0-9._-]+$ ]]
[[ "$EXPECTED_HELPER_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$EXPECTED_PLAN_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$EXPECTED_SUDOERS_SHA256" =~ ^[0-9a-f]{64}$ ]]

[[ -d "$LIB_DIR" && ! -L "$LIB_DIR" ]]
[[ -f "$HELPER" && ! -L "$HELPER" ]]
[[ -f "$PLAN_SCRIPT" && ! -L "$PLAN_SCRIPT" ]]
[[ -f "$SUDOERS" && ! -L "$SUDOERS" ]]
[[ "$(stat -c '%U:%G:%a' "$LIB_DIR")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$HELPER")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$PLAN_SCRIPT")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$SUDOERS")" == 'root:root:440' ]]
[[ "$(sha256sum "$HELPER" | awk '{print $1}')" == "$EXPECTED_HELPER_SHA256" ]]
[[ "$(sha256sum "$PLAN_SCRIPT" | awk '{print $1}')" == "$EXPECTED_PLAN_SHA256" ]]
[[ "$(sha256sum "$SUDOERS" | awk '{print $1}')" == "$EXPECTED_SUDOERS_SHA256" ]]

mapfile -t sudoers_lines < "$SUDOERS"
[[ "${#sudoers_lines[@]}" -eq 2 ]]
[[ "${sudoers_lines[0]}" == "$EXPECTED_USER ALL=(root) NOPASSWD: NOSETENV: $HELPER CHECK" ]]
[[ "${sudoers_lines[1]}" == "$EXPECTED_USER ALL=(root) NOPASSWD: NOSETENV: $HELPER APPLY" ]]
/usr/sbin/visudo -cf "$SUDOERS" >/dev/null

check_output="$(runuser -u "$EXPECTED_USER" -- sudo -k -n -- "$HELPER" CHECK)"
mapfile -t check_lines <<< "$check_output"
[[ "${#check_lines[@]}" -eq 3 ]]
[[ "${check_lines[0]}" == 'STATUS=PASS' ]]
[[ "${check_lines[1]}" == 'CAPABILITY_READY=YES' ]]
[[ "${check_lines[2]}" == 'CAPABILITY_SCOPE=FIXED_PURPOSE_ONLY' ]]

printf '%s\n' \
  'STATUS=PASS' \
  'CAPABILITY_READY=YES' \
  'HELPER_METADATA=root:root:755' \
  'PLAN_SCRIPT_METADATA=root:root:755' \
  'SUDOERS_METADATA=root:root:440' \
  'SUDOERS_SCOPE=FIXED_HELPER_CHECK_APPLY_ONLY' \
  'VISUDO_POST_INSTALL=PASS' \
  'GENERIC_ROOT_CAPABILITY=NONE'
