#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APPROVED_PLAN="${1:-}"
EXPECTED_DIGEST="${2:-}"
SNAPSHOT_REF="${3:-}"
TARGET_KERNEL='6.8.0-139-generic'
PROJECT_ROOT='/var/www/agency-preprod'
CURRENT_ROOT="$PROJECT_ROOT/current"
BACKUP_DIR="$PROJECT_ROOT/shared/backups"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLAN_SCRIPT="$SCRIPT_DIR/remote-plan.sh"

[[ "$(id -u)" -eq 0 ]]
[[ -f "$APPROVED_PLAN" && ! -L "$APPROVED_PLAN" ]]
[[ "$EXPECTED_DIGEST" =~ ^[0-9a-f]{64}$ ]]
[[ "$SNAPSHOT_REF" =~ ^[A-Za-z0-9._:@/-]{8,160}$ ]]
[[ -x "$PLAN_SCRIPT" ]]
command -v jq >/dev/null
command -v python3 >/dev/null
command -v runuser >/dev/null
command -v apt-get >/dev/null
command -v systemctl >/dev/null

jq -e \
  --arg digest "$EXPECTED_DIGEST" \
  --arg target_kernel "$TARGET_KERNEL" '
  .schema_version == 1
  and .STATUS == "PASS"
  and .ISSUE == 1182
  and .TARGET == "PREPROD"
  and .MODE == "PLAN"
  and .PLAN_DIGEST == $digest
  and (.MAIN_SHA | test("^[0-9a-f]{40}$"))
  and (.PLAN_ID | test("^plan-1182-[A-Za-z0-9._-]{8,80}$"))
  and .VERSION_ID == "24.04"
  and .KERNEL_INSTALLED_LATEST == $target_kernel
  and .PHP_BRANCH == "8.4"
  and .MARIADB_BRANCH == "11.8"
  and .PACKAGE_REMOVALS == []
  and .PACKAGE_ADDITIONS == []
  and .HELD_PACKAGES == []
  and .FAILED_SYSTEMD_UNITS == []
  and .NGINX_SERVICE == "ACTIVE"
  and .PHP_FPM_SERVICE == "ACTIVE"
  and .MARIADB_SERVICE == "ACTIVE"
  and .DRUPAL_HEALTH == "PASS"
  and .PUBLIC_HEALTH == "PASS"
  and .SAFETY_GATE == "PASS"' "$APPROVED_PLAN" >/dev/null

main_sha="$(jq -r '.MAIN_SHA' "$APPROVED_PLAN")"
plan_id="$(jq -r '.PLAN_ID' "$APPROVED_PLAN")"
work_root="$(mktemp -d)"
cleanup() { rm -rf -- "$work_root"; }
trap cleanup EXIT

# Mandatory stale-plan revalidation immediately before any package-state mutation.
"$PLAN_SCRIPT" "$main_sha" "$plan_id" >"$work_root/current-plan.json"
current_digest="$(jq -r '.PLAN_DIGEST' "$work_root/current-plan.json")"
[[ "$current_digest" == "$EXPECTED_DIGEST" ]] || {
  printf 'STALE_PLAN: approved=%s current=%s\n' "$EXPECTED_DIGEST" "$current_digest" >&2
  exit 75
}

install -d -m 750 -o agency-preprod -g www-data "$BACKUP_DIR"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_stem="$BACKUP_DIR/pre-os-maintenance-1182-$timestamp.sql"
db_backup="$backup_stem.gz"
config_backup="$BACKUP_DIR/pre-os-maintenance-1182-$timestamp-system-config.tar.gz"
receipt_file="$BACKUP_DIR/pre-os-maintenance-1182-$timestamp-pre-reboot.json"

runuser -u agency-preprod -- bash -lc \
  "cd '$CURRENT_ROOT' && vendor/bin/drush sql:dump --gzip --result-file='$backup_stem' >/dev/null"
[[ -s "$db_backup" ]]
tar -C / -czf "$config_backup" etc/nginx etc/php/8.4 etc/mysql
[[ -s "$config_backup" ]]
chmod 600 "$db_backup" "$config_backup"
chown agency-preprod:www-data "$db_backup" "$config_backup"

