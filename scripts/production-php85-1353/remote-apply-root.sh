#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
LC_ALL=C
export PATH LC_ALL

APPROVED_PLAN="${1:-}"
EXPECTED_DIGEST="${2:-}"
EXPECTED_MAIN="${3:-}"
PLAN_SCRIPT="${4:-}"

ISSUE='1353'
TARGET='PROD'
PROD_URL='https://emergingdigital.be'
DRUPAL_ROOT='/var/www/agency/current'
CAPABILITY_HELPER='/usr/local/sbin/agency-prod-php85-1353-apply'
CAPABILITY_SUDOERS='/etc/sudoers.d/agency-prod-php85-1353'
CAPABILITY_STATE='/var/lib/agency-prod-php85-1353'
CONSUMED_ROOT='/var/lib/agency-prod-php85-1353-consumed'

[[ "$(id -u)" -eq 0 ]]
[[ "$EXPECTED_DIGEST" =~ ^[0-9a-f]{64}$ ]]
[[ "$EXPECTED_MAIN" =~ ^[0-9a-f]{40}$ ]]
[[ -f "$APPROVED_PLAN" && ! -L "$APPROVED_PLAN" ]]
[[ -f "$PLAN_SCRIPT" && ! -L "$PLAN_SCRIPT" ]]
[[ "$APPROVED_PLAN" == "$CAPABILITY_STATE/approved-plan.json" ]]
[[ "$PLAN_SCRIPT" == "$CAPABILITY_STATE/remote-plan.sh" ]]

jq -e --arg digest "$EXPECTED_DIGEST" --arg main "$EXPECTED_MAIN" '
  .schema_version == 1
  and .STATUS == "PASS"
  and .ISSUE == 1353
  and .TARGET == "PROD"
  and .MODE == "PLAN"
  and .MAIN_SHA == $main
  and .PLAN_DIGEST == $digest
  and .SAFETY_GATE == "PASS"
  and .FAILED_CHECKS == []
  and .PHP85_INSTALL_SIMULATION == "PASS"
  and .PHP85_INSTALLABLE == "YES"
  and .PACKAGE_REMOVALS == []
  and .UNEXPECTED_PACKAGE_REMOVALS == []
  and .PACKAGE_UPGRADES == []
  and .UNRELATED_PACKAGE_UPGRADES == []
  and .PHP84_PACKAGES_PRESENT == "YES"
  and .PHP84_SERVICE_ACTIVE == "YES"
  and .ROLLBACK_PHP84_AVAILABLE == "YES"
  and .NGINX_VHOST_PHP84_SOCKET_MATCH == "YES"
  and .FPM84_POOL_CONTRACT == "YES"
  and (.REQUESTED_PACKAGE_ALLOWLIST | length) == 11' "$APPROVED_PLAN" >/dev/null

plan_id="$(jq -r '.PLAN_ID' "$APPROVED_PLAN")"
[[ "$plan_id" =~ ^plan-1353-[1-9][0-9]*-1$ ]]
old_socket="$(jq -r '.CURRENT_PROD_SOCKET' "$APPROVED_PLAN")"
[[ "$old_socket" =~ ^/run/php/php8\.4-fpm[A-Za-z0-9._-]*\.sock$ ]]
new_socket="${old_socket/php8.4-fpm/php8.5-fpm}"
[[ "$new_socket" =~ ^/run/php/php8\.5-fpm[A-Za-z0-9._-]*\.sock$ ]]
nginx_vhost="$(jq -r '.NGINX_VHOST_PATH' "$APPROVED_PLAN")"
fpm84_pool="$(jq -r '.FPM84_POOL_PATH' "$APPROVED_PLAN")"
[[ "$nginx_vhost" =~ ^/etc/nginx/sites-available/[A-Za-z0-9._-]+$ ]]
[[ "$fpm84_pool" =~ ^/etc/php/8\.4/fpm/pool\.d/[A-Za-z0-9._-]+\.conf$ ]]
fpm85_pool="/etc/php/8.5/fpm/pool.d/$(basename "$fpm84_pool")"

work_root="$(mktemp -d /root/agency-prod-php85-1353.XXXXXX)"
backup_root="/var/backups/agency/php85-1353-$(date -u +%Y%m%dT%H%M%SZ)-$$"
install -d -m 700 "$backup_root" "$CONSUMED_ROOT"

cleanup() {
  rm -rf -- "$work_root" "$CAPABILITY_STATE"
  rm -f -- "$CAPABILITY_SUDOERS" "$CAPABILITY_HELPER"
}
trap cleanup EXIT

