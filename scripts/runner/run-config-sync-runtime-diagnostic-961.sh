#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ISSUE_NUMBER="${ISSUE_NUMBER:-}"
DIAGNOSTIC_PROFILE="${DIAGNOSTIC_PROFILE:-metadata}"
PREPROD_SERVER_HOST="${PREPROD_SERVER_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/config-sync-runtime-diagnostic}"

[[ "$ISSUE_NUMBER" == '961' || "$ISSUE_NUMBER" == '982' || "$ISSUE_NUMBER" == '995' || "$ISSUE_NUMBER" == '1318' ]] || {
  echo 'This diagnostic is bound to issue #961, #982, #995 or #1318.' >&2
  exit 1
}
if [[ "$ISSUE_NUMBER" == '995' ]]; then
  [[ "$DIAGNOSTIC_PROFILE" == 'canvas_paths' ]] || {
    echo '#995 requires the bounded canvas_paths diagnostic profile.' >&2
    exit 1
  }
elif [[ "$ISSUE_NUMBER" == '1318' ]]; then
  [[ "$DIAGNOSTIC_PROFILE" == 'language_lock' ]] || {
    echo '#1318 requires the bounded language_lock diagnostic profile.' >&2
    exit 1
  }
else
  [[ "$DIAGNOSTIC_PROFILE" == 'metadata' ]] || {
    echo '#961/#982 require the metadata diagnostic profile.' >&2
    exit 1
  }
