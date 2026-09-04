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

# Standard Drupal/Drush convergence only; no custom migration engine.
drush updb -y
drush cim -y
drush cr
drush php:script scripts/development-seed/local-converge.php
drush cr
drush php:eval 'if (!\Drupal::hasService("database")) { throw new \RuntimeException("Drupal bootstrap failed."); }'

# Record non-sensitive reproducibility metadata only after import/convergence
# succeeded. The database artifact remains DDEV's native download lifecycle.
mv -f -- "$candidate" "$state"
chmod 600 "$state"

printf '%s\n' 'LOCAL_CONVERGENCE=PASS'
printf '%s\n' 'DEVELOPMENT_SEED_STATE=.ddev/.state-agency-seed.json'