previous_kernel="$(uname -r)"
[[ -d "/lib/modules/$previous_kernel" ]]
[[ -d "/lib/modules/$TARGET_KERNEL" ]]

mapfile -t package_args < <(jq -r '.PACKAGE_UPGRADES[] | "\(.name)=\(.to)"' "$APPROVED_PLAN")
for package_arg in "${package_args[@]}"; do
  [[ "$package_arg" =~ ^[A-Za-z0-9.+:-]+=[A-Za-z0-9.+:~_-]+$ ]]
done

if (( ${#package_args[@]} > 0 )); then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update >"$work_root/apt-update.log" 2>&1
  apt-get --simulate install --only-upgrade "${package_args[@]}" >"$work_root/exact-sim.raw" 2>&1

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
        m = re.match(r'^Inst\s+(\S+)(?:\s+\[([^\]]+)\])?\s+\((\S+)', line)
        if not m:
            raise SystemExit('Unparseable exact apply simulation')
        name, old, new = m.groups()
        if old is None:
            raise SystemExit('Exact apply simulation contains a package addition')
        actual.append((name, old, new))
expected = sorted((item['name'], item['from'], item['to']) for item in approved['PACKAGE_UPGRADES'])
if sorted(actual) != expected:
    raise SystemExit(f'Exact apply simulation drift: expected={expected!r} actual={sorted(actual)!r}')
PY

  apt-get install -y --only-upgrade "${package_args[@]}" >"$work_root/apt-apply.log" 2>&1
fi

# Package operations may restart services; validate configuration before reboot.
nginx -t >/dev/null 2>&1
php-fpm8.4 -t >/dev/null 2>&1
systemctl is-active --quiet nginx
systemctl is-active --quiet php8.4-fpm
systemctl is-active --quiet mariadb
[[ -d "/lib/modules/$previous_kernel" ]]
[[ -d "/lib/modules/$TARGET_KERNEL" ]]

reboot_needed='NO'
if [[ -f /var/run/reboot-required || "$(uname -r)" != "$TARGET_KERNEL" ]]; then
  reboot_needed='YES'
fi

jq -n \
  --arg status 'PASS' \
  --arg issue '1182' \
  --arg target 'PREPROD' \
  --arg mode 'APPLY' \
  --arg main_sha "$main_sha" \
  --arg plan_id "$plan_id" \
  --arg plan_digest "$EXPECTED_DIGEST" \
  --arg snapshot_ref "$SNAPSHOT_REF" \
  --arg db_backup "$db_backup" \
  --arg config_backup "$config_backup" \
  --arg previous_kernel "$previous_kernel" \
  --arg target_kernel "$TARGET_KERNEL" \
  --arg reboot_initiated "$reboot_needed" \
  '{
    STATUS:$status,
    ISSUE:($issue|tonumber),
    TARGET:$target,
    MODE:$mode,
    MAIN_SHA:$main_sha,
    PLAN_ID:$plan_id,
    PLAN_DIGEST:$plan_digest,
    STALE_PLAN:"PASS",
    PACKAGE_APPLY_SUCCESS:"YES",
    PROVIDER_SNAPSHOT_REF:$snapshot_ref,
    PROVIDER_SNAPSHOT_AUTOMATED_VERIFICATION:"UNAVAILABLE",
    DATABASE_BACKUP:$db_backup,
    SYSTEM_CONFIG_BACKUP:$config_backup,
    KEEP_PREVIOUS_KERNEL:"YES",
    PREVIOUS_KERNEL:$previous_kernel,
    TARGET_KERNEL:$target_kernel,
    REBOOT_INITIATED:$reboot_initiated,
    DRUPAL_DEPLOY:"NONE",
    DRUPAL_CONFIG_IMPORT:"NONE",
    PROD_ACCESS:"NONE"
  }' >"$receipt_file"
chmod 600 "$receipt_file"
chown agency-preprod:www-data "$receipt_file"
cat "$receipt_file"
sync

if [[ "$reboot_needed" == 'YES' ]]; then
  systemctl reboot
fi
