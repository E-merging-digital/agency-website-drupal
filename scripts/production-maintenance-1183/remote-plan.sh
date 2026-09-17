#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MAIN_SHA="${1:-}"
PLAN_ID="${2:-}"
EXPECTED_USER="${3:-}"
PLAN_CONTEXT="${4:-PLAN}"
ISSUE='1183'
TARGET='PROD'
MODE='PLAN'
TARGET_KERNEL='6.8.0-139-generic'
PROD_URL='https://emergingdigital.be'
DRUPAL_ROOT='/var/www/agency/current'
RUNTIME_ERROR_HELPER='/usr/local/sbin/agency-prod-runtime-error-counts'
REBOOT_HELPER='/usr/local/sbin/agency-prod-os-maintenance-1183-reboot'

[[ "$MAIN_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$PLAN_ID" =~ ^plan-1183-[A-Za-z0-9._-]{8,80}$ ]]
[[ "$EXPECTED_USER" =~ ^[A-Za-z0-9._-]+$ ]]
[[ "$PLAN_CONTEXT" == 'PLAN' || "$PLAN_CONTEXT" == 'POST_REBOOT' ]]
[[ "$(id -un)" == "$EXPECTED_USER" ]]
[[ "$(id -u)" -ne 0 ]]
for command_name in python3 apt-get apt apt-mark systemctl dpkg-query curl jq sudo; do
  command -v "$command_name" >/dev/null
done

work_root="$(mktemp -d)"
apt_root="$(mktemp -d)"
cleanup() {
  rm -rf -- "$work_root" "$apt_root"
}
trap cleanup EXIT

# shellcheck disable=SC1091
source /etc/os-release
os_pretty_name="${PRETTY_NAME:-}"
version_id="${VERSION_ID:-}"
kernel_running="$(uname -r)"
kernel_installed_latest="$(find /lib/modules -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | sort -V | tail -n 1)"
[[ -n "$os_pretty_name" && -n "$version_id" && -n "$kernel_running" && -n "$kernel_installed_latest" ]]
reboot_required='NO'
[[ ! -f /var/run/reboot-required ]] || reboot_required='YES'

mkdir -p "$apt_root/state/lists/partial" "$apt_root/cache/archives/partial" "$apt_root/log"
apt_options=(
  -o "Dir::State=$apt_root/state"
  -o 'Dir::State::status=/var/lib/dpkg/status'
  -o 'Dir::State::extended_states=/var/lib/apt/extended_states'
  -o "Dir::State::lists=$apt_root/state/lists"
  -o "Dir::Cache=$apt_root/cache"
  -o "Dir::Cache::archives=$apt_root/cache/archives"
  -o "Dir::Cache::pkgcache=$apt_root/cache/pkgcache.bin"
  -o "Dir::Cache::srcpkgcache=$apt_root/cache/srcpkgcache.bin"
  -o "Dir::Log=$apt_root/log"
  -o 'APT::Get::List-Cleanup=0'
  -o 'Debug::NoLocking=1'
)
apt-get "${apt_options[@]}" update >"$apt_root/update.log" 2>&1
apt "${apt_options[@]}" list --upgradable >"$work_root/upgradable.raw" 2>/dev/null
apt-get "${apt_options[@]}" --simulate upgrade >"$work_root/upgrade-sim.raw" 2>&1
apt-get "${apt_options[@]}" -o APT::Get::Always-Include-Phased-Updates=true \
  --simulate upgrade >"$work_root/upgrade-sim-phased.raw" 2>&1
apt-mark showhold 2>/dev/null | head -n 40 >"$work_root/held.raw"
systemctl --failed --no-legend --plain 2>/dev/null | awk 'NF {print $1}' | head -n 40 >"$work_root/failed.raw"

nginx_service="$(systemctl is-active nginx 2>/dev/null || true)"
php_service="$(systemctl is-active php8.4-fpm 2>/dev/null || true)"
mariadb_service="$(systemctl is-active mariadb 2>/dev/null || true)"
php_version="$(php-fpm8.4 -v 2>/dev/null | head -n 1 || true)"
mariadb_version="$(mariadb --version 2>/dev/null | head -n 1 || true)"
php_branch="$(sed -nE 's/^PHP ([0-9]+\.[0-9]+).*/\1/p' <<<"$php_version" | head -n 1)"
mariadb_branch="$(grep -oE '[0-9]+\.[0-9]+\.[0-9]+-MariaDB' <<<"$mariadb_version" | head -n 1 | cut -d. -f1-2)"

