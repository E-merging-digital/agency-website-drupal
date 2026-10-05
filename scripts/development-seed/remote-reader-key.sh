#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ACTION="${1:-}"
REQUEST_ID="${2:-}"
KEY_BLOB="${3:-}"
EXPECTED_READER_SHA="${4:-}"
[[ "$#" -eq 4 ]]
[[ "$ACTION" =~ ^(INSTALL|REMOVE|VERIFY)$ ]]
[[ "$REQUEST_ID" =~ ^seed-956-[A-Za-z0-9._-]{8,40}-r1$ ]]
[[ "$KEY_BLOB" =~ ^[A-Za-z0-9+/=]{32,}$ ]]
[[ "$EXPECTED_READER_SHA" =~ ^[0-9a-f]{64}$ ]]
[[ "$(id -un)" == 'agency-preprod' ]]

ROOT='/var/www/agency-preprod/shared/development-seeds'
READER="$ROOT/read-only-scp.sh"
AUTHORIZED_KEYS='/home/agency-preprod/.ssh/authorized_keys'
COMMENT="agency-development-seed-$REQUEST_ID"
LINE="restrict,command=\"$READER\" ssh-ed25519 $KEY_BLOB $COMMENT"

fail() {
  printf 'Development Seed reader identity rejected: %s\n' "$1" >&2
  exit 80
}

[[ -f "$READER" && ! -L "$READER" && -x "$READER" ]] || fail 'fixed reader command is unavailable'
[[ "$(sha256sum "$READER" | awk '{print $1}')" == "$EXPECTED_READER_SHA" ]] || fail 'fixed reader command digest mismatch'
[[ -f "$AUTHORIZED_KEYS" && ! -L "$AUTHORIZED_KEYS" ]] || fail 'authorized_keys is unavailable or unsafe'
[[ "$(stat -c '%U:%G:%a' "$AUTHORIZED_KEYS")" == 'agency-preprod:agency-preprod:600' ]] || fail 'authorized_keys ownership/mode mismatch'

count_line() {
  grep -Fxc -- "$LINE" "$AUTHORIZED_KEYS" || true
}
count_comment() {
  grep -Fc -- "$COMMENT" "$AUTHORIZED_KEYS" || true
}

case "$ACTION" in
  INSTALL)
    [[ "$(count_comment)" -eq 0 ]] || fail 'request-scoped reader identity already exists'
    printf '%s\n' "$LINE" >> "$AUTHORIZED_KEYS"
    chmod 600 "$AUTHORIZED_KEYS"
    [[ "$(count_line)" -eq 1 ]] || fail 'restricted reader key installation failed'
    printf '%s\n' \
      'reader_identity=EPHEMERAL_RESTRICTED_KEY' \
      'reader_forced_command=PASS' \
      'reader_seed_write=NONE' \
      'reader_general_shell=NONE' \
      'reader_port_forwarding=NONE' \
      'reader_pty=NONE'
    ;;

  REMOVE)
    tmp="${AUTHORIZED_KEYS}.seed-$REQUEST_ID.tmp"
    grep -Fvx -- "$LINE" "$AUTHORIZED_KEYS" > "$tmp" || true
    chmod 600 "$tmp"
    mv -f -- "$tmp" "$AUTHORIZED_KEYS"
    chmod 600 "$AUTHORIZED_KEYS"
    [[ "$(count_comment)" -eq 0 ]] || fail 'restricted reader key cleanup failed'
    printf '%s\n' 'temporary_reader_identity=ABSENT'
    ;;

  VERIFY)
    [[ "$(count_line)" -eq 1 ]] || fail 'restricted reader key is not exactly installed'
    printf '%s\n' \
      'reader_identity=EPHEMERAL_RESTRICTED_KEY' \
      'reader_forced_command=PASS' \
      'reader_read_scope=DEVELOPMENT_SEED_ONLY'
    ;;
esac
