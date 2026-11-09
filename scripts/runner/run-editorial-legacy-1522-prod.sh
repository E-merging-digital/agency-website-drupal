#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Closed, three-Article UPDATE of #1522. No create, IA, configurable node or SQL.
operation="${1:-}"
[[ "$operation" == identity || "$operation" == update ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" =~ ^[1-9][0-9]*$ ]]
[[ "${SERVER_HOST:-}" =~ ^[A-Za-z0-9.-]+$ ]]
[[ "${SERVER_USER:-}" =~ ^[A-Za-z0-9_-]+$ ]]
[[ -f "${PROD_SSH_KEY:-}" ]]
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
test "$(sha256sum assets/editorial/issue-1522-legacy-revisions.json | cut -d' ' -f1)" = '5d852f96c10f3ea23b9a3406f5b4288bf7dbce7f3ca2631354299b41cc83848a'
php -l scripts/runner/editorial-legacy-1522.php >/dev/null
out='artifacts/editorial-legacy-1522-prod'
mkdir -p "$out"

# Repository-pinned host identity, never trust-on-first-use / ssh-keyscan.
SERVER_HOST="$SERVER_HOST" bash scripts/production-ssh-trust/manage-known-host.sh PROVISION >/dev/null
ssh_args=(-i "$PROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes
  -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15)
target="$SERVER_USER@$SERVER_HOST"
stem="/tmp/agency-existing-1522-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
remote_dir="$stem"
cleanup() {
  set +e
  ssh "${ssh_args[@]}" "$target" "rm -f '$remote_dir'/*; rmdir '$remote_dir'" >/dev/null 2>&1
}
trap cleanup EXIT
ssh "${ssh_args[@]}" "$target" "mkdir -m 700 '$remote_dir'"
scp "${ssh_args[@]}" scripts/runner/editorial-legacy-1522.php "$target:$remote_dir/runner.php" >/dev/null
scp "${ssh_args[@]}" assets/editorial/issue-1522-legacy-revisions.json "$target:$remote_dir/candidate.json" >/dev/null
if [[ "$operation" == update ]]; then
  for image in issue-1522-drupal-10-vers-11.webp issue-1522-drupal-symfony-laravel.webp issue-1522-refonte-site-checklist.webp; do
      scp "${ssh_args[@]}" "assets/editorial/$image" "$target:$remote_dir/$image" >/dev/null
  done
fi

run_drupal() {
  local mode="$1" allowlist="${2:-}" result="$3"
  case "$mode" in identity|inspect|dry-run|apply) ;; *) exit 64 ;; esac
  [[ -z "$allowlist" || "$allowlist" =~ ^2:[0-9a-f]{64},3:[0-9a-f]{64},4:[0-9a-f]{64}$ ]]
  ssh "${ssh_args[@]}" "$target" \
    "set -euo pipefail; cd /var/www/agency/current; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; AGENCY_LEGACY_1522_TARGET=PROD AGENCY_LEGACY_1522_MODE='$mode' AGENCY_LEGACY_1522_ALLOWLIST='$allowlist' AGENCY_LEGACY_1522_CANDIDATE='$remote_dir/candidate.json' AGENCY_LEGACY_1522_ASSETS='$remote_dir' AGENCY_LEGACY_1522_RESULT='$remote_dir/$result' timeout 180s vendor/bin/drush php:script '$remote_dir/runner.php'"
  scp "${ssh_args[@]}" "$target:$remote_dir/$result" "$out/$result" >/dev/null
}

check_three() {
  local file="$1" mode="$2"
  jq -e --arg mode "$mode" '
    .status == "PASS" and .target == "PROD" and .held_article == "IA_PREPROD_IDENTITY"
    and .mode == $mode and [.articles[].candidate_index] == [2,3,4]
    and ([.articles[] | .status == "PASS" and (.proof | test("^[0-9a-f]{64}$"))
      and (.uuid | test("^[0-9a-f-]{36}$")) and (.nid > 0)] | all)
    and [.articles[0].uuid,.articles[1].uuid,.articles[2].uuid] == [
      "196a8ad1-ff50-41f5-82f0-14d662f48a6f",
      "3d638f3e-8a31-4b7c-9466-c40f70f4999a",
      "8b5c25de-88ce-4493-a28a-54d55957baaf"
    ] and .prod_write == (if $mode == "apply" then "BOUNDED_UPDATE" else "NONE" end)
  ' "$file" >/dev/null
}

# Strictly read-only inspection of three existing Article identities.
# No media upload, backup, revision change or publication is possible in this mode.
if [[ "$operation" == identity ]]; then
  run_drupal identity '' identity.json
  jq -e '
    .status == "PASS" and .target == "PROD" and .mode == "identity"
    and .issue_number == 1522 and .prod_write == "NONE"
    and .held_article == "IA_PREPROD_IDENTITY"
    and [.articles[].candidate_index] == [2,3,4]
    and ([.articles[] | .status == "PASS" and (.nid > 0)
      and (.revision > 0) and (.uuid | test("^[0-9a-f-]{36}$"))
      and (.fr_alias | startswith("/blog/")) and (.en_alias | startswith("/blog/"))] | all)
    and ([.articles[].nid] | unique | length) == 3
  ' "$out/identity.json" >/dev/null
  printf 'PROD_IDENTITY_ONLY=PASS\nPROD_WRITE=NONE\nIA_HOLD=YES\n' >> "$GITHUB_STEP_SUMMARY"
  exit 0
fi

# Fresh read-only PROD proof. It binds UUID, live revision and both aliases to
# the same node; any mismatch stops before backup or mutation.
run_drupal dry-run '' dry-run.json
check_three "$out/dry-run.json" dry-run
allowlist="$(jq -r '.eligible' "$out/dry-run.json")"
[[ "$allowlist" =~ ^2:[0-9a-f]{64},3:[0-9a-f]{64},4:[0-9a-f]{64}$ ]]

# Database checkpoint before the first mutation. No automatic whole-site
# restore: concurrent editorial data must not be overwritten after a failure.
backup="/var/www/agency/shared/backups/editorial-existing-1522-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}.sql"
ssh "${ssh_args[@]}" "$target" \
  "set -euo pipefail; cd /var/www/agency/current; mkdir -p /var/www/agency/shared/backups; vendor/bin/drush sql:dump --gzip --result-file='$backup' >/dev/null; test -s '$backup.gz'"
printf 'BACKUP_VERIFIED=%s\n' "$backup.gz" >> "$GITHUB_STEP_SUMMARY"

# The same revision identity must still match at apply. If any Article drifted,
# the PHP runner refuses it. Failure stops; the checkpoint is kept for recovery.
run_drupal apply "$allowlist" result.json
check_three "$out/result.json" apply
ssh "${ssh_args[@]}" "$target" \
  "set -euo pipefail; cd /var/www/agency/current; vendor/bin/drush cr >/dev/null"
run_drupal inspect '' postcheck.json
check_three "$out/postcheck.json" inspect
jq -e '[.articles[].verdict] == ["IDEMPOTENT","IDEMPOTENT","IDEMPOTENT"]' "$out/postcheck.json" >/dev/null
printf 'PROD_UPDATE_1522=PASS\nIA_HOLD=YES\n' >> "$GITHUB_STEP_SUMMARY"