drupal_health='FAIL'
maintenance_mode='UNKNOWN'
config_status='UNOBSERVABLE'
max_allowed_packet='UNKNOWN'
max_allowed_packet_source='UNKNOWN'
if [[ -x "$DRUPAL_ROOT/vendor/bin/drush" ]] && (cd "$DRUPAL_ROOT" && vendor/bin/drush status >/dev/null 2>&1); then
  drupal_health='PASS'
  if maintenance_raw="$(cd "$DRUPAL_ROOT" && vendor/bin/drush state:get system.maintenance_mode 2>/dev/null | tail -n 1 | tr -d '[:space:]')"; then
    [[ "$maintenance_raw" =~ ^[01]$ ]] && maintenance_mode="$maintenance_raw"
  fi
  unset maintenance_raw
  config_json="$work_root/config-status.json"
  if (cd "$DRUPAL_ROOT" && vendor/bin/drush config:status --format=json >"$config_json" 2>/dev/null) && jq -e 'type == "array" or type == "object"' "$config_json" >/dev/null 2>&1; then
    config_count="$(jq 'length' "$config_json")"
    config_status='DIFFERENT'
    [[ "$config_count" -eq 0 ]] && config_status='CLEAN'
  fi
  if max_packet_raw="$(cd "$DRUPAL_ROOT" && vendor/bin/drush php:eval 'echo (string) \Drupal::database()->query("SELECT @@global.max_allowed_packet")->fetchField();' 2>/dev/null | tail -n 1 | tr -d '[:space:]')"; then
    if [[ "$max_packet_raw" =~ ^[0-9]+$ ]]; then
      max_allowed_packet="$max_packet_raw"
      max_allowed_packet_source='DRUPAL_DB_API'
    fi
  fi
  unset max_packet_raw
fi
if [[ "$max_allowed_packet_source" == 'UNKNOWN' ]]; then
  if max_packet_raw="$(sudo -n mariadb -NBe 'SELECT @@global.max_allowed_packet;' 2>/dev/null | tail -n 1 | tr -d '[:space:]')"; then
    if [[ "$max_packet_raw" =~ ^[0-9]+$ ]]; then
      max_allowed_packet="$max_packet_raw"
      max_allowed_packet_source='SUDO_MARIADB'
    fi
  fi
  unset max_packet_raw
fi

recent_errors='UNKNOWN'
recent_error_read_capability='FAIL'
nginx_recent_error_count='UNKNOWN'
php_fpm_recent_error_count='UNKNOWN'
# BEGIN #1197 GOVERNED RUNTIME ERROR OBSERVATION
runtime_error_output=''
if runtime_error_output="$(sudo -n -- "$RUNTIME_ERROR_HELPER" 2>/dev/null)"; then
  mapfile -t runtime_error_lines <<<"$runtime_error_output"
  nginx_candidate=''
  php_fpm_candidate=''
  if [[ "${#runtime_error_lines[@]}" -eq 3 && "${runtime_error_lines[0]}" == 'STATUS=PASS' ]]; then
    if [[ "${runtime_error_lines[1]}" =~ ^NGINX_RECENT_ERROR_COUNT=([0-9]+)$ ]]; then
      nginx_candidate="${BASH_REMATCH[1]}"
    fi
    if [[ "${runtime_error_lines[2]}" =~ ^PHP_FPM_RECENT_ERROR_COUNT=([0-9]+)$ ]]; then
      php_fpm_candidate="${BASH_REMATCH[1]}"
    fi
  fi
  if [[ "$nginx_candidate" =~ ^[0-9]+$ && "$php_fpm_candidate" =~ ^[0-9]+$ ]]; then
    nginx_recent_error_count="$nginx_candidate"
    php_fpm_recent_error_count="$php_fpm_candidate"
    recent_error_read_capability='PASS'
    recent_errors='NONE_MATERIAL'
    (( nginx_recent_error_count == 0 && php_fpm_recent_error_count == 0 )) || recent_errors='PRESENT_MATERIAL'
  fi
fi
unset runtime_error_output runtime_error_lines nginx_candidate php_fpm_candidate
# END #1197 GOVERNED RUNTIME ERROR OBSERVATION

