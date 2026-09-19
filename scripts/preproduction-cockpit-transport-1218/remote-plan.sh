#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MAIN_SHA="${1:-}"
PLAN_ID="${2:-}"
NGINX_DIAGNOSTIC_B64="${3:-}"
NGINX_TEMPLATE_B64="${4:-}"
NGINX_ACTIVE_IDENTITY_B64="${5:-}"
PROJECT_ROOT='/var/www/agency-preprod'
CURRENT_LINK="$PROJECT_ROOT/current"
SETTINGS_FILE="$PROJECT_ROOT/shared/settings/settings.php"
NGINX_SITE='/etc/nginx/sites-available/agency-preprod'
NGINX_ENABLED_SITE='/etc/nginx/sites-enabled/agency-preprod'
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
[[ "$NGINX_DIAGNOSTIC_B64" =~ ^[A-Za-z0-9+/=]+$ ]] || fail 'Nginx diagnostic encoding is invalid.'
[[ "$NGINX_TEMPLATE_B64" =~ ^[A-Za-z0-9+/=]+$ ]] || fail 'Nginx template encoding is invalid.'
[[ "$NGINX_ACTIVE_IDENTITY_B64" =~ ^[A-Za-z0-9+/=]+$ ]] || fail 'Nginx active identity encoding is invalid.'
[[ -L "$CURRENT_LINK" ]] || fail 'PREPROD current release symlink is missing.'
[[ -f "$SETTINGS_FILE" && ! -L "$SETTINGS_FILE" ]] || fail 'Shared settings.php is missing or unsafe.'
[[ -e "$NGINX_SITE" || -L "$NGINX_SITE" ]] || fail 'PREPROD Nginx site is missing.'
[[ -r "$NGINX_SITE" ]] || fail 'PREPROD Nginx site is unreadable.'
for command in base64 curl jq nginx ps python3 sha256sum stat; do
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

nginx_diagnostic_script="$(mktemp)"
nginx_template_file="$(mktemp)"
nginx_active_identity_script="$(mktemp)"
cleanup_diagnostic() {
  rm -f -- "$nginx_diagnostic_script" "$nginx_template_file" "$nginx_active_identity_script"
}
trap cleanup_diagnostic EXIT
printf '%s' "$NGINX_DIAGNOSTIC_B64" | base64 -d > "$nginx_diagnostic_script"
printf '%s' "$NGINX_TEMPLATE_B64" | base64 -d > "$nginx_template_file"
printf '%s' "$NGINX_ACTIVE_IDENTITY_B64" | base64 -d > "$nginx_active_identity_script"
chmod 600 "$nginx_diagnostic_script" "$nginx_template_file" "$nginx_active_identity_script"
nginx_effective_route="$(python3 "$nginx_diagnostic_script" diagnose \
  "$NGINX_SITE" "$nginx_template_file" "$HOSTNAME" "$PATH_ONLY")"
jq -e '
  .effective_preprod_tls_server_block != "ABSENT"
  and .effective_preprod_tls_server_block != "AMBIGUOUS"
  and .effective_preprod_http_server_block != "AMBIGUOUS"
  and (.legacy_first_root_insertion_server_block | test("^(server-[0-9]+|ABSENT)$"))
  and (.legacy_first_root_insertion_is_tls | type == "boolean")
  and (.corrected_candidate_server_block | test("^server-[0-9]+$"))
  and .corrected_candidate_is_tls == true
  and (.server_blocks | type == "array")
  and (.machine_location_contracts | type == "object")
  and (.simulated_candidate_route_counts | type == "object")
' <<<"$nginx_effective_route" >/dev/null || fail 'Structural Nginx diagnostic is invalid.'

nginx_tls_server_id="$(jq -r '.effective_preprod_tls_server_block' <<<"$nginx_effective_route")"
nginx_location_count="$(jq '[.server_blocks[].exact_machine_route_count] | add // 0' <<<"$nginx_effective_route")"
nginx_tls_contract="$(jq -c --arg id "$nginx_tls_server_id" '.machine_location_contracts[$id]' <<<"$nginx_effective_route")"
nginx_auth_basic_off="$(jq -r 'if .auth_basic_off then "YES" else "NO" end' <<<"$nginx_tls_contract")"
nginx_authorization_forwarded="$(jq -r 'if .http_authorization_forwarded then "YES" else "NO" end' <<<"$nginx_tls_contract")"
nginx_script_filename="$(jq -r 'if .script_filename == "EXPECTED" then "YES" else "NO" end' <<<"$nginx_tls_contract")"
nginx_fastcgi_pass="$(jq -r 'if .fastcgi_pass == "EXPECTED" then "YES" else "NO" end' <<<"$nginx_tls_contract")"

