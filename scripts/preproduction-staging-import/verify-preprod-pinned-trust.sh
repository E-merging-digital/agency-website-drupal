#!/usr/bin/env bash
set -Eeuo pipefail

PROFILE='scripts/preproduction-staging-import/profile.json'
KEY_FILE='scripts/preproduction-ssh-trust/preprod-ed25519.pub'
FINGERPRINT_FILE='scripts/preproduction-ssh-trust/preprod-ed25519.sha256'
SERVER_HOST="${PREPROD_SERVER_HOST:-}"
KNOWN_HOSTS="${PREPROD_KNOWN_HOSTS_FILE:-$HOME/.ssh/known_hosts}"

[[ "$SERVER_HOST" =~ ^[A-Za-z0-9._:-]+$ ]] || { echo 'PREPROD host is invalid.' >&2; exit 64; }
test -f "$PROFILE"
state="$(jq -r '.preprod_trust.current_state' "$PROFILE")"
[[ "$state" == 'PINNED' ]] || {
  echo 'PREPROD SSH identity is not repository-pinned; APPLY is blocked.' >&2
  exit 65
}
test -f "$KEY_FILE" || { echo 'Pinned PREPROD ED25519 key is absent.' >&2; exit 66; }
test -f "$FINGERPRINT_FILE" || { echo 'Pinned PREPROD fingerprint is absent.' >&2; exit 67; }
test -f "$KNOWN_HOSTS" || { echo 'Explicit PREPROD known_hosts file is absent.' >&2; exit 68; }

read -r key_type key_blob _ < "$KEY_FILE"
[[ "$key_type" == 'ssh-ed25519' ]] || { echo 'Pinned PREPROD key type is not ED25519.' >&2; exit 69; }
[[ "$key_blob" =~ ^[A-Za-z0-9+/=]+$ ]] || { echo 'Pinned PREPROD key blob is invalid.' >&2; exit 70; }
expected_fingerprint="$(cat "$FINGERPRINT_FILE")"
actual_fingerprint="$(ssh-keygen -lf "$KEY_FILE" -E sha256 | awk '{print $2}')"
[[ "$expected_fingerprint" == "$actual_fingerprint" ]] || { echo 'Pinned PREPROD fingerprint mismatch.' >&2; exit 71; }

mapfile -t entries < <(ssh-keygen -F "$SERVER_HOST" -f "$KNOWN_HOSTS" | awk '$1 !~ /^#/ {print $0}')
[[ "${#entries[@]}" -eq 1 ]] || { echo 'PREPROD known_hosts entry count is not exactly one.' >&2; exit 72; }
read -r known_host_type known_host_blob _ <<< "${entries[0]}"
[[ "$known_host_type" == 'ssh-ed25519' ]] || { echo 'PREPROD known_hosts key type mismatch.' >&2; exit 73; }
[[ "$known_host_blob" == "$key_blob" ]] || { echo 'PREPROD known_hosts key blob mismatch.' >&2; exit 74; }

printf '%s\n' \
  'PREPROD_PINNED_TRUST=PASS' \
  'PREPROD_STRICT_HOST_KEY_CHECKING=YES'
