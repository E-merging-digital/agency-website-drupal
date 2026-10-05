#!/usr/bin/env bash

# Trusted Composer materialization profiles.
#
# Callers may select a reviewed profile name only. Package names, constraints,
# update selectors, commands and owning issues are repository-owned data and
# must never come from a dispatch request.

COMPOSER_MODE='package'
COMPOSER_LOCK_REFRESH_SELECTORS=''
COMPOSER_RESULT_RULES='{}'

case "${COMPOSER_PROFILE:-}" in
  canvas-ai-agents-530)
    COMPOSER_PACKAGE='drupal/ai_agents'
    COMPOSER_CONSTRAINT='^1.3'
    COMPOSER_VERSION_REGEX='^1\.3\.[0-9]+$'
    COMPOSER_UPDATE_SELECTOR='drupal/ai_agents'
    COMPOSER_REQUIRED_AI_SEARCH_VERSION=''
    COMPOSER_REQUIRED_FIELD_VALIDATION_VERSION=''
    COMPOSER_OWNER_ISSUE='530'
    ;;
  config-language-lock-628)
    COMPOSER_PACKAGE='drupal/config_language_lock'
    COMPOSER_CONSTRAINT='^1.0'
    COMPOSER_VERSION_REGEX='^1\.0\.[0-9]+$'
    COMPOSER_UPDATE_SELECTOR='drupal/config_language_lock'
    COMPOSER_REQUIRED_AI_SEARCH_VERSION=''
    COMPOSER_REQUIRED_FIELD_VALIDATION_VERSION=''
    COMPOSER_OWNER_ISSUE='628'
    ;;
  drupal-maintenance-ai-1.5-rc1)
    COMPOSER_PACKAGE='drupal/ai'
    COMPOSER_CONSTRAINT='1.5.0-rc1'
    COMPOSER_VERSION_REGEX='^1\.5\.0-rc1$'
    COMPOSER_UPDATE_SELECTOR='drupal/*'
    COMPOSER_REQUIRED_AI_SEARCH_VERSION='1.3.0-alpha4'
    COMPOSER_REQUIRED_FIELD_VALIDATION_VERSION='3.0.0-beta7'
    COMPOSER_OWNER_ISSUE='562'
    ;;
  dependency-maintenance-962)
    COMPOSER_MODE='lock-refresh'
    COMPOSER_PACKAGE=''
    COMPOSER_CONSTRAINT=''
    COMPOSER_VERSION_REGEX=''
    COMPOSER_UPDATE_SELECTOR=''
    COMPOSER_LOCK_REFRESH_SELECTORS='drupal/core-recommended drupal/core-composer-scaffold drupal/core-project-message drupal/core-recipe-unpack drupal/core-dev phpstan/phpstan composer/composer'
    COMPOSER_RESULT_RULES='{"drupal/core-recommended":"^11\\.4\\.[0-9]+$","drupal/core-composer-scaffold":"^11\\.4\\.[0-9]+$","drupal/core-project-message":"^11\\.4\\.[0-9]+$","drupal/core-recipe-unpack":"^11\\.4\\.[0-9]+$","drupal/core-dev":"^11\\.4\\.[0-9]+$","phpstan/phpstan":"^2\\.2\\.[0-9]+$","composer/composer":"^2\\.10\\.[0-9]+$"}'
    COMPOSER_REQUIRED_AI_SEARCH_VERSION=''
    COMPOSER_REQUIRED_FIELD_VALIDATION_VERSION=''
    COMPOSER_OWNER_ISSUE='962'
    ;;
  *)
    printf 'Unsupported Composer materialization profile: %s\n' \
      "${COMPOSER_PROFILE:-<empty>}" >&2
    return 1 2>/dev/null || exit 1
    ;;
esac

export COMPOSER_MODE
export COMPOSER_PACKAGE
export COMPOSER_CONSTRAINT
export COMPOSER_VERSION_REGEX
export COMPOSER_UPDATE_SELECTOR
export COMPOSER_LOCK_REFRESH_SELECTORS
export COMPOSER_RESULT_RULES
export COMPOSER_REQUIRED_AI_SEARCH_VERSION
export COMPOSER_REQUIRED_FIELD_VALIDATION_VERSION
export COMPOSER_OWNER_ISSUE
