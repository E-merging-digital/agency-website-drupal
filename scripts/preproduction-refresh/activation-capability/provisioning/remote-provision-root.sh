#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

[[ "$(id -u)" -eq 0 ]] || { echo 'Root provisioning identity required.' >&2; exit 70; }
[[ "${1:-}" == 'APPLY' ]] || { echo 'Expected APPLY only.' >&2; exit 64; }
REQUEST_ID="${2:-}"
REPOSITORY_SHA="${3:-}"
EXPECTED_PROFILE_SHA256="${4:-}"
[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 65; }
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid repository SHA.' >&2; exit 66; }
[[ "$EXPECTED_PROFILE_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo 'Invalid provisioning profile digest.' >&2; exit 66; }

suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
STAGE="/var/tmp/agency-915-${suffix}"
SOURCE="$STAGE/source"
TX="/var/tmp/agency-915-provision-rollback-${suffix}"
MANIFEST="$TX/manifest.tsv"

HELPER='/usr/local/sbin/agency-preprod-refresh-control'
INGRESS='/usr/local/sbin/agency-preprod-refresh-ingress'
AUTH_INSTALLER='/usr/local/sbin/agency-preprod-refresh-authority-install'
AUTH_ABORT='/usr/local/sbin/agency-preprod-refresh-authority-abort'
BUNDLE='/usr/local/lib/agency-preprod-refresh'
STATE='/var/lib/agency-preprod-refresh'
AUTHORITY_DIR="$STATE/authority"
TRANSACTIONS_DIR="$STATE/transactions"
RECOVERY_DIR="$STATE/recovery"
INCOMING="$STATE/incoming"
CANDIDATES="$STATE/candidates"
BACKUPS="$STATE/backups"
DISABLED_AUTH="$STATE/data-activation-authority.json"
ACTIVE_AUTH="$AUTHORITY_DIR/active.json"
MARKER="$STATE/refresh-maintenance.flag"
CONTROL_SUDOERS='/etc/sudoers.d/agency-preprod-refresh-control'
INGRESS_SUDOERS='/etc/sudoers.d/agency-preprod-refresh-ingress'
FENCE='/etc/nginx/snippets/agency-preprod-refresh-fence.conf'
INTERNAL='/etc/nginx/conf.d/agency-preprod-refresh-internal-readiness.conf'
VHOST='/etc/nginx/sites-available/agency-preprod'
STAGING_HELPER='/usr/local/sbin/agency-preprod-staging-db'
STAGING_SANITIZER='/usr/local/lib/agency-preprod-staging/agency-preprod-staging-sanitizer.py'
STAGING_POLICY='/usr/local/lib/agency-preprod-staging/sanitization-policy.json'

EXPECTED_STAGING_HELPER='a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71'
EXPECTED_SANITIZER='fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f'
EXPECTED_POLICY='cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb'
EXPECTED_VHOST_SELECTOR_BLOB='a17e3f932b9a5e7ec4978f3758ff0bf5bbae9c79'

[[ -d "$SOURCE" && ! -L "$SOURCE" ]] || { echo 'Fixed provisioning stage missing.' >&2; exit 67; }
required=(
  agency-preprod-refresh-control
  agency-preprod-refresh-ingress
  agency-preprod-refresh-authority-install
  agency-preprod-refresh-authority-abort
  transaction_contract.py
  admin-reconcile.sh
  admin-reconcile.php
  side_effect_hardening.py
  runtime_state_digest.py
  data-activation-authority.disabled.json
  capability-profile.json
  provisioning-profile.json
  bundle.json
  agency-preprod-refresh-fence.conf
  agency-preprod-refresh-internal-readiness.conf
  agency-preprod-refresh-control.sudoers
  agency-preprod-refresh-ingress.sudoers
  nginx-vhost-selector.py
)
for f in "${required[@]}"; do
  [[ -f "$SOURCE/$f" && ! -L "$SOURCE/$f" ]] || { echo "Missing staged file: $f" >&2; exit 68; }
done

[[ "$(sha256sum "$SOURCE/provisioning-profile.json" | awk '{print $1}')" == "$EXPECTED_PROFILE_SHA256" ]] || {
  echo 'Provisioning profile transport digest mismatch.' >&2; exit 69;
}
[[ "$(sha256sum "$STAGING_HELPER" | awk '{print $1}')" == "$EXPECTED_STAGING_HELPER" ]] || { echo 'Existing staging helper digest mismatch.' >&2; exit 69; }
[[ "$(sha256sum "$STAGING_SANITIZER" | awk '{print $1}')" == "$EXPECTED_SANITIZER" ]] || { echo 'Canonical sanitizer digest mismatch.' >&2; exit 69; }
[[ "$(sha256sum "$STAGING_POLICY" | awk '{print $1}')" == "$EXPECTED_POLICY" ]] || { echo 'Canonical policy digest mismatch.' >&2; exit 69; }
[[ "$(git hash-object "$SOURCE/nginx-vhost-selector.py")" == "$EXPECTED_VHOST_SELECTOR_BLOB" ]] || { echo 'Vhost selector Git blob mismatch.' >&2; exit 69; }

jq -e '
  .issue_number == 874 and .revision_issue == 915 and .abort_revision_issue == 917 and
  .profile_id == "agency-preprod-refresh-capability-provision-v1" and
  .apply.persistent_data_activation_authority_after_apply == "DISABLED" and
  .apply.transaction_authority_after_apply == "ABSENT" and
  .apply.abort_helper_after_apply == "INSTALLED_ROOT_ONLY" and
  .apply.real_data_activation == "FORBIDDEN" and
  .sudo.authority_installer_exposed == false and
  .sudo.abort_helper_exposed == false
' "$SOURCE/provisioning-profile.json" >/dev/null || { echo 'Provisioning profile mismatch.' >&2; exit 69; }

jq -e '
  .issue_number == 874 and .revision_issue == 915 and .abort_revision_issue == 917 and
  .persistent_data_activation_authority_after_provisioning == "DISABLED" and
  .transaction_authority_after_provisioning == "ABSENT" and
  .pre_ingress_abort_after_provisioning == "INSTALLED_ROOT_ONLY" and
  .normal_sudo_exposure_for_abort == "NONE"
' "$SOURCE/bundle.json" >/dev/null || { echo 'Bundle manifest mismatch.' >&2; exit 69; }

check_digest() {
  local key="$1" file="$2" expected
  expected="$(jq -r --arg key "$key" '.digests[$key]' "$SOURCE/provisioning-profile.json")"
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || return 1
  [[ "$(sha256sum "$SOURCE/$file" | awk '{print $1}')" == "$expected" ]]
}
check_digest helper agency-preprod-refresh-control
check_digest ingress agency-preprod-refresh-ingress
check_digest authority_installer agency-preprod-refresh-authority-install
check_digest authority_abort agency-preprod-refresh-authority-abort
check_digest transaction_contract transaction_contract.py
check_digest admin_reconcile admin-reconcile.sh
check_digest admin_reconcile_php admin-reconcile.php
check_digest side_effect_hardening side_effect_hardening.py
check_digest runtime_state_digest runtime_state_digest.py
check_digest disabled_authority_state data-activation-authority.disabled.json
check_digest control_sudoers agency-preprod-refresh-control.sudoers
check_digest ingress_sudoers agency-preprod-refresh-ingress.sudoers
check_digest fence_snippet agency-preprod-refresh-fence.conf
check_digest internal_readiness agency-preprod-refresh-internal-readiness.conf
check_digest capability_profile capability-profile.json
check_digest bundle_manifest bundle.json

[[ ! -e "$MARKER" && ! -L "$MARKER" ]] || { echo 'Refresh fence already closed; provisioning refused.' >&2; exit 73; }
[[ ! -e "$ACTIVE_AUTH" && ! -L "$ACTIVE_AUTH" ]] || { echo 'Active transaction authority exists; provisioning refused.' >&2; exit 73; }
if [[ -e "$DISABLED_AUTH" || -L "$DISABLED_AUTH" ]]; then
  [[ -f "$DISABLED_AUTH" && ! -L "$DISABLED_AUTH" ]] || { echo 'Persistent authority state type invalid.' >&2; exit 71; }
  cmp -s "$DISABLED_AUTH" "$SOURCE/data-activation-authority.disabled.json" || {
    echo 'Persistent authority is not exact DISABLED state.' >&2; exit 72;
  }
fi
[[ -f "$VHOST" && ! -L "$VHOST" ]] || { echo 'Canonical PREPROD vhost missing or unsafe.' >&2; exit 74; }
python3 -I "$SOURCE/nginx-vhost-selector.py" OBSERVE >/dev/null || {
  echo 'Canonical vhost selector rejected current topology.' >&2; exit 75;
}

rm -rf -- "$TX"
install -d -m 700 -o root -g root "$TX/backups"
: > "$MANIFEST"
chmod 600 "$MANIFEST"
record_path() {
  local path="$1" key
  key="$(printf '%s' "$path" | sha256sum | awk '{print $1}')"
  if [[ -e "$path" || -L "$path" ]]; then
    [[ ! -L "$path" ]] || return 90
    cp -a -- "$path" "$TX/backups/$key"
    printf 'PRESENT\t%s\t%s\n' "$key" "$path" >> "$MANIFEST"
  else
    printf 'ABSENT\t%s\t%s\n' "$key" "$path" >> "$MANIFEST"
  fi
}
restore_prestate() {
  local status=0 state key path backup
  while IFS=$'\t' read -r state key path; do
    [[ -n "$path" ]] || continue
    backup="$TX/backups/$key"
    if [[ "$state" == PRESENT ]]; then
      rm -rf -- "$path" || return 91
      cp -a -- "$backup" "$path" || return 92
    else
      rm -rf -- "$path" || return 93
    fi
  done < <(tac "$MANIFEST")
  nginx -t >/dev/null 2>&1 || status=94
  systemctl reload nginx >/dev/null 2>&1 || status=95
  return "$status"
}
mutated=0
on_exit() {
  local rc="$?" restore_rc=0
  trap - EXIT HUP INT TERM
  if [[ "$rc" -ne 0 && "$mutated" -eq 1 ]]; then
    set +e
    restore_prestate
    restore_rc="$?"
    set -e
    if [[ "$restore_rc" -ne 0 ]]; then
      printf '%s\n' 'PROVISIONING_ROLLBACK=FAILED' 'HUMAN_RECOVERY_REQUIRED=true' >&2
      exit 99
    fi
    printf '%s\n' 'PROVISIONING_ROLLBACK=PASS' 'HUMAN_RECOVERY_REQUIRED=false' >&2
  fi
  rm -rf -- "$TX" "$STAGE"
  exit "$rc"
}
trap on_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

for target in "$HELPER" "$INGRESS" "$AUTH_INSTALLER" "$AUTH_ABORT" "$BUNDLE" \
  "$CONTROL_SUDOERS" "$INGRESS_SUDOERS" "$FENCE" "$INTERNAL" "$VHOST"; do
  record_path "$target"
done
mutated=1

install -m 0440 -o root -g root "$SOURCE/agency-preprod-refresh-control.sudoers" "$TX/control.sudoers"
install -m 0440 -o root -g root "$SOURCE/agency-preprod-refresh-ingress.sudoers" "$TX/ingress.sudoers"
visudo -cf "$TX/control.sudoers" >/dev/null
visudo -cf "$TX/ingress.sudoers" >/dev/null
for candidate in "$TX/control.sudoers" "$TX/ingress.sudoers"; do
  ! grep -Eq 'NOPASSWD:[[:space:]]*ALL|(^|[[:space:]])SETENV:|[[:space:]](bash|sh|python|python3|mariadb|env)([[:space:]]|$)' "$candidate"
done
grep -Fxq 'agency-preprod ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-preprod-refresh-control' "$TX/control.sudoers"
grep -Fxq 'agency-preprod ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-preprod-refresh-ingress' "$TX/ingress.sudoers"
! grep -REq 'agency-preprod-refresh-authority-(install|abort)' "$TX/control.sudoers" "$TX/ingress.sudoers"

install -d -m 0755 -o root -g root "$BUNDLE"
install -m 0755 -o root -g root "$SOURCE/agency-preprod-refresh-control" "$HELPER"
install -m 0755 -o root -g root "$SOURCE/agency-preprod-refresh-ingress" "$INGRESS"
install -m 0750 -o root -g root "$SOURCE/agency-preprod-refresh-authority-install" "$AUTH_INSTALLER"
install -m 0750 -o root -g root "$SOURCE/agency-preprod-refresh-authority-abort" "$AUTH_ABORT"
install -m 0644 -o root -g root "$SOURCE/transaction_contract.py" "$BUNDLE/transaction_contract.py"
install -m 0755 -o root -g root "$SOURCE/admin-reconcile.sh" "$BUNDLE/admin-reconcile.sh"
install -m 0644 -o root -g root "$SOURCE/admin-reconcile.php" "$BUNDLE/admin-reconcile.php"
install -m 0644 -o root -g root "$SOURCE/side_effect_hardening.py" "$BUNDLE/side_effect_hardening.py"
install -m 0644 -o root -g root "$SOURCE/runtime_state_digest.py" "$BUNDLE/runtime_state_digest.py"
install -m 0644 -o root -g root "$SOURCE/capability-profile.json" "$BUNDLE/profile.json"
install -m 0644 -o root -g root "$SOURCE/bundle.json" "$BUNDLE/bundle.json"

install -d -m 0711 -o root -g root "$STATE"
for dir in "$AUTHORITY_DIR" "$TRANSACTIONS_DIR" "$RECOVERY_DIR" "$INCOMING" "$CANDIDATES" "$BACKUPS"; do
  install -d -m 0700 -o root -g root "$dir"
done
if [[ ! -e "$DISABLED_AUTH" ]]; then
  install -m 0600 -o root -g root "$SOURCE/data-activation-authority.disabled.json" "$DISABLED_AUTH"
fi
[[ ! -e "$ACTIVE_AUTH" && ! -L "$ACTIVE_AUTH" ]]
cmp -s "$DISABLED_AUTH" "$SOURCE/data-activation-authority.disabled.json"

install -m 0440 -o root -g root "$TX/control.sudoers" "$CONTROL_SUDOERS"
install -m 0440 -o root -g root "$TX/ingress.sudoers" "$INGRESS_SUDOERS"
install -d -m 0755 -o root -g root /etc/nginx/snippets /etc/nginx/conf.d
install -m 0644 -o root -g root "$SOURCE/agency-preprod-refresh-fence.conf" "$FENCE"
install -m 0644 -o root -g root "$SOURCE/agency-preprod-refresh-internal-readiness.conf" "$INTERNAL"
python3 -I "$SOURCE/nginx-vhost-selector.py" APPLY_FENCE >/dev/null
chown root:root "$VHOST"
chmod 0644 "$VHOST"
nginx -t >/dev/null
systemctl reload nginx

[[ "$(stat -c '%U:%G:%a' "$HELPER")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$INGRESS")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$AUTH_INSTALLER")" == 'root:root:750' ]]
[[ "$(stat -c '%U:%G:%a' "$AUTH_ABORT")" == 'root:root:750' ]]
[[ "$(stat -c '%U:%G:%a' "$STATE")" == 'root:root:711' ]]
for dir in "$AUTHORITY_DIR" "$TRANSACTIONS_DIR" "$RECOVERY_DIR" "$INCOMING" "$CANDIDATES" "$BACKUPS"; do
  [[ "$(stat -c '%U:%G:%a' "$dir")" == 'root:root:700' ]]
done
[[ "$(stat -c '%U:%G:%a' "$DISABLED_AUTH")" == 'root:root:600' ]]
visudo -cf "$CONTROL_SUDOERS" >/dev/null
visudo -cf "$INGRESS_SUDOERS" >/dev/null
! grep -REq 'agency-preprod-refresh-authority-(install|abort)' "$CONTROL_SUDOERS" "$INGRESS_SUDOERS"
nginx -t >/dev/null

mutated=0
rm -rf -- "$TX" "$STAGE"
printf '%s\n' \
  "request_id_sha256=$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print $1}')" \
  "repository_sha=$REPOSITORY_SHA" \
  'CAPABILITY_PROVISIONING=PASS' \
  'BASE_REVISION_ISSUE=915' \
  'ABORT_REVISION_ISSUE=917' \
  'PERSISTENT_DATA_ACTIVATION_AUTHORITY=DISABLED' \
  'TRANSACTION_AUTHORITY=ABSENT' \
  'ABORT_HELPER=INSTALLED_ROOT_ONLY' \
  'NORMAL_SUDO_EXPOSURE=NONE' \
  'REAL_DATA_ACTIVATION=FORBIDDEN' \
  'PROD_ACCESS=NONE'
