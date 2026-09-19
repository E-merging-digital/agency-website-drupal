#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APPROVED_PLAN="${1:-}"
EXPECTED_DIGEST="${2:-}"
EXPECTED_MAIN="${3:-}"
PLAN_SCRIPT="${4:-}"
NGINX_TEMPLATE="${5:-}"
SETTINGS_TEMPLATE="${6:-}"
TOKEN_PROVISIONER="${7:-}"
NGINX_DIAGNOSTIC="${8:-}"
NGINX_ACTIVE_IDENTITY="${9:-}"

PROJECT_ROOT='/var/www/agency-preprod'
CURRENT_LINK="$PROJECT_ROOT/current"
SETTINGS_FILE="$PROJECT_ROOT/shared/settings/settings.php"
NGINX_SITE='/etc/nginx/sites-available/agency-preprod'
TOKEN_FILE='/etc/agency-preprod/cockpit-state-token'
LEGACY_FAILED_RUN_DIR='/root/agency-1218-35221860275-1'
HOSTNAME='preprod.emergingdigital.be'
PATH_ONLY='/api/agency-operations/v1/environment-data-state'
ENDPOINT="https://$HOSTNAME$PATH_ONLY"
BACKUP_DIR="$(mktemp -d /root/agency-1218-apply.XXXXXX)"
MUTATION_STARTED='NO'
COMMITTED='NO'

fail() {
  printf '[cockpit-transport-apply] ERROR: %s\n' "$1" >&2
  exit 1
}

rollback() {
  local rc="$?"
  trap - EXIT

  if [[ "$rc" -ne 0 && "$MUTATION_STARTED" == 'YES' && "$COMMITTED" != 'YES' ]]; then
    local token_remove_rc=0
    local settings_restore_rc=0
    local nginx_restore_rc=0
    local nginx_test_rc=0
    local nginx_reload_rc=0
    local restored_settings_sha=''
    local restored_nginx_sha=''
    local restored_current_target=''
    local restored_current_release=''
    local token_absent='NO'
    local rollback_status='PASS'

    set +e
    rm -f -- "$TOKEN_FILE"
    token_remove_rc="$?"

    if [[ -f "$BACKUP_DIR/settings.php" && ! -L "$BACKUP_DIR/settings.php" ]]; then
      cp -a -- "$BACKUP_DIR/settings.php" "$SETTINGS_FILE"
      settings_restore_rc="$?"
    else
      settings_restore_rc=98
    fi

    if [[ -f "$BACKUP_DIR/nginx.conf" && ! -L "$BACKUP_DIR/nginx.conf" ]]; then
      cp -a -- "$BACKUP_DIR/nginx.conf" "$NGINX_SITE"
      nginx_restore_rc="$?"
    else
      nginx_restore_rc=98
    fi

    restored_settings_sha="$(sha256sum "$SETTINGS_FILE" 2>/dev/null | awk '{print $1}')"
    restored_nginx_sha="$(sha256sum "$NGINX_SITE" 2>/dev/null | awk '{print $1}')"
    restored_current_target="$(readlink -f "$CURRENT_LINK" 2>/dev/null)"
    restored_current_release="$(basename "$restored_current_target" 2>/dev/null)"

    if [[ ! -e "$TOKEN_FILE" && ! -L "$TOKEN_FILE" ]]; then
      token_absent='YES'
    fi

    nginx -t >/dev/null 2>&1
    nginx_test_rc="$?"
    if [[ "$nginx_test_rc" -eq 0 ]]; then
      systemctl reload nginx >/dev/null 2>&1
      nginx_reload_rc="$?"
    else
      nginx_reload_rc=99
    fi
    set -e

    if [[ "$token_remove_rc" -ne 0
      || "$settings_restore_rc" -ne 0
      || "$nginx_restore_rc" -ne 0
      || "$restored_settings_sha" != "$APPROVED_SETTINGS_SHA"
      || "$restored_nginx_sha" != "$APPROVED_NGINX_SHA"
      || "$restored_current_release" != "$APPROVED_CURRENT_RELEASE"
      || "$token_absent" != 'YES'
      || "$nginx_test_rc" -ne 0
      || "$nginx_reload_rc" -ne 0 ]]; then
      rollback_status='FAIL'
    fi

    if [[ "$rollback_status" == 'FAIL' ]]; then
      jq -n \
        --arg main_sha "$EXPECTED_MAIN" \
        --arg plan_digest "$EXPECTED_DIGEST" \
        --arg settings_sha_match "$([[ "$restored_settings_sha" == "$APPROVED_SETTINGS_SHA" ]] && printf YES || printf NO)" \
        --arg nginx_sha_match "$([[ "$restored_nginx_sha" == "$APPROVED_NGINX_SHA" ]] && printf YES || printf NO)" \
        --arg release_match "$([[ "$restored_current_release" == "$APPROVED_CURRENT_RELEASE" ]] && printf YES || printf NO)" \
        --arg token_absent "$token_absent" \
        --argjson token_remove_rc "$token_remove_rc" \
        --argjson settings_restore_rc "$settings_restore_rc" \
        --argjson nginx_restore_rc "$nginx_restore_rc" \
        --argjson nginx_test_rc "$nginx_test_rc" \
        --argjson nginx_reload_rc "$nginx_reload_rc" \
        '{
          STATUS:"ROLLBACK_FAILURE",ISSUE:1218,TARGET:"PREPROD",MODE:"APPLY",
          MAIN_SHA:$main_sha,PLAN_DIGEST:$plan_digest,
          RESTORE:{
            settings_sha_match:$settings_sha_match,
            nginx_sha_match:$nginx_sha_match,
            current_release_match:$release_match,
            token_absent:$token_absent,
            token_remove_rc:$token_remove_rc,
            settings_restore_rc:$settings_restore_rc,
            nginx_restore_rc:$nginx_restore_rc,
            nginx_test_rc:$nginx_test_rc,
            nginx_reload_rc:$nginx_reload_rc
          },
          PROD_ACCESS:"NONE",DB_MUTATION:"NONE",SECRET_CONTENT_EXPOSED:false
        }' >&2
      rm -rf -- "$BACKUP_DIR"
      exit 97
    fi
  fi

  rm -rf -- "$BACKUP_DIR"
  exit "$rc"
}
trap rollback EXIT

