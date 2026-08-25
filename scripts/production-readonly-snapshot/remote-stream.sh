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

# Both commands derive the database connection exclusively from trusted
# server-owned Drupal settings. No connection string, SQL, table, database,
# file path or dump option can be supplied by the request authority.
vendor/bin/drush status --fields=bootstrap --format=string >/dev/null

# Drush sql:dump invokes the database-specific logical dump client. The fixed
# options provide a streaming, non-locking transactional snapshot for the
# transactional tables used by Agency. No --result-file is provided: raw SQL is
# emitted only to stdout and captured directly in trusted RUNNER_TEMP.
exec vendor/bin/drush sql:dump \
  --no-interaction \
  --extra-dump='--single-transaction --quick --skip-lock-tables --no-tablespaces'
