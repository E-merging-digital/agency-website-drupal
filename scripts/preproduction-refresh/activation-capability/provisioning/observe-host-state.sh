#!/usr/bin/env bash
set -Eeuo pipefail
export LC_ALL=C

[[ "$#" -eq 0 ]] || { echo 'observer accepts no arguments' >&2; exit 64; }
[[ "$(id -u)" -ne 0 ]] || { echo 'observer must not run as root' >&2; exit 65; }

sha_text() {
  printf '%s' "$1" | sha256sum | awk '{print $1}'
}

emit_path() {
  local key="$1" path="$2" type owner group mode digest_state digest
  if [[ -L "$path" ]]; then
    type='SYMLINK'
  elif [[ -f "$path" ]]; then
    type='REGULAR'
  elif [[ -d "$path" ]]; then
    type='DIRECTORY'
  elif [[ -e "$path" ]]; then
    type='OTHER'
  else
    printf '%s\n' \
      "$key.state=ABSENT" \
      "$key.type=ABSENT" \
      "$key.owner=NONE" \
      "$key.group=NONE" \
      "$key.mode=NONE" \
      "$key.digest_state=ABSENT" \
      "$key.sha256=NONE"
    return 0
  fi

  owner="$(stat -c '%U' -- "$path")"
  group="$(stat -c '%G' -- "$path")"
  mode="$(stat -c '%a' -- "$path")"
  digest_state='NOT_FILE'
  digest='NONE'
  if [[ "$type" == 'REGULAR' ]]; then
    if [[ -r "$path" ]]; then
      digest_state='READABLE'
      digest="$(sha256sum -- "$path" | awk '{print $1}')"
    else
      digest_state='UNREADABLE'
    fi
  fi

  printf '%s\n' \
    "$key.state=PRESENT" \
    "$key.type=$type" \
    "$key.owner=$owner" \
    "$key.group=$group" \
    "$key.mode=$mode" \
    "$key.digest_state=$digest_state" \
    "$key.sha256=$digest"
}

HOSTNAME_VALUE="$(hostname 2>/dev/null || uname -n)"
printf '%s\n' \
  'observer_schema=1' \
  "execution_uid=$(id -u)" \
  "execution_gid=$(id -g)" \
  "execution_user_sha256=$(sha_text "$(id -un)")" \
  "host_identity_sha256=$(sha_text "$HOSTNAME_VALUE")" \
  "sudoers_dir_searchable=$([[ -x /etc/sudoers.d ]] && printf YES || printf NO)" \
  "state_dir_searchable=$([[ ! -e /var/lib/agency-preprod-refresh ]] && printf NOT_PRESENT || { [[ -x /var/lib/agency-preprod-refresh ]] && printf YES || printf NO; })"

emit_path staging_helper '/usr/local/sbin/agency-preprod-staging-db'
emit_path staging_sanitizer '/usr/local/lib/agency-preprod-staging/agency-preprod-staging-sanitizer.py'
emit_path staging_policy '/usr/local/lib/agency-preprod-staging/sanitization-policy.json'
emit_path helper '/usr/local/sbin/agency-preprod-refresh-control'
emit_path bundle_dir '/usr/local/lib/agency-preprod-refresh'
emit_path side_effect_hardening '/usr/local/lib/agency-preprod-refresh/side_effect_hardening.py'
emit_path runtime_state_digest '/usr/local/lib/agency-preprod-refresh/runtime_state_digest.py'
emit_path capability_profile '/usr/local/lib/agency-preprod-refresh/profile.json'
emit_path bundle_manifest '/usr/local/lib/agency-preprod-refresh/bundle.json'
emit_path state_dir '/var/lib/agency-preprod-refresh'
emit_path incoming_dir '/var/lib/agency-preprod-refresh/incoming'
emit_path candidates_dir '/var/lib/agency-preprod-refresh/candidates'
emit_path backups_dir '/var/lib/agency-preprod-refresh/backups'
emit_path authority_state '/var/lib/agency-preprod-refresh/data-activation-authority.json'
emit_path maintenance_marker '/var/lib/agency-preprod-refresh/refresh-maintenance.flag'
emit_path sudoers '/etc/sudoers.d/agency-preprod-refresh-control'
emit_path nginx_snippets_dir '/etc/nginx/snippets'
emit_path nginx_conf_dir '/etc/nginx/conf.d'
emit_path fence_snippet '/etc/nginx/snippets/agency-preprod-refresh-fence.conf'
emit_path internal_readiness '/etc/nginx/conf.d/agency-preprod-refresh-internal-readiness.conf'
emit_path vhost '/etc/nginx/sites-available/agency-preprod'
emit_path deploy_lock '/var/www/agency-preprod/shared/deploy.lock'
emit_path refresh_lock '/run/lock/agency-preprod-refresh.lock'
emit_path current_release '/var/www/agency-preprod/current'
emit_path current_web '/var/www/agency-preprod/current/web'

vhost='/etc/nginx/sites-available/agency-preprod'
if [[ -f "$vhost" && ! -L "$vhost" && -r "$vhost" ]]; then
  printf '%s\n' \
    "vhost_server_name_count=$(grep -Fc 'server_name preprod.emergingdigital.be;' "$vhost" || true)" \
    "vhost_fence_include_count=$(grep -Fc 'include /etc/nginx/snippets/agency-preprod-refresh-fence.conf;' "$vhost" || true)"
else
  printf '%s\n' 'vhost_server_name_count=UNAVAILABLE' 'vhost_fence_include_count=UNAVAILABLE'
fi

current='/var/www/agency-preprod/current'
if [[ -L "$current" ]]; then
  release_target="$(readlink -f -- "$current" || true)"
  release_name="$(basename -- "$release_target")"
  printf '%s\n' \
    "runtime_release_target_sha256=$(sha_text "$release_target")" \
    "runtime_release_name=$release_name"
else
  printf '%s\n' 'runtime_release_target_sha256=NONE' 'runtime_release_name=NONE'
fi

runtime_db_state='UNAVAILABLE'
runtime_db_name='NONE'
if [[ -L "$current" && -x "$current/vendor/bin/drush" ]]; then
  set +e
  runtime_db_name="$( (cd "$current" && "$current/vendor/bin/drush" --quiet status --field=db-name) 2>/dev/null | tr -d '\r\n')"
  db_rc=$?
  set -e
  if [[ "$db_rc" -eq 0 && "$runtime_db_name" =~ ^[A-Za-z0-9_]+$ ]]; then
    runtime_db_state='OBSERVED'
  else
    runtime_db_name='NONE'
  fi
fi
printf '%s\n' "runtime_db_name_state=$runtime_db_state" "runtime_db_name=$runtime_db_name"

printf '%s\n' \
  'PLAN_MUTATION=NONE' \
  'HELPER_EXECUTION=NONE' \
  'SUDO_EXECUTION=NONE' \
  'PROD_ACCESS=NONE' \
  'PREPROD_DB_MUTATION=NONE' \
  'PREPROD_BACKUP=NONE' \
  'FENCE_MUTATION=NONE' \
  'NGINX_MUTATION=NONE'
