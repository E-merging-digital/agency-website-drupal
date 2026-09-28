#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APPROVED_PLAN="${1:-}"
EXPECTED_DIGEST="${2:-}"
EXPECTED_MAIN="${3:-}"
PLAN_SCRIPT="${4:-}"
RUNTIME_VALIDATOR="${5:-}"

ISSUE='1336'
TARGET='PREPROD'
OLD_SOCKET='/run/php/php8.4-fpm-agency-preprod.sock'
NEW_SOCKET='/run/php/php8.5-fpm-agency-preprod.sock'
NGINX_VHOST='/etc/nginx/sites-available/agency-preprod'
FPM85_POOL='/etc/php/8.5/fpm/pool.d/agency-preprod.conf'
CLI85_SAFETY='/etc/php/8.5/cli/conf.d/99-agency-preprod-safety.ini'
PREPROD_URL='https://preprod.emergingdigital.be'

[[ "$(id -u)" -eq 0 ]]
[[ "$EXPECTED_DIGEST" =~ ^[0-9a-f]{64}$ ]]
[[ "$EXPECTED_MAIN" =~ ^[0-9a-f]{40}$ ]]
[[ -f "$APPROVED_PLAN" && ! -L "$APPROVED_PLAN" ]]
[[ -f "$PLAN_SCRIPT" && ! -L "$PLAN_SCRIPT" ]]
[[ -f "$RUNTIME_VALIDATOR" && ! -L "$RUNTIME_VALIDATOR" ]]

jq -e \
  --arg digest "$EXPECTED_DIGEST" \
  --arg main "$EXPECTED_MAIN" '
  .STATUS == "PASS"
  and .ISSUE == 1336
  and .TARGET == "PREPROD"
  and .MODE == "PLAN"
  and .MAIN_SHA == $main
  and .PLAN_DIGEST == $digest
  and .SAFETY_GATE == "PASS"
  and .REBOOT_REQUIRED == "NO"
  and .PHP84_PACKAGES_PRESENT == "YES"
  and .PHP84_SERVICE_ACTIVE == "YES"
  and .NGINX_VHOST_PHP84_SOCKET_MATCH == "YES"
  and .NGINX_VHOST_OWNER == "root"
  and .NGINX_VHOST_GROUP == "root"
  and .NGINX_VHOST_MODE == "0644"
  and .FPM84_POOL_CONTRACT == "YES"
  and .SENDMAIL_SAFETY_CONTRACT == "YES"
  and .PACKAGE_REMOVALS == []
  and .PACKAGE_UPGRADES == []
  and .FAILED_CHECKS == []
  and .PHP85_INSTALL_SIMULATION == "PASS"
  and (.REQUESTED_PACKAGE_ALLOWLIST | length) == 11' "$APPROVED_PLAN" >/dev/null

plan_id="$(jq -r '.PLAN_ID' "$APPROVED_PLAN")"
work_root="$(mktemp -d /root/agency-php85-1336.XXXXXX)"
receipt_file="$work_root/result.json"
backup_root="/var/backups/agency-preprod/php85-1336-$(date -u +%Y%m%dT%H%M%SZ)-$$"
install -d -m 700 "$backup_root"

cleanup() { rm -rf -- "$work_root"; }
trap cleanup EXIT

# Re-run the exact PLAN immediately before mutation. Its digest intentionally
# excludes volatile free-space bytes while retaining the safety gate.
"$PLAN_SCRIPT" "$EXPECTED_MAIN" "$plan_id" >"$work_root/current-plan.json"
current_digest="$(jq -r '.PLAN_DIGEST' "$work_root/current-plan.json")"
[[ "$current_digest" == "$EXPECTED_DIGEST" ]] || {
  printf '%s\n' 'STALE_PLAN: digest drift before mutation.' >&2
  exit 65
}
jq -e '
  .REBOOT_REQUIRED == "NO"
  and .SAFETY_GATE == "PASS"
  and .NGINX_FASTCGI_PASS_VALUES == ["unix:/run/php/php8.4-fpm-agency-preprod.sock"]
  and .NGINX_VHOST_PHP84_SOCKET_MATCH == "YES"
  and .NGINX_VHOST_OWNER == "root"
  and .NGINX_VHOST_GROUP == "root"
  and .NGINX_VHOST_MODE == "0644"
