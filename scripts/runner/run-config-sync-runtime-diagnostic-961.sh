#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ISSUE_NUMBER="${ISSUE_NUMBER:-}"
PREPROD_SERVER_HOST="${PREPROD_SERVER_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/config-sync-runtime-diagnostic}"

[[ "$ISSUE_NUMBER" == '961' ]] || {
  echo 'This diagnostic is bound to issue #961.' >&2
  exit 1
}
[[ -n "$PREPROD_SERVER_HOST" ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" ]]

PROJECT_ROOT='/var/www/agency-preprod'
CURRENT_LINK="$PROJECT_ROOT/current"
EXPECTED_SETTINGS="$PROJECT_ROOT/shared/settings/settings.php"
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_VERIFY='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'

mkdir -p "$ARTIFACT_DIR"

PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" bash "$PREPROD_TRUST_PROVISION" PROVISION >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" \
  PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash "$PREPROD_TRUST_VERIFY" >/dev/null

ssh_common=(
  -i "$PREPROD_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15
)
remote_target="agency-preprod@$PREPROD_SERVER_HOST"

current_target="$(ssh "${ssh_common[@]}" "$remote_target" \
  'set -euo pipefail; readlink -f /var/www/agency-preprod/current')"
[[ "$current_target" =~ ^/var/www/agency-preprod/releases/[A-Za-z0-9._-]+$ ]]
current_release="$(basename "$current_target")"

settings_target="$(ssh "${ssh_common[@]}" "$remote_target" \
  'set -euo pipefail; readlink -f /var/www/agency-preprod/current/web/sites/default/settings.php')"
[[ "$settings_target" == "$EXPECTED_SETTINGS" ]]

settings_sha256="$(ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; sha256sum '$EXPECTED_SETTINGS' | awk '{print \\$1}'")"
[[ "$settings_sha256" =~ ^[0-9a-f]{64}$ ]]

bootstrap_raw=''
set +e
bootstrap_raw="$(ssh "${ssh_common[@]}" "$remote_target" \
  'set -euo pipefail; cd /var/www/agency-preprod/current; vendor/bin/drush status --field=bootstrap 2>/dev/null')"
bootstrap_rc="$?"
set -e
if [[ "$bootstrap_rc" -eq 0 && "$bootstrap_raw" == *Successful* ]]; then
  drush_bootstrap='SUCCESS'
else
  drush_bootstrap='FAILURE'
fi

php_code="$(cat <<'PHP'
$value = \Drupal\Core\Site\Settings::get('config_sync_directory');
if (!is_string($value) || $value === '') {
  exit(2);
}
printf("%s\n%s\n", base64_encode(DRUPAL_ROOT), base64_encode($value));
PHP
)"
encoded_code="$(printf '%s' "$php_code" | base64 -w 0)"
[[ "$encoded_code" =~ ^[A-Za-z0-9+/=]+$ ]]

runtime_getter=''
getter_rc=1
if [[ "$drush_bootstrap" == 'SUCCESS' ]]; then
  printf -v getter_command \
    "set -euo pipefail; cd /var/www/agency-preprod/current; code=\$(printf '%%s' '%s' | base64 -d); vendor/bin/drush php:eval \"\$code\" 2>/dev/null" \
    "$encoded_code"
  set +e
  runtime_getter="$(ssh "${ssh_common[@]}" "$remote_target" "$getter_command")"
  getter_rc="$?"
  set -e
fi

if [[ "$getter_rc" -eq 0 ]]; then
  mapfile -t getter_lines <<<"$runtime_getter"
  [[ "${#getter_lines[@]}" -eq 2 ]]
  drupal_root="$(printf '%s' "${getter_lines[0]}" | base64 -d)"
  effective_config_sync="$(printf '%s' "${getter_lines[1]}" | base64 -d)"
  [[ "$drupal_root" == "$current_target/web" ]]
  [[ "$effective_config_sync" =~ ^/?[A-Za-z0-9._/-]+$ ]]
else
  drupal_root="$current_target/web"
  effective_config_sync='UNOBSERVABLE'
fi

