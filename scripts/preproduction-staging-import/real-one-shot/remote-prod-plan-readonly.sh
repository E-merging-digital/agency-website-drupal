#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

fail_reason() {
  printf '%s\n' \
    'PLAN_OBSERVER_RESULT=FAIL_CLOSED' \
    "PLAN_REASON=$1"
  exit 1
}

EXPECTED_PROD_RELEASE_SHA="${1:-}"
[[ "$#" -eq 1 ]] || fail_reason 'PROD_OBSERVER_CONTEXT'
[[ "$EXPECTED_PROD_RELEASE_SHA" == 'AUTO' || "$EXPECTED_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || fail_reason 'PROD_OBSERVER_CONTEXT'

PROJECT_ROOT='/var/www/agency'
CURRENT_RELEASE="$PROJECT_ROOT/current"
PROMOTIONS_DIR="$PROJECT_ROOT/shared/promotions"

[[ -L "$CURRENT_RELEASE" ]] || fail_reason 'PROD_CURRENT_RELEASE'
[[ -d "$PROMOTIONS_DIR" ]] || fail_reason 'PROD_PROMOTION_RECEIPT'
current_release="$(readlink -f "$CURRENT_RELEASE" 2>/dev/null || true)"
[[ -n "$current_release" && -d "$current_release" ]] \
  || fail_reason 'PROD_CURRENT_RELEASE'

matched=0
actual=''
shopt -s nullglob
for receipt in "$PROMOTIONS_DIR"/*.env; do
  release_path="$(grep -m1 '^release_path=' "$receipt" 2>/dev/null | cut -d= -f2- || true)"
  [[ "$release_path" == "$current_release" ]] || continue
  candidate="$(grep -m1 '^candidate_sha=' "$receipt" 2>/dev/null | cut -d= -f2- || true)"
  [[ "$candidate" =~ ^[0-9a-f]{40}$ ]] \
    || fail_reason 'PROD_PROMOTION_RECEIPT'
  actual="$candidate"
  matched=$((matched + 1))
done
shopt -u nullglob

[[ "$matched" -eq 1 ]] || fail_reason 'PROD_PROMOTION_RECEIPT'
if [[ "$EXPECTED_PROD_RELEASE_SHA" != 'AUTO' ]]; then
  [[ "$actual" == "$EXPECTED_PROD_RELEASE_SHA" ]] \
    || fail_reason 'PROD_PROMOTION_RECEIPT'
fi

# This PLAN script intentionally performs no Drush/DB command and never reads
# database contents. It proves only server-owned release metadata readiness.
printf '%s\n' \
  'prod_release_identity=PASS' \
  "prod_release_sha=$actual" \
  'prod_snapshot_route_metadata=PASS' \
  'prod_db_content_read=NONE' \
  'prod_snapshot=NOT_PERFORMED' \
  'prod_write=NONE'
