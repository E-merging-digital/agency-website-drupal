#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

[[ "${RUNNER_ENVIRONMENT:-}" == 'github-hosted' ]] || {
  echo 'Refusing #915/#917 root ingress synthetic proof outside GitHub-hosted ephemeral runner.' >&2
  exit 70
}
[[ "$(id -u)" -eq 0 ]] || { echo 'Synthetic ingress proof must run as root.' >&2; exit 71; }

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE='/var/lib/agency-preprod-refresh'
BUNDLE='/usr/local/lib/agency-preprod-refresh'
AUTH='/usr/local/sbin/agency-preprod-refresh-authority-install'
ABORT='/usr/local/sbin/agency-preprod-refresh-authority-abort'
INGRESS='/usr/local/sbin/agency-preprod-refresh-ingress'
ACTIVE="$STATE/authority/active.json"
INCOMING="$STATE/incoming"
HISTORY="$STATE/transactions"

for path in "$STATE" "$BUNDLE" "$AUTH" "$ABORT" "$INGRESS"; do
  [[ ! -e "$path" && ! -L "$path" ]] || { echo "Synthetic target already exists: $path" >&2; exit 72; }
done
cleanup() {
  # Fixture-wide teardown is intentionally the only direct state-tree removal.
  rm -rf -- "$STATE" "$BUNDLE" "$AUTH" "$ABORT" "$INGRESS" \
    /run/lock/agency-preprod-refresh-authority.lock
}
trap cleanup EXIT HUP INT TERM

install -d -m 0755 -o root -g root "$BUNDLE"
install -m 0644 -o root -g root "$BASE/transaction_contract.py" "$BUNDLE/transaction_contract.py"
install -m 0750 -o root -g root "$BASE/agency-preprod-refresh-authority-install" "$AUTH"
install -m 0750 -o root -g root "$BASE/agency-preprod-refresh-authority-abort" "$ABORT"
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
request,size,sha,main=sys.argv[1:]
print(json.dumps({
  'schema_version':1,
  'successor_issue':918,
  'request_id':request,
  'main_sha':main,
  'profile_id':'agency-preprod-refresh-capability-v1',
  'allowed_actions':['IMPORT_SANITIZE_HARDEN_RETAIN','BACKUP_ACTIVATE_CONVERGE_VALIDATE','ROLLBACK_RECORDED'],
  'snapshot_bytes':int(size),
  'snapshot_sha256':sha,
},separators=(',',':')))
PY
}
make_header() {
  local request="$1" expected_bytes="$2" expected_sha="$3" extra="${4:-}"
  python3 - "$request" "$expected_bytes" "$expected_sha" "$main_sha" "$extra" <<'PY'
import json,sys
request,size,sha,main,extra=sys.argv[1:]
x={
  'successor_issue':918,
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
make_abort() {
  python3 - "$ACTIVE" <<'PY'
import json,sys
a=json.load(open(sys.argv[1],encoding='utf-8'))
print(json.dumps({k:a[k] for k in ('successor_issue','request_id','main_sha','profile_id','authority_id')},separators=(',',':')))
PY
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
prove_no_ingress_objects() {
  local id="$1"
  [[ ! -e "$INCOMING/$id.sql" && ! -L "$INCOMING/$id.sql" ]]
  [[ ! -e "$INCOMING/$id.json" && ! -L "$INCOMING/$id.json" ]]
  [[ ! -e "$INCOMING/.$id.partial" && ! -L "$INCOMING/.$id.partial" ]]
  [[ ! -e "$INCOMING/.$id.manifest.tmp" && ! -L "$INCOMING/.$id.manifest.tmp" ]]
}
abort_after_failure() {
  local envelope="$1" id="$2" abort_request
  abort_request="$(make_abort)"
  "$ABORT" <<<"$abort_request" >/dev/null
  [[ ! -e "$ACTIVE" && ! -L "$ACTIVE" ]]
  [[ -f "$HISTORY/$id.json" && ! -L "$HISTORY/$id.json" ]]
  [[ "$(jq -r '.state+"/"+.phase' "$HISTORY/$id.json")" == 'ABORTED/TERMINAL' ]]
  [[ "$(stat -c '%U:%G:%a:%h' "$HISTORY/$id.json")" == 'root:root:600:1' ]]
  if "$AUTH" <<<"$envelope" >/dev/null 2>&1; then
    echo 'Aborted ingress request unexpectedly reinstalled.' >&2; exit 80
  fi
}

negative_case() {
  local name="$1" request="$2" expected_bytes="$3" expected_sha="$4" data="$5" extra="${6:-}"
  local envelope id log
  envelope="$(make_envelope "$request" "$expected_bytes" "$expected_sha")"
  arm "$request" "$expected_bytes" "$expected_sha"
  id="$(jq -r .authority_id "$ACTIVE")"
  log="$(mktemp)"
  if run_ingress "$request" "$expected_bytes" "$expected_sha" "$data" "$extra" >"$log" 2>&1; then
    echo "$name unexpectedly succeeded." >&2; cat "$log" >&2; exit 81
  fi
  ! grep -Fq "$payload" "$log"
  prove_no_ingress_objects "$id"
  [[ "$(jq -r '.state+"/"+.phase' "$ACTIVE")" == 'ARMED/AWAITING_INGRESS' ]]
  abort_after_failure "$envelope" "$id"
  rm -f "$log"
  printf '%s=FAIL_CLOSED\n' "$name"
  printf '%s_INGRESS_FAILURE_TO_ABORT=PASS\n' "$name"
}

negative_case PARTIAL_INGRESS 'apply-918-partial-r1' "$((bytes + 5))" "$sha" "$payload"
negative_case BYTE_MISMATCH 'apply-918-extra-r1' "$bytes" "$sha" "${payload}X"
negative_case SHA_MISMATCH 'apply-918-sha-r1' "$bytes" '0000000000000000000000000000000000000000000000000000000000000000' "$payload"
negative_case CALLER_PATH 'apply-918-path-r1' "$bytes" "$sha" "$payload" path

# Oversize is refused before active authority creation.
if make_envelope 'apply-918-oversize-r1' '1099511627777' "$sha" | "$AUTH" >/dev/null 2>&1; then
  echo 'Oversize authority unexpectedly accepted.' >&2; exit 82
fi
[[ ! -e "$ACTIVE" && ! -L "$ACTIVE" ]]
printf '%s\n' 'OVERSIZE=FAIL_CLOSED'

collision_case() {
  local name="$1" kind="$2" request="apply-918-${name,,}-r1" envelope id obstruction before after abort_request
  envelope="$(make_envelope "$request" "$bytes" "$sha")"
  arm "$request" "$bytes" "$sha"; id="$(jq -r .authority_id "$ACTIVE")"
  case "$kind" in
    symlink) obstruction="$INCOMING/$id.sql"; ln -s /etc/passwd "$obstruction";;
    hardlink) printf x > "$INCOMING/dummy-$id"; chmod 600 "$INCOMING/dummy-$id"; obstruction="$INCOMING/$id.sql"; ln "$INCOMING/dummy-$id" "$obstruction";;
    nonregular) obstruction="$INCOMING/$id.sql"; mkdir "$obstruction";;
    *) exit 90;;
  esac
  if run_ingress "$request" "$bytes" "$sha" "$payload" >/dev/null 2>&1; then echo "$name unexpectedly succeeded." >&2; exit 83; fi
  abort_request="$(make_abort)"; before="$(sha256sum "$ACTIVE" | awk '{print $1}')"
  if "$ABORT" <<<"$abort_request" >/dev/null 2>&1; then echo "$name obstruction abort unexpectedly succeeded." >&2; exit 84; fi
  after="$(sha256sum "$ACTIVE" | awk '{print $1}')"; [[ "$before" == "$after" ]]
  case "$kind" in
    nonregular) rmdir "$obstruction";;
    hardlink) rm -f "$obstruction" "$INCOMING/dummy-$id";;
    *) rm -f "$obstruction";;
  esac
  prove_no_ingress_objects "$id"
  abort_after_failure "$envelope" "$id"
  printf '%s=FAIL_CLOSED\n' "$name"
  printf '%s_ABORT_ABSENCE_GUARD=PASS\n' "$name"
}
collision_case SYMLINK symlink
collision_case HARDLINK_TYPE_CONFUSION hardlink
collision_case NONREGULAR_TYPE_CONFUSION nonregular