resolved_path='UNOBSERVABLE'
resolved_exists='NO'
entry_count=0
if [[ "$effective_config_sync" != 'UNOBSERVABLE' ]]; then
  if [[ "$effective_config_sync" == /* ]]; then
    resolved_path="$(realpath -m -- "$effective_config_sync")"
  else
    resolved_path="$(realpath -m -- "$drupal_root/$effective_config_sync")"
  fi
  [[ "$resolved_path" =~ ^/var/www/agency-preprod/([A-Za-z0-9._/-]+)$ ]]

  printf -v path_probe \
    "set -euo pipefail; if test -d '%s'; then printf 'YES\\n'; find '%s' -type f -print | wc -l; else printf 'NO\\n0\\n'; fi" \
    "$resolved_path" "$resolved_path"
  mapfile -t path_lines < <(ssh "${ssh_common[@]}" "$remote_target" "$path_probe")
  [[ "${#path_lines[@]}" -eq 2 ]]
  resolved_exists="${path_lines[0]}"
  entry_count="${path_lines[1]}"
  [[ "$resolved_exists" == 'YES' || "$resolved_exists" == 'NO' ]]
  [[ "$entry_count" =~ ^[0-9]+$ ]]
fi

config_status='ERROR'
if [[ "$drush_bootstrap" == 'SUCCESS' ]]; then
  config_status_raw=''
  set +e
  config_status_raw="$(ssh "${ssh_common[@]}" "$remote_target" \
    'set -euo pipefail; cd /var/www/agency-preprod/current; vendor/bin/drush config:status --format=json 2>/dev/null')"
  config_status_rc="$?"
  set -e
  if [[ "$config_status_rc" -eq 0 ]]; then
    if [[ -z "$config_status_raw" ]]; then
      config_status='CLEAN'
    else
      set +e
      CONFIG_STATUS_RAW="$config_status_raw" php -r '
        $decoded = json_decode((string) getenv("CONFIG_STATUS_RAW"), TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          exit(2);
        }
        exit(empty($decoded) ? 0 : 1);
      '
      decoded_rc="$?"
      set -e
      case "$decoded_rc" in
        0) config_status='CLEAN' ;;
        1) config_status='DIFFERENT' ;;
        *) config_status='ERROR' ;;
      esac
    fi
  fi
fi

jq -n \
  --arg current_release "$current_release" \
  --arg current_symlink_target "$current_target" \
  --arg drupal_root "$drupal_root" \
  --arg settings_symlink_target "$settings_target" \
  --arg shared_settings_sha256 "$settings_sha256" \
  --arg effective_config_sync_directory "$effective_config_sync" \
  --arg resolved_config_sync_path "$resolved_path" \
  --arg resolved_path_exists "$resolved_exists" \
  --argjson config_sync_entry_count "$entry_count" \
  --arg drush_bootstrap "$drush_bootstrap" \
  --arg drush_config_status "$config_status" \
  '{
    schema_version: 1,
    target: "PREPROD",
    current_release: $current_release,
    current_symlink_target: $current_symlink_target,
    drupal_root: $drupal_root,
    settings_symlink_target: $settings_symlink_target,
    shared_settings_sha256: $shared_settings_sha256,
    effective_config_sync_directory: $effective_config_sync_directory,
    resolved_config_sync_path: $resolved_config_sync_path,
    resolved_path_exists: $resolved_path_exists,
    config_sync_entry_count: $config_sync_entry_count,
    drush_bootstrap: $drush_bootstrap,
    drush_config_status: $drush_config_status,
    drupal_status_config_sync_warning: "NOT_OBSERVABLE",
    preprod_mutation: "NONE",
    prod_access: "NONE",
    prod_write: "NONE"
  }' > "$ARTIFACT_DIR/result.json"

jq -e '
  .schema_version == 1
  and .target == "PREPROD"
  and (.current_release | type == "string")
  and (.current_symlink_target | startswith("/var/www/agency-preprod/releases/"))
  and (.drupal_root | endswith("/web"))
  and .settings_symlink_target == "/var/www/agency-preprod/shared/settings/settings.php"
  and (.shared_settings_sha256 | test("^[0-9a-f]{64}$"))
  and (.resolved_path_exists == "YES" or .resolved_path_exists == "NO")
  and (.config_sync_entry_count | type == "number")
  and (.drush_bootstrap == "SUCCESS" or .drush_bootstrap == "FAILURE")
  and (.drush_config_status == "CLEAN" or .drush_config_status == "DIFFERENT" or .drush_config_status == "ERROR")
  and .drupal_status_config_sync_warning == "NOT_OBSERVABLE"
  and .preprod_mutation == "NONE"
  and .prod_access == "NONE"
  and .prod_write == "NONE"
' "$ARTIFACT_DIR/result.json" >/dev/null