emit_failure() {
  local rollback="$1"
  jq -n \
    --arg main "$EXPECTED_MAIN" \
    --arg digest "$EXPECTED_DIGEST" \
    --arg rollback "$rollback" \
    --arg backup "$backup_root" \
    '{
      STATUS:"FAIL",ISSUE:1353,TARGET:"PROD",MODE:"APPLY",
      MAIN_SHA:$main,PLAN_DIGEST:$digest,ROLLBACK:$rollback,
      SYSTEM_CONFIG_BACKUP:$backup,PHP84_REMOVAL:"NONE",
      DRUPAL_DEPLOY:"NONE",DRUPAL_CONFIG_IMPORT:"NONE",
      DB_MUTATION:"NONE",OS_UPGRADE:"NONE",MARIADB_CHANGE:"NONE"
    }'
}

# Re-run the exact PLAN immediately before the mutation boundary.
"$PLAN_SCRIPT" "$EXPECTED_MAIN" "$plan_id" > "$work_root/current-plan.json"
test "$(jq -r '.PLAN_DIGEST' "$work_root/current-plan.json")" = "$EXPECTED_DIGEST"
jq -e --arg old "$old_socket" --arg nginx "$nginx_vhost" --arg pool "$fpm84_pool" '
  .STATUS == "PASS"
  and .SAFETY_GATE == "PASS"
  and .FAILED_CHECKS == []
  and .CURRENT_PROD_SOCKET == $old
  and .NGINX_VHOST_PATH == $nginx
  and .FPM84_POOL_PATH == $pool
  and .PHP84_PACKAGES_PRESENT == "YES"
  and .PHP84_SERVICE_ACTIVE == "YES"
  and .ROLLBACK_PHP84_AVAILABLE == "YES"
  and .NGINX_FASTCGI_PASS_VALUES == ["unix:" + $old]
  and .FPM84_POOL_CONTRACT == "YES"' "$work_root/current-plan.json" >/dev/null

mapfile -t package_specs < <(
  jq -r '.REQUESTED_PACKAGE_ALLOWLIST[] as $pkg | "\($pkg)=\(.PHP85_PACKAGE_CANDIDATES[$pkg])"' "$APPROVED_PLAN"
)
[[ "${#package_specs[@]}" -eq 11 ]]
for spec in "${package_specs[@]}"; do
  [[ "$spec" =~ ^php8\.5-[a-z0-9.+-]+=[A-Za-z0-9.+:~_-]+$ ]]
done

# Re-run exact package simulation and reject all drift before package mutation.
apt-get --simulate install "${package_specs[@]}" > "$work_root/exact-sim.raw" 2>&1
python3 - "$APPROVED_PLAN" "$work_root/exact-sim.raw" <<'PY_SIM'
import json
import re
import sys
from pathlib import Path

approved = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
additions, upgrades, removals = [], [], []
for line in Path(sys.argv[2]).read_text(encoding='utf-8', errors='replace').splitlines():
    if line.startswith('Inst '):
        match = re.match(r'^Inst\s+(\S+)(?:\s+\[([^\]]+)\])?\s+\((\S+)', line)
        if not match:
            raise SystemExit('Unparseable exact simulation line')
        name, old, new = match.groups()
        item = {'name': name, 'from': old or 'ABSENT', 'to': new}
        (upgrades if old else additions).append(item)
    elif line.startswith('Remv '):
        parts = line.split()
        removals.append({'name': parts[1], 'from': parts[2] if len(parts) > 2 else 'UNKNOWN'})
for items in (additions, upgrades, removals):
    items.sort(key=lambda item: item['name'])
if additions != approved['PACKAGE_ADDITIONS']:
    raise SystemExit('Exact apply simulation addition drift')
if upgrades != approved['PACKAGE_UPGRADES']:
    raise SystemExit('Exact apply simulation upgrade drift')
if removals != approved['PACKAGE_REMOVALS']:
    raise SystemExit('Exact apply simulation removal drift')
PY_SIM

# Host-local replay guard complements the GitHub one-shot consumption marker.
consumed="$CONSUMED_ROOT/$EXPECTED_DIGEST"
[[ ! -e "$consumed" && ! -L "$consumed" ]]
install -m 600 /dev/null "$consumed"