[[ "$(id -u)" -eq 0 ]] || fail 'Root authority is required.'
[[ "$EXPECTED_DIGEST" =~ ^[0-9a-f]{64}$ ]] || fail 'Expected PLAN digest is invalid.'
[[ "$EXPECTED_MAIN" =~ ^[0-9a-f]{40}$ ]] || fail 'Expected main SHA is invalid.'
for file in "$APPROVED_PLAN" "$PLAN_SCRIPT" "$NGINX_TEMPLATE" "$SETTINGS_TEMPLATE" "$TOKEN_PROVISIONER" "$NGINX_DIAGNOSTIC" "$NGINX_ACTIVE_IDENTITY"; do
  [[ -f "$file" && ! -L "$file" ]] || fail "Required source is missing or unsafe: $file"
done
[[ -f "$SETTINGS_FILE" && ! -L "$SETTINGS_FILE" ]] || fail 'Shared settings.php is missing or unsafe.'
[[ -f "$NGINX_SITE" && ! -L "$NGINX_SITE" ]] || fail 'Nginx site is missing or unsafe.'
for command in base64 curl jq nginx openssl php python3 runuser sha256sum stat systemctl; do
  command -v "$command" >/dev/null 2>&1 || fail "$command is required."
done

jq -e --arg main "$EXPECTED_MAIN" --arg digest "$EXPECTED_DIGEST" '
  .STATUS == "PASS"
  and .ISSUE == 1218
  and .TARGET == "PREPROD"
  and .MODE == "PLAN"
  and .MAIN_SHA == $main
  and .PLAN_DIGEST == $digest
  and .PREPROD_MUTATION == "NONE"
  and .PROD_ACCESS == "NONE"
  and .SECRET_CONTENT_EXPOSED == false
' "$APPROVED_PLAN" >/dev/null

