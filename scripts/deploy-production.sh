#!/usr/bin/env bash
set -Eeuo pipefail

BRANCH="${1:-main}"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"

PROJECT_ROOT="/var/www/agency"
RELEASES_DIR="/var/www/agency/releases"
SHARED_DIR="/var/www/agency/shared"
BACKUPS_DIR="$SHARED_DIR/backups"
LOG_FILE="$SHARED_DIR/deployments.log"
REPO_URL="${REPO_URL:-git@github.com:E-merging-digital/agency-website-drupal.git}"
CURRENT_LINK="$PROJECT_ROOT/current"
NEW_RELEASE="$RELEASES_DIR/$TIMESTAMP"
ACTIVE_RELEASE=""
DEPLOY_USER="$(id -un)"
FILES_OWNER="deploy"
GIT_COMMIT="unknown"
MAINTENANCE_ENABLED=0
SHARED_FILES_DIR="$SHARED_DIR/files"
RELEASE_FILES_LINK="$NEW_RELEASE/web/sites/default/files"

log() {
  local message="$1"
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$message"
}

log_file() {
  local status="$1"
  local message="$2"
  printf '[%s] %s | %s | %s | %s | %s\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" \
    "$status" \
    "$BRANCH" \
    "$GIT_COMMIT" \
    "$NEW_RELEASE" \
    "$message" >> "$LOG_FILE"
}

fail_trap() {
  local exit_code="$?"
  local line_no="${1:-unknown}"

  log "[deploy] ERROR at line ${line_no} (exit ${exit_code})"
  log_file "FAILURE" "Deployment failed at line ${line_no} (exit ${exit_code})"

  if [[ "$MAINTENANCE_ENABLED" -eq 1 ]] && [[ -x "$CURRENT_LINK/vendor/bin/drush" ]]; then
    log "[deploy] Attempting Maintenance OFF after failure"
    "$CURRENT_LINK/vendor/bin/drush" state:set system.maintenance_mode 0 --input-format=integer || true
    "$CURRENT_LINK/vendor/bin/drush" cr || true
  fi

  log "Deployment failed. Previous release kept intact."
  exit "$exit_code"
}

trap 'fail_trap $LINENO' ERR

