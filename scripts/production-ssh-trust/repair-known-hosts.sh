#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${1:-}"
if [[ "$#" -ne 1 ]] || [[ "$MODE" != 'CLASSIFY' && "$MODE" != 'REPAIR' ]]; then
  echo 'Mode must be exactly CLASSIFY or REPAIR.' >&2
  exit 64
fi

PINNED_KEY_PATH='scripts/production-ssh-trust/prod-ed25519.pub'
PINNED_FINGERPRINT_PATH='scripts/production-ssh-trust/prod-ed25519.sha256'
VERIFY_SCRIPT='scripts/production-ssh-trust/manage-known-host.sh'

for required in "$PINNED_KEY_PATH" "$PINNED_FINGERPRINT_PATH" "$VERIFY_SCRIPT"; do
  [[ -f "$required" ]] || { echo 'Repository-pinned PROD SSH trust tooling is incomplete.' >&2; exit 65; }
done

read -r PINNED_KEY_TYPE PINNED_KEY_BLOB PINNED_KEY_COMMENT PINNED_KEY_EXTRA < "$PINNED_KEY_PATH"
if [[ "$PINNED_KEY_TYPE" != 'ssh-ed25519' ]] || [[ -z "$PINNED_KEY_BLOB" ]] || [[ -n "${PINNED_KEY_EXTRA:-}" ]]; then
  echo 'Pinned PROD SSH public key has an unexpected format.' >&2
  exit 66
fi

EXPECTED_FINGERPRINT="$(tr -d '\r\n' < "$PINNED_FINGERPRINT_PATH")"
ACTUAL_PINNED_FINGERPRINT="$(ssh-keygen -lf "$PINNED_KEY_PATH" -E sha256 | awk '{print $2}')"
if [[ "$EXPECTED_FINGERPRINT" != "$ACTUAL_PINNED_FINGERPRINT" ]]; then
  echo 'Repository-pinned PROD SSH key/fingerprint mismatch.' >&2
  exit 67
fi

SERVER_HOST="${SERVER_HOST:-}"
if [[ ! "$SERVER_HOST" =~ ^[A-Za-z0-9._:-]+$ ]]; then
  echo 'SERVER_HOST is invalid.' >&2
  exit 68
fi
if [[ -z "${HOME:-}" ]]; then
  echo 'HOME is required.' >&2
  exit 69
fi

SSH_DIR="$HOME/.ssh"
KNOWN_HOSTS="$SSH_DIR/known_hosts"
if [[ -e "$KNOWN_HOSTS" && ! -f "$KNOWN_HOSTS" ]]; then
  echo 'known_hosts is not a regular file.' >&2
  exit 70
fi

matching_entries() {
  [[ -f "$KNOWN_HOSTS" ]] || return 0
  ssh-keygen -F "$SERVER_HOST" -f "$KNOWN_HOSTS" 2>/dev/null | grep -v '^#' || true
}

classify_entries() {
  local entries="$1"
  local matching_count=0 pinned_count=0 non_pinned_count=0 alias_present='NO' hashed_present='NO'
  local key_types=''
  local line host_field key_type key_blob extra type_seen

  while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    matching_count=$((matching_count + 1))
    read -r host_field key_type key_blob extra <<< "$line"
    if [[ "$host_field" == '|1|'* ]]; then
      hashed_present='YES'
    elif [[ "$host_field" == *,* ]]; then
      alias_present='YES'
    fi
    if [[ "$key_type" == "$PINNED_KEY_TYPE" && "$key_blob" == "$PINNED_KEY_BLOB" && -z "${extra:-}" ]]; then
      pinned_count=$((pinned_count + 1))
    else
      non_pinned_count=$((non_pinned_count + 1))
    fi
    if [[ -n "$key_type" ]]; then
      type_seen=0
      IFS=',' read -ra current_types <<< "$key_types"
      for current in "${current_types[@]}"; do
        [[ "$current" == "$key_type" ]] && type_seen=1
      done
      if [[ "$type_seen" -eq 0 ]]; then
        key_types="${key_types:+$key_types,}$key_type"
      fi
    fi
  done <<< "$entries"

  [[ -n "$key_types" ]] || key_types='NONE'
  printf 'MATCHING_ENTRY_COUNT=%s\n' "$matching_count"
  printf 'PINNED_MATCH_COUNT=%s\n' "$pinned_count"
  printf 'NON_PINNED_MATCH_COUNT=%s\n' "$non_pinned_count"
  printf 'KEY_TYPES=%s\n' "$key_types"
  printf 'ALIASED_MATCH_PRESENT=%s\n' "$alias_present"
  printf 'HASHED_MATCH_PRESENT=%s\n' "$hashed_present"
}

