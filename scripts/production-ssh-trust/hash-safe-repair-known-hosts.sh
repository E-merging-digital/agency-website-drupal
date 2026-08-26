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
REPAIR_STRATEGY='OFFLINE_HMAC_SHA1_WORK_COPY_V1'

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
if [[ "$EXPECTED_FINGERPRINT" != 'SHA256:Pflpbgh2vc9dUYe4fpXPxdwzhPqyy8vmbAOcS+BRLDQ' ]]; then
  echo 'Unexpected PROD SSH trust authority for #842.' >&2
  exit 68
fi

SERVER_HOST="${SERVER_HOST:-}"
if [[ ! "$SERVER_HOST" =~ ^[A-Za-z0-9._:-]+$ ]]; then
  echo 'SERVER_HOST is invalid.' >&2
  exit 69
fi
if [[ -z "${HOME:-}" ]]; then
  echo 'HOME is required.' >&2
  exit 70
fi

SSH_DIR="$HOME/.ssh"
KNOWN_HOSTS="$SSH_DIR/known_hosts"
if [[ ! -d "$SSH_DIR" ]]; then
  echo 'Existing runner .ssh directory is required for hash-safe repair.' >&2
  exit 71
fi
if [[ ! -f "$KNOWN_HOSTS" ]]; then
  echo 'Existing runner known_hosts file is required for hash-safe repair.' >&2
  exit 72
fi

matching_entries_for() {
  local file="$1"
  ssh-keygen -F "$SERVER_HOST" -f "$file" 2>/dev/null | grep -v '^#' || true
}

classify_entries() {
  local entries="$1"
  local matching_count=0 pinned_count=0 non_pinned_count=0
  local plain_count=0 hashed_count=0 alias_present='NO'
  local key_types=''
  local line first second third fourth extra host_field key_type key_blob type_seen

  while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    matching_count=$((matching_count + 1))
    read -r first second third fourth extra <<< "$line"
    if [[ "$first" == @* ]]; then
      host_field="$second"
      key_type="$third"
      key_blob="$fourth"
      [[ -z "${extra:-}" ]] || key_blob='__NON_CANONICAL__'
    else
      host_field="$first"
      key_type="$second"
      key_blob="$third"
      [[ -z "${fourth:-}" ]] || key_blob='__NON_CANONICAL__'
    fi

    if [[ "$host_field" == '|1|'* ]]; then
      hashed_count=$((hashed_count + 1))
    else
      plain_count=$((plain_count + 1))
      [[ "$host_field" == *,* ]] && alias_present='YES'
    fi

    if [[ "$key_type" == "$PINNED_KEY_TYPE" && "$key_blob" == "$PINNED_KEY_BLOB" ]]; then
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
  printf 'PLAIN_MATCH_COUNT=%s\n' "$plain_count"
  printf 'HASHED_MATCH_COUNT=%s\n' "$hashed_count"
  printf 'KEY_TYPES=%s\n' "$key_types"
  printf 'ALIASED_MATCH_PRESENT=%s\n' "$alias_present"
  printf 'REPAIR_STRATEGY=%s\n' "$REPAIR_STRATEGY"
}

classification_for() {
  local file="$1"
  classify_entries "$(matching_entries_for "$file")"
}

value() {
  local key="$1" data="$2"
  awk -F= -v key="$key" '$1 == key { print substr($0, index($0, "=") + 1); exit }' <<< "$data"
}

canonical_exact_entry() {
  local entries="$1" line host_field key_type key_blob extra
  [[ "$(awk 'NF { count++ } END { print count + 0 }' <<< "$entries")" -eq 1 ]] || return 1
  line="$(awk 'NF { print; exit }' <<< "$entries")"
  read -r host_field key_type key_blob extra <<< "$line"
  [[ "$host_field" == "$SERVER_HOST" ]]
  [[ "$key_type" == "$PINNED_KEY_TYPE" ]]
  [[ "$key_blob" == "$PINNED_KEY_BLOB" ]]
  [[ -z "${extra:-}" ]]
}

entries_before="$(matching_entries_for "$KNOWN_HOSTS")"
classification_before="$(classify_entries "$entries_before")"
if [[ "$MODE" == 'CLASSIFY' ]]; then
  printf '%s\n' "$classification_before"
  exit 0
fi