' "$work_root/current-plan.json" >/dev/null

mapfile -t package_specs < <(
  jq -r '.REQUESTED_PACKAGE_ALLOWLIST[] as $pkg | "\($pkg)=\(.PHP85_PACKAGE_CANDIDATES[$pkg])"' "$APPROVED_PLAN"
)
[[ "${#package_specs[@]}" -eq 11 ]]
for spec in "${package_specs[@]}"; do
  [[ "$spec" =~ ^php8\.5-[a-z0-9.+-]+=[A-Za-z0-9.+:~_-]+$ ]]
done

# Re-run the exact install simulation before any mutation and reject any drift.
apt-get --simulate install "${package_specs[@]}" >"$work_root/exact-sim.raw" 2>&1
python3 - "$APPROVED_PLAN" "$work_root/exact-sim.raw" <<'PY'
import json
import re
import sys
from pathlib import Path
approved=json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
actual_additions=[]
actual_upgrades=[]
actual_removals=[]
for line in Path(sys.argv[2]).read_text(encoding='utf-8', errors='replace').splitlines():
    if line.startswith('Inst '):
        match=re.match(r'^Inst\s+(\S+)(?:\s+\[([^\]]+)\])?\s+\((\S+)', line)
        if not match:
            raise SystemExit('Unparseable exact simulation line')
        name, old, new=match.groups()
        item={'name':name,'from':old or 'ABSENT','to':new}
        (actual_upgrades if old else actual_additions).append(item)
    elif line.startswith('Remv '):
        parts=line.split()
        actual_removals.append({'name':parts[1],'from':parts[2] if len(parts)>2 else 'UNKNOWN'})
for items in (actual_additions,actual_upgrades,actual_removals):
    items.sort(key=lambda x:x['name'])
if actual_additions != approved['PACKAGE_ADDITIONS']:
    raise SystemExit('Exact apply simulation addition drift')
if actual_upgrades != approved['PACKAGE_UPGRADES']:
    raise SystemExit('Exact apply simulation upgrade drift')
if actual_removals != approved['PACKAGE_REMOVALS']:
    raise SystemExit('Exact apply simulation removal drift')
PY

# Bounded pre-migration backups. PHP 8.4 remains installed and active.
cp --preserve=all "$NGINX_VHOST" "$backup_root/nginx-agency-preprod.before"
cp --preserve=all /etc/php/8.4/fpm/pool.d/agency-preprod.conf "$backup_root/php84-agency-preprod-pool.before"
if [[ -f /etc/php/8.4/cli/conf.d/99-agency-preprod-safety.ini ]]; then
  cp --preserve=all /etc/php/8.4/cli/conf.d/99-agency-preprod-safety.ini "$backup_root/php84-cli-safety.before"
fi
[[ "$(stat -c '%U' "$backup_root/nginx-agency-preprod.before")" == 'root' ]]
[[ "$(stat -c '%G' "$backup_root/nginx-agency-preprod.before")" == 'root' ]]
[[ "$(stat -c '%a' "$backup_root/nginx-agency-preprod.before")" == '644' ]]

export DEBIAN_FRONTEND=noninteractive
apt-get install -y "${package_specs[@]}" >"$work_root/apt-install.log" 2>&1

# Never remove or stop PHP 8.4 as part of this migration.
dpkg-query -W -f='${Status}' php8.4-fpm | grep -qx 'install ok installed'
systemctl is-active --quiet php8.4-fpm

cat >"$FPM85_POOL" <<'EOF_POOL'
[agency-preprod]
user = agency-preprod
group = www-data
listen = /run/php/php8.5-fpm-agency-preprod.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 10s
pm.max_requests = 500
clear_env = yes
php_admin_value[sendmail_path] = /bin/true
EOF_POOL
chmod 644 "$FPM85_POOL"

cat >"$CLI85_SAFETY" <<'EOF_INI'
sendmail_path = /bin/true
EOF_INI
chmod 644 "$CLI85_SAFETY"

php-fpm8.5 -t
php8.5 -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'
systemctl enable --now php8.5-fpm
systemctl restart php8.5-fpm
[[ -S "$NEW_SOCKET" ]]
[[ "$(php8.5 -r 'echo (string) ini_get("sendmail_path");')" == '/bin/true' ]]

