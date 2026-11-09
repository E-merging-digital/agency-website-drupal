#!/usr/bin/env bash
set -euo pipefail

# Reuse the runner-health handoff path/symlink guards, not a new file transport.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=publish-agency-runner-health-handoff.sh
source "$SCRIPT_DIR/publish-agency-runner-health-handoff.sh"

ENVIRONMENT_DATA_TARGET='/home/agency-runner/.local/share/emerging-digital/agency-environment-data/agency-environment-data-state.json'
MAX_ENVIRONMENT_DATA_BYTES=16384

publish_agency_environment_data_handoff() {
  local source_file="$1" target_file="$2" expected_sha="$3"
  local parent bytes now observed age projection_sha tmp=''

  [[ "$(basename -- "$target_file")" == 'agency-environment-data-state.json' ]] || { fail 'unexpected environment-data target'; return 1; }
  [[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || { fail 'invalid source SHA'; return 1; }
  [[ -f "$source_file" && ! -L "$source_file" && -s "$source_file" ]] || { fail 'unsafe source file'; return 1; }
  bytes="$(wc -c < "$source_file")"
  [[ "$bytes" =~ ^[0-9]+$ ]] && (( bytes <= MAX_ENVIRONMENT_DATA_BYTES )) || { fail 'oversized state'; return 1; }
  command -v jq >/dev/null && command -v sha256sum >/dev/null || { fail 'required validation tools missing'; return 1; }

  jq -e --arg sha "$expected_sha" '
    type == "object"
    and ((keys | sort) == (["_publication", "adapter", "application_type", "capabilities", "development", "environments", "preprod", "project_id", "runtime", "schema_version"] | sort))
    and .schema_version == 1
    and .project_id == "agency-website"
    and .application_type == "drupal"
    and .adapter == {id:"agency-drupal-environment-data", version:"1"}
    and .runtime.environment == "PREPROD"
    and .runtime.release_available == true
    and (.runtime.release_identity | type == "string" and test("^[0-9]{14}-[0-9a-f]{12,40}$"))
    and .runtime.freshness == "current request"
    and .environments.PREPROD.availability == "observed"
    and (.preprod | type == "object")
    and (.development | type == "object")
    and (.capabilities | type == "object")
    and ._publication.schema_version == 1
    and ._publication.source_project == "agency-website"
    and ._publication.source_repository_sha == $sha
    and (._publication.observed_at_utc | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and (._publication.projection_sha256 | type == "string" and test("^[0-9a-f]{64}$"))
    and (._publication.evidence_reference | type == "string" and test("^agency-1309-[0-9]+-[0-9]+$"))
  ' "$source_file" >/dev/null || { fail 'projection contract mismatch'; return 1; }
  jq -e '[paths | .[-1] | select(type == "string" and test("(?i)(token|secret|password|passwd|credential|private[_-]?key|bearer|connection_string|database_url|dsn)"))] | length == 0' "$source_file" >/dev/null || { fail 'secret-like field rejected'; return 1; }
  if grep -Eiq '(github_pat_|gh[pousr]_[A-Za-z0-9]|-----BEGIN [A-Z ]*PRIVATE KEY-----)' "$source_file"; then
    fail 'secret-like content rejected'; return 1
  fi
  projection_sha="$(jq -cS 'del(._publication)' "$source_file" | sha256sum | awk '{print $1}')"
  [[ "$projection_sha" == "$(jq -r '._publication.projection_sha256' "$source_file")" ]] || { fail 'projection digest mismatch'; return 1; }
  observed="$(jq -r '._publication.observed_at_utc' "$source_file")"
  now="$(date -u +%s)"
  observed="$(date -u -d "$observed" +%s)" || { fail 'invalid observed time'; return 1; }
  age="$((now - observed))"
  (( age >= -60 && age <= 900 )) || { fail 'stale or future projection'; return 1; }

  parent="$(dirname -- "$target_file")"
  reject_symlink_components "$parent" || return 1
  install -d -m 700 "$parent"
  reject_symlink_components "$parent" || return 1
  [[ -d "$parent" && ! -L "$parent" && "$(stat -c '%u' "$parent")" == "$(id -u)" ]] || { fail 'unsafe target parent'; return 1; }
  chmod 700 "$parent"
  if [[ -e "$target_file" || -L "$target_file" ]]; then
    [[ -f "$target_file" && ! -L "$target_file" ]] || { fail 'unsafe existing target'; return 1; }
  fi
  tmp="$(mktemp "$parent/.agency-environment-data-state.json.tmp.XXXXXX")"
  trap '[[ -z "${tmp:-}" ]] || rm -f -- "$tmp"' RETURN
  cp -- "$source_file" "$tmp"
  chmod 600 "$tmp"
  cmp -s -- "$source_file" "$tmp" || { fail 'temporary bytes differ'; return 1; }
  mv -fT -- "$tmp" "$target_file"
  tmp=''
  chmod 600 "$target_file"
  cmp -s -- "$source_file" "$target_file" || { fail 'published bytes differ'; return 1; }
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  [[ "$#" -eq 1 ]] || { fail 'usage: publish-agency-environment-data-handoff.sh SOURCE'; exit 64; }
  [[ "$(id -un)" == 'agency-runner' && "${RUNNER_NAME:-}" == 'agency-browser-runner-01' && "$(hostname)" == 'preflight-runner-01' ]] || { fail 'unexpected runner identity'; exit 65; }
  publish_agency_environment_data_handoff "$1" "$ENVIRONMENT_DATA_TARGET" "${EXPECTED_SOURCE_SHA:-}"
fi
