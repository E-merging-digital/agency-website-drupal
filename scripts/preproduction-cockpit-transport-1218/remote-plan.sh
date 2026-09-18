#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MAIN_SHA="${1:-}"
PLAN_ID="${2:-}"
PROJECT_ROOT='/var/www/agency-preprod'
CURRENT_LINK="$PROJECT_ROOT/current"
SETTINGS_FILE="$PROJECT_ROOT/shared/settings/settings.php"
NGINX_SITE='/etc/nginx/sites-available/agency-preprod'
TOKEN_FILE='/etc/agency-preprod/cockpit-state-token'
LEGACY_FAILED_RUN_DIR='/root/agency-1218-35221860275-1'
HOSTNAME='preprod.emergingdigital.be'
PATH_ONLY='/api/agency-operations/v1/environment-data-state'
ENDPOINT="https://$HOSTNAME$PATH_ONLY"

fail() {
  printf '[cockpit-transport-plan] ERROR: %s\n' "$1" >&2
  exit 1
}

[[ "$MAIN_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'MAIN_SHA is invalid.'
[[ "$PLAN_ID" =~ ^plan-1218-[A-Za-z0-9._-]+$ ]] || fail 'PLAN_ID is invalid.'
[[ -L "$CURRENT_LINK" ]] || fail 'PREPROD current release symlink is missing.'
[[ -f "$SETTINGS_FILE" && ! -L "$SETTINGS_FILE" ]] || fail 'Shared settings.php is missing or unsafe.'
[[ -f "$NGINX_SITE" && ! -L "$NGINX_SITE" ]] || fail 'PREPROD Nginx site is missing or unsafe.'
for command in curl jq python3 sha256sum stat; do
  command -v "$command" >/dev/null 2>&1 || fail "$command is required."
done

current_target="$(readlink -f "$CURRENT_LINK")"
[[ "$current_target" =~ ^/var/www/agency-preprod/releases/[A-Za-z0-9._-]+$ ]] || fail 'Current release target is unexpected.'
current_release="$(basename "$current_target")"
settings_sha256="$(sha256sum "$SETTINGS_FILE" | awk '{print $1}')"
nginx_sha256="$(sha256sum "$NGINX_SITE" | awk '{print $1}')"

settings_reader='NO'
if grep -Fq '/etc/agency-preprod/cockpit-state-token' "$SETTINGS_FILE" \
  && grep -Fq "agency_operations_cockpit_state_token" "$SETTINGS_FILE"; then
  settings_reader='YES'
fi

mapfile -t nginx_contract < <(python3 - "$NGINX_SITE" <<'PY'
import re
import sys
from pathlib import Path

text = Path(sys.argv[1]).read_text(encoding='utf-8')
pattern = re.compile(
    r'location\s*=\s*/api/agency-operations/v1/environment-data-state\s*\{(?P<body>.*?)^\s*\}',
    re.MULTILINE | re.DOTALL,
)
matches = list(pattern.finditer(text))
print(len(matches))
if len(matches) != 1:
    for _ in range(4):
        print('NO')
    raise SystemExit(0)
body = matches[0].group('body')
checks = [
    bool(re.search(r'(?m)^\s*auth_basic\s+off\s*;', body)),
    bool(re.search(r'(?m)^\s*fastcgi_param\s+HTTP_AUTHORIZATION\s+\$http_authorization\s*;', body)),
    bool(re.search(r'(?m)^\s*fastcgi_param\s+SCRIPT_FILENAME\s+\$realpath_root/index\.php\s*;', body)),
    bool(re.search(r'(?m)^\s*fastcgi_pass\s+unix:', body)),
]
for value in checks:
    print('YES' if value else 'NO')
PY
)
[[ "${#nginx_contract[@]}" -eq 5 ]] || fail 'Unable to inspect Nginx machine route.'
nginx_location_count="${nginx_contract[0]}"
nginx_auth_basic_off="${nginx_contract[1]}"
nginx_authorization_forwarded="${nginx_contract[2]}"
nginx_script_filename="${nginx_contract[3]}"
nginx_fastcgi_pass="${nginx_contract[4]}"

TOKEN_STATE='ABSENT'
TOKEN_OWNER='ABSENT'
TOKEN_GROUP='ABSENT'
TOKEN_MODE='ABSENT'
TOKEN_SIZE=0
TOKEN_MTIME=0
TOKEN_CTIME=0
if [[ -L "$TOKEN_FILE" ]]; then
  TOKEN_STATE='SYMLINK'
elif [[ -e "$TOKEN_FILE" ]]; then
  if [[ -f "$TOKEN_FILE" ]]; then
    TOKEN_STATE='REGULAR'
  else
    TOKEN_STATE='OTHER'
  fi
  TOKEN_OWNER="$(stat -c '%U' "$TOKEN_FILE")"
  TOKEN_GROUP="$(stat -c '%G' "$TOKEN_FILE")"
  TOKEN_MODE="$(stat -c '%a' "$TOKEN_FILE")"
  TOKEN_SIZE="$(stat -c '%s' "$TOKEN_FILE")"
  TOKEN_MTIME="$(stat -c '%Y' "$TOKEN_FILE")"
  TOKEN_CTIME="$(stat -c '%Z' "$TOKEN_FILE")"
fi

# Observe only metadata for the exact failed APPLY staging path. The PLAN
# identity deliberately receives no new root authority. If /root cannot be
# traversed, report UNKNOWN/DENIED rather than guessing that the path is absent.
LEGACY_STAGE_PRESENCE='UNKNOWN'
LEGACY_STAGE_ACCESS='DENIED'
LEGACY_STAGE_TYPE='UNKNOWN'
LEGACY_STAGE_OWNER='UNKNOWN'
LEGACY_STAGE_GROUP='UNKNOWN'
LEGACY_STAGE_MODE='UNKNOWN'
legacy_stat=''
if legacy_stat="$(stat -c '%F|%U|%G|%a' -- "$LEGACY_FAILED_RUN_DIR" 2>/dev/null)"; then
  LEGACY_STAGE_PRESENCE='PRESENT'
  LEGACY_STAGE_ACCESS='OK'
  IFS='|' read -r LEGACY_STAGE_TYPE LEGACY_STAGE_OWNER LEGACY_STAGE_GROUP LEGACY_STAGE_MODE <<<"$legacy_stat"
elif [[ -x "$(dirname "$LEGACY_FAILED_RUN_DIR")" ]]; then
  LEGACY_STAGE_PRESENCE='ABSENT'
  LEGACY_STAGE_ACCESS='OK'
  LEGACY_STAGE_TYPE='ABSENT'
  LEGACY_STAGE_OWNER='ABSENT'
  LEGACY_STAGE_GROUP='ABSENT'
  LEGACY_STAGE_MODE='ABSENT'
fi

probe_http() {
  local label="$1"
  local endpoint="$2"
  shift 2
  local body headers rc status content_type kind www_auth
  body="$(mktemp)"
  headers="$(mktemp)"
  set +e
  status="$(curl --silent --show-error --connect-timeout 10 --max-time 20 \
    --output "$body" --dump-header "$headers" --write-out '%{http_code}' \
    "$@" "$endpoint")"
  rc="$?"
  set -e
  content_type="$(awk 'BEGIN{IGNORECASE=1} /^content-type:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0} END{print value}' "$headers")"
  www_auth="$(awk 'BEGIN{IGNORECASE=1} /^www-authenticate:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0} END{print value}' "$headers")"
  kind="$(python3 - "$body" <<'PY'
import json
import sys
from pathlib import Path
raw = Path(sys.argv[1]).read_bytes()[:65536]
if not raw:
    print('EMPTY')
    raise SystemExit
text = raw.decode('utf-8', errors='replace').lstrip()
try:
    json.loads(text)
except Exception:
    lowered = text[:256].lower()
    print('HTML' if lowered.startswith('<!doctype html') or lowered.startswith('<html') else 'OTHER')
else:
    print('JSON')
PY
)"
  rm -f "$body" "$headers"
  printf '%s_RC=%s\n' "$label" "$rc"
  printf '%s_STATUS=%s\n' "$label" "$status"
  printf '%s_CONTENT_TYPE=%s\n' "$label" "$content_type"
  printf '%s_KIND=%s\n' "$label" "$kind"
  printf '%s_WWW_AUTH=%s\n' "$label" "$www_auth"
}

mapfile -t no_auth < <(probe_http NO_AUTH "$ENDPOINT")
mapfile -t fake_bearer < <(probe_http FAKE_BEARER "$ENDPOINT" -H 'Authorization: Bearer invalid-cockpit-token-1218')
mapfile -t local_http < <(probe_http LOCAL_HTTP "http://127.0.0.1$PATH_ONLY" -H "Host: $HOSTNAME")
mapfile -t local_https < <(probe_http LOCAL_HTTPS "$ENDPOINT" --resolve "$HOSTNAME:443:127.0.0.1")

read_probe() {
  local prefix="$1"
  local key="$2"
  shift 2
  printf '%s\n' "$@" | sed -n "s/^${prefix}_${key}=//p" | tail -n 1
}

probe_json() {
  local prefix="$1"
  shift
  local rc status content_type kind www_auth
  rc="$(read_probe "$prefix" RC "$@")"
  status="$(read_probe "$prefix" STATUS "$@")"
  content_type="$(read_probe "$prefix" CONTENT_TYPE "$@")"
  kind="$(read_probe "$prefix" KIND "$@")"
  www_auth="$(read_probe "$prefix" WWW_AUTH "$@")"
  jq -cn \
    --argjson rc "$rc" \
    --arg status "$status" \
    --arg content_type "$content_type" \
    --arg body_kind "$kind" \
    --arg www_authenticate "$www_auth" \
    '{curl_rc:$rc,status:$status,content_type:$content_type,body_kind:$body_kind,www_authenticate:$www_authenticate}'
}

no_auth_json="$(probe_json NO_AUTH "${no_auth[@]}")"
fake_json="$(probe_json FAKE_BEARER "${fake_bearer[@]}")"
local_http_json="$(probe_json LOCAL_HTTP "${local_http[@]}")"
local_https_json="$(probe_json LOCAL_HTTPS "${local_https[@]}")"

nginx_topology="$(python3 - "$HOSTNAME" <<'PY'
import json
import re
import sys
from pathlib import Path

hostname = sys.argv[1]
roots = [Path('/etc/nginx/sites-enabled'), Path('/etc/nginx/sites-available'), Path('/etc/nginx/conf.d')]
items = []
seen = set()
for root in roots:
    if not root.is_dir():
        continue
    for entry in sorted(root.iterdir(), key=lambda p: str(p)):
        try:
            target = entry.resolve(strict=True)
        except (OSError, RuntimeError):
            continue
        key = (str(entry), str(target))
        if key in seen or not target.is_file():
            continue
        seen.add(key)
        try:
            text = target.read_text(encoding='utf-8')
        except (OSError, UnicodeError):
            continue
        if hostname not in text and 'Agency PREPROD' not in text:
            continue
        directives = []
        for line in text.splitlines():
            stripped = line.strip()
            if re.match(r'^(listen|server_name|auth_basic|proxy_pass|fastcgi_pass|include)\b', stripped):
                directives.append(stripped[:300])
        items.append({
            'path': str(entry),
            'target': str(target),
            'is_symlink': entry.is_symlink(),
            'directives': directives,
        })
print(json.dumps(items, separators=(',', ':'), sort_keys=True))
PY
)"
jq -e 'type == "array"' <<<"$nginx_topology" >/dev/null

