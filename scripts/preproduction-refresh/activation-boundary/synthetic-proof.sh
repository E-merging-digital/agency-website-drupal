#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

: "${MARIADB_HOST:=127.0.0.1}"
: "${MARIADB_PORT:=3306}"
: "${MARIADB_USER:=root}"
: "${MARIADB_PASSWORD:?MARIADB_PASSWORD is required for the synthetic service only}"

RUNTIME_DB='agency868_runtime'
CANDIDATE_DB='agency868_candidate'
ROLLBACK_DB='agency868_rollback'
FAILED_DB='agency868_failed'
EVIDENCE_FILE="${1:-artifacts/preprod-868-activation-rollback/evidence.env}"
TMP_DIR="$(mktemp -d)"
BACKUP_FILE="$TMP_DIR/runtime-before.sql"
ROLLBACK_DUMP="$TMP_DIR/runtime-after-rollback.sql"

client=(
  mariadb
  --protocol=tcp
  --host="$MARIADB_HOST"
  --port="$MARIADB_PORT"
  --user="$MARIADB_USER"
  --password="$MARIADB_PASSWORD"
  --batch
  --skip-column-names
  --raw
)
dumper=(
  mariadb-dump
  --protocol=tcp
  --host="$MARIADB_HOST"
  --port="$MARIADB_PORT"
  --user="$MARIADB_USER"
  --password="$MARIADB_PASSWORD"
  --skip-comments
  --compact
  --order-by-primary
  --skip-extended-insert
  --skip-triggers
  --skip-add-locks
  --skip-disable-keys
  --databases
)

sql() {
  "${client[@]}" -e "$1"
}

scalar() {
  local value
  value="$(sql "$1")"
  [[ "$value" =~ ^[0-9]+$ ]] || {
    printf 'Synthetic scalar proof is invalid.\n' >&2
    exit 70
  }
  printf '%s' "$value"
}

cleanup() {
  set +e
  sql "DROP DATABASE IF EXISTS \`$FAILED_DB\`; DROP DATABASE IF EXISTS \`$ROLLBACK_DB\`; DROP DATABASE IF EXISTS \`$CANDIDATE_DB\`; DROP DATABASE IF EXISTS \`$RUNTIME_DB\`;" >/dev/null 2>&1 || true
  rm -rf "$TMP_DIR" || true
}
trap cleanup EXIT HUP INT TERM

for command in mariadb mariadb-dump sha256sum stat; do
  command -v "$command" >/dev/null 2>&1 || {
    printf 'Required synthetic proof command is absent: %s\n' "$command" >&2
    exit 69
  }
done

mkdir -p "$(dirname "$EVIDENCE_FILE")"
rm -f "$EVIDENCE_FILE"

sql "DROP DATABASE IF EXISTS \`$FAILED_DB\`; DROP DATABASE IF EXISTS \`$ROLLBACK_DB\`; DROP DATABASE IF EXISTS \`$CANDIDATE_DB\`; DROP DATABASE IF EXISTS \`$RUNTIME_DB\`;"
sql "CREATE DATABASE \`$RUNTIME_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE \`$CANDIDATE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

