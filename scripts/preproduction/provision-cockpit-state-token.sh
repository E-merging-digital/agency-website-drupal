#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

TOKEN_DIR="/etc/agency-preprod"
TOKEN_FILE="$TOKEN_DIR/cockpit-state-token"
TOKEN_GROUP="www-data"
TOKEN_TMP=""

log() {
  printf '[cockpit-token] %s\n' "$1"
}

fail() {
  printf '[cockpit-token] ERROR: %s\n' "$1" >&2
  exit 1
}

cleanup() {
  if [[ -n "$TOKEN_TMP" && -f "$TOKEN_TMP" ]]; then
    rm -f "$TOKEN_TMP"
  fi
}
trap cleanup EXIT

[[ "$(id -u)" -eq 0 ]] || fail "Run as root."
[[ "$#" -eq 0 ]] || fail "Pass the bearer through stdin/prompt, never as an argument."
getent group "$TOKEN_GROUP" >/dev/null 2>&1 || fail "Required group $TOKEN_GROUP is missing."

install -d -m 750 -o root -g "$TOKEN_GROUP" "$TOKEN_DIR"
[[ ! -L "$TOKEN_DIR" ]] || fail "Token directory must not be a symlink."
[[ ! -L "$TOKEN_FILE" ]] || fail "Token file must not be a symlink."

if [[ -t 0 ]]; then
  IFS= read -r -s -p 'Cockpit bearer (minimum 32 characters): ' token
  printf '\n' >&2
else
  IFS= read -r token || fail "Bearer input is missing."
fi

token="${token%$'\r'}"
[[ ${#token} -ge 32 ]] || fail "Bearer must contain at least 32 characters."
[[ "$token" != *[[:space:]]* ]] || fail "Bearer must not contain whitespace."

TOKEN_TMP="$(mktemp "$TOKEN_DIR/.cockpit-state-token.XXXXXX")"
printf '%s\n' "$token" > "$TOKEN_TMP"
unset token
chown root:"$TOKEN_GROUP" "$TOKEN_TMP"
chmod 640 "$TOKEN_TMP"
mv -f "$TOKEN_TMP" "$TOKEN_FILE"
TOKEN_TMP=""

[[ "$(stat -c '%U:%G:%a' "$TOKEN_FILE")" == "root:$TOKEN_GROUP:640" ]] || \
  fail "Token file ownership/mode contract mismatch."

log "Runtime bearer materialized at the server-owned path."
log "No secret value was printed or stored in Git."
