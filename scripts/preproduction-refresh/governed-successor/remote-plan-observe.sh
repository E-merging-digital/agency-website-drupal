#!/usr/bin/env bash
set -Eeuo pipefail
export LC_ALL=C
[[ "$#" -eq 0 ]] || { echo 'observer accepts no arguments' >&2; exit 64; }
[[ "$(id -u)" -ne 0 ]] || { echo 'observer must remain unprivileged' >&2; exit 65; }

emit_meta() {
  local key="$1" path="$2" expected_mode="$3" expected_owner="$4"
  if [[ ! -e "$path" || -L "$path" || ! -f "$path" ]]; then
    printf '%s.state=NOT_EXACT\n' "$key"; return
  fi
  local mode owner group
  mode="$(stat -c '%a' "$path")"; owner="$(stat -c '%U' "$path")"; group="$(stat -c '%G' "$path")"
  [[ "$mode" == "$expected_mode" && "$owner:$group" == "$expected_owner" ]] || {
    printf '%s.state=NOT_EXACT\n' "$key"; return
  }
  printf '%s.state=EXACT\n' "$key"
}

emit_meta control /usr/local/sbin/agency-preprod-refresh-control 755 root:root
emit_meta ingress /usr/local/sbin/agency-preprod-refresh-ingress 755 root:root
emit_meta authority_installer /usr/local/sbin/agency-preprod-refresh-authority-install 750 root:root
emit_meta authority_abort /usr/local/sbin/agency-preprod-refresh-authority-abort 750 root:root
emit_meta capability_profile /usr/local/lib/agency-preprod-refresh/profile.json 644 root:root
emit_meta bundle_manifest /usr/local/lib/agency-preprod-refresh/bundle.json 644 root:root

for key in control ingress capability_profile bundle_manifest; do
  case "$key" in
    control) path=/usr/local/sbin/agency-preprod-refresh-control;;
    ingress) path=/usr/local/sbin/agency-preprod-refresh-ingress;;
    capability_profile) path=/usr/local/lib/agency-preprod-refresh/profile.json;;
    bundle_manifest) path=/usr/local/lib/agency-preprod-refresh/bundle.json;;
  esac
  if [[ -r "$path" ]]; then
    printf '%s.sha256=%s\n' "$key" "$(sha256sum "$path" | awk '{print $1}')"
  else
    printf '%s.sha256=UNREADABLE\n' "$key"
  fi
done

persistent=/var/lib/agency-preprod-refresh/data-activation-authority.json
if [[ -e "$persistent" && ! -L "$persistent" && -f "$persistent" ]]; then
  printf 'persistent_authority.meta=%s:%s:%s\n' "$(stat -c '%U' "$persistent")" "$(stat -c '%G' "$persistent")" "$(stat -c '%a' "$persistent")"
else
  printf 'persistent_authority.meta=NOT_EXACT\n'
fi

# active authority/history are root-only by design; PLAN does not claim their
# contents or absence from an unprivileged observation. The fixed root installer
# is the fail-closed APPLY arm gate for collisions/replay.
printf '%s\n' \
  'transaction_authority_observation=UNOBSERVABLE_UNPRIVILEGED' \
  'transaction_authority_arm_gate=ROOT_INSTALLER_FAIL_CLOSED' \
  "fence_marker_visible=$([[ -e /var/lib/agency-preprod-refresh/refresh-maintenance.flag ]] && printf YES || printf NO)" \
  "deploy_lock_present=$([[ -e /var/www/agency-preprod/shared/deploy.lock ]] && printf YES || printf NO)" \
  "refresh_lock_present=$([[ -e /run/lock/agency-preprod-refresh.lock ]] && printf YES || printf NO)" \
  "runner_capacity_probe=NOT_REMOTE" \
  'PLAN_MUTATION=NONE' \
  'SUDO_EXECUTION=NONE' \
  'PREPROD_DB_MUTATION=NONE' \
  'PREPROD_BACKUP=NONE' \
  'FENCE_MUTATION=NONE'
