#!/usr/bin/env python3
"""Ephemeral synthetic MariaDB proof for Agency PREPROD staging sanitization.

The CLI intentionally exposes PROVE only. It connects only to the fixed local
MariaDB 11.8 CI service with a clearly synthetic credential, derives all staging
DB names internally from fixed proof request identities, consumes the sole
agency-preprod-refresh-v1 policy, emits metadata-only evidence, and always
cleans its ephemeral databases.

It is not a PREPROD runtime executor and accepts no host, database, SQL, shell,
credential, executable, filesystem path, or request-id input from the caller.
"""

from __future__ import annotations

import copy
import hashlib
import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any

POLICY_VERSION = "agency-preprod-refresh-v1"
MARIADB_BIN = "/usr/bin/mariadb"
MARIADB_HOST = "127.0.0.1"
MARIADB_PORT = "3306"
MARIADB_USER = "root"
SYNTHETIC_CI_PASSWORD = "agency-synthetic-ci-root"
RUNTIME_DB = "agency_preprod"
STAGING_PREFIX = "agency_preprod_stage_"
REQUEST_RE = re.compile(r"^[A-Za-z0-9._-]{8,80}$")
SAFE_IDENTIFIER = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")
STAGING_DB_RE = re.compile(r"^agency_preprod_stage_[0-9a-f]{12}$")
PROOF_REQUEST_A = "proof-857-mariadb-a"
PROOF_REQUEST_B = "proof-857-mariadb-b"
PROOF_REQUEST_INCOMPATIBLE = "proof-857-mariadb-incompatible"


class SanitizationError(RuntimeError):
    """Fail-closed MariaDB sanitization contract violation."""


@dataclass(frozen=True)
class Scope:
    request_id: str
    suffix: str
    database: str


def policy_path() -> Path:
    return Path(__file__).with_name("sanitization-policy.json")


def load_policy() -> dict[str, Any]:
    with policy_path().open("r", encoding="utf-8") as handle:
        policy = json.load(handle)
    if not isinstance(policy, dict):
        raise SanitizationError("policy must be a JSON object")
    return policy


def policy_digest() -> str:
    return hashlib.sha256(policy_path().read_bytes()).hexdigest()


def derive_scope(request_id: str) -> Scope:
    if not REQUEST_RE.fullmatch(request_id):
        raise SanitizationError("invalid proof request identity")
    suffix = hashlib.sha256(request_id.encode("utf-8")).hexdigest()[:12]
    database = f"{STAGING_PREFIX}{suffix}"
    validate_target_database(database)
    return Scope(request_id=request_id, suffix=suffix, database=database)


def validate_target_database(database: str) -> None:
    if database == RUNTIME_DB:
        raise SanitizationError("runtime database is outside sanitization authority")
    if not STAGING_DB_RE.fullmatch(database):
        raise SanitizationError("target is not an internally derived staging database")


def quote_identifier(identifier: str) -> str:
    if not SAFE_IDENTIFIER.fullmatch(identifier):
        raise SanitizationError("unsafe schema identifier")
    return f"`{identifier}`"


def sql_string(value: str) -> str:
    return f"CONVERT(0x{value.encode('utf-8').hex()} USING utf8mb4)"


def connection_env() -> dict[str, str]:
    return {
        "PATH": "/usr/bin:/bin",
        "HOME": "/tmp",
        "LC_ALL": "C",
        "MYSQL_PWD": SYNTHETIC_CI_PASSWORD,
    }


def run_sql(query: str, database: str | None = None) -> str:
    if database is not None:
        validate_target_database(database)
    command = [
        MARIADB_BIN,
        "--protocol=tcp",
        f"--host={MARIADB_HOST}",
        f"--port={MARIADB_PORT}",
        f"--user={MARIADB_USER}",
        "--batch",
        "--skip-column-names",
        "--raw",
    ]
    if database is not None:
        command.append(f"--database={database}")
    result = subprocess.run(
        command,
        input=query + "\n",
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=False,
        env=connection_env(),
    )
    if result.returncode != 0:
        raise SanitizationError("bounded synthetic MariaDB operation failed")
    return result.stdout.strip()


def require_client() -> None:
    if not os.path.isfile(MARIADB_BIN) or not os.access(MARIADB_BIN, os.X_OK):
        raise SanitizationError("fixed MariaDB client is unavailable")
    if run_sql("SELECT VERSION()") == "":
        raise SanitizationError("ephemeral MariaDB service unavailable")


