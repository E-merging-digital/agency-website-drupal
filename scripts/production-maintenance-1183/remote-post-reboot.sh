#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APPROVED_PLAN="${1:-}"
EXPECTED_USER="${2:-}"
START_EPOCH="${3:-}"
TARGET_KERNEL='6.8.0-139-generic'
CURRENT_ROOT='/var/www/agency/current'
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLAN_SCRIPT="$SCRIPT_DIR/remote-plan.sh"
RUNTIME_ERROR_HELPER='/usr/local/sbin/agency-prod-runtime-error-counts'

[[ "$(id -u)" -ne 0 ]]
[[ "$(id -un)" == "$EXPECTED_USER" ]]
[[ "$START_EPOCH" =~ ^[1-9][0-9]{9,}$ ]]
[[ -f "$APPROVED_PLAN" && ! -L "$APPROVED_PLAN" ]]
[[ -x "$PLAN_SCRIPT" ]]

jq -e --arg kernel "$TARGET_KERNEL" '
  .STATUS == "PASS"
  and .ISSUE == 1183
  and .TARGET == "PROD"
  and .MODE == "PLAN"
  and .PLAN_CONTEXT == "PLAN"
  and .MAINTENANCE_ACTION == "REBOOT_ONLY"
  and .REBOOT_ONLY_ELIGIBLE == "YES"
  and .VERSION_ID == "24.04"
  and .TARGET_KERNEL == $kernel
  and .KERNEL_INSTALLED_LATEST == $kernel
  and .KERNEL_RUNNING != $kernel
  and .REBOOT_REQUIRED == "YES"
  and .SECURITY_UPDATES_TOTAL == 0
  and .PACKAGE_UPGRADES == []
  and .REAL_PACKAGE_MUTATION == "NONE"
  and .PHP_BRANCH == "8.4"
  and .MARIADB_BRANCH == "11.8"
  and .MAX_ALLOWED_PACKET == "67108864"' "$APPROVED_PLAN" >/dev/null

version_id="$(awk -F= '$1 == "VERSION_ID" {gsub(/^"|"$/, "", $2); print $2; exit}' /etc/os-release)"
[[ "$version_id" == '24.04' ]]
[[ "$(uname -r)" == "$TARGET_KERNEL" ]]
[[ -d "/lib/modules/$TARGET_KERNEL" ]]
previous_kernel="$(jq -r '.KERNEL_RUNNING' "$APPROVED_PLAN")"
[[ "$previous_kernel" =~ ^[A-Za-z0-9._+-]+$ ]]
[[ "$previous_kernel" != "$TARGET_KERNEL" ]]
[[ -d "/lib/modules/$previous_kernel" ]]
[[ ! -f /var/run/reboot-required ]]

# Read-only service checks remain explicitly unprivileged.
/usr/bin/systemctl is-active --quiet nginx
/usr/bin/systemctl is-active --quiet php8.4-fpm
/usr/bin/systemctl is-active --quiet mariadb
failed_units="$(systemctl --failed --no-legend --plain 2>/dev/null | awk 'NF {print $1}' | head -n 40)"
[[ -z "$failed_units" ]]

(cd "$CURRENT_ROOT" && vendor/bin/drush status >/dev/null)
max_packet="$(cd "$CURRENT_ROOT" && vendor/bin/drush sql:query 'SELECT @@global.max_allowed_packet;' 2>/dev/null | tail -n 1 | tr -d '[:space:]')"
[[ "$max_packet" == '67108864' ]]
maintenance_before="$(cd "$CURRENT_ROOT" && vendor/bin/drush state:get system.maintenance_mode | tail -n 1 | tr -d '[:space:]')"
[[ "$maintenance_before" == '1' ]]

# BEGIN #1197 GOVERNED RUNTIME ERROR OBSERVATION
runtime_error_output="$(sudo -n -- "$RUNTIME_ERROR_HELPER" 2>/dev/null)"
mapfile -t runtime_error_lines <<<"$runtime_error_output"
[[ "${#runtime_error_lines[@]}" -eq 3 ]]
[[ "${runtime_error_lines[0]}" == 'STATUS=PASS' ]]
[[ "${runtime_error_lines[1]}" =~ ^NGINX_RECENT_ERROR_COUNT=([0-9]+)$ ]]
nginx_recent_error_count="${BASH_REMATCH[1]}"
[[ "${runtime_error_lines[2]}" =~ ^PHP_FPM_RECENT_ERROR_COUNT=([0-9]+)$ ]]
php_fpm_recent_error_count="${BASH_REMATCH[1]}"
[[ "$nginx_recent_error_count" -eq 0 ]]
[[ "$php_fpm_recent_error_count" -eq 0 ]]
unset runtime_error_output runtime_error_lines nginx_recent_error_count php_fpm_recent_error_count
# END #1197 GOVERNED RUNTIME ERROR OBSERVATION

# Reopen Drupal only after kernel, service, DB and runtime-error gates pass.
(cd "$CURRENT_ROOT" && vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer >/dev/null)
(cd "$CURRENT_ROOT" && vendor/bin/drush cr >/dev/null)
maintenance_after="$(cd "$CURRENT_ROOT" && vendor/bin/drush state:get system.maintenance_mode | tail -n 1 | tr -d '[:space:]')"
[[ "$maintenance_after" == '0' ]]

