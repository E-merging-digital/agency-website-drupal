#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MAIN_SHA="${1:-}"
PLAN_ID="${2:-}"
SURFACE="${3:-}"
EXPECTED_USER="${4:-}"
ISSUE='1436'
MODE='PLAN'
TARGET_KERNEL='DECIDE_ONLY_AFTER_HUMAN_APPLY_AUTHORITY'
[[ "$MAIN_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$PLAN_ID" =~ ^plan-1436-[A-Za-z0-9._-]{8,80}$ ]]
[[ "$EXPECTED_USER" =~ ^[A-Za-z0-9._-]+$ ]]
case "$SURFACE" in
  preprod)
    TARGET='PREPROD'
    EXPECTED_HOST='agency-preprod-01'
    [[ "$EXPECTED_USER" == 'agency-preprod' ]]
    SITE_URL='https://preprod.emergingdigital.be'
    DRUPAL_ROOT='/var/www/agency-preprod/current'
    ;;
  prod)
    TARGET='PROD'
    EXPECTED_HOST='emergingdigital'
    SITE_URL='https://emergingdigital.be'
    DRUPAL_ROOT='/var/www/agency/current'
    ;;
  *) exit 64 ;;
esac
[[ "$(id -un)" == "$EXPECTED_USER" ]]
[[ "$(hostname -s)" == "$EXPECTED_HOST" ]]
for command_name in python3 apt-get apt apt-cache apt apt-mark systemctl dpkg-query curl jq; do
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
  -o 'APT::Update::Error-Mode=any'
)
if ! apt-get "${apt_options[@]}" update >"$apt_root/update.log" 2>&1; then
  printf '{"STATUS":"FAIL","ISSUE":1436,"TARGET":"%s","FAILURE_STAGE":"APT_SOURCE_OR_PERMISSION","SAFETY_GATE":"FAIL","REAL_PACKAGE_MUTATION":"NONE"}\n' "$TARGET"
  exit 76
fi
if ! apt "${apt_options[@]}" list --upgradable >"$work_root/upgradable.raw" 2>/dev/null; then
  printf '{"STATUS":"FAIL","ISSUE":1436,"TARGET":"%s","FAILURE_STAGE":"APT_LIST_UNAVAILABLE","SAFETY_GATE":"FAIL","REAL_PACKAGE_MUTATION":"NONE"}\n' "$TARGET"
  exit 76
fi
if ! apt-get "${apt_options[@]}" --simulate upgrade >"$work_root/upgrade-sim.raw" 2>&1; then
  printf '{"STATUS":"FAIL","ISSUE":1436,"TARGET":"%s","FAILURE_STAGE":"APT_UPGRADE_SIMULATION","SAFETY_GATE":"FAIL","REAL_PACKAGE_MUTATION":"NONE"}\n' "$TARGET"
  exit 76
fi
if ! apt-get "${apt_options[@]}" --simulate full-upgrade >"$work_root/full-upgrade-sim.raw" 2>&1; then
  printf '{"STATUS":"FAIL","ISSUE":1436,"TARGET":"%s","FAILURE_STAGE":"APT_FULL_UPGRADE_SIMULATION","SAFETY_GATE":"FAIL","REAL_PACKAGE_MUTATION":"NONE"}\n' "$TARGET"
  exit 76
fi
: >"$work_root/package-versions.raw"
for package in php8.5-cli php8.5-fpm php8.4-fpm mariadb-server mariadb-client nginx openssh-server composer certbot; do
  installed="$(dpkg-query -W -f='${Version}' "$package" 2>/dev/null || true)"
  candidate="$(apt-cache "${apt_options[@]}" policy "$package" 2>/dev/null | awk '/^[[:space:]]*Candidate:/ {value=$2} END {print value}')"
  printf '%s|%s|%s\n' "$package" "${installed:-NOT_INSTALLED}" "${candidate:-UNKNOWN}" >> "$work_root/package-versions.raw"
done
apt-mark showhold 2>/dev/null | head -n 40 >"$work_root/held.raw"
systemctl --failed --no-legend --plain 2>/dev/null | awk 'NF {print $1}' | head -n 40 >"$work_root/failed.raw"

