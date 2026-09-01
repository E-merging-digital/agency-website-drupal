#!/usr/bin/env bash
set -Eeuo pipefail
export LC_ALL=C
[[ "$#" -eq 0 ]] || exit 64
[[ "$(id -u)" -ne 0 ]] || exit 65

ROOT=/var/www/agency-preprod
CURRENT="$ROOT/current"
SHARED="$ROOT/shared"
DRUSH="$CURRENT/vendor/bin/drush"

[[ -L "$CURRENT" && -x "$DRUSH" ]]
[[ -d "$SHARED/backups" && -w "$SHARED/backups" ]]
[[ -f "$SHARED/settings/runtime.env" && ! -L "$SHARED/settings/runtime.env" && -r "$SHARED/settings/runtime.env" ]]
[[ -x "$CURRENT/scripts/preproduction/validate-runtime.sh" ]]
[[ -f "$CURRENT/scripts/preproduction-refresh/activation-capability/admin-reconcile.php" ]]
[[ -d "$CURRENT/config/splits/preproduction" ]]
for cmd in sql:dump sql:cli sql:drop sql:sanitize maint:set updb cim cr; do
  "$DRUSH" list --format=list | grep -Fxq "$cmd"
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
