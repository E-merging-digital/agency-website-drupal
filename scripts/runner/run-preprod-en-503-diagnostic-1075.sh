#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PREPROD_SERVER_HOST="${PREPROD_SERVER_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
PREPROD_BASIC_AUTH_USER="${PREPROD_BASIC_AUTH_USER:-}"
PREPROD_BASIC_AUTH_PASSWORD="${PREPROD_BASIC_AUTH_PASSWORD:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/preprod-en-503-diagnostic-1075}"

EXPECTED_RELEASE_PATH='/var/www/agency-preprod/releases/20260907172731-d07994a2c54b'
PREPROD_ORIGIN='https://preprod.emergingdigital.be'
LOG_WINDOW_START='2026-09-07T14:32:45Z'
LOG_WINDOW_END='2026-09-07T14:32:55Z'
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_VERIFY='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

failure_stage='INPUT_VALIDATION'

write_failure_receipt() {
  local failure_result="$ARTIFACT_DIR/result.json"
  mkdir -p "$ARTIFACT_DIR" 2>/dev/null || return 0
  printf '%s\n' \
    '{' \
    '  "schema_version": 1,' \
    '  "target": "PREPROD",' \
    '  "issue": 1075,' \
    '  "result": "FAILURE",' \
    "  \"failure_stage\": \"$failure_stage\"," \
    '  "root_cause": "NOT_YET_PROVEN",' \
    '  "preprod_write": "NONE",' \
    '  "prod_access": "NONE"' \
    '}' > "$failure_result"
}

on_exit() {
  local exit_code="$?"
  trap - EXIT
  if [[ "$exit_code" -ne 0 ]]; then
    set +e
    write_failure_receipt
  fi
  exit "$exit_code"
}

trap on_exit EXIT

[[ -n "$PREPROD_SERVER_HOST" ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" ]]
[[ -n "$PREPROD_BASIC_AUTH_USER" ]]
[[ -n "$PREPROD_BASIC_AUTH_PASSWORD" ]]
[[ -f "$PREPROD_TRUST_PROVISION" ]]
[[ -f "$PREPROD_TRUST_VERIFY" ]]

failure_stage='ARTIFACT_PREPARATION'
mkdir -p "$ARTIFACT_DIR"

failure_stage='PREPROD_TRUST'
PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" \
  bash "$PREPROD_TRUST_PROVISION" PROVISION >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" \
  PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash "$PREPROD_TRUST_VERIFY" >/dev/null

ssh_common=(
  -i "$PREPROD_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15
)
remote_target="agency-preprod@$PREPROD_SERVER_HOST"

failure_stage='RUNTIME_IDENTITY'
runtime_identity="$(
  ssh "${ssh_common[@]}" "$remote_target" 'bash -s' <<'REMOTE_RUNTIME'
set -euo pipefail
CURRENT='/var/www/agency-preprod/current'

active_release="$(readlink -f "$CURRENT")"
printf 'active_release=%s\n' "$active_release"

cd "$CURRENT"
bootstrap="$(vendor/bin/drush status --field=bootstrap --format=string 2>/dev/null | tr -d '\r\n')"
printf 'drupal_bootstrap=%s\n' "$bootstrap"

maintenance_mode="$(
  vendor/bin/drush php:eval \
    'echo (int) \Drupal::state()->get("system.maintenance_mode", 0);' \
    2>/dev/null | tr -d '\r\n'
)"
printf 'maintenance_mode=%s\n' "$maintenance_mode"
REMOTE_RUNTIME
)"

value_from() {
  local source="$1"
  local key="$2"
  grep -m1 "^${key}=" <<<"$source" | cut -d= -f2- || true
}

active_release="$(value_from "$runtime_identity" active_release)"
drupal_bootstrap="$(value_from "$runtime_identity" drupal_bootstrap)"
maintenance_mode="$(value_from "$runtime_identity" maintenance_mode)"

