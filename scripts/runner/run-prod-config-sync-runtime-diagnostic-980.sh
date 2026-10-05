#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ISSUE_NUMBER="${ISSUE_NUMBER:-}"
DIAGNOSTIC_PROFILE="${DIAGNOSTIC_PROFILE:-metadata}"
SERVER_HOST="${SERVER_HOST:-}"
SERVER_USER="${SERVER_USER:-}"
PROD_SSH_KEY="${PROD_SSH_KEY:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/prod-config-sync-runtime-diagnostic}"

[[ "$ISSUE_NUMBER" == '980' || "$ISSUE_NUMBER" == '982' || "$ISSUE_NUMBER" == '995' || "$ISSUE_NUMBER" == '1301' || "$ISSUE_NUMBER" == '1302' ]] || {
  echo 'This diagnostic is bound to issue #980, #982, #995, #1301 or #1302.' >&2
  exit 1
}
if [[ "$ISSUE_NUMBER" == '995' ]]; then
  [[ "$DIAGNOSTIC_PROFILE" == 'canvas_paths' ]] || {
    echo '#995 requires the bounded canvas_paths diagnostic profile.' >&2
    exit 1
  }
elif [[ "$ISSUE_NUMBER" == '1302' ]]; then
  [[ "$DIAGNOSTIC_PROFILE" == 'language_lock' ]] || {
    echo '#1302 requires the bounded language_lock diagnostic profile.' >&2
    exit 1
  }
else
  [[ "$DIAGNOSTIC_PROFILE" == 'metadata' ]] || {
    echo '#980/#982/#1301 require the metadata diagnostic profile.' >&2
    exit 1
  }
