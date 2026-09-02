#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ACTION="${1:-}"
REQUEST_ID="${2:-}"
EXPECTED_DATABASE_SHA="${3:-NONE}"
EXPECTED_READER_SHA="${4:-NONE}"
[[ "$#" -eq 4 ]]
[[ "$ACTION" =~ ^(PREPARE|COMMIT|CLEANUP|VERIFY)$ ]]
[[ "$REQUEST_ID" =~ ^seed-956-[A-Za-z0-9._-]{8,40}-r1$ ]]
[[ "$(id -un)" == 'agency-preprod' ]]

ROOT='/var/www/agency-preprod/shared/development-seeds'
IMMUTABLE="$ROOT/immutable"
INCOMING_ROOT="$ROOT/.incoming"
INCOMING="$INCOMING_ROOT/$REQUEST_ID"
SEED_ID="agency-development-seed-v1-$REQUEST_ID"
TARGET="$IMMUTABLE/$SEED_ID"
CURRENT="$ROOT/current"
READER="$ROOT/read-only-scp.sh"

fail() {
  printf 'Development Seed storage rejected: %s\n' "$1" >&2
  exit 80
}

safe_dir() {
  local path="$1"
  [[ -d "$path" && ! -L "$path" ]] || fail "unsafe directory: $path"
}

ensure_root() {
  if [[ -L "$ROOT" || -L "$IMMUTABLE" || -L "$INCOMING_ROOT" ]]; then
    fail 'seed storage directories may not be symlinks'
  fi
  install -d -m 700 "$ROOT" "$IMMUTABLE" "$INCOMING_ROOT"
  safe_dir "$ROOT"
  safe_dir "$IMMUTABLE"
  safe_dir "$INCOMING_ROOT"
}