[[ "$active_release" == "$EXPECTED_RELEASE_PATH" ]]
[[ "$drupal_bootstrap" == *Successful* ]]
[[ "$maintenance_mode" =~ ^[01]$ ]]

header_value() {
  local file="$1"
  local header="$2"
  awk -v wanted="$header" '
    BEGIN { IGNORECASE = 1 }
    index($0, wanted ":") == 1 {
      value = substr($0, length(wanted) + 2)
      sub(/^[[:space:]]+/, "", value)
      sub(/\r$/, "", value)
      last = value
    }
    END { print last }
  ' "$file"
}

probe_external() {
  local label="$1"
  local path="$2"
  local body="$ARTIFACT_DIR/.${label}.body"
  local headers="$ARTIFACT_DIR/.${label}.headers"
  local meta="$ARTIFACT_DIR/.${label}.meta"
  local target="${PREPROD_ORIGIN}${path}"
  local curl_rc=0

  : > "$body"
  : > "$headers"
  : > "$meta"

  set +e
  curl --silent --show-error \
    --location --max-redirs 3 \
    --connect-timeout 8 --max-time 15 \
    --user "$PREPROD_BASIC_AUTH_USER:$PREPROD_BASIC_AUTH_PASSWORD" \
    --user-agent 'agency-preprod-en-503-diagnostic-1075/1' \
    --dump-header "$headers" \
    --output "$body" \
    --write-out '%{http_code}\n%{url_effective}\n%{content_type}\n' \
    "$target" > "$meta"
  curl_rc="$?"
  set -e

  local status='000'
  local final_url="$target"
  local content_type=''
  if [[ "$curl_rc" -eq 0 ]]; then
    mapfile -t meta_lines < "$meta"
    status="${meta_lines[0]:-000}"
    final_url="${meta_lines[1]:-$target}"
    content_type="${meta_lines[2]:-}"
  fi
  [[ "$status" =~ ^[0-9]{3}$ ]]

  local body_sha='empty'
  if [[ -s "$body" ]]; then
    body_sha="$(sha256sum "$body" | awk '{print $1}')"
  fi
  [[ "$body_sha" == 'empty' || "$body_sha" =~ ^[0-9a-f]{64}$ ]]

  local html_identity
  html_identity="$(python3 - "$body" <<'PY'
import json
import sys
from html.parser import HTMLParser

class IdentityParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.in_title = False
        self.in_h1 = False
        self.title = []
        self.h1 = []
        self.h1_done = False

    def handle_starttag(self, tag, attrs):
        if tag.lower() == "title":
            self.in_title = True
        elif tag.lower() == "h1" and not self.h1_done:
            self.in_h1 = True

    def handle_endtag(self, tag):
        if tag.lower() == "title":
            self.in_title = False
        elif tag.lower() == "h1" and self.in_h1:
            self.in_h1 = False
            self.h1_done = True

    def handle_data(self, data):
        if self.in_title:
            self.title.append(data)
        if self.in_h1:
            self.h1.append(data)

def compact(parts):
    return " ".join(" ".join(parts).split())[:240]

parser = IdentityParser()
try:
    with open(sys.argv[1], "r", encoding="utf-8", errors="replace") as handle:
        parser.feed(handle.read())
except OSError:
    pass

print(json.dumps({"title": compact(parser.title), "h1": compact(parser.h1)}))
PY
)"

  jq -n \
    --arg status "$status" \
    --arg final_url "$final_url" \
    --arg content_type "$content_type" \
    --arg body_sha256 "$body_sha" \
    --arg cache_control "$(header_value "$headers" 'Cache-Control')" \
    --arg content_language "$(header_value "$headers" 'Content-Language')" \
    --arg vary "$(header_value "$headers" 'Vary')" \
    --arg age "$(header_value "$headers" 'Age')" \
    --arg x_drupal_cache "$(header_value "$headers" 'X-Drupal-Cache')" \
    --arg x_drupal_dynamic_cache "$(header_value "$headers" 'X-Drupal-Dynamic-Cache')" \
    --arg server "$(header_value "$headers" 'Server')" \
    --argjson html_identity "$html_identity" \
    '{
      status: $status,
      final_url: $final_url,
      content_type: $content_type,
      body_sha256: $body_sha256,
      title: $html_identity.title,
      h1: $html_identity.h1,
      headers: {
        cache_control: $cache_control,
        content_language: $content_language,
        vary: $vary,
        age: $age,
        x_drupal_cache: $x_drupal_cache,
        x_drupal_dynamic_cache: $x_drupal_dynamic_cache,
        server: $server
      }
    }'

  rm -f -- "$body" "$headers" "$meta"
}