# Preserve exact rollback inputs before PHP/Nginx mutation.
test "$(sha256sum "$nginx_vhost" | awk '{print $1}')" = "$(jq -r '.NGINX_VHOST_SHA256' "$APPROVED_PLAN")"
test "$(sha256sum "$fpm84_pool" | awk '{print $1}')" = "$(jq -r '.FPM84_POOL_SHA256' "$APPROVED_PLAN")"
cp --preserve=all "$nginx_vhost" "$backup_root/nginx-vhost.before"
cp --preserve=all "$fpm84_pool" "$backup_root/php84-pool.before"
if [[ -f "$fpm85_pool" ]]; then
  cp --preserve=all "$fpm85_pool" "$backup_root/php85-pool.before"
fi

export DEBIAN_FRONTEND=noninteractive
apt-get install -y "${package_specs[@]}" > "$work_root/apt-install.log" 2>&1

# PHP 8.4 remains installed and active as the rollback runtime.
for package in php8.4-cli php8.4-fpm; do
  dpkg-query -W -f='${Status}' "$package" | grep -qx 'install ok installed'
done
systemctl is-active --quiet php8.4-fpm

# Reproduce the exact Agency PHP 8.4 FPM pool contract for PHP 8.5,
# changing only the selected socket path.
python3 - "$fpm84_pool" "$work_root/php85-pool.candidate" "$old_socket" "$new_socket" <<'PY_POOL'
import sys
from pathlib import Path

src = Path(sys.argv[1]).read_bytes()
old = sys.argv[3].encode()
new = sys.argv[4].encode()
if src.count(old) != 1:
    raise SystemExit('Expected exactly one PHP 8.4 socket in source FPM pool')
candidate = src.replace(old, new)
if old in candidate or candidate.count(new) != 1:
    raise SystemExit('FPM85 contract substitution failed')
Path(sys.argv[2]).write_bytes(candidate)
PY_POOL
install -o root -g root -m 0644 "$work_root/php85-pool.candidate" "$fpm85_pool"
php-fpm8.5 -t
php8.5 -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'
systemctl enable --now php8.5-fpm
systemctl restart php8.5-fpm
systemctl is-active --quiet php8.5-fpm
[[ -S "$new_socket" ]]

# Derive the Nginx candidate from exact live bytes. Only Agency fastcgi socket
# substitution is permitted.
python3 - "$nginx_vhost" "$work_root/nginx.candidate" "$old_socket" "$new_socket" <<'PY_NGINX'
import re
import sys
from pathlib import Path

source = Path(sys.argv[1]).read_text(encoding='utf-8', errors='strict')
old = sys.argv[3]
new = sys.argv[4]
if new in source:
    raise SystemExit('PHP 8.5 socket already present before cutover')
pattern = re.compile(
    r'^(?P<prefix>\\s*fastcgi_pass\\s+)unix:'
    + re.escape(old)
    + r'(?P<suffix>\\s*;\\s*(?:#.*)?)$',
    re.MULTILINE,
)
candidate, substitutions = pattern.subn(
    lambda match: match.group('prefix') + 'unix:' + new + match.group('suffix'),
    source,
)
if substitutions < 1:
    raise SystemExit('No Agency FastCGI socket directive was replaced')
if source.count(old) != substitutions:
    raise SystemExit('Old PHP socket appears outside governed fastcgi_pass directives')
if old in candidate:
    raise SystemExit('Old PHP socket remains after governed substitution')
targets = set()
for line in candidate.splitlines():
    if 'fastcgi_pass' not in line:
        continue
    match = re.match(r'^\\s*fastcgi_pass\\s+([^;\\s]{1,256})\\s*;\\s*(?:#.*)?\\Z', line)
    if not match:
        raise SystemExit('Unparseable FastCGI directive')
    targets.add(match.group(1))
if targets != {'unix:' + new}:
    raise SystemExit('Unexpected candidate FastCGI target set')
Path(sys.argv[2]).write_text(candidate, encoding='utf-8')
PY_NGINX

switched='NO'
rollback() {
  local rc="$1"
  set +e
  rollback_status='FAIL'
  cp --preserve=all "$backup_root/nginx-vhost.before" "$nginx_vhost"
  if nginx -t >/dev/null 2>&1 \
    && systemctl reload nginx >/dev/null 2>&1 \
    && systemctl is-active --quiet php8.4-fpm \
    && (cd "$DRUPAL_ROOT" && vendor/bin/drush status --fields=bootstrap >/dev/null 2>&1) \
    && curl --silent --show-error --fail --max-time 8 "$PROD_URL/health/live" >/dev/null \
    && curl --silent --show-error --fail --max-time 8 "$PROD_URL/health/ready" >/dev/null; then
    rollback_status='PASS'
  fi
  emit_failure "$rollback_status"
  exit "$rc"
}
on_error() {
  local rc=$?
  if [[ "$switched" == 'YES' ]]; then
    rollback "$rc"
  fi
  emit_failure 'NOT_REQUIRED'
  exit "$rc"
}
trap on_error ERR