entries_before="$(matching_entries)"
classification_before="$(classify_entries "$entries_before")"
if [[ "$MODE" == 'CLASSIFY' ]]; then
  printf '%s\n' "$classification_before"
  exit 0
fi

matching_count="$(awk -F= '$1=="MATCHING_ENTRY_COUNT" {print $2}' <<< "$classification_before")"
pinned_count="$(awk -F= '$1=="PINNED_MATCH_COUNT" {print $2}' <<< "$classification_before")"
non_pinned_count="$(awk -F= '$1=="NON_PINNED_MATCH_COUNT" {print $2}' <<< "$classification_before")"
hashed_present="$(awk -F= '$1=="HASHED_MATCH_PRESENT" {print $2}' <<< "$classification_before")"
aliased_present="$(awk -F= '$1=="ALIASED_MATCH_PRESENT" {print $2}' <<< "$classification_before")"

if [[ "$hashed_present" == 'YES' ]]; then
  echo 'Matching hashed known_hosts entry cannot be selectively repaired without unsafe assumptions.' >&2
  exit 80
fi

# Every plain match returned by ssh-keygen must contain SERVER_HOST as an exact
# comma-delimited host-field token. Any other representation is fail-closed.
while IFS= read -r line; do
  [[ -n "$line" ]] || continue
  read -r host_field _ <<< "$line"
  found=0
  IFS=',' read -ra aliases <<< "$host_field"
  for alias in "${aliases[@]}"; do
    [[ "$alias" == "$SERVER_HOST" ]] && found=1
  done
  [[ "$found" -eq 1 ]] || {
    echo 'Matching plain known_hosts representation is not selectively repairable.' >&2
    exit 81
  }
done <<< "$entries_before"

# An already-canonical single plain entry is a true content no-op.
if [[ "$matching_count" -eq 1 && "$pinned_count" -eq 1 && "$non_pinned_count" -eq 0 && "$aliased_present" == 'NO' ]]; then
  SERVER_HOST="$SERVER_HOST" bash "$VERIFY_SCRIPT" VERIFY_ONLY >/dev/null
  if [[ -f "$KNOWN_HOSTS" ]]; then
    chmod 600 "$KNOWN_HOSTS"
  fi
  printf '%s\n' "$classification_before"
  printf 'REPAIR_RESULT=PASS\n'
  printf 'REPAIR_EFFECTIVE_CHANGE=NO\n'
  exit 0
fi

mkdir -p "$SSH_DIR"
chmod 700 "$SSH_DIR"
original_present=0
if [[ -f "$KNOWN_HOSTS" ]]; then
  original_present=1
  # Appending a canonical line to a non-newline-terminated unrelated final line
  # would alter that unrelated record, so refuse rather than normalize it.
  if [[ -s "$KNOWN_HOSTS" ]] && [[ "$(tail -c 1 "$KNOWN_HOSTS" | od -An -t x1 | tr -d ' \n')" != '0a' ]]; then
    echo 'known_hosts lacks a terminal newline; bounded repair refuses unrelated byte normalization.' >&2
    exit 82
  fi
else
  : > "$KNOWN_HOSTS"
fi
chmod 600 "$KNOWN_HOSTS"

backup="$(mktemp "$SSH_DIR/.known_hosts.agency-840.backup.XXXXXX")"
work="$(mktemp "$SSH_DIR/.known_hosts.agency-840.work.XXXXXX")"
chmod 600 "$backup" "$work"
cp -- "$KNOWN_HOSTS" "$backup"

