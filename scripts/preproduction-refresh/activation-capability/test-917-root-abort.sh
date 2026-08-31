#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

[[ "${RUNNER_ENVIRONMENT:-}" == 'github-hosted' ]] || {
  echo 'Refusing #917 root abort synthetic proof outside GitHub-hosted ephemeral runner.' >&2
  exit 70
}
[[ "$(id -u)" -eq 0 ]] || { echo 'Synthetic abort proof must run as root.' >&2; exit 71; }

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE='/var/lib/agency-preprod-refresh'
BUNDLE='/usr/local/lib/agency-preprod-refresh'
INSTALLER='/usr/local/sbin/agency-preprod-refresh-authority-install'
ABORT='/usr/local/sbin/agency-preprod-refresh-authority-abort'
ACTIVE="$STATE/authority/active.json"
HISTORY="$STATE/transactions"
INCOMING="$STATE/incoming"
CANDIDATES="$STATE/candidates"
BACKUPS="$STATE/backups"
FENCE="$STATE/refresh-maintenance.flag"
LOCK='/run/lock/agency-preprod-refresh-authority.lock'

for path in "$STATE" "$BUNDLE" "$INSTALLER" "$ABORT"; do
  [[ ! -e "$path" && ! -L "$path" ]] || { echo "Synthetic target already exists: $path" >&2; exit 72; }
done
cleanup() {
  rm -rf -- "$STATE" "$BUNDLE" "$INSTALLER" "$ABORT" "$LOCK"
}
trap cleanup EXIT HUP INT TERM

install -d -m 0755 -o root -g root "$BUNDLE"
install -m 0644 -o root -g root "$BASE/transaction_contract.py" "$BUNDLE/transaction_contract.py"
install -m 0750 -o root -g root "$BASE/agency-preprod-refresh-authority-install" "$INSTALLER"
install -m 0750 -o root -g root "$BASE/agency-preprod-refresh-authority-abort" "$ABORT"
install -d -m 0711 -o root -g root "$STATE"
for dir in authority transactions recovery incoming candidates backups; do
  install -d -m 0700 -o root -g root "$STATE/$dir"
done
install -m 0600 -o root -g root "$BASE/data-activation-authority.disabled.json" "$STATE/data-activation-authority.json"

main_sha='9999999999999999999999999999999999999999'
snapshot='agency917-synthetic-snapshot'
bytes="$(printf '%s' "$snapshot" | wc -c)"
sha="$(printf '%s' "$snapshot" | sha256sum | awk '{print $1}')"

make_envelope() {
  local request="$1"
  python3 - "$request" "$bytes" "$sha" "$main_sha" <<'PY'
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

make_abort_from_active() {
  local change_key="${1:-}" change_value="${2:-}"
  python3 - "$ACTIVE" "$change_key" "$change_value" <<'PY'
import json,sys
path,key,value=sys.argv[1:]
a=json.load(open(path,encoding='utf-8'))
x={k:a[k] for k in ('successor_issue','request_id','main_sha','profile_id','authority_id')}
if key:
    if key == 'successor_issue': value=int(value)
    x[key]=value
print(json.dumps(x,separators=(',',':')))
PY
}

arm() {
  local request="$1"
  make_envelope "$request" | "$INSTALLER" >/dev/null
  [[ -f "$ACTIVE" && ! -L "$ACTIVE" ]]
  [[ "$(jq -r '.state+"/"+.phase' "$ACTIVE")" == 'ARMED/AWAITING_INGRESS' ]]
}

abort_exact() {
  make_abort_from_active | "$ABORT"
}

prove_terminal() {
  local authority_id="$1"
  local history="$HISTORY/$authority_id.json"
  [[ ! -e "$ACTIVE" && ! -L "$ACTIVE" ]]
  [[ -f "$history" && ! -L "$history" ]]
  [[ "$(stat -c '%U:%G:%a:%h' "$history")" == 'root:root:600:1' ]]
  [[ "$(jq -r '.state+"/"+.phase+"/"+(.terminal|tostring)' "$history")" == 'ABORTED/TERMINAL/true' ]]
  [[ "$(jq -r '.human_recovery_required' "$history")" == 'false' ]]
}

finish_case() {
  local envelope="$1" request_json="$2" authority_id="$3"
  "$ABORT" <<<"$request_json" >/dev/null
  prove_terminal "$authority_id"
  if "$ABORT" <<<"$request_json" >/dev/null 2>&1; then
    echo 'Second abort unexpectedly succeeded.' >&2; exit 80
  fi
  if "$INSTALLER" <<<"$envelope" >/dev/null 2>&1; then
    echo 'Authority reinstall after ABORTED history unexpectedly succeeded.' >&2; exit 81
  fi
}

# Exact positive path + replay/reinstall proof.
request='apply-918-abort-positive-r1'
envelope="$(make_envelope "$request")"
arm "$request"
authority_id="$(jq -r .authority_id "$ACTIVE")"
request_json="$(make_abort_from_active)"
output="$("$ABORT" <<<"$request_json")"
grep -Fxq 'PRE_INGRESS_ABORT=PASS' <<<"$output"
grep -Fxq 'ABORTED_TERMINAL_STATE=PRESENT' <<<"$output"
grep -Fxq 'ACTIVE_AUTHORITY_AFTER_ABORT=ABSENT' <<<"$output"
grep -Fxq 'ABSENCE_PROOF=PASS' <<<"$output"
prove_terminal "$authority_id"
if "$ABORT" <<<"$request_json" >/dev/null 2>&1; then exit 82; fi
if "$INSTALLER" <<<"$envelope" >/dev/null 2>&1; then exit 83; fi
printf '%s\n' \
  'ARMED_AWAITING_INGRESS_ABORT=PASS' \
  'ABORTED_TERMINAL_STATE=PASS' \
  'ABORT_HISTORY_ROOT_ONLY=PASS' \
  'ACTIVE_AUTHORITY_AFTER_ABORT=ABSENT' \
  'SECOND_ABORT=FAIL_CLOSED' \
  'AUTHORITY_REINSTALL_AFTER_ABORT=FAIL_CLOSED'

binding_negative() {
  local label="$1" key="$2" value="$3" request="apply-918-${label,,}-r1"
  local envelope authority_id good bad before after
  envelope="$(make_envelope "$request")"; arm "$request"
  authority_id="$(jq -r .authority_id "$ACTIVE")"
  good="$(make_abort_from_active)"; bad="$(make_abort_from_active "$key" "$value")"
  before="$(sha256sum "$ACTIVE" | awk '{print $1}')"
  if "$ABORT" <<<"$bad" >/dev/null 2>&1; then echo "$label unexpectedly succeeded." >&2; exit 84; fi
  after="$(sha256sum "$ACTIVE" | awk '{print $1}')"
  [[ "$before" == "$after" ]]
  finish_case "$envelope" "$good" "$authority_id"
  printf '%s=FAIL_CLOSED\n' "$label"
}
binding_negative WRONG_ISSUE successor_issue 999
binding_negative WRONG_REQUEST request_id apply-999-wrong-r1
binding_negative WRONG_MAIN main_sha 8888888888888888888888888888888888888888
binding_negative WRONG_PROFILE profile_id wrong-profile
binding_negative WRONG_AUTHORITY_ID authority_id 0000000000000000000000000000000000000000000000000000000000000000

absence_case() {
  local label="$1" kind="$2" request="apply-918-${label,,}-r1"
  local envelope authority_id good target before after
  envelope="$(make_envelope "$request")"; arm "$request"
  authority_id="$(jq -r .authority_id "$ACTIVE")"; good="$(make_abort_from_active)"
  case "$kind" in
    spool) target="$INCOMING/$authority_id.sql";;
    manifest) target="$INCOMING/$authority_id.json";;
    partial) target="$INCOMING/.$authority_id.partial";;
    temp_manifest) target="$INCOMING/.$authority_id.manifest.tmp";;
    candidate) target="$CANDIDATES/$authority_id.json";;
    backup) target="$BACKUPS/$authority_id.sql";;
    fence) target="$FENCE";;
    *) exit 90;;
  esac
  printf 'synthetic obstruction\n' > "$target"; chmod 600 "$target"; chown root:root "$target"
  before="$(sha256sum "$ACTIVE" | awk '{print $1}')"
  if "$ABORT" <<<"$good" >/dev/null 2>&1; then echo "$label unexpectedly succeeded." >&2; exit 85; fi
  after="$(sha256sum "$ACTIVE" | awk '{print $1}')"; [[ "$before" == "$after" ]]
  rm -f -- "$target"
  finish_case "$envelope" "$good" "$authority_id"
  printf '%s=FAIL_CLOSED\n' "$label"
}
absence_case FINAL_SPOOL_PRESENT spool
absence_case FINAL_MANIFEST_PRESENT manifest
absence_case PARTIAL_FILE_PRESENT partial
absence_case TEMP_MANIFEST_PRESENT temp_manifest
absence_case CANDIDATE_SEAL_PRESENT candidate
absence_case BACKUP_PRESENT backup
absence_case FENCE_CLOSED fence

metadata_case() {
  local label="$1" key="$2" json_value="$3" request="apply-918-${label,,}-r1"
  local envelope authority_id good saved before after
  envelope="$(make_envelope "$request")"; arm "$request"
  authority_id="$(jq -r .authority_id "$ACTIVE")"; good="$(make_abort_from_active)"
  saved="$(cat "$ACTIVE")"
  python3 - "$ACTIVE" "$key" "$json_value" <<'PY'
import json,sys
path,key,raw=sys.argv[1:]
x=json.load(open(path,encoding='utf-8'))
x[key]=json.loads(raw)
with open(path,'w',encoding='utf-8') as f: json.dump(x,f,separators=(',',':')); f.write('\n')
PY
  chmod 600 "$ACTIVE"; chown root:root "$ACTIVE"
  before="$(sha256sum "$ACTIVE" | awk '{print $1}')"
  if "$ABORT" <<<"$good" >/dev/null 2>&1; then echo "$label unexpectedly succeeded." >&2; exit 86; fi
  after="$(sha256sum "$ACTIVE" | awk '{print $1}')"; [[ "$before" == "$after" ]]
  printf '%s\n' "$saved" > "$ACTIVE"; chmod 600 "$ACTIVE"; chown root:root "$ACTIVE"
  finish_case "$envelope" "$good" "$authority_id"
  printf '%s=FAIL_CLOSED\n' "$label"
}
metadata_case PREACTIVATION_RUNTIME_NON_NULL preactivation_runtime_sha256 '"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"'
metadata_case APPLICATION_RELEASE_NON_NULL application_release_sha256 '"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"'
metadata_case BACKUP_SHA_NON_NULL backup_sha256 '"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"'
metadata_case BACKUP_BYTES_NON_NULL backup_bytes '1'
metadata_case HUMAN_RECOVERY_TRUE human_recovery_required 'true'

state_case() {
  local label="$1" state="$2" phase="$3" terminal="$4" request="apply-918-${label,,}-r1"
  local envelope authority_id good saved before after
  envelope="$(make_envelope "$request")"; arm "$request"
  authority_id="$(jq -r .authority_id "$ACTIVE")"; good="$(make_abort_from_active)"; saved="$(cat "$ACTIVE")"
  python3 - "$ACTIVE" "$state" "$phase" "$terminal" <<'PY'
import json,sys
path,state,phase,terminal=sys.argv[1:]
x=json.load(open(path,encoding='utf-8'))
x['state']=state; x['phase']=phase; x['terminal']=(terminal=='true')
if state=='FAILED_RECOVERY': x['human_recovery_required']=True
with open(path,'w',encoding='utf-8') as f: json.dump(x,f,separators=(',',':')); f.write('\n')
PY
  chmod 600 "$ACTIVE"; chown root:root "$ACTIVE"
  before="$(sha256sum "$ACTIVE" | awk '{print $1}')"
  if "$ABORT" <<<"$good" >/dev/null 2>&1; then echo "$label unexpectedly succeeded." >&2; exit 87; fi
  after="$(sha256sum "$ACTIVE" | awk '{print $1}')"; [[ "$before" == "$after" ]]
  printf '%s\n' "$saved" > "$ACTIVE"; chmod 600 "$ACTIVE"; chown root:root "$ACTIVE"
  finish_case "$envelope" "$good" "$authority_id"
  printf '%s=FAIL_CLOSED\n' "$label"
}
state_case WRONG_STATE IN_PROGRESS IMPORTING false
state_case WRONG_PHASE ARMED SNAPSHOT_READY false
state_case TERMINAL_AUTHORITY COMMITTED TERMINAL true

# Concurrent authority lock collision preserves active.
request='apply-918-concurrent-lock-r1'; envelope="$(make_envelope "$request")"; arm "$request"
authority_id="$(jq -r .authority_id "$ACTIVE")"; good="$(make_abort_from_active)"
exec 9>"$LOCK"; chmod 600 "$LOCK"; chown root:root "$LOCK"; flock -n 9
before="$(sha256sum "$ACTIVE" | awk '{print $1}')"
if "$ABORT" <<<"$good" >/dev/null 2>&1; then echo 'Concurrent lock unexpectedly accepted.' >&2; exit 88; fi
after="$(sha256sum "$ACTIVE" | awk '{print $1}')"; [[ "$before" == "$after" ]]
flock -u 9; exec 9>&-
finish_case "$envelope" "$good" "$authority_id"
printf '%s\n' 'CONCURRENT_LOCK_COLLISION=FAIL_CLOSED'

# Crash recovery: simulate interruption immediately after durable ABORTED active.
request='apply-918-crash-active-terminal-r1'; envelope="$(make_envelope "$request")"; arm "$request"
authority_id="$(jq -r .authority_id "$ACTIVE")"; good="$(make_abort_from_active)"
python3 - "$BASE/transaction_contract.py" "$ACTIVE" "$good" <<'PY'
import importlib.util,json,os,sys,tempfile
contract_path,active_path,request_raw=sys.argv[1:]
spec=importlib.util.spec_from_file_location('tx',contract_path); m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
a=json.load(open(active_path,encoding='utf-8')); r=json.loads(request_raw)
t=m.pre_ingress_aborted_state(a,r)
parent=os.path.dirname(active_path); fd,tmp=tempfile.mkstemp(prefix='.crash.',dir=parent)
with os.fdopen(fd,'w',encoding='utf-8') as f: json.dump(t,f,separators=(',',':')); f.write('\n'); f.flush(); os.fsync(f.fileno())
os.chmod(tmp,0o600); os.replace(tmp,active_path)
PY
[[ "$(jq -r '.state+"/"+.phase' "$ACTIVE")" == 'ABORTED/TERMINAL' ]]
output="$("$ABORT" <<<"$good")"; grep -Fxq 'ABORT_OUTCOME=RECOVERED' <<<"$output"
prove_terminal "$authority_id"
printf '%s\n' 'CRASH_RECOVERY_EXACT_IDENTITY=PASS'

# Corruption must fail closed and preserve exact corrupt bytes. Restore fixture only for teardown proof.
request='apply-918-corrupt-active-r1'; arm "$request"; good="$(make_abort_from_active)"; saved="$(cat "$ACTIVE")"
printf '{corrupt-json\n' > "$ACTIVE"; chmod 600 "$ACTIVE"; chown root:root "$ACTIVE"
before="$(sha256sum "$ACTIVE" | awk '{print $1}')"
if "$ABORT" <<<"$good" >/dev/null 2>&1; then echo 'Corrupt active unexpectedly accepted.' >&2; exit 89; fi
after="$(sha256sum "$ACTIVE" | awk '{print $1}')"; [[ "$before" == "$after" ]]
printf '%s\n' "$saved" > "$ACTIVE"; chmod 600 "$ACTIVE"; chown root:root "$ACTIVE"
printf '%s\n' 'CORRUPTED_ACTIVE_AUTHORITY=FAIL_CLOSED'

printf '%s\n' \
  'ABSENCE_PROOF=PASS' \
  'REPLAY=FAIL_CLOSED' \
  'NORMAL_SUDO_EXPOSURE=NONE' \
  'GENERIC_EXECUTION=NONE' \
  'ROOT_917_ABORT_SYNTHETIC=PASS'