# Valid exact binary ingress last: root:root/0600, one link, atomic final name, no raw log.
request='apply-918-ingress-valid-r1'
envelope="$(make_envelope "$request" "$bytes" "$sha")"
arm "$request" "$bytes" "$sha"
authority_id="$(jq -r .authority_id "$ACTIVE")"
if make_envelope 'apply-918-hijack-r1' "$bytes" "$sha" | "$AUTH" >/dev/null 2>&1; then
  echo 'Active authority hijack unexpectedly accepted.' >&2; exit 85
fi
printf '%s\n' 'CONCURRENT_HIJACK=FAIL_CLOSED'
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
! find "$INCOMING" -maxdepth 1 \( -name '*.partial' -o -name '*.tmp' \) | grep -q .
rm -f "$log"

printf '%s\n' \
  'FIXED_BINARY_INGRESS=PASS' \
  'INGRESS_ROOT_ROOT=PASS' \
  'INGRESS_MODE_0600=PASS' \
  'INGRESS_SINGLE_LINK=PASS' \
  'ATOMIC_FINALIZE=PASS' \
  'PARTIAL_CLEANUP=PASS' \
  'INGRESS_FAILURE_TO_ABORT=PASS' \
  'ABORTED_HISTORY_AFTER_INGRESS_FAILURE=PASS' \
  'AUTHORITY_REPLAY_AFTER_ABORT=FAIL_CLOSED' \
  'CALLER_PATH=IMPOSSIBLE' \
  'RAW_DATA_LOG_BOUNDARY=NONE' \
  'RAW_DATA_LOG_LEAKAGE=NONE' \
  'ROOT_915_917_INGRESS_SYNTHETIC=PASS'
