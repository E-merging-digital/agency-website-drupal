#!/usr/bin/env bash
set -euo pipefail

# Reconcile the host-side PHP CLI required by Development Seed verification.
# This script is intentionally narrower than the full runner bootstrap: it does
# not register, replace or restart the Agency runner and does not touch DDEV or
# Docker configuration.

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run this script as root (for example: sudo bash ...)." >&2
  exit 1
fi

source /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "24.04" ]]; then
  echo "Expected Ubuntu 24.04, found ${ID:-unknown} ${VERSION_ID:-unknown}." >&2
  exit 1
fi

for command_name in apt-get dpkg-query getent grep runuser; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "Missing prerequisite on host: $command_name" >&2
    exit 1
  fi
done

RUNNER_USER="agency-runner"
RUNNER_HOME="$(getent passwd "$RUNNER_USER" | cut -d: -f6)"
if [[ -z "$RUNNER_HOME" ]]; then
  echo "Agency runner account is missing: $RUNNER_USER" >&2
  exit 1
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
VERIFY_SEED_SCRIPT="$REPOSITORY_ROOT/scripts/development-seed/verify-seed.php"
if [[ ! -f "$VERIFY_SEED_SCRIPT" ]]; then
  echo "Development Seed verifier is missing: $VERIFY_SEED_SCRIPT" >&2
  exit 1
fi

if ! dpkg-query -W -f='${Status}\n' php-cli 2>/dev/null | grep -qx 'install ok installed'; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y --no-install-recommends php-cli
fi

if ! command -v php >/dev/null 2>&1; then
  echo "Host php command is unavailable after php-cli reconciliation." >&2
  exit 1
fi

php --version >/dev/null
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'
php -l "$VERIFY_SEED_SCRIPT" >/dev/null

runuser -u "$RUNNER_USER" -- env HOME="$RUNNER_HOME" php --version >/dev/null
runuser -u "$RUNNER_USER" -- env HOME="$RUNNER_HOME" php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'
runuser -u "$RUNNER_USER" -- env HOME="$RUNNER_HOME" php -l "$VERIFY_SEED_SCRIPT" >/dev/null

printf 'HOST_PHP_COMMAND=PRESENT\n'
printf 'HOST_PHP_VERSION=%s\n' "$(php -r 'echo PHP_VERSION;')"
printf 'PHP_VERSION_COMPATIBLE_WITH_VERIFY_SEED=PASS\n'
printf 'AGENCY_RUNNER_PHP_COMMAND=PRESENT\n'
printf 'AGENCY_RUNNER_VERIFY_SEED_SYNTAX=PASS\n'
printf 'RUNNER_REREGISTRATION=NONE\n'
printf 'RUNNER_REPLACEMENT=NONE\n'
printf 'DDEV_MUTATION=NONE\n'
printf 'DOCKER_MUTATION=NONE\n'
