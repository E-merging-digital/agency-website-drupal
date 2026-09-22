#!/usr/bin/env bash
set -euo pipefail
umask 077

CURRENT_PHASE='PLAN_VALIDATION'

on_error() {
  local status=$?
  trap - ERR
  set +e
  printf 'RECOVERY_FAILURE_PHASE=%s\n' "$CURRENT_PHASE" >&2
  printf 'RECOVERY_FAILURE_REASON=CHECK_FAILED\n' >&2
  printf 'RECOVERY_FAILURE_EXIT=%s\n' "$status" >&2
  exit "$status"
}

trap on_error ERR

APPROVED_PLAN="${1:-}"
EXPECTED_USER="${2:-}"
INCIDENT_RUN="${3:-}"
PLAN_RUN="${4:-}"
PLAN_DIGEST="${5:-}"

TARGET_KERNEL='6.8.0-139-generic'
CURRENT_ROOT='/var/www/agency/current'
PROD_URL='https://emergingdigital.be'
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MAX_PACKET_OBSERVER="$SCRIPT_DIR/max-allowed-packet-observer.sh"
MAINTENANCE_REOPEN="$SCRIPT_DIR/recovery-maintenance-reopen.sh"
RUNTIME_ERROR_HELPER='/usr/local/sbin/agency-prod-runtime-error-counts'

[[ "$(id -u)" -ne 0 ]]
[[ "$(id -un)" == "$EXPECTED_USER" ]]
[[ "$INCIDENT_RUN" == '35602886717' ]]
[[ "$PLAN_RUN" == '35600328878' ]]
[[ "$PLAN_DIGEST" == '07a672088469959e01863450acd8b5078cd5efc2041dda5545e4bb986635744b' ]]
[[ -f "$APPROVED_PLAN" && ! -L "$APPROVED_PLAN" ]]
[[ -f "$MAX_PACKET_OBSERVER" && ! -L "$MAX_PACKET_OBSERVER" ]]
[[ -x "$MAINTENANCE_REOPEN" ]]

# shellcheck source=max-allowed-packet-observer.sh
source "$MAX_PACKET_OBSERVER"

jq -e --arg digest "$PLAN_DIGEST" --arg kernel "$TARGET_KERNEL" '
  .STATUS == "PASS"
  and .ISSUE == 1183
  and .TARGET == "PROD"
  and .MODE == "PLAN"
  and .PLAN_CONTEXT == "PLAN"
  and .MAINTENANCE_ACTION == "REBOOT_ONLY"
  and .REBOOT_ONLY_ELIGIBLE == "YES"
  and .PLAN_DIGEST == $digest
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
  and .MAX_ALLOWED_PACKET == "67108864"
  and (.MAX_ALLOWED_PACKET_SOURCE == "DRUPAL_DB_API"
    or .MAX_ALLOWED_PACKET_SOURCE == "SUDO_MARIADB")' "$APPROVED_PLAN" >/dev/null

CURRENT_PHASE='OS_KERNEL_VALIDATION'
version_id="$(awk -F= '$1 == "VERSION_ID" {gsub(/^"|"$/, "", $2); print $2; exit}' /etc/os-release)"
[[ "$version_id" == '24.04' ]]
kernel_running="$(uname -r)"
[[ "$kernel_running" == "$TARGET_KERNEL" ]]
[[ -d "/lib/modules/$TARGET_KERNEL" ]]
kernel_installed_latest="$TARGET_KERNEL"
previous_kernel="$(jq -r '.KERNEL_RUNNING' "$APPROVED_PLAN")"
[[ "$previous_kernel" =~ ^[A-Za-z0-9._+-]+$ ]]
[[ "$previous_kernel" != "$TARGET_KERNEL" ]]
[[ -d "/lib/modules/$previous_kernel" ]]
[[ ! -f /var/run/reboot-required ]]
reboot_required='NO'

CURRENT_PHASE='SERVICE_VALIDATION'
/usr/bin/systemctl is-active --quiet nginx
/usr/bin/systemctl is-active --quiet php8.4-fpm
/usr/bin/systemctl is-active --quiet mariadb
failed_units_raw="$(systemctl --failed --no-legend --plain 2>/dev/null | awk 'NF {print $1}' | head -n 40)"
[[ -z "$failed_units_raw" ]]