fi
[[ -n "$PREPROD_SERVER_HOST" ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" ]]

PROJECT_ROOT='/var/www/agency-preprod'
CURRENT_LINK="$PROJECT_ROOT/current"
EXPECTED_SETTINGS="$PROJECT_ROOT/shared/settings/settings.php"
PREPROD_TRUST_PROVISION='scripts/preproduction-ssh-trust/manage-known-host.sh'
PREPROD_TRUST_VERIFY='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
CONFIG_STATUS_FILTER='scripts/runner/filter-config-status-metadata.php'
CANVAS_PATH_PROBE='scripts/runner/canvas-runtime-diff-paths-995.php'

[[ -f "$CONFIG_STATUS_FILTER" ]]
[[ -f "$CANVAS_PATH_PROBE" ]]
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
  "set -euo pipefail; sha256sum '$EXPECTED_SETTINGS' | awk '{print \$1}'")"
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

if [[ "$DIAGNOSTIC_PROFILE" == 'language_lock' ]]; then
  EXPECTED_LANGUAGE_LOCK_RELEASE='/var/www/agency-preprod/releases/20260925235206-b33b6c240ea5'
  EXPECTED_LANGUAGE_LOCK_COMPOSER_SHA256='d3948f88b04e057182a1689a492f5371c5a174f3fad3c83cf7b8ce26f498ab73'
  RUNTIME_LANGUAGE_HELPER="$EXPECTED_LANGUAGE_LOCK_RELEASE/scripts/runner/config-language-policy-candidate-1314-runtime-proof.php"
  COLLECTION_LANGUAGE_HELPER="$EXPECTED_LANGUAGE_LOCK_RELEASE/scripts/runner/materialize-config-language-collections-1316.php"

  [[ "$current_target" == "$EXPECTED_LANGUAGE_LOCK_RELEASE" ]] || {
    echo '#1318 active PREPROD release identity mismatch.' >&2
    exit 1
  }
  [[ "$settings_target" == '/var/www/agency-preprod/shared/settings/settings.php' ]]
  [[ "$drush_bootstrap" == 'SUCCESS' ]] || {
    echo '#1318 requires successful Drush bootstrap before helper execution.' >&2
    exit 1
  }

  composer_lock_sha256="$(ssh "${ssh_common[@]}" "$remote_target" \
    "set -euo pipefail; sha256sum '$EXPECTED_LANGUAGE_LOCK_RELEASE/composer.lock' | awk '{print \$1}'")"
  [[ "$composer_lock_sha256" == "$EXPECTED_LANGUAGE_LOCK_COMPOSER_SHA256" ]] || {
    echo '#1318 active PREPROD composer.lock fingerprint mismatch.' >&2
    exit 1
  }
  echo 'LANGUAGE_LOCK_PHASE=IDENTITY_GATE_PASS'

  printf -v helper_gate \
    "set -euo pipefail; test -f '%s'; test -f '%s'" \
    "$RUNTIME_LANGUAGE_HELPER" "$COLLECTION_LANGUAGE_HELPER"
  if ! ssh "${ssh_common[@]}" "$remote_target" "$helper_gate" >/dev/null 2>/dev/null; then
    echo 'LANGUAGE_LOCK_FAILURE=HELPER_EXISTENCE' >&2
    exit 1
  fi
  echo 'LANGUAGE_LOCK_PHASE=HELPER_EXISTENCE_PASS'

  printf -v runtime_command \
    "set -euo pipefail; cd '%s'; vendor/bin/drush php:script '%s' 2>/dev/null" \
    "$EXPECTED_LANGUAGE_LOCK_RELEASE" "$RUNTIME_LANGUAGE_HELPER"
  runtime_language_raw=''
  if ! runtime_language_raw="$(ssh "${ssh_common[@]}" "$remote_target" "$runtime_command" 2>/dev/null)"; then
    echo 'LANGUAGE_LOCK_FAILURE=RUNTIME_HELPER_EXECUTION' >&2
    exit 1
  fi
  echo 'LANGUAGE_LOCK_PHASE=RUNTIME_HELPER_EXECUTION_PASS'
  runtime_language_json="$ARTIFACT_DIR/language-lock-runtime.json"
  printf '%s\n' "$runtime_language_raw" > "$runtime_language_json"
  unset runtime_language_raw runtime_command

  # RUNTIME_CONTRACT_OBSERVABILITY_BEGIN
  runtime_contract_jq='
    def observed:
      {
        schema_version: .schema_version,
        drupal_core_version: .drupal_core_version,
        canvas_version: .canvas_version,
        config_language_lock_version: .config_language_lock_version,
        site_default_language: .site_default_language,
        locked_langcode: .locked_langcode,
        follow_site_default: .follow_site_default,
        canvas_requirement_verdict: .canvas_requirement_verdict,
        collections: {
          fr: {count: .collections.fr.count},
          en: {count: .collections.en.count}
        },
        languages: {
          und: {
            id: .languages.und.id,
            locked: .languages.und.locked,
            technical_langcode: .languages.und.technical_langcode
          },
          zxx: {
            id: .languages.zxx.id,
            locked: .languages.zxx.locked,
            technical_langcode: .languages.zxx.technical_langcode
          }
        },
        config_values_exposed: .config_values_exposed
      };
    [
      {path: "schema_version", ok: (.schema_version == 1)},
      {path: "drupal_core_version", ok: (.drupal_core_version == "11.4.7")},
      {path: "canvas_version", ok: (.canvas_version == "1.11.0")},
      {path: "config_language_lock_version", ok: (.config_language_lock_version == "1.0.2")},
      {path: "site_default_language", ok: (.site_default_language == "fr")},
      {path: "locked_langcode", ok: (.locked_langcode == "fr")},
      {path: "follow_site_default", ok: (.follow_site_default == true)},
      {path: "canvas_requirement_verdict", ok: (.canvas_requirement_verdict == "PASS")},
      {path: "collections.fr.count", ok: (.collections.fr.count == 7)},
      {path: "collections.en.count", ok: (.collections.en.count == 418)},
      {path: "languages.und.id", ok: (.languages.und.id == "und")},
      {path: "languages.und.locked", ok: (.languages.und.locked == true)},
      {path: "languages.und.technical_langcode", ok: (.languages.und.technical_langcode == "fr")},
      {path: "languages.zxx.id", ok: (.languages.zxx.id == "zxx")},
      {path: "languages.zxx.locked", ok: (.languages.zxx.locked == true)},
      {path: "languages.zxx.technical_langcode", ok: (.languages.zxx.technical_langcode == "fr")},
      {path: "config_values_exposed", ok: (.config_values_exposed == false)}
    ] as $checks
    | {
        valid: ($checks | all(.ok)),
        mismatches: [$checks[] | select(.ok == false) | .path],
        observed: observed
      }
  '
  runtime_contract_diagnostic=''
  if ! runtime_contract_diagnostic="$(jq -c "$runtime_contract_jq" "$runtime_language_json" 2>/dev/null)"; then
    echo 'LANGUAGE_LOCK_FAILURE=RUNTIME_CONTRACT' >&2
    echo 'LANGUAGE_LOCK_RUNTIME_MISMATCH_FIELDS=INVALID_JSON' >&2
    exit 1
  fi

  if [[ "$(jq -r '.valid' <<<"$runtime_contract_diagnostic")" != 'true' ]]; then
    runtime_mismatch_fields="$(jq -r '.mismatches | join(",")' <<<"$runtime_contract_diagnostic")"
    runtime_observed="$(jq -c '.observed' <<<"$runtime_contract_diagnostic")"
    echo 'LANGUAGE_LOCK_FAILURE=RUNTIME_CONTRACT' >&2
    printf 'LANGUAGE_LOCK_RUNTIME_MISMATCH_FIELDS=%s\n' "$runtime_mismatch_fields" >&2
    printf 'LANGUAGE_LOCK_RUNTIME_OBSERVED=%s\n' "$runtime_observed" >&2
    unset runtime_contract_diagnostic runtime_contract_jq runtime_mismatch_fields runtime_observed
    exit 1
  fi
  unset runtime_contract_diagnostic runtime_contract_jq
  # RUNTIME_CONTRACT_OBSERVABILITY_END
  echo 'LANGUAGE_LOCK_PHASE=RUNTIME_CONTRACT_PASS'

  printf -v collection_command \
    "set -euo pipefail; cd '%s'; AGENCY_CONFIG_LANGUAGE_COLLECTION_MODE=VERIFY AGENCY_CONFIG_LANGUAGE_COLLECTION_DIAGNOSTIC_ONLY=1 vendor/bin/drush php:script '%s' 2>/dev/null" \
    "$EXPECTED_LANGUAGE_LOCK_RELEASE" "$COLLECTION_LANGUAGE_HELPER"
  strict_collection_raw=''
  if ! strict_collection_raw="$(ssh "${ssh_common[@]}" "$remote_target" "$collection_command" 2>/dev/null)"; then
    echo 'LANGUAGE_LOCK_FAILURE=COLLECTION_HELPER_EXECUTION' >&2
    exit 1
  fi
  echo 'LANGUAGE_LOCK_PHASE=COLLECTION_HELPER_EXECUTION_PASS'
  strict_collection_json="$ARTIFACT_DIR/language-lock-collections.json"
  printf '%s\n' "$strict_collection_raw" > "$strict_collection_json"
  unset strict_collection_raw collection_command

  if ! jq -e '
    .schema_version == 1
    and .mode == "VERIFY"
    and .diagnostic_only == true
    and .status == "PASS"
    and .raw_config_values_exposed == false
    and .collections["language.fr"].classification == "MATCH"
    and .collections["language.fr"].match == true
    and .collections["language.fr"].active_count == 7
    and .collections["language.fr"].sync_count == 7
    and .collections["language.fr"].active_only_count == 0
    and .collections["language.fr"].sync_only_count == 0
    and .collections["language.fr"].value_mismatch_count == 0
    and .collections["language.fr"].written == 0
    and .collections["language.fr"].deleted == 0
    and .collections["language.fr"].active_names_sha256 == "528df3f930b3a5b0cc26e14cabf1e628e5e7d3da8fe19634ef870bb5855a80ca"
    and .collections["language.fr"].sync_names_sha256 == "528df3f930b3a5b0cc26e14cabf1e628e5e7d3da8fe19634ef870bb5855a80ca"
    and .collections["language.fr"].active_values_sha256 == "5123ea2664916fb9059165b7cd2657b14b382b3ae88d0d039648e7eb4cf57b2c"
    and .collections["language.fr"].sync_values_sha256 == "5123ea2664916fb9059165b7cd2657b14b382b3ae88d0d039648e7eb4cf57b2c"
    and .collections["language.en"].classification == "MATCH"
    and .collections["language.en"].match == true
    and .collections["language.en"].active_count == 418
    and .collections["language.en"].sync_count == 418
    and .collections["language.en"].active_only_count == 0
    and .collections["language.en"].sync_only_count == 0
    and .collections["language.en"].value_mismatch_count == 0
    and .collections["language.en"].written == 0
    and .collections["language.en"].deleted == 0
    and .collections["language.en"].active_names_sha256 == "31ff109065631edad357abbf9569f68d55dc81ac03feeb7fe99d173f00a14425"
    and .collections["language.en"].sync_names_sha256 == "31ff109065631edad357abbf9569f68d55dc81ac03feeb7fe99d173f00a14425"
    and .collections["language.en"].active_values_sha256 == "7ab417ceafdd77939f8fdb12f042acb6fd9bcd54242e8da5cac074f4708d36ac"
    and .collections["language.en"].sync_values_sha256 == "7ab417ceafdd77939f8fdb12f042acb6fd9bcd54242e8da5cac074f4708d36ac"
  ' "$strict_collection_json" >/dev/null 2>&1; then
    echo 'LANGUAGE_LOCK_FAILURE=COLLECTION_CONTRACT' >&2
    exit 1
  fi
  echo 'LANGUAGE_LOCK_PHASE=COLLECTION_CONTRACT_PASS'

  jq -n \
    --arg current_release "$current_target" \
    --arg composer_lock_sha256 "$composer_lock_sha256" \
    --arg drush_bootstrap "$drush_bootstrap" \
    --arg settings_symlink_target "$settings_target" \
    --slurpfile runtime_language_policy "$runtime_language_json" \
    --slurpfile strict_collection_verify "$strict_collection_json" \
    '{
      schema_version: 1,
      target: "PREPROD",
      diagnostic_profile: "language_lock",
      current_release: $current_release,
      composer_lock_sha256: $composer_lock_sha256,
      drush_bootstrap: $drush_bootstrap,
      settings_symlink_target: $settings_symlink_target,
      runtime_language_policy: $runtime_language_policy[0],
      strict_collection_verify: $strict_collection_verify[0],
      config_values_exposed: false,
      preprod_access: "READ_ONLY",
      preprod_mutation: "NONE",
      preprod_write: "NONE",
      prod_access: "NONE",
      prod_write: "NONE"
    }' > "$ARTIFACT_DIR/result.json"

  rm -f -- "$runtime_language_json" "$strict_collection_json"

  jq -e '
    .schema_version == 1
    and .target == "PREPROD"
    and .diagnostic_profile == "language_lock"
    and .current_release == "/var/www/agency-preprod/releases/20260925235206-b33b6c240ea5"
    and .composer_lock_sha256 == "d3948f88b04e057182a1689a492f5371c5a174f3fad3c83cf7b8ce26f498ab73"
    and .drush_bootstrap == "SUCCESS"
    and .settings_symlink_target == "/var/www/agency-preprod/shared/settings/settings.php"
    and .runtime_language_policy.config_values_exposed == false
    and .strict_collection_verify.mode == "VERIFY"
    and .strict_collection_verify.status == "PASS"
    and .strict_collection_verify.raw_config_values_exposed == false
    and .config_values_exposed == false
    and .preprod_access == "READ_ONLY"
    and .preprod_mutation == "NONE"
    and .preprod_write == "NONE"
    and .prod_access == "NONE"
    and .prod_write == "NONE"
  ' "$ARTIFACT_DIR/result.json" >/dev/null
  echo 'LANGUAGE_LOCK_PHASE=RESULT_MATERIALIZED'
  exit 0
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