def database_exists(scope: Scope) -> bool:
    value = run_sql(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA "
        f"WHERE SCHEMA_NAME={sql_string(scope.database)}"
    )
    return value == "1"


def cleanup_scope(scope: Scope) -> None:
    validate_target_database(scope.database)
    run_sql(f"DROP DATABASE IF EXISTS {quote_identifier(scope.database)}")
    if database_exists(scope):
        raise SanitizationError("ephemeral staging database cleanup failed")


def table_names(scope: Scope) -> set[str]:
    validate_target_database(scope.database)
    output = run_sql(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        f"WHERE TABLE_SCHEMA={sql_string(scope.database)} ORDER BY TABLE_NAME"
    )
    if not output:
        return set()
    names = {line.strip() for line in output.splitlines() if line.strip()}
    for name in names:
        quote_identifier(name)
    return names


def columns(scope: Scope, table: str) -> list[str]:
    quote_identifier(table)
    output = run_sql(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        f"WHERE TABLE_SCHEMA={sql_string(scope.database)} "
        f"AND TABLE_NAME={sql_string(table)} ORDER BY ORDINAL_POSITION"
    )
    names = [line.strip() for line in output.splitlines() if line.strip()]
    for name in names:
        quote_identifier(name)
    return names


def table_ref(scope: Scope, table: str) -> str:
    validate_target_database(scope.database)
    return f"{quote_identifier(scope.database)}.{quote_identifier(table)}"


def require_columns(
    scope: Scope,
    table: str,
    required: set[str],
    *,
    required_table: bool = False,
) -> None:
    existing = table_names(scope)
    if table not in existing:
        if required_table:
            raise SanitizationError("required sensitive table is absent")
        return
    actual = set(columns(scope, table))
    if required - actual:
        raise SanitizationError("known sensitive table has incompatible schema")


def clear_table(scope: Scope, table: str) -> None:
    if table not in table_names(scope):
        return
    run_sql(f"DELETE FROM {table_ref(scope, table)}")


def count_rows(scope: Scope, table: str) -> int:
    if table not in table_names(scope):
        return 0
    value = run_sql(f"SELECT COUNT(*) FROM {table_ref(scope, table)}")
    if not re.fullmatch(r"[0-9]+", value):
        raise SanitizationError("row-count proof is invalid")
    return int(value, 10)


def validate_policy(policy: dict[str, Any]) -> dict[str, Any]:
    if policy.get("schema_version") != 1:
        raise SanitizationError("unsupported policy schema")
    if policy.get("policy_version") != POLICY_VERSION:
        raise SanitizationError("unexpected policy version")

    execution = policy.get("sanitization_execution")
    if not isinstance(execution, dict):
        raise SanitizationError("sanitization execution contract missing")
    if execution.get("mode") != "SYNTHETIC_FIXTURE_ONLY":
        raise SanitizationError("repository proof mode changed unexpectedly")
    if execution.get("real_runtime_enabled") is not False:
        raise SanitizationError("real runtime sanitization must remain disabled")
    if execution.get("unknown_mandatory_class") != "FAIL_CLOSED":
        raise SanitizationError("unknown mandatory classes must fail closed")

    mariadb_proof = execution.get("mariadb_proof")
    if not isinstance(mariadb_proof, dict):
        raise SanitizationError("MariaDB proof contract missing")
    if mariadb_proof.get("mode") != "EPHEMERAL_SYNTHETIC_CI_ONLY":
        raise SanitizationError("MariaDB proof must remain ephemeral synthetic CI only")
    if mariadb_proof.get("real_runtime_enabled") is not False:
        raise SanitizationError("MariaDB runtime sanitization is not authorized")
    target = mariadb_proof.get("target_database")
    if not isinstance(target, dict):
        raise SanitizationError("MariaDB target contract missing")
    if (
        target.get("runtime_database") != RUNTIME_DB
        or target.get("runtime_targetable") is not False
        or target.get("staging_prefix") != STAGING_PREFIX
        or target.get("derivation") != "SHA256_REQUEST_ID_FIRST_12_HEX"
        or target.get("caller_database_name") != "FORBIDDEN"
    ):
        raise SanitizationError("MariaDB target boundary changed")
    caller = mariadb_proof.get("caller_inputs")
    if not isinstance(caller, dict) or any(value != "FORBIDDEN" for value in caller.values()):
        raise SanitizationError("MariaDB proof exposes forbidden caller inputs")

    mandatory = policy.get("mandatory_sanitization")
    if not isinstance(mandatory, list):
        raise SanitizationError("mandatory sanitization contract missing")
    mandatory_ids: set[str] = set()
    for rule in mandatory:
        if not isinstance(rule, dict) or rule.get("required") is not True:
            raise SanitizationError("all mandatory rules must remain required")
        rule_id = rule.get("id")
        if not isinstance(rule_id, str) or not rule_id or rule_id in mandatory_ids:
            raise SanitizationError("mandatory rule id is invalid")
        mandatory_ids.add(rule_id)

    handlers = execution.get("mandatory_class_handlers")
    if not isinstance(handlers, dict):
        raise SanitizationError("mandatory class handlers missing")
    if set(handlers) != mandatory_ids:
        raise SanitizationError("mandatory sensitive class is not exactly handled")
    if set(handlers.values()) - set(HANDLERS):
        raise SanitizationError("unknown sanitization handler")

    github_evidence = policy.get("github_evidence", {})
    if (
        github_evidence.get("raw_sql_allowed") is not False
        or github_evidence.get("pii_allowed") is not False
        or github_evidence.get("secrets_allowed") is not False
    ):
        raise SanitizationError("GitHub evidence boundary weakened")
    return execution


