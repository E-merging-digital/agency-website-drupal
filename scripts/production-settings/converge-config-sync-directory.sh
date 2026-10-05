#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$#" -ne 1 ]]; then
  echo 'Usage: converge-config-sync-directory.sh <settings.php>' >&2
  exit 64
fi

SETTINGS_FILE="$1"
EXPECTED_ASSIGNMENT="\$settings['config_sync_directory'] = dirname(DRUPAL_ROOT) . '/config/sync';"

if [[ ! -f "$SETTINGS_FILE" || -L "$SETTINGS_FILE" ]]; then
  echo 'Production shared settings must be one existing regular file.' >&2
  exit 1
fi

SETTINGS_DIR="$(dirname "$SETTINGS_FILE")"
SETTINGS_OWNER="$(stat -c '%u' "$SETTINGS_FILE")"
SETTINGS_GROUP="$(stat -c '%g' "$SETTINGS_FILE")"
SETTINGS_MODE="$(stat -c '%a' "$SETTINGS_FILE")"
CURRENT_UID="$(id -u)"

if [[ "$SETTINGS_OWNER" != "$CURRENT_UID" ]]; then
  echo 'Production shared settings ownership is not compatible with atomic deploy-user convergence.' >&2
  exit 1
fi

SETTINGS_TMP="$(mktemp "$SETTINGS_DIR/.settings.php.983.XXXXXX")"
cleanup() {
  if [[ -n "${SETTINGS_TMP:-}" ]]; then
    rm -f -- "$SETTINGS_TMP"
  fi
}
trap cleanup EXIT

SETTINGS_FILE="$SETTINGS_FILE" SETTINGS_TMP="$SETTINGS_TMP" php <<'PHP'
<?php

declare(strict_types=1);

$settingsFile = getenv('SETTINGS_FILE');
$settingsTmp = getenv('SETTINGS_TMP');
if (!is_string($settingsFile) || $settingsFile === '' || !is_string($settingsTmp) || $settingsTmp === '') {
  fwrite(STDERR, "Missing bounded settings paths.\n");
  exit(1);
}

$source = file_get_contents($settingsFile);
if (!is_string($source)) {
  fwrite(STDERR, "Unable to read production shared settings.\n");
  exit(1);
}

$pattern = <<<'REGEX'
~\$settings\s*\[\s*(['"])config_sync_directory\1\s*\]\s*=\s*[^;]+;~s
REGEX;
$matches = preg_match_all($pattern, $source);
if ($matches !== 1) {
  fwrite(STDERR, "Expected exactly one config_sync_directory assignment.\n");
  exit(1);
}

$replacement = <<<'PHP_ASSIGNMENT'
$settings['config_sync_directory'] = dirname(DRUPAL_ROOT) . '/config/sync';
PHP_ASSIGNMENT;
$count = 0;
$updated = preg_replace($pattern, $replacement, $source, 1, $count);
if (!is_string($updated) || $count !== 1) {
  fwrite(STDERR, "Unable to converge config_sync_directory.\n");
  exit(1);
}

try {
  token_get_all($updated, TOKEN_PARSE);
}
catch (ParseError) {
  fwrite(STDERR, "Converged production settings failed PHP syntax validation.\n");
  exit(1);
}

if (file_put_contents($settingsTmp, $updated) === false) {
  fwrite(STDERR, "Unable to materialize converged production settings.\n");
  exit(1);
}
PHP

chmod "$SETTINGS_MODE" "$SETTINGS_TMP"
chgrp "$SETTINGS_GROUP" "$SETTINGS_TMP"

if [[ "$(stat -c '%u' "$SETTINGS_TMP")" != "$SETTINGS_OWNER" ]] || \
  [[ "$(stat -c '%g' "$SETTINGS_TMP")" != "$SETTINGS_GROUP" ]] || \
  [[ "$(stat -c '%a' "$SETTINGS_TMP")" != "$SETTINGS_MODE" ]]; then
  echo 'Production shared settings metadata preservation failed.' >&2
  exit 1
fi

php -l "$SETTINGS_TMP" >/dev/null
mv -f -- "$SETTINGS_TMP" "$SETTINGS_FILE"
SETTINGS_TMP=''
trap - EXIT

if ! grep -Fqx "$EXPECTED_ASSIGNMENT" "$SETTINGS_FILE"; then
  echo 'Deterministic config_sync_directory assignment was not materialized exactly.' >&2
  exit 1
fi

printf '%s\n' 'PROD_CONFIG_SYNC_SETTING=DETERMINISTIC'
printf '%s\n' 'CWD_DEPENDENCY=NONE'