APPROVED_CURRENT_RELEASE="$(jq -r '.STATE.current_release // empty' "$APPROVED_PLAN")"
APPROVED_SETTINGS_SHA="$(jq -r '.STATE.settings.sha256 // empty' "$APPROVED_PLAN")"
APPROVED_NGINX_SHA="$(jq -r '.STATE.nginx.sha256 // empty' "$APPROVED_PLAN")"
[[ "$APPROVED_CURRENT_RELEASE" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'Approved PLAN current release is invalid.'
[[ "$APPROVED_SETTINGS_SHA" =~ ^[0-9a-f]{64}$ ]] || fail 'Approved PLAN settings SHA is invalid.'
[[ "$APPROVED_NGINX_SHA" =~ ^[0-9a-f]{64}$ ]] || fail 'Approved PLAN Nginx SHA is invalid.'
[[ -L "$CURRENT_LINK" ]] || fail 'PREPROD current release symlink is missing.'

# Re-run the stale PLAN under the same unprivileged identity and exact
# repository diagnostic/template inputs used by the approved PLAN.
nginx_diagnostic_b64="$(base64 -w0 "$NGINX_DIAGNOSTIC")"
nginx_template_b64="$(base64 -w0 "$NGINX_TEMPLATE")"
nginx_active_identity_b64="$(base64 -w0 "$NGINX_ACTIVE_IDENTITY")"
stale_json="$(runuser -u agency-preprod -- bash -s -- \
  "$EXPECTED_MAIN" 'plan-1218-stale-check' \
  "$nginx_diagnostic_b64" "$nginx_template_b64" "$nginx_active_identity_b64" \
  < "$PLAN_SCRIPT")"
approved_operational_state="$(jq -cS '.STATE | del(.legacy_failed_staging)' "$APPROVED_PLAN")"
live_operational_state="$(jq -cS '.STATE | del(.legacy_failed_staging)' <<<"$stale_json")"
approved_operational_digest="$(printf '%s' "$approved_operational_state" | sha256sum | awk '{print $1}')"
live_operational_digest="$(printf '%s' "$live_operational_state" | sha256sum | awk '{print $1}')"
[[ "$live_operational_digest" == "$approved_operational_digest" ]] || fail 'STALE_PLAN: live PREPROD operational state no longer matches the approved PLAN.'

LEGACY_STAGING_CLEANUP='ABSENT'
[[ "$LEGACY_FAILED_RUN_DIR" == '/root/agency-1218-35221860275-1' ]] || fail 'Legacy staging path is not the exact authorized recovery target.'
[[ ! -L "$LEGACY_FAILED_RUN_DIR" ]] || fail 'Legacy staging recovery target is a symlink.'
if [[ -e "$LEGACY_FAILED_RUN_DIR" ]]; then
  [[ -d "$LEGACY_FAILED_RUN_DIR" ]] || fail 'Legacy staging recovery target is not a directory.'
  rm -rf -- "$LEGACY_FAILED_RUN_DIR"
  [[ ! -e "$LEGACY_FAILED_RUN_DIR" && ! -L "$LEGACY_FAILED_RUN_DIR" ]] || fail 'Legacy staging recovery cleanup did not converge.'
  LEGACY_STAGING_CLEANUP='REMOVED'
fi

cp -a -- "$SETTINGS_FILE" "$BACKUP_DIR/settings.php"
cp -a -- "$NGINX_SITE" "$BACKUP_DIR/nginx.conf"

settings_tmp="$(mktemp "$(dirname "$SETTINGS_FILE")/.settings.php.1218.XXXXXX")"
nginx_tmp="$(mktemp "$(dirname "$NGINX_SITE")/.agency-preprod.1218.XXXXXX")"
cp -a -- "$SETTINGS_FILE" "$settings_tmp"
cp -a -- "$NGINX_SITE" "$nginx_tmp"

nginx_insert_result="$(python3 "$NGINX_DIAGNOSTIC" insert \
  "$nginx_tmp" "$NGINX_TEMPLATE" "$HOSTNAME" "$PATH_ONLY")"
jq -e '
  .status == "INSERTED"
  and (.server_block | test("^server-[0-9]+$"))
' <<<"$nginx_insert_result" >/dev/null || fail 'Structural Nginx insertion did not converge.'
python3 - "$settings_tmp" "$SETTINGS_TEMPLATE" <<'PY'
import re
import sys
from pathlib import Path

live_path = Path(sys.argv[1])
source_path = Path(sys.argv[2])
live = live_path.read_text(encoding='utf-8')
source = source_path.read_text(encoding='utf-8')
pattern = re.compile(
    r'(?ms)^// The cockpit bearer is runtime-only server state\..*?^unset\(\$agency_cockpit_token_file\);\n'
)
source_matches = list(pattern.finditer(source))
if len(source_matches) != 1:
    raise SystemExit('approved settings template does not contain exactly one canonical cockpit token reader')

source_block = source_matches[0].group(0)
live_matches = list(pattern.finditer(live))
has_token_path = '/etc/agency-preprod/cockpit-state-token' in live
has_setting = 'agency_operations_cockpit_state_token' in live

def normalize(block: str) -> str:
    return '\n'.join(line.strip() for line in block.strip().splitlines())

if len(live_matches) == 1:
    live_match = live_matches[0]
    if normalize(live_match.group(0)) != normalize(source_block):
        raise SystemExit('live settings canonical cockpit token reader differs from approved template')
    remainder = live[:live_match.start()] + live[live_match.end():]
    if '/etc/agency-preprod/cockpit-state-token' in remainder or 'agency_operations_cockpit_state_token' in remainder:
        raise SystemExit('live settings contains conflicting cockpit token reader logic outside the canonical block')
    # Canonical reader is already converged. Preserve it exactly and never
    # duplicate the block.
    raise SystemExit(0)

if len(live_matches) > 1:
    raise SystemExit('live settings contains duplicate cockpit token reader blocks')

if has_token_path or has_setting:
    raise SystemExit('live settings contains conflicting or partial cockpit token reader logic')

marker = '// PREPROD is fail-safe: never inherit production-only configuration.'
pos = live.find(marker)
if pos < 0:
    raise SystemExit('live settings insertion marker is missing')
block = source_block.rstrip('\n') + '\n\n'
live_path.write_text(live[:pos] + block + live[pos:], encoding='utf-8')
PY

php -l "$settings_tmp" >/dev/null
nginx_candidate_sha="$(sha256sum "$nginx_tmp" | awk '{print $1}')"
settings_candidate_sha="$(sha256sum "$settings_tmp" | awk '{print $1}')"
[[ "$nginx_candidate_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'Candidate Nginx SHA is invalid.'
[[ "$settings_candidate_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'Candidate settings SHA is invalid.'

MUTATION_STARTED='YES'
mv -f -- "$settings_tmp" "$SETTINGS_FILE"
mv -f -- "$nginx_tmp" "$NGINX_SITE"
php -l "$SETTINGS_FILE" >/dev/null
nginx -t >/dev/null

bearer="$(openssl rand -hex 32)"
[[ ${#bearer} -ge 32 ]] || fail 'Generated bearer is unexpectedly short.'
printf '%s\n' "$bearer" | "$TOKEN_PROVISIONER" >/dev/null
[[ "$(stat -c '%U:%G:%a' "$TOKEN_FILE")" == 'root:www-data:640' ]] || fail 'Runtime token ownership/mode mismatch.'

systemctl reload nginx

probe_http() {
  local endpoint="$1"
  shift
  local body headers status_remote rc status remote_ip kind content_type www_auth location_header redirect_json redirect_origin scheme
  body="$(mktemp)"
  headers="$(mktemp)"
  set +e
  status_remote="$(curl --silent --show-error --connect-timeout 10 --max-time 20 \
    --output "$body" --dump-header "$headers" --write-out '%{http_code}|%{remote_ip}' \
    "$@" "$endpoint")"
  rc="$?"
  set -e
  IFS='|' read -r status remote_ip <<<"$status_remote"
  content_type="$(awk 'BEGIN{IGNORECASE=1} /^content-type:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0} END{print value}' "$headers")"
  www_auth="$(awk 'BEGIN{IGNORECASE=1} /^www-authenticate:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0} END{print value}' "$headers")"
  location_header="$(awk 'BEGIN{IGNORECASE=1} /^location:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); value=$0} END{print value}' "$headers")"
  scheme="${endpoint%%:*}"
  redirect_json="$(python3 "$NGINX_DIAGNOSTIC" redirect "$status" "$location_header" "$HOSTNAME" "$scheme")"
  redirect_origin="$(python3 "$NGINX_DIAGNOSTIC" origin "$NGINX_SITE" "$HOSTNAME" "$PATH_ONLY" "$status")"
  kind="$(python3 - "$body" <<'PY'
import json
import sys
from pathlib import Path
raw = Path(sys.argv[1]).read_bytes()[:262144]
try:
    value = json.loads(raw.decode('utf-8'))
except Exception:
    print('NON_JSON')
else:
    print('JSON' if isinstance(value, (dict, list)) else 'JSON_SCALAR')
PY
)"
  rm -f -- "$body" "$headers"
  jq -cn \
    --argjson curl_rc "$rc" \
    --arg status "$status" \
    --arg body_kind "$kind" \
    --arg content_type "$content_type" \
    --arg www_authenticate "$www_auth" \
    --arg remote_ip "$remote_ip" \
    --argjson redirect "$redirect_json" \
    --arg redirect_origin "$redirect_origin" \
    '{
      curl_rc:$curl_rc,
      status:$status,
      body_kind:$body_kind,
      content_type:$content_type,
      www_authenticate:$www_authenticate,
      remote_ip:$remote_ip,
      proxy_bypass:false,
      location_header_kind:$redirect.location_header_kind,
      normalized_path:$redirect.normalized_path,
      redirect_origin:$redirect_origin
    }'
}

probe_local_https() {
  local receipt
  receipt="$(probe_http "$ENDPOINT" --noproxy '*' --resolve "$HOSTNAME:443:127.0.0.1" "$@")"
  [[ "$(jq -r '.remote_ip' <<<"$receipt")" == '127.0.0.1' ]] \
    || fail 'LOCAL_PROBE_INVALID: HTTPS remote IP is not loopback.'
  jq -c '.proxy_bypass = true' <<<"$receipt"
}

probe_contract() {
  jq -r '.status + "|" + .body_kind' <<<"$1"
}

is_basic_challenge() {
  jq -e '(.www_authenticate | ascii_downcase | startswith("basic"))' <<<"$1" >/dev/null
}

local_no_auth="$(probe_local_https)"
local_fake_bearer="$(probe_local_https -H 'Authorization: Bearer invalid-cockpit-token-1218')"
local_real_bearer="$(probe_local_https -H "Authorization: Bearer $bearer")"
public_no_auth="$(probe_http "$ENDPOINT")"
public_fake_bearer="$(probe_http "$ENDPOINT" -H 'Authorization: Bearer invalid-cockpit-token-1218')"
public_real_bearer="$(probe_http "$ENDPOINT" -H "Authorization: Bearer $bearer")"

local_ok='NO'
public_ok='NO'
if [[ "$(probe_contract "$local_no_auth")" == '401|JSON' \
  && "$(probe_contract "$local_fake_bearer")" == '401|JSON' \
  && "$(probe_contract "$local_real_bearer")" == '200|JSON' ]]; then
  local_ok='YES'
fi
if [[ "$(probe_contract "$public_no_auth")" == '401|JSON' \
  && "$(probe_contract "$public_fake_bearer")" == '401|JSON' \
  && "$(probe_contract "$public_real_bearer")" == '200|JSON' ]]; then
  public_ok='YES'
fi

TRANSPORT_CLASSIFICATION='CONTRACT_MISMATCH'
if [[ "$local_ok" == 'YES' && "$public_ok" == 'YES' ]]; then
  TRANSPORT_CLASSIFICATION='CONVERGED'
elif is_basic_challenge "$local_no_auth" || is_basic_challenge "$local_fake_bearer" || is_basic_challenge "$local_real_bearer"; then
  TRANSPORT_CLASSIFICATION='LOCAL_HTTPS_BASIC_INTERCEPTION'
elif [[ "$local_ok" == 'YES' ]] && { is_basic_challenge "$public_no_auth" || is_basic_challenge "$public_fake_bearer" || is_basic_challenge "$public_real_bearer"; }; then
  TRANSPORT_CLASSIFICATION='PUBLIC_HTTPS_BASIC_INTERCEPTION'
elif [[ "$local_ok" != 'YES' ]]; then
  TRANSPORT_CLASSIFICATION='LOCAL_HTTPS_CONTRACT_MISMATCH'
else
  TRANSPORT_CLASSIFICATION='PUBLIC_HTTPS_CONTRACT_MISMATCH'
fi

if [[ "$TRANSPORT_CLASSIFICATION" != 'CONVERGED' ]]; then
  jq -n \
    --arg main_sha "$EXPECTED_MAIN" \
    --arg plan_digest "$EXPECTED_DIGEST" \
    --arg classification "$TRANSPORT_CLASSIFICATION" \
    --arg legacy_cleanup "$LEGACY_STAGING_CLEANUP" \
    --argjson local_no_auth "$local_no_auth" \
    --argjson local_fake_bearer "$local_fake_bearer" \
    --argjson local_real_bearer "$local_real_bearer" \
    --argjson public_no_auth "$public_no_auth" \
    --argjson public_fake_bearer "$public_fake_bearer" \
    --argjson public_real_bearer "$public_real_bearer" \
    '{
      STATUS:"FAIL",ISSUE:1218,TARGET:"PREPROD",MODE:"APPLY",
      MAIN_SHA:$main_sha,PLAN_DIGEST:$plan_digest,STALE_PLAN:"PASS",
      TRANSPORT_CLASSIFICATION:$classification,
      LEGACY_STAGING_CLEANUP:$legacy_cleanup,
      HTTP:{
        local_https:{no_auth:$local_no_auth,fake_bearer:$local_fake_bearer,real_bearer:$local_real_bearer},
        public_https:{no_auth:$public_no_auth,fake_bearer:$public_fake_bearer,real_bearer:$public_real_bearer}
      },
      PROD_ACCESS:"NONE",DB_MUTATION:"NONE",SECRET_CONTENT_EXPOSED:false,
      ROLLBACK_REQUIRED:true
    }'
  fail "Cockpit transport contract mismatch: $TRANSPORT_CLASSIFICATION"
fi

unset bearer
rm -f -- "$TOKEN_FILE"
local_cleanup="$(probe_local_https)"
public_cleanup="$(probe_http "$ENDPOINT")"
local_cleanup_contract="$(probe_contract "$local_cleanup")"
public_cleanup_contract="$(probe_contract "$public_cleanup")"
[[ "$local_cleanup_contract" == '503|JSON' || "$local_cleanup_contract" == '401|JSON' ]] \
  || fail "Local post-cleanup fail-closed contract mismatch: $local_cleanup_contract"
[[ "$public_cleanup_contract" == '503|JSON' || "$public_cleanup_contract" == '401|JSON' ]] \
  || fail "Public post-cleanup fail-closed contract mismatch: $public_cleanup_contract"

COMMITTED='YES'


jq -n \
  --arg main_sha "$EXPECTED_MAIN" \
  --arg plan_digest "$EXPECTED_DIGEST" \
  --arg nginx_sha "$nginx_candidate_sha" \
  --arg settings_sha "$settings_candidate_sha" \
  --arg classification "$TRANSPORT_CLASSIFICATION" \
  --arg legacy_cleanup "$LEGACY_STAGING_CLEANUP" \
  --argjson local_no_auth "$local_no_auth" \
  --argjson local_fake_bearer "$local_fake_bearer" \
  --argjson local_real_bearer "$local_real_bearer" \
  --argjson local_cleanup "$local_cleanup" \
  --argjson public_no_auth "$public_no_auth" \
  --argjson public_fake_bearer "$public_fake_bearer" \
  --argjson public_real_bearer "$public_real_bearer" \
  --argjson public_cleanup "$public_cleanup" \
  '{
    STATUS: "PASS",
    ISSUE: 1218,
    TARGET: "PREPROD",
    MODE: "APPLY",
    MAIN_SHA: $main_sha,
    PLAN_DIGEST: $plan_digest,
    STALE_PLAN: "PASS",
    NGINX_CONVERGED: true,
    SETTINGS_READER_CONVERGED: true,
    NGINX_SHA256: $nginx_sha,
    SETTINGS_SHA256: $settings_sha,
    TOKEN_PERMISSIONS_DURING_PROOF: "root:www-data:640",
    TRANSPORT_CLASSIFICATION: $classification,
    LEGACY_STAGING_CLEANUP: $legacy_cleanup,
    HTTP: {
      local_https: {
        no_auth: $local_no_auth,
        fake_bearer: $local_fake_bearer,
        real_bearer: $local_real_bearer,
        post_cleanup_no_auth: $local_cleanup
      },
      public_https: {
        no_auth: $public_no_auth,
        fake_bearer: $public_fake_bearer,
        real_bearer: $public_real_bearer,
        post_cleanup_no_auth: $public_cleanup
      }
    },
    TOKEN_CLEANUP: "PASS",
    TOKEN_PERSISTED: false,
    PROD_ACCESS: "NONE",
    DB_MUTATION: "NONE",
    SECRET_CONTENT_EXPOSED: false,
    ROLLBACK_REQUIRED: false
  }'