nginx_service="$(systemctl is-active nginx 2>/dev/null || true)"
php_service="$(systemctl is-active php8.5-fpm 2>/dev/null || true)"
php84_rollback="$(dpkg-query -W -f='${Version}' php8.4-fpm 2>/dev/null || true)"
mariadb_service="$(systemctl is-active mariadb 2>/dev/null || true)"
php_version="$(php-fpm8.5 -v 2>/dev/null | head -n 1 || true)"
mariadb_version="$(mariadb --version 2>/dev/null | head -n 1 || true)"
php_branch="$(sed -nE 's/^PHP ([0-9]+\.[0-9]+).*/\1/p' <<<"$php_version" | head -n 1)"
mariadb_branch="$(grep -oE '[0-9]+\.[0-9]+\.[0-9]+-MariaDB' <<<"$mariadb_version" | head -n 1 | cut -d. -f1-2)"

drupal_health='FAIL'
if [[ -x "$DRUPAL_ROOT/vendor/bin/drush" ]] && (cd "$DRUPAL_ROOT" && vendor/bin/drush status >/dev/null 2>&1); then
  drupal_health='PASS'
fi

public_live='FAIL'
public_ready='FAIL'
for endpoint in live ready; do
  body="$work_root/health-$endpoint.json"
  code="$(curl --silent --show-error --max-time 8 --output "$body" --write-out '%{http_code}' "$SITE_URL/health/$endpoint" || true)"
  if [[ "$code" == '200' ]] && jq -e '.status == "ok" and (keys | sort) == ["status"]' "$body" >/dev/null 2>&1; then
    printf -v "public_${endpoint}" '%s' 'PASS'
  fi
done
public_health='FAIL'
[[ "$public_live" == 'PASS' && "$public_ready" == 'PASS' ]] && public_health='PASS'

disk_available_kb="$(df -Pk / | awk 'NR == 2 {print $4}')"
[[ "$disk_available_kb" =~ ^[0-9]+$ ]]

export MAIN_SHA PLAN_ID ISSUE TARGET MODE TARGET_KERNEL
export OS_PRETTY_NAME="$os_pretty_name" VERSION_ID="$version_id"
export KERNEL_RUNNING="$kernel_running" KERNEL_INSTALLED_LATEST="$kernel_installed_latest" REBOOT_REQUIRED="$reboot_required"
export PHP_BRANCH="$php_branch" MARIADB_BRANCH="$mariadb_branch" PHP84_ROLLBACK="$php84_rollback" EXPECTED_HOST EXPECTED_USER
export NGINX_SERVICE="$nginx_service" PHP_FPM_SERVICE="$php_service" MARIADB_SERVICE="$mariadb_service"
export DRUPAL_HEALTH="$drupal_health" PUBLIC_HEALTH="$public_health"
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

def read_lines(name):
    path = root / name
    if not path.exists():
        return []
    return [line.rstrip('\n') for line in path.read_text(encoding='utf-8', errors='replace').splitlines() if line.strip()]

upgradable = []
for line in read_lines('upgradable.raw'):
    if line.startswith('Listing'):
        continue
    m = re.match(r'^([^/]+)/\S+\s+(\S+)\s+\S+\s+\[upgradable from: ([^\]]+)\]', line)
    if not m:
        raise SystemExit(f'Unparseable apt upgradable line: {line[:160]}')
    name, candidate, installed = m.groups()
    upgradable.append({
        'name': name,
        'from': installed,
        'to': candidate,
        'security': 'security' in line.lower(),
    })

def parse_simulation(lines):
    changes = []
    additions = []
    removals = []
    for line in lines:
        if line.startswith('Inst '):
            m = re.match(r'^Inst\s+(\S+)(?:\s+\[([^\]]+)\])?\s+\((\S+)', line)
            if not m:
                raise SystemExit('Unparseable apt simulated installation')
            name, installed, candidate = m.groups()
            item = {'name': name, 'from': installed or 'ABSENT', 'to': candidate}
            (changes if installed else additions).append(item)
        elif line.startswith('Remv '):
            parts = line.split()
            if len(parts) < 2:
                raise SystemExit('Unparseable apt simulated removal')
            removals.append({'name': parts[1], 'from': parts[2] if len(parts) > 2 else 'UNKNOWN'})
    for values in (changes, additions, removals):
        values.sort(key=lambda item: item['name'])
    return changes, additions, removals