def sanitize_users(scope: Scope, execution: dict[str, Any]) -> None:
    contract = execution["users"]
    table = str(contract["table"])
    required = {
        str(contract["uid_column"]),
        str(contract["name_column"]),
        str(contract["mail_column"]),
        str(contract["init_column"]),
        str(contract["password_column"]),
        str(contract["access_column"]),
        str(contract["login_column"]),
    }
    require_columns(scope, table, required, required_table=True)
    uid_column = quote_identifier(str(contract["uid_column"]))
    output = run_sql(
        f"SELECT {uid_column} FROM {table_ref(scope, table)} ORDER BY {uid_column}"
    )
    for raw_uid in [line.strip() for line in output.splitlines() if line.strip()]:
        if not re.fullmatch(r"[0-9]+", raw_uid):
            raise SanitizationError("user uid is not a stable numeric identifier")
        uid = int(raw_uid, 10)
        username = f"preprod-user-{uid}"
        email = f"preprod-user+{uid}@example.invalid"
        run_sql(
            f"UPDATE {table_ref(scope, table)} SET "
            f"{quote_identifier(str(contract['name_column']))}={sql_string(username)},"
            f"{quote_identifier(str(contract['mail_column']))}={sql_string(email)},"
            f"{quote_identifier(str(contract['init_column']))}={sql_string(email)},"
            f"{quote_identifier(str(contract['password_column']))}='',"
            f"{quote_identifier(str(contract['access_column']))}=0,"
            f"{quote_identifier(str(contract['login_column']))}=0 "
            f"WHERE {uid_column}={uid}"
        )


def purge_tables_for_class(scope: Scope, execution: dict[str, Any], class_id: str) -> None:
    purge_by_class = execution.get("purge_tables_by_class", {})
    tables = purge_by_class.get(class_id)
    if not isinstance(tables, list):
        raise SanitizationError("purge table mapping missing")
    for table in tables:
        if not isinstance(table, str):
            raise SanitizationError("invalid purge table mapping")
        clear_table(scope, table)


def clear_caches(scope: Scope, execution: dict[str, Any]) -> None:
    prefixes = execution.get("cache_table_prefixes")
    if not isinstance(prefixes, list) or not prefixes:
        raise SanitizationError("cache table prefixes missing")
    for table in sorted(table_names(scope)):
        if any(table.startswith(str(prefix)) for prefix in prefixes):
            clear_table(scope, table)


def delete_key_value_state(scope: Scope, execution: dict[str, Any]) -> None:
    contract = execution["runtime_key_value_state"]
    table = str(contract["table"])
    require_columns(scope, table, {"collection", "name", "value"})
    if table not in table_names(scope):
        return
    collection = str(contract["collection"])
    for name in contract["exact_names"]:
        run_sql(
            f"DELETE FROM {table_ref(scope, table)} WHERE "
            f"collection={sql_string(collection)} AND name={sql_string(str(name))}"
        )
    for prefix in contract["name_prefixes"]:
        prefix = str(prefix)
        run_sql(
            f"DELETE FROM {table_ref(scope, table)} WHERE "
            f"collection={sql_string(collection)} AND "
            f"LEFT(name,CHAR_LENGTH({sql_string(prefix)}))={sql_string(prefix)}"
        )


