#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Existing Drupal Article UPDATE only. No CREATE, IA, or arbitrary SSH command.
operation="${1:-}"
[[ "$operation" == inspect || "$operation" == apply ]]
[[ "${SOURCE_UUID:-}" =~ ^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$ ]]
[[ "${PROD_UUID:-}" =~ ^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$ ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" == 1 ]]
[[ "${PREPROD_SERVER_HOST:-}" =~ ^[A-Za-z0-9.-]+$ && "${SERVER_HOST:-}" =~ ^[A-Za-z0-9.-]+$ ]]
[[ "${SERVER_USER:-}" =~ ^[A-Za-z0-9_-]+$ ]]
[[ -f "${PREPROD_SSH_KEY:-}" && -f "${PROD_SSH_KEY:-}" ]]

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
php -l scripts/runner/editorial-existing-article-1525.php >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" bash scripts/preproduction-ssh-trust/manage-known-host.sh PROVISION >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh >/dev/null
SERVER_HOST="$SERVER_HOST" bash scripts/production-ssh-trust/manage-known-host.sh PROVISION >/dev/null

pre=(-i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
prod=(-i "$PROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
src="agency-preprod@$PREPROD_SERVER_HOST"
dst="$SERVER_USER@$SERVER_HOST"
stem="/tmp/agency-existing-1525-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
local_dir="$RUNNER_TEMP/agency-existing-1525-${GITHUB_RUN_ID}"
mkdir -m 700 "$local_dir"
mkdir -p artifacts/editorial-existing-1525
cleanup() {
  set +e
  ssh "${pre[@]}" "$src" "rm -f '$stem'/*; rmdir '$stem'" >/dev/null 2>&1
  ssh "${prod[@]}" "$dst" "rm -f '$stem'/*; rmdir '$stem'" >/dev/null 2>&1
  rm -f -- "$local_dir"/*
  rmdir "$local_dir" >/dev/null 2>&1
}
trap cleanup EXIT
ssh "${pre[@]}" "$src" "mkdir -m 700 '$stem'"
ssh "${prod[@]}" "$dst" "mkdir -m 700 '$stem'"
scp "${pre[@]}" scripts/runner/editorial-existing-article-1525.php "$src:$stem/runner.php" >/dev/null
scp "${prod[@]}" scripts/runner/editorial-existing-article-1525.php "$dst:$stem/runner.php" >/dev/null

# Drupal PREPROD is the content source of truth; no schema or database copying.
ssh "${pre[@]}" "$src" \
  "set -euo pipefail; cd /var/www/agency-preprod/current; test -x vendor/bin/drush; AGENCY_EXISTING_TARGET=PREPROD AGENCY_EXISTING_MODE=export AGENCY_EXISTING_SOURCE_UUID='$SOURCE_UUID' AGENCY_EXISTING_PROD_UUID='$PROD_UUID' AGENCY_EXISTING_CANDIDATE='$stem/article.json' AGENCY_EXISTING_ASSET='$stem/image.webp' AGENCY_EXISTING_RESULT='$stem/export.json' timeout 120s vendor/bin/drush php:script '$stem/runner.php'"
for item in article.json image.webp export.json; do
  scp "${pre[@]}" "$src:$stem/$item" "$local_dir/$item" >/dev/null
done
source_sha="$(sha256sum "$local_dir/article.json" | cut -d' ' -f1)"
image_sha="$(sha256sum "$local_dir/image.webp" | cut -d' ' -f1)"
jq -e --arg source "$SOURCE_UUID" --arg prod "$PROD_UUID" --arg hash "$source_sha" --arg image "$image_sha" '
  .status == "PASS" and .target == "PREPROD" and .mode == "export" and .prod_write == "NONE"
  and .source_uuid == $source and .prod_uuid == $prod
  and .source_sha256 == $hash and .image_sha256 == $image and .hold_ia == "PRESERVED"
' "$local_dir/export.json" >/dev/null

# The source must still exactly match the one direct human-approved snapshot.
if [[ "$operation" == apply ]]; then
  [[ "${GO_SOURCE_SHA:-}" == "$source_sha" && "${GO_IMAGE_SHA:-}" == "$image_sha" ]]
  [[ "${GO_FR_URL:-}" == "$(jq -r .fr_url "$local_dir/export.json")" ]]
  [[ "${GO_EN_URL:-}" == "$(jq -r .en_url "$local_dir/export.json")" ]]
fi

for item in article.json image.webp; do
  scp "${prod[@]}" "$local_dir/$item" "$dst:$stem/$item" >/dev/null
done
run_prod() {
  local mode="$1" proof="${2:-}" result="$3"
  [[ "$mode" == dry-run || "$mode" == apply || "$mode" == postcheck ]]
  [[ -z "$proof" || "$proof" =~ ^[0-9a-f]{64}$ ]]
  ssh "${prod[@]}" "$dst" \
    "set -euo pipefail; cd /var/www/agency/current; test -x vendor/bin/drush; AGENCY_EXISTING_TARGET=PROD AGENCY_EXISTING_MODE='$mode' AGENCY_EXISTING_SOURCE_UUID='$SOURCE_UUID' AGENCY_EXISTING_PROD_UUID='$PROD_UUID' AGENCY_EXISTING_CANDIDATE='$stem/article.json' AGENCY_EXISTING_ASSET='$stem/image.webp' AGENCY_EXISTING_PROOF='$proof' AGENCY_EXISTING_RESULT='$stem/$result' timeout 180s vendor/bin/drush php:script '$stem/runner.php'"
  scp "${prod[@]}" "$dst:$stem/$result" "$local_dir/$result" >/dev/null
}
run_prod dry-run '' dry-run.json
jq -e --arg uuid "$PROD_UUID" --arg source "$source_sha" --arg image "$image_sha" '
  .status == "PASS" and .mode == "dry-run" and .prod_write == "NONE"
  and .uuid == $uuid and .source_sha256 == $source and .image_sha256 == $image
  and (.proof | test("^[0-9a-f]{64}$")) and .hold_ia == "PRESERVED"
' "$local_dir/dry-run.json" >/dev/null

if [[ "$operation" == apply ]]; then
  # Revalidate live main and the direct unedited OWNER comment at the last
  # possible boundary before taking a backup or changing Drupal.
  [[ "$(gh api "repos/$GITHUB_REPOSITORY/branches/main" --jq .commit.sha)" == "$TRUSTED_MAIN" ]]
  gh api "repos/$GITHUB_REPOSITORY/issues/comments/$GO_COMMENT_ID" | jq -e '
    .user.login == "E-merging-digital" and .user.type == "User"
    and .author_association == "OWNER" and .performed_via_github_app == null
    and .created_at == .updated_at
  ' >/dev/null
  # The comment body is also pinned by the workflow guard; hash equality is
  # checked below without logging the approved content.
  actual_go="$(gh api "repos/$GITHUB_REPOSITORY/issues/comments/$GO_COMMENT_ID" | jq -j .body | sha256sum | cut -d' ' -f1)"
  [[ "$actual_go" == "$GO_COMMENT_SHA" ]]
  proof="$(jq -r .proof "$local_dir/dry-run.json")"
  backup="/var/www/agency/shared/backups/editorial-existing-1525-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.sql"
  ssh "${prod[@]}" "$dst" \
    "set -euo pipefail; cd /var/www/agency/current; mkdir -p /var/www/agency/shared/backups; vendor/bin/drush sql:dump --gzip --result-file='$backup' >/dev/null; test -s '$backup.gz'"
  printf 'BACKUP_VERIFIED=YES\n' >> "$GITHUB_STEP_SUMMARY"
  run_prod apply "$proof" apply.json
  jq -e --arg uuid "$PROD_UUID" '
    .status == "PASS" and .mode == "apply" and .uuid == $uuid
    and .prod_write == "BOUNDED_UPDATE" and .hold_ia == "PRESERVED"
    and (.verdict == "UPDATED" or .verdict == "IDEMPOTENT")
  ' "$local_dir/apply.json" >/dev/null
  ssh "${prod[@]}" "$dst" "cd /var/www/agency/current && vendor/bin/drush cr >/dev/null"
  run_prod postcheck '' postcheck.json
  jq -e '.status == "PASS" and .verdict == "IDEMPOTENT" and .prod_write == "NONE"' "$local_dir/postcheck.json" >/dev/null
  cp "$local_dir/apply.json" artifacts/editorial-existing-1525/apply.json
  cp "$local_dir/postcheck.json" artifacts/editorial-existing-1525/postcheck.json
  printf 'ARTICLE_UPDATE=PASS\nHOLD_IA=PRESERVED\n' >> "$GITHUB_STEP_SUMMARY"
fi
cp "$local_dir/export.json" artifacts/editorial-existing-1525/export.json
cp "$local_dir/dry-run.json" artifacts/editorial-existing-1525/dry-run.json
