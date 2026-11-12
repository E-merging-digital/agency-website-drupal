#!/usr/bin/env bash
# Reuse the pinned PREPROD SSH/Drush connection; never read a public URL.
set -Eeuo pipefail
umask 077
: "${ISSUE_NUMBER:?}" "${PAYLOAD_SHA256:?}" "${PAYLOAD_FILE:?}"
: "${CANDIDATE_REVISION:?}" "${TRUSTED_MAIN:?}"
: "${PREPROD_SERVER_HOST:?}" "${PREPROD_SSH_KEY:?}" "${NATIVE_IMAGE_DIR:?}"
[[ "$ISSUE_NUMBER" =~ ^[1-9][0-9]*$ ]] && (( ISSUE_NUMBER >= 1542 ))
[[ "$CANDIDATE_REVISION" =~ ^[1-9][0-9]*$ ]]
[[ "$TRUSTED_MAIN" =~ ^[0-9a-f]{40}$ ]]
[[ "$PAYLOAD_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -s "$PAYLOAD_FILE" && -f "$PREPROD_SSH_KEY" ]]
[[ "$(sha256sum "$PAYLOAD_FILE" | awk '{print $1}')" == "$PAYLOAD_SHA256" ]]
run_id="${GITHUB_RUN_ID:-0}"
run_attempt="${GITHUB_RUN_ATTEMPT:-1}"
[[ "$run_id" =~ ^[0-9]+$ && "$run_attempt" =~ ^[1-9][0-9]*$ ]]
script='scripts/runner/editorial-native-image-source.php'
helper='scripts/runner/editorial-native-image-manifest.py'
test -f "$script" && test -f "$helper"
php -l "$script" >/dev/null
mkdir -p "$NATIVE_IMAGE_DIR"
# A changed main/candidate cannot grant the PREPROD credential.
[[ "$(gh api "repos/$GITHUB_REPOSITORY/branches/main" --jq '.commit.sha')" == "$TRUSTED_MAIN" ]]
[[ "$(git rev-parse HEAD)" == "$TRUSTED_MAIN" ]]
gh api --paginate "repos/$GITHUB_REPOSITORY/issues/$ISSUE_NUMBER/comments?per_page=100" \
  --jq '.[] | @base64' > "$NATIVE_IMAGE_DIR/latest-comments.b64"
python3 "$helper" --issue "$ISSUE_NUMBER" --trusted-main "$TRUSTED_MAIN" \
  --payload-comments "$NATIVE_IMAGE_DIR/latest-comments.b64" \
  --payload-output "$NATIVE_IMAGE_DIR/latest-payload.json" \
  > "$NATIVE_IMAGE_DIR/latest-identity.json"
[[ "$(jq -r '.candidate_revision' "$NATIVE_IMAGE_DIR/latest-identity.json")" == "$CANDIDATE_REVISION" ]]
[[ "$(jq -r '.payload_sha256' "$NATIVE_IMAGE_DIR/latest-identity.json")" == "$PAYLOAD_SHA256" ]]
cmp -s "$NATIVE_IMAGE_DIR/latest-payload.json" "$PAYLOAD_FILE"

PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" \
  bash scripts/preproduction-ssh-trust/manage-known-host.sh PROVISION >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" \
  PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh >/dev/null
ssh_opts=(-i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes
  -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15)
remote_target="agency-preprod@$PREPROD_SERVER_HOST"
remote_dir="$(ssh "${ssh_opts[@]}" "$remote_target" \
  "umask 077; mktemp -d '/tmp/agency-native-${ISSUE_NUMBER}-${run_id}-${run_attempt}-XXXXXXXX'")"
[[ "$remote_dir" =~ ^/tmp/agency-native-[0-9]+-[0-9]+-[0-9]+-[A-Za-z0-9]{8}$ ]]
cleanup() {
  set +e
  ssh "${ssh_opts[@]}" "$remote_target" "rm -rf -- '$remote_dir'" >/dev/null 2>&1
}
trap cleanup EXIT
scp "${ssh_opts[@]}" "$script" "$remote_target:$remote_dir/source.php" >/dev/null
scp "${ssh_opts[@]}" "$PAYLOAD_FILE" "$remote_target:$remote_dir/payload.json" >/dev/null
ssh "${ssh_opts[@]}" "$remote_target" \
  "set -euo pipefail; /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh >/dev/null; cd /var/www/agency-preprod/current; AGENCY_IMAGE_ISSUE='$ISSUE_NUMBER' AGENCY_IMAGE_CANDIDATE_REVISION='$CANDIDATE_REVISION' AGENCY_IMAGE_TRUSTED_MAIN='$TRUSTED_MAIN' AGENCY_IMAGE_PAYLOAD_SHA='$PAYLOAD_SHA256' AGENCY_IMAGE_PAYLOAD_FILE='$remote_dir/payload.json' AGENCY_IMAGE_MANIFEST_FILE='$remote_dir/manifest.json' AGENCY_IMAGE_ASSET_FILE='$remote_dir/asset.webp' timeout 120s vendor/bin/drush php:script '$remote_dir/source.php'; /var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh >/dev/null"
scp "${ssh_opts[@]}" "$remote_target:$remote_dir/manifest.json" "$NATIVE_IMAGE_DIR/manifest.json" >/dev/null
scp "${ssh_opts[@]}" "$remote_target:$remote_dir/asset.webp" "$NATIVE_IMAGE_DIR/asset.webp" >/dev/null
python3 "$helper" --issue "$ISSUE_NUMBER" \
  --candidate-revision "$CANDIDATE_REVISION" --payload-sha256 "$PAYLOAD_SHA256" \
  --trusted-main "$TRUSTED_MAIN" --manifest "$NATIVE_IMAGE_DIR/manifest.json" \
  --asset "$NATIVE_IMAGE_DIR/asset.webp" \
  --profile-output "$NATIVE_IMAGE_DIR/profiles.json" \
  --identity-output "$NATIVE_IMAGE_DIR/identity.json" >/dev/null
# Revalidate the main after the authenticated source capture.
[[ "$(gh api "repos/$GITHUB_REPOSITORY/branches/main" --jq '.commit.sha')" == "$TRUSTED_MAIN" ]]
echo 'NATIVE_PREPROD_WEBP_SOURCE=PASS'
