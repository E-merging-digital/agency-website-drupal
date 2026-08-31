#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

[[ "${RUNNER_ENVIRONMENT:-}" == 'github-hosted' ]] || {
  echo 'Refusing #915 root ingress synthetic proof outside GitHub-hosted ephemeral runner.' >&2
  exit 70
}
[[ "$(id -u)" -eq 0 ]] || { echo 'Synthetic ingress proof must run as root.' >&2; exit 71; }

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE='/var/lib/agency-preprod-refresh'
BUNDLE='/usr/local/lib/agency-preprod-refresh'
AUTH='/usr/local/sbin/agency-preprod-refresh-authority-install'
INGRESS='/usr/local/sbin/agency-preprod-refresh-ingress'
ACTIVE="$STATE/authority/active.json"
INCOMING="$STATE/incoming"
HISTORY="$STATE/transactions"

for path in "$STATE" "$BUNDLE" "$AUTH" "$INGRESS"; do
  [[ ! -e "$path" && ! -L "$path" ]] || { echo "Synthetic target already exists: $path" >&2; exit 72; }
done
cleanup() {
  rm -rf -- "$STATE" "$BUNDLE" "$AUTH" "$INGRESS" \
    /run/lock/agency-preprod-refresh-authority.lock
}
trap cleanup EXIT HUP INT TERM

install -d -m 0755 -o root -g root "$BUNDLE"
install -m 0644 -o root -g root "$BASE/transaction_contract.py" "$BUNDLE/transaction_contract.py"
install -m 0750 -o root -g root "$BASE/agency-preprod-refresh-authority-install" "$AUTH"
install -m 0755 -o root -g root "$BASE/agency-preprod-refresh-ingress" "$INGRESS"
install -d -m 0711 -o root -g root "$STATE"
for dir in authority transactions recovery incoming candidates backups; do
  install -d -m 0700 -o root -g root "$STATE/$dir"
done
install -m 0600 -o root -g root "$BASE/data-activation-authority.disabled.json" "$STATE/data-activation-authority.json"

payload='agency915-synthetic-raw-sentinel'
bytes="$(printf '%s' "$payload" | wc -c)"
sha="$(printf '%s' "$payload" | sha256sum | awk '{print $1}')"
main_sha='8888888888888888888888888888888888888888'

make_envelope() {
  local request="$1" expected_bytes="$2" expected_sha="$3"
  python3 - "$request" "$expected_bytes" "$expected_sha" "$main_sha" <<'PY'
import json,sys
request, size, sha, main = sys.argv[1:]
print(json.dumps({
  'schema_version':1,
  'successor_issue':914,
  'request_id':request,
  'main_sha':main,
  'profile_id':'agency-preprod-refresh-capability-v1',
  'allowed_actions':['IMPORT_SANITIZE_HARDEN_RETAIN','BACKUP_ACTIVATE_CONVERGE_VALIDATE','ROLLBACK_RECORDED'],
  'snapshot_bytes':int(size),
  'snapshot_sha256':sha,
}, separators=(',',':')))
PY
}
make_header() {
  local request="$1" expected_bytes="$2" expected_sha="$3" extra="${4:-}"
  python3 - "$request" "$expected_bytes" "$expected_sha" "$main_sha" "$extra" <<'PY'
import json,sys
request, size, sha, main, extra = sys.argv[1:]
x={
  'successor_issue':914,
  'request_id':request,
  'main_sha':main,
  'profile_id':'agency-preprod-refresh-capability-v1',
  'expected_bytes':int(size),
  'expected_sha256':sha,
}
if extra == 'path': x['path']='/tmp/caller-selected'
print(json.dumps(x,separators=(',',':')))
PY
}
reset_transaction() {
  rm -f -- "$ACTIVE"
  find "$INCOMING" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
}
arm() {
  local request="$1" expected_bytes="$2" expected_sha="$3"
  make_envelope "$request" "$expected_bytes" "$expected_sha" | "$AUTH" >/dev/null
}
run_ingress() {
  local request="$1" expected_bytes="$2" expected_sha="$3" data="$4" extra="${5:-}"
  {
    make_header "$request" "$expected_bytes" "$expected_sha" "$extra"
    printf '%s' "$data"
  } | "$INGRESS"
}

# Valid exact binary ingress: root:root/0600, one link, atomic final name, no raw log.
request='apply-914-ingress-valid-r1'
arm "$request" "$bytes" "$sha"
authority_id="$(jq -r .authority_id "$ACTIVE")"
log="$(mktemp)"
run_ingress "$request" "$bytes" "$sha" "$payload" >"$log" 2>&1
! grep -Fq "$payload" "$log"
final="$INCOMING/$authority_id.sql"
manifest="$INCOMING/$authority_id.json"
[[ -f "$final" && ! -L "$final" && -f "$manifest" && ! -L "$manifest" ]]
[[ "$(stat -c '%U:%G:%a:%h' "$final")" == 'root:root:600:1' ]]
[[ "$(sha256sum "$final" | awk '{print $1}')" == "$sha" ]]
[[ "$(stat -c '%s' "$final")" == "$bytes" ]]
[[ "$(jq -r .phase "$ACTIVE")" == 'SNAPSHOT_READY' ]]
! find "$INCOMING" -maxdepth 1 -name '*.partial' -o -name '*.tmp' | grep -q .
rm -f "$log"
printf '%s\n' \
  'FIXED_BINARY_INGRESS=PASS' \
  'INGRESS_ROOT_ROOT=PASS' \
  'INGRESS_MODE_0600=PASS' \
  'INGRESS_SINGLE_LINK=PASS' \
  'ATOMIC_FINALIZE=PASS' \
  'RAW_DATA_LOG_LEAKAGE=NONE'

