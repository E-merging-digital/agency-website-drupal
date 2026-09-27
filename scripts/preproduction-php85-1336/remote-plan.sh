#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MAIN_SHA="${1:-}"
PLAN_ID="${2:-}"
ISSUE='1336'
TARGET='PREPROD'
MODE='PLAN'
PREPROD_URL='https://preprod.emergingdigital.be'
DRUPAL_ROOT='/var/www/agency-preprod/current'
NGINX_VHOST='/etc/nginx/sites-available/agency-preprod'
FPM84_POOL='/etc/php/8.4/fpm/pool.d/agency-preprod.conf'
PHP84_SOCKET='/run/php/php8.4-fpm-agency-preprod.sock'

PHP85_PACKAGES=(
  php8.5-bcmath
  php8.5-cli
  php8.5-common
  php8.5-curl
  php8.5-fpm
  php8.5-gd
  php8.5-intl
  php8.5-mbstring
  php8.5-mysql
  php8.5-opcache
  php8.5-xml
  php8.5-zip
)

[[ "$MAIN_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$PLAN_ID" =~ ^plan-1336-[A-Za-z0-9._-]{8,80}$ ]]
for command_name in python3 apt-get apt-cache systemctl dpkg-query curl sha256sum; do
  command -v "$command_name" >/dev/null
done

work_root="$(mktemp -d)"
cleanup() { rm -rf -- "$work_root"; }
trap cleanup EXIT

# shellcheck disable=SC1091
source /etc/os-release
os_pretty_name="${PRETTY_NAME:-}"
version_id="${VERSION_ID:-}"
kernel_running="$(uname -r)"
reboot_required='NO'
[[ ! -f /var/run/reboot-required ]] || reboot_required='YES'

current_php_cli="$(php8.4 -r 'echo PHP_VERSION;' 2>/dev/null || true)"
current_php_fpm="$(php-fpm8.4 -v 2>/dev/null | head -n 1 || true)"
current_php_fpm_service="$(systemctl is-active php8.4-fpm 2>/dev/null || true)"
current_socket='ABSENT'
[[ -S "$PHP84_SOCKET" ]] && current_socket="$PHP84_SOCKET"
nginx_service="$(systemctl is-active nginx 2>/dev/null || true)"
mariadb_service="$(systemctl is-active mariadb 2>/dev/null || true)"
mariadb_version="$(mariadb --version 2>/dev/null | head -n 1 || true)"
systemctl --failed --no-legend --plain 2>/dev/null | awk 'NF {print $1}' | head -n 40 >"$work_root/failed.raw"

drupal_health='FAIL'
if [[ -x "$DRUPAL_ROOT/vendor/bin/drush" ]] && (cd "$DRUPAL_ROOT" && vendor/bin/drush status >/dev/null 2>&1); then
  drupal_health='PASS'
fi

public_health='FAIL'
public_live='FAIL'
public_ready='FAIL'
for endpoint in live ready; do
  body="$work_root/health-$endpoint.json"
  code="$(curl --silent --show-error --max-time 8 --output "$body" --write-out '%{http_code}' "$PREPROD_URL/health/$endpoint" || true)"
  if [[ "$code" == '200' ]] && python3 - "$body" <<'PY'
import json, sys
with open(sys.argv[1], encoding='utf-8') as handle:
    data=json.load(handle)
raise SystemExit(0 if data == {'status':'ok'} else 1)
PY
  then
    printf -v "public_${endpoint}" '%s' 'PASS'
  fi
done
[[ "$public_live" == 'PASS' && "$public_ready" == 'PASS' ]] && public_health='PASS'

disk_available_kb="$(df -Pk / | awk 'NR == 2 {print $4}')"
[[ "$disk_available_kb" =~ ^[0-9]+$ ]]

php84_packages_present='YES'
for pkg in php8.4-cli php8.4-fpm; do
  dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -qx 'install ok installed' || php84_packages_present='NO'
done
php84_service_active='NO'
[[ "$current_php_fpm_service" == 'active' ]] && php84_service_active='YES'

nginx_vhost_php84_socket_match='NO'
[[ -f "$NGINX_VHOST" ]] && [[ "$(grep -Foc "$PHP84_SOCKET" "$NGINX_VHOST" || true)" == '1' ]] && nginx_vhost_php84_socket_match='YES'
nginx_vhost_sha256="$(sha256sum "$NGINX_VHOST" 2>/dev/null | awk '{print $1}' || true)"