CURRENT_PHASE='DRUPAL_BOOTSTRAP'
[[ "$CURRENT_ROOT" == '/var/www/agency/current' ]]
[[ -L "$CURRENT_ROOT" ]]
[[ -d '/var/www/agency/releases' && ! -L '/var/www/agency/releases' ]]
active_root="$(readlink -f -- "$CURRENT_ROOT")"
[[ -n "$active_root" && -d "$active_root" ]]
release_name="$(basename -- "$active_root")"
[[ "$release_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]
[[ "$active_root" == "/var/www/agency/releases/$release_name" ]]
[[ -x "$active_root/vendor/bin/drush" ]]
(cd "$active_root" && vendor/bin/drush status >/dev/null)
CURRENT_PHASE='MAX_ALLOWED_PACKET'
max_packet_observation="$(observe_max_allowed_packet "$active_root")"
mapfile -t max_packet_lines <<<"$max_packet_observation"
[[ "${#max_packet_lines[@]}" -eq 2 ]]
[[ "${max_packet_lines[0]}" =~ ^MAX_ALLOWED_PACKET=([0-9]+|UNKNOWN)$ ]]
max_allowed_packet="${BASH_REMATCH[1]}"
[[ "${max_packet_lines[1]}" =~ ^MAX_ALLOWED_PACKET_SOURCE=(DRUPAL_DB_API|SUDO_MARIADB|UNKNOWN)$ ]]
max_allowed_packet_source="${BASH_REMATCH[1]}"
[[ "$max_allowed_packet" == '67108864' ]]
[[ "$max_allowed_packet_source" != 'UNKNOWN' ]]

CURRENT_PHASE='MAINTENANCE_STATE'
maintenance_before="$(cd "$active_root" && vendor/bin/drush state:get system.maintenance_mode | tail -n 1 | tr -d '[:space:]')"
[[ "$maintenance_before" == '0' || "$maintenance_before" == '1' ]]

CURRENT_PHASE='RUNTIME_ERROR_VALIDATION'
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
recent_errors='NONE_MATERIAL'

# This is the only mutable recovery action and occurs only after all pre-reopen gates above pass.
CURRENT_PHASE='MAINTENANCE_REOPEN'
maintenance_change_output="$("$MAINTENANCE_REOPEN" "$CURRENT_ROOT" "$maintenance_before")"
[[ "$maintenance_change_output" =~ ^MAINTENANCE_MODE_CHANGE=(NONE|1_TO_0)$ ]]
maintenance_mode_change="${BASH_REMATCH[1]}"
maintenance_after="$(cd "$active_root" && vendor/bin/drush state:get system.maintenance_mode | tail -n 1 | tr -d '[:space:]')"
[[ "$maintenance_after" == '0' ]]

CURRENT_PHASE='PUBLIC_HEALTH'
work_root="$(mktemp -d)"
cleanup() { rm -rf -- "$work_root"; }
trap cleanup EXIT

public_live='FAIL'
public_ready='FAIL'
for endpoint in live ready; do
  body="$work_root/health-$endpoint.json"
  code="$(curl --silent --show-error --max-time 8 --output "$body" --write-out '%{http_code}' "$PROD_URL/health/$endpoint" || true)"
  if [[ "$code" == '200' ]] && jq -e '.status == "ok" and (keys | sort) == ["status"]' "$body" >/dev/null 2>&1; then
    printf -v "public_${endpoint}" '%s' 'PASS'
  fi
done
[[ "$public_live" == 'PASS' && "$public_ready" == 'PASS' ]]

CURRENT_PHASE='PUBLIC_HOME'
public_home='FAIL'
home_meta="$(curl --silent --show-error --location --max-redirs 3 --connect-timeout 8 --max-time 15 \
  --output "$work_root/home.html" --write-out '%{http_code}|%{url_effective}' "$PROD_URL/" 2>/dev/null || true)"
IFS='|' read -r home_code home_final_url <<<"$home_meta"
[[ "$home_code" == '200' ]]
[[ "$home_final_url" == "$PROD_URL/fr" || "$home_final_url" == "$PROD_URL/fr/" ]]
home_markers="$(python3 - "$work_root/home.html" <<'PY_HOME'
from html.parser import HTMLParser
import sys

class HomepageParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.brand = self.html = self.body = self.main = False

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        tag = tag.lower()
        if tag == 'html':
            self.html = True
        elif tag == 'body':
            self.body = True
        if tag == 'main' or attributes.get('role', '').strip().lower() == 'main':
            self.main = True
        if tag == 'img' and '/images/branding/emerging-digital-mark.svg' in attributes.get('src', ''):
            self.brand = True

parser = HomepageParser()
with open(sys.argv[1], 'r', encoding='utf-8', errors='replace') as handle:
    parser.feed(handle.read())
print('PASS' if all((parser.brand, parser.html, parser.body, parser.main)) else 'FAIL')
PY_HOME
)"
[[ "$home_markers" == 'PASS' ]]
public_home='PASS'

CURRENT_PHASE='CONTACT_FORM'
contact_form_surface='FAIL'
contact_code="$(curl --silent --show-error --max-time 10 --output "$work_root/contact.html" --write-out '%{http_code}' "$PROD_URL/fr/contact" || true)"
if [[ "$contact_code" == '200' ]] && grep -Eqi '<form|webform|contact' "$work_root/contact.html"; then
  contact_form_surface='PASS'
fi
[[ "$contact_form_surface" == 'PASS' ]]

CURRENT_PHASE='TERMINAL_RECEIPT'
python3 - "$INCIDENT_RUN" "$PLAN_RUN" "$PLAN_DIGEST" "$version_id" "$kernel_running" \
  "$kernel_installed_latest" "$reboot_required" "$maintenance_before" "$maintenance_mode_change" \
  "$max_allowed_packet" "$max_allowed_packet_source" "$recent_errors" "$public_home" \
  "$contact_form_surface" "$previous_kernel" <<'PY_RECEIPT'
import json
import sys

(
    incident_run, plan_run, plan_digest, version_id, kernel_running,
    kernel_installed_latest, reboot_required, maintenance_before,
    maintenance_mode_change, max_allowed_packet, max_allowed_packet_source,
    recent_errors, public_home, contact_form_surface, previous_kernel,
) = sys.argv[1:]

receipt = {
    'schema_version': 1,
    'STATUS': 'PASS',
    'ISSUE': 1183,
    'TARGET': 'PROD',
    'MODE': 'POST_REBOOT_RECOVERY',
    'INCIDENT_RUN': int(incident_run),
    'PLAN_RUN': int(plan_run),
    'PLAN_DIGEST': plan_digest,
    'VERSION_ID': version_id,
    'KERNEL_RUNNING': kernel_running,
    'KERNEL_INSTALLED_LATEST': kernel_installed_latest,
    'REBOOT_REQUIRED': reboot_required,
    'FAILED_SYSTEMD_UNITS': [],
    'NGINX_SERVICE': 'ACTIVE',
    'PHP_FPM_SERVICE': 'ACTIVE',
    'MARIADB_SERVICE': 'ACTIVE',
    'DRUPAL_HEALTH': 'PASS',
    'POST_REBOOT_VALIDATION': 'PASS',
    'REBOOT_RECONCILIATION': 'PASS',
    'MAINTENANCE_MODE_BEFORE': maintenance_before,
    'MAINTENANCE_MODE_CHANGE': maintenance_mode_change,
    'MAINTENANCE_MODE': 'OFF',
    'PUBLIC_HOME': public_home,
    'CONTACT_FORM_SURFACE': contact_form_surface,
    'MAX_ALLOWED_PACKET': max_allowed_packet,
    'MAX_ALLOWED_PACKET_SOURCE': max_allowed_packet_source,
    'RECENT_NGINX_PHP_ERRORS': recent_errors,
    'PREVIOUS_KERNEL': previous_kernel,
    'PREVIOUS_KERNEL_RETAINED': 'YES',
    'CONFIG_AUTO_CORRECTION': 'NONE',
    'REAL_PACKAGE_MUTATION': 'NONE',
    'APT': 'NONE',
    'REBOOT_PERFORMED_BY_RECOVERY': 'NO',
    'KEEP_PREVIOUS_KERNEL': 'YES',
    'VERSION_LOG_REQUIRED': 'YES',
    'SNAPSHOT_RESTORE': 'NONE',
}
print(json.dumps(receipt, sort_keys=True, separators=(',', ':')))
PY_RECEIPT
