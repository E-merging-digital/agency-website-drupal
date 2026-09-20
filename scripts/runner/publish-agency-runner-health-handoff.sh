#!/usr/bin/env bash
set -euo pipefail

HANDOFF_TARGET='/home/agency-runner/.local/share/emerging-digital/agency-runner-health/agency-runner-health.json'
MAX_RECEIPT_BYTES=8192

fail() {
  printf 'runner-health-handoff: %s\n' "$*" >&2
  return 1
}

reject_symlink_components() {
  local path="$1"
  local current='/'
  local part
  local -a parts=()
  IFS='/' read -r -a parts <<< "${path#/}"
  for part in "${parts[@]}"; do
    [[ -n "$part" ]] || continue
    current="${current%/}/$part"
    if [[ -L "$current" ]]; then
      fail "symlink path component rejected: $current"
      return 1
    fi
  done
}

publish_agency_runner_health_handoff() {
  local source_file="$1"
  local target_file="$2"
  local parent bytes owner_uid tmp=''

  [[ "$(basename -- "$target_file")" == 'agency-runner-health.json' ]] || {
    fail "unexpected target filename"
    return 1
  }
  [[ -f "$source_file" && ! -L "$source_file" && -s "$source_file" ]] || {
    fail "source receipt is missing or unsafe"
    return 1
  }

  bytes="$(wc -c < "$source_file")"
  [[ "$bytes" =~ ^[0-9]+$ ]] && (( bytes <= MAX_RECEIPT_BYTES )) || {
    fail "source receipt exceeds bounded size"
    return 1
  }
  command -v jq >/dev/null || {
    fail "jq CLI missing"
    return 1
  }

  jq -e 'type == "object"' "$source_file" >/dev/null || {
    fail "source receipt is not valid JSON"
    return 1
  }
  if grep -Eiq \
    '("?(token|secret|password|credential|private[_ -]?key|ssh[_ -]?key)"?[[:space:]]*:|github_pat_|gh[pousr]_[A-Za-z0-9]|-----BEGIN [A-Z ]*PRIVATE KEY-----)' \
    "$source_file"; then
    fail "source receipt failed secret scan"
    return 1
  fi

  parent="$(dirname -- "$target_file")"
  reject_symlink_components "$parent" || return 1
  install -d -m 700 "$parent"
  reject_symlink_components "$parent" || return 1
  [[ -d "$parent" && ! -L "$parent" ]] || {
    fail "target parent is unsafe"
    return 1
  }

  owner_uid="$(stat -c '%u' "$parent")"
  [[ "$owner_uid" == "$(id -u)" ]] || {
    fail "target parent is not owned by the current runner identity"
    return 1
  }
  chmod 700 "$parent"

  if [[ -e "$target_file" && ( -L "$target_file" || ! -f "$target_file" ) ]]; then
    fail "existing target is not a regular file"
    return 1
  fi

  tmp="$(mktemp "$parent/.agency-runner-health.json.tmp.XXXXXX")"
  trap '[[ -z "${tmp:-}" ]] || rm -f -- "$tmp"' RETURN
  cp -- "$source_file" "$tmp"
  chmod 600 "$tmp"
  cmp -s -- "$source_file" "$tmp" || {
    fail "temporary copy differs from source"
    return 1
  }

  mv -fT -- "$tmp" "$target_file"
  tmp=''
  chmod 600 "$target_file"
  cmp -s -- "$source_file" "$target_file" || {
    fail "published receipt differs from source"
    return 1
  }
}

main() {
  [[ "$#" -eq 1 ]] || {
    fail "usage: $0 <validated-receipt>"
    return 64
  }
  publish_agency_runner_health_handoff "$1" "$HANDOFF_TARGET"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi