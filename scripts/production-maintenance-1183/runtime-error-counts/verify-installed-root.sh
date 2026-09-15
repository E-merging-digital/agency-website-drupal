#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

EXPECTED_USER="${1:-}"
HELPER_PATH='/usr/local/sbin/agency-prod-runtime-error-counts'
SUDOERS_PATH='/etc/sudoers.d/agency-prod-runtime-error-counts'

[[ "$#" -eq 1 ]]
[[ "$EXPECTED_USER" =~ ^[A-Za-z0-9._-]+$ ]]
[[ "$(id -u)" -eq 0 ]]
[[ -f "$HELPER_PATH" && ! -L "$HELPER_PATH" ]]
[[ -f "$SUDOERS_PATH" && ! -L "$SUDOERS_PATH" ]]
[[ "$(stat -c '%U:%G:%a' "$HELPER_PATH")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$SUDOERS_PATH")" == 'root:root:440' ]]
[[ ! -w "$HELPER_PATH" || "$(id -u)" -eq 0 ]]

mapfile -t sudoers_lines < "$SUDOERS_PATH"
[[ "${#sudoers_lines[@]}" -eq 1 ]]
expected_rule="$EXPECTED_USER ALL=(root) NOPASSWD: NOSETENV: $HELPER_PATH"
[[ "${sudoers_lines[0]}" == "$expected_rule" ]]
/usr/sbin/visudo -cf "$SUDOERS_PATH" >/dev/null

printf '%s\n' \
  'HELPER_OWNER=root' \
  'HELPER_GROUP=root' \
  'HELPER_MODE=0755' \
  'HELPER_NON_ROOT_WRITABLE=NO' \
  'SUDOERS_OWNER=root' \
  'SUDOERS_GROUP=root' \
  'SUDOERS_MODE=0440' \
  'SUDOERS_SCOPE=FIXED_HELPER_ONLY' \
  'VISUDO_CF=PASS'
