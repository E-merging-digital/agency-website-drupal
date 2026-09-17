#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APPROVED_PLAN="${1:-}"
EXPECTED_DIGEST="${2:-}"
SNAPSHOT_REF="${3:-}"
WINDOW_REF="${4:-}"
EXPECTED_USER="${5:-}"
TARGET_KERNEL='6.8.0-139-generic'
PROJECT_ROOT='/var/www/agency'
CURRENT_ROOT="$PROJECT_ROOT/current"
BACKUP_DIR="$PROJECT_ROOT/shared/backups"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYSTEM_CONFIG_BACKUP_HELPER='/usr/local/sbin/agency-prod-system-config-backup'
REBOOT_HELPER='/usr/local/sbin/agency-prod-os-maintenance-1183-reboot'
PLAN_SCRIPT="$SCRIPT_DIR/remote-plan.sh"

[[ "$(id -u)" -ne 0 ]]
[[ "$(id -un)" == "$EXPECTED_USER" ]]
[[ -f "$APPROVED_PLAN" && ! -L "$APPROVED_PLAN" ]]
[[ "$EXPECTED_DIGEST" =~ ^[0-9a-f]{64}$ ]]
[[ "$SNAPSHOT_REF" =~ ^[A-Za-z0-9._:@/-]{8,160}$ ]]
[[ "$WINDOW_REF" =~ ^[A-Za-z0-9._:@/+:-]{8,160}$ ]]
[[ -x "$PLAN_SCRIPT" ]]
for command_name in jq python3 sudo systemctl; do
  command -v "$command_name" >/dev/null
done

jq -e \
  --arg digest "$EXPECTED_DIGEST" \
  --arg kernel "$TARGET_KERNEL" '
  .schema_version == 1
  and .STATUS == "PASS"
  and .ISSUE == 1183
  and .TARGET == "PROD"
  and .MODE == "PLAN"
  and .PLAN_CONTEXT == "PLAN"
  and .MAINTENANCE_ACTION == "REBOOT_ONLY"
  and .REBOOT_ONLY_ELIGIBLE == "YES"
  and .PLAN_DIGEST == $digest
  and (.MAIN_SHA | test("^[0-9a-f]{40}$"))
  and (.PLAN_ID | test("^plan-1183-[A-Za-z0-9._-]{8,80}$"))
  and .VERSION_ID == "24.04"
  and .TARGET_KERNEL == $kernel
  and .KERNEL_INSTALLED_LATEST == $kernel
  and .KERNEL_RUNNING != $kernel
  and .REBOOT_REQUIRED == "YES"
  and .SECURITY_UPDATES_TOTAL == 0
  and .PACKAGE_UPGRADES == []
  and .PACKAGE_UPGRADES_SELECTED_FOR_APPLY == []
  and .PACKAGE_REMOVALS == []
  and .PACKAGE_ADDITIONS == []
  and .HELD_PACKAGES == []
  and .FAILED_SYSTEMD_UNITS == []
  and .PHP_BRANCH == "8.4"
  and .MARIADB_BRANCH == "11.8"
  and .MAX_ALLOWED_PACKET == "67108864"
  and .NGINX_SERVICE == "ACTIVE"
  and .PHP_FPM_SERVICE == "ACTIVE"
  and .MARIADB_SERVICE == "ACTIVE"
  and .DRUPAL_HEALTH == "PASS"
  and .PUBLIC_HEALTH == "PASS"
  and .PUBLIC_HOME == "PASS"
  and .CONTACT_FORM_SURFACE == "PASS"
  and .RECENT_NGINX_PHP_ERRORS == "NONE_MATERIAL"
  and .MAINTENANCE_MODE == "0"
  and .PRIVILEGED_SYSTEM_CONFIG_BACKUP == "AVAILABLE"
  and .PRIVILEGED_RUNTIME_ERROR_HELPER == "AVAILABLE"
  and .PRIVILEGED_REBOOT_HELPER == "AVAILABLE"
  and .PRIVILEGED_APT_UPDATE == "UNKNOWN"
  and .WHY_UNKNOWN_APT_UPDATE == "NOT_REQUIRED"
  and .PRIVILEGED_APT_SIMULATE == "UNKNOWN"
  and .WHY_UNKNOWN_APT_SIMULATE == "NOT_REQUIRED"
  and .PRIVILEGED_APT_INSTALL == "UNKNOWN"
  and .WHY_UNKNOWN_APT_INSTALL == "NOT_REQUIRED"
  and .PRIVILEGED_NGINX_TEST == "UNKNOWN"
  and .WHY_UNKNOWN_NGINX_TEST == "NOT_REQUIRED"
  and .PRIVILEGED_PHP_FPM_TEST == "UNKNOWN"
  and .WHY_UNKNOWN_PHP_FPM_TEST == "NOT_REQUIRED"
  and .REAL_PACKAGE_MUTATION == "NONE"
  and .SAFETY_GATE == "PASS"' "$APPROVED_PLAN" >/dev/null

