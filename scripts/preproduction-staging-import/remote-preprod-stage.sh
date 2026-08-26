#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ACTION="${1:-}"
REQUEST_ID="${2:-}"
SNAPSHOT_BYTES="${3:-}"
if [[ "$#" -ne 3 ]] || [[ "$ACTION" != 'PRECHECK' && "$ACTION" != 'IMPORT' && "$ACTION" != 'CLEANUP' && "$ACTION" != 'VERIFY_ABSENCE' ]]; then
  echo 'Invalid fixed staging action.' >&2
  exit 64
fi
[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 65; }
[[ "$SNAPSHOT_BYTES" =~ ^[0-9]+$ ]] || { echo 'Invalid snapshot byte count.' >&2; exit 66; }
[[ "$SNAPSHOT_BYTES" -le 1099511627776 ]] || { echo 'Snapshot byte count exceeds bounded policy.' >&2; exit 67; }

PROJECT_ROOT='/var/www/agency-preprod'
CURRENT_LINK="$PROJECT_ROOT/current"
RUNTIME_SETTINGS="$PROJECT_ROOT/shared/settings/settings.php"
ACTIVE_SETTINGS="$CURRENT_LINK/web/sites/default/settings.php"
RUNTIME_DB='agency_preprod'
suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
STAGING_DB="agency_preprod_stage_${suffix}"
STAGING_DB_HASH="$(printf '%s' "$STAGING_DB" | sha256sum | awk '{print $1}')"
IMPORT_STDERR="/var/tmp/agency-834-${suffix}.import.stderr"

[[ "$STAGING_DB" =~ ^agency_preprod_stage_[0-9a-f]{12}$ ]]
test -f "$RUNTIME_SETTINGS"

command -v sudo >/dev/null
command -v mariadb >/dev/null
sudo -n mariadb --protocol=socket -Nse 'SELECT 1' >/dev/null

schema_count() {
  sudo -n mariadb --protocol=socket -Nse \
    "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${STAGING_DB}'"
}

table_count() {
  sudo -n mariadb --protocol=socket -Nse \
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${STAGING_DB}'"
}

runtime_isolated() {
  test -L "$CURRENT_LINK" && \
    test -L "$ACTIVE_SETTINGS" && \
    [[ "$(readlink -f "$ACTIVE_SETTINGS")" == "$(readlink -f "$RUNTIME_SETTINGS")" ]] && \
    grep -Fq "'database' => '$RUNTIME_DB'" "$RUNTIME_SETTINGS" && \
    ! grep -Fq "'database' => '$STAGING_DB'" "$RUNTIME_SETTINGS"
}

capacity_check() {
  local free required multiplier margin minimum
  free="$(df -PB1 /var/lib/mysql | awk 'NR==2 {print $4}')"
  multiplier=4
  margin=1073741824
  minimum=2147483648
  required=$((SNAPSHOT_BYTES * multiplier + margin))
  if [[ "$required" -lt "$minimum" ]]; then
    required="$minimum"
  fi
  [[ "$free" =~ ^[0-9]+$ && "$free" -ge "$required" ]]
}

case "$ACTION" in
  PRECHECK)
    capacity_check || { echo 'PREPROD capacity preflight failed.' >&2; exit 68; }
    runtime_isolated || { echo 'PREPROD runtime isolation preflight failed.' >&2; exit 69; }
    printf '%s\n' \
      'preprod_capacity=PASS' \
      'preprod_runtime_points_to_staging=NO' \
      'staging_admin_capability=PASS'
    ;;

  VERIFY_ABSENCE)
    runtime_isolated || { echo 'PREPROD runtime isolation verification failed.' >&2; exit 70; }
    [[ "$(schema_count)" == '0' ]] || { echo 'Unsanitized staging database is still present.' >&2; exit 71; }
    printf '%s\n' \
      'preprod_runtime_points_to_staging=NO' \
      'staging_db_present_after_cleanup=NO'
    ;;

  CLEANUP)
    sudo -n mariadb --protocol=socket -e "DROP DATABASE IF EXISTS \`${STAGING_DB}\`;" >/dev/null
    rm -f -- "$IMPORT_STDERR"
    runtime_isolated || { echo 'PREPROD runtime isolation cleanup verification failed.' >&2; exit 72; }
    [[ "$(schema_count)" == '0' ]] || { echo 'Staging cleanup failed.' >&2; exit 73; }
    printf '%s\n' \
      'preprod_runtime_points_to_staging=NO' \
      'staging_db_present_after_cleanup=NO'
    ;;

  IMPORT)
    capacity_check || { echo 'PREPROD capacity preflight failed before import.' >&2; exit 74; }
    runtime_isolated || { echo 'PREPROD runtime unexpectedly references staging.' >&2; exit 75; }
    [[ "$(schema_count)" == '0' ]] || { echo 'Staging database already exists.' >&2; exit 76; }

    cleanup_import() {
      local original="$?"
      local final="$original"
      trap - EXIT HUP INT TERM
      set +e
      sudo -n mariadb --protocol=socket -e "DROP DATABASE IF EXISTS \`${STAGING_DB}\`;" >/dev/null 2>&1
      local drop_status="$?"
      rm -f -- "$IMPORT_STDERR"
      if [[ "$drop_status" -ne 0 || "$(schema_count 2>/dev/null)" != '0' ]]; then
        final=97
      else
        printf '%s\n' 'staging_db_present_after_cleanup=NO'
      fi
      if ! runtime_isolated; then
        final=98
      else
        printf '%s\n' 'preprod_runtime_points_to_staging_after=NO'
      fi
      exit "$final"
    }
    trap cleanup_import EXIT
    trap 'exit 129' HUP
    trap 'exit 130' INT
    trap 'exit 143' TERM

    sudo -n mariadb --protocol=socket -e \
      "CREATE DATABASE \`${STAGING_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null
    : > "$IMPORT_STDERR"
    chmod 600 "$IMPORT_STDERR"
    if ! sudo -n mariadb --protocol=socket "$STAGING_DB" 2>"$IMPORT_STDERR"; then
      echo 'Staging import failed; SQL diagnostics retained only transiently for cleanup.' >&2
      exit 77
    fi
    count="$(table_count)"
    [[ "$count" =~ ^[0-9]+$ && "$count" -gt 0 ]] || { echo 'Imported staging schema proof failed.' >&2; exit 78; }
    runtime_isolated || { echo 'PREPROD runtime points to staging after import.' >&2; exit 79; }

    printf '%s\n' \
      'preprod_runtime_points_to_staging_before=NO' \
      'staging_import_result=PASS' \
      "staging_db_id_hash=$STAGING_DB_HASH" \
      'schema_proof=PASS' \
      "safe_table_count=$count"
    ;;
esac
