#!/usr/bin/env bash
set -euo pipefail

artifact_root="${ARTIFACT_ROOT:-artifacts/config-language-lock-post-migration-evaluation}"
mkdir -p "$artifact_root"

state_script='/var/www/html/scripts/runner/config-language-lock-state.php'
audit_script='/var/www/html/scripts/runner/configuration-language-audit.php'

capture_status() {
  local target="$1"
  local status
  status="$(ddev drush config:status 2>&1)"
  printf '%s\n' "$status" > "$target"
  grep -Fq 'No differences' <<<"$status"
}

capture_state() {
  local state_target="$1"
  local audit_target="$2"
  ddev drush php:script "$state_script" > "$state_target"
  ddev drush php:script "$audit_script" > "$audit_target"
}

ddev start -y
ddev composer audit --locked --format=json > "$artifact_root/composer-audit.json"
jq -e '(.advisories // {}) | length == 0' "$artifact_root/composer-audit.json" >/dev/null
ddev composer install --no-interaction --no-progress --prefer-dist
ddev exec php -l "$state_script" >/dev/null
ddev exec php -l "$audit_script" >/dev/null

admin_pass="$(openssl rand -hex 24)"
ddev drush site:install --existing-config -y --account-pass="$admin_pass"
unset admin_pass
ddev drush cim -y
ddev drush cr
capture_status "$artifact_root/config-status-before.txt"
capture_state "$artifact_root/state-before.json" "$artifact_root/language-before.json"

jq -e '.schema_version == 2 and .total == 595' "$artifact_root/state-before.json" >/dev/null
jq -e '.site_default_language == "fr"' "$artifact_root/state-before.json" >/dev/null
jq -e '.config_language_lock_enabled == false' "$artifact_root/state-before.json" >/dev/null
jq -e '(.module_owned | length) == 0' "$artifact_root/state-before.json" >/dev/null
jq -e '.special.system_menu_footer_langcode == "und"' "$artifact_root/state-before.json" >/dev/null
jq -e '.special.language_entity_und_id == "und"' "$artifact_root/state-before.json" >/dev/null
jq -e '.special.language_entity_zxx_id == "zxx"' "$artifact_root/state-before.json" >/dev/null
jq -e '.repository.summary.total == 595' "$artifact_root/language-before.json" >/dev/null
jq -e '.active.summary.total == 595' "$artifact_root/language-before.json" >/dev/null
jq -e '.repository.summary.by_langcode == {"__none__":59,"en":395,"fr":140,"und":1}' "$artifact_root/language-before.json" >/dev/null
jq -e '.active.summary.by_langcode == {"__none__":59,"en":395,"fr":140,"und":1}' "$artifact_root/language-before.json" >/dev/null
jq -e '.repository_active_comparison.missing_from_active | length == 0' "$artifact_root/language-before.json" >/dev/null
jq -e '.repository_active_comparison.missing_from_repository | length == 0' "$artifact_root/language-before.json" >/dev/null
jq -e '.repository_active_comparison.langcode_mismatches | length == 0' "$artifact_root/language-before.json" >/dev/null

enable_outcome='failure'
semantics_outcome='skipped'
uninstall_outcome='skipped'
restore_outcome='skipped'

if ddev drush pm:enable config_language_lock -y && ddev drush cr; then
  enable_outcome='success'
  capture_state "$artifact_root/state-enabled.json" "$artifact_root/language-enabled.json"
  test -z "$(git status --short config/sync)"

  if python3 - "$artifact_root" <<'PY'
import json
import sys
from pathlib import Path

root = Path(sys.argv[1])
before = json.loads((root / 'state-before.json').read_text(encoding='utf-8'))
enabled = json.loads((root / 'state-enabled.json').read_text(encoding='utf-8'))
language_before = json.loads((root / 'language-before.json').read_text(encoding='utf-8'))
language_enabled = json.loads((root / 'language-enabled.json').read_text(encoding='utf-8'))

errors = []
if enabled.get('config_language_lock_enabled') is not True:
    errors.append('config_language_lock was not enabled')
if enabled.get('site_default_language') != before.get('site_default_language'):
    errors.append('site default language changed during enable')

