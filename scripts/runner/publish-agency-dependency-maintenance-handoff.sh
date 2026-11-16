#!/usr/bin/env bash
set -euo pipefail

# Reuse the existing runner-health anti-symlink checks and atomic file pattern.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=publish-agency-runner-health-handoff.sh
source "$SCRIPT_DIR/publish-agency-runner-health-handoff.sh"

DEPENDENCY_HANDOFF_TARGET='/home/agency-runner/.local/share/emerging-digital/agency-dependency-maintenance/agency-dependency-maintenance-state.json'
MAX_DEPENDENCY_BYTES=1048576

publish_agency_dependency_handoff() {
  local source_file="$1" target_file="$2" expected_sha="$3" expected_digest="$4"
  local parent size observed age actual_digest tmp=''

  [[ "$(basename -- "$target_file")" == 'agency-dependency-maintenance-state.json' ]] || { fail 'unexpected target filename'; return 1; }
  [[ "$expected_sha" =~ ^[0-9a-f]{40}$ && "$expected_digest" =~ ^[0-9a-f]{64}$ ]] || { fail 'invalid expected source identity'; return 1; }
  [[ -f "$source_file" && ! -L "$source_file" && -s "$source_file" ]] || { fail 'unsafe source file'; return 1; }
  reject_symlink_components "$(dirname -- "$source_file")" || return 1
  size="$(wc -c < "$source_file")"
  [[ "$size" =~ ^[0-9]+$ ]] && (( size <= MAX_DEPENDENCY_BYTES )) || { fail 'oversized source'; return 1; }
  command -v jq >/dev/null && command -v sha256sum >/dev/null || { fail 'validation tools unavailable'; return 1; }
  actual_digest="$(sha256sum "$source_file" | awk '{print $1}')"
  [[ "$actual_digest" == "$expected_digest" ]] || { fail 'artifact byte digest mismatch'; return 1; }

  jq -e --arg sha "$expected_sha" '
    type == "object"
    and .schema_version == 1
    and .project == "agency-website"
    and .repository == "E-merging-digital/agency-website-drupal"
    and .repository_sha == $sha
    and .execution_capability == "read_only"
    and (.collected_at_utc | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
    and (.overall | IN("OK", "UPDATE_AVAILABLE", "SECURITY", "BLOCKED", "ABANDONED", "EOL", "UNKNOWN"))
    and (.security | type == "object" and (.advisory_count | type == "number" and . >= 0) and (.state | IN("OK", "SECURITY")))
    and (.abandoned | type == "object" and (.package_count | type == "number" and . >= 0) and (.state | IN("OK", "ABANDONED")))
    and (.components | type == "array" and length <= 250)
    and all(.components[];
      type == "object" and .execution_capability == "read_only"
      and (.component | type == "string")
      and (.ecosystem | type == "string")
      and (.health | IN("OK", "UPDATE_AVAILABLE", "SECURITY", "BLOCKED", "ABANDONED", "EOL", "UNKNOWN"))
      and (.update_class | IN("NONE", "PATCH", "MINOR", "MAJOR", "PRERELEASE", "SECURITY", "EOL", "UNKNOWN"))
    )
  ' "$source_file" >/dev/null || { fail 'dependency schema or provenance mismatch'; return 1; }
  jq -e '[paths | .[-1] | select(type == "string" and test("(?i)(token|secret|password|passwd|credential|private[_-]?key|bearer|connection_string|database_url|dsn)"))] | length == 0' "$source_file" >/dev/null || { fail 'secret-like field rejected'; return 1; }
  if grep -Eiq '(COMPOSER_AUTH|github_pat_|gh[pousr]_[A-Za-z0-9]|-----BEGIN [A-Z ]*PRIVATE KEY-----|mysql://|mariadb://|postgres(ql)?://)' "$source_file"; then
    fail 'secret-like content rejected'; return 1
  fi
  observed="$(jq -r '.collected_at_utc' "$source_file")"
  observed="$(date -u -d "$observed" +%s)" || { fail 'invalid observation time'; return 1; }
  age="$(($(date -u +%s) - observed))"
  (( age >= -300 && age <= 129600 )) || { fail 'stale or future snapshot'; return 1; }

  parent="$(dirname -- "$target_file")"
  reject_symlink_components "$parent" || return 1
  if [[ -e "$parent" ]]; then
    [[ -d "$parent" && ! -L "$parent" && "$(stat -c '%u:%a' "$parent")" == "$(id -u):700" ]] || { fail 'unsafe existing parent'; return 1; }
  else
    install -d -m 700 "$parent"
  fi
  reject_symlink_components "$parent" || return 1
  [[ -d "$parent" && ! -L "$parent" && "$(stat -c '%u:%a' "$parent")" == "$(id -u):700" ]] || { fail 'unsafe target parent'; return 1; }
  if [[ -e "$target_file" || -L "$target_file" ]]; then
    [[ -f "$target_file" && ! -L "$target_file" && "$(stat -c '%u:%a' "$target_file")" == "$(id -u):600" ]] || { fail 'unsafe existing target'; return 1; }
  fi

  tmp="$(mktemp "$parent/.agency-dependency-maintenance-state.json.tmp.XXXXXX")"
  trap '[[ -z "${tmp:-}" ]] || rm -f -- "$tmp"' RETURN
  cp -- "$source_file" "$tmp"
  chmod 600 "$tmp"
  [[ "$(sha256sum "$tmp" | awk '{print $1}')" == "$expected_digest" ]] || { fail 'temporary byte mismatch'; return 1; }
  cmp -s -- "$source_file" "$tmp" || { fail 'temporary source mismatch'; return 1; }
  mv -fT -- "$tmp" "$target_file"
  tmp=''
  [[ "$(stat -c '%u:%a' "$target_file")" == "$(id -u):600" ]] || { fail 'published file permission mismatch'; return 1; }
  cmp -s -- "$source_file" "$target_file" || { fail 'published bytes mismatch'; return 1; }
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  [[ "$#" -eq 1 ]] || { fail 'usage: publish-agency-dependency-maintenance-handoff.sh SOURCE'; exit 64; }
  [[ "$(id -un)" == 'agency-runner' && "$(id -u)" == '1002' && "$(hostname)" == 'preflight-runner-01' && "${RUNNER_NAME:-}" == 'agency-browser-runner-01' ]] || { fail 'unexpected runner identity'; exit 65; }
  [[ "${GITHUB_EVENT_NAME:-}" =~ ^(push|schedule)$ && "${GITHUB_REF:-}" == 'refs/heads/main' && "${GITHUB_REPOSITORY:-}" == 'E-merging-digital/agency-website-drupal' && "${EXPECTED_SOURCE_SHA:-}" == "${GITHUB_SHA:-}" ]] || { fail 'untrusted workflow source'; exit 66; }
  publish_agency_dependency_handoff "$1" "$DEPENDENCY_HANDOFF_TARGET" "${EXPECTED_SOURCE_SHA:-}" "${EXPECTED_ARTIFACT_SHA256:-}"
fi