case "$ACTION" in
  PREPARE)
    ensure_root
    [[ ! -e "$TARGET" && ! -L "$TARGET" ]] || fail 'immutable seed identity already exists'
    rm -rf -- "$INCOMING"
    install -d -m 700 "$INCOMING"
    printf '%s\n' \
      "seed_id=$SEED_ID" \
      "incoming=$INCOMING" \
      'storage_prepare=PASS'
    ;;

  CLEANUP)
    ensure_root
    if [[ -e "$INCOMING" || -L "$INCOMING" ]]; then
      [[ -d "$INCOMING" && ! -L "$INCOMING" ]] || fail 'incoming seed path is unsafe'
      rm -rf -- "$INCOMING"
    fi
    [[ ! -e "$INCOMING" && ! -L "$INCOMING" ]] || fail 'incoming cleanup could not be proven'
    printf '%s\n' 'temporary_storage_material=ABSENT'
    ;;

  COMMIT)
    ensure_root
    [[ "$EXPECTED_DATABASE_SHA" =~ ^[0-9a-f]{64}$ ]] || fail 'invalid expected database digest'
    [[ "$EXPECTED_READER_SHA" =~ ^[0-9a-f]{64}$ ]] || fail 'invalid expected reader command digest'
    safe_dir "$INCOMING"
    [[ ! -e "$TARGET" && ! -L "$TARGET" ]] || fail 'immutable seed identity already exists'

    database="$INCOMING/database.sql.gz"
    metadata="$INCOMING/seed.json"
    reader_candidate="$INCOMING/read-only-scp.sh"
    for path in "$database" "$metadata" "$reader_candidate"; do
      [[ -f "$path" && ! -L "$path" ]] || fail 'incoming payload is incomplete or unsafe'
    done
    [[ -s "$database" && -s "$metadata" && -s "$reader_candidate" ]] || fail 'incoming payload is empty'
    actual_database_sha="$(sha256sum "$database" | awk '{print $1}')"
    actual_reader_sha="$(sha256sum "$reader_candidate" | awk '{print $1}')"
    [[ "$actual_database_sha" == "$EXPECTED_DATABASE_SHA" ]] || fail 'database digest mismatch before publication'
    [[ "$actual_reader_sha" == "$EXPECTED_READER_SHA" ]] || fail 'reader command digest mismatch before publication'
    jq -e \
      --arg seed "$SEED_ID" \
      --arg digest "$EXPECTED_DATABASE_SHA" \
      '.schema_version == 1 and .seed_id == $seed and .database_sha256 == $digest and (.source_preprod_refresh_identity | type == "string") and (.source_preprod_application_release_sha | test("^[0-9a-f]{40}$")) and .sanitization_policy.id == "agency-development-seed-v1"' \
      "$metadata" >/dev/null || fail 'seed metadata contract failed before publication'

    # Install the fixed reader command separately; immutable seed directories
    # contain only the distributable database and metadata payload.
    reader_tmp="$ROOT/.read-only-scp.$REQUEST_ID.tmp"
    cp -- "$reader_candidate" "$reader_tmp"
    chmod 500 "$reader_tmp"
    [[ "$(sha256sum "$reader_tmp" | awk '{print $1}')" == "$EXPECTED_READER_SHA" ]] || fail 'fixed reader candidate changed'
    rm -f -- "$reader_candidate"

    chmod 400 "$database" "$metadata"
    mv -- "$INCOMING" "$TARGET"
    chmod 500 "$TARGET"
    [[ -d "$TARGET" && ! -L "$TARGET" ]] || fail 'immutable seed move failed'
    [[ -f "$TARGET/database.sql.gz" && -f "$TARGET/seed.json" ]] || fail 'immutable seed payload is incomplete'
    [[ "$(find "$TARGET" -mindepth 1 -maxdepth 1 -type f | wc -l)" -eq 2 ]] || fail 'immutable seed payload contains unexpected files'
    [[ "$(sha256sum "$TARGET/database.sql.gz" | awk '{print $1}')" == "$EXPECTED_DATABASE_SHA" ]] || fail 'published database digest mismatch'

    mv -f -- "$reader_tmp" "$READER"
    chmod 500 "$READER"
    [[ "$(sha256sum "$READER" | awk '{print $1}')" == "$EXPECTED_READER_SHA" ]] || fail 'fixed reader command installation failed'

    current_tmp="$ROOT/.current.$REQUEST_ID.tmp"
    rm -f -- "$current_tmp"
    ln -s "immutable/$SEED_ID" "$current_tmp"
    [[ "$(readlink -f "$current_tmp")" == "$TARGET" ]] || fail 'candidate current pointer is invalid'
    mv -Tf -- "$current_tmp" "$CURRENT"
    [[ -L "$CURRENT" && "$(readlink -f "$CURRENT")" == "$TARGET" ]] || fail 'current pointer switch failed'
    [[ ! -e "$INCOMING" && ! -L "$INCOMING" ]] || fail 'incoming material survived publication'

    printf '%s\n' \
      "seed_id=$SEED_ID" \
      "database_sha256=$EXPECTED_DATABASE_SHA" \
      'seed_storage=PUBLISHED' \
      'immutable_seed=PASS' \
      'current_pointer=VERIFIED' \
      'temporary_storage_material=ABSENT'
    ;;

  VERIFY)
    ensure_root
    [[ "$EXPECTED_DATABASE_SHA" =~ ^[0-9a-f]{64}$ ]] || fail 'invalid expected database digest'
    [[ "$EXPECTED_READER_SHA" =~ ^[0-9a-f]{64}$ ]] || fail 'invalid expected reader command digest'
    [[ -L "$CURRENT" && "$(readlink -f "$CURRENT")" == "$TARGET" ]] || fail 'current pointer does not address expected immutable seed'
    [[ -d "$TARGET" && ! -L "$TARGET" ]] || fail 'expected immutable seed is unavailable'
    [[ -f "$TARGET/database.sql.gz" && ! -L "$TARGET/database.sql.gz" ]] || fail 'published database is unavailable'
    [[ -f "$TARGET/seed.json" && ! -L "$TARGET/seed.json" ]] || fail 'published metadata is unavailable'
    [[ "$(find "$TARGET" -mindepth 1 -maxdepth 1 -type f | wc -l)" -eq 2 ]] || fail 'immutable seed contains unexpected files'
    [[ "$(sha256sum "$TARGET/database.sql.gz" | awk '{print $1}')" == "$EXPECTED_DATABASE_SHA" ]] || fail 'published database digest changed'
    [[ -f "$READER" && ! -L "$READER" && "$(sha256sum "$READER" | awk '{print $1}')" == "$EXPECTED_READER_SHA" ]] || fail 'reader command identity changed'
    [[ ! -e "$INCOMING" && ! -L "$INCOMING" ]] || fail 'temporary storage material remains'
    printf '%s\n' \
      'seed_storage=PUBLISHED' \
      'immutable_seed=PASS' \
      'current_pointer=VERIFIED' \
      'temporary_storage_material=ABSENT'
    ;;
esac