before_entries = before['entries']
enabled_entries = enabled['entries']
before_names = set(before_entries)
enabled_names = set(enabled_entries)
removed = sorted(before_names - enabled_names)
added = sorted(enabled_names - before_names)
changed_hash = sorted(
    name for name in before_names & enabled_names
    if before_entries[name]['sha256'] != enabled_entries[name]['sha256']
)
langcode_transitions = []
for name in sorted(before_names & enabled_names):
    old = before_entries[name].get('langcode')
    new = enabled_entries[name].get('langcode')
    if old != new:
        langcode_transitions.append({'name': name, 'before': old, 'after': new})

if removed:
    errors.append(f'enable removed existing config: {removed}')
if added != ['config_language_lock.settings']:
    errors.append(f'unexpected module-install config: {added}')

settings = enabled.get('module_owned', {}).get('config_language_lock.settings')
if not isinstance(settings, dict):
    errors.append('config_language_lock.settings was not captured')
else:
    if settings.get('locked_langcode') is not None:
        errors.append(
            f"locked_langcode is enforcing unexpectedly: {settings.get('locked_langcode')!r}"
        )
    if settings.get('follow_site_default') is not False:
        errors.append('follow_site_default must remain false in non-enforcing mode')

site_default = before.get('site_default_language')
for transition in langcode_transitions:
    old = transition['before']
    new = transition['after']
    if old not in ('en', None) or new != site_default:
        errors.append(
            'non-Locale langcode transition: '
            f"{transition['name']} {old!r}->{new!r}"
        )

special = enabled.get('special', {})
if special.get('system_menu_footer_langcode') != 'und':
    errors.append('system.menu.footer no longer has langcode und')
if special.get('language_entity_und_id') != 'und':
    errors.append('language.entity.und semantic id changed')
if special.get('language_entity_zxx_id') != 'zxx':
    errors.append('language.entity.zxx semantic id changed')

mismatch_names = sorted(
    entry['name']
    for entry in language_enabled.get('repository_active_comparison', {}).get(
        'langcode_mismatches', []
    )
)
transition_names = sorted(entry['name'] for entry in langcode_transitions)
if mismatch_names != transition_names:
    errors.append(
        'active/repository langcode mismatches do not equal observed Locale '
        f'transitions: mismatches={mismatch_names}, transitions={transition_names}'
    )

if language_enabled.get('translations') != language_before.get('translations'):
    errors.append('versioned translation-directory inventory changed')

comparison = {
    'classification': 'DRUPAL_LOCALE_EXTENSION_INSTALL_FOOTPRINT',
    'site_default_language': site_default,
    'removed_existing': removed,
    'added_module_owned': added,
    'changed_existing_hashes': changed_hash,
    'langcode_transitions': langcode_transitions,
    'repository_active_langcode_mismatches': mismatch_names,
    'module_settings': settings,
    'special': special,
    'errors': errors,
}
(root / 'enable-comparison.json').write_text(
    json.dumps(comparison, indent=2, sort_keys=True) + '\n',
    encoding='utf-8',
)
if errors:
    raise SystemExit('; '.join(errors))
PY
  then
    semantics_outcome='success'
  else
    semantics_outcome='failure'
  fi

  if ddev drush pm:uninstall config_language_lock -y && ddev drush cr; then
    uninstall_outcome='success'
    capture_state "$artifact_root/state-after-uninstall.json" "$artifact_root/language-after-uninstall.json"
    ddev drush config:status > "$artifact_root/config-status-after-uninstall.txt" 2>&1 || true

    if ! python3 - "$artifact_root" <<'PY'
import json
import sys
from pathlib import Path

root = Path(sys.argv[1])
enabled = json.loads((root / 'state-enabled.json').read_text(encoding='utf-8'))
final = json.loads((root / 'state-after-uninstall.json').read_text(encoding='utf-8'))
errors = []

if final.get('config_language_lock_enabled') is not False:
    errors.append('config_language_lock remains enabled after uninstall')
if final.get('module_owned'):
    errors.append('module-owned config remains after uninstall')

enabled_entries = enabled['entries']
final_entries = final['entries']
expected_names = set(enabled_entries) - {'config_language_lock.settings'}
if set(final_entries) != expected_names:
    errors.append('unexpected config add/remove during uninstall')

changed = sorted(
    name for name in set(final_entries) & set(enabled_entries)
    if final_entries[name]['sha256'] != enabled_entries[name]['sha256']
)
if changed != ['core.extension']:
    errors.append(f'unexpected post-enable uninstall mutation: {changed}')
if final.get('special') != enabled.get('special'):
    errors.append('semantic language/footer state changed during uninstall')