prepare_public_files() {
  log "[deploy] Public files symlink"

  mkdir -p "$SHARED_FILES_DIR"

  if [[ -e "$RELEASE_FILES_LINK" && ! -L "$RELEASE_FILES_LINK" ]]; then
    rm -rf "$RELEASE_FILES_LINK"
  fi

  ln -sfn "$SHARED_FILES_DIR" "$RELEASE_FILES_LINK"
  if [[ "$(id -u)" -eq 0 ]]; then
    chown -R "${FILES_OWNER}:www-data" "$SHARED_FILES_DIR"
    chmod -R ug+rwX "$SHARED_FILES_DIR"
    find "$SHARED_FILES_DIR" -type d -exec chmod g+s {} +
  else
    chgrp www-data "$SHARED_FILES_DIR"
    chmod ug+rwX "$SHARED_FILES_DIR"
    chmod g+s "$SHARED_FILES_DIR"
  fi

  if [[ ! -L "$RELEASE_FILES_LINK" ]]; then
    log "ERROR: ${RELEASE_FILES_LINK} is not a symlink."
    exit 1
  fi

  local expected_target
  local actual_target
  expected_target="$(readlink -f "$SHARED_FILES_DIR")"
  actual_target="$(readlink -f "$RELEASE_FILES_LINK")"
  if [[ "$actual_target" != "$expected_target" ]]; then
    log "ERROR: ${RELEASE_FILES_LINK} points to ${actual_target}, expected ${expected_target}."
    exit 1
  fi

  if [[ "$(stat -c '%G' "$SHARED_FILES_DIR")" != "www-data" ]]; then
    log "ERROR: ${SHARED_FILES_DIR} group is not www-data."
    exit 1
  fi

  if [[ "$(stat -c '%U' "$SHARED_FILES_DIR")" != "$FILES_OWNER" ]]; then
    log "ERROR: ${SHARED_FILES_DIR} owner is not ${FILES_OWNER}."
    exit 1
  fi

  local shared_files_mode
  shared_files_mode="$(stat -c '%a' "$SHARED_FILES_DIR")"
  if (( ((10#$shared_files_mode / 10) % 10 & 2) == 0 )); then
    log "ERROR: ${SHARED_FILES_DIR} is not group-writable."
    exit 1
  fi

  log "Public files ready: ${RELEASE_FILES_LINK} -> ${SHARED_FILES_DIR}."
}

if [[ -z "$REPO_URL" ]] || [[ "$REPO_URL" == *"<org>/<repo>"* ]]; then
  echo "REPO_URL invalide: $REPO_URL" >&2
  exit 1
fi

mkdir -p "$RELEASES_DIR" "$SHARED_DIR" "$BACKUPS_DIR" "$(dirname "$LOG_FILE")"
touch "$LOG_FILE"

log "[deploy] START branch=${BRANCH}"
log_file "START" "Deployment started"
log "[deploy] Prepare release ${NEW_RELEASE}"

if [[ -e "$NEW_RELEASE" ]]; then
  log "ERROR: Release directory already exists: ${NEW_RELEASE}"
  exit 1
fi

mkdir -p "$NEW_RELEASE"

log "[deploy] Clone"
git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$NEW_RELEASE"
GIT_COMMIT="$(git -C "$NEW_RELEASE" rev-parse HEAD)"
log "Repository cloned at commit ${GIT_COMMIT}."

log "[deploy] Composer"
composer --working-dir="$NEW_RELEASE" install --no-dev --optimize-autoloader

mkdir -p "$SHARED_DIR/private" "$SHARED_DIR/settings"
prepare_public_files
ln -sfn "$SHARED_DIR/settings/settings.php" "$NEW_RELEASE/web/sites/default/settings.php"
log "Shared symlinks created for files and settings.php."

if [[ -L "$CURRENT_LINK" ]]; then
  ACTIVE_RELEASE="$(readlink -f "$CURRENT_LINK")"
  log "Active release detected: ${ACTIVE_RELEASE}"

  if [[ -x "$CURRENT_LINK/vendor/bin/drush" ]]; then
    DB_BACKUP="$BACKUPS_DIR/db-${TIMESTAMP}.sql.gz"
    log "[deploy] Backup DB"
    (
      cd "$CURRENT_LINK"
      vendor/bin/drush sql:dump --gzip --result-file="$DB_BACKUP"
    )
    log "Database backup created: ${DB_BACKUP}"
  else
    log "WARNING: Drush not available on active release. Skipping DB backup."
  fi

  if [[ -x "$CURRENT_LINK/vendor/bin/drush" ]]; then
    log "[deploy] Maintenance ON"
    "$CURRENT_LINK/vendor/bin/drush" state:set system.maintenance_mode 1 --input-format=integer
    "$CURRENT_LINK/vendor/bin/drush" cr
    MAINTENANCE_ENABLED=1
  fi
else
  log "No current release detected."
  log_file "START" "no previous release, no backup"
fi

if [[ -x "$NEW_RELEASE/vendor/bin/drush" ]]; then
  "$NEW_RELEASE/vendor/bin/drush" status >/dev/null
fi

log "[deploy] Switch release"
ln -sfn "$NEW_RELEASE" "$CURRENT_LINK"

"$CURRENT_LINK/vendor/bin/drush" updb -y
"$CURRENT_LINK/vendor/bin/drush" cim -y
PRODUCTION_SPLIT_DIR="$CURRENT_LINK/config/splits/production"
if [[ ! -d "$PRODUCTION_SPLIT_DIR" ]]; then
  log "ERROR: Production config split directory not found: ${PRODUCTION_SPLIT_DIR}"
  exit 1
fi
log "[deploy] Production Config Split"
"$CURRENT_LINK/vendor/bin/drush" config:import --source="$PRODUCTION_SPLIT_DIR" --partial -y
log "[deploy] Content Sync"
"$CURRENT_LINK/vendor/bin/drush" emerging:content-sync --all
"$CURRENT_LINK/vendor/bin/drush" cr
log "Drupal update, config import, production config split import, content sync and cache rebuild completed."

log "[deploy] Maintenance OFF"
"$CURRENT_LINK/vendor/bin/drush" state:set system.maintenance_mode 0 --input-format=integer
"$CURRENT_LINK/vendor/bin/drush" cr
MAINTENANCE_ENABLED=0

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

log "[deploy] SUCCESS"
log_file "SUCCESS" "Deployment completed successfully"