mapfile -t nginx_master_lines < <(ps -C nginx -o args= 2>/dev/null | sed -n '/nginx: master process/p')
[[ ${#nginx_master_lines[@]} -eq 1 ]] || fail 'Exactly one Nginx master process identity is required.'
nginx_version="$(nginx -V 2>&1)" || fail 'nginx -V failed.'
nginx_main_config="$(python3 "$nginx_active_identity_script" main-config "${nginx_master_lines[0]}" "$nginx_version")"
nginx_active_identity="$(python3 "$nginx_active_identity_script" diagnose   / "$nginx_main_config" "$NGINX_SITE" "$NGINX_ENABLED_SITE"   "$nginx_diagnostic_script" "$nginx_template_file" "$HOSTNAME" "$PATH_ONLY")"
jq -e '
  (.status | test("^(PASS|FAIL_CLOSED|UNPROVEN|AMBIGUOUS|CANDIDATE_SOURCE_MISMATCH)$"))
  and (.nginx_main_config | startswith("/etc/nginx/"))
  and .canonical_site.path == "/etc/nginx/sites-available/agency-preprod"
  and (.canonical_site.sha256 | test("^[0-9a-f]{64}$"))
  and .enabled_site.path == "/etc/nginx/sites-enabled/agency-preprod"
  and (.include_chain.status | test("^(PROVEN|UNPROVEN|NOT_LOADED)$"))
  and (.loaded_preprod_tls_match_count | type == "number")
  and (.loaded_preprod_http_match_count | type == "number")
  and (.loaded_preprod_candidates | type == "array")
  and (.candidate.status | type == "string")
' <<<"$nginx_active_identity" >/dev/null || fail 'Active Nginx identity diagnostic is invalid.'

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
  local body headers rc status remote_ip status_remote content_type kind www_auth location_header redirect_json redirect_origin scheme
  body="$(mktemp)"
  headers="$(mktemp)"
  set +e
  status_remote="$(curl --silent --show-error --connect-timeout 10 --max-time 20 \
    --output "$body" --dump-header "$headers" --write-out '%{http_code}|%{remote_ip}' \
    "$@" "$endpoint")"
  rc="$?"
  IFS='|' read -r status remote_ip <<<"$status_remote"
  set -e
  content_type="$(awk 'BEGIN{IGNORECASE=1} /^content-type:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0} END{print value}' "$headers")"
  www_auth="$(awk 'BEGIN{IGNORECASE=1} /^www-authenticate:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0} END{print value}' "$headers")"
  location_header="$(awk 'BEGIN{IGNORECASE=1} /^location:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0} END{print value}' "$headers")"
  scheme="${endpoint%%:*}"
  redirect_json="$(python3 "$nginx_diagnostic_script" redirect "$status" "$location_header" "$HOSTNAME" "$scheme")"
  redirect_origin="$(python3 "$nginx_diagnostic_script" origin "$NGINX_SITE" "$HOSTNAME" "$PATH_ONLY" "$status")"
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
  printf '%s_REMOTE_IP=%s\n' "$label" "$remote_ip"
  printf '%s_CONTENT_TYPE=%s\n' "$label" "$content_type"
  printf '%s_KIND=%s\n' "$label" "$kind"
  printf '%s_WWW_AUTH=%s\n' "$label" "$www_auth"
  printf '%s_REDIRECT=%s\n' "$label" "$redirect_json"
  printf '%s_REDIRECT_ORIGIN=%s\n' "$label" "$redirect_origin"
}

mapfile -t no_auth < <(probe_http NO_AUTH "$ENDPOINT")
mapfile -t fake_bearer < <(probe_http FAKE_BEARER "$ENDPOINT" -H 'Authorization: Bearer invalid-cockpit-token-1218')
mapfile -t local_http < <(probe_http LOCAL_HTTP "http://$HOSTNAME$PATH_ONLY" --noproxy '*' --resolve "$HOSTNAME:80:127.0.0.1")
mapfile -t local_https < <(probe_http LOCAL_HTTPS "$ENDPOINT" --noproxy '*' --resolve "$HOSTNAME:443:127.0.0.1")

read_probe() {
  local prefix="$1"
  local key="$2"
  shift 2
  printf '%s\n' "$@" | sed -n "s/^${prefix}_${key}=//p" | tail -n 1
}

probe_json() {
  local prefix="$1"
  shift
  local rc status remote_ip content_type kind www_auth redirect redirect_origin
  rc="$(read_probe "$prefix" RC "$@")"
  status="$(read_probe "$prefix" STATUS "$@")"
  remote_ip="$(read_probe "$prefix" REMOTE_IP "$@")"
  content_type="$(read_probe "$prefix" CONTENT_TYPE "$@")"
  kind="$(read_probe "$prefix" KIND "$@")"
  www_auth="$(read_probe "$prefix" WWW_AUTH "$@")"
  redirect="$(read_probe "$prefix" REDIRECT "$@")"
  redirect_origin="$(read_probe "$prefix" REDIRECT_ORIGIN "$@")"
  jq -cn \
    --argjson rc "$rc" \
    --arg status "$status" \
    --arg remote_ip "$remote_ip" \
    --arg content_type "$content_type" \
    --arg body_kind "$kind" \
    --arg www_authenticate "$www_auth" \
    --argjson redirect "$redirect" \
    --arg redirect_origin "$redirect_origin" \
    '{curl_rc:$rc,status:$status,remote_ip:$remote_ip,content_type:$content_type,body_kind:$body_kind,www_authenticate:$www_authenticate,redirect:$redirect,redirect_origin:$redirect_origin}'
}

no_auth_json="$(probe_json NO_AUTH "${no_auth[@]}")"
fake_json="$(probe_json FAKE_BEARER "${fake_bearer[@]}")"
local_http_json="$(probe_json LOCAL_HTTP "${local_http[@]}" | jq '. + {proxy_bypass:true}')"
local_https_json="$(probe_json LOCAL_HTTPS "${local_https[@]}" | jq '. + {proxy_bypass:true}')"
[[ "$(jq -r '.remote_ip' <<<"$local_http_json")" == '127.0.0.1' ]] || fail 'LOCAL_PROBE_INVALID: HTTP remote IP is not loopback.'
[[ "$(jq -r '.remote_ip' <<<"$local_https_json")" == '127.0.0.1' ]] || fail 'LOCAL_PROBE_INVALID: HTTPS remote IP is not loopback.'

nginx_topology="$(jq -c '.server_blocks' <<<"$nginx_effective_route")"
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
  --argjson nginx_effective_route "$nginx_effective_route" \
  --argjson nginx_active_identity "$nginx_active_identity" \
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
      topology:$nginx_topology,
      effective_route:$nginx_effective_route,
      active_identity:$nginx_active_identity
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