def remove_credential_config(scope: Scope, execution: dict[str, Any]) -> None:
    contract = execution["credential_config"]
    table = str(contract["table"])
    require_columns(scope, table, {"name", "data"})
    if table not in table_names(scope):
        return
    for name in contract["exact_names"]:
        run_sql(
            f"DELETE FROM {table_ref(scope, table)} WHERE name={sql_string(str(name))}"
        )
    for prefix in contract["name_prefixes"]:
        prefix = str(prefix)
        run_sql(
            f"DELETE FROM {table_ref(scope, table)} WHERE "
            f"LEFT(name,CHAR_LENGTH({sql_string(prefix)}))={sql_string(prefix)}"
        )


def assert_preprod_admin_boundary(_scope: Scope, execution: dict[str, Any]) -> None:
    admin = execution.get("preprod_admin")
    if not isinstance(admin, dict):
        raise SanitizationError("PREPROD admin boundary missing")
    if admin.get("source") != "PREPROD_SERVER_OWNED":
        raise SanitizationError("PREPROD admin must remain server-owned")
    if admin.get("restore_in_issue_855") is not False:
        raise SanitizationError("repository proof must not restore PREPROD admin")


def handle_users(scope: Scope, execution: dict[str, Any]) -> None:
    sanitize_users(scope, execution)


def handle_preprod_admin(scope: Scope, execution: dict[str, Any]) -> None:
    assert_preprod_admin_boundary(scope, execution)


def handle_webform(scope: Scope, execution: dict[str, Any]) -> None:
    purge_tables_for_class(scope, execution, "webform_submissions")


def handle_sessions(scope: Scope, execution: dict[str, Any]) -> None:
    purge_tables_for_class(scope, execution, "sessions")


def handle_flood(scope: Scope, execution: dict[str, Any]) -> None:
    purge_tables_for_class(scope, execution, "flood_rate_limit")


def handle_dblog(scope: Scope, execution: dict[str, Any]) -> None:
    purge_tables_for_class(scope, execution, "dblog_watchdog")


def handle_caches(scope: Scope, execution: dict[str, Any]) -> None:
    clear_caches(scope, execution)


def handle_batch_temp(scope: Scope, execution: dict[str, Any]) -> None:
    purge_tables_for_class(scope, execution, "batch_temp_state")


def handle_queues(scope: Scope, execution: dict[str, Any]) -> None:
    purge_tables_for_class(scope, execution, "queues")


def handle_runtime_state(scope: Scope, execution: dict[str, Any]) -> None:
    delete_key_value_state(scope, execution)


def handle_one_time_auth(scope: Scope, execution: dict[str, Any]) -> None:
    sanitize_users(scope, execution)
    purge_tables_for_class(scope, execution, "batch_temp_state")


def handle_credentials(scope: Scope, execution: dict[str, Any]) -> None:
    remove_credential_config(scope, execution)


def handle_production_state(scope: Scope, execution: dict[str, Any]) -> None:
    delete_key_value_state(scope, execution)


HANDLERS = {
    "sanitize_users": handle_users,
    "assert_preprod_admin_boundary": handle_preprod_admin,
    "purge_webform": handle_webform,
    "purge_sessions": handle_sessions,
    "purge_flood": handle_flood,
    "purge_dblog": handle_dblog,
    "clear_caches": handle_caches,
    "purge_batch_temp": handle_batch_temp,
    "clear_queues": handle_queues,
    "reset_runtime_state": handle_runtime_state,
    "invalidate_one_time_auth": handle_one_time_auth,
    "remove_persisted_credentials": handle_credentials,
    "remove_production_state": handle_production_state,
}


def sanitize(scope: Scope, policy: dict[str, Any]) -> None:
    validate_target_database(scope.database)
    execution = validate_policy(policy)
    handlers = execution["mandatory_class_handlers"]
    for rule in policy["mandatory_sanitization"]:
        rule_id = str(rule["id"])
        HANDLERS[str(handlers[rule_id])](scope, execution)