comparison = {
    'changed_existing': changed,
    'module_owned_removed': not bool(final.get('module_owned')),
    'errors': errors,
}
(root / 'uninstall-comparison.json').write_text(
    json.dumps(comparison, indent=2, sort_keys=True) + '\n',
    encoding='utf-8',
)
if errors:
    raise SystemExit('; '.join(errors))
PY
    then
      uninstall_outcome='failure'
    fi
    test -z "$(git status --short config/sync)"
  fi
fi

if [[ "$uninstall_outcome" == 'success' ]]; then
  if ddev drush cim -y && ddev drush cr; then
    capture_state "$artifact_root/state-restored.json" "$artifact_root/language-restored.json"
    if capture_status "$artifact_root/config-status-restored.txt" && \
      python3 - "$artifact_root" <<'PY'
import json
import sys
from pathlib import Path

root = Path(sys.argv[1])
before = json.loads((root / 'state-before.json').read_text(encoding='utf-8'))
restored = json.loads((root / 'state-restored.json').read_text(encoding='utf-8'))
if restored.get('config_language_lock_enabled') is not False:
    raise SystemExit('config_language_lock enabled after canonical restore')
if restored.get('module_owned'):
    raise SystemExit('module-owned config remains after canonical restore')
if restored['entries'] != before['entries']:
    raise SystemExit('canonical restore did not return exact active config baseline')
if restored['overall_sha256'] != before['overall_sha256']:
    raise SystemExit('restored active configuration fingerprint differs from baseline')
PY
    then
      jq -e '.repository_active_comparison.missing_from_active | length == 0' "$artifact_root/language-restored.json" >/dev/null
      jq -e '.repository_active_comparison.missing_from_repository | length == 0' "$artifact_root/language-restored.json" >/dev/null
      jq -e '.repository_active_comparison.langcode_mismatches | length == 0' "$artifact_root/language-restored.json" >/dev/null
      test -z "$(git status --short config/sync)"
      restore_outcome='success'
    else
      restore_outcome='failure'
    fi
  fi
fi

locale_transition_count=0
changed_hash_count=0
if [[ -s "$artifact_root/enable-comparison.json" ]]; then
  locale_transition_count="$(jq -r '.langcode_transitions | length' "$artifact_root/enable-comparison.json")"
  changed_hash_count="$(jq -r '.changed_existing_hashes | length' "$artifact_root/enable-comparison.json")"
fi
baseline_sha="$(jq -r '.overall_sha256' "$artifact_root/state-before.json")"
restored_sha='n/a'
if [[ -s "$artifact_root/state-restored.json" ]]; then
  restored_sha="$(jq -r '.overall_sha256' "$artifact_root/state-restored.json")"
fi

status='FAIL'
verdict='POST_MIGRATION_NON_ENFORCING_EVALUATION_FAILED'
if [[ "$enable_outcome" == 'success' && \
      "$semantics_outcome" == 'success' && \
      "$uninstall_outcome" == 'success' && \
      "$restore_outcome" == 'success' ]]; then
  status='PASS'
  verdict='POST_MIGRATION_NON_ENFORCING_BEHAVIOR_PROVEN'
fi

jq -n \
  --arg status "$status" \
  --arg verdict "$verdict" \
  --arg enable_outcome "$enable_outcome" \
  --arg semantics_outcome "$semantics_outcome" \
  --arg uninstall_outcome "$uninstall_outcome" \
  --arg restore_outcome "$restore_outcome" \
  --arg baseline_sha256 "$baseline_sha" \
  --arg restored_sha256 "$restored_sha" \
  --argjson locale_transition_count "$locale_transition_count" \
  --argjson changed_hash_count "$changed_hash_count" \
  '{
    status:$status,
    verdict:$verdict,
    mode:"post_migration_non_enforcing_with_native_locale_footprint",
    baseline_distribution:{"__none__":59,"en":395,"fr":140,"und":1},
    enable_outcome:$enable_outcome,
    semantics_outcome:$semantics_outcome,
    uninstall_outcome:$uninstall_outcome,
    restore_outcome:$restore_outcome,
    baseline_sha256:$baseline_sha256,
    restored_sha256:$restored_sha256,
    locale_langcode_transition_count:$locale_transition_count,
    changed_existing_hash_count:$changed_hash_count,
    locked_langcode:null,
    follow_site_default:false
  }' > "$artifact_root/result.json"

cat "$artifact_root/result.json"
test "$status" = 'PASS'