upgrades, additions, removals = parse_simulation(read_lines('upgrade-sim.raw'))
full_upgrades, full_additions, full_removals = parse_simulation(read_lines('full-upgrade-sim.raw'))
upgradable.sort(key=lambda item: item['name'])
held = sorted(read_lines('held.raw'))
failed = sorted(read_lines('failed.raw'))

upgradable_names = {item['name'] for item in upgradable}
upgrade_names = {item['name'] for item in upgrades}
if upgradable_names != upgrade_names:
    missing = sorted(upgradable_names - upgrade_names)
    unexpected = sorted(upgrade_names - upgradable_names)
    raise SystemExit(f'APT simulation does not exactly cover upgradable set; missing={missing}, unexpected={unexpected}')

checks = {
    'ubuntu_24_04': os.environ['VERSION_ID'] == '24.04',
    'kernel_inventory_present': bool(os.environ['KERNEL_RUNNING']) and bool(os.environ['KERNEL_INSTALLED_LATEST']),
    'host_and_user_bounded': os.environ['EXPECTED_HOST'] in ('agency-preprod-01', 'emergingdigital') and bool(os.environ['EXPECTED_USER']),
    'php_branch_8_5': os.environ['PHP_BRANCH'] == '8.5',
    'php_8_4_rollback_installed': bool(os.environ['PHP84_ROLLBACK']),
    'mariadb_branch_11_8': os.environ['MARIADB_BRANCH'] == '11.8',
    'no_package_additions': not additions,
    'no_package_removals': not removals,
    'no_full_upgrade_removals': not full_removals,
    'no_held_packages': not held,
    'no_failed_units': not failed,
    'nginx_active': os.environ['NGINX_SERVICE'] == 'active',
    'php_fpm_active': os.environ['PHP_FPM_SERVICE'] == 'active',
    'mariadb_active': os.environ['MARIADB_SERVICE'] == 'active',
    'drupal_health': os.environ['DRUPAL_HEALTH'] == 'PASS',
    'public_health': os.environ['PUBLIC_HEALTH'] == 'PASS',
    'disk_space_min_2gib': int(os.environ['DISK_AVAILABLE_KB']) >= 2 * 1024 * 1024,
}

# The operation set itself is the allowlist. Branch-changing package names are rejected.
for item in upgrades:
    name = item['name']
    if re.match(r'^php[0-9]+\.[0-9]+(?:-|$)', name) and not name.startswith(('php8.4', 'php8.5')):
        checks[f'php_package_branch:{name}'] = False
    if name.startswith('mariadb-') and '11.8' not in item['to']:
        checks[f'mariadb_candidate_branch:{name}'] = False

if not all(checks.values()):
    failed_checks = sorted(name for name, value in checks.items() if not value)
    raise SystemExit('PLAN safety gate failed: ' + ','.join(failed_checks))

