#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REQUEST_ID="${1:-}"
EXPECTED_MAIN="${2:-}"
EXPECTED_SQL_SHA="${3:-}"
[[ "$#" -eq 3 ]]
[[ "$REQUEST_ID" =~ ^apply-[0-9]+-[A-Za-z0-9._-]{8,40}-r1$ ]]
[[ "$EXPECTED_MAIN" =~ ^[0-9a-f]{40}$ ]]
[[ "$EXPECTED_SQL_SHA" =~ ^[0-9a-f]{64}$ ]]

ROOT=/var/www/agency-preprod
CURRENT="$ROOT/current"
SHARED="$ROOT/shared"
JOB="$SHARED/refresh-jobs/$REQUEST_ID"
SQL="$JOB/sanitized.sql"
RESULT="$JOB/result.env"
OUTPUT="$JOB/worker.log"
LOCK="$SHARED/deploy.lock"
RUNTIME_ENV="$SHARED/settings/runtime.env"
BACKUP="$SHARED/backups/refresh-${REQUEST_ID}.sql"
DRUSH="$CURRENT/vendor/bin/drush"
VALIDATE="$CURRENT/scripts/preproduction/validate-runtime.sh"
ADMIN="$CURRENT/scripts/preproduction-refresh/activation-capability/admin-reconcile.php"
MAINT=0
DESTRUCTIVE=0
TERMINAL=0

write_result() {
  local outcome="$1" detail="$2" tmp="$RESULT.tmp.$$"
  {
    printf 'schema_version=1\nrequest_id=%s\nmain_sha=%s\n' "$REQUEST_ID" "$EXPECTED_MAIN"
    printf 'outcome=%s\ndetail=%s\n' "$outcome" "$detail"
    printf 'finished_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  } > "$tmp"
  chmod 600 "$tmp"
  mv -f "$tmp" "$RESULT"
  TERMINAL=1
}

validate_runtime() {
  "$DRUSH" status --field=bootstrap 2>/dev/null | grep -q Successful
  "$VALIDATE" >/dev/null
}

rollback() {
  set +e
  if (( DESTRUCTIVE == 0 )); then
    rm -f -- "$SQL"
    write_result ROLLED_BACK NO_PREPROD_RUNTIME_MUTATION
    exit 1
  fi
  local ok=1
  "$DRUSH" sql:drop -y >/dev/null 2>&1 || ok=0
  "$DRUSH" sql:cli < "$BACKUP" >/dev/null 2>&1 || ok=0
  "$DRUSH" cr >/dev/null 2>&1 || ok=0
  validate_runtime >/dev/null 2>&1 || ok=0
  if (( ok == 1 )); then
    "$DRUSH" maint:set 0 -y >/dev/null 2>&1 || ok=0
    MAINT=0
  fi
  rm -f -- "$SQL"
  if (( ok == 1 )); then
    write_result ROLLED_BACK EXACT_BACKUP_OR_UNCHANGED_RUNTIME_PROVEN
    exit 1
  fi
  # Fail closed: do not reopen maintenance when rollback is unproven.
  write_result HUMAN_RECOVERY_REQUIRED ROLLBACK_NOT_PROVEN_MAINTENANCE_REMAINS_ON
  exit 90
}

on_exit() {
  local status=$?
  trap - EXIT HUP INT TERM
  (( TERMINAL == 1 )) && exit "$status"
  rollback
}
trap on_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

[[ -L "$CURRENT" && -x "$DRUSH" && -x "$VALIDATE" && -f "$ADMIN" ]]
[[ -f "$RUNTIME_ENV" && ! -L "$RUNTIME_ENV" && -r "$RUNTIME_ENV" ]]
[[ -d "$JOB" && ! -L "$JOB" && -f "$SQL" && ! -L "$SQL" ]]
[[ "$(stat -c '%a' "$SQL")" == 600 ]]
[[ "$(sha256sum "$SQL" | awk '{print $1}')" == "$EXPECTED_SQL_SHA" ]]
[[ ! -e "$RESULT" ]]

exec 9>"$LOCK"
if ! flock -n 9; then
  write_result ROLLED_BACK NO_MUTATION_DEPLOY_LOCK_BUSY
  exit 75
fi

# Backup is complete and verified before maintenance/destructive replacement.
rm -f -- "$BACKUP"
"$DRUSH" sql:dump --no-interaction --result-file="$BACKUP" >/dev/null
chmod 600 "$BACKUP"
[[ -s "$BACKUP" && -f "$BACKUP" && ! -L "$BACKUP" ]]
backup_sha="$(sha256sum "$BACKUP" | awk '{print $1}')"
[[ "$backup_sha" =~ ^[0-9a-f]{64}$ ]]

"$DRUSH" maint:set 1 -y >/dev/null
MAINT=1
DESTRUCTIVE=1
"$DRUSH" sql:drop -y >/dev/null
"$DRUSH" sql:cli < "$SQL" >/dev/null
"$DRUSH" updb -y >/dev/null
"$DRUSH" cim -y >/dev/null
[[ -d "$CURRENT/config/splits/preproduction" ]]
"$DRUSH" config:import --source="$CURRENT/config/splits/preproduction" --partial -y >/dev/null

# Server-owned PREPROD credential is consumed only on PREPROD and never printed.
# shellcheck disable=SC1090
source "$RUNTIME_ENV"
[[ -n "${DRUPAL_ADMIN_PASSWORD:-}" ]]
export DRUPAL_ADMIN_PASSWORD
"$DRUSH" --quiet php:script "$ADMIN" >/dev/null 2>&1
unset DRUPAL_ADMIN_PASSWORD

"$DRUSH" cr >/dev/null
validate_runtime
"$DRUSH" maint:set 0 -y >/dev/null
MAINT=0
rm -f -- "$SQL"
write_result COMMITTED SANITIZED_DATABASE_ACTIVE_AND_VALIDATED
trap - EXIT HUP INT TERM
printf '%s\n' 'PREPROD_REFRESH_WORKER=COMMITTED'