public_live='FAIL'
public_ready='FAIL'
for endpoint in live ready; do
  body="$work_root/health-$endpoint.json"
  code="$(curl --silent --show-error --max-time 8 --output "$body" --write-out '%{http_code}' "$PROD_URL/health/$endpoint" || true)"
  if [[ "$code" == '200' ]] && jq -e '.status == "ok" and (keys | sort) == ["status"]' "$body" >/dev/null 2>&1; then
    printf -v "public_${endpoint}" '%s' 'PASS'
  fi
done
public_health='FAIL'
[[ "$public_live" == 'PASS' && "$public_ready" == 'PASS' ]] && public_health='PASS'

# BEGIN #1190 PUBLIC HOME PROBE
probe_public_home() {
  local body="$1"
  local meta code final_url parsed brand_marker html_marker body_marker main_marker
  PUBLIC_HOME='FAIL'
  PUBLIC_HOME_HTTP_CODE='UNKNOWN'
  PUBLIC_HOME_EFFECTIVE_PATH='UNKNOWN'
  meta="$(curl --silent --show-error --location --max-redirs 3 --connect-timeout 8 --max-time 15 \
    --output "$body" --write-out '%{http_code}|%{url_effective}' "$PROD_URL/" 2>/dev/null || true)"
  IFS='|' read -r code final_url <<<"$meta"
  [[ "$code" =~ ^[0-9]{3}$ ]] && PUBLIC_HOME_HTTP_CODE="$code"
  case "$final_url" in
    "$PROD_URL/fr") PUBLIC_HOME_EFFECTIVE_PATH='/fr' ;;
    "$PROD_URL/fr/") PUBLIC_HOME_EFFECTIVE_PATH='/fr/' ;;
    *) return 0 ;;
  esac
  [[ "$code" == '200' ]] || return 0
  parsed="$(python3 - "$body" <<'PY_HOME'
from html.parser import HTMLParser
import sys

class HomepageParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.brand = False
        self.html = False
        self.body = False
        self.main = False
    def handle_starttag(self, tag, attrs):
        tag = tag.lower()
        attributes = dict(attrs)
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
print(f"{'YES' if parser.brand else 'NO'}\t{'YES' if parser.html else 'NO'}\t{'YES' if parser.body else 'NO'}\t{'YES' if parser.main else 'NO'}")
PY_HOME
)"
  IFS=$'\t' read -r brand_marker html_marker body_marker main_marker <<<"$parsed"
  if [[ "$brand_marker" == 'YES' && "$html_marker" == 'YES' && "$body_marker" == 'YES' && "$main_marker" == 'YES' ]]; then
    PUBLIC_HOME='PASS'
  fi
}
# END #1190 PUBLIC HOME PROBE

probe_public_home "$work_root/home.html"
public_home="$PUBLIC_HOME"
public_home_http_code="$PUBLIC_HOME_HTTP_CODE"
public_home_effective_path="$PUBLIC_HOME_EFFECTIVE_PATH"
contact_form_surface='FAIL'
contact_code="$(curl --silent --show-error --max-time 10 --output "$work_root/contact.html" --write-out '%{http_code}' "$PROD_URL/fr/contact" || true)"
if [[ "$contact_code" == '200' ]] && grep -Eqi '<form|webform|contact' "$work_root/contact.html"; then
  contact_form_surface='PASS'
fi

disk_available_kb="$(df -Pk / | awk 'NR == 2 {print $4}')"
[[ "$disk_available_kb" =~ ^[0-9]+$ ]]

