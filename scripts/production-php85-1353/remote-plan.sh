#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MAIN_SHA="${1:-}"
PLAN_ID="${2:-}"
ISSUE='1353'
TARGET='PROD'
MODE='PLAN'
PROD_URL='https://emergingdigital.be'
DRUPAL_ROOT='/var/www/agency/current'
NGINX_SITES_ENABLED='/etc/nginx/sites-enabled'
FPM84_POOL_DIR='/etc/php/8.4/fpm/pool.d'

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
  php8.5-xml
  php8.5-zip
)

[[ "$MAIN_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$PLAN_ID" =~ ^plan-1353-[A-Za-z0-9._-]{8,80}$ ]]
for command_name in python3 apt-get apt-cache systemctl dpkg-query curl sha256sum readlink stat mariadb uname php8.4 php-fpm8.4; do
  command -v "$command_name" >/dev/null
done

work_root="$(mktemp -d)"
cleanup() { rm -rf -- "$work_root"; }
trap cleanup EXIT

current_release="$(readlink -f "$DRUPAL_ROOT" 2>/dev/null || true)"
if [[ ! "$current_release" =~ ^/var/www/agency/releases/[A-Za-z0-9._-]+$ ]]; then
  current_release='INVALID'
fi

# shellcheck disable=SC1091
source /etc/os-release
os_pretty_name="${PRETTY_NAME:-}"
version_id="${VERSION_ID:-}"
kernel_running="$(uname -r)"
reboot_required='NO'
[[ ! -f /var/run/reboot-required ]] || reboot_required='YES'

reboot_required_packages_source='ABSENT'
: >"$work_root/reboot-required-packages.raw"
if [[ -f /var/run/reboot-required.pkgs ]]; then
  reboot_required_packages_source='PRESENT'
  python3 - /var/run/reboot-required.pkgs >"$work_root/reboot-required-packages.raw" <<'PY_REBOOT'
import re
import sys
from pathlib import Path
names = []
for line in Path(sys.argv[1]).read_text(encoding='utf-8', errors='replace').splitlines():
    name = line.strip().split()[0] if line.strip() else ''
    if re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.+:-]{0,127}', name):
        names.append(name)
for name in sorted(set(names))[:100]:
    print(name)
PY_REBOOT
fi

current_php_cli="$(php8.4 -r 'echo PHP_VERSION;' 2>/dev/null || true)"
current_php_fpm="$(php-fpm8.4 -v 2>/dev/null | head -n 1 || true)"
current_php_fpm_service="$(systemctl is-active php8.4-fpm 2>/dev/null || true)"
current_socket='ABSENT'
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
  code="$(curl --silent --show-error --max-time 8 --output "$body" --write-out '%{http_code}' "$PROD_URL/health/$endpoint" || true)"
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
nginx_vhost_path='ABSENT'
nginx_vhost_owner=''
nginx_vhost_group=''
nginx_vhost_mode=''
nginx_vhost_sha256=''
: >"$work_root/nginx-vhosts.raw"
python3 - "$NGINX_SITES_ENABLED" >"$work_root/nginx-vhosts.raw" <<'PY_VHOST'
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
matches = set()
if root.is_dir():
    for entry in root.iterdir():
        try:
            resolved = entry.resolve(strict=True)
        except OSError:
            continue
        if resolved.parent != Path('/etc/nginx/sites-available') or not resolved.is_file():
            continue
        try:
            text = resolved.read_text(encoding='utf-8', errors='strict')
        except OSError:
            continue
        for line in text.splitlines():
            clean = re.sub(r'#.*$', '', line).strip()
            match = re.match(r'^server_name\s+(.+);$', clean)
            if match and ({'emergingdigital.be', 'www.emergingdigital.be'} & set(match.group(1).split())):
                matches.add(str(resolved))
for path in sorted(matches):
    print(path)
PY_VHOST

