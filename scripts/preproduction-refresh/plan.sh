#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${1:-PLAN}"
REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
SOURCE_PROD_RELEASE_SHA="${SOURCE_PROD_RELEASE_SHA:-}"
PREPROD_RELEASE_SHA="${PREPROD_RELEASE_SHA:-}"
PREPROD_DISK_AVAILABLE_BYTES="${PREPROD_DISK_AVAILABLE_BYTES:-}"
ESTIMATED_STAGING_BYTES="${ESTIMATED_STAGING_BYTES:-}"
REFRESH_LOCK_STATE="${REFRESH_LOCK_STATE:-}"
POLICY="scripts/preproduction-refresh/sanitization-policy.json"
OUTPUT_DIR="artifacts/preproduction-refresh-plan"
EVIDENCE="$OUTPUT_DIR/plan.env"
CAPACITY_MULTIPLIER=3

fail() {
  printf '[preprod-refresh-plan] ERROR: %s\n' "$1" >&2
  exit 1
}

case "$MODE" in
  PLAN)
    ;;
  *)
    fail "Only PLAN is implemented. APPLY is not authorized or executable in this tranche."
    ;;
esac

for command_name in bash git jq sha256sum flock; do
  command -v "$command_name" >/dev/null 2>&1 || fail "Required PLAN capability is unavailable: $command_name."
done