export MAIN_SHA PLAN_ID PLAN_CONTEXT ISSUE TARGET MODE TARGET_KERNEL
export OS_PRETTY_NAME="$os_pretty_name" VERSION_ID="$version_id"
export KERNEL_RUNNING="$kernel_running" KERNEL_INSTALLED_LATEST="$kernel_installed_latest" REBOOT_REQUIRED="$reboot_required"
export PHP_BRANCH="$php_branch" MARIADB_BRANCH="$mariadb_branch"
export NGINX_SERVICE="$nginx_service" PHP_FPM_SERVICE="$php_service" MARIADB_SERVICE="$mariadb_service"
export DRUPAL_HEALTH="$drupal_health" PUBLIC_HEALTH="$public_health"
export MAINTENANCE_MODE="$maintenance_mode" CONFIG_STATUS="$config_status" MAX_ALLOWED_PACKET="$max_allowed_packet"
export MAX_ALLOWED_PACKET_SOURCE="$max_allowed_packet_source"
export PUBLIC_HOME="$public_home" PUBLIC_HOME_HTTP_CODE="$public_home_http_code" PUBLIC_HOME_EFFECTIVE_PATH="$public_home_effective_path"
export CONTACT_FORM_SURFACE="$contact_form_surface" RECENT_NGINX_PHP_ERRORS="$recent_errors"
export RECENT_ERROR_READ_CAPABILITY="$recent_error_read_capability"
export NGINX_RECENT_ERROR_COUNT="$nginx_recent_error_count" PHP_FPM_RECENT_ERROR_COUNT="$php_fpm_recent_error_count"
export DISK_AVAILABLE_KB="$disk_available_kb" WORK_ROOT="$work_root"

python3 - <<'PY'
import hashlib
import json
import os
import re
import subprocess
import sys
from pathlib import Path

root = Path(os.environ['WORK_ROOT'])
context = os.environ['PLAN_CONTEXT']


def read_lines(name):
    path = root / name
    if not path.exists():
        return []
    return [line.rstrip('\n') for line in path.read_text(encoding='utf-8', errors='replace').splitlines() if line.strip()]


# Exact private sudo listing only; never execute the command being audited.
# Parser follows runtime-error-counts/remote-provision-plan.sh (#1208/#1212).
def probe_exact_sudo(args):
    expected = " ".join(args)
    try:
        result = subprocess.run(['sudo', '-k', '-n', '-ll', '--', *args],
                                capture_output=True, text=True, timeout=15,
                                env={**os.environ, 'LC_ALL': 'C'})
        policy, error, rc = result.stdout, result.stderr, result.returncode
    except (OSError, ValueError, subprocess.SubprocessError):
        return 'UNKNOWN', 'INTERNAL_PARSER_ERROR'
    pattern = (r"\s*Sudoers entry:(?: /[^\s]+)?\n[ \t]+RunAsUsers: root\n"
               r"(?:[ \t]+RunAsGroups: root\n)?"
               r"[ \t]+Options: ([^\n]+)\n"
               r"[ \t]+Commands:\n[ \t]+([^\n]+)\n"
               r"[ \t]*Matched: ([^\n]+)\s*")
    match = re.fullmatch(pattern, policy)
    options = [option.strip() for option in match[1].split(",")] if match else []
    allowed = {"!authenticate", "authenticate", "!setenv", "env_reset",
               "mail_badpass", "use_pty", "log_input", "log_output",
               "noexec", "!sudoedit_follow", "sudoedit_checkdir"}
    auth = set(options) & {"authenticate", "!authenticate"}
    safe_options = all(option in allowed or re.fullmatch(
        r"secure_path=/[A-Za-z0-9_./-]*(?::/[A-Za-z0-9_./-]*)*", option)
        for option in options)
    safe_args = bool(args) and all(re.fullmatch(r"[A-Za-z0-9_./=:+~\-]+", arg) for arg in args)
    exact = (match is not None and match[2] == expected and match[3] == expected
             and len(options) == len({option.split("=", 1)[0] for option in options})
             and len(auth) == 1 and safe_options and safe_args)
    state = "UNKNOWN"
    if rc == 0 and not error and exact:
        state = "AVAILABLE" if "!authenticate" in auth else "UNAVAILABLE"
    elif rc == 1 and not policy.strip() and safe_args:
        quote = chr(39)
        command = re.escape(quote + expected + quote)
        identity = r"[A-Za-z0-9_.-]+"
        denial = (r"(?:sudo: )?(?:Sorry, user " + identity
                  + r" is not allowed to execute " + command
                  + r" as root on " + identity + r"\.|User " + identity
                  + r" is not allowed to run " + command
                  + r" as root on " + identity + r"\.)\n?")
        if re.fullmatch(denial, error):
            state = "UNAVAILABLE"
    reason = "NONE"
    if state == "UNKNOWN":
        if "sudo: a password is required" in error:
            reason = "STDERR_PASSWORD_REQUIRED"
        elif error:
            reason = "STDERR_PRESENT"
        elif rc != 0:
            exit_kind = "ONE" if rc == 1 else "OTHER"
            policy_kind = "EMPTY" if not policy.strip() else "EXACT" if exact else "OTHER"
            reason = "EXIT_" + exit_kind + "_POLICY_" + policy_kind
        elif not policy.strip():
            reason = "POLICY_EMPTY"
        elif len(re.findall(r"(?m)^\s*Sudoers entry:", policy)) > 1:
            reason = "MULTIPLE_ENTRIES"
        elif not re.search(r"(?m)^[ \t]*Matched:", policy):
            reason = ("MATCHED_MISSING" if re.search(
                r"(?m)^\s*Sudoers entry:", policy) else "POLICY_FORMAT_UNSUPPORTED")
        elif re.search(r"(?m)^[ \t]+RunAs(?:Users|Groups): (?!root$)", policy):
            reason = "RUNAS_MISMATCH"
        elif match is None:
            reason = "POLICY_FORMAT_UNSUPPORTED"
        elif match[2] != expected or match[3] != expected or not safe_args:
            reason = "COMMAND_MISMATCH"
        elif len(auth) != 1 or any(options.count(tag) > 1 for tag in auth):
            reason = "AUTH_AMBIGUOUS"
        elif not safe_options or len(options) != len({
                option.split("=", 1)[0] for option in options}):
            reason = "OPTION_UNSUPPORTED"
        else:
            reason = "POLICY_FORMAT_UNSUPPORTED"
    return state, reason


