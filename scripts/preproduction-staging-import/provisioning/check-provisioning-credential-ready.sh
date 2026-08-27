#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${PREPROD_PROVISIONING_SSH_PRIVATE_KEY:-}" ]]; then
  printf '%s\n' 'Provisioning PLAN fail closed: PREPROD_PROVISIONING_SSH_PRIVATE_KEY is absent or empty.' >&2
  exit 78
fi

printf '%s\n' 'provisioning_credential_ready=PASS'