failure_stage='EXTERNAL_HTTP_FR'
external_fr_json="$(probe_external external_fr '/fr')"
failure_stage='EXTERNAL_HTTP_EN'
external_en_json="$(probe_external external_en '/en')"

failure_stage='LOCAL_HTTP'
local_http_raw="$(
  {
    printf 'BASIC_USER=%q\n' "$PREPROD_BASIC_AUTH_USER"
    printf 'BASIC_PASSWORD=%q\n' "$PREPROD_BASIC_AUTH_PASSWORD"
    cat <<'REMOTE_LOCAL_HTTP'
set -euo pipefail
ORIGIN='https://preprod.emergingdigital.be'
RESOLVE='preprod.emergingdigital.be:443:127.0.0.1'

probe_local() {
  label="$1"
  path="$2"
  target="${ORIGIN}${path}"
  result=''
  rc=0
  set +e
  result="$(
    curl --silent --show-error \
      --location --max-redirs 3 \
      --connect-timeout 8 --max-time 15 \
      --user "$BASIC_USER:$BASIC_PASSWORD" \
      --user-agent 'agency-preprod-en-503-diagnostic-1075/1' \
      --resolve "$RESOLVE" \
      --output /dev/null \
      --write-out '%{http_code}|%{url_effective}|%{content_type}' \
      "$target"
  )"
  rc="$?"
  set -e
  if [ "$rc" -ne 0 ]; then
    result="000|$target|"
  fi
  printf '%s=%s\n' "$label" "$result"
}

probe_local local_fr '/fr'
probe_local local_en '/en'
REMOTE_LOCAL_HTTP
  } | ssh "${ssh_common[@]}" "$remote_target" 'bash -s'
)"

local_probe_json() {
  local label="$1"
  local raw
  raw="$(value_from "$local_http_raw" "$label")"
  local status final_url content_type
  IFS='|' read -r status final_url content_type <<<"$raw"
  status="${status:-000}"
  final_url="${final_url:-${PREPROD_ORIGIN}/}"
  content_type="${content_type:-}"
  [[ "$status" =~ ^[0-9]{3}$ ]]
  jq -n \
    --arg status "$status" \
    --arg final_url "$final_url" \
    --arg content_type "$content_type" \
    '{status:$status, final_url:$final_url, content_type:$content_type}'
}

local_fr_json="$(local_probe_json local_fr)"
local_en_json="$(local_probe_json local_en)"

failure_stage='DRUPAL_STATE'
runtime_probe_php="$(cat <<'PHP'
$start = strtotime('2026-09-07T14:32:45Z');
$end = strtotime('2026-09-07T14:32:55Z');
$database = \Drupal::database();
$log = [
  'status' => 'UNOBSERVABLE',
  'rows_scanned' => 0,
  'signals' => [
    'en' => 0,
    'maintenance' => 0,
    'routing' => 0,
    'language' => 0,
    'exception' => 0,
    'cache' => 0,
  ],
];

