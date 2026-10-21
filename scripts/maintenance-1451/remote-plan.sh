#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

main_sha="$1"
plan_id="$2"
surface="$3"
expected_user="$4"
[[ "$main_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "$plan_id" =~ ^plan-1451-[0-9]+-[0-9]+$ ]]
[[ "$expected_user" =~ ^[a-z][a-z0-9_-]*$ ]]
case "$surface" in
  preprod)
    target=PREPROD
    expected_host=agency-preprod-01
    test "$expected_user" = agency-preprod
    ;;
  prod)
    target=PROD
    expected_host=emergingdigital
    ;;
  *) exit 64 ;;
esac
test "$(hostname -s)" = "$expected_host"
test "$(id -un)" = "$expected_user"
test "$EUID" -ne 0
source /etc/os-release
test "$VERSION_ID" = 24.04

task_tmp="$(mktemp -d)"
trap 'rm -rf -- "$task_tmp"' EXIT
mkdir -p "$task_tmp/lists/partial" "$task_tmp/cache/archives/partial"
opts=(
  -o "Dir::State::lists=$task_tmp/lists"
  -o "Dir::Cache::archives=$task_tmp/cache/archives"
  -o "Dir::Cache::pkgcache=$task_tmp/cache/pkgcache.bin"
  -o "Dir::Cache::srcpkgcache=$task_tmp/cache/srcpkgcache.bin"
  -o "Dir::State::status=/var/lib/dpkg/status"
  -o "Debug::NoLocking=1"
  -o "APT::Update::Error-Mode=any"
  -o "Acquire::Retries=2"
)

# PHP-only PLAN: no privilege to inspect private MariaDB sources or pinning.
apt-get "${opts[@]}" update >"$task_tmp/update.log" 2>&1 || {
  printf '{"STATUS":"FAIL","ISSUE":1451,"TARGET":"%s","FAILURE_STAGE":"APT_REFRESH","REAL_PACKAGE_MUTATION":"NONE"}\n' "$target"
  echo "AGENCY_1451_CONTROLLED_STOP=APT_REFRESH" >&2
  exit 76
}

dpkg-query -W -f='${binary:Package}\t${Status}\t${Version}\n' 'php8.4*' 'php8.5*' 2>/dev/null |
  awk -F '\t' '$2 == "install ok installed" { print $1 "\t" $3 }' |
  LC_ALL=C sort -u >"$task_tmp/installed.tsv"
mapfile -t packages < <(cut -f1 "$task_tmp/installed.tsv")
test "${#packages[@]}" -gt 0
printf '%s\n' "${packages[@]}" | grep -Eq '^php8[.]5-fpm$'
printf '%s\n' "${packages[@]}" | grep -Eq '^php8[.]4-fpm$'
for package in "${packages[@]}"; do
  [[ "$package" =~ ^php8[.][45]-[a-z0-9.+-]+$ ]]
done

apt-cache "${opts[@]}" policy php8.5-fpm >"$task_tmp/provenance.txt"
grep -Eq "https://(ppa[.]launchpadcontent[.]net|ppa[.]setup-php[.]com)/ondrej/php/ubuntu" "$task_tmp/provenance.txt"
: >"$task_tmp/candidates.tsv"
for package in "${packages[@]}"; do
  candidate="$(apt-cache "${opts[@]}" policy "$package" | awk '$1 == "Candidate:" { print $2 }')"
  test -n "$candidate"
  printf '%s\t%s\n' "$package" "$candidate" >>"$task_tmp/candidates.tsv"
done
apt-get "${opts[@]}" --simulate install "${packages[@]}" >"$task_tmp/simulation.log" 2>&1 || {
  printf '{"STATUS":"FAIL","ISSUE":1451,"TARGET":"%s","FAILURE_STAGE":"APT_SIMULATION","REAL_PACKAGE_MUTATION":"NONE"}\n' "$target"
  echo "AGENCY_1451_CONTROLLED_STOP=APT_SIMULATION" >&2
  exit 76
}

export MAIN_SHA="$main_sha" PLAN_ID="$plan_id" TARGET="$target"
export EXPECTED_HOST="$expected_host" EXPECTED_USER="$expected_user" TASK_TMP="$task_tmp"
python3 - <<'PY'
import hashlib
import json
import os
import re
from pathlib import Path

root = Path(os.environ['TASK_TMP'])
installed = dict(line.split('\t') for line in (root / 'installed.tsv').read_text().splitlines())
candidates = dict(line.split('\t') for line in (root / 'candidates.tsv').read_text().splitlines())
simulation = (root / 'simulation.log').read_text(errors='replace').splitlines()
assert installed and installed.keys() == candidates.keys()
assert 'php8.4-fpm' in installed and 'php8.5-fpm' in installed
allowed_target = {'8.4': '8.4.27-', '8.5': '8.5.12-'}
for package, candidate in candidates.items():
    branch = package[3:6]
    if not candidate.startswith(allowed_target[branch] + '1+ubuntu24.04.1+deb.sury.org+1'):
        raise SystemExit('Unexpected PHP package candidate: ' + package)
upgrades = {}
for line in simulation:
    if line.startswith('Remv '):
        raise SystemExit('Simulation would remove packages')
    if line.startswith('Inst '):
        match = re.match(r'^Inst\s+(\S+)\s+\[([^\]]+)\]\s+\((\S+)', line)
        if not match:
            raise SystemExit('Simulation adds a package or has unparseable Inst line')
        name, from_version, to_version = match.groups()
        if name not in installed or from_version != installed[name] or to_version != candidates[name]:
            raise SystemExit('Simulation package not in exact installed candidate set: ' + name)
        if name in upgrades:
            raise SystemExit('Duplicate simulation upgrade')
        upgrades[name] = {'from': from_version, 'to': to_version}
expected = {name for name in installed if installed[name] != candidates[name]}
if set(upgrades) != expected:
    raise SystemExit('Simulated upgrades do not equal installed PHP candidate changes')
receipt = {
    'STATUS': 'PASS',
    'ISSUE': 1451,
    'MODE': 'READ_ONLY_PHP_PLAN',
    'TARGET': os.environ['TARGET'],
    'EXPECTED_HOST': os.environ['EXPECTED_HOST'],
    'EXPECTED_USER': os.environ['EXPECTED_USER'],
    'MAIN_SHA': os.environ['MAIN_SHA'],
    'PLAN_ID': os.environ['PLAN_ID'],
    'PHP84_CANDIDATE': candidates['php8.4-fpm'],
    'PHP85_CANDIDATE': candidates['php8.5-fpm'],
    'INSTALLED_PHP_PACKAGE_COUNT': len(installed),
    'UPGRADE_COUNT': len(upgrades),
    'PACKAGE_UPGRADES': dict(sorted(upgrades.items())),
    'PACKAGE_ADDITIONS': [],
    'PACKAGE_REMOVALS': [],
    'MARIADB_AND_UBUNTU': 'OUT_OF_SCOPE_NOT_ATTESTED',
    'PHP_SOURCE': 'SIGNED_ONDREJ_PPA',
    'REAL_PACKAGE_MUTATION': 'NONE',
    'APT_STATE_MUTATION': 'ISOLATED_TEMPORARY_ONLY',
    'SAFETY_GATE': 'PASS',
}
canonical = json.dumps(receipt, sort_keys=True, separators=(',', ':')).encode()
receipt['PLAN_DIGEST'] = hashlib.sha256(canonical).hexdigest()
print(json.dumps(receipt, sort_keys=True, separators=(',', ':')))
PY
