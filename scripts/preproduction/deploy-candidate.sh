#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

EXPECTED_SHA="${1:-}"
EXPECTED_ARTIFACT_SHA256="${2:-}"
REQUEST_DIR="${3:-}"
PROJECT_ROOT="/var/www/agency-preprod"
RELEASES_DIR="$PROJECT_ROOT/releases"
SHARED_DIR="$PROJECT_ROOT/shared"
CURRENT_LINK="$PROJECT_ROOT/current"
RUNTIME_ENV="$SHARED_DIR/settings/runtime.env"
SETTINGS_FILE="$SHARED_DIR/settings/settings.php"
BACKUPS_DIR="$SHARED_DIR/backups"
ARTIFACTS_DIR="$SHARED_DIR/artifacts"
TIMESTAMP="$(date -u '+%Y%m%d%H%M%S')"
NEW_RELEASE="$RELEASES_DIR/${TIMESTAMP}-${EXPECTED_SHA:0:12}"
PAYLOAD="$REQUEST_DIR/agency-release-candidate.tar.gz"
CHECKSUM="$REQUEST_DIR/agency-release-candidate.tar.gz.sha256"
METADATA="$REQUEST_DIR/candidate.json"
EVIDENCE="$REQUEST_DIR/deployment-evidence.env"
ACTIVE_RELEASE=""
MAINTENANCE_ENABLED=0
SWITCH_COMPLETED=0

log() {
  printf '[preprod-deploy] %s\n' "$1"
}

fail() {
  printf '[preprod-deploy] ERROR: %s\n' "$1" >&2
  exit 1
}

fail_trap() {
  local exit_code="$?"
  local line_no="${1:-unknown}"

  if (( BASH_SUBSHELL > 0 )); then
    trap - ERR
    return "$exit_code"
  fi

  trap - ERR
  set +e
  log "Deployment failed at line ${line_no} (exit ${exit_code})."

  if [[ "$MAINTENANCE_ENABLED" -eq 1 ]]; then
    recovery_release="$ACTIVE_RELEASE"
    if [[ "$SWITCH_COMPLETED" -eq 1 && -x "$CURRENT_LINK/vendor/bin/drush" ]]; then
      recovery_release="$CURRENT_LINK"
    fi
    if [[ -n "$recovery_release" && -x "$recovery_release/vendor/bin/drush" ]]; then
      (
        cd "$recovery_release"
        vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer
      ) || true
    fi
  fi

  if [[ "$SWITCH_COMPLETED" -eq 0 && -d "$NEW_RELEASE" ]]; then
    rm -rf "$NEW_RELEASE" || true
  fi

  exit "$exit_code"
}

trap 'fail_trap $LINENO' ERR

