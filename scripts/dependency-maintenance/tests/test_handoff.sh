#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
# Test only an isolated temp tree. Never invoke the entrypoint against the runner path.
# shellcheck source=../../runner/publish-agency-dependency-maintenance-handoff.sh
source "$ROOT/scripts/runner/publish-agency-dependency-maintenance-handoff.sh"

temp="$(mktemp -d)"
trap 'rm -rf -- "$temp"' EXIT
source_file="$temp/validated.json"
target_dir="$temp/private"
target_file="$target_dir/agency-dependency-maintenance-state.json"
sha='f27a2b6a03555eaf63ff101083e6992f0106a6f3'
passes=0

make_source() {
  jq -n --arg sha "$sha" --arg now "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '{
    schema_version: 1,
    project: "agency-website", repository: "E-merging-digital/agency-website-drupal",
    repository_sha: $sha, collected_at_utc: $now, overall: "BLOCKED",
    execution_capability: "read_only", security: {advisory_count: 0, state: "OK"},
    abandoned: {package_count: 0, state: "OK"},
    components: [{component: "drupal/coder", ecosystem: "composer", installed_version: "8.3.31", available_version: "8.3.31", compatible_available_version: "8.3.31", latest_available_version: "9.0.1", update_class: "MAJOR", health: "BLOCKED", source: "composer.lock", impact: "blocked", recommended_action: "WAIT", execution_capability: "read_only"}]
  }' > "$source_file"
}

digest() { sha256sum "$source_file" | awk '{print $1}'; }
check_reject() {
  local label="$1" old_digest
  old_digest="$(sha256sum "$target_file" | awk '{print $1}')"
  if publish_agency_dependency_handoff "$source_file" "$target_file" "$sha" "$(digest)" > /dev/null 2>&1; then
    printf 'FAIL expected rejection: %s\n' "$label" >&2; exit 1
  fi
  [[ "$(sha256sum "$target_file" | awk '{print $1}')" == "$old_digest" ]] || { echo "FAIL old bytes changed: $label" >&2; exit 1; }
  ((++passes))
}

make_source
publish_agency_dependency_handoff "$source_file" "$target_file" "$sha" "$(digest)"
[[ "$(stat -c '%a' "$target_dir")" == 700 && "$(stat -c '%a' "$target_file")" == 600 ]]
[[ "$(stat -c '%u' "$target_file")" == "$(id -u)" ]]
cmp -s -- "$source_file" "$target_file"
((++passes))

# Exact-byte source changes are permitted, but an artifact digest cannot be substituted.
make_source
if publish_agency_dependency_handoff "$source_file" "$target_file" "$sha" "$(printf '0%.0s' {1..64})" >/dev/null 2>&1; then echo 'FAIL unexpected digest accepted'; exit 1; fi
((++passes))

# Validate the immutable last known good state after all rejected inputs.
make_source; jq '.repository_sha = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"' "$source_file" > "$temp/edit"; mv "$temp/edit" "$source_file"; check_reject repo_sha
make_source; jq '.project = "other-project"' "$source_file" > "$temp/edit"; mv "$temp/edit" "$source_file"; check_reject project
make_source; jq '.execution_capability = "write"' "$source_file" > "$temp/edit"; mv "$temp/edit" "$source_file"; check_reject capability
make_source; jq '.secret = "SYNTHETIC"' "$source_file" > "$temp/edit"; mv "$temp/edit" "$source_file"; check_reject secret_field
make_source; jq '.collected_at_utc = "2000-01-01T00:00:00Z"' "$source_file" > "$temp/edit"; mv "$temp/edit" "$source_file"; check_reject stale
make_source; jq '.components[0].health = "EXECUTABLE"' "$source_file" > "$temp/edit"; mv "$temp/edit" "$source_file"; check_reject component_contract
make_source; printf '%s\n' 'COMPOSER_AUTH' >> "$source_file"; check_reject invalid_json_secret_marker
make_source; printf '%1048577s' 'x' > "$source_file"; check_reject oversized
make_source; ln -s "$source_file" "$temp/symlink.json"; if publish_agency_dependency_handoff "$temp/symlink.json" "$target_file" "$sha" "$(digest)" >/dev/null 2>&1; then echo 'FAIL source symlink accepted'; exit 1; fi; ((++passes))
make_source; ln -s "$target_file" "$temp/target-link"; if publish_agency_dependency_handoff "$source_file" "$temp/target-link" "$sha" "$(digest)" >/dev/null 2>&1; then echo 'FAIL wrong target filename accepted'; exit 1; fi; ((++passes))
make_source; chmod 644 "$target_file"; check_reject unsafe_permissions; chmod 600 "$target_file"
make_source; ln -s "$temp" "$temp/alias"; if publish_agency_dependency_handoff "$source_file" "$temp/alias/agency-dependency-maintenance-state.json" "$sha" "$(digest)" >/dev/null 2>&1; then echo 'FAIL symlink component accepted'; exit 1; fi; ((++passes))
make_source; echo 'before' > "$temp/not-regular"; if publish_agency_dependency_handoff "$source_file" "$temp/not-regular/agency-dependency-maintenance-state.json" "$sha" "$(digest)" >/dev/null 2>&1; then echo 'FAIL non-directory parent accepted'; exit 1; fi; ((++passes))

workflow="$ROOT/.github/workflows/dependency-maintenance-state.yml"
# Structural source guards: PR snapshots remain GitHub-hosted, no PR code on the runner.
grep -Fq 'runs-on: ubuntu-24.04' "$workflow"
grep -Fq "github.event_name == 'push' || github.event_name == 'schedule'" "$workflow"
grep -Fq "github.ref == 'refs/heads/main'" "$workflow"
grep -Fq "needs.snapshot.result == 'success'" "$workflow"
grep -Fq 'actions/download-artifact@v4' "$workflow"
grep -Fq 'EXPECTED_ARTIFACT_SHA256' "$workflow"
grep -Fq 'persist-credentials: false' "$workflow"
if grep -Eq 'pull_request_target:|workflow_run:|actions: write' "$workflow"; then echo 'FAIL unsafe self-hosted trigger/permission'; exit 1; fi
((++passes))

printf 'HANDOFF_SOURCE_TESTS=PASS / %s cases / TEMP_ONLY / RUNNER_WRITE=NO\n' "$passes"