main_sha="$(jq -r '.MAIN_SHA' "$APPROVED_PLAN")"
plan_id="$(jq -r '.PLAN_ID' "$APPROVED_PLAN")"
work_root="$(mktemp -d)"
cleanup() { rm -rf -- "$work_root"; }
trap cleanup EXIT

maintenance_entered='NO'
reboot_boundary_crossed='NO'
current_stage='PRE_MAINTENANCE'

# BEGIN #1228 PRE-REBOOT FAILURE RECOVERY
drush_current() {
  (cd "$CURRENT_ROOT" && vendor/bin/drush "$@")
}

emit_pre_reboot_failure_receipt() {
  local output="$receipt_file.failure.tmp"
  jq -n \
    --arg main_sha "$main_sha" \
    --arg plan_id "$plan_id" \
    --arg plan_digest "$EXPECTED_DIGEST" \
    --arg snapshot_ref "$SNAPSHOT_REF" \
    --arg window_ref "$WINDOW_REF" \
    --arg db_backup "$db_backup" \
    --arg config_backup "$config_backup" \
    --arg config_backup_sha256 "$config_backup_sha256" \
    --arg config_backup_size "$config_backup_size" \
    --arg failure_stage "$original_failure_stage" \
    --arg original_exit_status "$original_exit_status" \
    --arg recovery_attempted "$maintenance_recovery_attempted" \
    --arg recovery_result "$maintenance_recovery_result" \
    --arg maintenance_final "$maintenance_mode_final" '
    {
      schema_version:1,
      STATUS:"FAIL",
      ISSUE:1183,
      TARGET:"PROD",
      MODE:"APPLY",
      MAINTENANCE_ACTION:"REBOOT_ONLY",
      MAIN_SHA:$main_sha,
      PLAN_ID:$plan_id,
      PLAN_DIGEST:$plan_digest,
      STALE_PLAN:"PASS",
      PROVIDER_SNAPSHOT_REF:$snapshot_ref,
      PROVIDER_SNAPSHOT_AUTOMATED_VERIFICATION:"UNAVAILABLE",
      MAINTENANCE_WINDOW_REF:$window_ref,
      MAINTENANCE_WINDOW_APPROVAL:"PROJECT_LEAD_EXTERNAL",
      FAILURE_PHASE:"PRE_REBOOT",
      FAILURE_STAGE:$failure_stage,
      REBOOT_HELPER_INVOKED:"NO",
      REBOOT_BOUNDARY_CROSSED:"NO",
      PACKAGE_APPLY:"NONE",
      PACKAGE_APPLY_SUCCESS:"NOT_REQUIRED",
      SECOND_EXACT_APT_SIMULATION:"NOT_REQUIRED",
      REAL_PACKAGE_MUTATION:"NONE",
      DATABASE_BACKUP:$db_backup,
      SYSTEM_CONFIG_BACKUP:$config_backup,
      SYSTEM_CONFIG_BACKUP_SHA256:$config_backup_sha256,
      SYSTEM_CONFIG_BACKUP_SIZE:($config_backup_size|tonumber),
      BACKUPS_PRESERVED:"YES",
      MAINTENANCE_RECOVERY_ATTEMPTED:$recovery_attempted,
      MAINTENANCE_RECOVERY_RESULT:$recovery_result,
      MAINTENANCE_MODE_FINAL:$maintenance_final,
      ORIGINAL_EXIT_STATUS:($original_exit_status|tonumber),
      DRUPAL_DEPLOY:"NONE",
      DRUPAL_CONFIG_IMPORT:"NONE",
      SNAPSHOT_RESTORE:"NONE"
    }' >"$output"
  mv -- "$output" "$receipt_file"
  cat "$receipt_file"
}