[[ "$REQUEST_ID" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,95}$ ]] || fail "REQUEST_ID is missing or invalid."
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "REPOSITORY_SHA is invalid."
[[ "$SOURCE_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "SOURCE_PROD_RELEASE_SHA is invalid."
[[ "$PREPROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "PREPROD_RELEASE_SHA is invalid."
[[ "$PREPROD_DISK_AVAILABLE_BYTES" =~ ^[0-9]+$ ]] || fail "PREPROD_DISK_AVAILABLE_BYTES is invalid."
[[ "$ESTIMATED_STAGING_BYTES" =~ ^[0-9]+$ ]] || fail "ESTIMATED_STAGING_BYTES is invalid."
[[ "$REFRESH_LOCK_STATE" == "FREE" ]] || fail "Refresh lock state is not FREE."

(( PREPROD_DISK_AVAILABLE_BYTES > 0 )) || fail "PREPROD available capacity must be positive."
(( ESTIMATED_STAGING_BYTES > 0 )) || fail "Estimated staging size must be positive."
(( ESTIMATED_STAGING_BYTES <= 1000000000000000 )) || fail "Estimated staging size is outside the supported PLAN range."

actual_repository_sha="$(git rev-parse HEAD)"
[[ "$actual_repository_sha" == "$REPOSITORY_SHA" ]] || fail "Repository SHA does not match the checked-out PLAN source."

[[ -f "$POLICY" ]] || fail "Sanitization policy is missing."
[[ -f scripts/preproduction/settings.php.template ]] || fail "PREPROD settings template is missing."
[[ -f scripts/preproduction/validate-runtime.sh ]] || fail "PREPROD runtime validator is missing."
[[ -f docs/operations/environment-side-effects.md ]] || fail "Environment side-effect contract is missing."
[[ -f docs/operations/preproduction-data-refresh.md ]] || fail "PREPROD data-refresh contract is missing."

jq -e '
  .schema_version == 1
  and .policy_version == "agency-preprod-refresh-v1"
  and .scope.source == "PROD_READ_ONLY"
  and .scope.target == "PREPROD_ISOLATED_STAGING_DB"
  and .scope.private_files == "NEVER"
  and .future_apply.implemented == false
  and .future_apply.owner_authorization_required == true
  and .future_apply.isolated_staging_db_required == true
  and .future_apply.prod_rollback_path == "NONE"
  and .github_evidence.raw_sql_allowed == false
  and .github_evidence.pii_allowed == false
  and .github_evidence.secrets_allowed == false
  and .github_evidence.phase_1_artifact_path == "artifacts/preproduction-refresh-plan/plan.env"
' "$POLICY" >/dev/null || fail "Sanitization policy contract is invalid."

for mandatory_id in \
  users \
  preprod_admin \
  webform_submissions \
  sessions \
  flood_rate_limit \
  dblog_watchdog \
  caches \
  batch_temp_state \
  queues \
  cron_update_announcements_linkchecker_state \
  one_time_auth_material \
  persisted_credentials \
  production_environment_state; do
  jq -e --arg id "$mandatory_id" \
    '.mandatory_sanitization[] | select(.id == $id and .required == true)' \
    "$POLICY" >/dev/null || fail "Mandatory sanitization rule is missing: $mandatory_id."
done

for assertion in \
  webform_submissions_zero \
  active_sessions_zero \
  production_mail_transport_inactive \
  production_config_split_off \
  preproduction_config_split_on \
  google_tag_off \
  provider_egress_off \
  production_credentials_absent \
  externally_acting_queues_cleared_or_explicitly_bounded \
  staged_db_bootstrap_health_pass \
  basic_auth_preserved \
  noindex_preserved; do
  jq -e --arg assertion "$assertion" \
    '.activation_assertions | index($assertion) != null' \
    "$POLICY" >/dev/null || fail "Activation assertion is missing: $assertion."
done

grep -Fq "config_split.config_split.production']['status'] = FALSE" scripts/preproduction/settings.php.template \
  || fail "PREPROD production split override is missing."
grep -Fq "config_split.config_split.preproduction']['status'] = TRUE" scripts/preproduction/settings.php.template \
  || fail "PREPROD split override is missing."
grep -Fq "automated_cron.settings']['interval'] = 0" scripts/preproduction/settings.php.template \
  || fail "PREPROD automated cron override is missing."
grep -Fq "google_tag.settings']['default_google_tag_entity'] = NULL" scripts/preproduction/settings.php.template \
  || fail "PREPROD Google Tag override is missing."
grep -Fq "agency_external_ai_egress_enabled'] = FALSE" scripts/preproduction/settings.php.template \
  || fail "PREPROD external AI gate is missing."
grep -Fq 'side_effects=PASS' scripts/preproduction/validate-runtime.sh \
  || fail "PREPROD side-effect runtime assertion is missing."

required_capacity_bytes=$(( ESTIMATED_STAGING_BYTES * CAPACITY_MULTIPLIER ))
(( PREPROD_DISK_AVAILABLE_BYTES >= required_capacity_bytes )) \
  || fail "Declared PREPROD capacity is below the phase-1 safety multiplier."

policy_version="$(jq -r '.policy_version' "$POLICY")"
policy_sha256="$(sha256sum "$POLICY" | awk '{print $1}')"
[[ "$policy_sha256" =~ ^[0-9a-f]{64}$ ]] || fail "Sanitization policy digest is invalid."

rm -rf "$OUTPUT_DIR"
mkdir -p "$OUTPUT_DIR"

cat > "$EVIDENCE" <<EOF
schema_version=1
mode=PLAN
request_id=$REQUEST_ID
repository_sha=$REPOSITORY_SHA
source_prod_release_sha=$SOURCE_PROD_RELEASE_SHA
preprod_release_sha=$PREPROD_RELEASE_SHA
sanitization_policy_version=$policy_version
sanitization_policy_sha256=$policy_sha256
preprod_disk_available_bytes=$PREPROD_DISK_AVAILABLE_BYTES
estimated_staging_bytes=$ESTIMATED_STAGING_BYTES
required_capacity_bytes=$required_capacity_bytes
refresh_lock_state=FREE
capacity=PASS
repository_contract=PASS
prod_write_path=NONE
real_prod_data_transfer=NONE
preprod_db_activation=NONE
raw_prod_data_in_github=NONE
apply_authority=NOT_AUTHORIZED
EOF
chmod 600 "$EVIDENCE"

if find "$OUTPUT_DIR" -type f \( -name '*.sql' -o -name '*.sql.gz' -o -name '*.dump' -o -name '*.tar' -o -name '*.tar.gz' \) -print -quit | grep -q .; then
  fail "Raw or archive material appeared in the PLAN evidence directory."
fi

expected_keys='schema_version mode request_id repository_sha source_prod_release_sha preprod_release_sha sanitization_policy_version sanitization_policy_sha256 preprod_disk_available_bytes estimated_staging_bytes required_capacity_bytes refresh_lock_state capacity repository_contract prod_write_path real_prod_data_transfer preprod_db_activation raw_prod_data_in_github apply_authority'
actual_keys="$(cut -d= -f1 "$EVIDENCE" | tr '\n' ' ' | sed 's/ $//')"
[[ "$actual_keys" == "$expected_keys" ]] || fail "PLAN evidence key allowlist drifted."

printf '[preprod-refresh-plan] PLAN PASS: metadata-only evidence generated at %s.\n' "$EVIDENCE"