main_sha="$(jq -r '.MAIN_SHA' "$APPROVED_PLAN")"
plan_id="$(jq -r '.PLAN_ID' "$APPROVED_PLAN")"
post_plan="$(mktemp)"
cleanup() { rm -f -- "$post_plan"; }
trap cleanup EXIT
"$PLAN_SCRIPT" "$main_sha" "$plan_id" "$EXPECTED_USER" POST_REBOOT >"$post_plan"

jq -e --arg kernel "$TARGET_KERNEL" '
  .STATUS == "PASS"
  and .TARGET == "PROD"
  and .PLAN_CONTEXT == "POST_REBOOT"
  and .MAINTENANCE_ACTION == "REBOOT_ONLY"
  and .REBOOT_ONLY_ELIGIBLE == "NO"
  and .PLAN_DIGEST == null
  and .KERNEL_RUNNING == $kernel
  and .KERNEL_INSTALLED_LATEST == $kernel
  and .REBOOT_REQUIRED == "NO"
  and .SECURITY_UPDATES_TOTAL == 0
  and .PACKAGE_UPGRADES == []
  and .REAL_PACKAGE_MUTATION == "NONE"
  and .MAINTENANCE_MODE == "0"
  and .MAX_ALLOWED_PACKET == "67108864"
  and .NGINX_SERVICE == "ACTIVE"
  and .PHP_FPM_SERVICE == "ACTIVE"
  and .MARIADB_SERVICE == "ACTIVE"
  and .DRUPAL_HEALTH == "PASS"
  and .PUBLIC_HEALTH == "PASS"
  and .PUBLIC_HOME == "PASS"
  and .CONTACT_FORM_SURFACE == "PASS"
  and .RECENT_NGINX_PHP_ERRORS == "NONE_MATERIAL"
  and .SAFETY_GATE == "PASS"' "$post_plan" >/dev/null

python3 - "$APPROVED_PLAN" "$post_plan" <<'PY'
import json
import sys
from pathlib import Path
approved = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
post = json.loads(Path(sys.argv[2]).read_text(encoding='utf-8'))
pre_observed = {item['name'] for item in approved.get('UPGRADABLE_PACKAGES', [])}
post_observed = {item['name'] for item in post.get('UPGRADABLE_PACKAGES', [])}
new_updates = sorted(post_observed - pre_observed)
result = {
    'schema_version': 1,
    'STATUS': 'PASS',
    'ISSUE': 1183,
    'TARGET': 'PROD',
    'MODE': 'APPLY',
    'MAINTENANCE_ACTION': 'REBOOT_ONLY',
    'MAIN_SHA': approved['MAIN_SHA'],
    'PLAN_ID': approved['PLAN_ID'],
    'PLAN_DIGEST': approved['PLAN_DIGEST'],
    'PACKAGE_APPLY': 'NONE',
    'PACKAGE_APPLY_SUCCESS': 'NOT_REQUIRED',
    'SECOND_EXACT_APT_SIMULATION': 'NOT_REQUIRED',
    'REAL_PACKAGE_MUTATION': 'NONE',
    'REBOOT_RECONCILIATION': 'PASS',
    'HOST_REACHABLE_AFTER_REBOOT': 'YES',
    'POST_REBOOT_VALIDATION': 'PASS',
    'KERNEL_RUNNING': post['KERNEL_RUNNING'],
    'KERNEL_INSTALLED_LATEST': post['KERNEL_INSTALLED_LATEST'],
    'REBOOT_REQUIRED': post['REBOOT_REQUIRED'],
    'SECURITY_UPDATES_TOTAL': post['SECURITY_UPDATES_TOTAL'],
    'PRE_OBSERVED_UPGRADABLE_PACKAGES': approved.get('UPGRADABLE_PACKAGES', []),
    'POST_OBSERVED_UPGRADABLE_PACKAGES': post.get('UPGRADABLE_PACKAGES', []),
    'NEW_UPDATES_AFTER_PLAN': new_updates,
    'FAILED_SYSTEMD_UNITS': post['FAILED_SYSTEMD_UNITS'],
    'NGINX_SERVICE': post['NGINX_SERVICE'],
    'PHP_FPM_SERVICE': post['PHP_FPM_SERVICE'],
    'MARIADB_SERVICE': post['MARIADB_SERVICE'],
    'UNPRIVILEGED_SERVICE_CHECKS': 'PASS',
    'DRUPAL_HEALTH': post['DRUPAL_HEALTH'],
    'PUBLIC_HEALTH': post['PUBLIC_HEALTH'],
    'CONFIG_STATUS': post['CONFIG_STATUS'],
    'CONFIG_AUTO_CORRECTION': 'NONE',
    'MAX_ALLOWED_PACKET': post['MAX_ALLOWED_PACKET'],
    'PUBLIC_HOME': post['PUBLIC_HOME'],
    'CONTACT_FORM_SURFACE': post['CONTACT_FORM_SURFACE'],
    'RECENT_NGINX_PHP_ERRORS': post['RECENT_NGINX_PHP_ERRORS'],
    'MAINTENANCE_MODE': 'OFF',
    'KEEP_PREVIOUS_KERNEL': 'YES',
    'VERSION_LOG_REQUIRED': 'YES',
    'SNAPSHOT_RESTORE': 'NONE',
}
print(json.dumps(result, sort_keys=True, separators=(',', ':')))
PY