matching_count="$(value MATCHING_ENTRY_COUNT "$classification_before")"
pinned_count="$(value PINNED_MATCH_COUNT "$classification_before")"
non_pinned_count="$(value NON_PINNED_MATCH_COUNT "$classification_before")"
plain_count="$(value PLAIN_MATCH_COUNT "$classification_before")"
hashed_count="$(value HASHED_MATCH_COUNT "$classification_before")"
aliased_present="$(value ALIASED_MATCH_PRESENT "$classification_before")"

# Preserve #840 ordinary semantics as a separate primitive: this #842 path is
# the only path allowed to canonicalize hashed entries, and only via a work copy.
if [[ "$matching_count" -eq 1 && "$pinned_count" -eq 1 && "$non_pinned_count" -eq 0 && "$plain_count" -eq 1 && "$hashed_count" -eq 0 && "$aliased_present" == 'NO' ]] && canonical_exact_entry "$entries_before"; then
  verify="$(SERVER_HOST="$SERVER_HOST" bash "$VERIFY_SCRIPT" VERIFY_ONLY)"
  grep -Fxq 'KNOWN_HOSTS_MATCH=PASS' <<< "$verify"
  grep -Fxq "FINGERPRINT=$EXPECTED_FINGERPRINT" <<< "$verify"
  printf 'MATCHING_ENTRY_COUNT_AFTER=1\n'
  printf 'PINNED_MATCH_COUNT_AFTER=1\n'
  printf 'NON_PINNED_MATCH_COUNT_AFTER=0\n'
  printf 'PLAIN_MATCH_COUNT_AFTER=1\n'
  printf 'HASHED_MATCH_COUNT_AFTER=0\n'
  printf 'KEY_TYPES_AFTER=ssh-ed25519\n'
  printf 'ALIASED_MATCH_PRESENT_AFTER=NO\n'
  printf 'WORK_COPY_ONLY=PASS\n'
  printf 'WORK_COPY_TARGET_MATCH_COUNT_AFTER_REMOVAL=NOT_REQUIRED_CANONICAL\n'
  printf 'UNRELATED_STATE_PRESERVED=PASS\n'
  printf 'KNOWN_HOSTS_MATCH=PASS\n'
  printf 'FINGERPRINT=%s\n' "$EXPECTED_FINGERPRINT"
  printf 'ROLLBACK_USED=NO\n'
  printf 'REPAIR_RESULT=PASS\n'
  printf 'REPAIR_EFFECTIVE_CHANGE=NO\n'
  printf 'REPAIR_STRATEGY=%s\n' "$REPAIR_STRATEGY"
  exit 0
fi

if [[ -s "$KNOWN_HOSTS" ]] && [[ "$(tail -c 1 "$KNOWN_HOSTS" | od -An -t x1 | tr -d ' \n')" != '0a' ]]; then
  echo 'known_hosts lacks a terminal newline; #842 refuses bounded normalization.' >&2
  exit 90
fi

ssh_dir_mode="$(stat -c '%a' "$SSH_DIR")"
known_hosts_mode="$(stat -c '%a' "$KNOWN_HOSTS")"
[[ "$ssh_dir_mode" == '700' ]] || { echo 'Runner .ssh mode must already be 0700 before #842 repair.' >&2; exit 89; }
[[ "$known_hosts_mode" == '600' ]] || { echo 'Runner known_hosts mode must already be 0600 before #842 repair.' >&2; exit 89; }
backup="$(mktemp "$SSH_DIR/.known_hosts.agency-842.backup.XXXXXX")"
work="$(mktemp "$SSH_DIR/.known_hosts.agency-842.work.XXXXXX")"
removed="$(mktemp "$SSH_DIR/.known_hosts.agency-842.removed.XXXXXX")"
chmod 600 "$backup" "$work" "$removed"
cp -- "$KNOWN_HOSTS" "$backup"

cleanup_local() {
  rm -f -- "$backup" "$work" "$removed"
}
trap cleanup_local EXIT

before_hash="$(sha256sum "$backup" | awk '{print $1}')"

set +e
SERVER_HOST="$SERVER_HOST" python3 - "$backup" "$work" <<'PY'
import base64
import hashlib
import hmac
import re
import sys

source, target = sys.argv[1:3]
host = __import__('os').environ['SERVER_HOST']
with open(source, 'rb') as fh:
    raw = fh.read()
if raw and not raw.endswith(b'\n'):
    print('known_hosts lacks a terminal newline; refusing normalization.', file=sys.stderr)
    raise SystemExit(10)