: >"$work_root/nginx-fastcgi-pass.raw"
if [[ "$(wc -l <"$work_root/nginx-vhosts.raw")" -eq 1 ]]; then
  nginx_vhost_path="$(cat "$work_root/nginx-vhosts.raw")"
  nginx_vhost_owner="$(stat -c '%U' "$nginx_vhost_path" 2>/dev/null || true)"
  nginx_vhost_group="$(stat -c '%G' "$nginx_vhost_path" 2>/dev/null || true)"
  nginx_vhost_mode_raw="$(stat -c '%a' "$nginx_vhost_path" 2>/dev/null || true)"
  case "$nginx_vhost_mode_raw" in
    [0-7][0-7][0-7]) nginx_vhost_mode="0$nginx_vhost_mode_raw" ;;
    [0-7][0-7][0-7][0-7]) nginx_vhost_mode="$nginx_vhost_mode_raw" ;;
  esac
  nginx_vhost_sha256="$(sha256sum "$nginx_vhost_path" 2>/dev/null | awk '{print $1}' || true)"
  python3 - "$nginx_vhost_path" >"$work_root/nginx-fastcgi-pass.raw" <<'PY_NGINX'
import re
import sys
from pathlib import Path

values = set()
for line in Path(sys.argv[1]).read_text(encoding='utf-8', errors='replace').splitlines():
    if 'fastcgi_pass' not in line:
        continue
    match = re.match(r"^\s*fastcgi_pass\s+([^;\s]{1,256})\s*;\s*(?:#.*)?\Z", line)
    if match:
        values.add(match.group(1))
for value in sorted(values)[:20]:
    print(value)
PY_NGINX
fi
if [[ "$(wc -l <"$work_root/nginx-fastcgi-pass.raw")" -eq 1 ]]; then
  fastcgi_target="$(cat "$work_root/nginx-fastcgi-pass.raw")"
  if [[ "$fastcgi_target" =~ ^unix:(/run/php/php8\.4-fpm[A-Za-z0-9._-]*\.sock)$ ]]; then
    current_socket="${BASH_REMATCH[1]}"
    nginx_vhost_php84_socket_match='YES'
  fi
fi

fpm84_pool_contract='NO'
fpm84_pool_path='ABSENT'
fpm84_pool_sha256=''
: >"$work_root/fpm84-pools.raw"
if [[ "$current_socket" != 'ABSENT' ]]; then
  python3 - "$FPM84_POOL_DIR" "$current_socket" >"$work_root/fpm84-pools.raw" <<'PY_FPM_FIND'
import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
socket = sys.argv[2]
for path in sorted(root.glob('*.conf')) if root.is_dir() else []:
    directives = {}
    try:
        lines = path.read_text(encoding='utf-8', errors='strict').splitlines()
    except OSError:
        continue
    for line in lines:
        clean = line.strip()
        if not clean or clean.startswith((';', '#', '[')):
            continue
        match = re.match(r'^([A-Za-z0-9_.]+)\s*=\s*(.*?)\s*$', clean)
        if match:
            directives[match.group(1)] = match.group(2)
    if directives.get('listen') == socket:
        print(path)
PY_FPM_FIND
fi
if [[ "$(wc -l <"$work_root/fpm84-pools.raw")" -eq 1 ]]; then
  fpm84_pool_path="$(cat "$work_root/fpm84-pools.raw")"
  fpm84_pool_sha256="$(sha256sum "$fpm84_pool_path" 2>/dev/null | awk '{print $1}' || true)"
  if python3 - "$fpm84_pool_path" "$current_socket" <<'PY_FPM_CONTRACT'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
socket = sys.argv[2]
directives = {}
for line in path.read_text(encoding='utf-8', errors='strict').splitlines():
    clean = line.strip()
    if not clean or clean.startswith((';', '#', '[')):
        continue
    match = re.match(r'^([A-Za-z0-9_.]+)\s*=\s*(.*?)\s*$', clean)
    if match:
        directives[match.group(1)] = match.group(2)
