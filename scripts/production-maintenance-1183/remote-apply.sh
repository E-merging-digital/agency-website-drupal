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
PLAN_SCRIPT="$SCRIPT_DIR/remote-plan.sh"

[[ "$(id -u)" -ne 0 ]]
[[ "$(id -un)" == "$EXPECTED_USER" ]]
[[ -f "$APPROVED_PLAN" && ! -L "$APPROVED_PLAN" ]]
[[ "$EXPECTED_DIGEST" =~ ^[0-9a-f]{64}$ ]]
[[ "$SNAPSHOT_REF" =~ ^[A-Za-z0-9._:@/-]{8,160}$ ]]
[[ "$WINDOW_REF" =~ ^[A-Za-z0-9._:@/+:-]{8,160}$ ]]
[[ -x "$PLAN_SCRIPT" ]]
for command_name in jq python3 sudo apt-get systemctl; do
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
  and .PLAN_DIGEST == $digest
  and (.MAIN_SHA | test("^[0-9a-f]{40}$"))
  and (.PLAN_ID | test("^plan-1183-[A-Za-z0-9._-]{8,80}$"))
  and .VERSION_ID == "24.04"
  and .KERNEL_INSTALLED_LATEST == $kernel
  and .PHP_BRANCH == "8.4"
  and .MARIADB_BRANCH == "11.8"
  and .MAX_ALLOWED_PACKET == "67108864"
  and .PACKAGE_REMOVALS == []
  and .PACKAGE_ADDITIONS == []
  and .HELD_PACKAGES == []
  and .FAILED_SYSTEMD_UNITS == []
  and .NGINX_SERVICE == "ACTIVE"
  and .PHP_FPM_SERVICE == "ACTIVE"
  and .MARIADB_SERVICE == "ACTIVE"
  and .DRUPAL_HEALTH == "PASS"
  and .PUBLIC_HEALTH == "PASS"
  and .MAINTENANCE_MODE == "0"
  and (.CONFIG_STATUS == "CLEAN" or .CONFIG_STATUS == "DIFFERENT")
  and .CONFIG_AUTO_CORRECTION == "NONE"
  and .PUBLIC_HOME == "PASS"
  and .CONTACT_FORM_SURFACE == "PASS"
  and .RECENT_NGINX_PHP_ERRORS == "NONE_MATERIAL"
  and .PRIVILEGED_SYSTEM_CONFIG_BACKUP == "AVAILABLE"
  and .PRIVILEGED_NGINX_TEST == "AVAILABLE"
  and .PRIVILEGED_PHP_FPM_TEST == "AVAILABLE"
  and .PRIVILEGED_NGINX_ACTIVE == "AVAILABLE"
  and .PRIVILEGED_PHP_FPM_ACTIVE == "AVAILABLE"
  and .PRIVILEGED_MARIADB_ACTIVE == "AVAILABLE"
  and .PRIVILEGED_REBOOT == "AVAILABLE"
  and .PRIVILEGED_RUNTIME_ERROR_HELPER == "AVAILABLE"
  and (.PACKAGE_UPGRADES | type == "array")
  and ((.PACKAGE_UPGRADES | length) == 0 or
       (.PRIVILEGED_APT_UPDATE == "AVAILABLE" and .PRIVILEGED_APT_SIMULATE == "AVAILABLE" and .PRIVILEGED_APT_INSTALL == "AVAILABLE"))
  and .SAFETY_GATE == "PASS"' "$APPROVED_PLAN" >/dev/null

main_sha="$(jq -r '.MAIN_SHA' "$APPROVED_PLAN")"
plan_id="$(jq -r '.PLAN_ID' "$APPROVED_PLAN")"
work_root="$(mktemp -d)"
cleanup() { rm -rf -- "$work_root"; }
trap cleanup EXIT

# Revalidate the approved mutation identity before any backup or package mutation.
"$PLAN_SCRIPT" "$main_sha" "$plan_id" "$EXPECTED_USER" >"$work_root/current-plan.json"
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
# Backups are mandatory and precede every package mutation.
(cd "$CURRENT_ROOT" && vendor/bin/drush sql:dump --gzip --result-file="$backup_stem" >/dev/null)
[[ -s "$db_backup" ]]
# BEGIN #1215 BACKUP RECEIPT
# Keep helper diagnostics private; accept exactly four canonical lines.
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
# Reject missing newline, NUL bytes and any noncanonical serialization.
printf '%s\n' "${backup_lines[@]}" | cmp -s -- "$work_root/config-backup.receipt" -
[[ -f "$config_backup" && ! -L "$config_backup" ]]
[[ "$(stat -c '%s:%u:%g:%a' -- "$config_backup" 2>/dev/null)" == "$config_backup_size:0:0:600" ]]
unset backup_lines
# END #1215 BACKUP RECEIPT

