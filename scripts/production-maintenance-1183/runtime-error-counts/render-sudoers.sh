#!/usr/bin/env bash
set -Eeuo pipefail

SERVER_USER="${1:-}"
TEMPLATE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/agency-prod-runtime-error-counts.sudoers.template"
HELPER_PATH='/usr/local/sbin/agency-prod-runtime-error-counts'

[[ "$#" -eq 1 ]]
[[ "$SERVER_USER" =~ ^[A-Za-z0-9._-]+$ ]]
[[ -f "$TEMPLATE" && ! -L "$TEMPLATE" ]]
expected='__SERVER_USER__ ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-runtime-error-counts'
[[ "$(tr -d '\r\n' < "$TEMPLATE")" == "$expected" ]]
printf '%s ALL=(root) NOPASSWD: NOSETENV: %s\n' "$SERVER_USER" "$HELPER_PATH"