state="$(jq -n \
  --arg current_release "$current_release" \
  --arg current_target "$current_target" \
  --arg settings_sha256 "$settings_sha256" \
  --arg settings_reader "$settings_reader" \
  --arg nginx_sha256 "$nginx_sha256" \
  --argjson nginx_location_count "$nginx_location_count" \
  --arg nginx_auth_basic_off "$nginx_auth_basic_off" \
  --arg nginx_authorization_forwarded "$nginx_authorization_forwarded" \
  --arg nginx_script_filename "$nginx_script_filename" \
  --arg nginx_fastcgi_pass "$nginx_fastcgi_pass" \
  --arg token_state "$TOKEN_STATE" \
  --arg token_owner "$TOKEN_OWNER" \
  --arg token_group "$TOKEN_GROUP" \
  --arg token_mode "$TOKEN_MODE" \
  --argjson token_size "$TOKEN_SIZE" \
  --argjson token_mtime "$TOKEN_MTIME" \
  --argjson token_ctime "$TOKEN_CTIME" \
  --arg legacy_stage_path "$LEGACY_FAILED_RUN_DIR" \
  --arg legacy_stage_presence "$LEGACY_STAGE_PRESENCE" \
  --arg legacy_stage_access "$LEGACY_STAGE_ACCESS" \
  --arg legacy_stage_type "$LEGACY_STAGE_TYPE" \
  --arg legacy_stage_owner "$LEGACY_STAGE_OWNER" \
  --arg legacy_stage_group "$LEGACY_STAGE_GROUP" \
  --arg legacy_stage_mode "$LEGACY_STAGE_MODE" \
  --argjson no_auth "$no_auth_json" \
  --argjson fake_bearer "$fake_json" \
  --argjson local_http "$local_http_json" \
  --argjson local_https "$local_https_json" \
  --argjson nginx_topology "$nginx_topology" \
  '{
    current_release: $current_release,
    current_target: $current_target,
    settings: {sha256:$settings_sha256,cockpit_token_reader:$settings_reader},
    nginx: {
      sha256:$nginx_sha256,
      machine_location_count:$nginx_location_count,
      auth_basic_off:$nginx_auth_basic_off,
      authorization_forwarded:$nginx_authorization_forwarded,
      script_filename_contract:$nginx_script_filename,
      fastcgi_pass_present:$nginx_fastcgi_pass,
      topology:$nginx_topology
    },
    token_file: {
      state:$token_state,owner:$token_owner,group:$token_group,mode:$token_mode,
      size:$token_size,mtime_epoch:$token_mtime,ctime_epoch:$token_ctime
    },
    legacy_failed_staging: {
      path:$legacy_stage_path,
      presence:$legacy_stage_presence,
      access:$legacy_stage_access,
      type:$legacy_stage_type,
      owner:$legacy_stage_owner,
      group:$legacy_stage_group,
      mode:$legacy_stage_mode
    },
    http: {
      no_auth:$no_auth,
      fake_bearer:$fake_bearer,
      local_http:$local_http,
      local_https:$local_https
    }
  }')"

canonical="$(jq -cS . <<<"$state")"
plan_digest="$(printf '%s' "$canonical" | sha256sum | awk '{print $1}')"

jq -n \
  --arg main_sha "$MAIN_SHA" \
  --arg plan_id "$PLAN_ID" \
  --arg plan_digest "$plan_digest" \
  --argjson state "$state" \
  '{
    STATUS:"PASS",ISSUE:1218,TARGET:"PREPROD",MODE:"PLAN",
    MAIN_SHA:$main_sha,PLAN_ID:$plan_id,PLAN_DIGEST:$plan_digest,STATE:$state,
    PREPROD_MUTATION:"NONE",PROD_ACCESS:"NONE",SECRET_CONTENT_EXPOSED:false
  }'