ok = (
    directives.get('user') == 'www-data'
    and directives.get('group') == 'www-data'
    and directives.get('listen') == socket
    and directives.get('listen.owner', 'www-data') == 'www-data'
    and directives.get('listen.group', 'www-data') == 'www-data'
    and directives.get('listen.mode', '0660') == '0660'
)
raise SystemExit(0 if ok else 1)
PY_FPM_CONTRACT
  then
    fpm84_pool_contract='YES'
  fi
fi

: >"$work_root/candidates.tsv"
: >"$work_root/installed.tsv"
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

  installed_version='ABSENT'
  if dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -qx 'install ok installed'; then
    installed_version="$(dpkg-query -W -f='${Version}' "$pkg" 2>/dev/null || true)"
    [[ -n "$installed_version" ]] || installed_version='INVALID'
  fi
  printf '%s\t%s\n' "$pkg" "$installed_version" >>"$work_root/installed.tsv"
done

: >"$work_root/install-sim.raw"
php85_install_simulation='NOT_RUN'
if [[ "$candidate_gap" == 'NO' ]]; then
  set +e
  apt-get --simulate install "${package_specs[@]}" >"$work_root/install-sim.raw" 2>&1
  install_sim_rc=$?
  set -e
  if [[ "$install_sim_rc" -eq 0 ]]; then
    php85_install_simulation='PASS'
  else
    php85_install_simulation='FAIL'
  fi
fi

export MAIN_SHA PLAN_ID ISSUE TARGET MODE
export CURRENT_RELEASE="$current_release"
export OS_PRETTY_NAME="$os_pretty_name" VERSION_ID="$version_id" KERNEL_RUNNING="$kernel_running" REBOOT_REQUIRED="$reboot_required"
export REBOOT_REQUIRED_PACKAGES_SOURCE="$reboot_required_packages_source"
export CURRENT_PHP_CLI="$current_php_cli" CURRENT_PHP_FPM="$current_php_fpm" CURRENT_PHP_FPM_SERVICE="$current_php_fpm_service" CURRENT_PROD_SOCKET="$current_socket"
export NGINX_SERVICE="$nginx_service" MARIADB_SERVICE="$mariadb_service" MARIADB_VERSION="$mariadb_version"
export DRUPAL_HEALTH="$drupal_health" PUBLIC_HEALTH="$public_health" DISK_AVAILABLE_KB="$disk_available_kb"
export PHP84_PACKAGES_PRESENT="$php84_packages_present" PHP84_SERVICE_ACTIVE="$php84_service_active"
export NGINX_VHOST_PHP84_SOCKET_MATCH="$nginx_vhost_php84_socket_match" FPM84_POOL_CONTRACT="$fpm84_pool_contract"
export NGINX_VHOST_PATH="$nginx_vhost_path" FPM84_POOL_PATH="$fpm84_pool_path"
export NGINX_VHOST_OWNER="$nginx_vhost_owner" NGINX_VHOST_GROUP="$nginx_vhost_group" NGINX_VHOST_MODE="$nginx_vhost_mode"
export NGINX_VHOST_SHA256="$nginx_vhost_sha256" FPM84_POOL_SHA256="$fpm84_pool_sha256"
export CANDIDATE_GAP="$candidate_gap" PHP85_INSTALL_SIMULATION="$php85_install_simulation" WORK_ROOT="$work_root"

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
    'php8.5-mysql','php8.5-xml','php8.5-zip',
]
candidates = {}
for line in (root / 'candidates.tsv').read_text(encoding='utf-8').splitlines():
    name, version = line.split('\t', 1)
    candidates[name] = version

installed_versions = {}
for line in (root / 'installed.tsv').read_text(encoding='utf-8').splitlines():
    name, version = line.split('\t', 1)
    installed_versions[name] = version