[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "Expected SHA is invalid."
[[ "$EXPECTED_ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "Artifact digest is invalid."
[[ -d "$REQUEST_DIR" && ! -L "$REQUEST_DIR" ]] || fail "Request directory is invalid."
[[ -f "$PAYLOAD" && -f "$CHECKSUM" && -f "$METADATA" ]] || fail "Candidate files are incomplete."
[[ -f "$SETTINGS_FILE" && -f "$RUNTIME_ENV" ]] || fail "PREPROD bootstrap is incomplete."
command -v jq >/dev/null 2>&1 || fail "jq is required."
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is required."

unset OPENAI_API_KEY || true
unset EMERGING_DIGITAL_SMTP_HOST || true
unset EMERGING_DIGITAL_SMTP_USER || true
unset EMERGING_DIGITAL_SMTP_PASSWORD || true
unset EMERGING_DIGITAL_SMTP_FROM || true

candidate_sha="$(jq -r '.candidate_sha' "$METADATA")"
artifact_sha="$(jq -r '.artifact_sha256' "$METADATA")"
composer_lock_sha="$(jq -r '.composer_lock_sha256' "$METADATA")"
source_branch="$(jq -r '.source_branch' "$METADATA")"
[[ "$candidate_sha" == "$EXPECTED_SHA" ]] || fail "candidate.json SHA mismatch."
[[ "$artifact_sha" == "$EXPECTED_ARTIFACT_SHA256" ]] || fail "candidate.json artifact digest mismatch."
[[ "$source_branch" == release/* ]] || fail "Candidate did not originate from release/*."
[[ "$composer_lock_sha" =~ ^[0-9a-f]{64}$ ]] || fail "Composer lock digest is invalid."
actual_artifact_sha="$(sha256sum "$PAYLOAD" | awk '{print $1}')"
[[ "$actual_artifact_sha" == "$EXPECTED_ARTIFACT_SHA256" ]] || fail "Payload digest mismatch."
(
  cd "$REQUEST_DIR"
  sha256sum -c "$(basename "$CHECKSUM")"
)

archive_dir="$ARTIFACTS_DIR/$EXPECTED_SHA/$EXPECTED_ARTIFACT_SHA256"
install -d -m 750 "$archive_dir"
for candidate_file in "$PAYLOAD" "$CHECKSUM" "$METADATA"; do
  archive_file="$archive_dir/$(basename "$candidate_file")"
  if [[ -e "$archive_file" ]]; then
    cmp -s "$candidate_file" "$archive_file" || fail "Archived immutable candidate differs."
  else
    install -m 640 "$candidate_file" "$archive_file"
  fi
done

[[ ! -e "$NEW_RELEASE" ]] || fail "Release directory already exists."
install -d -m 750 "$NEW_RELEASE"
tar -xzf "$PAYLOAD" -C "$NEW_RELEASE"
[[ -x "$NEW_RELEASE/vendor/bin/drush" ]] || fail "Candidate does not contain Drush."
[[ -f "$NEW_RELEASE/web/index.php" ]] || fail "Candidate does not contain web/index.php."
[[ ! -e "$NEW_RELEASE/web/sites/default/settings.php" ]] || fail "Candidate contains environment settings."

rm -rf "$NEW_RELEASE/web/sites/default/files"
ln -s "$SHARED_DIR/files" "$NEW_RELEASE/web/sites/default/files"
ln -s "$SETTINGS_FILE" "$NEW_RELEASE/web/sites/default/settings.php"
chgrp -R www-data "$NEW_RELEASE"
chmod -R g+rX,o-rwx "$NEW_RELEASE"

if [[ -L "$CURRENT_LINK" ]]; then
  ACTIVE_RELEASE="$(readlink -f "$CURRENT_LINK")"
fi

if [[ -n "$ACTIVE_RELEASE" && -x "$ACTIVE_RELEASE/vendor/bin/drush" ]]; then
  backup="$BACKUPS_DIR/db-${TIMESTAMP}-${EXPECTED_SHA:0:12}.sql"
  log "Create pre-deploy database backup."
  (
    cd "$ACTIVE_RELEASE"
    vendor/bin/drush sql:dump --gzip --result-file="$backup"
  )

  log "Enable maintenance mode on the active release."
  (
    cd "$ACTIVE_RELEASE"
    vendor/bin/drush state:set system.maintenance_mode 1 --input-format=integer
  )
  MAINTENANCE_ENABLED=1
fi

# shellcheck disable=SC1090
source "$RUNTIME_ENV"
if ! (
  cd "$NEW_RELEASE"
  vendor/bin/drush status --field=bootstrap 2>/dev/null | grep -q 'Successful'
); then
  [[ -n "${DRUPAL_ADMIN_PASSWORD:-}" ]] || fail "Initial Drupal admin password is unavailable."
  log "Initialize the independent PREPROD database from committed configuration."
  (
    cd "$NEW_RELEASE"
    vendor/bin/drush site:install --existing-config \
      --account-name=preprod-admin \
      --account-pass="$DRUPAL_ADMIN_PASSWORD" \
      -y
  )
fi

log "Switch current to the verified candidate release."
ln -sfn "$NEW_RELEASE" "$CURRENT_LINK"
SWITCH_COMPLETED=1
[[ "$(readlink -f "$CURRENT_LINK")" == "$(readlink -f "$NEW_RELEASE")" ]] || fail "current symlink did not switch."

"$CURRENT_LINK/vendor/bin/drush" updb -y
"$CURRENT_LINK/vendor/bin/drush" cim -y
preprod_split="$CURRENT_LINK/config/splits/preproduction"
[[ -d "$preprod_split" ]] || fail "PREPROD config split directory is missing."
"$CURRENT_LINK/vendor/bin/drush" config:import --source="$preprod_split" --partial -y

log "Validate governed content catalog."
"$CURRENT_LINK/vendor/bin/drush" emerging:governed-content:validate
log "Preview governed content synchronization."
"$CURRENT_LINK/vendor/bin/drush" emerging:governed-content --all --dry-run
log "Apply governed content synchronization."
"$CURRENT_LINK/vendor/bin/drush" emerging:governed-content --all
governed_content='PASS'

"$CURRENT_LINK/vendor/bin/drush" cr
if [[ "$MAINTENANCE_ENABLED" -eq 1 ]]; then
  "$CURRENT_LINK/vendor/bin/drush" state:set system.maintenance_mode 0 --input-format=integer
  MAINTENANCE_ENABLED=0
fi

{
  printf 'schema_version=1\n'
  printf 'candidate_sha=%s\n' "$EXPECTED_SHA"
  printf 'artifact_sha256=%s\n' "$EXPECTED_ARTIFACT_SHA256"
  printf 'composer_lock_sha256=%s\n' "$composer_lock_sha"
  printf 'source_branch=%s\n' "$source_branch"
  printf 'release_path=%s\n' "$NEW_RELEASE"
  printf 'artifact_archive=%s\n' "$archive_dir"
  printf 'governed_content=%s\n' "$governed_content"
  printf 'deployed_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "$EVIDENCE"
chmod 640 "$EVIDENCE"

release_retention='PASS'
mapfile -t releases < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
if (( ${#releases[@]} > 3 )); then
  for release in "${releases[@]:3}"; do
    release_path="$RELEASES_DIR/$release"
    if [[ "$(readlink -f "$CURRENT_LINK")" != "$(readlink -f "$release_path")" ]]; then
      if ! rm -rf "$release_path"; then
        release_retention='WARNING'
        log "Warning: unable to prune stale release $release; active candidate remains valid."
      fi
    fi
  done
fi

backup_retention='PASS'
mapfile -t backups < <(
  find "$BACKUPS_DIR" -maxdepth 1 -type f \
    \( -name 'db-*.sql.gz' -o -name 'db-*.sql.gz.gz' \) \
    -printf '%f\n' | sort -r
)
if (( ${#backups[@]} > 10 )); then
  for backup in "${backups[@]:10}"; do
    if ! rm -f "$BACKUPS_DIR/$backup"; then
      backup_retention='WARNING'
      log "Warning: unable to prune stale database backup $backup."
    fi
  done
fi

{
  printf 'release_retention=%s\n' "$release_retention"
  printf 'backup_retention=%s\n' "$backup_retention"
} >> "$EVIDENCE"

log "Candidate deployed without rebuild: $EXPECTED_SHA / $EXPECTED_ARTIFACT_SHA256."