sql "
CREATE TABLE \`$RUNTIME_DB\`.content (id INT PRIMARY KEY, marker VARCHAR(64) NOT NULL) ENGINE=InnoDB;
CREATE TABLE \`$RUNTIME_DB\`.state (id INT PRIMARY KEY, marker VARCHAR(64) NOT NULL) ENGINE=InnoDB;
CREATE TABLE \`$RUNTIME_DB\`.guard (id INT PRIMARY KEY, sanitized TINYINT NOT NULL, hardened TINYINT NOT NULL, webforms INT NOT NULL, sessions INT NOT NULL, queues INT NOT NULL) ENGINE=InnoDB;
INSERT INTO \`$RUNTIME_DB\`.content VALUES (1, 'previous-boundary-content');
INSERT INTO \`$RUNTIME_DB\`.state VALUES (1, 'previous-boundary-state');
INSERT INTO \`$RUNTIME_DB\`.guard VALUES (1, 1, 1, 0, 0, 0);

CREATE TABLE \`$CANDIDATE_DB\`.content (id INT PRIMARY KEY, marker VARCHAR(64) NOT NULL) ENGINE=InnoDB;
CREATE TABLE \`$CANDIDATE_DB\`.state (id INT PRIMARY KEY, marker VARCHAR(64) NOT NULL) ENGINE=InnoDB;
CREATE TABLE \`$CANDIDATE_DB\`.guard (id INT PRIMARY KEY, sanitized TINYINT NOT NULL, hardened TINYINT NOT NULL, webforms INT NOT NULL, sessions INT NOT NULL, queues INT NOT NULL) ENGINE=InnoDB;
INSERT INTO \`$CANDIDATE_DB\`.content VALUES (1, 'sanitized-candidate-content');
INSERT INTO \`$CANDIDATE_DB\`.state VALUES (1, 'sanitized-candidate-state');
INSERT INTO \`$CANDIDATE_DB\`.guard VALUES (1, 1, 1, 0, 0, 0);
"

runtime_table_count="$(scalar "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RUNTIME_DB' AND TABLE_TYPE='BASE TABLE';")"
candidate_table_count="$(scalar "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$CANDIDATE_DB' AND TABLE_TYPE='BASE TABLE';")"
[[ "$runtime_table_count" -eq 3 && "$candidate_table_count" -eq 3 ]] || {
  printf 'Synthetic table-set prerequisite failed.\n' >&2
  exit 71
}

non_base_objects="$(scalar "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ('$RUNTIME_DB','$CANDIDATE_DB') AND TABLE_TYPE <> 'BASE TABLE';")"
trigger_count="$(scalar "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ('$RUNTIME_DB','$CANDIDATE_DB');")"
event_count="$(scalar "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA IN ('$RUNTIME_DB','$CANDIDATE_DB');")"
foreign_key_count="$(scalar "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA IN ('$RUNTIME_DB','$CANDIDATE_DB');")"
[[ "$non_base_objects" -eq 0 && "$trigger_count" -eq 0 && "$event_count" -eq 0 && "$foreign_key_count" -eq 0 ]] || {
  printf 'Synthetic atomic-rename preflight subset failed.\n' >&2
  exit 72
}

candidate_guard="$(sql "SELECT CONCAT(sanitized,':',hardened,':',webforms,':',sessions,':',queues) FROM \`$CANDIDATE_DB\`.guard WHERE id=1;")"
[[ "$candidate_guard" == '1:1:0:0:0' ]] || {
  printf 'Candidate hardening gate failed.\n' >&2
  exit 73
}

"${dumper[@]}" "$RUNTIME_DB" > "$BACKUP_FILE"
chmod 600 "$BACKUP_FILE"
backup_bytes="$(stat -c '%s' "$BACKUP_FILE")"
backup_sha256="$(sha256sum "$BACKUP_FILE" | awk '{print $1}')"
[[ "$backup_bytes" =~ ^[1-9][0-9]*$ && "$backup_sha256" =~ ^[0-9a-f]{64}$ ]] || {
  printf 'Synthetic backup identity proof failed.\n' >&2
  exit 74
}

previous_runtime_marker_hash="$(sql "SELECT CONCAT(marker,':',(SELECT marker FROM \`$RUNTIME_DB\`.state WHERE id=1)) FROM \`$RUNTIME_DB\`.content WHERE id=1;" | sha256sum | awk '{print $1}')"
candidate_marker_hash="$(sql "SELECT CONCAT(marker,':',(SELECT marker FROM \`$CANDIDATE_DB\`.state WHERE id=1)) FROM \`$CANDIDATE_DB\`.content WHERE id=1;" | sha256sum | awk '{print $1}')"
[[ "$previous_runtime_marker_hash" != "$candidate_marker_hash" ]] || {
  printf 'Synthetic boundary identities unexpectedly collide.\n' >&2
  exit 75
}

sql "CREATE DATABASE \`$ROLLBACK_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

set +e
"${client[@]}" -e "
RENAME TABLE
  \`$RUNTIME_DB\`.content TO \`$ROLLBACK_DB\`.content,
  \`$CANDIDATE_DB\`.content TO \`$RUNTIME_DB\`.content,
  \`$RUNTIME_DB\`.state TO \`$ROLLBACK_DB\`.state,
  \`$CANDIDATE_DB\`.missing_required_table TO \`$RUNTIME_DB\`.state;
" >/dev/null 2>&1
forced_failure_rc="$?"
set -e
[[ "$forced_failure_rc" -ne 0 ]] || {
  printf 'Forced activation failure unexpectedly succeeded.\n' >&2
  exit 76
}

post_failure_runtime_hash="$(sql "SELECT CONCAT(marker,':',(SELECT marker FROM \`$RUNTIME_DB\`.state WHERE id=1)) FROM \`$RUNTIME_DB\`.content WHERE id=1;" | sha256sum | awk '{print $1}')"
post_failure_candidate_tables="$(scalar "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$CANDIDATE_DB' AND TABLE_TYPE='BASE TABLE';")"
post_failure_rollback_tables="$(scalar "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$ROLLBACK_DB' AND TABLE_TYPE='BASE TABLE';")"
[[ "$post_failure_runtime_hash" == "$previous_runtime_marker_hash" && "$post_failure_candidate_tables" -eq 3 && "$post_failure_rollback_tables" -eq 0 ]] || {
  printf 'Atomic failure did not preserve the exact previous synthetic boundary.\n' >&2
  exit 77
}

sql "
RENAME TABLE
  \`$RUNTIME_DB\`.content TO \`$ROLLBACK_DB\`.content,
  \`$CANDIDATE_DB\`.content TO \`$RUNTIME_DB\`.content,
  \`$RUNTIME_DB\`.state TO \`$ROLLBACK_DB\`.state,
  \`$CANDIDATE_DB\`.state TO \`$RUNTIME_DB\`.state,
  \`$RUNTIME_DB\`.guard TO \`$ROLLBACK_DB\`.guard,
  \`$CANDIDATE_DB\`.guard TO \`$RUNTIME_DB\`.guard;
"

post_activation_runtime_hash="$(sql "SELECT CONCAT(marker,':',(SELECT marker FROM \`$RUNTIME_DB\`.state WHERE id=1)) FROM \`$RUNTIME_DB\`.content WHERE id=1;" | sha256sum | awk '{print $1}')"
[[ "$post_activation_runtime_hash" == "$candidate_marker_hash" ]] || {
  printf 'Synthetic activation did not expose exactly the proved candidate.\n' >&2
  exit 78
}

sql "CREATE DATABASE \`$FAILED_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sql "
RENAME TABLE
  \`$RUNTIME_DB\`.content TO \`$FAILED_DB\`.content,
  \`$ROLLBACK_DB\`.content TO \`$RUNTIME_DB\`.content,
  \`$RUNTIME_DB\`.state TO \`$FAILED_DB\`.state,
  \`$ROLLBACK_DB\`.state TO \`$RUNTIME_DB\`.state,
  \`$RUNTIME_DB\`.guard TO \`$FAILED_DB\`.guard,
  \`$ROLLBACK_DB\`.guard TO \`$RUNTIME_DB\`.guard;
"

post_rollback_runtime_hash="$(sql "SELECT CONCAT(marker,':',(SELECT marker FROM \`$RUNTIME_DB\`.state WHERE id=1)) FROM \`$RUNTIME_DB\`.content WHERE id=1;" | sha256sum | awk '{print $1}')"
[[ "$post_rollback_runtime_hash" == "$previous_runtime_marker_hash" ]] || {
  printf 'Reverse atomic rename did not restore previous synthetic identity.\n' >&2
  exit 79
}

"${dumper[@]}" "$RUNTIME_DB" > "$ROLLBACK_DUMP"
chmod 600 "$ROLLBACK_DUMP"
rollback_dump_sha256="$(sha256sum "$ROLLBACK_DUMP" | awk '{print $1}')"
[[ "$rollback_dump_sha256" == "$backup_sha256" ]] || {
  printf 'Rollback dump identity differs from the pre-activation backup identity.\n' >&2
  exit 80
}

{
  printf 'schema_version=1\n'
  printf 'issue=868\n'
  printf 'synthetic_only=YES\n'
  printf 'real_prod_data_read=NONE\n'
  printf 'real_prod_data_transfer=NONE\n'
  printf 'real_preprod_mutation=NONE\n'
  printf 'candidate_isolated_before_activation=PASS\n'
  printf 'candidate_sanitized=PASS\n'
  printf 'candidate_side_effect_hardened=PASS\n'
  printf 'backup_required_before_activation=PASS\n'
  printf 'backup_byte_size=%s\n' "$backup_bytes"
  printf 'backup_sha256=%s\n' "$backup_sha256"
  printf 'safe_table_count=%s\n' "$runtime_table_count"
  printf 'atomic_forced_failure_preserved_previous=PASS\n'
  printf 'activation_exact_candidate=PASS\n'
  printf 'rollback_exact_previous_identity=PASS\n'
  printf 'rollback_dump_matches_backup=PASS\n'
  printf 'application_release_change=NONE\n'
  printf 'runtime_database_name_change=NONE\n'
  printf 'server_settings_mutation=NONE\n'
  printf 'prod_write_path=NONE\n'
  printf 'raw_data_in_github=NONE\n'
  printf 'evidence=METADATA_ONLY\n'
  printf 'real_activation=NOT_PERFORMED\n'
} > "$EVIDENCE_FILE"
chmod 600 "$EVIDENCE_FILE"

printf 'synthetic_activation_rollback_proof=PASS\n'