previous_kernel="$(uname -r)"
[[ -d "/lib/modules/$previous_kernel" ]]
[[ -d "/lib/modules/$TARGET_KERNEL" ]]

# Enter Drupal maintenance mode only after rollback evidence exists.
(cd "$CURRENT_ROOT" && vendor/bin/drush state:set system.maintenance_mode 1 --input-format=integer >/dev/null)
(cd "$CURRENT_ROOT" && vendor/bin/drush cr >/dev/null)
maintenance_now="$(cd "$CURRENT_ROOT" && vendor/bin/drush state:get system.maintenance_mode | tail -n 1 | tr -d '[:space:]')"
[[ "$maintenance_now" == '1' ]]

mapfile -t package_args < <(jq -r '.PACKAGE_UPGRADES | sort_by(.name + "=" + .to)[] | "\(.name)=\(.to)"' "$APPROVED_PLAN")
for package_arg in "${package_args[@]}"; do
  [[ "$package_arg" =~ ^[A-Za-z0-9.+:-]+=[A-Za-z0-9.+:~_-]+$ ]]
done

if (( ${#package_args[@]} > 0 )); then
  sudo -n -- /usr/bin/apt-get update >"$work_root/apt-update.log" 2>&1
  sudo -n -- /usr/bin/apt-get --simulate install --only-upgrade "${package_args[@]}" >"$work_root/exact-sim.raw" 2>&1
  python3 - "$APPROVED_PLAN" "$work_root/exact-sim.raw" <<'PY'
import json
import re
import sys
from pathlib import Path
approved = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
actual = []
for line in Path(sys.argv[2]).read_text(encoding='utf-8', errors='replace').splitlines():
    if line.startswith('Remv '):
        raise SystemExit('Exact apply simulation contains a removal')
    if line.startswith('Inst '):
        match = re.match(r'^Inst\s+(\S+)(?:\s+\[([^\]]+)\])?\s+\((\S+)', line)
        if not match:
            raise SystemExit('Unparseable exact apply simulation')
        name, old, new = match.groups()
        if old is None:
            raise SystemExit('Exact apply simulation contains a package addition')
        actual.append((name, old, new))
expected = sorted((item['name'], item['from'], item['to']) for item in approved['PACKAGE_UPGRADES'])
if sorted(actual) != expected:
    raise SystemExit(f'Exact apply simulation drift: expected={expected!r} actual={sorted(actual)!r}')
PY

  sudo -n -- /usr/bin/apt-get install -y --only-upgrade "${package_args[@]}" >"$work_root/apt-apply.log" 2>&1
fi
sudo -n -- /usr/sbin/nginx -t >/dev/null 2>&1
sudo -n -- /usr/sbin/php-fpm8.4 -t >/dev/null 2>&1
sudo -n -- /usr/bin/systemctl is-active --quiet nginx
sudo -n -- /usr/bin/systemctl is-active --quiet php8.4-fpm
sudo -n -- /usr/bin/systemctl is-active --quiet mariadb
[[ -d "/lib/modules/$previous_kernel" ]]
[[ -d "/lib/modules/$TARGET_KERNEL" ]]

max_packet="$(cd "$CURRENT_ROOT" && vendor/bin/drush sql:query 'SELECT @@global.max_allowed_packet;' 2>/dev/null | tail -n 1 | tr -d '[:space:]')"
[[ "$max_packet" == '67108864' ]]

reboot_needed='NO'
if [[ -f /var/run/reboot-required || "$(uname -r)" != "$TARGET_KERNEL" ]]; then
  reboot_needed='YES'
fi

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
  --arg reboot_initiated "$reboot_needed" \
  --arg start_epoch "$start_epoch" '
  {
    schema_version:1,
    STATUS:"PASS",
    ISSUE:1183,
    TARGET:"PROD",
    MODE:"APPLY",
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
    BACKUPS_BEFORE_PACKAGE_APPLY:"PASS",
    MAINTENANCE_MODE_ENTERED:"YES",
    PACKAGE_APPLY_SUCCESS:"YES",
    SECOND_EXACT_APT_SIMULATION:"PASS",
    KEEP_PREVIOUS_KERNEL:"YES",
    PREVIOUS_KERNEL:$previous_kernel,
    TARGET_KERNEL:$target_kernel,
    REBOOT_INITIATED:$reboot_initiated,
    START_EPOCH:($start_epoch|tonumber),
    DRUPAL_DEPLOY:"NONE",
    DRUPAL_CONFIG_IMPORT:"NONE",
    SNAPSHOT_RESTORE:"NONE"
  }' >"$receipt_file"
cat "$receipt_file"
sync

if [[ "$reboot_needed" == 'YES' ]]; then
  sudo -n -- /usr/bin/systemctl reboot
fi