fi
[[ -n "$SERVER_HOST" ]]
[[ "$SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -n "$SERVER_USER" ]]
[[ "$SERVER_USER" =~ ^[A-Za-z0-9._-]+$ ]]
[[ -f "$PROD_SSH_KEY" ]]

PROJECT_ROOT='/var/www/agency'
CURRENT_LINK="$PROJECT_ROOT/current"
EXPECTED_SETTINGS="$PROJECT_ROOT/shared/settings/settings.php"
PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'
CONFIG_STATUS_FILTER='scripts/runner/filter-config-status-metadata.php'
CANVAS_PATH_PROBE='scripts/runner/canvas-runtime-diff-paths-995.php'
LANGUAGE_LOCK_PROBE='scripts/runner/language-lock-runtime-state-1311.php'

[[ -f "$CONFIG_STATUS_FILTER" ]]
[[ -f "$CANVAS_PATH_PROBE" ]]
[[ -f "$LANGUAGE_LOCK_PROBE" ]]
mkdir -p "$ARTIFACT_DIR"

SERVER_HOST="$SERVER_HOST" bash "$PROD_TRUST" PROVISION >/dev/null
SERVER_HOST="$SERVER_HOST" bash "$PROD_TRUST" VERIFY_ONLY >/dev/null

ssh_common=(
  -i "$PROD_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15
)
remote_target="$SERVER_USER@$SERVER_HOST"

current_target="$(ssh "${ssh_common[@]}" "$remote_target" \
  'set -euo pipefail; readlink -f /var/www/agency/current')"
[[ "$current_target" =~ ^/var/www/agency/releases/[A-Za-z0-9._-]+$ ]]
current_release="$(basename "$current_target")"

settings_target="$(ssh "${ssh_common[@]}" "$remote_target" \
  'set -euo pipefail; readlink -f /var/www/agency/current/web/sites/default/settings.php')"
[[ "$settings_target" == "$EXPECTED_SETTINGS" ]]

settings_sha256="$(ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; sha256sum '$EXPECTED_SETTINGS' | awk '{print \$1}'")"
[[ "$settings_sha256" =~ ^[0-9a-f]{64}$ ]]

bootstrap_raw=''
set +e
bootstrap_raw="$(ssh "${ssh_common[@]}" "$remote_target" \
  'set -euo pipefail; cd /var/www/agency/current; vendor/bin/drush status --field=bootstrap 2>/dev/null')"
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
if [[ "$DIAGNOSTIC_PROFILE" == 'language_lock' ]]; then
  drupal_root="$current_target/web"
  effective_config_sync='UNOBSERVABLE'
elif [[ "$drush_bootstrap" == 'SUCCESS' ]]; then
  printf -v getter_command \
    "set -euo pipefail; cd /var/www/agency/current; code=\$(printf '%%s' '%s' | base64 -d); vendor/bin/drush php:eval \"\$code\" 2>/dev/null" \
    "$encoded_code"
  set +e
  runtime_getter="$(ssh "${ssh_common[@]}" "$remote_target" "$getter_command")"
  getter_rc="$?"
  set -e
fi

if [[ "$DIAGNOSTIC_PROFILE" == 'language_lock' ]]; then
  :
elif [[ "$getter_rc" -eq 0 ]]; then
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
  [[ "$resolved_path" =~ ^/var/www/agency/([A-Za-z0-9._/-]+)$ ]]

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

[[ "$drush_bootstrap" == 'SUCCESS' ]] || {
  echo 'Drush bootstrap is required for the bounded runtime diagnostic.' >&2
  exit 1
}

if [[ "$DIAGNOSTIC_PROFILE" == 'canvas_paths' ]]; then
  canvas_probe_code="$(tail -n +2 "$CANVAS_PATH_PROBE")"
  encoded_canvas_probe="$(printf '%s' "$canvas_probe_code" | base64 -w 0)"
  [[ "$encoded_canvas_probe" =~ ^[A-Za-z0-9+/=]+$ ]]

  printf -v canvas_command \
    "set -euo pipefail; cd /var/www/agency/current; code=\$(printf '%%s' '%s' | base64 -d); AGENCY_CANVAS_995_EXECUTE=1 AGENCY_CANVAS_995_ENVIRONMENT=PROD vendor/bin/drush php:eval \"\$code\" 2>/dev/null" \
    "$encoded_canvas_probe"
  canvas_paths_raw=''
  set +e
  canvas_paths_raw="$(ssh "${ssh_common[@]}" "$remote_target" "$canvas_command")"
  canvas_paths_rc="$?"
  set -e
  [[ "$canvas_paths_rc" -eq 0 ]] || {
    echo 'PROD #995 path-only Canvas probe failed.' >&2
    exit 1
  }

  canvas_paths_public="$ARTIFACT_DIR/canvas-runtime-diff-paths.json"
  printf '%s\n' "$canvas_paths_raw" > "$canvas_paths_public"
  unset canvas_paths_raw canvas_probe_code encoded_canvas_probe canvas_command

  jq -e '
    .schema_version == 1
    and .environment == "PROD"
    and .public_schema == "environment + config_name + differing_paths[] + classification"
    and .config_values_exposed == false
    and .cohort_size == 15
    and .summary.total == 15
    and (.items | length == 15)
    and (.items | all(
      .environment == "PROD"
      and (.config_name | startswith("canvas.component.block."))
      and (.differing_paths | type == "array" and length > 0)
      and (.differing_paths | all(type == "string" and length > 0))
      and (
        .classification == "KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN"
        or .classification == "UNEXPECTED_CANVAS_BUSINESS_PATH_REVIEW_REQUIRED"
      )
    ))
  ' "$canvas_paths_public" >/dev/null

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
    --slurpfile runtime_canvas_paths "$canvas_paths_public" \
    '{
      schema_version: 3,
      target: "PROD",
      diagnostic_profile: "canvas_paths",
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
      runtime_canvas_paths: $runtime_canvas_paths[0],
      prod_access: "READ_ONLY",
      prod_mutation: "NONE",
      prod_write: "NONE",
      preprod_access: "NONE",
      preprod_write: "NONE"
    }' > "$ARTIFACT_DIR/result.json"

  jq -e '
    .schema_version == 3
    and .target == "PROD"
    and .diagnostic_profile == "canvas_paths"
    and .drush_bootstrap == "SUCCESS"
    and .runtime_canvas_paths.environment == "PROD"
    and .runtime_canvas_paths.config_values_exposed == false
    and .runtime_canvas_paths.cohort_size == 15
    and .prod_access == "READ_ONLY"
    and .prod_mutation == "NONE"
    and .prod_write == "NONE"
    and .preprod_access == "NONE"
    and .preprod_write == "NONE"
  ' "$ARTIFACT_DIR/result.json" >/dev/null
  exit 0
fi