cat "$work_root/nginx.candidate" > "$nginx_vhost"
switched='YES'
nginx -t
systemctl reload nginx

# Canonical post-switch Drupal/public validation; no Drupal write command.
(cd "$DRUPAL_ROOT" && vendor/bin/drush status --fields=bootstrap >/dev/null)
curl --silent --show-error --fail --max-time 8 "$PROD_URL/health/live" > "$work_root/public-live.json"
curl --silent --show-error --fail --max-time 8 "$PROD_URL/health/ready" > "$work_root/public-ready.json"
homepage_status="$(curl --silent --show-error --location --output /dev/null --write-out '%{http_code}' --max-time 12 "$PROD_URL/fr")"
test "$homepage_status" = '200'

# Prove the Nginx-selected socket is owned by PHP 8.5 FPM.
python3 - "$new_socket" <<'PY_SOCKET'
import os
import sys
from pathlib import Path

socket_path = sys.argv[1]
inode = None
for line in Path('/proc/net/unix').read_text().splitlines()[1:]:
    parts = line.split()
    if len(parts) >= 8 and parts[-1] == socket_path:
        inode = parts[6]
        break
if not inode:
    raise SystemExit('Unable to resolve PHP 8.5 FPM socket inode')
needle = f'socket:[{inode}]'
owners = []
for proc in Path('/proc').glob('[0-9]*'):
    try:
        for item in (proc / 'fd').iterdir():
            try:
                if os.readlink(item) == needle:
                    owners.append((proc / 'cmdline').read_bytes().replace(b'\0', b' ').decode(errors='replace'))
                    break
            except (OSError, PermissionError):
                pass
    except (OSError, PermissionError):
        pass
if not any('php-fpm8.5' in cmd or 'php-fpm: master process (/etc/php/8.5/' in cmd for cmd in owners):
    raise SystemExit('PHP 8.5 FPM does not own selected PROD socket')
PY_SOCKET

systemctl is-active --quiet nginx
systemctl is-active --quiet php8.5-fpm
systemctl is-active --quiet php8.4-fpm
systemctl is-active --quiet mariadb
for package in php8.4-cli php8.4-fpm; do
  dpkg-query -W -f='${Status}' "$package" | grep -qx 'install ok installed'
done
test "$(mariadb --version | head -n 1)" = "$(jq -r '.MARIADB_VERSION' "$APPROVED_PLAN")"
# shellcheck disable=SC1091
source /etc/os-release
test "${VERSION_ID:-}" = '24.04'

trap - ERR
jq -n \
  --arg main "$EXPECTED_MAIN" \
  --arg digest "$EXPECTED_DIGEST" \
  --arg backup "$backup_root" \
  --arg old_socket "$old_socket" \
  --arg new_socket "$new_socket" \
  '{
    STATUS:"PASS",ISSUE:1353,TARGET:"PROD",MODE:"APPLY",
    MAIN_SHA:$main,PLAN_DIGEST:$digest,
    STALE_PLAN:"PASS",EXACT_PACKAGE_SIMULATION:"PASS",
    PACKAGE_APPLY:"PASS",PHP85_FPM:"ACTIVE",
    PHP85_OPCACHE_AVAILABLE:"PASS",
    PHP84_FPM:"ACTIVE_ROLLBACK_AVAILABLE",
    FPM85_CONTRACT:"PASS",
    OLD_SOCKET:$old_socket,PROD_SOCKET:$new_socket,
    NGINX_SOCKET_ONLY_DELTA:"PASS",NGINX_SERVICE:"ACTIVE",
    MARIADB_SERVICE:"ACTIVE",DRUPAL_HEALTH:"PASS",
    PUBLIC_HEALTH:"PASS",WEB_RUNTIME_PHP85:"PASS",
    ROLLBACK:"NOT_REQUIRED",SYSTEM_CONFIG_BACKUP:$backup,
    PHP84_REMOVAL:"NONE",DRUPAL_DEPLOY:"NONE",
    DRUPAL_CONFIG_IMPORT:"NONE",DB_MUTATION:"NONE",
    OS_UPGRADE:"NONE",MARIADB_CHANGE:"NONE"
  }'
