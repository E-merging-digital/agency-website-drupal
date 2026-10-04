#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PATH='/usr/sbin:/usr/bin:/sbin:/bin'
LC_ALL=C
export PATH LC_ALL

SERVER_USER="${1:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
HELPER_SOURCE="$SOURCE_DIR/remote-apply-root.sh"
PLAN_SOURCE="$SOURCE_DIR/remote-plan.sh"
RENDER_SUDOERS="$SCRIPT_DIR/render-sudoers.sh"

HELPER_DEST='/usr/local/sbin/agency-prod-php85-1353-apply'
PLAN_DEST='/usr/local/lib/agency-prod-php85-1353/remote-plan.sh'
SUDOERS_DEST='/etc/sudoers.d/agency-prod-php85-1353'

[[ "$#" -eq 1 ]]
[[ "$(id -u)" -eq 0 ]]
[[ "$SERVER_USER" =~ ^[A-Za-z0-9._-]+$ ]]
for path in "$HELPER_SOURCE" "$PLAN_SOURCE" "$RENDER_SUDOERS" "$HELPER_DEST" "$PLAN_DEST" "$SUDOERS_DEST"; do
  [[ -f "$path" && ! -L "$path" ]]
done

[[ "$(stat -c '%U:%G:%a' "$HELPER_DEST")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$PLAN_DEST")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$SUDOERS_DEST")" == 'root:root:440' ]]

work_root="$(mktemp -d)"
cleanup() { rm -rf -- "$work_root"; }
trap cleanup EXIT
rendered="$work_root/agency-prod-php85-1353.sudoers"
bash "$RENDER_SUDOERS" "$SERVER_USER" > "$rendered"

/usr/sbin/visudo -cf "$rendered" >/dev/null
/usr/sbin/visudo -cf "$SUDOERS_DEST" >/dev/null

test "$(sha256sum "$HELPER_SOURCE" | awk '{print $1}')" = "$(sha256sum "$HELPER_DEST" | awk '{print $1}')"
test "$(sha256sum "$PLAN_SOURCE" | awk '{print $1}')" = "$(sha256sum "$PLAN_DEST" | awk '{print $1}')"
test "$(sha256sum "$rendered" | awk '{print $1}')" = "$(sha256sum "$SUDOERS_DEST" | awk '{print $1}')"

printf '%s\n'   'STATUS=PASS'   'CAPABILITY_READY=YES'   'HELPER_METADATA=root:root:755'   'PLAN_METADATA=root:root:755'   'SUDOERS_METADATA=root:root:440'   'SUDOERS_SCOPE=FIXED_HELPER_READY_AND_APPLY_ONLY'   'VISUDO_CF=PASS'   'GENERIC_ROOT_CAPABILITY=NONE'