receipt = {
    'schema_version': 1,
    'STATUS': 'PASS',
    'ISSUE': int(os.environ['ISSUE']),
    'TARGET': os.environ['TARGET'],
    'EXPECTED_HOST': os.environ['EXPECTED_HOST'],
    'EXPECTED_USER': os.environ['EXPECTED_USER'],
    'MODE': os.environ['MODE'],
    'MAIN_SHA': os.environ['MAIN_SHA'],
    'PLAN_ID': os.environ['PLAN_ID'],
    'OS_PRETTY_NAME': os.environ['OS_PRETTY_NAME'],
    'VERSION_ID': os.environ['VERSION_ID'],
    'KERNEL_RUNNING': os.environ['KERNEL_RUNNING'],
    'KERNEL_INSTALLED_LATEST': os.environ['KERNEL_INSTALLED_LATEST'],
    'REBOOT_REQUIRED': os.environ['REBOOT_REQUIRED'],
    'UPGRADABLE_TOTAL': len(upgradable),
    'SECURITY_UPDATES_TOTAL': sum(1 for item in upgradable if item['security']),
    'UPGRADABLE_PACKAGES': upgradable,
    'APT_UPGRADE_SIMULATION': 'PASS',
    'APT_FULL_UPGRADE_SIMULATION': 'PASS',
    'PACKAGE_VERSIONS': {},
    'PHP84_ROLLBACK': os.environ['PHP84_ROLLBACK'],
    'REAL_PACKAGE_MUTATION': 'NONE',
    'APT_STATE_MUTATION': 'ISOLATED_TEMPORARY_ONLY',
    'PACKAGE_REMOVALS': removals,
    'PACKAGE_ADDITIONS': additions,
    'PACKAGE_UPGRADES': upgrades,
    'FULL_PACKAGE_UPGRADES': full_upgrades,
    'FULL_PACKAGE_ADDITIONS': full_additions,
    'FULL_PACKAGE_REMOVALS': full_removals,
    'FULL_UPGRADE_ADDITIONS_REQUIRE_REVIEW': bool(full_additions),
    'HELD_PACKAGES': held,
    'PHP_BRANCH': os.environ['PHP_BRANCH'],
    'MARIADB_BRANCH': os.environ['MARIADB_BRANCH'],
    'FAILED_SYSTEMD_UNITS': failed,
    'NGINX_SERVICE': os.environ['NGINX_SERVICE'].upper(),
    'PHP_FPM_SERVICE': os.environ['PHP_FPM_SERVICE'].upper(),
    'MARIADB_SERVICE': os.environ['MARIADB_SERVICE'].upper(),
    'DRUPAL_HEALTH': os.environ['DRUPAL_HEALTH'],
    'PUBLIC_HEALTH': os.environ['PUBLIC_HEALTH'],
    'DISK_AVAILABLE_KB': int(os.environ['DISK_AVAILABLE_KB']),
    'SAFETY_GATE': 'PASS',
}
for line in read_lines('package-versions.raw'):
    fields = line.split('|')
    if len(fields) != 3:
        raise SystemExit('Unparseable package version receipt')
    name, installed, candidate = fields
    if not re.fullmatch(r'[a-z0-9.+-]+', name):
        raise SystemExit('Unexpected package name')
    receipt['PACKAGE_VERSIONS'][name] = {
        'installed': installed,
        'candidate': candidate,
    }

# Keep volatile observations in the receipt and safety gate, but bind stale-plan
# identity only to mutation-relevant state. Healthy free-space fluctuations must
# not invalidate an otherwise identical approved operation set.
mutation_identity_keys = (
    'schema_version',
    'STATUS',
    'ISSUE',
    'TARGET',
    'EXPECTED_HOST',
    'EXPECTED_USER',
    'MODE',
    'MAIN_SHA',
    'PLAN_ID',
    'OS_PRETTY_NAME',
    'VERSION_ID',
    'KERNEL_RUNNING',
    'KERNEL_INSTALLED_LATEST',
    'REBOOT_REQUIRED',
    'UPGRADABLE_TOTAL',
    'SECURITY_UPDATES_TOTAL',
    'UPGRADABLE_PACKAGES',
    'APT_UPGRADE_SIMULATION',
    'APT_FULL_UPGRADE_SIMULATION',
    'PACKAGE_VERSIONS',
    'PHP84_ROLLBACK',
    'REAL_PACKAGE_MUTATION',
    'APT_STATE_MUTATION',
    'PACKAGE_REMOVALS',
    'PACKAGE_ADDITIONS',
    'PACKAGE_UPGRADES',
    'FULL_PACKAGE_UPGRADES',
    'FULL_PACKAGE_ADDITIONS',
    'FULL_PACKAGE_REMOVALS',
    'FULL_UPGRADE_ADDITIONS_REQUIRE_REVIEW',
    'HELD_PACKAGES',
    'PHP_BRANCH',
    'MARIADB_BRANCH',
    'FAILED_SYSTEMD_UNITS',
    'NGINX_SERVICE',
    'PHP_FPM_SERVICE',
    'MARIADB_SERVICE',
    'DRUPAL_HEALTH',
    'PUBLIC_HEALTH',
    'SAFETY_GATE',
)
mutation_identity = {key: receipt[key] for key in mutation_identity_keys}
canonical = json.dumps(mutation_identity, sort_keys=True, separators=(',', ':')).encode('utf-8')
receipt['PLAN_DIGEST'] = hashlib.sha256(canonical).hexdigest()
print(json.dumps(receipt, sort_keys=True, separators=(',', ':')))
PY
