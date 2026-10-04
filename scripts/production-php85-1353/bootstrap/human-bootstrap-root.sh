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
LIB_DIR='/usr/local/lib/agency-prod-php85-1353'
PLAN_DEST="$LIB_DIR/remote-plan.sh"
SUDOERS_DEST='/etc/sudoers.d/agency-prod-php85-1353'

[[ "$#" -eq 1 ]]
[[ "$(id -u)" -eq 0 ]]
[[ "$SERVER_USER" =~ ^[A-Za-z0-9._-]+$ ]]
for source in "$HELPER_SOURCE" "$PLAN_SOURCE" "$RENDER_SUDOERS"; do
  [[ -f "$source" && ! -L "$source" ]]
done

work_root="$(mktemp -d)"
cleanup() { rm -rf -- "$work_root"; }
trap cleanup EXIT

rendered="$work_root/agency-prod-php85-1353.sudoers"
bash "$RENDER_SUDOERS" "$SERVER_USER" > "$rendered"
chmod 0600 "$rendered"

/usr/sbin/visudo -cf "$rendered" >/dev/null
VISUDO_PRE_INSTALL='PASS'

helper_source_sha="$(sha256sum "$HELPER_SOURCE" | awk '{print $1}')"
plan_source_sha="$(sha256sum "$PLAN_SOURCE" | awk '{print $1}')"
sudoers_source_sha="$(sha256sum "$rendered" | awk '{print $1}')"
for digest in "$helper_source_sha" "$plan_source_sha" "$sudoers_source_sha"; do
  [[ "$digest" =~ ^[0-9a-f]{64}$ ]]
done

/usr/bin/install -d -o root -g root -m 0755 -- "$LIB_DIR"
/usr/bin/install -o root -g root -m 0755 -- "$HELPER_SOURCE" "$HELPER_DEST"
/usr/bin/install -o root -g root -m 0755 -- "$PLAN_SOURCE" "$PLAN_DEST"
/usr/bin/install -o root -g root -m 0440 -- "$rendered" "$SUDOERS_DEST"

/usr/sbin/visudo -cf "$SUDOERS_DEST" >/dev/null
VISUDO_POST_INSTALL='PASS'

helper_installed_sha="$(sha256sum "$HELPER_DEST" | awk '{print $1}')"
plan_installed_sha="$(sha256sum "$PLAN_DEST" | awk '{print $1}')"
sudoers_installed_sha="$(sha256sum "$SUDOERS_DEST" | awk '{print $1}')"

[[ "$helper_installed_sha" == "$helper_source_sha" ]]
[[ "$plan_installed_sha" == "$plan_source_sha" ]]
[[ "$sudoers_installed_sha" == "$sudoers_source_sha" ]]
[[ "$(stat -c '%U:%G:%a' "$HELPER_DEST")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$PLAN_DEST")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$SUDOERS_DEST")" == 'root:root:440' ]]

printf '%s\n'   'STATUS=PASS'   'ISSUE=1353'   'BOUNDARY=HUMAN_ADMIN_INTERACTIVE'   "HELPER_SOURCE_SHA256=$helper_source_sha"   "HELPER_INSTALLED_SHA256=$helper_installed_sha"   'HELPER_METADATA=root:root:755'   "PLAN_SOURCE_SHA256=$plan_source_sha"   "PLAN_INSTALLED_SHA256=$plan_installed_sha"   'PLAN_METADATA=root:root:755'   "SUDOERS_SOURCE_SHA256=$sudoers_source_sha"   "SUDOERS_INSTALLED_SHA256=$sudoers_installed_sha"   'SUDOERS_METADATA=root:root:440'   "VISUDO_PRE_INSTALL=$VISUDO_PRE_INSTALL"   "VISUDO_POST_INSTALL=$VISUDO_POST_INSTALL"   'GENERIC_ROOT_CAPABILITY=NONE'   'PROD_PHP_MUTATION=NONE'
