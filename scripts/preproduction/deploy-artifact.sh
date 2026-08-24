#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

CANDIDATE_SHA="${1:-}"
EXPECTED_ARTIFACT_SHA256="${2:-}"
EXPECTED_COMPOSER_LOCK_SHA256="${3:-}"
PAYLOAD="${4:-}"
METADATA="${5:-}"

PROJECT_ROOT="${PROJECT_ROOT:-/var/www/agency-preprod}"
RELEASES_DIR="$PROJECT_ROOT/releases"
SHARED_DIR="$PROJECT_ROOT/shared"
BACKUPS_DIR="$SHARED_DIR/backups"
CURRENT_LINK="$PROJECT_ROOT/current"
TIMESTAMP="$(date -u +%Y%m%d%H%M%S)"
RELEASE_DIR="$RELEASES_DIR/${TIMESTAMP}-${CANDIDATE_SHA:0:12}"
ACTIVE_RELEASE=""
MAINTENANCE_ENABLED=0
SWITCH_COMPLETED=0

[[ "$CANDIDATE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "Invalid candidate SHA." >&2; exit 2; }
[[ "$EXPECTED_ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo "Invalid artifact digest." >&2; exit 2; }
[[ "$EXPECTED_COMPOSER_LOCK_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo "Invalid composer.lock digest." >&2; exit 2; }
[[ -f "$PAYLOAD" && ! -L "$PAYLOAD" ]] || { echo "Candidate payload missing or unsafe." >&2; exit 2; }
[[ -f "$METADATA" && ! -L "$METADATA" ]] || { echo "Candidate metadata missing or unsafe." >&2; exit 2; }

for command in jq sha256sum tar flock openssl; do
  command -v "$command" >/dev/null 2>&1 || { echo "Missing command: $command" >&2; exit 2; }
done

log() { printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$1"; }

fail_trap() {
  local rc="$?"
  local line="${1:-unknown}"
  trap - ERR
  set +e
  log "ERROR line=$line exit=$rc"
  if [[ "$SWITCH_COMPLETED" -eq 0 ]]; then
    rm -rf "$RELEASE_DIR"
  elif [[ -n "$ACTIVE_RELEASE" && -d "$ACTIVE_RELEASE" ]]; then
    ln -sfn "$ACTIVE_RELEASE" "$CURRENT_LINK"
    log "Code symlink restored to previous release; database rollback is intentionally not automatic."
  fi
  if [[ "$MAINTENANCE_ENABLED" -eq 1 && -n "$ACTIVE_RELEASE" && -x "$ACTIVE_RELEASE/vendor/bin/drush" ]]; then
    (cd "$ACTIVE_RELEASE" && vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer) || true
  fi
  exit "$rc"
}
trap 'fail_trap $LINENO' ERR

actual_artifact_sha256="$(sha256sum "$PAYLOAD" | awk '{print $1}')"
[[ "$actual_artifact_sha256" == "$EXPECTED_ARTIFACT_SHA256" ]] || { echo "Artifact digest mismatch." >&2; exit 1; }

metadata_sha="$(jq -r '.candidate_sha // empty' "$METADATA")"
metadata_artifact="$(jq -r '.artifact_sha256 // empty' "$METADATA")"
metadata_lock="$(jq -r '.composer_lock_sha256 // empty' "$METADATA")"
metadata_branch="$(jq -r '.source_branch // empty' "$METADATA")"
[[ "$metadata_sha" == "$CANDIDATE_SHA" ]] || { echo "Metadata candidate SHA mismatch." >&2; exit 1; }
[[ "$metadata_artifact" == "$EXPECTED_ARTIFACT_SHA256" ]] || { echo "Metadata artifact digest mismatch." >&2; exit 1; }
[[ "$metadata_lock" == "$EXPECTED_COMPOSER_LOCK_SHA256" ]] || { echo "Metadata composer.lock digest mismatch." >&2; exit 1; }
[[ "$metadata_branch" == release/* ]] || { echo "Candidate source branch is not release/*." >&2; exit 1; }

mkdir -p "$RELEASES_DIR" "$BACKUPS_DIR"
[[ ! -e "$RELEASE_DIR" ]] || { echo "Release already exists: $RELEASE_DIR" >&2; exit 1; }
mkdir "$RELEASE_DIR"
tar -xzf "$PAYLOAD" -C "$RELEASE_DIR"
[[ -f "$RELEASE_DIR/composer.lock" ]] || { echo "composer.lock missing from candidate." >&2; exit 1; }
[[ -f "$RELEASE_DIR/vendor/autoload.php" ]] || { echo "vendor/autoload.php missing; PREPROD must not rebuild dependencies." >&2; exit 1; }
[[ -f "$RELEASE_DIR/web/index.php" ]] || { echo "Drupal entrypoint missing." >&2; exit 1; }
[[ "$(sha256sum "$RELEASE_DIR/composer.lock" | awk '{print $1}')" == "$EXPECTED_COMPOSER_LOCK_SHA256" ]] || { echo "Extracted composer.lock digest mismatch." >&2; exit 1; }
cp "$METADATA" "$RELEASE_DIR/.agency-candidate.json"
chmod 0644 "$RELEASE_DIR/.agency-candidate.json"

rm -rf "$RELEASE_DIR/web/sites/default/files"
ln -s "$SHARED_DIR/files" "$RELEASE_DIR/web/sites/default/files"
rm -f "$RELEASE_DIR/web/sites/default/settings.php"
ln -s "$SHARED_DIR/settings/settings.php" "$RELEASE_DIR/web/sites/default/settings.php"
find "$RELEASE_DIR" -xdev -type d -exec chmod a+rx {} +
find "$RELEASE_DIR" -xdev -type f -exec chmod a+r {} +

if [[ -L "$CURRENT_LINK" ]]; then
  ACTIVE_RELEASE="$(readlink -f "$CURRENT_LINK")"
  [[ -d "$ACTIVE_RELEASE" ]] || { echo "Current release link is invalid." >&2; exit 1; }
  if [[ -x "$ACTIVE_RELEASE/vendor/bin/drush" ]]; then
    backup="$BACKUPS_DIR/db-${TIMESTAMP}.sql.gz"
    log "Backup current PREPROD database"
    (cd "$ACTIVE_RELEASE" && vendor/bin/drush sql:dump --gzip --result-file="$backup")
    log "Enable PREPROD maintenance mode"
    (cd "$ACTIVE_RELEASE" && vendor/bin/drush state:set system.maintenance_mode 1 --input-format=integer)
    MAINTENANCE_ENABLED=1
  fi
fi

# First real PREPROD deployment uses the dedicated empty database plus committed
# configuration. Subsequent deployments require the existing Drupal key_value
# table to remain queryable. The test is performed through Drupal's own database
# settings so no DB credential is exposed to this script or its logs.
if ! (cd "$RELEASE_DIR" && vendor/bin/drush sql:query 'SELECT 1 FROM key_value LIMIT 1' >/dev/null 2>&1); then
  if [[ -n "$ACTIVE_RELEASE" ]]; then
    echo "Existing PREPROD database is not Drupal-queryable; refusing destructive re-install." >&2
    exit 1
  fi
  log "First PREPROD install from existing config"
  ADMIN_PASSWORD="$(openssl rand -hex 18)"
  (cd "$RELEASE_DIR" && vendor/bin/drush site:install --existing-config -y \
    --account-name=preprod-admin --account-pass="$ADMIN_PASSWORD")
  printf 'username=preprod-admin\npassword=%s\n' "$ADMIN_PASSWORD" > "$SHARED_DIR/settings/preprod-admin.env"
  chmod 0600 "$SHARED_DIR/settings/preprod-admin.env"
fi

log "Switch candidate into PREPROD current"
ln -sfn "$RELEASE_DIR" "$CURRENT_LINK"
SWITCH_COMPLETED=1
[[ "$(readlink -f "$CURRENT_LINK")" == "$(readlink -f "$RELEASE_DIR")" ]] || { echo "Release switch failed." >&2; exit 1; }

cd "$CURRENT_LINK"
log "Drupal updb"
vendor/bin/drush updb -y
log "Drupal cim"
vendor/bin/drush cim -y
PREPROD_SPLIT="$CURRENT_LINK/config/splits/preproduction"
[[ -d "$PREPROD_SPLIT" ]] || { echo "PREPROD split directory missing." >&2; exit 1; }
log "PREPROD config split import"
vendor/bin/drush config:import --source="$PREPROD_SPLIT" --partial -y
log "Drupal cache rebuild"
vendor/bin/drush cr

# Fail closed on the side-effect contract before declaring the candidate live.
mail_scheme="$(vendor/bin/drush config:get system.mail mailer_dsn.scheme --format=string)"
mail_host="$(vendor/bin/drush config:get system.mail mailer_dsn.host --format=string)"
mail_port="$(vendor/bin/drush config:get system.mail mailer_dsn.port --format=string)"
[[ "$mail_scheme" == "smtp" && "$mail_host" == "127.0.0.1" && "$mail_port" == "1025" ]] || { echo "PREPROD mail is not isolated to the local sink." >&2; exit 1; }
analytics_id="$(vendor/bin/drush config:get google_tag.settings default_google_tag_entity --format=string 2>/dev/null || true)"
[[ -z "$analytics_id" ]] || { echo "Production analytics remains enabled in PREPROD." >&2; exit 1; }

if [[ "$MAINTENANCE_ENABLED" -eq 1 ]]; then
  vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer
  MAINTENANCE_ENABLED=0
fi

mapfile -t releases < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
if (( ${#releases[@]} > 3 )); then
  for old in "${releases[@]:3}"; do
    old_path="$RELEASES_DIR/$old"
    [[ "$(readlink -f "$CURRENT_LINK")" == "$(readlink -f "$old_path")" ]] || rm -rf "$old_path"
  done
fi
mapfile -t backups < <(find "$BACKUPS_DIR" -maxdepth 1 -type f -name 'db-*.sql.gz' -printf '%f\n' | sort -r)
if (( ${#backups[@]} > 10 )); then
  for old in "${backups[@]:10}"; do rm -f "$BACKUPS_DIR/$old"; done
fi

printf '[%s] SUCCESS | sha=%s | artifact_sha256=%s | composer_lock_sha256=%s | release=%s\n' \
  "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$CANDIDATE_SHA" "$EXPECTED_ARTIFACT_SHA256" "$EXPECTED_COMPOSER_LOCK_SHA256" "$RELEASE_DIR" >> "$SHARED_DIR/deployments.log"

cat <<EOF_RESULT
PREPROD_DEPLOY=PASS
candidate_sha=$CANDIDATE_SHA
artifact_sha256=$EXPECTED_ARTIFACT_SHA256
composer_lock_sha256=$EXPECTED_COMPOSER_LOCK_SHA256
release_dir=$RELEASE_DIR
EOF_RESULT
