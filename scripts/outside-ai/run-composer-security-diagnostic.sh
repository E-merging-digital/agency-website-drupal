#!/usr/bin/env bash
set -euo pipefail

artifact_root="${ARTIFACT_ROOT:?ARTIFACT_ROOT is required}"
mkdir -p "$artifact_root"

for command in ddev git python3; do
  command -v "$command" >/dev/null
 done

test -f composer.json
test -f composer.lock
test -z "$(git status --porcelain --untracked-files=no)"

original_dir="$(mktemp -d)"
cp composer.json "$original_dir/composer.json"
cp composer.lock "$original_dir/composer.lock"

cleanup() {
  local exit_code=$?
  local restore_failed=0
  trap - EXIT
  set +e

  cp "$original_dir/composer.json" composer.json || restore_failed=1
  cp "$original_dir/composer.lock" composer.lock || restore_failed=1
  cmp -s "$original_dir/composer.json" composer.json || restore_failed=1
  cmp -s "$original_dir/composer.lock" composer.lock || restore_failed=1

  if [[ "$restore_failed" -eq 0 ]]; then
    printf 'PASS\n' > "$artifact_root/composer-files-restored.txt"
  else
    printf 'FAIL\n' > "$artifact_root/composer-files-restored.txt"
    exit_code=70
  fi

  rm -rf "$original_dir"
  exit "$exit_code"
}
trap cleanup EXIT

ddev start -y

set +e
ddev composer audit --locked --format=json \
  > "$artifact_root/baseline-audit.json"
baseline_audit_exit=$?
set -e
printf '%s\n' "$baseline_audit_exit" \
  > "$artifact_root/baseline-audit-exit-code.txt"
python3 -m json.tool "$artifact_root/baseline-audit.json" >/dev/null
if [[ "$baseline_audit_exit" -ne 0 && "$baseline_audit_exit" -ne 1 ]]; then
  exit "$baseline_audit_exit"
fi

set +e
ddev composer require \
  'drupal/tool:1.0.0-beta7' \
  'drupal/tool_belt:1.0.0-alpha5' \
  'drupal/mcp_server:2.0.0-beta2' \
  'drupal/mcp_server_tool_bridge-mcp_server_tool_bridge:1.0.0-beta1' \
  --with-all-dependencies \
  --no-interaction \
  --no-progress \
  > "$artifact_root/pilot-solve.log" 2>&1
solve_exit=$?
set -e
printf '%s\n' "$solve_exit" > "$artifact_root/pilot-solve-exit-code.txt"

python3 - composer.lock <<'PY'
import json
import sys

expected = {
    'drupal/tool': '1.0.0-beta7',
    'drupal/tool_belt': '1.0.0-alpha5',
    'drupal/mcp_server': '2.0.0-beta2',
    'drupal/mcp_server_tool_bridge-mcp_server_tool_bridge': '1.0.0-beta1',
}
lock = json.load(open(sys.argv[1], encoding='utf-8'))
packages = lock.get('packages', []) + lock.get('packages-dev', [])
versions = {package.get('name'): package.get('version') for package in packages}
for name, version in expected.items():
    if versions.get(name) != version:
        raise SystemExit(
            f'Unexpected temporary solve for {name}: {versions.get(name)!r}'
        )
PY

if [[ "$solve_exit" -gt 1 ]]; then
  exit "$solve_exit"
fi

set +e
ddev composer audit --locked --format=json \
  > "$artifact_root/pilot-audit.json"
pilot_audit_exit=$?
set -e
printf '%s\n' "$pilot_audit_exit" \
  > "$artifact_root/pilot-audit-exit-code.txt"
python3 -m json.tool "$artifact_root/pilot-audit.json" >/dev/null
if [[ "$pilot_audit_exit" -ne 0 && "$pilot_audit_exit" -ne 1 ]]; then
  exit "$pilot_audit_exit"
fi

affected_package="$(python3 - \
  "$artifact_root/pilot-audit.json" \
  "$artifact_root/advisory-summary.json" <<'PY'
import json
import sys

source_path, target_path = sys.argv[1:]
data = json.load(open(source_path, encoding='utf-8'))
items = []
for package, advisories in (data.get('advisories') or {}).items():
    for advisory in advisories:
        items.append((package, advisory))

if len(items) != 1:
    raise SystemExit(f'Expected exactly one pilot advisory, got {len(items)}')

package, advisory = items[0]
advisory_id = advisory.get('advisoryId') or ''
cve_or_ghsa = advisory.get('cve') or ''
if not cve_or_ghsa:
    for source in advisory.get('sources') or []:
        remote_id = str(source.get('remoteId') or '')
        if remote_id.startswith('GHSA-'):
            cve_or_ghsa = remote_id
            break

