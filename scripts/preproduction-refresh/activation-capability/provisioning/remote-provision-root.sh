#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

[[ "$(id -u)" -eq 0 ]] || { echo 'Root provisioning identity required.' >&2; exit 70; }
[[ "${1:-}" == 'APPLY' ]] || { echo 'Expected APPLY only.' >&2; exit 64; }
REQUEST_ID="${2:-}"
REPOSITORY_SHA="${3:-}"
[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 65; }
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid repository SHA.' >&2; exit 66; }

suffix="$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print substr($1,1,12)}')"
STAGE="/var/tmp/agency-874-${suffix}"
SOURCE="$STAGE/source"
TX="/var/tmp/agency-874-rollback-${suffix}"
MANIFEST="$TX/manifest.tsv"

HELPER='/usr/local/sbin/agency-preprod-refresh-control'
BUNDLE='/usr/local/lib/agency-preprod-refresh'
STATE='/var/lib/agency-preprod-refresh'
INCOMING="$STATE/incoming"
CANDIDATES="$STATE/candidates"
BACKUPS="$STATE/backups"
AUTH="$STATE/data-activation-authority.json"
MARKER="$STATE/refresh-maintenance.flag"
SUDOERS='/etc/sudoers.d/agency-preprod-refresh-control'
FENCE='/etc/nginx/snippets/agency-preprod-refresh-fence.conf'
INTERNAL='/etc/nginx/conf.d/agency-preprod-refresh-internal-readiness.conf'
VHOST='/etc/nginx/sites-available/agency-preprod'
STAGING_HELPER='/usr/local/sbin/agency-preprod-staging-db'
STAGING_SANITIZER='/usr/local/lib/agency-preprod-staging/agency-preprod-staging-sanitizer.py'
STAGING_POLICY='/usr/local/lib/agency-preprod-staging/sanitization-policy.json'

EXPECTED_STAGING_HELPER='a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71'
EXPECTED_SANITIZER='fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f'
EXPECTED_POLICY='cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb'
EXPECTED_CAPABILITY_PROFILE='75180d09b98852bdd0b05a92f397eefaad88d4cab6ae64dc12ea76886b963333'

[[ -d "$SOURCE" && ! -L "$SOURCE" ]] || { echo 'Fixed provisioning stage missing.' >&2; exit 67; }
for f in \
  agency-preprod-refresh-control \
  side_effect_hardening.py \
  runtime_state_digest.py \
  data-activation-authority.disabled.json \
  capability-profile.json \
  provisioning-profile.json \
  bundle.json \
  agency-preprod-refresh-fence.conf \
  agency-preprod-refresh-internal-readiness.conf \
  agency-preprod-refresh-control.sudoers; do
  [[ -f "$SOURCE/$f" && ! -L "$SOURCE/$f" ]] || { echo "Missing staged file: $f" >&2; exit 68; }
done

[[ "$(sha256sum "$STAGING_HELPER" | awk '{print $1}')" == "$EXPECTED_STAGING_HELPER" ]] || { echo 'Existing staging helper digest mismatch.' >&2; exit 69; }
[[ "$(sha256sum "$STAGING_SANITIZER" | awk '{print $1}')" == "$EXPECTED_SANITIZER" ]] || { echo 'Canonical sanitizer digest mismatch.' >&2; exit 69; }
[[ "$(sha256sum "$STAGING_POLICY" | awk '{print $1}')" == "$EXPECTED_POLICY" ]] || { echo 'Canonical policy digest mismatch.' >&2; exit 69; }
[[ "$(sha256sum "$SOURCE/capability-profile.json" | awk '{print $1}')" == "$EXPECTED_CAPABILITY_PROFILE" ]] || { echo 'Capability profile digest mismatch.' >&2; exit 69; }
for pair in \
  "helper:agency-preprod-refresh-control" \
  "side_effect_hardening:side_effect_hardening.py" \
  "runtime_state_digest:runtime_state_digest.py" \
  "disabled_authority_state:data-activation-authority.disabled.json" \
  "fence_snippet:agency-preprod-refresh-fence.conf" \
  "internal_readiness:agency-preprod-refresh-internal-readiness.conf" \
  "capability_profile:capability-profile.json"; do
  key="${pair%%:*}"; file="${pair#*:}"
  expected="$(jq -r --arg key "$key" '.digests[$key]' "$SOURCE/provisioning-profile.json")"
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || { echo "Invalid digest for $key." >&2; exit 69; }
  [[ "$(sha256sum "$SOURCE/$file" | awk '{print $1}')" == "$expected" ]] || { echo "Provisioning bundle digest mismatch: $key" >&2; exit 69; }
done
jq -e '.issue_number == 874 and .profile_id == "agency-preprod-refresh-capability-provision-v1" and .apply.data_activation_authority_after_apply == "DISABLED" and .apply.real_data_activation == "FORBIDDEN"' "$SOURCE/provisioning-profile.json" >/dev/null || { echo 'Provisioning profile mismatch.' >&2; exit 69; }
jq -e '.issue_number == 874 and .data_activation_authority_after_provisioning == "DISABLED"' "$SOURCE/bundle.json" >/dev/null || { echo 'Bundle manifest mismatch.' >&2; exit 69; }

if [[ -e "$AUTH" || -L "$AUTH" ]]; then
  [[ -f "$AUTH" && ! -L "$AUTH" ]] || { echo 'Authority state type invalid.' >&2; exit 71; }
  cmp -s "$AUTH" "$SOURCE/data-activation-authority.disabled.json" || { echo 'Existing authority is not the exact disabled state; refusing overwrite.' >&2; exit 72; }