fpm84_pool_contract='NO'
if [[ -f "$FPM84_POOL" ]] \
  && grep -Eq '^\[agency-preprod\]$' "$FPM84_POOL" \
  && grep -Eq '^user = agency-preprod$' "$FPM84_POOL" \
  && grep -Eq '^group = www-data$' "$FPM84_POOL" \
  && grep -Fqx "listen = $PHP84_SOCKET" "$FPM84_POOL" \
  && grep -Eq '^listen\.owner = www-data$' "$FPM84_POOL" \
  && grep -Eq '^listen\.group = www-data$' "$FPM84_POOL" \
  && grep -Eq '^listen\.mode = 0660$' "$FPM84_POOL" \
  && grep -Eq '^clear_env = yes$' "$FPM84_POOL" \
  && grep -Fqx 'php_admin_value[sendmail_path] = /bin/true' "$FPM84_POOL"; then
  fpm84_pool_contract='YES'
fi
fpm84_pool_sha256="$(sha256sum "$FPM84_POOL" 2>/dev/null | awk '{print $1}' || true)"

sendmail_safety_contract='NO'
if [[ "$(php8.4 -r 'echo (string) ini_get("sendmail_path");' 2>/dev/null || true)" == '/bin/true' ]]; then
  sendmail_safety_contract='YES'
fi

: >"$work_root/candidates.tsv"
package_specs=()
candidate_gap='NO'
for pkg in "${PHP85_PACKAGES[@]}"; do
  candidate="$(apt-cache policy "$pkg" 2>/dev/null | awk '
    /Candidate:/ { candidate = $2 }
    END { if (candidate != "") print candidate }
  ')"
  if [[ -z "$candidate" || "$candidate" == '(none)' ]]; then
    candidate_gap='YES'
    candidate='NONE'
  else
    package_specs+=("$pkg=$candidate")
  fi
  printf '%s\t%s\n' "$pkg" "$candidate" >>"$work_root/candidates.tsv"
done

: >"$work_root/install-sim.raw"
if [[ "$candidate_gap" == 'NO' ]]; then
  apt-get --simulate install "${package_specs[@]}" >"$work_root/install-sim.raw" 2>&1
fi

export MAIN_SHA PLAN_ID ISSUE TARGET MODE
export OS_PRETTY_NAME="$os_pretty_name" VERSION_ID="$version_id" KERNEL_RUNNING="$kernel_running" REBOOT_REQUIRED="$reboot_required"
export CURRENT_PHP_CLI="$current_php_cli" CURRENT_PHP_FPM="$current_php_fpm" CURRENT_PHP_FPM_SERVICE="$current_php_fpm_service" CURRENT_PREPROD_SOCKET="$current_socket"
export NGINX_SERVICE="$nginx_service" MARIADB_SERVICE="$mariadb_service" MARIADB_VERSION="$mariadb_version"
export DRUPAL_HEALTH="$drupal_health" PUBLIC_HEALTH="$public_health" DISK_AVAILABLE_KB="$disk_available_kb"
export PHP84_PACKAGES_PRESENT="$php84_packages_present" PHP84_SERVICE_ACTIVE="$php84_service_active"
export NGINX_VHOST_PHP84_SOCKET_MATCH="$nginx_vhost_php84_socket_match" FPM84_POOL_CONTRACT="$fpm84_pool_contract" SENDMAIL_SAFETY_CONTRACT="$sendmail_safety_contract"
export NGINX_VHOST_SHA256="$nginx_vhost_sha256" FPM84_POOL_SHA256="$fpm84_pool_sha256"
export CANDIDATE_GAP="$candidate_gap" WORK_ROOT="$work_root"

python3 - <<'PY'
import hashlib
import json
import os
import re
from pathlib import Path

root = Path(os.environ['WORK_ROOT'])
requested_allowlist = [
    'php8.5-bcmath','php8.5-cli','php8.5-common','php8.5-curl',
    'php8.5-fpm','php8.5-gd','php8.5-intl','php8.5-mbstring',
    'php8.5-mysql','php8.5-opcache','php8.5-xml','php8.5-zip',
]
candidates = {}
for line in (root / 'candidates.tsv').read_text(encoding='utf-8').splitlines():
    name, version = line.split('\t', 1)
    candidates[name] = version

