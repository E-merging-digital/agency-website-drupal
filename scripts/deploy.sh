#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${PROJECT_ROOT:-/var/www/agency}"
RELEASES_DIR="${RELEASES_DIR:-$PROJECT_ROOT/releases}"
SHARED_DIR="${SHARED_DIR:-$PROJECT_ROOT/shared}"
REPO_URL="${REPO_URL:-git@github.com:E-merging-digital/agency-website-drupal.git}"
BRANCH="${BRANCH:-main}"
KEEP_RELEASES="${KEEP_RELEASES:-3}"
RUN_NGINX_RELOAD="${RUN_NGINX_RELOAD:-0}"

TIMESTAMP="$(date +%Y%m%d%H%M%S)"
NEW_RELEASE="$RELEASES_DIR/$TIMESTAMP"

log() {
  printf '[deploy] %s\n' "$*"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "Missing command: $1" >&2; exit 1; }
}

require_cmd git
require_cmd composer
require_cmd php

if [[ "${REPO_URL}" == *"<org>/<repo>"* ]]; then
  echo "REPO_URL invalide: ${REPO_URL}" >&2
  exit 1
fi

log "User: $(whoami)"
log "Branch: $BRANCH"
log "Repository: $REPO_URL"
log "Release: $NEW_RELEASE"

mkdir -p "$RELEASES_DIR" "$SHARED_DIR/files" "$SHARED_DIR/private" "$SHARED_DIR/settings"

if [[ ! -f "$SHARED_DIR/settings/settings.php" ]]; then
  echo "Fichier manquant: $SHARED_DIR/settings/settings.php" >&2
  exit 1
fi

log "Validation accès GitHub"
ssh -T git@github.com || true
git ls-remote "$REPO_URL" -h "refs/heads/$BRANCH" >/dev/null

log "Clone release"
git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$NEW_RELEASE"

cd "$NEW_RELEASE"
log "Composer install"
composer install --no-dev --optimize-autoloader

log "Liens partagés"
rm -rf "$NEW_RELEASE/web/sites/default/files"
ln -sfn "$SHARED_DIR/files" "$NEW_RELEASE/web/sites/default/files"
rm -f "$NEW_RELEASE/web/sites/default/settings.php"
ln -sfn "$SHARED_DIR/settings/settings.php" "$NEW_RELEASE/web/sites/default/settings.php"

log "Bascule current"
ln -sfn "$NEW_RELEASE" "$PROJECT_ROOT/current"
cd "$PROJECT_ROOT/current"

log "Drush post-deploy"
vendor/bin/drush updb -y
vendor/bin/drush cim -y
vendor/bin/drush cr

if [[ "$RUN_NGINX_RELOAD" == "1" ]]; then
  log "Reload nginx"
  sudo systemctl reload nginx
fi

log "Nettoyage anciennes releases"
cd "$RELEASES_DIR"
ls -1dt */ | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -rf

log "Déploiement terminé"
