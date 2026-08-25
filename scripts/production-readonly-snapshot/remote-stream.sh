#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ "$#" -ne 1 ]]; then
  echo 'Expected exactly one immutable PROD release SHA.' >&2
  exit 64
fi

EXPECTED_PROD_RELEASE_SHA="$1"
CURRENT_RELEASE='/var/www/agency/current'

if [[ ! "$EXPECTED_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo 'Expected PROD release SHA is invalid.' >&2
  exit 65
fi

if [[ ! -d "$CURRENT_RELEASE" ]]; then
  echo 'Current PROD release is unavailable.' >&2
  exit 66
fi

if [[ ! -x "$CURRENT_RELEASE/vendor/bin/drush" ]]; then
  echo 'Current PROD release does not contain executable Drush.' >&2
  exit 67
fi

ACTUAL_PROD_RELEASE_SHA="$(git -C "$CURRENT_RELEASE" rev-parse HEAD)"
if [[ "$ACTUAL_PROD_RELEASE_SHA" != "$EXPECTED_PROD_RELEASE_SHA" ]]; then
  echo 'Current PROD release identity does not match authorization.' >&2
  exit 68
fi

cd "$CURRENT_RELEASE"

# Bootstrap and connection discovery are read-only. Never print the generated
# connection command because it contains server-owned database credentials.
vendor/bin/drush status --fields=bootstrap --format=string >/dev/null
connection_command="$(vendor/bin/drush sql:connect --show-passwords)"
if [[ -z "$connection_command" ]]; then
  echo 'Unable to resolve the server-owned PROD database connection.' >&2
  exit 69
fi

# Drush generates this command exclusively from trusted server-owned settings.
# No user-controlled value is ever inserted into it. Decode its quoting into an
# argv array, validate the SQL client shape, then replace only the executable
# with the fixed read-only dump client.
eval "set -- ${connection_command}"
unset connection_command

if [[ "$#" -lt 2 ]]; then
  echo 'Resolved database connection is incomplete.' >&2
  exit 70
fi

connect_client="$(basename "$1")"
shift
case "$connect_client" in
  mysql|mariadb) ;;
  *)
    echo 'Resolved database connection is not a supported MariaDB/MySQL client.' >&2
    exit 71
    ;;
esac

for connection_arg in "$@"; do
  case "$connection_arg" in
    -e|--execute|--execute=*|--init-command|--init-command=*|--local-infile|--local-infile=*)
      echo 'Resolved database connection unexpectedly contains an executable SQL option.' >&2
      exit 72
      ;;
  esac
done

if command -v mariadb-dump >/dev/null 2>&1; then
  dump_client="$(command -v mariadb-dump)"
elif command -v mysqldump >/dev/null 2>&1; then
  dump_client="$(command -v mysqldump)"
else
  echo 'No supported database dump client is available on PROD.' >&2
  exit 73
fi

# Raw SQL is emitted only on stdout so the trusted Agency runner can capture it
# directly in its private RUNNER_TEMP. No raw dump file is created on PROD.
exec "$dump_client" \
  --single-transaction \
  --quick \
  --skip-lock-tables \
  --no-tablespaces \
  "$@"