if ($database->schema()->tableExists('watchdog')) {
  $log['status'] = 'SUPPORTED';
  $query = $database->select('watchdog', 'w');
  $query->fields('w', ['type', 'message', 'location', 'referer']);
  $query->condition('timestamp', $start, '>=');
  $query->condition('timestamp', $end, '<=');
  $query->orderBy('wid', 'ASC');
  $query->range(0, 50);

  foreach ($query->execute() as $row) {
    $log['rows_scanned']++;
    $haystack = strtolower(implode(' ', [
      (string) ($row->type ?? ''),
      (string) ($row->message ?? ''),
      (string) ($row->location ?? ''),
      (string) ($row->referer ?? ''),
    ]));
    $patterns = [
      'en' => ['/en', ' en '],
      'maintenance' => ['maintenance'],
      'routing' => ['route', 'routing'],
      'language' => ['language', 'negotiat'],
      'exception' => ['exception', 'error'],
      'cache' => ['cache'],
    ];
    foreach ($patterns as $signal => $needles) {
      foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
          $log['signals'][$signal]++;
          break;
        }
      }
    }
  }
}

$languages = array_keys(\Drupal::languageManager()->getLanguages());
sort($languages);
$defaultLanguage = \Drupal::languageManager()->getDefaultLanguage()->getId();
$frontPage = (string) \Drupal::config('system.site')->get('page.front');
$homepage = \Drupal::entityTypeManager()->getStorage('node')->load(5);
$enTranslation = $homepage !== NULL && $homepage->hasTranslation('en');