upgradable = []
for line in read_lines('upgradable.raw'):
    if line.startswith('Listing'):
        continue
    match = re.match(r'^([^/]+)/\S+\s+(\S+)\s+\S+\s+\[upgradable from: ([^\]]+)\]', line)
    if not match:
        raise SystemExit(f'Unparseable apt upgradable line: {line[:160]}')
    name, candidate, installed = match.groups()
    upgradable.append({
        'name': name,
        'from': installed,
        'to': candidate,
        'security': 'security' in line.lower(),
    })


def parse_simulation(name):
    parsed_upgrades = []
    parsed_additions = []
    parsed_removals = []
    for line in read_lines(name):
        if line.startswith('Inst '):
            match = re.match(r'^Inst\s+(\S+)(?:\s+\[([^\]]+)\])?\s+\((\S+)', line)
            if not match:
                raise SystemExit(f'Unparseable apt simulation Inst line: {line[:160]}')
            package, installed, candidate = match.groups()
            item = {'name': package, 'from': installed or 'ABSENT', 'to': candidate}
            (parsed_upgrades if installed else parsed_additions).append(item)
        elif line.startswith('Remv '):
            parts = line.split()
            parsed_removals.append({'name': parts[1], 'from': parts[2] if len(parts) > 2 else 'UNKNOWN'})
    parsed_upgrades.sort(key=lambda item: item['name'])
    parsed_additions.sort(key=lambda item: item['name'])
    parsed_removals.sort(key=lambda item: item['name'])
    return parsed_upgrades, parsed_additions, parsed_removals


observed_normal_upgrades, additions, removals = parse_simulation('upgrade-sim.raw')
phased_upgrades, _, _ = parse_simulation('upgrade-sim-phased.raw')
upgradable.sort(key=lambda item: item['name'])
held = sorted(read_lines('held.raw'))
failed = sorted(read_lines('failed.raw'))
security_total = sum(1 for item in upgradable if item['security'])
selected_upgrades = []

upgradable_by_name = {item['name']: item for item in upgradable}
normal_names = {item['name'] for item in observed_normal_upgrades}
upgradable_names = set(upgradable_by_name)
missing_all = sorted(upgradable_names - normal_names)
unexpected_all = sorted(normal_names - upgradable_names)
phased_by_name = {item['name']: item for item in phased_upgrades}
phased_deferred_all = []
phased_deferred_security_all = []
unclassified_missing_all = []
for name in missing_all:
    candidate = upgradable_by_name[name]
    forced = phased_by_name.get(name)
    if forced and forced['to'] == candidate['to']:
        if candidate['security']:
            phased_deferred_security_all.append(name)
        else:
            phased_deferred_all.append({'name': name, 'from': candidate['from'], 'to': candidate['to']})
    else:
        unclassified_missing_all.append(name)
apt_policy_classification = not unclassified_missing_all and not unexpected_all and not phased_deferred_security_all
missing = missing_all[:50]
unexpected = unexpected_all[:50]
phased_deferred = phased_deferred_all[:50]
phased_deferred_security = phased_deferred_security_all[:50]
unclassified_missing = unclassified_missing_all[:50]