[[ "$drush_bootstrap" == 'SUCCESS' ]] || {
  echo 'Drush bootstrap is required for the bounded runtime diagnostic.' >&2
  exit 1
}

if [[ "$DIAGNOSTIC_PROFILE" == 'canvas_paths' ]]; then
  canvas_probe_code="$(tail -n +2 "$CANVAS_PATH_PROBE")"
  encoded_canvas_probe="$(printf '%s' "$canvas_probe_code" | base64 -w 0)"
  [[ "$encoded_canvas_probe" =~ ^[A-Za-z0-9+/=]+$ ]]

  printf -v canvas_command \
    "set -euo pipefail; cd /var/www/agency-preprod/current; code=\$(printf '%%s' '%s' | base64 -d); AGENCY_CANVAS_995_EXECUTE=1 AGENCY_CANVAS_995_ENVIRONMENT=PREPROD vendor/bin/drush php:eval \"\$code\" 2>/dev/null" \
    "$encoded_canvas_probe"
  canvas_paths_raw=''
  set +e
  canvas_paths_raw="$(ssh "${ssh_common[@]}" "$remote_target" "$canvas_command")"
  canvas_paths_rc="$?"
  set -e
  [[ "$canvas_paths_rc" -eq 0 ]] || {
    echo 'PREPROD #995 path-only Canvas probe failed.' >&2
    exit 1
  }

  canvas_paths_public="$ARTIFACT_DIR/canvas-runtime-diff-paths.json"
  printf '%s\n' "$canvas_paths_raw" > "$canvas_paths_public"
  unset canvas_paths_raw canvas_probe_code encoded_canvas_probe canvas_command

  jq -e '
    .schema_version == 1
    and .environment == "PREPROD"
    and .public_schema == "environment + config_name + differing_paths[] + classification"
    and .config_values_exposed == false
    and .cohort_size == 15
    and .summary.total == 15
    and (.items | length == 15)
    and (.items | all(
      .environment == "PREPROD"
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
      target: "PREPROD",
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
      preprod_access: "READ_ONLY",
      preprod_mutation: "NONE",
      preprod_write: "NONE",
      prod_access: "NONE",
      prod_write: "NONE"
    }' > "$ARTIFACT_DIR/result.json"

  jq -e '
    .schema_version == 3
    and .target == "PREPROD"
    and .diagnostic_profile == "canvas_paths"
    and .drush_bootstrap == "SUCCESS"
    and .runtime_canvas_paths.environment == "PREPROD"
    and .runtime_canvas_paths.config_values_exposed == false
    and .runtime_canvas_paths.cohort_size == 15
    and .preprod_access == "READ_ONLY"
    and .preprod_mutation == "NONE"
    and .preprod_write == "NONE"
    and .prod_access == "NONE"
    and .prod_write == "NONE"
  ' "$ARTIFACT_DIR/result.json" >/dev/null
  exit 0
fi

config_status_raw=''
set +e
config_status_raw="$(ssh "${ssh_common[@]}" "$remote_target" \
  'set -euo pipefail; cd /var/www/agency-preprod/current; vendor/bin/drush config:status --format=json 2>/dev/null')"
config_status_rc="$?"
set -e
[[ "$config_status_rc" -eq 0 ]] || {
  echo 'PREPROD drush config:status failed.' >&2
  exit 1
}

config_status_metadata="$ARTIFACT_DIR/config-status-metadata.json"
printf '%s' "$config_status_raw" \
  | php "$CONFIG_STATUS_FILTER" PREPROD \
  > "$config_status_metadata"
unset config_status_raw

jq -e '
  .schema_version == 1
  and .environment == "PREPROD"
  and .metadata_schema == "environment + config_name + operation/state"
  and .config_values_exposed == false
  and (.summary.total | type == "number")
  and (.summary.persistent_language_lock_cim_safety == "YES" or .summary.persistent_language_lock_cim_safety == "REVIEW_REQUIRED")
  and (.items | all(
    .environment == "PREPROD"
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
    runtime_config_metadata: $runtime_config_metadata[0],
    drupal_status_config_sync_warning: "NOT_OBSERVABLE",
    preprod_access: "READ_ONLY",
    preprod_mutation: "NONE",
    preprod_write: "NONE",
    prod_access: "NONE",
    prod_write: "NONE"
  }' > "$ARTIFACT_DIR/result.json"

rm -f -- "$config_status_metadata"

jq -e '
  .schema_version == 2
  and .target == "PREPROD"
  and (.current_release | type == "string")
  and (.current_symlink_target | startswith("/var/www/agency-preprod/releases/"))
  and (.drupal_root | endswith("/web"))
  and .settings_symlink_target == "/var/www/agency-preprod/shared/settings/settings.php"
  and (.shared_settings_sha256 | test("^[0-9a-f]{64}$"))
  and (.resolved_path_exists == "YES" or .resolved_path_exists == "NO")
  and (.config_sync_entry_count | type == "number")
  and .drush_bootstrap == "SUCCESS"
  and (.drush_config_status == "CLEAN" or .drush_config_status == "DIFFERENT")
  and .runtime_config_metadata.environment == "PREPROD"
  and .runtime_config_metadata.config_values_exposed == false
  and .drupal_status_config_sync_warning == "NOT_OBSERVABLE"
  and .preprod_access == "READ_ONLY"
  and .preprod_mutation == "NONE"
  and .preprod_write == "NONE"
  and .prod_access == "NONE"
  and .prod_write == "NONE"
' "$ARTIFACT_DIR/result.json" >/dev/null
