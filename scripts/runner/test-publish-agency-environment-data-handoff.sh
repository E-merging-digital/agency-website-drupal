#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=publish-agency-environment-data-handoff.sh
source "$SCRIPT_DIR/publish-agency-environment-data-handoff.sh"

root="$(mktemp -d)"
trap 'rm -rf -- "$root"' EXIT
sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
target="$root/private/agency-environment-data-state.json"
projection="$root/projection.json"
receipt="$root/receipt.json"

jq -ncS '{
  schema_version: 1, project_id: "agency-website", application_type: "drupal",
  adapter: {id:"agency-drupal-environment-data", version:"1"},
  runtime: {environment:"PREPROD",release_available:true,release_identity:"20261109123456-aaaaaaaaaaaa",freshness:"current request"},
  environments: {PREPROD:{availability:"observed"}},
  preprod: {available:false,status:"unavailable"},
  development: {available:false,status:"unavailable"},
  capabilities: {}
}' > "$projection"
digest="$(sha256sum "$projection" | awk '{print $1}')"
observed="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
jq -cS --arg sha "$sha" --arg digest "$digest" --arg observed "$observed" '
  ._publication = {
    schema_version:1, source_project:"agency-website", source_repository_sha:$sha,
    observed_at_utc:$observed, projection_sha256:$digest,
    evidence_reference:"agency-1309-123-1"
  }
' "$projection" > "$receipt"

publish_agency_environment_data_handoff "$receipt" "$target" "$sha"
cmp -s "$receipt" "$target"
[[ "$(stat -c '%a' "$target")" == '600' ]]
[[ "$(stat -c '%a' "$(dirname "$target")")" == '700' ]]
[[ "$(stat -c '%u' "$target")" == "$(id -u)" ]]
before="$(sha256sum "$target" | awk '{print $1}')"

reject_case() {
  local input="$1" dest="${2:-$target}"
  if publish_agency_environment_data_handoff "$input" "$dest" "$sha"; then
    echo 'Invalid environment-data handoff accepted' >&2
    exit 1
  fi
  [[ "$(sha256sum "$target" | awk '{print $1}')" == "$before" ]]
}

jq -cS '.project_id = "different"' "$receipt" > "$root/wrong-project.json"
reject_case "$root/wrong-project.json"
jq -cS '.adapter.version = "2"' "$receipt" > "$root/wrong-adapter.json"
reject_case "$root/wrong-adapter.json"
jq -cS '.password = "not-permitted"' "$receipt" > "$root/secret.json"
reject_case "$root/secret.json"
jq -cS '._publication.projection_sha256 = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"' "$receipt" > "$root/wrong-hash.json"
reject_case "$root/wrong-hash.json"
jq -cS '._publication.observed_at_utc = "2000-01-01T00:00:00Z"' "$receipt" > "$root/stale.json"
reject_case "$root/stale.json"
printf '{broken\n' > "$root/broken.json"
reject_case "$root/broken.json"
jq -cS '.padding = ("x" * 17000)' "$receipt" > "$root/oversized.json"
reject_case "$root/oversized.json"

mkdir "$root/actual"
ln -s "$root/actual" "$root/linked"
reject_case "$receipt" "$root/linked/agency-environment-data-state.json"
reject_case "$receipt" "$root/private/unexpected.json"

rm -f -- "$target"
printf '{"original":true}\n' > "$root/victim.json"
ln -s "$root/victim.json" "$target"
if publish_agency_environment_data_handoff "$receipt" "$target" "$sha"; then
  echo 'Symlink target accepted' >&2
  exit 1
fi
cmp -s "$root/victim.json" <(printf '{"original":true}\n')

echo 'AGENCY_ENVIRONMENT_DATA_HANDOFF_TEST=PASS'
