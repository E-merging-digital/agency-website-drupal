#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

EXPECTED_SHA="${1:-}"
EXPECTED_ARTIFACT_SHA256="${2:-}"
EXPECTED_COMPOSER_LOCK_SHA256="${3:-}"
AUTH_COMMENT_ID="${4:-}"
AUTH_BODY_SHA256="${5:-}"
REQUEST_DIR="${6:-}"

PROJECT_ROOT="/var/www/agency"
RELEASES_DIR="$PROJECT_ROOT/releases"
SHARED_DIR="$PROJECT_ROOT/shared"
CURRENT_LINK="$PROJECT_ROOT/current"
SETTINGS_FILE="$SHARED_DIR/settings/settings.php"
BACKUPS_DIR="$SHARED_DIR/backups"
ARTIFACTS_DIR="$SHARED_DIR/artifacts"
PROMOTIONS_DIR="$SHARED_DIR/promotions"
LOG_FILE="$SHARED_DIR/deployments.log"
TIMESTAMP="$(date -u '+%Y%m%d%H%M%S')"
NEW_RELEASE="$RELEASES_DIR/${TIMESTAMP}-${EXPECTED_SHA:0:12}"
PAYLOAD="$REQUEST_DIR/agency-release-candidate.tar.gz"
CHECKSUM="$REQUEST_DIR/agency-release-candidate.tar.gz.sha256"
METADATA="$REQUEST_DIR/candidate.json"
EVIDENCE="$REQUEST_DIR/promotion-evidence.env"
RECEIPT="$PROMOTIONS_DIR/${EXPECTED_SHA}-${EXPECTED_ARTIFACT_SHA256}.env"
ACTIVE_RELEASE=""
DB_BACKUP=""
MAINTENANCE_ENABLED=0
SWITCH_COMPLETED=0

log() { printf '[prod-promote] %s\n' "$1"; }
fail() { printf '[prod-promote] ERROR: %s\n' "$1" >&2; exit 1; }

fail_trap() {
  local exit_code="$?"
  local line_no="${1:-unknown}"
  if (( BASH_SUBSHELL > 0 )); then trap - ERR; return "$exit_code"; fi
  trap - ERR
  set +e
  log "Promotion failed at line ${line_no} (exit ${exit_code})."
  if [[ "$MAINTENANCE_ENABLED" -eq 1 ]]; then
    if [[ "$SWITCH_COMPLETED" -eq 1 && -x "$CURRENT_LINK/vendor/bin/drush" ]]; then
      (cd "$CURRENT_LINK" && vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer) || true
    elif [[ -n "$ACTIVE_RELEASE" && -x "$ACTIVE_RELEASE/vendor/bin/drush" ]]; then
      (cd "$ACTIVE_RELEASE" && vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer) || true
    fi
  fi
  if [[ "$SWITCH_COMPLETED" -eq 0 && -d "$NEW_RELEASE" ]]; then rm -rf "$NEW_RELEASE" || true; fi
  log "No automatic database rollback is attempted after schema/config mutation."
  exit "$exit_code"
}
trap 'fail_trap $LINENO' ERR