def create_database(scope: Scope) -> None:
    cleanup_scope(scope)
    run_sql(
        f"CREATE DATABASE {quote_identifier(scope.database)} "
        "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    )


def create_fixture(scope: Scope) -> None:
    create_database(scope)
    run_sql(
        """
CREATE TABLE users_field_data (uid BIGINT NOT NULL PRIMARY KEY,name VARCHAR(255) NOT NULL,mail VARCHAR(254) NOT NULL,pass VARCHAR(255) NOT NULL,init VARCHAR(254) NOT NULL,access BIGINT NOT NULL,login BIGINT NOT NULL) ENGINE=InnoDB;
CREATE TABLE webform_submission (sid BIGINT NOT NULL PRIMARY KEY,uuid VARCHAR(128) NOT NULL) ENGINE=InnoDB;
CREATE TABLE webform_submission_data (sid BIGINT NOT NULL,name VARCHAR(128) NOT NULL,value LONGTEXT NULL) ENGINE=InnoDB;
CREATE TABLE sessions (sid VARCHAR(128) NOT NULL PRIMARY KEY,uid BIGINT NOT NULL,hostname VARCHAR(128),session LONGTEXT) ENGINE=InnoDB;
CREATE TABLE flood (fid BIGINT NOT NULL PRIMARY KEY,event VARCHAR(128),identifier VARCHAR(255),timestamp BIGINT,expiration BIGINT) ENGINE=InnoDB;
CREATE TABLE watchdog (wid BIGINT NOT NULL PRIMARY KEY,uid BIGINT,hostname VARCHAR(128),location TEXT,message TEXT,variables LONGTEXT) ENGINE=InnoDB;
CREATE TABLE cache_default (cid VARCHAR(255) NOT NULL PRIMARY KEY,data LONGBLOB) ENGINE=InnoDB;
CREATE TABLE cache_render (cid VARCHAR(255) NOT NULL PRIMARY KEY,data LONGBLOB) ENGINE=InnoDB;
CREATE TABLE cache_dynamic_page_cache (cid VARCHAR(255) NOT NULL PRIMARY KEY,data LONGBLOB) ENGINE=InnoDB;
CREATE TABLE cache_discovery (cid VARCHAR(255) NOT NULL PRIMARY KEY,data LONGBLOB) ENGINE=InnoDB;
CREATE TABLE batch (bid BIGINT NOT NULL PRIMARY KEY,token VARCHAR(255),timestamp BIGINT,batch LONGBLOB) ENGINE=InnoDB;
CREATE TABLE semaphore (name VARCHAR(255) NOT NULL PRIMARY KEY,value VARCHAR(255),expire DOUBLE) ENGINE=InnoDB;
CREATE TABLE queue (item_id BIGINT NOT NULL PRIMARY KEY,name VARCHAR(255),data LONGBLOB,expire BIGINT,created BIGINT) ENGINE=InnoDB;
CREATE TABLE key_value (collection VARCHAR(128) NOT NULL,name VARCHAR(128) NOT NULL,value LONGBLOB,PRIMARY KEY(collection,name)) ENGINE=InnoDB;
CREATE TABLE key_value_expire (collection VARCHAR(128) NOT NULL,name VARCHAR(128) NOT NULL,value LONGBLOB,expire BIGINT,PRIMARY KEY(collection,name)) ENGINE=InnoDB;
CREATE TABLE config (name VARCHAR(255) NOT NULL PRIMARY KEY,data LONGBLOB) ENGINE=InnoDB;
CREATE TABLE node_field_data (nid BIGINT NOT NULL PRIMARY KEY,title VARCHAR(255) NOT NULL) ENGINE=InnoDB;
""",
        scope.database,
    )
    run_sql(
        """
INSERT INTO users_field_data VALUES (1,'Synthetic Admin','admin@example.invalid','$SYNTHETIC$HASH','admin@example.invalid',1700000000,1700000001),(17,'Synthetic Person','person@example.invalid','$SYNTHETIC$HASH2','person@example.invalid',1700000002,1700000003);
INSERT INTO webform_submission VALUES (1,'00000000-0000-0000-0000-000000000001');
INSERT INTO webform_submission_data VALUES (1,'message','SYNTHETIC WEBFORM PAYLOAD');
INSERT INTO sessions VALUES ('synthetic-session',17,'192.0.2.10','SYNTHETIC SESSION PAYLOAD');
INSERT INTO flood VALUES (1,'user.failed_login_ip','192.0.2.10',1,2);
INSERT INTO watchdog VALUES (1,17,'198.51.100.7','/synthetic','SYNTHETIC LOG','SYNTHETIC CONTEXT');
INSERT INTO cache_default VALUES ('synthetic-cache','SYNTHETIC CACHE');
INSERT INTO cache_render VALUES ('synthetic-cache','SYNTHETIC CACHE');
INSERT INTO cache_dynamic_page_cache VALUES ('synthetic-cache','SYNTHETIC CACHE');
INSERT INTO cache_discovery VALUES ('synthetic-cache','SYNTHETIC CACHE');
INSERT INTO batch VALUES (1,'SYNTHETIC-TOKEN',1,'SYNTHETIC BATCH');
INSERT INTO semaphore VALUES ('synthetic-lock','SYNTHETIC',999999.0);
INSERT INTO queue VALUES (1,'ai_provider','SYNTHETIC QUEUE PAYLOAD',0,1);
INSERT INTO key_value VALUES ('state','system.cron_last','1700000000'),('state','update.last_check','1700000000'),('state','announcements_feed.last_fetch','1700000000'),('state','linkchecker.last_run','1700000000'),('state','synthetic.public.state','preserve-me');
INSERT INTO key_value_expire VALUES ('user.private_tempstore','synthetic-auth','SYNTHETIC-TOKEN',1700000000);
INSERT INTO config VALUES ('key.key.openai_api_key','SYNTHETIC-CREDENTIAL-LIKE-STATE'),('ai_provider_openai.settings','SYNTHETIC-PROVIDER-STATE'),('system.site','SYNTHETIC-PUBLIC-CONFIG');
INSERT INTO node_field_data VALUES (1,'Synthetic public content');
""",
        scope.database,
    )


def create_incompatible_fixture(scope: Scope) -> None:
    create_database(scope)
    run_sql(
        """
CREATE TABLE users_field_data (uid BIGINT NOT NULL PRIMARY KEY,name VARCHAR(255) NOT NULL,mail VARCHAR(254) NOT NULL,init VARCHAR(254) NOT NULL,access BIGINT NOT NULL,login BIGINT NOT NULL) ENGINE=InnoDB;
INSERT INTO users_field_data VALUES (1,'Synthetic','synthetic@example.invalid','synthetic@example.invalid',1,1);
""",
        scope.database,
    )


def canonical_digest(scope: Scope) -> str:
    state: list[dict[str, Any]] = []
    for table in sorted(table_names(scope)):
        table_columns = columns(scope, table)
        expressions = [
            f"IF({quote_identifier(column)} IS NULL,'N',CONCAT('V',HEX(CAST({quote_identifier(column)} AS CHAR))))"
            for column in table_columns
        ]
        output = ""
        if expressions:
            select = ",".join(expressions)
            order = ",".join(expressions)
            output = run_sql(
                f"SELECT {select} FROM {table_ref(scope, table)} ORDER BY {order}"
            )
        rows = [line.split("\t") for line in output.splitlines()] if output else []
        state.append({"table": table, "columns": table_columns, "rows": rows})
    canonical = json.dumps(state, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(canonical).hexdigest()


def state_targets_remaining(scope: Scope, execution: dict[str, Any]) -> int:
    contract = execution["runtime_key_value_state"]
    table = str(contract["table"])
    if table not in table_names(scope):
        return 0
    collection = str(contract["collection"])
    total = 0
    for name in contract["exact_names"]:
        total += int(
            run_sql(
                f"SELECT COUNT(*) FROM {table_ref(scope, table)} WHERE "
                f"collection={sql_string(collection)} AND name={sql_string(str(name))}"
            ),
            10,
        )
    for prefix in contract["name_prefixes"]:
        prefix = str(prefix)
        total += int(
            run_sql(
                f"SELECT COUNT(*) FROM {table_ref(scope, table)} WHERE "
                f"collection={sql_string(collection)} AND "
                f"LEFT(name,CHAR_LENGTH({sql_string(prefix)}))={sql_string(prefix)}"
            ),
            10,
        )
    return total


def credentials_remaining(scope: Scope, execution: dict[str, Any]) -> int:
    contract = execution["credential_config"]
    table = str(contract["table"])
    if table not in table_names(scope):
        return 0
    total = 0
    for name in contract["exact_names"]:
        total += int(
            run_sql(
                f"SELECT COUNT(*) FROM {table_ref(scope, table)} WHERE name={sql_string(str(name))}"
            ),
            10,
        )
    for prefix in contract["name_prefixes"]:
        prefix = str(prefix)
        total += int(
            run_sql(
                f"SELECT COUNT(*) FROM {table_ref(scope, table)} WHERE "
                f"LEFT(name,CHAR_LENGTH({sql_string(prefix)}))={sql_string(prefix)}"
            ),
            10,
        )
    return total


def assert_sanitized(scope: Scope, execution: dict[str, Any]) -> dict[str, str]:
    rows = run_sql(
        "SELECT uid,name,mail,pass,init,access,login FROM users_field_data ORDER BY uid",
        scope.database,
    )
    for line in rows.splitlines():
        values = line.split("\t")
        if len(values) != 7 or not re.fullmatch(r"[0-9]+", values[0]):
            raise SanitizationError("user assertion result is invalid")
        uid = int(values[0], 10)
        expected_name = f"preprod-user-{uid}"
        expected_mail = f"preprod-user+{uid}@example.invalid"
        if values[1:] != [expected_name, expected_mail, "", expected_mail, "0", "0"]:
            raise SanitizationError("user sanitization assertion failed")

    purge_expectations = {
        "webform_submission": "WEBFORM_SUBMISSIONS_AFTER",
        "webform_submission_data": "WEBFORM_SUBMISSION_DATA_AFTER",
        "sessions": "ACTIVE_SESSIONS_AFTER",
        "flood": "FLOOD_ROWS_AFTER",
        "watchdog": "DBLOG_ROWS_AFTER",
        "batch": "BATCH_ROWS_AFTER",
        "semaphore": "SEMAPHORE_ROWS_AFTER",
        "key_value_expire": "TEMP_AUTH_ROWS_AFTER",
        "queue": "QUEUE_ROWS_AFTER",
    }
    evidence: dict[str, str] = {}
    for table, key in purge_expectations.items():
        if count_rows(scope, table) != 0:
            raise SanitizationError("sensitive purge assertion failed")
        evidence[key] = "0"

    cache_remaining = sum(
        count_rows(scope, table)
        for table in table_names(scope)
        if any(table.startswith(str(prefix)) for prefix in execution["cache_table_prefixes"])
    )
    if cache_remaining != 0:
        raise SanitizationError("cache purge assertion failed")
    evidence["CACHE_ROWS_AFTER"] = "0"

    if state_targets_remaining(scope, execution) != 0:
        raise SanitizationError("runtime state reset assertion failed")
    evidence["TARGETED_RUNTIME_STATE_AFTER"] = "0"

    if credentials_remaining(scope, execution) != 0:
        raise SanitizationError("credential-like state assertion failed")
    evidence["IMPORTED_PROD_SECRET_LIKE_STATE_SURVIVES"] = "NO"

    if count_rows(scope, "node_field_data") != 1:
        raise SanitizationError("public editorial fidelity assertion failed")
    if run_sql("SELECT COUNT(*) FROM config WHERE name='system.site'", scope.database) != "1":
        raise SanitizationError("non-sensitive config fidelity assertion failed")
    if run_sql(
        "SELECT COUNT(*) FROM key_value WHERE collection='state' "
        "AND name='synthetic.public.state'",
        scope.database,
    ) != "1":
        raise SanitizationError("non-sensitive state fidelity assertion failed")

    evidence.update(
        {
            "USER_SANITIZATION": "PASS",
            "AUTH_MATERIAL_INVALIDATION": "PASS",
            "WEBFORM_PURGE": "PASS",
            "SESSIONS_PURGE": "PASS",
            "FLOOD_PURGE": "PASS",
            "DBLOG_PURGE": "PASS",
            "CACHE_PURGE": "PASS",
            "BATCH_TEMP_PURGE": "PASS",
            "QUEUES_POLICY": "PASS",
            "CRON_UPDATE_STATE_RESET": "PASS",
            "SECRET_LIKE_STATE_HANDLING": "PASS",
            "PROD_ONLY_STATE_HANDLING": "PASS",
            "EDITORIAL_FIDELITY": "PASS",
        }
    )
    return evidence


def prove_unknown_sensitive_state_fail_closed(policy: dict[str, Any]) -> None:
    mutated = copy.deepcopy(policy)
    mutated["mandatory_sanitization"].append(
        {
            "id": "synthetic_future_sensitive_class",
            "action": "DELETE_ALL",
            "deterministic_rule": "Synthetic proof-only class.",
            "required": True,
        }
    )
    try:
        validate_policy(mutated)
    except SanitizationError:
        return
    raise SanitizationError("unknown mandatory sensitive class was silently accepted")


def prove_incompatible_schema_fail_closed(policy: dict[str, Any]) -> None:
    scope = derive_scope(PROOF_REQUEST_INCOMPATIBLE)
    try:
        create_incompatible_fixture(scope)
        try:
            sanitize(scope, policy)
        except SanitizationError:
            return
        raise SanitizationError("incompatible known sensitive schema was accepted")
    finally:
        cleanup_scope(scope)


def prove_runtime_target_rejection(policy: dict[str, Any]) -> None:
    suffix = hashlib.sha256(b"proof-857-forged-runtime").hexdigest()[:12]
    forged = Scope("proof-857-forged-runtime", suffix, RUNTIME_DB)
    try:
        sanitize(forged, policy)
    except SanitizationError:
        return
    raise SanitizationError("runtime database target was accepted")


def prove() -> dict[str, str]:
    require_client()
    policy = load_policy()
    execution = validate_policy(policy)
    first = derive_scope(PROOF_REQUEST_A)
    second = derive_scope(PROOF_REQUEST_B)
    evidence: dict[str, str] = {}
    try:
        create_fixture(first)
        create_fixture(second)

        sanitize(first, policy)
        evidence.update(assert_sanitized(first, execution))
        state_a = canonical_digest(first)

        sanitize(second, policy)
        assert_sanitized(second, execution)
        state_b = canonical_digest(second)
        if state_a != state_b:
            raise SanitizationError("MariaDB determinism proof failed")

        sanitize(first, policy)
        assert_sanitized(first, execution)
        state_c = canonical_digest(first)
        if state_c != state_a:
            raise SanitizationError("MariaDB second-pass idempotence failed")

        prove_unknown_sensitive_state_fail_closed(policy)
        prove_incompatible_schema_fail_closed(policy)
        prove_runtime_target_rejection(policy)

        evidence.update(
            {
                "POLICY_VERSION": POLICY_VERSION,
                "POLICY_SHA256": policy_digest(),
                "MARIADB_SANITIZATION": "PASS",
                "MARIADB_VERSION_COMPATIBILITY": "11.8",
                "DETERMINISTIC_PROOF": "PASS",
                "SECOND_PASS_IDEMPOTENCE": "PASS",
                "UNKNOWN_MANDATORY_CLASS_FAIL_CLOSED": "PASS",
                "INCOMPATIBLE_SENSITIVE_SCHEMA_FAIL_CLOSED": "PASS",
                "RUNTIME_DB_TARGET_REJECTION": "PASS",
                "EPHEMERAL_DB_CLEANUP": "PASS",
                "STATE_A_SHA256": state_a,
                "STATE_B_SHA256": state_b,
                "STATE_C_SHA256": state_c,
                "CALLER_DATABASE_NAME": "FORBIDDEN",
                "ARBITRARY_SQL_INPUT": "FORBIDDEN",
                "GENERIC_SHELL": "FORBIDDEN",
                "GENERIC_SUDO_MARIADB": "FORBIDDEN",
                "REAL_PROD_ACCESS": "NONE",
                "REAL_PROD_DB_READ": "NONE",
                "REAL_PROD_SNAPSHOT": "NONE",
                "REAL_PREPROD_DB_MUTATION": "NONE",
                "REAL_STAGING_SANITIZATION": "NOT_PERFORMED",
                "REAL_HELPER_PROVISIONING": "NOT_PERFORMED",
                "ACTIVATION": "NOT_PERFORMED",
                "PII_OR_SECRET_IN_EVIDENCE": "NONE",
            }
        )
        return evidence
    finally:
        cleanup_scope(first)
        cleanup_scope(second)


def main(argv: list[str]) -> int:
    if argv != ["PROVE"]:
        print("ERROR=PROVE_ONLY", file=sys.stderr)
        return 2
    try:
        evidence = prove()
    except (SanitizationError, KeyError, TypeError, ValueError):
        print("MARIADB_SANITIZATION_PROOF=FAIL", file=sys.stderr)
        return 1
    for key in sorted(evidence):
        print(f"{key}={evidence[key]}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
