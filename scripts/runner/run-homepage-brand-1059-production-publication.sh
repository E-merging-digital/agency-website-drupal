#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${HOMEPAGE_BRAND_1059_PROD_MODE:-}"
SERVER_HOST="${SERVER_HOST:-}"
SERVER_USER="${SERVER_USER:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/homepage-brand-1059-production-publication}"
RUN_ID="${GITHUB_RUN_ID:-0}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"
PROFILE_SHA256='e7f6e184a31048b4fcea7f126b6e24e9aa23db46522cd2537f441fd2f3218390'
FR_H1='Créer, améliorer ou moderniser votre plateforme web'
EN_H1='Create, improve or modernise your web platform'

case "$MODE" in
  dry-run|apply) ;;
  *) echo "Unsupported HOMEPAGE_BRAND_1059_PROD_MODE: $MODE" >&2; exit 1 ;;
esac
[[ "$RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUN_ATTEMPT" =~ ^[0-9]+$ ]]
[[ -n "$SERVER_HOST" ]]
[[ -n "$SERVER_USER" ]]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROFILE_LIBRARY="$SCRIPT_DIR/homepage-brand-1059.php"
[[ -f "$PROFILE_LIBRARY" ]]
php -l "$PROFILE_LIBRARY" >/dev/null
mkdir -p "$ARTIFACT_DIR"

remote_target="${SERVER_USER}@${SERVER_HOST}"
remote_stem="/tmp/agency-homepage-brand-1059-prod-${RUN_ID}-${RUN_ATTEMPT}"
remote_profile="${remote_stem}-profile.php"
remote_result="${remote_stem}-result.json"

cleanup_remote() {
  set +e
  ssh "$remote_target" "rm -f '$remote_profile' '$remote_result'" >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "$PROFILE_LIBRARY" "$remote_target:$remote_profile" >/dev/null

backup_file='NONE'
backup_verified='false'
if [[ "$MODE" == 'apply' ]]; then
  timestamp="$(date -u +%Y%m%d%H%M%S)"
  backup_stem="/var/www/agency/shared/backups/homepage-brand-1059-prod-${timestamp}-${RUN_ID}-${RUN_ATTEMPT}.sql"
  backup_file="${backup_stem}.gz"
  ssh "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; mkdir -p /var/www/agency/shared/backups; test -x vendor/bin/drush; vendor/bin/drush sql:dump --gzip --result-file='$backup_stem' >/dev/null; test -s '$backup_file'"
  backup_verified='true'
fi

set +e
ssh "$remote_target" \
  "set -euo pipefail; cd /var/www/agency/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_HOMEPAGE_BRAND_1059_MODE='$MODE' AGENCY_HOMEPAGE_BRAND_1059_RESULT_PATH='$remote_result' vendor/bin/drush php:script '$remote_profile'"
drush_status=$?
set -e

if (( drush_status != 0 )); then
  if ssh "$remote_target" "test -f '$remote_result'"; then
    scp "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null || true
  fi
  exit "$drush_status"
fi

scp "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null

jq -e --arg mode "$MODE" --arg profile_sha "$PROFILE_SHA256" \
  '.status == "PASS"
   and .profile == "homepage-brand-1059"
   and .profile_sha256 == $profile_sha
   and .issue_number == 1059
   and .target == "PREPROD"
   and .bundle == "page"
   and .language == "en"
   and .front == "/node/5"
   and .node.id == 5
   and .fr_mutated == "NO"
   and .content_sync == "RELEASED_UNCHANGED"
   and .prod_access == "NONE"
   and .prod_write == "NONE"
   and (
     if $mode == "apply" then
       (.verdict == "APPLIED" or .verdict == "IDEMPOTENT")
     else
       (.verdict == "UPDATE_READY" or .verdict == "IDEMPOTENT")
     end
   )' "$ARTIFACT_DIR/result.json" >/dev/null

content_sync_after="$(
  ssh "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; vendor/bin/drush php:eval '\$mapping = \\Drupal::service(\"emerging_digital_content.content_sync_mapping_repository\")->findByContentId(\"homepage\"); echo \$mapping ? \$mapping->status() : \"MISSING\";' 2>/dev/null" \
    | tr -d '\r\n'
)"
[[ "$content_sync_after" == 'released' ]]

cache_rebuilt='false'
public_fr_json='{"status":"NOT_APPLICABLE","h1":"NOT_APPLICABLE"}'
public_en_json='{"status":"NOT_APPLICABLE","h1":"NOT_APPLICABLE"}'
fr_database_snapshot='UNCHANGED_READ_ONLY'
en_profile="$(jq -r 'if .verdict == "IDEMPOTENT" then "CONVERGED" else "UPDATE_READY" end' "$ARTIFACT_DIR/result.json")"
prod_access='READ_ONLY'
prod_write='NONE'