additions, upgrades, removals = [], [], []
for line in (root / 'install-sim.raw').read_text(encoding='utf-8', errors='replace').splitlines():
    if line.startswith('Inst '):
        match = re.match(r'^Inst\s+(\S+)(?:\s+\[([^\]]+)\])?\s+\((\S+)', line)
        if not match:
            raise SystemExit('Unparseable install simulation line: ' + line[:160])
        name, old, new = match.groups()
        item = {'name': name, 'from': old or 'ABSENT', 'to': new}
        (upgrades if old else additions).append(item)
    elif line.startswith('Remv '):
        parts = line.split()
        removals.append({'name': parts[1], 'from': parts[2] if len(parts) > 2 else 'UNKNOWN'})

additions.sort(key=lambda item:item['name'])
upgrades.sort(key=lambda item:item['name'])
removals.sort(key=lambda item:item['name'])
failed_units = sorted(
    line.strip() for line in (root / 'failed.raw').read_text(encoding='utf-8', errors='replace').splitlines()
    if line.strip()
)

addition_names = {item['name'] for item in additions}
requested_names = set(requested_allowlist)
transitive_additions = [
    item for item in additions if item['name'] not in requested_names
]
unexpected_transitive = [
    item for item in transitive_additions
    if re.fullmatch(r'php8\.5-[A-Za-z0-9.+-]+', item['name']) is None
]

checks = {
    'ubuntu_24_04': os.environ['VERSION_ID'] == '24.04',
    'reboot_not_required': os.environ['REBOOT_REQUIRED'] == 'NO',
    'current_php_cli_8_4': os.environ['CURRENT_PHP_CLI'].startswith('8.4.'),
    'current_php_fpm_8_4': 'PHP 8.4.' in os.environ['CURRENT_PHP_FPM'],
    'php84_service_active': os.environ['PHP84_SERVICE_ACTIVE'] == 'YES',
    'php84_packages_present': os.environ['PHP84_PACKAGES_PRESENT'] == 'YES',
    'current_socket_php84': os.environ['CURRENT_PREPROD_SOCKET'] == '/run/php/php8.4-fpm-agency-preprod.sock',
    'nginx_active': os.environ['NGINX_SERVICE'] == 'active',
    'mariadb_active': os.environ['MARIADB_SERVICE'] == 'active',
    'mariadb_11_8': '11.8.' in os.environ['MARIADB_VERSION'],
    'no_failed_units': not failed_units,
    'drupal_health': os.environ['DRUPAL_HEALTH'] == 'PASS',
    'public_health': os.environ['PUBLIC_HEALTH'] == 'PASS',
    'disk_space_min_2gib': int(os.environ['DISK_AVAILABLE_KB']) >= 2 * 1024 * 1024,
    'php85_candidates_present': os.environ['CANDIDATE_GAP'] == 'NO' and set(candidates) == requested_names and all(v != 'NONE' for v in candidates.values()),
    'all_requested_packages_present_in_simulation': requested_names <= addition_names,
    'package_removals_none': not removals,
    'unrelated_package_upgrades_none': not upgrades,
    'transitive_additions_php85_only': not unexpected_transitive,
    'nginx_vhost_php84_socket_match': os.environ['NGINX_VHOST_PHP84_SOCKET_MATCH'] == 'YES',
    'fpm84_pool_contract': os.environ['FPM84_POOL_CONTRACT'] == 'YES',
    'sendmail_safety_contract': os.environ['SENDMAIL_SAFETY_CONTRACT'] == 'YES',
}
failed_checks = sorted(name for name, passed in checks.items() if not passed)
safety_pass = not failed_checks