additions, upgrades, removals = [], [], []
install_simulation_state = os.environ['PHP85_INSTALL_SIMULATION']
if install_simulation_state == 'PASS':
    for line in (root / 'install-sim.raw').read_text(encoding='utf-8', errors='replace').splitlines():
        if line.startswith('Inst '):
            match = re.match(r'^Inst\s+(\S+)(?:\s+\[([^\]]+)\])?\s+\((\S+)', line)
            if not match:
                install_simulation_state = 'FAIL'
                additions, upgrades, removals = [], [], []
                break
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
reboot_required_packages = sorted(
    line.strip() for line in (root / 'reboot-required-packages.raw').read_text(encoding='utf-8', errors='replace').splitlines()
    if line.strip()
)
nginx_fastcgi_pass_values = sorted(
    set(
        line.strip() for line in (root / 'nginx-fastcgi-pass.raw').read_text(encoding='utf-8', errors='replace').splitlines()
        if line.strip()
    )
)

requested_names = set(requested_allowlist)
requested_packages_converge = set(installed_versions) == requested_names
if requested_packages_converge:
    for name in requested_allowlist:
        candidate = candidates.get(name, 'NONE')
        installed = installed_versions[name]
        matching_additions = [
            item for item in additions if item['name'] == name
        ]
        if candidate == 'NONE':
            requested_packages_converge = False
            break
        if installed == candidate:
            if matching_additions:
                requested_packages_converge = False
                break
            continue
        if installed == 'ABSENT':
            if (
                len(matching_additions) != 1
                or matching_additions[0]['to'] != candidate
            ):
                requested_packages_converge = False
                break
            continue
        requested_packages_converge = False
        break
transitive_additions = [
    item for item in additions if item['name'] not in requested_names
]
unexpected_transitive = [
    item for item in transitive_additions
    if re.fullmatch(r'php8\.5-[A-Za-z0-9.+-]+', item['name']) is None
]

checks = {
    'ubuntu_24_04': os.environ['VERSION_ID'] == '24.04',
    'current_php_cli_8_4': os.environ['CURRENT_PHP_CLI'].startswith('8.4.'),
    'current_php_fpm_8_4': 'PHP 8.4.' in os.environ['CURRENT_PHP_FPM'],
    'php84_service_active': os.environ['PHP84_SERVICE_ACTIVE'] == 'YES',
    'php84_packages_present': os.environ['PHP84_PACKAGES_PRESENT'] == 'YES',
    'current_release_valid': re.fullmatch(r'/var/www/agency/releases/[A-Za-z0-9._-]+', os.environ['CURRENT_RELEASE']) is not None,
    'current_socket_php84': re.fullmatch(r'/run/php/php8\.4-fpm[A-Za-z0-9._-]*\.sock', os.environ['CURRENT_PROD_SOCKET']) is not None,
    'nginx_active': os.environ['NGINX_SERVICE'] == 'active',
    'mariadb_active': os.environ['MARIADB_SERVICE'] == 'active',
    'mariadb_11_8': '11.8.' in os.environ['MARIADB_VERSION'],
    'no_failed_units': not failed_units,
    'drupal_health': os.environ['DRUPAL_HEALTH'] == 'PASS',
    'public_health': os.environ['PUBLIC_HEALTH'] == 'PASS',
    'disk_space_min_2gib': int(os.environ['DISK_AVAILABLE_KB']) >= 2 * 1024 * 1024,
    'php85_candidates_present': os.environ['CANDIDATE_GAP'] == 'NO' and set(candidates) == requested_names and all(v != 'NONE' for v in candidates.values()),
    'php85_install_simulation_pass': install_simulation_state == 'PASS',
    'all_requested_packages_converge_to_candidate': requested_packages_converge,
    'package_removals_none': not removals,
    'unrelated_package_upgrades_none': not upgrades,
    'transitive_additions_php85_only': not unexpected_transitive,
    'nginx_vhost_php84_socket_match': (
        os.environ['NGINX_VHOST_PHP84_SOCKET_MATCH'] == 'YES'
        and os.environ['CURRENT_PROD_SOCKET'] != 'ABSENT'
        and nginx_fastcgi_pass_values == ['unix:' + os.environ['CURRENT_PROD_SOCKET']]
    ),
    'fpm84_pool_contract': os.environ['FPM84_POOL_CONTRACT'] == 'YES',
}
failed_checks = sorted(name for name, passed in checks.items() if not passed)
safety_pass = not failed_checks
php85_installable = (
    install_simulation_state == 'PASS'
    and requested_packages_converge
    and not removals
    and not upgrades
    and not unexpected_transitive
)
rollback_php84_available = (
    os.environ['PHP84_PACKAGES_PRESENT'] == 'YES'
    and os.environ['PHP84_SERVICE_ACTIVE'] == 'YES'
    and os.environ['NGINX_VHOST_PHP84_SOCKET_MATCH'] == 'YES'
    and os.environ['FPM84_POOL_CONTRACT'] == 'YES'
)