fi
[[ ! -e "$MARKER" && ! -L "$MARKER" ]] || { echo 'Fence marker already present; provisioning refuses active/ambiguous refresh state.' >&2; exit 73; }
[[ -f "$VHOST" && ! -L "$VHOST" ]] || { echo 'Canonical PREPROD vhost missing or unsafe.' >&2; exit 74; }
[[ "$(grep -Fc 'server_name preprod.emergingdigital.be;' "$VHOST")" -eq 1 ]] || { echo 'Canonical vhost integration point is ambiguous.' >&2; exit 75; }
[[ "$(grep -Fc 'include /etc/nginx/snippets/agency-preprod-refresh-fence.conf;' "$VHOST" || true)" -le 1 ]] || { echo 'Fence include is duplicated.' >&2; exit 76; }

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
  local rc="$?"
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
for target in "$HELPER" "$BUNDLE" "$STATE" "$SUDOERS" "$FENCE" "$INTERNAL" "$VHOST"; do record_path "$target"; done
mutated=1
install -m 0440 -o root -g root "$SOURCE/agency-preprod-refresh-control.sudoers" "$TX/sudoers.candidate"
visudo -cf "$TX/sudoers.candidate" >/dev/null
! grep -Eq 'NOPASSWD:[[:space:]]*ALL|(^|[[:space:]])SETENV:|[[:space:]](bash|sh|python|python3|mariadb)([[:space:]]|$)' "$TX/sudoers.candidate"
grep -Fxq 'agency-preprod ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-preprod-refresh-control' "$TX/sudoers.candidate"
install -d -m 0755 -o root -g root "$BUNDLE"
install -m 0755 -o root -g root "$SOURCE/agency-preprod-refresh-control" "$HELPER"
install -m 0644 -o root -g root "$SOURCE/side_effect_hardening.py" "$BUNDLE/side_effect_hardening.py"
install -m 0644 -o root -g root "$SOURCE/runtime_state_digest.py" "$BUNDLE/runtime_state_digest.py"
install -m 0644 -o root -g root "$SOURCE/capability-profile.json" "$BUNDLE/profile.json"
install -m 0644 -o root -g root "$SOURCE/bundle.json" "$BUNDLE/bundle.json"
install -d -m 0711 -o root -g root "$STATE"
install -d -m 0700 -o root -g root "$INCOMING" "$CANDIDATES" "$BACKUPS"
if [[ ! -e "$AUTH" ]]; then install -m 0600 -o root -g root "$SOURCE/data-activation-authority.disabled.json" "$AUTH"; fi
install -m 0440 -o root -g root "$TX/sudoers.candidate" "$SUDOERS"
install -d -m 0755 -o root -g root /etc/nginx/snippets /etc/nginx/conf.d
install -m 0644 -o root -g root "$SOURCE/agency-preprod-refresh-fence.conf" "$FENCE"
install -m 0644 -o root -g root "$SOURCE/agency-preprod-refresh-internal-readiness.conf" "$INTERNAL"
if ! grep -Fq 'include /etc/nginx/snippets/agency-preprod-refresh-fence.conf;' "$VHOST"; then
  python3 -I - "$VHOST" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
needle = '    server_name preprod.emergingdigital.be;\n'
if text.count(needle) != 1:
    raise SystemExit(80)
path.write_text(text.replace(needle, needle + '\n    include /etc/nginx/snippets/agency-preprod-refresh-fence.conf;\n', 1))
PY
fi
chown root:root "$VHOST"
chmod 0644 "$VHOST"
nginx -t >/dev/null
systemctl reload nginx
[[ "$(stat -c '%U:%G:%a' "$HELPER")" == 'root:root:755' ]]
[[ "$(stat -c '%U:%G:%a' "$STATE")" == 'root:root:711' ]]
[[ "$(stat -c '%U:%G:%a' "$INCOMING")" == 'root:root:700' ]]
[[ "$(stat -c '%U:%G:%a' "$CANDIDATES")" == 'root:root:700' ]]
[[ "$(stat -c '%U:%G:%a' "$BACKUPS")" == 'root:root:700' ]]
[[ "$(stat -c '%U:%G:%a' "$AUTH")" == 'root:root:600' ]]
cmp -s "$AUTH" "$SOURCE/data-activation-authority.disabled.json"
[[ ! -e "$MARKER" && ! -L "$MARKER" ]]
grep -Fq 'listen 127.0.0.1:18087;' "$INTERNAL"
grep -Fq 'location = /health/ready' "$INTERNAL"
grep -Fq 'include /etc/nginx/snippets/agency-preprod-refresh-fence.conf;' "$VHOST"
visudo -cf "$SUDOERS" >/dev/null
nginx -t >/dev/null
mutated=0
rm -rf -- "$TX" "$STAGE"
printf '%s\n' "request_id_sha256=$(printf '%s' "$REQUEST_ID" | sha256sum | awk '{print $1}')" "repository_sha=$REPOSITORY_SHA" 'capability_provisioning=PASS' 'data_activation_authority=DISABLED' 'real_data_activation=FORBIDDEN' 'canonical_sanitizer=REUSED' 'public_fence=INSTALLED_OPEN' 'internal_readiness=LOOPBACK_ONLY' 'prod_access=NONE'