[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "Expected SHA is invalid."
[[ "$EXPECTED_ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "Artifact digest is invalid."
[[ "$EXPECTED_COMPOSER_LOCK_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "Composer lock digest is invalid."
[[ "$AUTH_COMMENT_ID" =~ ^[0-9]+$ ]] || fail "Authorization comment id is invalid."
[[ "$AUTH_BODY_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "Authorization body digest is invalid."
[[ -d "$REQUEST_DIR" && ! -L "$REQUEST_DIR" ]] || fail "Request directory is invalid."
[[ -f "$PAYLOAD" && -f "$CHECKSUM" && -f "$METADATA" ]] || fail "Candidate files are incomplete."
[[ -f "$SETTINGS_FILE" ]] || fail "Production shared settings are unavailable."
command -v jq >/dev/null 2>&1 || fail "jq is required."
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is required."
command -v crontab >/dev/null 2>&1 || fail "crontab is required for controlled Drupal scheduling."
command -v flock >/dev/null 2>&1 || fail "flock is required for controlled Drupal scheduling."

install -d -m 750 "$RELEASES_DIR" "$BACKUPS_DIR" "$ARTIFACTS_DIR" "$PROMOTIONS_DIR"
touch "$LOG_FILE"
[[ ! -e "$RECEIPT" ]] || fail "This exact candidate artifact already has a successful production promotion receipt."

candidate_sha="$(jq -r '.candidate_sha' "$METADATA")"
artifact_sha="$(jq -r '.artifact_sha256' "$METADATA")"
composer_lock_sha="$(jq -r '.composer_lock_sha256' "$METADATA")"
source_branch="$(jq -r '.source_branch' "$METADATA")"
[[ "$candidate_sha" == "$EXPECTED_SHA" ]] || fail "candidate.json SHA mismatch."
[[ "$artifact_sha" == "$EXPECTED_ARTIFACT_SHA256" ]] || fail "candidate.json artifact digest mismatch."
[[ "$composer_lock_sha" == "$EXPECTED_COMPOSER_LOCK_SHA256" ]] || fail "candidate.json Composer lock digest mismatch."
[[ "$source_branch" == release/* ]] || fail "Candidate did not originate from release/*."
[[ "$(sha256sum "$PAYLOAD" | awk '{print $1}')" == "$EXPECTED_ARTIFACT_SHA256" ]] || fail "Payload digest mismatch."
(cd "$REQUEST_DIR" && sha256sum -c "$(basename "$CHECKSUM")")

archive_dir="$ARTIFACTS_DIR/$EXPECTED_SHA/$EXPECTED_ARTIFACT_SHA256"
install -d -m 750 "$archive_dir"
for candidate_file in "$PAYLOAD" "$CHECKSUM" "$METADATA"; do
  archive_file="$archive_dir/$(basename "$candidate_file")"
  if [[ -e "$archive_file" ]]; then cmp -s "$candidate_file" "$archive_file" || fail "Archived immutable candidate differs."; else install -m 640 "$candidate_file" "$archive_file"; fi
done

[[ ! -e "$NEW_RELEASE" ]] || fail "Release directory already exists."
install -d -m 750 "$NEW_RELEASE"
tar -xzf "$PAYLOAD" -C "$NEW_RELEASE"
[[ -x "$NEW_RELEASE/vendor/bin/drush" ]] || fail "Candidate does not contain Drush."
[[ -f "$NEW_RELEASE/web/index.php" ]] || fail "Candidate does not contain web/index.php."
[[ -f "$NEW_RELEASE/composer.lock" ]] || fail "Candidate does not contain composer.lock."
[[ ! -e "$NEW_RELEASE/web/sites/default/settings.php" ]] || fail "Candidate contains environment settings."
[[ -x "$NEW_RELEASE/scripts/production-promotion/reconcile-cron.sh" ]] || fail "Candidate does not contain the controlled scheduler reconciler."
[[ "$(sha256sum "$NEW_RELEASE/composer.lock" | awk '{print $1}')" == "$EXPECTED_COMPOSER_LOCK_SHA256" ]] || fail "Extracted composer.lock digest mismatch."

rm -rf "$NEW_RELEASE/web/sites/default/files"
ln -s "$SHARED_DIR/files" "$NEW_RELEASE/web/sites/default/files"
ln -s "$SETTINGS_FILE" "$NEW_RELEASE/web/sites/default/settings.php"
chmod a+rx "$NEW_RELEASE"
find "$NEW_RELEASE/vendor" "$NEW_RELEASE/web" -xdev -type d -exec chmod a+rx {} +
find "$NEW_RELEASE/vendor" "$NEW_RELEASE/web" -xdev -type f -exec chmod a+r {} +

[[ -L "$CURRENT_LINK" ]] || fail "Production current release symlink is missing."
ACTIVE_RELEASE="$(readlink -f "$CURRENT_LINK")"
[[ -n "$ACTIVE_RELEASE" && -x "$ACTIVE_RELEASE/vendor/bin/drush" ]] || fail "Active production release is not healthy enough for governed promotion."
log "Preflight active production Drupal and database."
(cd "$ACTIVE_RELEASE" && vendor/bin/drush status --fields=bootstrap >/dev/null && vendor/bin/drush sql:query 'SELECT 1' >/dev/null)

CONFIG_SYNC_CONVERGER="$NEW_RELEASE/scripts/production-settings/converge-config-sync-directory.sh"
[[ -f "$CONFIG_SYNC_CONVERGER" && -r "$CONFIG_SYNC_CONVERGER" ]] || fail "Exact candidate config sync converger is unavailable or unreadable."
bash -n "$CONFIG_SYNC_CONVERGER"
log "Converge deterministic production config sync setting from exact candidate bytes."
bash "$CONFIG_SYNC_CONVERGER" "$SETTINGS_FILE"

DB_BACKUP_BASE="$BACKUPS_DIR/db-${TIMESTAMP}-${EXPECTED_SHA:0:12}.sql"
log "Create pre-promotion database backup."
(cd "$ACTIVE_RELEASE" && vendor/bin/drush sql:dump --gzip --result-file="$DB_BACKUP_BASE")
DB_BACKUP="${DB_BACKUP_BASE}.gz"
[[ -s "$DB_BACKUP" ]] || fail "Database backup was not created."

log "Enable maintenance mode on the active release."
(cd "$ACTIVE_RELEASE" && vendor/bin/drush state:set system.maintenance_mode 1 --input-format=integer)
MAINTENANCE_ENABLED=1
(cd "$NEW_RELEASE" && vendor/bin/drush status --fields=bootstrap >/dev/null)

log "Switch current to exact verified candidate bytes."
ln -sfn "$NEW_RELEASE" "$CURRENT_LINK"
SWITCH_COMPLETED=1
[[ "$(readlink -f "$CURRENT_LINK")" == "$(readlink -f "$NEW_RELEASE")" ]] || fail "current symlink did not switch."

"$CURRENT_LINK/vendor/bin/drush" updb -y
"$CURRENT_LINK/vendor/bin/drush" cim -y
production_split="$CURRENT_LINK/config/splits/production"
[[ -d "$production_split" ]] || fail "Production config split directory is missing."
"$CURRENT_LINK/vendor/bin/drush" config:import --source="$production_split" --partial -y
"$CURRENT_LINK/vendor/bin/drush" emerging:governed-content:validate
"$CURRENT_LINK/vendor/bin/drush" emerging:governed-content --all --dry-run
"$CURRENT_LINK/vendor/bin/drush" emerging:governed-content --all
"$CURRENT_LINK/vendor/bin/drush" cr
"$CURRENT_LINK/vendor/bin/drush" state:set system.maintenance_mode 0 --input-format=integer
MAINTENANCE_ENABLED=0

log "Reconcile the single controlled PROD Drupal cron scheduler."
"$CURRENT_LINK/scripts/production-promotion/reconcile-cron.sh"

{
  printf 'schema_version=2\n'
  printf 'candidate_sha=%s\n' "$EXPECTED_SHA"
  printf 'artifact_sha256=%s\n' "$EXPECTED_ARTIFACT_SHA256"
  printf 'composer_lock_sha256=%s\n' "$EXPECTED_COMPOSER_LOCK_SHA256"
  printf 'source_branch=%s\n' "$source_branch"
  printf 'authorization_comment_id=%s\n' "$AUTH_COMMENT_ID"
  printf 'authorization_body_sha256=%s\n' "$AUTH_BODY_SHA256"
  printf 'release_path=%s\n' "$NEW_RELEASE"
  printf 'previous_release=%s\n' "$ACTIVE_RELEASE"
  printf 'database_backup=%s\n' "$DB_BACKUP"
  printf 'artifact_archive=%s\n' "$archive_dir"
  printf 'governed_content=PASS\n'
  printf 'production_scheduler=DEPLOY_USER_CRONTAB\n'
  printf 'production_scheduler_entries=1\n'
  printf 'rollback_boundary=PREVIOUS_RELEASE_PLUS_DB_BACKUP\n'
  printf 'deployed_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "$EVIDENCE"
chmod 640 "$EVIDENCE"

receipt_tmp="${RECEIPT}.tmp.$$"
cp "$EVIDENCE" "$receipt_tmp"
chmod 640 "$receipt_tmp"
mv -f "$receipt_tmp" "$RECEIPT"

printf '[%s] PROMOTION_SUCCESS | %s | %s | %s | previous=%s | backup=%s\n' \
  "$(date '+%Y-%m-%d %H:%M:%S')" "$EXPECTED_SHA" "$EXPECTED_ARTIFACT_SHA256" "$NEW_RELEASE" "$ACTIVE_RELEASE" "$DB_BACKUP" >> "$LOG_FILE"

mapfile -t releases < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
if (( ${#releases[@]} > 3 )); then
  for release in "${releases[@]:3}"; do
    release_path="$RELEASES_DIR/$release"
    if [[ "$(readlink -f "$CURRENT_LINK")" != "$(readlink -f "$release_path")" && "$(readlink -f "$ACTIVE_RELEASE")" != "$(readlink -f "$release_path")" ]]; then rm -rf "$release_path" || log "Warning: stale release retention cleanup failed for $release_path."; fi
  done
fi

mapfile -t backups < <(find "$BACKUPS_DIR" -maxdepth 1 -type f -name 'db-*.sql.gz' -printf '%f\n' | sort -r)
if (( ${#backups[@]} > 10 )); then
  for backup in "${backups[@]:10}"; do [[ "$BACKUPS_DIR/$backup" == "$DB_BACKUP" ]] || rm -f "$BACKUPS_DIR/$backup" || true; done
fi

log "Exact immutable candidate promoted successfully without rebuild."
