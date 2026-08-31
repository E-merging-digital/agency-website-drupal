#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

CURRENT='/var/www/agency-preprod/current'
RUNTIME_ENV='/var/www/agency-preprod/shared/settings/runtime.env'
DRUSH="$CURRENT/vendor/bin/drush"
SCRIPT='/usr/local/lib/agency-preprod-refresh/admin-reconcile.php'

fail() { printf '%s\n' 'PREPROD_ADMIN_RECONCILIATION=FAIL_CLOSED' >&2; exit 80; }

[[ "$(id -u)" -eq 0 ]] || fail
[[ "$#" -eq 0 ]] || fail
[[ -L "$CURRENT" ]] || fail
[[ -f "$RUNTIME_ENV" && ! -L "$RUNTIME_ENV" ]] || fail
[[ -x "$DRUSH" && -f "$SCRIPT" && ! -L "$SCRIPT" ]] || fail
# runtime.env is server-owned PREPROD state.  It was historically created 0600;
# accept only a single-link regular file with no group/other permissions.
mode="$(stat -c '%a' "$RUNTIME_ENV")"
links="$(stat -c '%h' "$RUNTIME_ENV")"
[[ "$links" == '1' ]] || fail
[[ "$mode" == '600' ]] || fail

# shellcheck disable=SC1090
source "$RUNTIME_ENV"
[[ -n "${DRUPAL_ADMIN_PASSWORD:-}" ]] || fail

# Never put the secret in argv/stdout/stderr.  The fixed PHP reconciliation
# script receives it only through this root-owned process environment.
export DRUPAL_ADMIN_PASSWORD
cd "$CURRENT"
"$DRUSH" --quiet php:script "$SCRIPT" >/dev/null 2>/dev/null
unset DRUPAL_ADMIN_PASSWORD
printf '%s\n' \
  'PREPROD_ADMIN_RECONCILIATION=PASS' \
  'PREPROD_ADMIN_IDENTITY=preprod-admin' \
  'PREPROD_ADMIN_SECRET_SOURCE=SERVER_OWNED_RUNTIME_ENV' \
  'PROD_CREDENTIAL_REUSE=NONE' \
  'ADMIN_SECRET_LOGGING=NONE'
