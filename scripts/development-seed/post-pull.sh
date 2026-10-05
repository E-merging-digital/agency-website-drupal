#!/usr/bin/env bash
set -euo pipefail

[[ "${IS_DDEV_PROJECT:-}" == "true" ]] || {
  echo "Development Seed convergence is DDEV-only." >&2
  exit 2
}

candidate=/var/www/html/.ddev/.downloads/agency-seed.json
state=/var/www/html/.ddev/.state-agency-seed.json
[[ -s "$candidate" ]] || {
  echo "Verified Development Seed metadata is missing." >&2
  exit 2
}

# Prove the imported seed is runtime-clean before any local convergence command
# can create its own logs, queues or session-like state.
AGENCY_DEVELOPMENT_SEED_PHASE=ASSERT_IMPORTED_RUNTIME_EMPTY \
  drush php:script scripts/development-seed/local-converge.php

# Standard Drupal/Drush convergence only; no custom migration engine.
drush updb -y
drush cim -y
drush cr
AGENCY_DEVELOPMENT_SEED_PHASE=FINALIZE \
  drush php:script scripts/development-seed/local-converge.php
drush cr
drush php:eval 'if (!\Drupal::hasService("database")) { throw new \RuntimeException("Drupal bootstrap failed."); }'

# Convergence itself may create local-only runtime rows (for example dblog
# notices). They are safe to remove only after the imported state passed the
# fail-closed assertion above.
AGENCY_DEVELOPMENT_SEED_PHASE=CLEAR_LOCAL_RUNTIME \
  drush php:script scripts/development-seed/local-converge.php
AGENCY_DEVELOPMENT_SEED_PHASE=ASSERT_FINAL_RUNTIME_EMPTY \
  drush php:script scripts/development-seed/local-converge.php

# Record non-sensitive reproducibility metadata only after import/convergence
# succeeded. The database artifact remains DDEV's native download lifecycle.
mv -f -- "$candidate" "$state"
chmod 600 "$state"

printf '%s\n' 'LOCAL_CONVERGENCE=PASS'
printf '%s\n' 'DEVELOPMENT_SEED_STATE=.ddev/.state-agency-seed.json'
