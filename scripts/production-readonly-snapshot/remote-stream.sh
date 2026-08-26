#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ "$#" -ne 1 ]]; then
  echo 'Expected exactly one immutable PROD release SHA.' >&2
  exit 64
fi

EXPECTED_PROD_RELEASE_SHA="$1"
PROJECT_ROOT='/var/www/agency'
CURRENT_RELEASE="$PROJECT_ROOT/current"
PROMOTIONS_DIR="$PROJECT_ROOT/shared/promotions"

if [[ ! "$EXPECTED_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo 'Expected PROD release SHA is invalid.' >&2
  exit 65
fi

if [[ ! -L "$CURRENT_RELEASE" ]]; then
  echo 'Current PROD release symlink is unavailable.' >&2
  exit 66
fi

if [[ ! -x "$CURRENT_RELEASE/vendor/bin/drush" ]]; then
  echo 'Current PROD release does not contain executable Drush.' >&2
  exit 67
fi

if [[ ! -d "$PROMOTIONS_DIR" ]]; then
  echo 'Production promotion receipts are unavailable.' >&2
  exit 68
fi

current_release="$(readlink -f "$CURRENT_RELEASE")"
if [[ -z "$current_release" || ! -d "$current_release" ]]; then
  echo 'Current PROD release cannot be resolved.' >&2
  exit 69
fi

# Release payloads intentionally exclude .git. Bind runtime identity to the
# durable same-artifact promotion receipt for the resolved current release.
matched_receipts=0
ACTUAL_PROD_RELEASE_SHA=''
shopt -s nullglob
for receipt in "$PROMOTIONS_DIR"/*.env; do
  receipt_release="$(grep -m1 '^release_path=' "$receipt" | cut -d= -f2- || true)"
  [[ "$receipt_release" == "$current_release" ]] || continue
  receipt_sha="$(grep -m1 '^candidate_sha=' "$receipt" | cut -d= -f2- || true)"
  if [[ ! "$receipt_sha" =~ ^[0-9a-f]{40}$ ]]; then
    echo 'Current production receipt has invalid candidate identity.' >&2
    exit 70
  fi
  ACTUAL_PROD_RELEASE_SHA="$receipt_sha"
  matched_receipts=$((matched_receipts + 1))
done
shopt -u nullglob

if [[ "$matched_receipts" -ne 1 ]]; then
  echo 'Current PROD release must map to exactly one promotion receipt.' >&2
  exit 71
fi

if [[ "$ACTUAL_PROD_RELEASE_SHA" != "$EXPECTED_PROD_RELEASE_SHA" ]]; then
  echo 'Current PROD release identity does not match authorization.' >&2
  exit 72
fi

cd "$CURRENT_RELEASE"

# Both commands derive the database connection exclusively from trusted
# server-owned Drupal settings. No connection string, SQL, table, database,
# file path or dump option can be supplied by the request authority.
vendor/bin/drush status --fields=bootstrap --format=string >/dev/null

# Drush sql:dump invokes the database-specific logical dump client. The fixed
# options provide a streaming, non-locking transactional snapshot for the
# transactional tables used by Agency. No remote result file is configured:
# raw SQL is emitted only to stdout and captured directly in trusted RUNNER_TEMP.
exec vendor/bin/drush sql:dump \
  --no-interaction \
  --extra-dump='--single-transaction --quick --skip-lock-tables --no-tablespaces'
