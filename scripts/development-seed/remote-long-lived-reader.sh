#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ACTION="${1:-}"
LABEL="${2:-}"
KEY_BLOB="${3:-}"
EXPECTED_READER_SHA="${4:-}"
[[ "$#" -eq 4 ]]
[[ "$ACTION" =~ ^(INSTALL|REMOVE|VERIFY)$ ]]
[[ "$LABEL" =~ ^[A-Za-z0-9._-]{3,40}$ ]]
[[ "$KEY_BLOB" =~ ^[A-Za-z0-9+/=]{32,}$ ]]
[[ "$EXPECTED_READER_SHA" =~ ^[0-9a-f]{64}$ ]]
[[ "$(id -un)" == 'agency-preprod' ]]

ROOT='/var/www/agency-preprod/shared/development-seeds'
READER="$ROOT/read-only-scp.sh"
AUTHORIZED_KEYS='/home/agency-preprod/.ssh/authorized_keys'
COMMENT="agency-development-seed-reader-$LABEL"
LINE="restrict,command=\"$READER\" ssh-ed25519 $KEY_BLOB $COMMENT"
LOCK='/home/agency-preprod/.ssh/development-seed-reader.lock'

fail() {
  printf 'Development Seed long-lived reader rejected: %s\n' "$1" >&2
  exit 80
}

[[ -f "$READER" && ! -L "$READER" && -x "$READER" ]] || fail 'fixed reader command is unavailable'
[[ "$(sha256sum "$READER" | awk '{print $1}')" == "$EXPECTED_READER_SHA" ]] || fail 'fixed reader command digest mismatch'
[[ -f "$AUTHORIZED_KEYS" && ! -L "$AUTHORIZED_KEYS" ]] || fail 'authorized_keys is unavailable or unsafe'
[[ "$(stat -c '%U:%G:%a' "$AUTHORIZED_KEYS")" == 'agency-preprod:agency-preprod:600' ]] || fail 'authorized_keys ownership/mode mismatch'

exec 9>"$LOCK"
chmod 600 "$LOCK"
flock -x 9

count_line() { grep -Fxc -- "$LINE" "$AUTHORIZED_KEYS" || true; }
count_comment() { grep -Fc -- "$COMMENT" "$AUTHORIZED_KEYS" || true; }

case "$ACTION" in
  INSTALL)
    if [[ "$(count_line)" -eq 1 && "$(count_comment)" -eq 1 ]]; then
      printf '%s\n' 'reader_install=ALREADY_PRESENT'
    else
      [[ "$(count_comment)" -eq 0 ]] || fail 'reader label already belongs to another key'
      printf '%s\n' "$LINE" >> "$AUTHORIZED_KEYS"
      chmod 600 "$AUTHORIZED_KEYS"
      [[ "$(count_line)" -eq 1 && "$(count_comment)" -eq 1 ]] || fail 'reader installation failed'
      printf '%s\n' 'reader_install=PASS'
    fi
    ;;
  REMOVE)
    tmp="${AUTHORIZED_KEYS}.reader-$LABEL.tmp"
    grep -Fvx -- "$LINE" "$AUTHORIZED_KEYS" > "$tmp" || true
    chmod 600 "$tmp"
    mv -f -- "$tmp" "$AUTHORIZED_KEYS"
    chmod 600 "$AUTHORIZED_KEYS"
    [[ "$(count_comment)" -eq 0 ]] || fail 'reader removal failed or label mismatch'
    printf '%s\n' 'reader_remove=PASS'
    ;;
  VERIFY)
    [[ "$(count_line)" -eq 1 && "$(count_comment)" -eq 1 ]] || fail 'reader is not exactly installed'
    printf '%s\n' 'reader_verify=PASS'
    ;;
esac

printf '%s\n' \
  'reader_forced_command=PASS' \
  'reader_scope=DEVELOPMENT_SEED_ONLY' \
  'reader_seed_write=NONE' \
  'reader_general_shell=NONE' \
  'reader_port_forwarding=NONE' \
  'reader_pty=NONE'
