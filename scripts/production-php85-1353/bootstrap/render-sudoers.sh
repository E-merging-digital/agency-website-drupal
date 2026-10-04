#!/usr/bin/env bash
set -Eeuo pipefail

SERVER_USER="${1:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE="$SCRIPT_DIR/agency-prod-php85-1353.sudoers.template"
HELPER='/usr/local/sbin/agency-prod-php85-1353-apply'

[[ "$#" -eq 1 ]]
[[ "$SERVER_USER" =~ ^[A-Za-z0-9._-]+$ ]]
[[ -f "$TEMPLATE" && ! -L "$TEMPLATE" ]]

mapfile -t lines < "$TEMPLATE"
[[ "${#lines[@]}" -eq 2 ]]
[[ "${lines[0]}" == '__SERVER_USER__ ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-php85-1353-apply READY' ]]
[[ "${lines[1]}" == '__SERVER_USER__ ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-php85-1353-apply APPLY' ]]

printf '%s ALL=(root) NOPASSWD: NOSETENV: %s READY\n' "$SERVER_USER" "$HELPER"
printf '%s ALL=(root) NOPASSWD: NOSETENV: %s APPLY\n' "$SERVER_USER" "$HELPER"
