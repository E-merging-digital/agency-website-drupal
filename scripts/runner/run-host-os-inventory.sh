#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

surface="${1:-}"
case "$surface" in
  runner|preprod|prod) ;;
  *)
    printf '{"status":"FAIL","SURFACE":"unknown","failure_stage":"INPUT_VALIDATION"}\n'
    exit 64
    ;;
esac

failure_stage='INITIALIZATION'
result_emitted=0
apt_root=''
work_root="$(mktemp -d)"

cleanup() {
  if [[ -n "$apt_root" && -d "$apt_root" ]]; then
    rm -rf -- "$apt_root"
  fi
  if [[ -d "$work_root" ]]; then
    rm -rf -- "$work_root"
  fi
}

on_exit() {
  local rc=$?
  trap - EXIT
  if [[ "$rc" -ne 0 && "$result_emitted" -eq 0 ]]; then
    printf '{"status":"FAIL","SURFACE":"%s","failure_stage":"%s"}\n' \
      "$surface" "$failure_stage"
  fi
  cleanup
  exit "$rc"
}
trap on_exit EXIT

command -v python3 >/dev/null
command -v apt-get >/dev/null
command -v apt >/dev/null
command -v apt-mark >/dev/null
command -v systemctl >/dev/null
command -v dpkg-query >/dev/null

failure_stage='OS_IDENTITY'
# shellcheck disable=SC1091
source /etc/os-release
os_pretty_name="${PRETTY_NAME:-}"
version_id="${VERSION_ID:-}"
[[ -n "$os_pretty_name" && -n "$version_id" ]]

kernel_running="$(uname -r)"
kernel_installed_latest="$(
  find /lib/modules -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null |
    sort -V |
    tail -n 1
)"
[[ -n "$kernel_running" ]]
kernel_installed_latest="${kernel_installed_latest:-UNKNOWN}"

if [[ -f /var/run/reboot-required ]]; then
  reboot_required='YES'
else
  reboot_required='NO'
fi

failure_stage='APT_TEMP_METADATA'
apt_root="$(mktemp -d)"
mkdir -p \
  "$apt_root/state/lists/partial" \
  "$apt_root/cache/archives/partial" \
  "$apt_root/log"

