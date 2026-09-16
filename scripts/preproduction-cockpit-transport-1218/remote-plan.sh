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
ENDPOINT='https://preprod.emergingdigital.be/api/agency-operations/v1/environment-data-state'

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

probe_http() {
  local label="$1"
  shift
  local body headers rc status content_type kind
  body="$(mktemp)"
  headers="$(mktemp)"
  set +e
  status="$(curl --silent --show-error --connect-timeout 10 --max-time 20 \
    --output "$body" --dump-header "$headers" --write-out '%{http_code}' \
    "$@" "$ENDPOINT")"
  rc="$?"
  set -e
  content_type="$(awk 'BEGIN{IGNORECASE=1} /^content-type:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0} END{print value}' "$headers")"
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
}

mapfile -t no_auth < <(probe_http NO_AUTH)
mapfile -t fake_bearer < <(probe_http FAKE_BEARER -H 'Authorization: Bearer invalid-cockpit-token-1218')

read_probe() {
  local prefix="$1"
  local key="$2"
  shift 2
  printf '%s\n' "$@" | sed -n "s/^${prefix}_${key}=//p" | tail -n 1
}

no_auth_rc="$(read_probe NO_AUTH RC "${no_auth[@]}")"
no_auth_status="$(read_probe NO_AUTH STATUS "${no_auth[@]}")"
no_auth_content_type="$(read_probe NO_AUTH CONTENT_TYPE "${no_auth[@]}")"
no_auth_kind="$(read_probe NO_AUTH KIND "${no_auth[@]}")"
fake_rc="$(read_probe FAKE_BEARER RC "${fake_bearer[@]}")"
fake_status="$(read_probe FAKE_BEARER STATUS "${fake_bearer[@]}")"
fake_content_type="$(read_probe FAKE_BEARER CONTENT_TYPE "${fake_bearer[@]}")"
fake_kind="$(read_probe FAKE_BEARER KIND "${fake_bearer[@]}")"

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
  --argjson no_auth_rc "$no_auth_rc" \
  --arg no_auth_status "$no_auth_status" \
  --arg no_auth_content_type "$no_auth_content_type" \
  --arg no_auth_kind "$no_auth_kind" \
  --argjson fake_rc "$fake_rc" \
  --arg fake_status "$fake_status" \
  --arg fake_content_type "$fake_content_type" \
  --arg fake_kind "$fake_kind" \
  '{
    current_release: $current_release,
    current_target: $current_target,
    settings: {
      sha256: $settings_sha256,
      cockpit_token_reader: $settings_reader
    },
    nginx: {
      sha256: $nginx_sha256,
      machine_location_count: $nginx_location_count,
      auth_basic_off: $nginx_auth_basic_off,
      authorization_forwarded: $nginx_authorization_forwarded,
      script_filename_contract: $nginx_script_filename,
      fastcgi_pass_present: $nginx_fastcgi_pass
    },
    token_file: {
      state: $token_state,
      owner: $token_owner,
      group: $token_group,
      mode: $token_mode,
      size: $token_size,
      mtime_epoch: $token_mtime,
      ctime_epoch: $token_ctime
    },
    http: {
      no_auth: {curl_rc: $no_auth_rc, status: $no_auth_status, content_type: $no_auth_content_type, body_kind: $no_auth_kind},
      fake_bearer: {curl_rc: $fake_rc, status: $fake_status, content_type: $fake_content_type, body_kind: $fake_kind}
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
    STATUS: "PASS",
    ISSUE: 1218,
    TARGET: "PREPROD",
    MODE: "PLAN",
    MAIN_SHA: $main_sha,
    PLAN_ID: $plan_id,
    PLAN_DIGEST: $plan_digest,
    STATE: $state,
    PREPROD_MUTATION: "NONE",
    PROD_ACCESS: "NONE",
    SECRET_CONTENT_EXPOSED: false
  }'
