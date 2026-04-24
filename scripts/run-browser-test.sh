#!/usr/bin/env bash

set -euo pipefail

if [ "$#" -lt 1 ]; then
  echo "Usage: $0 <phpunit test path>" >&2
  exit 2
fi

TEST_PATH="$1"

export SYMFONY_DEPRECATIONS_HELPER="${SYMFONY_DEPRECATIONS_HELPER:-disabled}"
export BROWSERTEST_OUTPUT_DIRECTORY="${BROWSERTEST_OUTPUT_DIRECTORY:-/tmp/browsertest_output}"
mkdir -p "$BROWSERTEST_OUTPUT_DIRECTORY" web/sites/simpletest

if [ -z "${SIMPLETEST_DB:-}" ]; then
  export SIMPLETEST_DB="sqlite://localhost/sites/default/files/.ht.sqlite"
fi

PHP_SERVER_PID=""

if [ -z "${SIMPLETEST_BASE_URL:-}" ]; then
  if [ -n "${DDEV_PRIMARY_URL:-}" ]; then
    export SIMPLETEST_BASE_URL="$DDEV_PRIMARY_URL"
  else
    export SIMPLETEST_BASE_URL="http://127.0.0.1:8888"
    php -S 127.0.0.1:8888 -t web >/tmp/browsertest_server.log 2>&1 &
    PHP_SERVER_PID=$!
  fi
fi

cleanup() {
  if [ -n "$PHP_SERVER_PID" ] && kill -0 "$PHP_SERVER_PID" >/dev/null 2>&1; then
    kill "$PHP_SERVER_PID"
  fi
}
trap cleanup EXIT

vendor/bin/phpunit -c web/core/phpunit.xml.dist "$TEST_PATH"