receipt = {
    'schema_version': 1,
    'STATUS': 'PASS' if safety_pass else 'FAIL',
    'ISSUE': 1336,
    'TARGET': 'PREPROD',
    'MODE': 'PLAN',
    'MAIN_SHA': os.environ['MAIN_SHA'],
    'PLAN_ID': os.environ['PLAN_ID'],
    'OS_PRETTY_NAME': os.environ['OS_PRETTY_NAME'],
    'VERSION_ID': os.environ['VERSION_ID'],
    'KERNEL_RUNNING': os.environ['KERNEL_RUNNING'],
    'REBOOT_REQUIRED': os.environ['REBOOT_REQUIRED'],
    'CURRENT_PHP_CLI': os.environ['CURRENT_PHP_CLI'],
    'CURRENT_PHP_FPM': os.environ['CURRENT_PHP_FPM'],
    'CURRENT_PHP_FPM_SERVICE': os.environ['CURRENT_PHP_FPM_SERVICE'].upper(),
    'CURRENT_PREPROD_SOCKET': os.environ['CURRENT_PREPROD_SOCKET'],
    'NGINX_SERVICE': os.environ['NGINX_SERVICE'].upper(),
    'MARIADB_SERVICE': os.environ['MARIADB_SERVICE'].upper(),
    'MARIADB_VERSION': os.environ['MARIADB_VERSION'],
    'FAILED_SYSTEMD_UNITS': failed_units,
    'DRUPAL_HEALTH': os.environ['DRUPAL_HEALTH'],
    'PUBLIC_HEALTH': os.environ['PUBLIC_HEALTH'],
    'DISK_AVAILABLE_KB': int(os.environ['DISK_AVAILABLE_KB']),
    'PHP85_PACKAGE_CANDIDATES': candidates,
    'PHP85_INSTALL_SIMULATION': 'PASS',
    'REQUESTED_PACKAGE_ALLOWLIST': requested_allowlist,
    'PACKAGE_ADDITIONS': additions,
    'TRANSITIVE_ADDITIONS': transitive_additions,
    'PACKAGE_UPGRADES': upgrades,
    'PACKAGE_REMOVALS': removals,
    'PHP84_PACKAGES_PRESENT': os.environ['PHP84_PACKAGES_PRESENT'],
    'PHP84_SERVICE_ACTIVE': os.environ['PHP84_SERVICE_ACTIVE'],
    'NGINX_VHOST_PHP84_SOCKET_MATCH': os.environ['NGINX_VHOST_PHP84_SOCKET_MATCH'],
    'NGINX_VHOST_SHA256': os.environ['NGINX_VHOST_SHA256'],
    'FPM84_POOL_CONTRACT': os.environ['FPM84_POOL_CONTRACT'],
    'FPM84_POOL_SHA256': os.environ['FPM84_POOL_SHA256'],
    'SENDMAIL_SAFETY_CONTRACT': os.environ['SENDMAIL_SAFETY_CONTRACT'],
    'SAFETY_GATE': 'PASS' if safety_pass else 'FAIL',
    'FAILED_CHECKS': failed_checks,
}
# Exact free disk is volatile: observe and safety-gate it, but exclude it from
# stale-plan mutation identity.
mutation_identity_keys = (
    'schema_version','STATUS','ISSUE','TARGET','MODE','MAIN_SHA','PLAN_ID',
    'OS_PRETTY_NAME','VERSION_ID','KERNEL_RUNNING','REBOOT_REQUIRED',
    'CURRENT_PHP_CLI','CURRENT_PHP_FPM','CURRENT_PHP_FPM_SERVICE',
    'CURRENT_PREPROD_SOCKET','NGINX_SERVICE','MARIADB_SERVICE','MARIADB_VERSION',
    'FAILED_SYSTEMD_UNITS','DRUPAL_HEALTH','PUBLIC_HEALTH',
    'PHP85_PACKAGE_CANDIDATES','PHP85_INSTALL_SIMULATION',
    'REQUESTED_PACKAGE_ALLOWLIST','PACKAGE_ADDITIONS','TRANSITIVE_ADDITIONS',
    'PACKAGE_UPGRADES','PACKAGE_REMOVALS',
    'PHP84_PACKAGES_PRESENT','PHP84_SERVICE_ACTIVE',
    'NGINX_VHOST_PHP84_SOCKET_MATCH','NGINX_VHOST_SHA256',
    'FPM84_POOL_CONTRACT','FPM84_POOL_SHA256','SENDMAIL_SAFETY_CONTRACT',
    'SAFETY_GATE','FAILED_CHECKS',
)
mutation_identity = {key: receipt[key] for key in mutation_identity_keys}
canonical = json.dumps(mutation_identity, sort_keys=True, separators=(',', ':')).encode('utf-8')
receipt['PLAN_DIGEST'] = hashlib.sha256(canonical).hexdigest()
print(json.dumps(receipt, sort_keys=True, separators=(',', ':')))
if failed_checks:
    raise SystemExit(65)
PY