language_lock_public=''
if [[ "$DIAGNOSTIC_PROFILE" == 'language_lock' ]]; then
  language_lock_probe_code="$(tail -n +2 "$LANGUAGE_LOCK_PROBE")"
  encoded_language_lock_probe="$(printf '%s' "$language_lock_probe_code" | base64 -w 0)"
  [[ "$encoded_language_lock_probe" =~ ^[A-Za-z0-9+/=]+$ ]]

  printf -v language_lock_command \
    "set -euo pipefail; cd /var/www/agency/current; code=\$(printf '%%s' '%s' | base64 -d); AGENCY_LANGUAGE_LOCK_1311_EXECUTE=1 AGENCY_LANGUAGE_LOCK_1311_ENVIRONMENT=PROD vendor/bin/drush php:eval \"\$code\" 2>/dev/null" \
    "$encoded_language_lock_probe"
  language_lock_raw=''
  set +e
  language_lock_raw="$(ssh "${ssh_common[@]}" "$remote_target" "$language_lock_command")"
  language_lock_rc="$?"
  set -e
  [[ "$language_lock_rc" -eq 0 ]] || {
    echo 'PROD #1302 bounded Language Lock runtime probe failed.' >&2
    exit 1
  }

  language_lock_public="$ARTIFACT_DIR/language-lock-runtime-state.json"
  printf '%s\n' "$language_lock_raw" > "$language_lock_public"
  unset language_lock_raw language_lock_probe_code encoded_language_lock_probe language_lock_command

  jq -e '
    .schema_version == 1
    and .target == "PROD"
    and (.current_release | type == "string" and test("^[A-Za-z0-9._-]+$"))
    and .drupal_root == ("/var/www/agency/releases/" + .current_release + "/web")
    and (.drupal_core_version | type == "string" and length > 0 and length <= 64)
    and (.canvas_enabled | type == "boolean")
    and (.canvas_version | type == "string" and length > 0 and length <= 64)
    and (.config_language_lock_enabled | type == "boolean")
    and (.config_language_lock_version | type == "string" and length > 0 and length <= 64)
    and (.site_default_language | type == "string" and test("^[A-Za-z0-9_-]+$"))
    and (.active_locked_langcode == null or (.active_locked_langcode | type == "string" and test("^[A-Za-z0-9_-]+$")))
    and (.active_follow_site_default == null or (.active_follow_site_default | type == "boolean"))
    and (.sync_locked_langcode == null or (.sync_locked_langcode | type == "string" and test("^[A-Za-z0-9_-]+$")))
    and (.sync_follow_site_default == null or (.sync_follow_site_default | type == "boolean"))
    and (.active_sync_lock_settings_match | type == "boolean")
    and .canvas_requirement_source == "Drupal\\config_language_lock\\Hook\\ConfigLanguageLockRequirementsHooks::runtimeRequirements"
    and .canvas_requirement_key == "config_language_lock_canvas_mismatch"
    and (.canvas_requirement_severity == "NONE" or .canvas_requirement_severity == "INFO" or .canvas_requirement_severity == "OK" or .canvas_requirement_severity == "WARNING" or .canvas_requirement_severity == "ERROR")
    and (.canvas_requirement_verdict == "PASS" or .canvas_requirement_verdict == "WARNING" or .canvas_requirement_verdict == "ERROR" or .canvas_requirement_verdict == "NOT_APPLICABLE")
    and (.canvas_requirement_summary | type == "string" and length > 0 and length <= 160 and (test("[<>]") | not))
    and (.languages.und.present | type == "boolean")
    and (.languages.zxx.present | type == "boolean")
    and .config_values_exposed == false
  ' "$language_lock_public" >/dev/null
fi

config_status_raw=''
set +e
config_status_raw="$(ssh "${ssh_common[@]}" "$remote_target" \
  'set -euo pipefail; cd /var/www/agency/current; vendor/bin/drush config:status --format=json 2>/dev/null')"
config_status_rc="$?"
set -e
[[ "$config_status_rc" -eq 0 ]] || {
  echo 'PROD drush config:status failed.' >&2
  exit 1
}

config_status_metadata="$ARTIFACT_DIR/config-status-metadata.json"
printf '%s' "$config_status_raw" \
  | php "$CONFIG_STATUS_FILTER" PROD \
  > "$config_status_metadata"
unset config_status_raw

jq -e '
  .schema_version == 1
  and .environment == "PROD"
  and .metadata_schema == "environment + config_name + operation/state"
  and .config_values_exposed == false
  and (.summary.total | type == "number")
  and (.summary.persistent_language_lock_cim_safety == "YES" or .summary.persistent_language_lock_cim_safety == "REVIEW_REQUIRED")
  and (.items | all(
    .environment == "PROD"
    and (.config_name | type == "string")
    and (.state == "Only in DB" or .state == "Only in sync dir" or .state == "Different")
    and (.operation == "CREATE" or .operation == "UPDATE" or .operation == "DELETE")
    and (.classification == "EXPECTED_REPOSITORY_DEPLOY_DRIFT" or .classification == "INTENTIONAL_RUNTIME_ONLY" or .classification == "UNEXPECTED_REVIEW_REQUIRED")
  ))
' "$config_status_metadata" >/dev/null

config_status='CLEAN'
if [[ "$(jq -r '.summary.total' "$config_status_metadata")" -gt 0 ]]; then
  config_status='DIFFERENT'
fi