apt_options=(
  -o "Dir::State=$apt_root/state"
  -o "Dir::State::status=/var/lib/dpkg/status"
  -o "Dir::State::extended_states=/var/lib/apt/extended_states"
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
apt "${apt_options[@]}" list --upgradable >"$apt_root/upgradable.raw" 2>/dev/null

awk '
  NR == 1 && /^Listing/ { next }
  NF { count++ }
  END { print count + 0 }
' "$apt_root/upgradable.raw" > "$work_root/upgradable-total.txt"
upgradable_total="$(cat "$work_root/upgradable-total.txt")"

awk '
  NR == 1 && /^Listing/ { next }
  NF && $0 ~ /-security([, ]|$)/ { count++ }
  END { print count + 0 }
' "$apt_root/upgradable.raw" > "$work_root/security-total.txt"
security_updates_total="$(cat "$work_root/security-total.txt")"

awk '
  NR == 1 && /^Listing/ { next }
  NF {
    split($1, name, "/")
    printf "%s %s\n", name[1], $2
  }
' "$apt_root/upgradable.raw" |
  head -n 30 > "$work_root/upgradable-packages.txt"

apt-mark showhold 2>/dev/null | head -n 30 > "$work_root/held-packages.txt"
systemctl --failed --no-legend --plain 2>/dev/null |
  awk 'NF { print $1 }' |
  head -n 20 > "$work_root/failed-units.txt"

disk_root="$(df -P -h / | awk 'NR == 2 {printf "size=%s used=%s avail=%s use=%s", $2, $3, $4, $5}')"
memory_available="$(awk '/^MemAvailable:/ {print $2 " kB"}' /proc/meminfo)"
uptime_value="$(uptime -p 2>/dev/null || true)"
[[ -n "$disk_root" && -n "$memory_available" ]]
uptime_value="${uptime_value:-UNKNOWN}"

hostname_value=''
user_value=''
actions_runner_version=''
runner_service=''
docker_version=''
docker_service=''
ddev_version=''
ddev_smoke=''
nginx_version=''
php_fpm_version=''
mariadb_version=''
nginx_service=''
php_fpm_service=''
mariadb_service=''
drupal_health=''

if [[ "$surface" == 'runner' ]]; then
  failure_stage='RUNNER_TOOLCHAIN'
  hostname_value="$(hostname)"
  user_value="$(whoami)"
  [[ "$hostname_value" == 'preflight-runner-01' ]]
  [[ "$user_value" == 'agency-runner' ]]

  runner_unit="$(
    systemctl list-unit-files --type=service --no-legend 'actions.runner.*.service' 2>/dev/null |
      awk 'NF { print $1; exit }'
  )"
  if [[ -n "$runner_unit" ]]; then
    runner_service="$(systemctl is-active "$runner_unit" 2>/dev/null || true)"
  else
    runner_service='NOT_FOUND'
  fi

  listener_pid="$(pgrep -u "$(id -u)" -f 'Runner.Listener' | head -n 1 || true)"
  if [[ -n "$listener_pid" ]]; then
    listener_exe="$(readlink -f "/proc/$listener_pid/exe" 2>/dev/null || true)"
    if [[ -n "$listener_exe" && -x "$listener_exe" ]]; then
      actions_runner_version="$("$listener_exe" --version 2>/dev/null | head -n 1 || true)"
    fi
  fi
  actions_runner_version="${actions_runner_version:-UNKNOWN}"

  docker_version="$(docker --version 2>/dev/null | head -n 1 || true)"
  docker_service="$(systemctl is-active docker 2>/dev/null || true)"
  ddev_version="$(ddev --version 2>/dev/null | head -n 1 || true)"
  if docker info >/dev/null 2>&1 && ddev version >/dev/null 2>&1; then
    ddev_smoke='PASS'
  else
    ddev_smoke='FAIL'
  fi
  docker_version="${docker_version:-UNKNOWN}"
  docker_service="${docker_service:-UNKNOWN}"
  ddev_version="${ddev_version:-UNKNOWN}"
else
  failure_stage='RUNTIME_SERVICES'
  nginx_version="$(nginx -v 2>&1 | head -n 1 || true)"
  php_fpm_version="$(php-fpm8.4 -v 2>/dev/null | head -n 1 || true)"
  mariadb_version="$(mariadb --version 2>/dev/null | head -n 1 || true)"
  nginx_service="$(systemctl is-active nginx 2>/dev/null || true)"
  php_fpm_service="$(systemctl is-active php8.4-fpm 2>/dev/null || true)"
  mariadb_service="$(systemctl is-active mariadb 2>/dev/null || true)"

  if [[ "$surface" == 'preprod' ]]; then
    drupal_root='/var/www/agency-preprod/current'
  else
    drupal_root='/var/www/agency/current'
  fi

  if [[ -x "$drupal_root/vendor/bin/drush" ]] && (
    cd "$drupal_root" && vendor/bin/drush status >/dev/null 2>&1
  ); then
    drupal_health='PASS'
  else
    drupal_health='FAIL'
  fi

  nginx_version="${nginx_version:-UNKNOWN}"
  php_fpm_version="${php_fpm_version:-UNKNOWN}"
  mariadb_version="${mariadb_version:-UNKNOWN}"
  nginx_service="${nginx_service:-UNKNOWN}"
  php_fpm_service="${php_fpm_service:-UNKNOWN}"
  mariadb_service="${mariadb_service:-UNKNOWN}"
fi