checks = {
    'ubuntu_24_04': os.environ['VERSION_ID'] == '24.04',
    'target_kernel_installed_latest': os.environ['KERNEL_INSTALLED_LATEST'] == os.environ['TARGET_KERNEL'],
    'php_branch_8_4': os.environ['PHP_BRANCH'] == '8.4',
    'mariadb_branch_11_8': os.environ['MARIADB_BRANCH'] == '11.8',
    'no_package_additions': not additions,
    'no_package_removals': not removals,
    'no_held_packages': not held,
    'no_failed_units': not failed,
    'nginx_active': os.environ['NGINX_SERVICE'] == 'active',
    'php_fpm_active': os.environ['PHP_FPM_SERVICE'] == 'active',
    'mariadb_active': os.environ['MARIADB_SERVICE'] == 'active',
    'drupal_health': os.environ['DRUPAL_HEALTH'] == 'PASS',
    'public_health': os.environ['PUBLIC_HEALTH'] == 'PASS',
    'maintenance_mode_off': os.environ['MAINTENANCE_MODE'] == '0',
    'config_status_observable': os.environ['CONFIG_STATUS'] in {'CLEAN', 'DIFFERENT'},
    'max_allowed_packet_64m': os.environ['MAX_ALLOWED_PACKET'] == '67108864',
    'max_allowed_packet_source_known': os.environ['MAX_ALLOWED_PACKET_SOURCE'] in {'DRUPAL_DB_API', 'SUDO_MARIADB'},
    'public_home': os.environ['PUBLIC_HOME'] == 'PASS',
    'contact_form_surface': os.environ['CONTACT_FORM_SURFACE'] == 'PASS',
    'recent_error_read_capability': os.environ['RECENT_ERROR_READ_CAPABILITY'] == 'PASS',
    'recent_nginx_php_errors': os.environ['RECENT_NGINX_PHP_ERRORS'] == 'NONE_MATERIAL',
    'disk_space_min_2gib': int(os.environ['DISK_AVAILABLE_KB']) >= 2 * 1024 * 1024,
    'security_updates_zero': security_total == 0,
}
if context == 'PLAN':
    checks['target_kernel_not_running'] = os.environ['KERNEL_RUNNING'] != os.environ['TARGET_KERNEL']
    checks['reboot_required_yes'] = os.environ['REBOOT_REQUIRED'] == 'YES'
else:
    checks['target_kernel_running'] = os.environ['KERNEL_RUNNING'] == os.environ['TARGET_KERNEL']
    checks['reboot_required_no'] = os.environ['REBOOT_REQUIRED'] == 'NO'

# BEGIN #1221 REBOOT-ONLY PRIVILEGE AUDIT
required_commands = {
    'SYSTEM_CONFIG_BACKUP': ['/usr/local/sbin/agency-prod-system-config-backup'],
    'RUNTIME_ERROR_HELPER': ['/usr/local/sbin/agency-prod-runtime-error-counts'],
    'REBOOT_HELPER': ['/usr/local/sbin/agency-prod-os-maintenance-1183-reboot'],
}
nonrequired_privileges = (
    'APT_UPDATE', 'APT_SIMULATE', 'APT_INSTALL',
    'NGINX_TEST', 'PHP_FPM_TEST',
    'NGINX_ACTIVE', 'PHP_FPM_ACTIVE', 'MARIADB_ACTIVE',
    'REBOOT',
)
privileges = {}
privilege_reasons = {}
for name, args in required_commands.items():
    state, reason = probe_exact_sudo(args)
    privileges['PRIVILEGED_' + name] = state
    privilege_reasons['WHY_UNKNOWN_' + name] = reason
    checks['privileged_' + name.lower()] = state == 'AVAILABLE'
for name in nonrequired_privileges:
    privileges['PRIVILEGED_' + name] = 'UNKNOWN'
    privilege_reasons['WHY_UNKNOWN_' + name] = 'NOT_REQUIRED'
# END #1221 REBOOT-ONLY PRIVILEGE AUDIT

failed_checks = sorted(name for name, value in checks.items() if not value)
safety_gate = 'FAIL' if failed_checks else 'PASS'
status = 'FAIL' if failed_checks else 'PASS'
reboot_only_eligible = 'YES' if context == 'PLAN' and not failed_checks else 'NO'