handle_pre_reboot_failure() {
  original_exit_status="$1"
  original_failure_stage="$current_stage"
  trap - ERR
  set +e

  maintenance_recovery_attempted='NO'
  maintenance_recovery_result='NOT_REQUIRED'
  maintenance_mode_final='UNKNOWN'

  if [[ "$maintenance_entered" == 'YES' && "$reboot_boundary_crossed" == 'NO' ]]; then
    maintenance_recovery_attempted='YES'
    drush_current state:set system.maintenance_mode 0 --input-format=integer >/dev/null 2>&1
    recovery_state_rc=$?
    drush_current cr >/dev/null 2>&1
    recovery_cr_rc=$?
    recovered_maintenance="$(drush_current state:get system.maintenance_mode 2>/dev/null | tail -n 1 | tr -d '[:space:]')"
    recovery_verify_rc=$?
    if [[ "$recovery_verify_rc" -eq 0 && "$recovered_maintenance" == '0' ]]; then
      maintenance_mode_final='OFF'
    fi
    if [[ "$recovery_state_rc" -eq 0 && "$recovery_cr_rc" -eq 0 && "$maintenance_mode_final" == 'OFF' ]]; then
      maintenance_recovery_result='PASS'
    else
      maintenance_recovery_result='FAIL'
    fi
  fi

  emit_pre_reboot_failure_receipt
  receipt_rc=$?
  if [[ "$receipt_rc" -ne 0 ]]; then
    printf 'PRE_REBOOT_FAILURE_RECEIPT_WRITE_FAILED stage=%s original_status=%s\n' \
      "$original_failure_stage" "$original_exit_status" >&2
  fi
  exit "$original_exit_status"
}
# END #1228 PRE-REBOOT FAILURE RECOVERY

# Revalidate the approved reboot-only identity before any backup or maintenance mutation.
set +e
"$PLAN_SCRIPT" "$main_sha" "$plan_id" "$EXPECTED_USER" PLAN >"$work_root/current-plan.json"
current_plan_rc=$?
set -e
[[ -s "$work_root/current-plan.json" ]]
if [[ "$current_plan_rc" -ne 0 ]]; then
  printf 'STALE_PLAN: current reboot-only plan is no longer eligible\n' >&2
  exit 75
fi
current_digest="$(jq -r '.PLAN_DIGEST' "$work_root/current-plan.json")"
[[ "$current_digest" == "$EXPECTED_DIGEST" ]] || {
  printf 'STALE_PLAN: approved=%s current=%s\n' "$EXPECTED_DIGEST" "$current_digest" >&2
  exit 75
}

start_epoch="$(date +%s)"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_stem="$BACKUP_DIR/pre-os-maintenance-1183-$timestamp.sql"
db_backup="$backup_stem.gz"
receipt_file="$BACKUP_DIR/pre-os-maintenance-1183-$timestamp-pre-reboot.json"