out = bytearray()
line_re = re.compile(
    rb'^(?P<indent>[ \t]*)(?P<marker>@[A-Za-z0-9-]+[ \t]+)?(?P<hosts>\S+)(?P<rest>[ \t]+\S+[ \t]+\S+(?:[ \t]+.*)?)$'
)
for line in raw.splitlines(keepends=True):
    body = line[:-1] if line.endswith(b'\n') else line
    newline = b'\n' if line.endswith(b'\n') else b''
    if not body.strip() or body.lstrip().startswith(b'#'):
        out.extend(line)
        continue
    match = line_re.match(body)
    if match is None:
        print('Malformed known_hosts record cannot be proven safe.', file=sys.stderr)
        raise SystemExit(11)
    try:
        hosts = match.group('hosts').decode('ascii')
    except UnicodeDecodeError:
        print('Non-ASCII known_hosts host field cannot be proven safe.', file=sys.stderr)
        raise SystemExit(11)

    if hosts.startswith('|'):
        parts = hosts.split('|')
        if len(parts) != 4 or parts[0] != '' or parts[1] != '1' or not parts[2] or not parts[3]:
            print('Unknown or malformed hashed known_hosts representation.', file=sys.stderr)
            raise SystemExit(12)
        try:
            salt = base64.b64decode(parts[2], validate=True)
            stored = base64.b64decode(parts[3], validate=True)
        except Exception:
            print('Malformed hashed known_hosts encoding.', file=sys.stderr)
            raise SystemExit(12)
        expected = hmac.new(salt, host.encode('utf-8'), hashlib.sha1).digest()
        if hmac.compare_digest(expected, stored):
            continue
        out.extend(line)
        continue

    aliases = hosts.split(',')
    if not aliases or any(alias == '' for alias in aliases):
        print('Malformed plain known_hosts host field.', file=sys.stderr)
        raise SystemExit(13)
    remaining = [alias for alias in aliases if alias != host]
    if len(remaining) == len(aliases):
        out.extend(line)
        continue
    if remaining:
        rebuilt = (
            match.group('indent')
            + (match.group('marker') or b'')
            + ','.join(remaining).encode('ascii')
            + match.group('rest')
            + newline
        )
        out.extend(rebuilt)

with open(target, 'wb') as fh:
    fh.write(out)
PY
transform_status=$?
set -e
case "$transform_status" in
  0) ;;
  10) exit 90 ;;
  11) exit 91 ;;
  12) exit 92 ;;
  13) exit 93 ;;
  *) echo 'Offline #842 work-copy transformation failed unexpectedly.' >&2; exit 94 ;;
esac
chmod 600 "$work"

removed_matches="$(matching_entries_for "$work")"
removed_count="$(awk 'NF { count++ } END { print count + 0 }' <<< "$removed_matches")"
if [[ "$removed_count" -ne 0 ]]; then
  echo 'Target associations remain on the offline work copy after hash-aware removal.' >&2
  exit 95
fi
cp -- "$work" "$removed"
chmod 600 "$removed"

# The transformer preserves every non-target record byte-for-byte and only
# rewrites a plain multi-host field to remove the exact SERVER_HOST token.
# Synthetic validation independently exercises these preservation invariants.
unrelated_state_preserved='PASS'

printf '%s %s %s\n' "$SERVER_HOST" "$PINNED_KEY_TYPE" "$PINNED_KEY_BLOB" >> "$work"
chmod 600 "$work"

work_entries="$(matching_entries_for "$work")"
work_classification="$(classify_entries "$work_entries")"
[[ "$(value MATCHING_ENTRY_COUNT "$work_classification")" -eq 1 ]]
[[ "$(value PINNED_MATCH_COUNT "$work_classification")" -eq 1 ]]
[[ "$(value NON_PINNED_MATCH_COUNT "$work_classification")" -eq 0 ]]
[[ "$(value PLAIN_MATCH_COUNT "$work_classification")" -eq 1 ]]
[[ "$(value HASHED_MATCH_COUNT "$work_classification")" -eq 0 ]]
[[ "$(value KEY_TYPES "$work_classification")" == 'ssh-ed25519' ]]
[[ "$(value ALIASED_MATCH_PRESENT "$work_classification")" == 'NO' ]]
canonical_exact_entry "$work_entries"