failure_stage='JSON_RECEIPT'
export \
  INVENTORY_SURFACE="$surface" \
  OS_PRETTY_NAME_VALUE="$os_pretty_name" \
  VERSION_ID_VALUE="$version_id" \
  KERNEL_RUNNING_VALUE="$kernel_running" \
  KERNEL_INSTALLED_LATEST_VALUE="$kernel_installed_latest" \
  REBOOT_REQUIRED_VALUE="$reboot_required" \
  UPGRADABLE_TOTAL_VALUE="$upgradable_total" \
  SECURITY_UPDATES_TOTAL_VALUE="$security_updates_total" \
  DISK_ROOT_VALUE="$disk_root" \
  MEMORY_AVAILABLE_VALUE="$memory_available" \
  UPTIME_VALUE="$uptime_value" \
  HOSTNAME_VALUE="$hostname_value" \
  USER_VALUE="$user_value" \
  ACTIONS_RUNNER_VERSION_VALUE="$actions_runner_version" \
  RUNNER_SERVICE_VALUE="$runner_service" \
  DOCKER_VERSION_VALUE="$docker_version" \
  DOCKER_SERVICE_VALUE="$docker_service" \
  DDEV_VERSION_VALUE="$ddev_version" \
  DDEV_SMOKE_VALUE="$ddev_smoke" \
  NGINX_VERSION_VALUE="$nginx_version" \
  PHP_FPM_VERSION_VALUE="$php_fpm_version" \
  MARIADB_VERSION_VALUE="$mariadb_version" \
  NGINX_SERVICE_VALUE="$nginx_service" \
  PHP_FPM_SERVICE_VALUE="$php_fpm_service" \
  MARIADB_SERVICE_VALUE="$mariadb_service" \
  DRUPAL_HEALTH_VALUE="$drupal_health" \
  INVENTORY_WORK_ROOT="$work_root"

python3 - <<'PY'
import json
import os
from pathlib import Path

root = Path(os.environ['INVENTORY_WORK_ROOT'])

def lines(name):
    path = root / name
    if not path.exists():
        return []
    return [line.strip() for line in path.read_text(encoding='utf-8').splitlines() if line.strip()]

def value(name):
    return os.environ.get(name, '')

surface = value('INVENTORY_SURFACE')
result = {
    'schema_version': 1,
    'status': 'PASS',
    'SURFACE': surface,
    'OS_PRETTY_NAME': value('OS_PRETTY_NAME_VALUE'),
    'VERSION_ID': value('VERSION_ID_VALUE'),
    'KERNEL_RUNNING': value('KERNEL_RUNNING_VALUE'),
    'KERNEL_INSTALLED_LATEST': value('KERNEL_INSTALLED_LATEST_VALUE'),
    'REBOOT_REQUIRED': value('REBOOT_REQUIRED_VALUE'),
    'UPGRADABLE_TOTAL': int(value('UPGRADABLE_TOTAL_VALUE')),
    'SECURITY_UPDATES_TOTAL': int(value('SECURITY_UPDATES_TOTAL_VALUE')),
    'UPGRADABLE_PACKAGES': lines('upgradable-packages.txt'),
    'HELD_PACKAGES': lines('held-packages.txt'),
    'FAILED_SYSTEMD_UNITS': lines('failed-units.txt'),
    'DISK_ROOT': value('DISK_ROOT_VALUE'),
    'MEMORY_AVAILABLE': value('MEMORY_AVAILABLE_VALUE'),
    'UPTIME': value('UPTIME_VALUE'),
}

if surface == 'runner':
    result.update({
        'HOSTNAME': value('HOSTNAME_VALUE'),
        'USER': value('USER_VALUE'),
        'ACTIONS_RUNNER_VERSION': value('ACTIONS_RUNNER_VERSION_VALUE'),
        'RUNNER_SERVICE': value('RUNNER_SERVICE_VALUE'),
        'DOCKER_VERSION': value('DOCKER_VERSION_VALUE'),
        'DOCKER_SERVICE': value('DOCKER_SERVICE_VALUE'),
        'DDEV_VERSION': value('DDEV_VERSION_VALUE'),
        'DDEV_SMOKE': value('DDEV_SMOKE_VALUE'),
    })
else:
    result.update({
        'NGINX_VERSION': value('NGINX_VERSION_VALUE'),
        'PHP_FPM_VERSION': value('PHP_FPM_VERSION_VALUE'),
        'MARIADB_VERSION': value('MARIADB_VERSION_VALUE'),
        'NGINX_SERVICE': value('NGINX_SERVICE_VALUE'),
        'PHP_FPM_SERVICE': value('PHP_FPM_SERVICE_VALUE'),
        'MARIADB_SERVICE': value('MARIADB_SERVICE_VALUE'),
        'DRUPAL_HEALTH': value('DRUPAL_HEALTH_VALUE'),
    })

print(json.dumps(result, sort_keys=True, separators=(',', ':')))
PY

result_emitted=1