# Rollback evidence is mandatory before Drupal maintenance mode or reboot.
(cd "$CURRENT_ROOT" && vendor/bin/drush sql:dump --gzip --result-file="$backup_stem" >/dev/null)
[[ -s "$db_backup" ]]
# BEGIN #1215 BACKUP RECEIPT
sudo -n -- "$SYSTEM_CONFIG_BACKUP_HELPER" >"$work_root/config-backup.receipt" 2>/dev/null
[[ "$(stat -c '%s' -- "$work_root/config-backup.receipt")" -le 512 ]]
mapfile -t backup_lines <"$work_root/config-backup.receipt"
[[ "${#backup_lines[@]}" -eq 4 && "${backup_lines[0]}" == 'STATUS=PASS' ]]
[[ "${backup_lines[1]}" =~ ^SYSTEM_CONFIG_BACKUP=(/var/www/agency/shared/backups/pre-os-maintenance-1183-[0-9]{8}T[0-9]{6}Z-system-config\.tar\.gz)$ ]]
config_backup="${BASH_REMATCH[1]}"
[[ "${backup_lines[2]}" =~ ^SYSTEM_CONFIG_BACKUP_SHA256=([0-9a-f]{64})$ ]]
config_backup_sha256="${BASH_REMATCH[1]}"
[[ "${backup_lines[3]}" =~ ^SYSTEM_CONFIG_BACKUP_SIZE=([1-9][0-9]{0,18})$ ]]
config_backup_size="${BASH_REMATCH[1]}"
printf '%s\n' "${backup_lines[@]}" | cmp -s -- "$work_root/config-backup.receipt" -
[[ -f "$config_backup" && ! -L "$config_backup" ]]
[[ "$(stat -c '%s:%u:%g:%a' -- "$config_backup" 2>/dev/null)" == "$config_backup_size:0:0:600" ]]
unset backup_lines
# END #1215 BACKUP RECEIPT

previous_kernel="$(uname -r)"
[[ "$previous_kernel" != "$TARGET_KERNEL" ]]
[[ -d "/lib/modules/$previous_kernel" ]]
[[ -d "/lib/modules/$TARGET_KERNEL" ]]
[[ -f /var/run/reboot-required ]]

# Service state is a read-only unprivileged observation; no sudo is required.
/usr/bin/systemctl is-active --quiet nginx
/usr/bin/systemctl is-active --quiet php8.4-fpm
/usr/bin/systemctl is-active --quiet mariadb

# Enter Drupal maintenance mode only after both rollback backups exist.
current_stage='MAINTENANCE_MODE_ENABLE'
drush_current state:set system.maintenance_mode 1 --input-format=integer >/dev/null
maintenance_entered='YES'
trap 'handle_pre_reboot_failure "$?"' ERR

current_stage='CACHE_REBUILD_AFTER_MAINTENANCE_ON'
drush_current cr >/dev/null
current_stage='MAINTENANCE_MODE_VERIFY_ON'
# Do not inherit ERR into command substitution: the parent trap owns recovery.
set +E
maintenance_now="$(drush_current state:get system.maintenance_mode | tail -n 1 | tr -d '[:space:]')"
set -E
[[ "$maintenance_now" == '1' ]]

# REBOOT_ONLY intentionally executes no apt command and no privileged config test.
current_stage='NGINX_ACTIVE_CHECK'
/usr/bin/systemctl is-active --quiet nginx
current_stage='PHP_FPM_ACTIVE_CHECK'
/usr/bin/systemctl is-active --quiet php8.4-fpm
current_stage='MARIADB_ACTIVE_CHECK'
/usr/bin/systemctl is-active --quiet mariadb
current_stage='MAX_ALLOWED_PACKET_CHECK'
# Do not inherit ERR into command substitution: the parent trap owns recovery.
set +E
max_packet="$(drush_current sql:query 'SELECT @@global.max_allowed_packet;' 2>/dev/null | tail -n 1 | tr -d '[:space:]')"
set -E
[[ "$max_packet" == '67108864' ]]
current_stage='RUNNING_KERNEL_INVARIANT'
# Keep command-substitution failure owned by the parent ERR trap.
set +E
running_kernel="$(uname -r)"
set -E
[[ "$running_kernel" != "$TARGET_KERNEL" ]]
current_stage='TARGET_KERNEL_INVARIANT'
[[ -d "/lib/modules/$TARGET_KERNEL" ]]
current_stage='REBOOT_REQUIRED_INVARIANT'
[[ -f /var/run/reboot-required ]]