receipt = {
    **privileges,
    **privilege_reasons,
    'RAW_SUDO_POLICY_EXPOSURE': 'NONE',
    'schema_version': 1,
    'STATUS': status,
    'ISSUE': int(os.environ['ISSUE']),
    'TARGET': os.environ['TARGET'],
    'MODE': os.environ['MODE'],
    'PLAN_CONTEXT': context,
    'MAIN_SHA': os.environ['MAIN_SHA'],
    'PLAN_ID': os.environ['PLAN_ID'],
    'MAINTENANCE_ACTION': 'REBOOT_ONLY',
    'REBOOT_ONLY_ELIGIBLE': reboot_only_eligible,
    'TARGET_KERNEL': os.environ['TARGET_KERNEL'],
    'OS_PRETTY_NAME': os.environ['OS_PRETTY_NAME'],
    'VERSION_ID': os.environ['VERSION_ID'],
    'KERNEL_RUNNING': os.environ['KERNEL_RUNNING'],
    'KERNEL_INSTALLED_LATEST': os.environ['KERNEL_INSTALLED_LATEST'],
    'REBOOT_REQUIRED': os.environ['REBOOT_REQUIRED'],
    'UPGRADABLE_TOTAL': len(upgradable),
    'SECURITY_UPDATES_TOTAL': security_total,
    'UPGRADABLE_PACKAGES': upgradable,
    'APT_NORMAL_SELECTED_UPGRADES': observed_normal_upgrades,
    'APT_UPGRADE_SIMULATION': 'PASS' if apt_policy_classification else 'FAIL',
    'APT_SIMULATION_MISSING_UPGRADABLE': missing,
    'APT_PHASED_DEFERRED_PACKAGES': phased_deferred,
    'APT_PHASED_DEFERRED_SECURITY': phased_deferred_security,
    'APT_UNCLASSIFIED_MISSING_UPGRADABLE': unclassified_missing,
    'APT_SIMULATION_UNEXPECTED_UPGRADES': unexpected,
    'APT_POLICY_CLASSIFICATION': 'PASS' if apt_policy_classification else 'FAIL',
    'APT_UPDATE': 'NOT_REQUIRED',
    'APT_SIMULATE': 'NOT_REQUIRED',
    'APT_INSTALL': 'NOT_REQUIRED',
    'PACKAGE_REMOVALS': removals,
    'PACKAGE_ADDITIONS': additions,
    'PACKAGE_UPGRADES': selected_upgrades,
    'PACKAGE_UPGRADES_SELECTED_FOR_APPLY': selected_upgrades,
    'REAL_PACKAGE_MUTATION': 'NONE',
    'HELD_PACKAGES': held,
    'PHP_BRANCH': os.environ['PHP_BRANCH'],
    'MARIADB_BRANCH': os.environ['MARIADB_BRANCH'],
    'FAILED_SYSTEMD_UNITS': failed,
    'NGINX_SERVICE': os.environ['NGINX_SERVICE'].upper(),
    'PHP_FPM_SERVICE': os.environ['PHP_FPM_SERVICE'].upper(),
    'MARIADB_SERVICE': os.environ['MARIADB_SERVICE'].upper(),
    'DRUPAL_HEALTH': os.environ['DRUPAL_HEALTH'],
    'PUBLIC_HEALTH': os.environ['PUBLIC_HEALTH'],
    'MAINTENANCE_MODE': os.environ['MAINTENANCE_MODE'],
    'CONFIG_STATUS': os.environ['CONFIG_STATUS'],
    'CONFIG_AUTO_CORRECTION': 'NONE',
    'MAX_ALLOWED_PACKET': os.environ['MAX_ALLOWED_PACKET'] if os.environ['MAX_ALLOWED_PACKET'].isdigit() else 'UNKNOWN',
    'MAX_ALLOWED_PACKET_SOURCE': os.environ['MAX_ALLOWED_PACKET_SOURCE'] if os.environ['MAX_ALLOWED_PACKET_SOURCE'] in {'DRUPAL_DB_API', 'SUDO_MARIADB'} else 'UNKNOWN',
    'PUBLIC_HOME': os.environ['PUBLIC_HOME'],
    'PUBLIC_HOME_HTTP_CODE': int(os.environ['PUBLIC_HOME_HTTP_CODE']) if os.environ.get('PUBLIC_HOME_HTTP_CODE', '').isdigit() else 'UNKNOWN',
    'PUBLIC_HOME_EFFECTIVE_PATH': os.environ.get('PUBLIC_HOME_EFFECTIVE_PATH', 'UNKNOWN'),
    'CONTACT_FORM_SURFACE': os.environ['CONTACT_FORM_SURFACE'],
    'RECENT_ERROR_READ_CAPABILITY': os.environ['RECENT_ERROR_READ_CAPABILITY'] if os.environ['RECENT_ERROR_READ_CAPABILITY'] in {'PASS', 'FAIL'} else 'FAIL',
    'RECENT_NGINX_PHP_ERRORS': os.environ['RECENT_NGINX_PHP_ERRORS'],
    'NGINX_RECENT_ERROR_COUNT': int(os.environ['NGINX_RECENT_ERROR_COUNT']) if os.environ.get('NGINX_RECENT_ERROR_COUNT', '').isdigit() else 'UNKNOWN',
    'PHP_FPM_RECENT_ERROR_COUNT': int(os.environ['PHP_FPM_RECENT_ERROR_COUNT']) if os.environ.get('PHP_FPM_RECENT_ERROR_COUNT', '').isdigit() else 'UNKNOWN',
    'DISK_AVAILABLE_KB': int(os.environ['DISK_AVAILABLE_KB']),
    'SAFETY_GATE': safety_gate,
    'FAILED_CHECKS': failed_checks,
    'CANNOT_BE_APPROVED': 'YES' if failed_checks or context != 'PLAN' else 'NO',
    'REAL_PROD_MUTATION': 'NONE',
}