print(json_encode([
  'log_window' => [
    'start' => '2026-09-07T14:32:45Z',
    'end' => '2026-09-07T14:32:55Z',
  ],
  'recent_drupal_log_signal' => $log,
  'languages' => $languages,
  'default_language' => $defaultLanguage,
  'front_page' => $frontPage,
  'homepage_node_id' => 5,
  'en_homepage_translation' => $enTranslation,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
PHP
)"
encoded_probe="$(printf '%s' "$runtime_probe_php" | base64 -w 0)"
[[ "$encoded_probe" =~ ^[A-Za-z0-9+/=]+$ ]]

printf -v runtime_probe_command \
  "set -euo pipefail; cd /var/www/agency-preprod/current; code=\$(printf '%%s' '%s' | base64 -d); vendor/bin/drush php:eval \"\$code\" 2>/dev/null" \
  "$encoded_probe"

runtime_probe_json="$(ssh "${ssh_common[@]}" "$remote_target" "$runtime_probe_command")"
jq -e '
  .log_window.start == "2026-09-07T14:32:45Z"
  and .log_window.end == "2026-09-07T14:32:55Z"
  and (.recent_drupal_log_signal.status == "SUPPORTED" or .recent_drupal_log_signal.status == "UNOBSERVABLE")
  and (.recent_drupal_log_signal.rows_scanned | type == "number")
  and (.languages | type == "array")
  and (.default_language | type == "string")
  and (.front_page | type == "string")
  and .homepage_node_id == 5
  and (.en_homepage_translation | type == "boolean")
' <<<"$runtime_probe_json" >/dev/null

failure_stage='DIAGNOSTIC_EVALUATION'
external_fr_status="$(jq -r '.status' <<<"$external_fr_json")"
external_en_status="$(jq -r '.status' <<<"$external_en_json")"
local_fr_status="$(jq -r '.status' <<<"$local_fr_json")"
local_en_status="$(jq -r '.status' <<<"$local_en_json")"

is_2xx_or_3xx() {
  [[ "$1" =~ ^[23][0-9][0-9]$ ]]
}

classification='G'
classification_label='INSUFFICIENT_EVIDENCE'
root_cause='NOT_YET_PROVEN'

if [[ "$maintenance_mode" == '1' \
  && "$external_fr_status" == '503' \
  && "$external_en_status" == '503' \
  && "$local_fr_status" == '503' \
  && "$local_en_status" == '503' ]]; then
  classification='A'
  classification_label='GLOBAL_DRUPAL_MAINTENANCE_STATE'
  root_cause='PROVEN'
elif [[ "$external_en_status" == '503' ]] \
  && is_2xx_or_3xx "$local_en_status" \
  && is_2xx_or_3xx "$external_fr_status"; then
  classification='D'
  classification_label='WEB_TIER_OR_EDGE_EN_SPECIFIC_503'
  root_cause='PROVEN'
fi

# The bounded watchdog counters and EN translation state remain observations only.
# They are not causal evidence for automatic B/E PROVEN classification.

cache_evidence='NOT_NEEDED'
if [[ "$classification" == 'G' && "$external_en_status" == '503' ]]; then
  cache_evidence='RESPONSE_HEADERS_ONLY'
fi

failure_stage='RESULT_RECEIPT'
result="$ARTIFACT_DIR/result.json"
jq -n \
  --arg expected_release "$EXPECTED_RELEASE_PATH" \
  --arg active_release "$active_release" \
  --arg active_release_binding 'MATCH' \
  --arg drupal_bootstrap "$drupal_bootstrap" \
  --arg system_maintenance_mode "$maintenance_mode" \
  --argjson external_fr "$external_fr_json" \
  --argjson external_en "$external_en_json" \
  --argjson local_fr "$local_fr_json" \
  --argjson local_en "$local_en_json" \
  --argjson runtime "$runtime_probe_json" \
  --arg cache_evidence "$cache_evidence" \
  --arg classification "$classification" \
  --arg classification_label "$classification_label" \
  --arg root_cause "$root_cause" \
  '{
    schema_version: 1,
    target: "PREPROD",
    issue: 1075,
    purpose: "homepage-1059-en-503-readonly-diagnostic",
    expected_release: $expected_release,
    active_release: $active_release,
    active_release_binding: $active_release_binding,
    drupal_bootstrap: $drupal_bootstrap,
    system_maintenance_mode: $system_maintenance_mode,
    external_http: {fr: $external_fr, en: $external_en},
    local_http: {fr: $local_fr, en: $local_en},
    recent_drupal_log_signal: $runtime.recent_drupal_log_signal,
    log_window: $runtime.log_window,
    languages: $runtime.languages,
    default_language: $runtime.default_language,
    front_page: $runtime.front_page,
    homepage_node_id: $runtime.homepage_node_id,
    en_homepage_translation: $runtime.en_homepage_translation,
    cache_evidence: {
      status: $cache_evidence,
      source: "external_response_headers",
      en: $external_en.headers
    },
    classification: {
      code: $classification,
      label: $classification_label,
      allowed: {
        A: "GLOBAL_DRUPAL_MAINTENANCE_STATE",
        B: "LANGUAGE_SPECIFIC_DRUPAL_RUNTIME_DEFECT",
        C: "STALE_APPLICATION_CACHE_FOR_EN",
        D: "WEB_TIER_OR_EDGE_EN_SPECIFIC_503",
        E: "HOMEPAGE_EN_TRANSLATION_OR_ROUTE_CAUSES_MAINTENANCE_RESPONSE",
        F: "OTHER_PROVEN_RUNTIME_DEFECT",
        G: "INSUFFICIENT_EVIDENCE"
      }
    },
    root_cause: $root_cause,
    preprod_access: "READ_ONLY",
    preprod_write: "NONE",
    mutation_capability: "NONE",
    prod_access: "NONE",
    prod_write: "NONE"
  }' > "$result"

jq -e '
  .schema_version == 1
  and .target == "PREPROD"
  and .issue == 1075
  and .active_release_binding == "MATCH"
  and (.drupal_bootstrap | type == "string")
  and (.system_maintenance_mode == "0" or .system_maintenance_mode == "1")
  and (.external_http.fr.status | test("^[0-9]{3}$"))
  and (.external_http.en.status | test("^[0-9]{3}$"))
  and (.local_http.fr.status | test("^[0-9]{3}$"))
  and (.local_http.en.status | test("^[0-9]{3}$"))
  and (.classification.code | test("^[A-G]$"))
  and .classification.allowed.C == "STALE_APPLICATION_CACHE_FOR_EN"
  and .classification.allowed.F == "OTHER_PROVEN_RUNTIME_DEFECT"
  and .preprod_access == "READ_ONLY"
  and .preprod_write == "NONE"
  and .mutation_capability == "NONE"
  and .prod_access == "NONE"
  and .prod_write == "NONE"
' "$result" >/dev/null
