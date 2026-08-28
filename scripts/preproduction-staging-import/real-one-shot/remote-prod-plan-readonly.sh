#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

EXPECTED_PROD_RELEASE_SHA="${1:-}"
[[ "$#" -eq 1 ]] || { echo 'Expected exactly one PROD release selector.' >&2; exit 64; }
[[ "$EXPECTED_PROD_RELEASE_SHA" == 'AUTO' || "$EXPECTED_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || {
  echo 'Invalid expected PROD release identity.' >&2
  exit 65
}

PROJECT_ROOT='/var/www/agency'
CURRENT_RELEASE="$PROJECT_ROOT/current"
PROMOTIONS_DIR="$PROJECT_ROOT/shared/promotions"

[[ -L "$CURRENT_RELEASE" ]] || { echo 'Current PROD release symlink is unavailable.' >&2; exit 66; }
[[ -d "$PROMOTIONS_DIR" ]] || { echo 'Production promotion receipts are unavailable.' >&2; exit 67; }
current_release="$(readlink -f "$CURRENT_RELEASE")"
[[ -n "$current_release" && -d "$current_release" ]] || { echo 'Current PROD release cannot be resolved.' >&2; exit 68; }

matched=0
actual=''
shopt -s nullglob
for receipt in "$PROMOTIONS_DIR"/*.env; do
  release_path="$(grep -m1 '^release_path=' "$receipt" | cut -d= -f2- || true)"
  [[ "$release_path" == "$current_release" ]] || continue
  candidate="$(grep -m1 '^candidate_sha=' "$receipt" | cut -d= -f2- || true)"
  [[ "$candidate" =~ ^[0-9a-f]{40}$ ]] || { echo 'Current PROD receipt identity is invalid.' >&2; exit 69; }
  actual="$candidate"
  matched=$((matched + 1))
done
shopt -u nullglob

[[ "$matched" -eq 1 ]] || { echo 'Current PROD release must map to exactly one promotion receipt.' >&2; exit 70; }
if [[ "$EXPECTED_PROD_RELEASE_SHA" != 'AUTO' ]]; then
  [[ "$actual" == "$EXPECTED_PROD_RELEASE_SHA" ]] || { echo 'Current PROD release identity does not match authority.' >&2; exit 71; }
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