probe_public() {
  local url="$1"
  local expected_h1="$2"
  local body
  body="$(mktemp "$ARTIFACT_DIR/.public-XXXXXX")"
  local meta
  meta="$(curl --silent --show-error --location --max-redirs 3 --connect-timeout 8 --max-time 15 \
    --output "$body" --write-out '%{http_code}|%{url_effective}' "$url")"
  local status final_url
  IFS='|' read -r status final_url <<<"$meta"
  [[ "$status" =~ ^[23][0-9][0-9]$ ]]
  local h1
  h1="$(python3 - "$body" <<'PY'
from html.parser import HTMLParser
import sys

class H1Parser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.in_h1 = False
        self.done = False
        self.parts = []
    def handle_starttag(self, tag, attrs):
        if tag.lower() == 'h1' and not self.done:
            self.in_h1 = True
    def handle_endtag(self, tag):
        if tag.lower() == 'h1' and self.in_h1:
            self.in_h1 = False
            self.done = True
    def handle_data(self, data):
        if self.in_h1:
            self.parts.append(data)

parser = H1Parser()
with open(sys.argv[1], 'r', encoding='utf-8', errors='replace') as handle:
    parser.feed(handle.read())
print(' '.join(' '.join(parser.parts).split()))
PY
)"
  rm -f -- "$body"
  [[ "$h1" == "$expected_h1" ]]
  jq -n --arg status "$status" --arg final_url "$final_url" --arg h1 "$h1" \
    '{status:$status, final_url:$final_url, h1:$h1}'
}

if [[ "$MODE" == 'apply' ]]; then
  ssh "$remote_target" \
    "set -euo pipefail; cd /var/www/agency/current; vendor/bin/drush cr >/dev/null"
  cache_rebuilt='true'
  public_fr_json="$(probe_public 'https://emergingdigital.be/fr' "$FR_H1")"
  public_en_json="$(probe_public 'https://emergingdigital.be/en' "$EN_H1")"
  fr_database_snapshot='UNCHANGED_BY_PROFILE_ASSERTION'
  en_profile='CONVERGED'
  prod_access='BOUNDED_WRITE'
  if [[ "$(jq -r '.verdict' "$ARTIFACT_DIR/result.json")" == 'APPLIED' ]]; then
    prod_write='MATERIALIZED'
  fi
fi

tmp="$ARTIFACT_DIR/result.tmp.json"
jq \
  --arg mode "$MODE" \
  --arg profile_sha "$PROFILE_SHA256" \
  --arg backup_file "$backup_file" \
  --argjson backup_verified "$backup_verified" \
  --argjson cache_rebuilt "$cache_rebuilt" \
  --arg fr_database_snapshot "$fr_database_snapshot" \
  --arg en_profile "$en_profile" \
  --arg prod_access "$prod_access" \
  --arg prod_write "$prod_write" \
  --argjson public_fr "$public_fr_json" \
  --argjson public_en "$public_en_json" \
  '. + {
    source_profile_target: .target,
    target: "PROD",
    control_issue: 1091,
    content_issue: 1059,
    profile_sha256: $profile_sha,
    language_mode: "FR_EN_APPROVED",
    prod_mutation: "EN_ONLY",
    fr_mutation: "FORBIDDEN",
    content_sync_before: "RELEASED",
    content_sync_after: "RELEASED",
    content_sync_mutation: "NONE",
    fr_database_snapshot: $fr_database_snapshot,
    en_profile: $en_profile,
    public_verification: {fr:$public_fr,en:$public_en},
    backup_file: $backup_file,
    backup_verified: $backup_verified,
    cache_rebuilt: $cache_rebuilt,
    prod_access: $prod_access,
    prod_write: $prod_write
  }' "$ARTIFACT_DIR/result.json" > "$tmp"
mv "$tmp" "$ARTIFACT_DIR/result.json"

jq -e --arg mode "$MODE" --arg profile_sha "$PROFILE_SHA256" --arg fr_h1 "$FR_H1" --arg en_h1 "$EN_H1" \
  '.status == "PASS"
   and .profile == "homepage-brand-1059"
   and .profile_sha256 == $profile_sha
   and .issue_number == 1059
   and .control_issue == 1091
   and .content_issue == 1059
   and .source_profile_target == "PREPROD"
   and .target == "PROD"
   and .node.id == 5
   and .language == "en"
   and .language_mode == "FR_EN_APPROVED"
   and .prod_mutation == "EN_ONLY"
   and .fr_mutated == "NO"
   and .fr_mutation == "FORBIDDEN"
   and .content_sync == "RELEASED_UNCHANGED"
   and .content_sync_before == "RELEASED"
   and .content_sync_after == "RELEASED"
   and .content_sync_mutation == "NONE"
   and (
     if $mode == "apply" then
       .backup_verified == true
       and .cache_rebuilt == true
       and .fr_database_snapshot == "UNCHANGED_BY_PROFILE_ASSERTION"
       and .en_profile == "CONVERGED"
       and (.public_verification.fr.status | test("^[23][0-9]{2}$"))
       and (.public_verification.en.status | test("^[23][0-9]{2}$"))
       and .public_verification.fr.h1 == $fr_h1
       and .public_verification.en.h1 == $en_h1
       and .prod_access == "BOUNDED_WRITE"
       and (
         (.verdict == "APPLIED" and .prod_write == "MATERIALIZED")
         or (.verdict == "IDEMPOTENT" and .prod_write == "NONE")
       )
     else
       .backup_file == "NONE"
       and .backup_verified == false
       and .cache_rebuilt == false
       and .fr_database_snapshot == "UNCHANGED_READ_ONLY"
       and (.en_profile == "UPDATE_READY" or .en_profile == "CONVERGED")
       and .public_verification.fr.status == "NOT_APPLICABLE"
       and .public_verification.en.status == "NOT_APPLICABLE"
       and .prod_access == "READ_ONLY"
       and .prod_write == "NONE"
     end
   )' "$ARTIFACT_DIR/result.json" >/dev/null
