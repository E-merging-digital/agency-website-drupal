#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${1:-}"
case "$MODE" in
  fresh|reset) ;;
  *)
    echo 'Usage: scripts/development-seed/use-native-seed.sh fresh|reset' >&2
    exit 2
    ;;
esac

for command_name in ddev git php scp mktemp; do
  command -v "$command_name" >/dev/null 2>&1 || {
    echo "Missing required local command: $command_name" >&2
    exit 2
  }
done

repo="$(git rev-parse --show-toplevel)"
repo_abs="$(cd "$repo" && pwd -P)"
cache_root="${AGENCY_SEED_CACHE_DIR:-${XDG_CACHE_HOME:-$HOME/.cache}/agency-development-seeds}"
mkdir -p "$cache_root"
cache_abs="$(cd "$cache_root" && pwd -P)"
case "$cache_abs/" in
  "$repo_abs/"*)
    echo 'AGENCY_SEED_CACHE_DIR must remain outside the Git checkout.' >&2
    exit 2
    ;;
esac

: "${AGENCY_SEED_SSH_TARGET:?Configure AGENCY_SEED_SSH_TARGET locally}"
case "$AGENCY_SEED_SSH_TARGET" in
  agency-preprod@*) ;;
  *)
    echo 'AGENCY_SEED_SSH_TARGET must use the restricted agency-preprod reader identity.' >&2
    exit 2
    ;;
esac
host="${AGENCY_SEED_SSH_TARGET#agency-preprod@}"
case "$host" in
  ''|*[!A-Za-z0-9.-]*)
    echo 'Unsafe Development Seed host.' >&2
    exit 2
    ;;
esac

remote_root='/var/www/agency-preprod/shared/development-seeds/current'
snapshot_name='database-mariadb_11.8.zst'
pinned="$repo/scripts/preproduction-ssh-trust/preprod-ed25519.pub"
[[ -f "$pinned" && ! -L "$pinned" ]]

incoming="$(mktemp -d "$cache_abs/.incoming.XXXXXX")"
cleanup() {
  local code=$?
  if [[ -n "${incoming:-}" && -d "$incoming" ]]; then
    rm -rf -- "$incoming"
  fi
  exit "$code"
}
trap cleanup EXIT HUP INT TERM

metadata="$incoming/seed.json"
snapshot="$incoming/$snapshot_name"
known_hosts="$incoming/known_hosts"
read -r key_type key_blob _ < "$pinned"
[[ "$key_type" == ssh-ed25519 && "$key_blob" =~ ^[A-Za-z0-9+/=]+$ ]]
printf '%s %s %s\n' "$host" "$key_type" "$key_blob" > "$known_hosts"
chmod 600 "$known_hosts"

scp_args=(-O -q -o BatchMode=yes -o IdentitiesOnly=no -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts")
scp "${scp_args[@]}" -- "$AGENCY_SEED_SSH_TARGET:$remote_root/seed.json" "$metadata"
scp "${scp_args[@]}" -- "$AGENCY_SEED_SSH_TARGET:$remote_root/$snapshot_name" "$snapshot"
chmod 600 "$metadata" "$snapshot"
rm -f -- "$known_hosts"

php "$repo/scripts/development-seed/verify-seed.php" \
  --metadata="$metadata" \
  --database="$snapshot" \
  --repository="$repo" \
  --checkout-ref=HEAD >/dev/null

seed_id="$(php -r '$m=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); $id=$m["seed_id"] ?? ""; if (!is_string($id) || !preg_match("/^agency-development-seed-v1-[A-Za-z0-9._-]+$/", $id)) { exit(2); } echo $id;' "$metadata")"
final_dir="$cache_abs/$seed_id"
final_metadata="$final_dir/seed.json"
final_snapshot="$final_dir/$snapshot_name"

if [[ -e "$final_dir" ]]; then
  [[ -d "$final_dir" && ! -L "$final_dir" ]]
  php "$repo/scripts/development-seed/verify-seed.php" \
    --metadata="$final_metadata" \
    --database="$final_snapshot" \
    --repository="$repo" \
    --checkout-ref=HEAD >/dev/null
  rm -rf -- "$incoming"
  incoming=''
else
  mv -- "$incoming" "$final_dir"
  incoming=''
  chmod 700 "$final_dir"
  chmod 600 "$final_metadata" "$final_snapshot"
fi

# Only non-sensitive metadata is copied into the ignored DDEV working area.
mkdir -p "$repo/.ddev/.downloads"
cp -- "$final_metadata" "$repo/.ddev/.downloads/agency-seed.json"
chmod 600 "$repo/.ddev/.downloads/agency-seed.json"

case "$MODE" in
  fresh)
    ddev start --seed-snapshot="$final_snapshot"
    ;;
  reset)
    # Intentionally no -y and no --omit-snapshot: human reset remains explicit,
    # confirmed, and protected by DDEV's default pre-reset safety snapshot.
    ddev start --reset-database --seed-snapshot="$final_snapshot"
    ;;
esac

ddev exec bash scripts/development-seed/post-pull.sh

printf '%s\n' \
  "DEVELOPMENT_SEED_ID=$seed_id" \
  "DEVELOPMENT_SEED_CACHE=$final_dir" \
  "DDEV_NATIVE_SEED_MODE=$MODE" \
  'LOCAL_CONVERGENCE=PASS'