if [[ "$DIAGNOSTIC_PROFILE" == 'language_lock' ]]; then
  config_status_concerned="$ARTIFACT_DIR/config-status-concerned.json"
  jq '[
    .items[]
    | select(
        .config_name == "config_language_lock.settings"
        or .config_name == "system.site"
        or .config_name == "core.extension"
      )
  ]' "$config_status_metadata" > "$config_status_concerned"
  jq -e '
    type == "array"
    and all(.[];
      (.config_name == "config_language_lock.settings" or .config_name == "system.site" or .config_name == "core.extension")
      and (.state == "Only in DB" or .state == "Only in sync dir" or .state == "Different")
      and (.operation == "CREATE" or .operation == "UPDATE" or .operation == "DELETE")
      and (.classification == "EXPECTED_REPOSITORY_DEPLOY_DRIFT" or .classification == "INTENTIONAL_RUNTIME_ONLY" or .classification == "UNEXPECTED_REVIEW_REQUIRED")
    )
  ' "$config_status_concerned" >/dev/null

  jq -n \
    --slurpfile runtime "$language_lock_public" \
    --slurpfile config_status_concerned "$config_status_concerned" \
    '{
      schema_version: 4,
      target: $runtime[0].target,
      diagnostic_profile: "language_lock",
      current_release: $runtime[0].current_release,
      drupal_root: $runtime[0].drupal_root,
      drupal_core_version: $runtime[0].drupal_core_version,
      canvas_enabled: $runtime[0].canvas_enabled,
      canvas_version: $runtime[0].canvas_version,
      config_language_lock_enabled: $runtime[0].config_language_lock_enabled,
      config_language_lock_version: $runtime[0].config_language_lock_version,
      site_default_language: $runtime[0].site_default_language,
      active_locked_langcode: $runtime[0].active_locked_langcode,
      active_follow_site_default: $runtime[0].active_follow_site_default,
      sync_locked_langcode: $runtime[0].sync_locked_langcode,
      sync_follow_site_default: $runtime[0].sync_follow_site_default,
      active_sync_lock_settings_match: $runtime[0].active_sync_lock_settings_match,
      canvas_requirement_source: $runtime[0].canvas_requirement_source,
      canvas_requirement_key: $runtime[0].canvas_requirement_key,
      canvas_requirement_severity: $runtime[0].canvas_requirement_severity,
      canvas_requirement_verdict: $runtime[0].canvas_requirement_verdict,
      canvas_requirement_summary: $runtime[0].canvas_requirement_summary,
      config_status_concerned: $config_status_concerned[0],
      languages: $runtime[0].languages,
      prod_access: "READ_ONLY",
      prod_mutation: "NONE",
      prod_write: "NONE",
      preprod_access: "NONE",
      preprod_write: "NONE",
      config_values_exposed: false
    }' > "$ARTIFACT_DIR/result.json"

  rm -f -- "$config_status_metadata" "$config_status_concerned" "$language_lock_public"

  jq -e '
    .schema_version == 4
    and .target == "PROD"
    and .diagnostic_profile == "language_lock"
    and .prod_access == "READ_ONLY"
    and .prod_mutation == "NONE"
    and .prod_write == "NONE"
    and .preprod_access == "NONE"
    and .preprod_write == "NONE"
    and .config_values_exposed == false
    and (.config_status_concerned | type == "array")
  ' "$ARTIFACT_DIR/result.json" >/dev/null
  exit 0
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
  --slurpfile runtime_config_metadata "$config_status_metadata" \
  '{
    schema_version: 2,
    target: "PROD",
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
    runtime_config_metadata: $runtime_config_metadata[0],
    drupal_status_config_sync_warning: "NOT_OBSERVABLE",
    prod_access: "READ_ONLY",
    prod_mutation: "NONE",
    prod_write: "NONE",
    preprod_access: "NONE",
    preprod_write: "NONE"
  }' > "$ARTIFACT_DIR/result.json"

rm -f -- "$config_status_metadata"

jq -e '
  .schema_version == 2
  and .target == "PROD"
  and (.current_release | type == "string")
  and (.current_symlink_target | startswith("/var/www/agency/releases/"))
  and (.drupal_root | endswith("/web"))
  and .settings_symlink_target == "/var/www/agency/shared/settings/settings.php"
  and (.shared_settings_sha256 | test("^[0-9a-f]{64}$"))
  and (.resolved_path_exists == "YES" or .resolved_path_exists == "NO")
  and (.config_sync_entry_count | type == "number")
  and .drush_bootstrap == "SUCCESS"
  and (.drush_config_status == "CLEAN" or .drush_config_status == "DIFFERENT")
  and .runtime_config_metadata.environment == "PROD"
  and .runtime_config_metadata.config_values_exposed == false
  and .drupal_status_config_sync_warning == "NOT_OBSERVABLE"
  and .prod_access == "READ_ONLY"
  and .prod_mutation == "NONE"
  and .prod_write == "NONE"
  and .preprod_access == "NONE"
  and .preprod_write == "NONE"
' "$ARTIFACT_DIR/result.json" >/dev/null