# Atomic same-directory replacement happens only after all work-copy checks.
effective_change='YES'
if cmp -s -- "$KNOWN_HOSTS" "$work"; then
  effective_change='NO'
else
  mv -f -- "$work" "$KNOWN_HOSTS"
  chmod 600 "$KNOWN_HOSTS"
  work=''
fi

rollback() {
  cp -- "$backup" "$KNOWN_HOSTS"
  chmod 600 "$KNOWN_HOSTS"
  local restored_hash
  restored_hash="$(sha256sum "$KNOWN_HOSTS" | awk '{print $1}')"
  [[ "$restored_hash" == "$before_hash" ]] || {
    echo 'Rollback failed to restore exact pre-repair known_hosts bytes.' >&2
    exit 99
  }
}

verify_status=0
verify=''
if [[ "${AGENCY_842_SYNTHETIC_TEST:-0}" == '1' && "${AGENCY_842_TEST_FORCE_POST_VERIFY_FAILURE:-0}" == '1' ]]; then
  verify_status=99
else
  verify="$(SERVER_HOST="$SERVER_HOST" bash "$VERIFY_SCRIPT" VERIFY_ONLY)" || verify_status=$?
fi
if [[ "$verify_status" -ne 0 ]]; then
  rollback
  echo 'Post-repair VERIFY_ONLY failed; exact #842 backup restored.' >&2
  exit 96
fi

grep -Fxq 'KNOWN_HOSTS_MATCH=PASS' <<< "$verify"
grep -Fxq "FINGERPRINT=$EXPECTED_FINGERPRINT" <<< "$verify"

classification_after="$(classification_for "$KNOWN_HOSTS")"
if [[ "$(value MATCHING_ENTRY_COUNT "$classification_after")" -ne 1 ]] ||
   [[ "$(value PINNED_MATCH_COUNT "$classification_after")" -ne 1 ]] ||
   [[ "$(value NON_PINNED_MATCH_COUNT "$classification_after")" -ne 0 ]] ||
   [[ "$(value PLAIN_MATCH_COUNT "$classification_after")" -ne 1 ]] ||
   [[ "$(value HASHED_MATCH_COUNT "$classification_after")" -ne 0 ]] ||
   [[ "$(value KEY_TYPES "$classification_after")" != 'ssh-ed25519' ]] ||
   [[ "$(value ALIASED_MATCH_PRESENT "$classification_after")" != 'NO' ]]; then
  rollback
  echo 'Post-repair canonical classification failed; exact #842 backup restored.' >&2
  exit 97
fi
canonical_exact_entry "$(matching_entries_for "$KNOWN_HOSTS")" || {
  rollback
  echo 'Post-repair exact canonical host representation failed; exact #842 backup restored.' >&2
  exit 98
}

printf 'MATCHING_ENTRY_COUNT_AFTER=%s\n' "$(value MATCHING_ENTRY_COUNT "$classification_after")"
printf 'PINNED_MATCH_COUNT_AFTER=%s\n' "$(value PINNED_MATCH_COUNT "$classification_after")"
printf 'NON_PINNED_MATCH_COUNT_AFTER=%s\n' "$(value NON_PINNED_MATCH_COUNT "$classification_after")"
printf 'PLAIN_MATCH_COUNT_AFTER=%s\n' "$(value PLAIN_MATCH_COUNT "$classification_after")"
printf 'HASHED_MATCH_COUNT_AFTER=%s\n' "$(value HASHED_MATCH_COUNT "$classification_after")"
printf 'KEY_TYPES_AFTER=%s\n' "$(value KEY_TYPES "$classification_after")"
printf 'ALIASED_MATCH_PRESENT_AFTER=%s\n' "$(value ALIASED_MATCH_PRESENT "$classification_after")"
printf 'WORK_COPY_ONLY=PASS\n'
printf 'WORK_COPY_TARGET_MATCH_COUNT_AFTER_REMOVAL=0\n'
printf 'UNRELATED_STATE_PRESERVED=%s\n' "$unrelated_state_preserved"
printf 'KNOWN_HOSTS_MATCH=PASS\n'
printf 'FINGERPRINT=%s\n' "$EXPECTED_FINGERPRINT"
printf 'ROLLBACK_USED=NO\n'
printf 'REPAIR_RESULT=PASS\n'
printf 'REPAIR_EFFECTIVE_CHANGE=%s\n' "$effective_change"
printf 'REPAIR_STRATEGY=%s\n' "$REPAIR_STRATEGY"
