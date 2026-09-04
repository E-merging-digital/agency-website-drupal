#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ROOT='/var/www/agency-preprod/shared/development-seeds'
CURRENT="$ROOT/current"
ORIGINAL="${SSH_ORIGINAL_COMMAND:-}"

fail() {
  printf '%s\n' 'Development Seed reader command rejected.' >&2
  exit 80
}

[[ "$(id -un)" == 'agency-preprod' ]] || fail
[[ -L "$CURRENT" ]] || fail
resolved="$(readlink -f "$CURRENT")"
case "$resolved" in
  "$ROOT"/immutable/agency-development-seed-v1-*) ;;
  *) fail ;;
esac
[[ -d "$resolved" && ! -L "$resolved" ]] || fail

# Legacy SCP protocol is deliberately used so OpenSSH's authorized_keys forced
# command can permit only server-side file reads. No shell, SFTP or upload mode.
read -r -a argv <<< "$ORIGINAL"
path=''
if [[ "${#argv[@]}" -eq 3 && "${argv[0]}" == scp && "${argv[1]}" == -f ]]; then
  path="${argv[2]}"
elif [[ "${#argv[@]}" -eq 4 && "${argv[0]}" == scp && "${argv[1]}" == -f && "${argv[2]}" == -- ]]; then
  path="${argv[3]}"
else
  fail
fi

case "$path" in
  "$CURRENT/seed.json") fixed="$resolved/seed.json" ;;
  "$CURRENT/database.sql.gz") fixed="$resolved/database.sql.gz" ;;
  *) fail ;;
esac
[[ -f "$fixed" && ! -L "$fixed" && -r "$fixed" ]] || fail

exec /usr/bin/scp -f "$fixed"