# Active transaction hijack/collision.
if make_envelope 'apply-914-hijack-r1' "$bytes" "$sha" | "$AUTH" >/dev/null 2>&1; then
  echo 'Active authority hijack unexpectedly accepted.' >&2; exit 80
fi
printf '%s\n' 'CONCURRENT_HIJACK=FAIL_CLOSED'

# Simulate terminal history and prove exact authority identity cannot replay.
cp "$ACTIVE" "$HISTORY/$authority_id.json"
chmod 600 "$HISTORY/$authority_id.json"
rm -f "$ACTIVE"
if make_envelope "$request" "$bytes" "$sha" | "$AUTH" >/dev/null 2>&1; then
  echo 'Authority replay unexpectedly accepted.' >&2; exit 81
fi
printf '%s\n' 'AUTHORITY_REPLAY=FAIL_CLOSED'
rm -f "$HISTORY/$authority_id.json"
reset_transaction

negative_case() {
  local name="$1" request="$2" expected_bytes="$3" expected_sha="$4" data="$5" extra="${6:-}"
  reset_transaction
  arm "$request" "$expected_bytes" "$expected_sha"
  local id="$(jq -r .authority_id "$ACTIVE")"
  local log="$(mktemp)"
  if run_ingress "$request" "$expected_bytes" "$expected_sha" "$data" "$extra" >"$log" 2>&1; then
    echo "$name unexpectedly succeeded." >&2; cat "$log" >&2; exit 82
  fi
  ! grep -Fq "$payload" "$log"
  [[ ! -e "$INCOMING/$id.sql" && ! -L "$INCOMING/$id.sql" ]]
  [[ ! -e "$INCOMING/$id.json" && ! -L "$INCOMING/$id.json" ]]
  ! find "$INCOMING" -mindepth 1 -maxdepth 1 | grep -q .
  rm -f "$log"
  printf '%s=FAIL_CLOSED\n' "$name"
}

negative_case PARTIAL_INGRESS 'apply-914-partial-r1' "$((bytes + 5))" "$sha" "$payload"
negative_case BYTE_MISMATCH 'apply-914-extra-r1' "$bytes" "$sha" "${payload}X"
negative_case SHA_MISMATCH 'apply-914-sha-r1' "$bytes" '0000000000000000000000000000000000000000000000000000000000000000' "$payload"
negative_case CALLER_PATH 'apply-914-path-r1' "$bytes" "$sha" "$payload" path

# Oversize is refused while installing the exact transaction authority.
reset_transaction
if make_envelope 'apply-914-oversize-r1' '1099511627777' "$sha" | "$AUTH" >/dev/null 2>&1; then
  echo 'Oversize authority unexpectedly accepted.' >&2; exit 83
fi
printf '%s\n' 'OVERSIZE=FAIL_CLOSED'

# Symlink collision.
reset_transaction
request='apply-914-symlink-r1'; arm "$request" "$bytes" "$sha"; authority_id="$(jq -r .authority_id "$ACTIVE")"
ln -s /etc/passwd "$INCOMING/$authority_id.sql"
if run_ingress "$request" "$bytes" "$sha" "$payload" >/dev/null 2>&1; then echo 'Symlink accepted.' >&2; exit 84; fi
rm -f "$INCOMING/$authority_id.sql"
printf '%s\n' 'SYMLINK=FAIL_CLOSED'

# Hardlink collision and link-count/type confusion.
reset_transaction
request='apply-914-hardlink-r1'; arm "$request" "$bytes" "$sha"; authority_id="$(jq -r .authority_id "$ACTIVE")"
printf x > "$INCOMING/dummy"; chmod 600 "$INCOMING/dummy"; ln "$INCOMING/dummy" "$INCOMING/$authority_id.sql"
if run_ingress "$request" "$bytes" "$sha" "$payload" >/dev/null 2>&1; then echo 'Hardlink accepted.' >&2; exit 85; fi
rm -f "$INCOMING/$authority_id.sql" "$INCOMING/dummy"
printf '%s\n' 'HARDLINK_TYPE_CONFUSION=FAIL_CLOSED'

reset_transaction
request='apply-914-type-r1'; arm "$request" "$bytes" "$sha"; authority_id="$(jq -r .authority_id "$ACTIVE")"
mkdir "$INCOMING/$authority_id.sql"
if run_ingress "$request" "$bytes" "$sha" "$payload" >/dev/null 2>&1; then echo 'Type confusion accepted.' >&2; exit 86; fi
rmdir "$INCOMING/$authority_id.sql"
printf '%s\n' 'NONREGULAR_TYPE_CONFUSION=FAIL_CLOSED'

printf '%s\n' \
  'PARTIAL_CLEANUP=PASS' \
  'CALLER_PATH=IMPOSSIBLE' \
  'RAW_DATA_LOG_BOUNDARY=NONE' \
  'ROOT_915_INGRESS_SYNTHETIC=PASS'
