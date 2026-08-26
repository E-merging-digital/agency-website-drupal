#!/usr/bin/env bash
set -euo pipefail
umask 077

MODE="${1:-}"
if [[ "$#" -ne 1 ]] || [[ "$MODE" != 'VERIFY_ONLY' && "$MODE" != 'PROVISION' ]]; then
  echo 'Mode must be exactly VERIFY_ONLY or PROVISION.' >&2
  exit 64
fi

TRUST_SOURCE='OUT_OF_BAND_PROD_CONSOLE'
PINNED_KEY_PATH='scripts/production-ssh-trust/prod-ed25519.pub'
PINNED_FINGERPRINT_PATH='scripts/production-ssh-trust/prod-ed25519.sha256'

if [[ ! -f "$PINNED_KEY_PATH" || ! -f "$PINNED_FINGERPRINT_PATH" ]]; then
  echo 'Pinned PROD SSH trust material is incomplete.' >&2
  exit 65
fi

read -r PINNED_KEY_TYPE PINNED_KEY_BLOB PINNED_KEY_COMMENT PINNED_KEY_EXTRA < "$PINNED_KEY_PATH"
if [[ "$PINNED_KEY_TYPE" != 'ssh-ed25519' ]] || [[ -z "$PINNED_KEY_BLOB" ]] || [[ -n "${PINNED_KEY_EXTRA:-}" ]]; then
  echo 'Pinned PROD SSH public key has an unexpected format.' >&2
  exit 66
fi

EXPECTED_FINGERPRINT="$(tr -d '\r\n' < "$PINNED_FINGERPRINT_PATH")"
if [[ ! "$EXPECTED_FINGERPRINT" =~ ^SHA256:[A-Za-z0-9+/]+$ ]]; then
  echo 'Pinned PROD SSH fingerprint has an unexpected format.' >&2
  exit 67
fi

PINNED_FINGERPRINT="$(ssh-keygen -lf "$PINNED_KEY_PATH" -E sha256 | awk '{print $2}')"
if [[ "$PINNED_FINGERPRINT" != "$EXPECTED_FINGERPRINT" ]]; then
  echo 'Pinned PROD SSH key does not match the pinned fingerprint.' >&2
  exit 68
fi

SERVER_HOST="${SERVER_HOST:-}"
if [[ ! "$SERVER_HOST" =~ ^[A-Za-z0-9._:-]+$ ]]; then
  echo 'SERVER_HOST is invalid.' >&2
  exit 69
fi

if [[ -z "${HOME:-}" ]]; then
  echo 'HOME is required for known_hosts management.' >&2
  exit 70
fi

SSH_DIR="$HOME/.ssh"
KNOWN_HOSTS="$SSH_DIR/known_hosts"
if [[ -e "$KNOWN_HOSTS" && ! -f "$KNOWN_HOSTS" ]]; then
  echo 'known_hosts is not a regular file.' >&2
  exit 71
fi

matching_entries() {
  if [[ ! -f "$KNOWN_HOSTS" ]]; then
    return 0
  fi
  ssh-keygen -F "$SERVER_HOST" -f "$KNOWN_HOSTS" 2>/dev/null | grep -v '^#' || true
}

count_entries() {
  local entries="$1"
  if [[ -z "$entries" ]]; then
    printf '0\n'
  else
    printf '%s\n' "$entries" | awk 'NF { count++ } END { print count + 0 }'
  fi
}

validate_single_entry() {
  local entries="$1"
  local count
  count="$(count_entries "$entries")"
  if [[ "$count" -ne 1 ]]; then
    echo 'SERVER_HOST must resolve to exactly one known_hosts entry.' >&2
    exit 72
  fi

  local line host_field key_type key_blob extra
  line="$(printf '%s\n' "$entries" | awk 'NF { print; exit }')"
  read -r host_field key_type key_blob extra <<< "$line"
  if [[ -z "$host_field" || "$key_type" != 'ssh-ed25519' || -z "$key_blob" || -n "${extra:-}" ]]; then
    echo 'SERVER_HOST known_hosts entry has an unexpected format or key type.' >&2
    exit 73
  fi
  if [[ "$key_blob" != "$PINNED_KEY_BLOB" ]]; then
    echo 'SERVER_HOST known_hosts key does not match the pinned PROD key.' >&2
    exit 74
  fi

  local actual_key_file actual_fingerprint
  actual_key_file="$(mktemp)"
  trap 'rm -f "$actual_key_file"' RETURN
  printf '%s %s\n' "$key_type" "$key_blob" > "$actual_key_file"
  chmod 600 "$actual_key_file"
  actual_fingerprint="$(ssh-keygen -lf "$actual_key_file" -E sha256 | awk '{print $2}')"
  rm -f "$actual_key_file"
  trap - RETURN

  if [[ "$actual_fingerprint" != "$EXPECTED_FINGERPRINT" ]]; then
    echo 'SERVER_HOST known_hosts fingerprint does not match the pinned PROD fingerprint.' >&2
    exit 75
  fi

  printf 'ENTRY_COUNT_FOR_SERVER_HOST=1\n'
  printf 'KEY_TYPE=ssh-ed25519\n'
  printf 'KEY_BLOB_MATCH=PASS\n'
  printf 'FINGERPRINT=%s\n' "$actual_fingerprint"
  printf 'TRUST_SOURCE=%s\n' "$TRUST_SOURCE"
  printf 'KNOWN_HOSTS_MATCH=PASS\n'
}

entries_before="$(matching_entries)"
count_before="$(count_entries "$entries_before")"

if [[ "$count_before" -gt 1 ]]; then
  echo 'SERVER_HOST has multiple known_hosts entries; refusing ambiguity.' >&2
  exit 76
fi

if [[ "$count_before" -eq 1 ]]; then
  validate_single_entry "$entries_before" >/dev/null
fi

if [[ "$count_before" -eq 0 ]]; then
  if [[ "$MODE" == 'VERIFY_ONLY' ]]; then
    echo 'Pinned PROD host identity is absent from known_hosts.' >&2
    exit 77
  fi

  mkdir -p "$SSH_DIR"
  chmod 700 "$SSH_DIR"
  touch "$KNOWN_HOSTS"
  chmod 600 "$KNOWN_HOSTS"
  printf '%s %s %s\n' "$SERVER_HOST" "$PINNED_KEY_TYPE" "$PINNED_KEY_BLOB" >> "$KNOWN_HOSTS"
else
  if [[ "$MODE" == 'PROVISION' ]]; then
    chmod 700 "$SSH_DIR"
    chmod 600 "$KNOWN_HOSTS"
  fi
fi

entries_after="$(matching_entries)"
validate_single_entry "$entries_after"
