#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SERVER_USER="${1:-}"
OUTPUT_DIR="${2:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
HELPER_SOURCE="$SOURCE_ROOT/remote-apply-root.sh"
PLAN_SOURCE="$SOURCE_ROOT/remote-plan.sh"
RENDER="$SCRIPT_DIR/render-sudoers.sh"

[[ "$#" -eq 2 ]]
[[ "$SERVER_USER" =~ ^[A-Za-z0-9._-]+$ ]]
[[ "$OUTPUT_DIR" == /* ]]
[[ ! -e "$OUTPUT_DIR" && ! -L "$OUTPUT_DIR" ]]
[[ -f "$HELPER_SOURCE" && ! -L "$HELPER_SOURCE" ]]
[[ -f "$PLAN_SOURCE" && ! -L "$PLAN_SOURCE" ]]
[[ -x /usr/sbin/visudo ]]

install -d -m 0700 "$OUTPUT_DIR"
cp -- "$HELPER_SOURCE" "$OUTPUT_DIR/agency-prod-php85-1353-apply"
cp -- "$PLAN_SOURCE" "$OUTPUT_DIR/remote-plan.sh"
"$RENDER" "$SERVER_USER" > "$OUTPUT_DIR/agency-prod-php85-1353.sudoers"
chmod 0755 "$OUTPUT_DIR/agency-prod-php85-1353-apply" "$OUTPUT_DIR/remote-plan.sh"
chmod 0440 "$OUTPUT_DIR/agency-prod-php85-1353.sudoers"
/usr/sbin/visudo -cf "$OUTPUT_DIR/agency-prod-php85-1353.sudoers" >/dev/null

printf '%s\n' \
  'STATUS=PASS' \
  'BOUNDARY=HUMAN_ADMIN_BOOTSTRAP' \
  "HELPER_SOURCE_SHA256=$(sha256sum "$OUTPUT_DIR/agency-prod-php85-1353-apply" | awk '{print $1}')" \
  'HELPER_DESTINATION=/usr/local/sbin/agency-prod-php85-1353-apply' \
  'HELPER_METADATA=root:root:0755' \
  "PLAN_SCRIPT_SOURCE_SHA256=$(sha256sum "$OUTPUT_DIR/remote-plan.sh" | awk '{print $1}')" \
  'PLAN_SCRIPT_DESTINATION=/usr/local/lib/agency-prod-php85-1353/remote-plan.sh' \
  'PLAN_SCRIPT_METADATA=root:root:0755' \
  "RENDERED_SUDOERS_SHA256=$(sha256sum "$OUTPUT_DIR/agency-prod-php85-1353.sudoers" | awk '{print $1}')" \
  'SUDOERS_DESTINATION=/etc/sudoers.d/agency-prod-php85-1353' \
  'SUDOERS_METADATA=root:root:0440' \
  'VISUDO_PRE_INSTALL=PASS' \
  'GENERIC_ROOT_CAPABILITY=NONE'