# Derive the candidate vhost from exact live bytes, replacing every exact
# occurrence of the approved PHP 8.4 socket and nothing else.
python3 - "$NGINX_VHOST" "$work_root/nginx.candidate" <<'PY'
import re
import sys
from pathlib import Path

source_path = Path(sys.argv[1])
candidate_path = Path(sys.argv[2])
src = source_path.read_bytes()
old = b'/run/php/php8.4-fpm-agency-preprod.sock'
new = b'/run/php/php8.5-fpm-agency-preprod.sock'
old_count = src.count(old)
if old_count < 1:
    raise SystemExit('Expected at least one PHP 8.4 PREPROD socket in live vhost')
if new in src:
    raise SystemExit('PHP 8.5 socket already present before switch')

text = src.decode('utf-8', errors='strict')
targets = set()
valid = True
for line in text.splitlines():
    if 'fastcgi_pass' not in line:
        continue
    match = re.match(r"^\s*fastcgi_pass\s+([^;\s]{1,256})\s*;\s*(?:#.*)?\Z", line)
    if not match:
        valid = False
        continue
    targets.add(match.group(1))
if not valid or targets != {'unix:/run/php/php8.4-fpm-agency-preprod.sock'}:
    raise SystemExit('Unexpected live FastCGI target set before switch')

candidate = src.replace(old, new)
if old in candidate:
    raise SystemExit('PHP 8.4 socket remains after candidate substitution')
if candidate.count(new) != old_count:
    raise SystemExit('PHP 8.5 socket occurrence count does not match source')
candidate_path.write_bytes(candidate)
PY

python3 - "$NGINX_VHOST" "$work_root/nginx.candidate" <<'PY'
import re
import sys
from pathlib import Path

source = Path(sys.argv[1]).read_bytes()
candidate = Path(sys.argv[2]).read_bytes()
old = b'/run/php/php8.4-fpm-agency-preprod.sock'
new = b'/run/php/php8.5-fpm-agency-preprod.sock'
old_count = source.count(old)
expected = source.replace(old, new)
if candidate != expected:
    raise SystemExit('NGINX_SOCKET_ONLY_DELTA failed')
if old in candidate:
    raise SystemExit('NGINX_SOCKET_ONLY_DELTA left old socket behind')
if candidate.count(new) != old_count:
    raise SystemExit('NGINX_SOCKET_ONLY_DELTA occurrence count drift')

targets = set()
valid = True
for line in candidate.decode('utf-8', errors='strict').splitlines():
    if 'fastcgi_pass' not in line:
        continue
    match = re.match(r"^\s*fastcgi_pass\s+([^;\s]{1,256})\s*;\s*(?:#.*)?\Z", line)
    if not match:
        valid = False
        continue
    targets.add(match.group(1))
if not valid or targets != {'unix:/run/php/php8.5-fpm-agency-preprod.sock'}:
    raise SystemExit('NGINX_SOCKET_ONLY_DELTA candidate FastCGI targets invalid')
PY

switched='NO'
rollback() {
  local rc="$1"
  set +e
  rollback_status='FAIL'
  cp --preserve=all "$backup_root/nginx-agency-preprod.before" "$NGINX_VHOST"
  if [[ "$(stat -c '%U' "$NGINX_VHOST")" == 'root' ]] \
    && [[ "$(stat -c '%G' "$NGINX_VHOST")" == 'root' ]] \
    && [[ "$(stat -c '%a' "$NGINX_VHOST")" == '644' ]] \
    && nginx -t >/dev/null 2>&1 \
    && systemctl reload nginx >/dev/null 2>&1 \
    && systemctl is-active --quiet php8.4-fpm \
    && curl --silent --show-error --fail --max-time 8 "$PREPROD_URL/health/live" >/dev/null \
    && curl --silent --show-error --fail --max-time 8 "$PREPROD_URL/health/ready" >/dev/null; then
    rollback_status='PASS'
  fi
  jq -n \
    --arg status 'FAIL' \
    --arg rollback "$rollback_status" \
    --arg main "$EXPECTED_MAIN" \
    --arg digest "$EXPECTED_DIGEST" \
    --arg backup "$backup_root" \
    '{STATUS:$status,ISSUE:1336,TARGET:"PREPROD",MODE:"APPLY",MAIN_SHA:$main,PLAN_DIGEST:$digest,ROLLBACK:$rollback,SYSTEM_CONFIG_BACKUP:$backup,PHP84_REMOVAL:"NONE",PROD_ACCESS:"NONE"}'
  exit "$rc"
}
on_error() {
  local rc=$?
  if [[ "$switched" == 'YES' ]]; then
    rollback "$rc"
  fi
  exit "$rc"
}
trap on_error ERR

