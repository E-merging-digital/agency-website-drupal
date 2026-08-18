#!/usr/bin/env bash

# Trusted Composer materialization profiles.
#
# Callers may select a reviewed profile name only. Package names, constraints,
# commands and owning issues are repository-owned data and must never come from
# a dispatch request.

case "${COMPOSER_PROFILE:-}" in
  canvas-ai-agents-530)
    COMPOSER_PACKAGE='drupal/ai_agents'
    COMPOSER_CONSTRAINT='^1.3'
    COMPOSER_VERSION_REGEX='^1\.3\.[0-9]+$'
    COMPOSER_OWNER_ISSUE='530'
    ;;
  *)
    printf 'Unsupported Composer materialization profile: %s\n' \
      "${COMPOSER_PROFILE:-<empty>}" >&2
    return 1 2>/dev/null || exit 1
    ;;
esac

export COMPOSER_PACKAGE
export COMPOSER_CONSTRAINT
export COMPOSER_VERSION_REGEX
export COMPOSER_OWNER_ISSUE
