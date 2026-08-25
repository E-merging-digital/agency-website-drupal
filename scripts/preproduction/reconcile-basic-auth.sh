#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

HTPASSWD_FILE="/etc/nginx/agency-preprod.htpasswd"
TMP_FILE=""

cleanup() {
  if [[ -n "$TMP_FILE" && -f "$TMP_FILE" ]]; then
    rm -f "$TMP_FILE"
  fi
}
trap cleanup EXIT

fail() {
  printf 'schema_version=1\n'
  printf 'basic_auth_credentials=FAIL\n'
  printf 'basic_auth_reconciled=NO\n'
  printf 'basic_auth_failure=%s\n' "$1"
  exit 1
}

read -r basic_user_b64 || fail 'MISSING_USERNAME_INPUT'
read -r basic_password_b64 || fail 'MISSING_PASSWORD_INPUT'

BASIC_USER="$(printf '%s' "$basic_user_b64" | base64 --decode)" || \
  fail 'INVALID_USERNAME_INPUT'
BASIC_PASSWORD="$(printf '%s' "$basic_password_b64" | base64 --decode)" || \
  fail 'INVALID_PASSWORD_INPUT'
unset basic_user_b64 basic_password_b64

[[ -n "$BASIC_USER" ]] || fail 'EMPTY_USERNAME'
[[ -n "$BASIC_PASSWORD" ]] || fail 'EMPTY_PASSWORD'
[[ -f "$HTPASSWD_FILE" ]] || fail 'HTPASSWD_MISSING'
[[ -r "$HTPASSWD_FILE" ]] || fail 'HTPASSWD_UNREADABLE'
command -v htpasswd >/dev/null 2>&1 || fail 'HTPASSWD_TOOL_MISSING'

verify_credentials() {
  printf '%s\n' "$BASIC_PASSWORD" | \
    htpasswd -vi "$HTPASSWD_FILE" "$BASIC_USER" >/dev/null 2>&1
}

if cut -d: -f1 "$HTPASSWD_FILE" | grep -Fxq "$BASIC_USER"; then
  username_match='PASS'
else
  username_match='FAIL'
fi

if verify_credentials; then
  printf 'schema_version=1\n'
  printf 'basic_auth_username_match=%s\n' "$username_match"
  printf 'basic_auth_credentials=PASS\n'
  printf 'basic_auth_reconciled=NO\n'
  printf 'basic_auth_reconciliation_method=NOT_REQUIRED\n'
  exit 0
fi

TMP_FILE="$(mktemp /tmp/agency-preprod-htpasswd.XXXXXX)"
printf '%s\n' "$BASIC_PASSWORD" | \
  htpasswd -B -i -c "$TMP_FILE" "$BASIC_USER" >/dev/null 2>&1
chmod 600 "$TMP_FILE"

reconciliation_method=''
if [[ -w "$HTPASSWD_FILE" ]] && cat "$TMP_FILE" > "$HTPASSWD_FILE"; then
  reconciliation_method='DIRECT_WRITE'
elif command -v sudo >/dev/null 2>&1 && \
  sudo -n install -o root -g www-data -m 640 \
    "$TMP_FILE" "$HTPASSWD_FILE" >/dev/null 2>&1; then
  reconciliation_method='SUDO_INSTALL'
else
  printf 'schema_version=1\n'
  printf 'basic_auth_username_match=%s\n' "$username_match"
  printf 'basic_auth_credentials=FAIL\n'
  printf 'basic_auth_reconciled=NO\n'
  printf 'basic_auth_reconciliation_method=NO_PRIVILEGED_WRITE\n'
  exit 42
fi

verify_credentials || fail 'RECONCILIATION_VERIFY_FAILED'

printf 'schema_version=1\n'
printf 'basic_auth_username_match=%s\n' "$username_match"
printf 'basic_auth_credentials=PASS\n'
printf 'basic_auth_reconciled=YES\n'
printf 'basic_auth_reconciliation_method=%s\n' "$reconciliation_method"
