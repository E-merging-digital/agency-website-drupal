#!/usr/bin/env bash
set -Eeuo pipefail

BRANCH="${1:-main}"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"

PROJECT_ROOT="/var/www/agency"
RELEASES_DIR="/var/www/agency/releases"
SHARED_DIR="/var/www/agency/shared"
BACKUPS_DIR="/var/www/agency/shared/backups"
LOG_FILE="/var/www/agency/shared/deployments.log"
REPO_URL="git@github.com:<org>/<repo>.git"
CURRENT_LINK="$PROJECT_ROOT/current"
NEW_RELEASE="$RELEASES_DIR/$TIMESTAMP"
ACTIVE_RELEASE=""
DEPLOY_USER="$(id -un)"
COMMIT_HASH="unknown"
DEPLOY_STATUS="failure"

log() {
  local message="$1"
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$message" | tee -a "$LOG_FILE"
}

fail_trap() {
  local exit_code="$?"
  local line_no="${1:-unknown}"

  log "ERROR: Deployment failed at line ${line_no} with exit code ${exit_code}."

  if [[ -L "$CURRENT_LINK" ]] && [[ -x "$CURRENT_LINK/vendor/bin/drush" ]]; then
    log "Attempting to disable maintenance mode on active release after failure."
    "$CURRENT_LINK/vendor/bin/drush" state:set system.maintenance_mode 0 --input-format=integer || true
    "$CURRENT_LINK/vendor/bin/drush" cr || true
  else
    log "No active release with Drush detected to disable maintenance mode after failure."
  fi

  log "Deployment metadata: timestamp=${TIMESTAMP} branch=${BRANCH} commit=${COMMIT_HASH} release=${NEW_RELEASE} user=${DEPLOY_USER} status=${DEPLOY_STATUS}"
  exit "$exit_code"
}

trap 'fail_trap $LINENO' ERR

mkdir -p "$RELEASES_DIR" "$SHARED_DIR" "$BACKUPS_DIR" "$(dirname "$LOG_FILE")"
touch "$LOG_FILE"

log "Starting deployment for branch '${BRANCH}'."
log "Creating new release directory: ${NEW_RELEASE}"

if [[ -e "$NEW_RELEASE" ]]; then
  log "ERROR: Release directory already exists: ${NEW_RELEASE}"
  exit 1
fi

mkdir -p "$NEW_RELEASE"

git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$NEW_RELEASE"
COMMIT_HASH="$(git -C "$NEW_RELEASE" rev-parse --short HEAD)"
log "Repository cloned at commit ${COMMIT_HASH}."

composer --working-dir="$NEW_RELEASE" install --no-dev --optimize-autoloader
log "Composer dependencies installed."

mkdir -p "$SHARED_DIR/files" "$SHARED_DIR/private" "$SHARED_DIR/settings"
ln -sfn "$SHARED_DIR/files" "$NEW_RELEASE/web/sites/default/files"
ln -sfn "$SHARED_DIR/settings/settings.php" "$NEW_RELEASE/web/sites/default/settings.php"
log "Shared symlinks created for files and settings.php."

if [[ -L "$CURRENT_LINK" ]]; then
  ACTIVE_RELEASE="$(readlink -f "$CURRENT_LINK")"
  log "Active release detected: ${ACTIVE_RELEASE}"

  if [[ -x "$CURRENT_LINK/vendor/bin/drush" ]]; then
    DB_BACKUP="$BACKUPS_DIR/db-${TIMESTAMP}.sql.gz"
    "$CURRENT_LINK/vendor/bin/drush" sql:dump --gzip --result-file="$DB_BACKUP"
    log "Database backup created: ${DB_BACKUP}"

    "$CURRENT_LINK/vendor/bin/drush" state:set system.maintenance_mode 1 --input-format=integer
    "$CURRENT_LINK/vendor/bin/drush" cr
    log "Maintenance mode enabled on active release."
  else
    log "WARNING: Drush not available on active release. Skipping DB backup and maintenance mode activation."
  fi
else
  log "No current release detected. Skipping DB backup and maintenance mode activation."
fi

ln -sfn "$NEW_RELEASE" "$CURRENT_LINK"
log "Current symlink switched to new release."

"$CURRENT_LINK/vendor/bin/drush" updb -y
"$CURRENT_LINK/vendor/bin/drush" cim -y
"$CURRENT_LINK/vendor/bin/drush" cr
log "Drupal update, config import and cache rebuild completed."

"$CURRENT_LINK/vendor/bin/drush" state:set system.maintenance_mode 0 --input-format=integer
"$CURRENT_LINK/vendor/bin/drush" cr
log "Maintenance mode disabled on new release."

mapfile -t all_releases < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
if (( ${#all_releases[@]} > 3 )); then
  for release in "${all_releases[@]:3}"; do
    release_path="$RELEASES_DIR/$release"
    if [[ "$(readlink -f "$CURRENT_LINK")" != "$(readlink -f "$release_path")" ]]; then
      rm -rf "$release_path"
      log "Old release removed: ${release_path}"
    fi
  done
fi

mapfile -t all_backups < <(find "$BACKUPS_DIR" -maxdepth 1 -type f -name 'db-*.sql.gz' -printf '%f\n' | sort -r)
if (( ${#all_backups[@]} > 10 )); then
  for backup in "${all_backups[@]:10}"; do
    rm -f "$BACKUPS_DIR/$backup"
    log "Old backup removed: $BACKUPS_DIR/$backup"
  done
fi

DEPLOY_STATUS="success"
log "Deployment completed successfully."
log "Deployment metadata: timestamp=${TIMESTAMP} branch=${BRANCH} commit=${COMMIT_HASH} release=${NEW_RELEASE} user=${DEPLOY_USER} status=${DEPLOY_STATUS}"