install -m 644 "$work_root/nginx.candidate" "$NGINX_VHOST"
switched='YES'
nginx -t
systemctl reload nginx

# Canonical PREPROD runtime and public health validation.
bash "$RUNTIME_VALIDATOR" >"$work_root/runtime-validation.txt"
grep -Fqx 'side_effects=PASS' "$work_root/runtime-validation.txt"
curl --silent --show-error --fail --max-time 8 "$PREPROD_URL/health/live" >"$work_root/public-live.json"
curl --silent --show-error --fail --max-time 8 "$PREPROD_URL/health/ready" >"$work_root/public-ready.json"

# Prove the socket selected by Nginx is backed by a PHP 8.5 FPM process.
python3 - "$NEW_SOCKET" <<'PY'
import os
import re
import sys
from pathlib import Path
socket_path=sys.argv[1]
inode=None
for line in Path('/proc/net/unix').read_text().splitlines()[1:]:
    parts=line.split()
    if len(parts) >= 8 and parts[-1] == socket_path:
        inode=parts[6]
        break
if not inode:
    raise SystemExit('Unable to resolve PHP 8.5 FPM socket inode')
needle=f'socket:[{inode}]'
owners=[]
for proc in Path('/proc').glob('[0-9]*'):
    fd=proc/'fd'
    try:
        for item in fd.iterdir():
            try:
                if os.readlink(item) == needle:
                    cmd=(proc/'cmdline').read_bytes().replace(b'\0',b' ').decode(errors='replace')
                    owners.append(cmd)
                    break
            except (OSError, PermissionError):
                pass
    except (OSError, PermissionError):
        pass
if not any('php-fpm8.5' in cmd or 'php-fpm: master process (/etc/php/8.5/' in cmd for cmd in owners):
    raise SystemExit('PHP 8.5 FPM does not own the selected PREPROD socket')
PY

systemctl is-active --quiet nginx
systemctl is-active --quiet php8.5-fpm
systemctl is-active --quiet php8.4-fpm
systemctl is-active --quiet mariadb
grep -Fqx 'fastcgi_pass unix:/run/php/php8.5-fpm-agency-preprod.sock;' "$NGINX_VHOST" || \
  grep -Fq '/run/php/php8.5-fpm-agency-preprod.sock' "$NGINX_VHOST"

trap - ERR
jq -n \
  --arg status 'PASS' \
  --arg main "$EXPECTED_MAIN" \
  --arg digest "$EXPECTED_DIGEST" \
  --arg backup "$backup_root" \
  '{
    STATUS:$status,
    ISSUE:1336,
    TARGET:"PREPROD",
    MODE:"APPLY",
    MAIN_SHA:$main,
    PLAN_DIGEST:$digest,
    STALE_PLAN:"PASS",
    EXACT_PACKAGE_SIMULATION:"PASS",
    PACKAGE_APPLY:"PASS",
    PHP85_FPM:"ACTIVE",
    PHP85_OPCACHE_AVAILABLE:"PASS",
    PREPROD_SOCKET:"/run/php/php8.5-fpm-agency-preprod.sock",
    PHP84_FPM:"ACTIVE_ROLLBACK_AVAILABLE",
    NGINX_SOCKET_ONLY_DELTA:"PASS",
    NGINX_SERVICE:"ACTIVE",
    MARIADB_SERVICE:"ACTIVE",
    DRUPAL_HEALTH:"PASS",
    PUBLIC_HEALTH:"PASS",
    SIDE_EFFECT_ISOLATION:"PASS",
    WEB_RUNTIME_PHP85:"PASS",
    ROLLBACK:"NOT_REQUIRED",
    SYSTEM_CONFIG_BACKUP:$backup,
    PHP84_REMOVAL:"NONE",
    DRUPAL_DEPLOY:"NONE",
    DRUPAL_CONFIG_IMPORT:"NONE",
    DB_MUTATION:"NONE",
    PROD_ACCESS:"NONE"
  }'
