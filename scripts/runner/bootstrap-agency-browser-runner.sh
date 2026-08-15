#!/usr/bin/env bash
set -euo pipefail

# One-time bootstrap for a repository-scoped Agency browser validation runner.
# Run as root on the already-proven preflight-runner-01 host.
# This script never modifies the existing Preflight runner installation/service.

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run this script as root (for example: sudo bash ...)." >&2
  exit 1
fi

RUNNER_VERSION="2.336.0"
RUNNER_ARCHIVE="actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
RUNNER_SHA256="04cf0be1aff4c3ec3554466c39124ca250e3effd8873bb7e8d68535aa9505d5d"
RUNNER_URL="https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/${RUNNER_ARCHIVE}"
REPOSITORY_URL="https://github.com/E-merging-digital/agency-website-drupal"
RUNNER_USER="agency-runner"
RUNNER_HOME="/home/${RUNNER_USER}"
RUNNER_DIR="/opt/actions-runner-agency"
RUNNER_NAME="${AGENCY_RUNNER_NAME:-agency-browser-runner-01}"
RUNNER_LABELS="agency,ddev,browser"
PLAYWRIGHT_VERSION="1.62.1"

for command in curl tar sha256sum docker ddev node npm runuser openssl; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "Missing prerequisite on host: $command" >&2
    exit 1
  fi
done

docker info >/dev/null
ddev version
node_version="$(node -p 'process.versions.node')"
node_major="${node_version%%.*}"
if (( node_major < 20 )); then
  echo "Node.js >=20 is required on the host; found ${node_version}." >&2
  exit 1
fi

if [[ -z "${AGENCY_RUNNER_REGISTRATION_TOKEN:-}" ]]; then
  if [[ ! -t 0 ]]; then
    echo "Set AGENCY_RUNNER_REGISTRATION_TOKEN or run interactively." >&2
    exit 1
  fi
  read -r -s -p "Agency GitHub runner registration token: " AGENCY_RUNNER_REGISTRATION_TOKEN
  echo
fi

if [[ -z "$AGENCY_RUNNER_REGISTRATION_TOKEN" ]]; then
  echo "Registration token is required." >&2
  exit 1
fi

if ! id "$RUNNER_USER" >/dev/null 2>&1; then
  useradd --create-home --home-dir "$RUNNER_HOME" --shell /bin/bash "$RUNNER_USER"
fi

if getent group docker >/dev/null 2>&1; then
  usermod -aG docker "$RUNNER_USER"
else
  echo "Docker group is missing; refusing to alter Docker installation." >&2
  exit 1
fi

# Install browser OS dependencies once at host level. Browser binaries themselves
# are installed per workflow under the runner account.
playwright_tmp="$(mktemp -d)"
cleanup_tmp() {
  rm -rf "$playwright_tmp"
}
trap cleanup_tmp EXIT
pushd "$playwright_tmp" >/dev/null
npm init -y >/dev/null 2>&1
npm install --no-audit --no-fund --silent --save-dev "@playwright/test@${PLAYWRIGHT_VERSION}"
npx playwright install-deps chromium
popd >/dev/null

if [[ -e "$RUNNER_DIR/.runner" ]]; then
  echo "Agency runner already appears configured at $RUNNER_DIR; refusing replacement." >&2
  exit 1
fi

mkdir -p "$RUNNER_DIR"
archive_path="${playwright_tmp}/${RUNNER_ARCHIVE}"
curl --fail --location --silent --show-error "$RUNNER_URL" --output "$archive_path"
printf '%s  %s\n' "$RUNNER_SHA256" "$archive_path" | sha256sum --check --status || {
  echo "GitHub Actions runner archive checksum mismatch." >&2
  exit 1
}

tar -xzf "$archive_path" -C "$RUNNER_DIR"
chown -R "$RUNNER_USER:$RUNNER_USER" "$RUNNER_DIR" "$RUNNER_HOME"

pushd "$RUNNER_DIR" >/dev/null
./bin/installdependencies.sh

runuser -u "$RUNNER_USER" -- env \
  HOME="$RUNNER_HOME" \
  ./config.sh \
    --unattended \
    --url "$REPOSITORY_URL" \
    --token "$AGENCY_RUNNER_REGISTRATION_TOKEN" \
    --name "$RUNNER_NAME" \
    --labels "$RUNNER_LABELS" \
    --work _work

unset AGENCY_RUNNER_REGISTRATION_TOKEN

./svc.sh install "$RUNNER_USER"
./svc.sh start
./svc.sh status
popd >/dev/null

# A new login/session is needed for docker-group membership to be reflected.
runuser -u "$RUNNER_USER" -- env HOME="$RUNNER_HOME" bash -lc '
  set -euo pipefail
  id
  docker info --format "ServerVersion={{.ServerVersion}} Driver={{.Driver}}"
  ddev version
  node --version
  npm --version
'

echo
printf 'Agency runner provisioned: %s\n' "$RUNNER_NAME"
printf 'Labels: self-hosted, linux, x64, %s\n' "$RUNNER_LABELS"
printf 'Repository: %s\n' "$REPOSITORY_URL"
printf 'Existing Preflight runner was not modified.\n'