current_stage='PRE_REBOOT_RECEIPT'
jq -n \
  --arg main_sha "$main_sha" \
  --arg plan_id "$plan_id" \
  --arg plan_digest "$EXPECTED_DIGEST" \
  --arg snapshot_ref "$SNAPSHOT_REF" \
  --arg window_ref "$WINDOW_REF" \
  --arg db_backup "$db_backup" \
  --arg config_backup "$config_backup" \
  --arg config_backup_sha256 "$config_backup_sha256" \
  --arg config_backup_size "$config_backup_size" \
  --arg previous_kernel "$previous_kernel" \
  --arg target_kernel "$TARGET_KERNEL" \
  --arg start_epoch "$start_epoch" '
  {
    schema_version:1,
    STATUS:"PASS",
    ISSUE:1183,
    TARGET:"PROD",
    MODE:"APPLY",
    MAINTENANCE_ACTION:"REBOOT_ONLY",
    MAIN_SHA:$main_sha,
    PLAN_ID:$plan_id,
    PLAN_DIGEST:$plan_digest,
    STALE_PLAN:"PASS",
    PROVIDER_SNAPSHOT_REF:$snapshot_ref,
    PROVIDER_SNAPSHOT_AUTOMATED_VERIFICATION:"UNAVAILABLE",
    MAINTENANCE_WINDOW_REF:$window_ref,
    MAINTENANCE_WINDOW_APPROVAL:"PROJECT_LEAD_EXTERNAL",
    DATABASE_BACKUP:$db_backup,
    SYSTEM_CONFIG_BACKUP:$config_backup,
    SYSTEM_CONFIG_BACKUP_SHA256:$config_backup_sha256,
    SYSTEM_CONFIG_BACKUP_SIZE:($config_backup_size|tonumber),
    BACKUPS_BEFORE_REBOOT:"PASS",
    MAINTENANCE_MODE_ENTERED:"YES",
    PACKAGE_APPLY:"NONE",
    PACKAGE_APPLY_SUCCESS:"NOT_REQUIRED",
    SECOND_EXACT_APT_SIMULATION:"NOT_REQUIRED",
    REAL_PACKAGE_MUTATION:"NONE",
    UNPRIVILEGED_SERVICE_CHECKS:"PASS",
    NGINX_TEST:"NOT_REQUIRED",
    PHP_FPM_TEST:"NOT_REQUIRED",
    KEEP_PREVIOUS_KERNEL:"YES",
    PREVIOUS_KERNEL:$previous_kernel,
    TARGET_KERNEL:$target_kernel,
    REBOOT_INITIATED:"YES",
    REBOOT_CAPABILITY:"FIXED_PURPOSE_HELPER",
    START_EPOCH:($start_epoch|tonumber),
    DRUPAL_DEPLOY:"NONE",
    DRUPAL_CONFIG_IMPORT:"NONE",
    SNAPSHOT_RESTORE:"NONE"
  }' >"$receipt_file"
cat "$receipt_file"
sync

# Crossing this boundary means a real reboot may already have started. Never run
# pre-reboot maintenance cleanup after the fixed-purpose helper is invoked.
current_stage='REBOOT_HELPER_INVOCATION'
reboot_boundary_crossed='YES'
trap - ERR
sudo -n -- "$REBOOT_HELPER" >/dev/null 2>&1