# Reboot-only stale identity deliberately excludes benign pending normal/phased
# update observations. Security state, the empty selected mutation set, kernels,
# safety/health state, and required bounded capabilities remain digest-bound.
mutation_identity_keys = (
    'schema_version', 'STATUS', 'ISSUE', 'TARGET', 'MODE', 'PLAN_CONTEXT',
    'MAIN_SHA', 'PLAN_ID', 'MAINTENANCE_ACTION', 'REBOOT_ONLY_ELIGIBLE',
    'TARGET_KERNEL', 'OS_PRETTY_NAME', 'VERSION_ID', 'KERNEL_RUNNING',
    'KERNEL_INSTALLED_LATEST', 'REBOOT_REQUIRED', 'SECURITY_UPDATES_TOTAL',
    'PACKAGE_REMOVALS', 'PACKAGE_ADDITIONS', 'PACKAGE_UPGRADES',
    'PACKAGE_UPGRADES_SELECTED_FOR_APPLY', 'REAL_PACKAGE_MUTATION',
    'HELD_PACKAGES', 'PHP_BRANCH', 'MARIADB_BRANCH', 'FAILED_SYSTEMD_UNITS',
    'NGINX_SERVICE', 'PHP_FPM_SERVICE', 'MARIADB_SERVICE', 'DRUPAL_HEALTH',
    'PUBLIC_HEALTH', 'MAINTENANCE_MODE', 'CONFIG_STATUS', 'CONFIG_AUTO_CORRECTION',
    'MAX_ALLOWED_PACKET', 'PUBLIC_HOME', 'CONTACT_FORM_SURFACE',
    'RECENT_ERROR_READ_CAPABILITY', 'RECENT_NGINX_PHP_ERRORS', 'SAFETY_GATE',
)
receipt['PLAN_DIGEST'] = None
if context == 'PLAN' and not failed_checks:
    mutation_identity = {key: receipt[key] for key in mutation_identity_keys}
    for key in ('PRIVILEGED_SYSTEM_CONFIG_BACKUP', 'PRIVILEGED_RUNTIME_ERROR_HELPER',
                'PRIVILEGED_REBOOT_HELPER'):
        mutation_identity[key] = receipt[key]
    canonical = json.dumps(mutation_identity, sort_keys=True, separators=(',', ':')).encode('utf-8')
    receipt['PLAN_DIGEST'] = hashlib.sha256(canonical).hexdigest()
print(json.dumps(receipt, sort_keys=True, separators=(',', ':')))
if failed_checks:
    print('PLAN safety gate failed: ' + ','.join(failed_checks), file=sys.stderr)
    raise SystemExit(2)
PY
