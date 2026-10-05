#!/usr/bin/env bash
set -euo pipefail

if [[ "$#" -ne 1 ]] || [[ ! "$1" =~ ^[0-9a-f]{40}$ ]]; then
  echo 'Expected exactly one immutable 40-character PROD release SHA.' >&2
  exit 64
fi

EXPECTED_PROD_RELEASE_SHA="$1"
PROJECT_ROOT='/var/www/agency'
CURRENT_RELEASE="$PROJECT_ROOT/current"
PROMOTIONS_DIR="$PROJECT_ROOT/shared/promotions"

current_release="$(readlink -f "$CURRENT_RELEASE")"
[[ -n "$current_release" && -d "$current_release" ]] || {
  echo 'Current PROD release cannot be resolved.' >&2
  exit 65
}
[[ -d "$PROMOTIONS_DIR" ]] || {
  echo 'Production promotion receipt directory is missing.' >&2
  exit 66
}

matched_receipts=0
actual_prod_release_sha=''
shopt -s nullglob
for receipt in "$PROMOTIONS_DIR"/*.env; do
  receipt_release_path="$(grep -m1 '^release_path=' "$receipt" | cut -d= -f2- || true)"
  [[ "$receipt_release_path" == "$current_release" ]] || continue
  matched_receipts=$((matched_receipts + 1))
  receipt_candidate_sha="$(grep -m1 '^candidate_sha=' "$receipt" | cut -d= -f2- || true)"
  [[ "$receipt_candidate_sha" =~ ^[0-9a-f]{40}$ ]] || {
    echo 'Matching production promotion receipt has an invalid candidate SHA.' >&2
    exit 67
  }
  actual_prod_release_sha="$receipt_candidate_sha"
done
shopt -u nullglob

[[ "$matched_receipts" -eq 1 ]] || {
  echo 'Current PROD release must map to exactly one promotion receipt.' >&2
  exit 68
}
[[ "$actual_prod_release_sha" == "$EXPECTED_PROD_RELEASE_SHA" ]] || {
  echo 'Current PROD release identity does not match the expected rebaseline SHA.' >&2
  exit 69
}

printf 'ACTIVE_RELEASE_PATH=RESOLVED\n'
printf 'PROMOTION_RECEIPT_MATCH_COUNT=1\n'
printf 'ACTIVE_PROD_RELEASE_SHA=%s\n' "$actual_prod_release_sha"
printf 'ACTIVE_PROD_RELEASE_MATCH=PASS\n'
printf 'PROD_DB_READ=NONE\n'
printf 'PROD_WRITE=NONE\n'
printf 'PROD_SCHEDULER_MUTATION=NONE\n'
printf 'PREPROD_MUTATION=NONE\n'
