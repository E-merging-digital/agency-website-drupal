#!/usr/bin/env bash
set -euo pipefail

: "${AGENCY_CANVAS_1375_CODE_B64:?Missing encoded Canvas drift gate PHP.}"
: "${AGENCY_CANVAS_1373_STATUS_B64:?Missing Canvas drift status metadata.}"
: "${AGENCY_CANVAS_1373_EXECUTE:?Missing Canvas drift execution gate.}"

cd /var/www/html

code="$(printf '%s' "$AGENCY_CANVAS_1375_CODE_B64" | base64 -d)"
unset AGENCY_CANVAS_1375_CODE_B64

exec vendor/bin/drush php:eval "$code"