affected_versions = advisory.get('affectedVersions') or ''
fixed = advisory.get('fixedVersions') or advisory.get('fixedVersion') or ''
if isinstance(fixed, list):
    fixed = ','.join(str(value) for value in fixed)

summary = {
    'AFFECTED_PACKAGE': package,
    'ADVISORY_ID': advisory_id,
    'CVE_OR_GHSA': cve_or_ghsa,
    'AFFECTED_VERSION_RANGE': affected_versions,
    'FIXED_VERSION_IF_ANY': fixed,
}
with open(target_path, 'w', encoding='utf-8') as handle:
    json.dump(summary, handle, indent=2, sort_keys=True)
    handle.write('\n')
print(package)
PY
)"

test -n "$affected_package"

set +e
ddev composer why "$affected_package" --tree \
  > "$artifact_root/dependency-path.txt" 2>&1
why_exit=$?
set -e
printf '%s\n' "$why_exit" > "$artifact_root/dependency-path-exit-code.txt"
test "$why_exit" -eq 0

python3 - \
  "$original_dir/composer.lock" \
  composer.lock \
  "$affected_package" \
  "$artifact_root/baseline-audit.json" \
  "$artifact_root/advisory-summary.json" \
  "$artifact_root/package-comparison.json" <<'PY'
import json
import sys

baseline_lock_path, pilot_lock_path, package, baseline_audit_path, summary_path, target_path = sys.argv[1:]

def version_for(path, name):
    lock = json.load(open(path, encoding='utf-8'))
    packages = lock.get('packages', []) + lock.get('packages-dev', [])
    matches = [item for item in packages if item.get('name') == name]
    if not matches:
        return None
    if len(matches) != 1:
        raise SystemExit(f'Package appears multiple times in lock: {name}')
    return matches[0].get('version')

baseline_version = version_for(baseline_lock_path, package)
pilot_version = version_for(pilot_lock_path, package)
if pilot_version is None:
    raise SystemExit(f'Affected package missing from pilot lock: {package}')

if baseline_version is None:
    origin = 'PILOT_INTRODUCED'
elif baseline_version == pilot_version:
    origin = 'BASELINE_PRESENT_UNCHANGED'
else:
    origin = 'BASELINE_PRESENT_VERSION_CHANGED_BY_PILOT_SOLVE'

summary = json.load(open(summary_path, encoding='utf-8'))
baseline_audit = json.load(open(baseline_audit_path, encoding='utf-8'))
baseline_advisory_present = False
for advisory in (baseline_audit.get('advisories') or {}).get(package, []):
    if summary['ADVISORY_ID'] and advisory.get('advisoryId') == summary['ADVISORY_ID']:
        baseline_advisory_present = True
        break

result = {
    'BASELINE_PACKAGE_PRESENT': 'YES' if baseline_version is not None else 'NO',
    'BASELINE_VERSION': baseline_version or '',
    'PILOT_VERSION': pilot_version,
    'BASELINE_PRESENT_OR_PILOT_INTRODUCED': origin,
    'BASELINE_ADVISORY_PRESENT': 'YES' if baseline_advisory_present else 'NO',
}
with open(target_path, 'w', encoding='utf-8') as handle:
    json.dump(result, handle, indent=2, sort_keys=True)
    handle.write('\n')
PY

security_audit='PASS'
security_exit=0
if [[ "$baseline_audit_exit" -ne 0 || "$pilot_audit_exit" -ne 0 ]]; then
  security_audit='FAIL'
  security_exit=1
fi

python3 - \
  "$artifact_root/advisory-summary.json" \
  "$artifact_root/package-comparison.json" \
  "$baseline_audit_exit" \
  "$pilot_audit_exit" \
  "$solve_exit" \
  "$security_audit" \
  "$artifact_root/result.json" <<'PY'
import json
import sys

summary_path, comparison_path, baseline_exit, pilot_exit, solve_exit, security_audit, target_path = sys.argv[1:]
summary = json.load(open(summary_path, encoding='utf-8'))
comparison = json.load(open(comparison_path, encoding='utf-8'))
result = {
    'DIAGNOSTIC_EXECUTION': 'PASS',
    'SECURITY_AUDIT': security_audit,
    'BASELINE_AUDIT_EXIT_CODE': int(baseline_exit),
    'PILOT_AUDIT_EXIT_CODE': int(pilot_exit),
    'PILOT_SOLVE_EXIT_CODE': int(solve_exit),
    **summary,
    **comparison,
}
with open(target_path, 'w', encoding='utf-8') as handle:
    json.dump(result, handle, indent=2, sort_keys=True)
    handle.write('\n')
PY

cat "$artifact_root/result.json"
exit "$security_exit"
