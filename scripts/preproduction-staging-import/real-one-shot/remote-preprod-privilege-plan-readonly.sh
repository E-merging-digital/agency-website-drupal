#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REQUEST_ID="${1:-}"
[[ "$#" -eq 1 && "$REQUEST_ID" =~ ^(plan|apply)-866-[A-Za-z0-9._-]{1,64}$ ]] || {
  echo 'Invalid #866 privilege PLAN request identity.' >&2
  exit 64
}

HELPER_PATH='/usr/local/sbin/agency-preprod-staging-db'

# Policy discovery only. `sudo -l` never executes the target command.
sudo -n -l -- "$HELPER_PATH" PRECHECK "$REQUEST_ID" 0 >/dev/null 2>&1 || {
  echo 'Fixed helper sudo authority is unavailable.' >&2
  exit 65
}

for forbidden in \
  /usr/bin/mariadb \
  /bin/bash \
  /bin/sh \
  /bin/dash \
  /usr/bin/python3 \
  /usr/bin/python \
  /usr/bin/env; do
  if sudo -n -l -- "$forbidden" >/dev/null 2>&1; then
    echo 'Generic privileged executable authority detected.' >&2
    exit 66
  fi
done

printf '%s\n' \
  'sudoers_fixed_helper_available=PASS' \
  'direct_mariadb_sudo=FORBIDDEN' \
  'generic_shell_sudo=FORBIDDEN' \
  'generic_python_sudo=FORBIDDEN' \
  'generic_env_sudo=FORBIDDEN'