rollback() {
  if [[ "$original_present" -eq 1 ]]; then
    cp -- "$backup" "$KNOWN_HOSTS"
    chmod 600 "$KNOWN_HOSTS"
  else
    rm -f -- "$KNOWN_HOSTS"
  fi
}

cleanup_local() {
  rm -f -- "$backup" "$work"
}
trap cleanup_local EXIT

SERVER_HOST="$SERVER_HOST" PINNED_KEY_TYPE="$PINNED_KEY_TYPE" PINNED_KEY_BLOB="$PINNED_KEY_BLOB" \
python3 - "$KNOWN_HOSTS" "$work" <<'PY'
import os
import re
import sys

source, target = sys.argv[1:3]
host = os.environ['SERVER_HOST']
key_type = os.environ['PINNED_KEY_TYPE']
key_blob = os.environ['PINNED_KEY_BLOB']

with open(source, 'rb') as fh:
    raw = fh.read()

out = bytearray()
pattern = re.compile(rb'^(\S+)(\s+)(\S+)(\s+)(\S+)(.*)$')
for line in raw.splitlines(keepends=True):
    body = line[:-1] if line.endswith(b'\n') else line
    newline = b'\n' if line.endswith(b'\n') else b''
    if not body or body.lstrip().startswith(b'#'):
        out.extend(line)
        continue
    match = pattern.match(body)
    if match is None:
        out.extend(line)
        continue
    host_field = match.group(1).decode('utf-8', 'strict')
    if host_field.startswith('|1|'):
        out.extend(line)
        continue
    aliases = host_field.split(',')
    if host not in aliases:
        out.extend(line)
        continue
    remaining = [alias for alias in aliases if alias != host]
    if remaining:
        rebuilt = ','.join(remaining).encode() + match.group(2) + match.group(3) + match.group(4) + match.group(5) + match.group(6) + newline
        out.extend(rebuilt)

if out and not out.endswith(b'\n'):
    raise SystemExit('refusing to append across a non-newline-terminated record')
out.extend(f'{host} {key_type} {key_blob}\n'.encode())
with open(target, 'wb') as fh:
    fh.write(out)
PY
chmod 600 "$work"

effective_change='YES'
if cmp -s -- "$KNOWN_HOSTS" "$work"; then
  effective_change='NO'
else
  mv -f -- "$work" "$KNOWN_HOSTS"
  chmod 600 "$KNOWN_HOSTS"
  : > "$work"
fi

verify_status=0
if [[ "${AGENCY_840_SYNTHETIC_TEST:-0}" == '1' && "${AGENCY_840_TEST_FORCE_POST_VERIFY_FAILURE:-0}" == '1' ]]; then
  verify_status=99
else
  SERVER_HOST="$SERVER_HOST" bash "$VERIFY_SCRIPT" VERIFY_ONLY >/dev/null || verify_status=$?
fi
if [[ "$verify_status" -ne 0 ]]; then
  rollback
  echo 'Post-repair verification failed; local known_hosts backup restored.' >&2
  exit 83
fi

entries_after="$(matching_entries)"
classification_after="$(classify_entries "$entries_after")"
post_count="$(awk -F= '$1=="MATCHING_ENTRY_COUNT" {print $2}' <<< "$classification_after")"
post_pinned="$(awk -F= '$1=="PINNED_MATCH_COUNT" {print $2}' <<< "$classification_after")"
post_non_pinned="$(awk -F= '$1=="NON_PINNED_MATCH_COUNT" {print $2}' <<< "$classification_after")"
[[ "$post_count" -eq 1 && "$post_pinned" -eq 1 && "$post_non_pinned" -eq 0 ]] || {
  rollback
  echo 'Post-repair classification is not canonical; local known_hosts backup restored.' >&2
  exit 84
}

printf '%s\n' "$classification_after"
printf 'REPAIR_RESULT=PASS\n'
printf 'REPAIR_EFFECTIVE_CHANGE=%s\n' "$effective_change"
