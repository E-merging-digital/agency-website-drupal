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

for command_name in apt-get apt-cache dpkg-query getent grep runuser; do
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

if ! dpkg-query -W -f='${Status}\n' php8.4-cli 2>/dev/null | grep -qx 'install ok installed'; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  if ! apt-cache show php8.4-cli >/dev/null 2>&1; then
    apt-get install -y --no-install-recommends software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update
  fi
  apt-get install -y --no-install-recommends php8.4-cli
fi

if ! command -v php8.4 >/dev/null 2>&1; then
  echo "Host php8.4 command is unavailable after php8.4-cli reconciliation." >&2
  exit 1
fi

php8.4 --version >/dev/null
php8.4 -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);'
php8.4 -l "$VERIFY_SEED_SCRIPT" >/dev/null

runuser -u "$RUNNER_USER" -- env HOME="$RUNNER_HOME" php8.4 --version >/dev/null
runuser -u "$RUNNER_USER" -- env HOME="$RUNNER_HOME" php8.4 -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);'
runuser -u "$RUNNER_USER" -- env HOME="$RUNNER_HOME" php8.4 -l "$VERIFY_SEED_SCRIPT" >/dev/null

printf 'HOST_PHP84_COMMAND=PRESENT\n'
printf 'HOST_PHP_MAJOR_MINOR=%s\n' "$(php8.4 -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
printf 'HOST_PHP84_VERSION=%s\n' "$(php8.4 -r 'echo PHP_VERSION;')"
printf 'PHP84_VERSION_CONTRACT=PASS\n'
printf 'AGENCY_RUNNER_PHP84_COMMAND=PRESENT\n'
printf 'AGENCY_RUNNER_PHP_MAJOR_MINOR=8.4\n'
printf 'AGENCY_RUNNER_VERIFY_SEED_SYNTAX=PASS\n'
printf 'GENERIC_PHP_ALTERNATIVE_MUTATION=NONE\n'
printf 'RUNNER_REREGISTRATION=NONE\n'
printf 'RUNNER_REPLACEMENT=NONE\n'
printf 'DDEV_MUTATION=NONE\n'
printf 'DOCKER_MUTATION=NONE\n'
