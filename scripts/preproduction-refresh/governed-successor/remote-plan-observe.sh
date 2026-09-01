#!/usr/bin/env bash
set -Eeuo pipefail
export LC_ALL=C

fail_reason() {
  printf '%s\n' \
    'PLAN_OBSERVER_RESULT=FAIL_CLOSED' \
    "PLAN_REASON=$1"
  exit 1
}

[[ "$#" -eq 0 ]] || fail_reason 'PREPROD_OBSERVER_CONTEXT'
[[ "$(id -u)" -ne 0 ]] || fail_reason 'PREPROD_OBSERVER_CONTEXT'

ROOT=/var/www/agency-preprod
CURRENT="$ROOT/current"
SHARED="$ROOT/shared"
DRUSH="$CURRENT/vendor/bin/drush"

[[ -L "$CURRENT" ]] || fail_reason 'PREPROD_CURRENT_RELEASE'
[[ -x "$DRUSH" ]] || fail_reason 'PREPROD_DRUSH_EXECUTABLE'
[[ -d "$SHARED/backups" && -w "$SHARED/backups" ]] \
  || fail_reason 'PREPROD_BACKUP_PATH'
[[ -f "$SHARED/settings/runtime.env" && ! -L "$SHARED/settings/runtime.env" && -r "$SHARED/settings/runtime.env" ]] \
  || fail_reason 'PREPROD_RUNTIME_ENV'
[[ -x "$CURRENT/scripts/preproduction/validate-runtime.sh" ]] \
  || fail_reason 'PREPROD_RUNTIME_VALIDATOR'
[[ -f "$CURRENT/scripts/preproduction-refresh/activation-capability/admin-reconcile.php" ]] \
  || fail_reason 'PREPROD_ADMIN_RECONCILER'
[[ -d "$CURRENT/config/splits/preproduction" ]] \
  || fail_reason 'PREPROD_CONFIG_SPLIT'

for cmd in sql:dump sql:cli sql:drop sql:sanitize maint:set updb cim cr; do
  "$DRUSH" help "$cmd" >/dev/null 2>&1 \
    || fail_reason 'PREPROD_DRUSH_COMMAND_SET'
done

printf '%s\n' \
  'PREPROD_STANDARD_DRUSH=PASS' \
  'PREPROD_BACKUP_PATH=READY' \
  'PREPROD_RUNTIME_ENV=SERVER_OWNED_PRESENT' \
  'PREPROD_CONFIG_SPLIT=READY' \
  'PREPROD_ADMIN_RECONCILER=READY' \
  "DEPLOY_LOCK_PRESENT=$([[ -e "$SHARED/deploy.lock" ]] && echo YES || echo NO)" \
  'PLAN_MUTATION=NONE' \
  'PREPROD_DB_MUTATION=NONE' \
  'DATA_ACTIVATION_AUTHORITY=DISABLED'