receipt = {
    'schema_version': 1,
    'STATUS': 'PASS' if safety_pass else 'FAIL',
    'ISSUE': 1353,
    'TARGET': 'PROD',
    'MODE': 'PLAN',
    'MAIN_SHA': os.environ['MAIN_SHA'],
    'PLAN_ID': os.environ['PLAN_ID'],
    'OS_PRETTY_NAME': os.environ['OS_PRETTY_NAME'],
    'OS': os.environ['OS_PRETTY_NAME'],
    'VERSION_ID': os.environ['VERSION_ID'],
    'KERNEL_RUNNING': os.environ['KERNEL_RUNNING'],
    'REBOOT_REQUIRED': os.environ['REBOOT_REQUIRED'],
    'REBOOT_REQUIRED_PACKAGES_SOURCE': os.environ['REBOOT_REQUIRED_PACKAGES_SOURCE'],
    'REBOOT_REQUIRED_PACKAGES': reboot_required_packages,
    'CURRENT_PHP_CLI': os.environ['CURRENT_PHP_CLI'],
    'CURRENT_PHP_FPM': os.environ['CURRENT_PHP_FPM'],
    'CURRENT_PHP_FPM_SERVICE': os.environ['CURRENT_PHP_FPM_SERVICE'].upper(),
    'CURRENT_RELEASE': os.environ['CURRENT_RELEASE'],
    'CURRENT_PROD_SOCKET': os.environ['CURRENT_PROD_SOCKET'],
    'NGINX_SERVICE': os.environ['NGINX_SERVICE'].upper(),
    'MARIADB_SERVICE': os.environ['MARIADB_SERVICE'].upper(),
    'MARIADB_VERSION': os.environ['MARIADB_VERSION'],
    'FAILED_SYSTEMD_UNITS': failed_units,
    'DRUPAL_HEALTH': os.environ['DRUPAL_HEALTH'],
    'PUBLIC_HEALTH': os.environ['PUBLIC_HEALTH'],
    'DISK_AVAILABLE_KB': int(os.environ['DISK_AVAILABLE_KB']),
    'DISK_AVAILABLE': int(os.environ['DISK_AVAILABLE_KB']),
    'PHP85_PACKAGE_CANDIDATES': candidates,
    'PHP85_INSTALLED_VERSIONS': {
        name: installed_versions[name] for name in requested_allowlist
    },
    'PHP85_INSTALL_SIMULATION': install_simulation_state,
    'PHP85_INSTALLABLE': 'YES' if php85_installable else 'NO',
    'REQUESTED_PACKAGE_ALLOWLIST': requested_allowlist,
    'PACKAGE_ADDITIONS': additions,
    'TRANSITIVE_ADDITIONS': transitive_additions,
    'PACKAGE_UPGRADES': upgrades,
    'PACKAGE_REMOVALS': removals,
    'UNEXPECTED_PACKAGE_REMOVALS': removals,
    'UNRELATED_PACKAGE_UPGRADES': upgrades,
    'PHP84_PACKAGES_PRESENT': os.environ['PHP84_PACKAGES_PRESENT'],
    'PHP84_SERVICE_ACTIVE': os.environ['PHP84_SERVICE_ACTIVE'],
    'ROLLBACK_PHP84_AVAILABLE': 'YES' if rollback_php84_available else 'NO',
    'NGINX_VHOST_PHP84_SOCKET_MATCH': os.environ['NGINX_VHOST_PHP84_SOCKET_MATCH'],
    'NGINX_VHOST_PATH': os.environ['NGINX_VHOST_PATH'],
    'NGINX_VHOST_OWNER': os.environ['NGINX_VHOST_OWNER'],
    'NGINX_VHOST_GROUP': os.environ['NGINX_VHOST_GROUP'],
    'NGINX_VHOST_MODE': os.environ['NGINX_VHOST_MODE'],
    'NGINX_VHOST_SHA256': os.environ['NGINX_VHOST_SHA256'],
    'NGINX_FASTCGI_PASS_VALUES': nginx_fastcgi_pass_values,
    'FPM84_POOL_CONTRACT': os.environ['FPM84_POOL_CONTRACT'],
    'FPM84_POOL_PATH': os.environ['FPM84_POOL_PATH'],
    'FPM84_POOL_SHA256': os.environ['FPM84_POOL_SHA256'],
    'SAFETY_GATE': 'PASS' if safety_pass else 'FAIL',
    'FAILED_CHECKS': failed_checks,
}
# Exact free disk is volatile: observe and safety-gate it, but exclude it from
# stale-plan mutation identity.
mutation_identity_keys = (
    'schema_version','STATUS','ISSUE','TARGET','MODE','MAIN_SHA','PLAN_ID',
    'OS_PRETTY_NAME','VERSION_ID','KERNEL_RUNNING','REBOOT_REQUIRED',
    'REBOOT_REQUIRED_PACKAGES_SOURCE','REBOOT_REQUIRED_PACKAGES',
    'CURRENT_PHP_CLI','CURRENT_PHP_FPM','CURRENT_PHP_FPM_SERVICE',
    'CURRENT_RELEASE','CURRENT_PROD_SOCKET','NGINX_SERVICE','MARIADB_SERVICE','MARIADB_VERSION',
    'FAILED_SYSTEMD_UNITS','DRUPAL_HEALTH','PUBLIC_HEALTH',
    'PHP85_PACKAGE_CANDIDATES','PHP85_INSTALLED_VERSIONS','PHP85_INSTALL_SIMULATION','PHP85_INSTALLABLE',
    'REQUESTED_PACKAGE_ALLOWLIST','PACKAGE_ADDITIONS','TRANSITIVE_ADDITIONS',
    'PACKAGE_UPGRADES','PACKAGE_REMOVALS','UNEXPECTED_PACKAGE_REMOVALS','UNRELATED_PACKAGE_UPGRADES',
    'PHP84_PACKAGES_PRESENT','PHP84_SERVICE_ACTIVE','ROLLBACK_PHP84_AVAILABLE',
    'NGINX_VHOST_PHP84_SOCKET_MATCH','NGINX_VHOST_PATH','NGINX_VHOST_OWNER','NGINX_VHOST_GROUP',
    'NGINX_VHOST_MODE','NGINX_VHOST_SHA256','NGINX_FASTCGI_PASS_VALUES',
    'FPM84_POOL_CONTRACT','FPM84_POOL_PATH','FPM84_POOL_SHA256',
    'SAFETY_GATE','FAILED_CHECKS',
)
mutation_identity = {key: receipt[key] for key in mutation_identity_keys}
canonical = json.dumps(mutation_identity, sort_keys=True, separators=(',', ':')).encode('utf-8')
receipt['PLAN_DIGEST'] = hashlib.sha256(canonical).hexdigest()
print(json.dumps(receipt, sort_keys=True, separators=(',', ':')))
if failed_checks:
    raise SystemExit(65)
PY
