#!/usr/bin/env python3
"""Synthetic-only proof for Agency PREPROD staging database sanitization.

This executable intentionally exposes only the PROVE mode. It creates isolated
in-memory SQLite fixtures containing obviously synthetic sentinel values,
sanitizes them through the repository policy, and emits metadata-only evidence.
It has no host, database, credential, SSH, MariaDB, or arbitrary SQL input.
"""

from __future__ import annotations

import copy
import hashlib
import json
import re
import sqlite3
import sys
from pathlib import Path
from typing import Any

POLICY_VERSION = "agency-preprod-refresh-v1"
SAFE_IDENTIFIER = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")


class SanitizationError(RuntimeError):
    """Fail-closed sanitization contract violation."""


def policy_path() -> Path:
    return Path(__file__).with_name("sanitization-policy.json")


def load_policy() -> dict[str, Any]:
    with policy_path().open("r", encoding="utf-8") as handle:
        policy = json.load(handle)
    if not isinstance(policy, dict):
        raise SanitizationError("policy must be a JSON object")
    return policy


def quote_identifier(identifier: str) -> str:
    if not SAFE_IDENTIFIER.fullmatch(identifier):
        raise SanitizationError("unsafe schema identifier")
    return f'"{identifier}"'


def table_names(connection: sqlite3.Connection) -> set[str]:
    rows = connection.execute(
        "SELECT name FROM sqlite_master "
        "WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
    ).fetchall()
    return {str(row[0]) for row in rows}


def columns(connection: sqlite3.Connection, table: str) -> list[str]:
    quoted = quote_identifier(table)
    return [
        str(row[1])
        for row in connection.execute(f"PRAGMA table_info({quoted})").fetchall()
    ]


def require_columns(
    connection: sqlite3.Connection,
    table: str,
    required: set[str],
) -> None:
    if table not in table_names(connection):
        return
    actual = set(columns(connection, table))
    missing = sorted(required - actual)
    if missing:
        raise SanitizationError(
            f"known sensitive table {table} is missing required columns: "
            + ",".join(missing)
        )


def clear_table(connection: sqlite3.Connection, table: str) -> None:
    if table not in table_names(connection):
        return
    connection.execute(f"DELETE FROM {quote_identifier(table)}")


def count_rows(connection: sqlite3.Connection, table: str) -> int:
    if table not in table_names(connection):
        return 0
    row = connection.execute(
        f"SELECT COUNT(*) FROM {quote_identifier(table)}"
    ).fetchone()
    return int(row[0])


def validate_policy(policy: dict[str, Any]) -> dict[str, Any]:
    if policy.get("schema_version") != 1:
        raise SanitizationError("unsupported policy schema")
    if policy.get("policy_version") != POLICY_VERSION:
        raise SanitizationError("unexpected policy version")

    execution = policy.get("sanitization_execution")
    if not isinstance(execution, dict):
        raise SanitizationError("sanitization execution contract missing")
    if execution.get("mode") != "SYNTHETIC_FIXTURE_ONLY":
        raise SanitizationError("real sanitization mode is not authorized")
    if execution.get("real_runtime_enabled") is not False:
        raise SanitizationError("real runtime sanitization must remain disabled")
    if execution.get("unknown_mandatory_class") != "FAIL_CLOSED":
        raise SanitizationError("unknown mandatory classes must fail closed")

    mandatory = policy.get("mandatory_sanitization")
    if not isinstance(mandatory, list):
        raise SanitizationError("mandatory sanitization contract missing")

    mandatory_ids: set[str] = set()
    for rule in mandatory:
        if not isinstance(rule, dict) or rule.get("required") is not True:
            raise SanitizationError("all mandatory rules must remain required")
        rule_id = rule.get("id")
        if not isinstance(rule_id, str) or not rule_id:
            raise SanitizationError("mandatory rule id is invalid")
        if rule_id in mandatory_ids:
            raise SanitizationError("duplicate mandatory rule id")
        mandatory_ids.add(rule_id)

    handlers = execution.get("mandatory_class_handlers")
    if not isinstance(handlers, dict):
        raise SanitizationError("mandatory class handlers missing")
    handler_ids = set(handlers.keys())
    if handler_ids != mandatory_ids:
        raise SanitizationError("mandatory sensitive class is not exactly handled")

    unknown_handlers = sorted(set(handlers.values()) - set(HANDLERS.keys()))
    if unknown_handlers:
        raise SanitizationError("unknown sanitization handler")

    github_evidence = policy.get("github_evidence", {})
    if (
        github_evidence.get("raw_sql_allowed") is not False
        or github_evidence.get("pii_allowed") is not False
        or github_evidence.get("secrets_allowed") is not False
    ):
        raise SanitizationError("GitHub evidence boundary weakened")

    return execution


def sanitize_users(connection: sqlite3.Connection, execution: dict[str, Any]) -> None:
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
    require_columns(connection, table, required)
    if table not in table_names(connection):
        return

    uid_column = quote_identifier(str(contract["uid_column"]))
    rows = connection.execute(
        f"SELECT {uid_column} FROM {quote_identifier(table)} ORDER BY {uid_column}"
    ).fetchall()

    for row in rows:
        uid = int(row[0])
        username = f"preprod-user-{uid}"
        email = f"preprod-user+{uid}@example.invalid"
        connection.execute(
            f"UPDATE {quote_identifier(table)} SET "
            f"{quote_identifier(str(contract['name_column']))} = ?, "
            f"{quote_identifier(str(contract['mail_column']))} = ?, "
            f"{quote_identifier(str(contract['init_column']))} = ?, "
            f"{quote_identifier(str(contract['password_column']))} = '', "
            f"{quote_identifier(str(contract['access_column']))} = 0, "
            f"{quote_identifier(str(contract['login_column']))} = 0 "
            f"WHERE {uid_column} = ?",
            (username, email, email, uid),
        )


def purge_tables_for_class(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
    class_id: str,
) -> None:
    purge_by_class = execution.get("purge_tables_by_class", {})
    tables = purge_by_class.get(class_id)
    if not isinstance(tables, list):
        raise SanitizationError(f"purge table mapping missing for {class_id}")
    for table in tables:
        if not isinstance(table, str):
            raise SanitizationError("invalid purge table name")
        clear_table(connection, table)


def clear_caches(connection: sqlite3.Connection, execution: dict[str, Any]) -> None:
    prefixes = execution.get("cache_table_prefixes")
    if not isinstance(prefixes, list) or not prefixes:
        raise SanitizationError("cache table prefixes missing")
    existing = table_names(connection)
    for table in sorted(existing):
        if any(table.startswith(str(prefix)) for prefix in prefixes):
            clear_table(connection, table)


def delete_key_value_state(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> None:
    contract = execution["runtime_key_value_state"]
    table = str(contract["table"])
    require_columns(connection, table, {"collection", "name", "value"})
    if table not in table_names(connection):
        return

    collection = str(contract["collection"])
    for name in contract["exact_names"]:
        connection.execute(
            f"DELETE FROM {quote_identifier(table)} "
            "WHERE collection = ? AND name = ?",
            (collection, str(name)),
        )
    for prefix in contract["name_prefixes"]:
        escaped = (
            str(prefix)
            .replace("\\", "\\\\")
            .replace("%", "\\%")
            .replace("_", "\\_")
        )
        connection.execute(
            f"DELETE FROM {quote_identifier(table)} "
            "WHERE collection = ? AND name LIKE ? ESCAPE '\\'",
            (collection, escaped + "%"),
        )


def remove_credential_config(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> None:
    contract = execution["credential_config"]
    table = str(contract["table"])
    require_columns(connection, table, {"name", "data"})
    if table not in table_names(connection):
        return

    for name in contract["exact_names"]:
        connection.execute(
            f"DELETE FROM {quote_identifier(table)} WHERE name = ?",
            (str(name),),
        )
    for prefix in contract["name_prefixes"]:
        escaped = (
            str(prefix)
            .replace("\\", "\\\\")
            .replace("%", "\\%")
            .replace("_", "\\_")
        )
        connection.execute(
            f"DELETE FROM {quote_identifier(table)} WHERE name LIKE ? ESCAPE '\\'",
            (escaped + "%",),
        )


def assert_preprod_admin_boundary(
    _connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> None:
    admin = execution.get("preprod_admin")
    if not isinstance(admin, dict):
        raise SanitizationError("PREPROD admin boundary missing")
    if admin.get("source") != "PREPROD_SERVER_OWNED":
        raise SanitizationError("PREPROD admin must remain server-owned")
    if admin.get("restore_in_issue_855") is not False:
        raise SanitizationError("issue #855 must not restore a real PREPROD admin")


def handle_users(connection: sqlite3.Connection, execution: dict[str, Any]) -> None:
    sanitize_users(connection, execution)


def handle_preprod_admin(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> None:
    assert_preprod_admin_boundary(connection, execution)


def handle_webform(connection: sqlite3.Connection, execution: dict[str, Any]) -> None:
    purge_tables_for_class(connection, execution, "webform_submissions")


def handle_sessions(connection: sqlite3.Connection, execution: dict[str, Any]) -> None:
    purge_tables_for_class(connection, execution, "sessions")


def handle_flood(connection: sqlite3.Connection, execution: dict[str, Any]) -> None:
    purge_tables_for_class(connection, execution, "flood_rate_limit")


def handle_dblog(connection: sqlite3.Connection, execution: dict[str, Any]) -> None:
    purge_tables_for_class(connection, execution, "dblog_watchdog")


def handle_caches(connection: sqlite3.Connection, execution: dict[str, Any]) -> None:
    clear_caches(connection, execution)


def handle_batch_temp(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> None:
    purge_tables_for_class(connection, execution, "batch_temp_state")


def handle_queues(connection: sqlite3.Connection, execution: dict[str, Any]) -> None:
    purge_tables_for_class(connection, execution, "queues")


def handle_runtime_state(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> None:
    delete_key_value_state(connection, execution)


def handle_one_time_auth(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> None:
    # User password/init material is invalidated by the users handler. Expiring
    # temporary/auth state is cleared wholesale by the batch/temp handler.
    sanitize_users(connection, execution)
    purge_tables_for_class(connection, execution, "batch_temp_state")


def handle_credentials(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> None:
    remove_credential_config(connection, execution)


def handle_production_state(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> None:
    delete_key_value_state(connection, execution)


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


def sanitize(connection: sqlite3.Connection, policy: dict[str, Any]) -> None:
    execution = validate_policy(policy)
    handlers = execution["mandatory_class_handlers"]
    for rule in policy["mandatory_sanitization"]:
        rule_id = str(rule["id"])
        handler_name = str(handlers[rule_id])
        HANDLERS[handler_name](connection, execution)
    connection.commit()


def create_fixture() -> sqlite3.Connection:
    connection = sqlite3.connect(":memory:")
    connection.executescript(
        """
        CREATE TABLE users_field_data (
          uid INTEGER PRIMARY KEY,
          name TEXT NOT NULL,
          mail TEXT NOT NULL,
          pass TEXT NOT NULL,
          init TEXT NOT NULL,
          access INTEGER NOT NULL,
          login INTEGER NOT NULL
        );
        CREATE TABLE webform_submission (sid INTEGER PRIMARY KEY, uuid TEXT NOT NULL);
        CREATE TABLE webform_submission_data (sid INTEGER NOT NULL, name TEXT NOT NULL, value TEXT);
        CREATE TABLE sessions (sid TEXT PRIMARY KEY, uid INTEGER NOT NULL, hostname TEXT, session TEXT);
        CREATE TABLE flood (fid INTEGER PRIMARY KEY, event TEXT, identifier TEXT, timestamp INTEGER, expiration INTEGER);
        CREATE TABLE watchdog (wid INTEGER PRIMARY KEY, uid INTEGER, hostname TEXT, location TEXT, message TEXT, variables TEXT);
        CREATE TABLE cache_default (cid TEXT PRIMARY KEY, data TEXT);
        CREATE TABLE cache_render (cid TEXT PRIMARY KEY, data TEXT);
        CREATE TABLE cache_dynamic_page_cache (cid TEXT PRIMARY KEY, data TEXT);
        CREATE TABLE cache_discovery (cid TEXT PRIMARY KEY, data TEXT);
        CREATE TABLE batch (bid INTEGER PRIMARY KEY, token TEXT, timestamp INTEGER, batch TEXT);
        CREATE TABLE semaphore (name TEXT PRIMARY KEY, value TEXT, expire REAL);
        CREATE TABLE queue (item_id INTEGER PRIMARY KEY, name TEXT, data TEXT, expire INTEGER, created INTEGER);
        CREATE TABLE key_value (collection TEXT NOT NULL, name TEXT NOT NULL, value TEXT, PRIMARY KEY (collection, name));
        CREATE TABLE key_value_expire (collection TEXT NOT NULL, name TEXT NOT NULL, value TEXT, expire INTEGER, PRIMARY KEY (collection, name));
        CREATE TABLE config (name TEXT PRIMARY KEY, data TEXT);
        CREATE TABLE node_field_data (nid INTEGER PRIMARY KEY, title TEXT NOT NULL);
        """
    )
    connection.executemany(
        "INSERT INTO users_field_data(uid,name,mail,pass,init,access,login) VALUES(?,?,?,?,?,?,?)",
        [
            (
                1,
                "Synthetic Admin",
                "admin@example.invalid",
                "$SYNTHETIC$HASH",
                "admin@example.invalid",
                1700000000,
                1700000001,
            ),
            (
                17,
                "Synthetic Person",
                "person@example.invalid",
                "$SYNTHETIC$HASH2",
                "person@example.invalid",
                1700000002,
                1700000003,
            ),
        ],
    )
    connection.execute(
        "INSERT INTO webform_submission VALUES(1,'00000000-0000-0000-0000-000000000001')"
    )
    connection.execute(
        "INSERT INTO webform_submission_data VALUES(1,'message','SYNTHETIC WEBFORM PAYLOAD')"
    )
    connection.execute(
        "INSERT INTO sessions VALUES('synthetic-session',17,'192.0.2.10','SYNTHETIC SESSION PAYLOAD')"
    )
    connection.execute(
        "INSERT INTO flood VALUES(1,'user.failed_login_ip','192.0.2.10',1,2)"
    )
    connection.execute(
        "INSERT INTO watchdog VALUES(1,17,'198.51.100.7','/synthetic','SYNTHETIC LOG','SYNTHETIC CONTEXT')"
    )
    for table in (
        "cache_default",
        "cache_render",
        "cache_dynamic_page_cache",
        "cache_discovery",
    ):
        connection.execute(
            f"INSERT INTO {table} VALUES('synthetic-cache','SYNTHETIC CACHE')"
        )
    connection.execute(
        "INSERT INTO batch VALUES(1,'SYNTHETIC-TOKEN',1,'SYNTHETIC BATCH')"
    )
    connection.execute(
        "INSERT INTO semaphore VALUES('synthetic-lock','SYNTHETIC',999999.0)"
    )
    connection.execute(
        "INSERT INTO queue VALUES(1,'ai_provider','SYNTHETIC QUEUE PAYLOAD',0,1)"
    )
    connection.executemany(
        "INSERT INTO key_value(collection,name,value) VALUES(?,?,?)",
        [
            ("state", "system.cron_last", "1700000000"),
            ("state", "update.last_check", "1700000000"),
            ("state", "announcements_feed.last_fetch", "1700000000"),
            ("state", "linkchecker.last_run", "1700000000"),
            ("state", "synthetic.public.state", "preserve-me"),
        ],
    )
    connection.execute(
        "INSERT INTO key_value_expire VALUES('user.private_tempstore','synthetic-auth','SYNTHETIC-TOKEN',1700000000)"
    )
    connection.executemany(
        "INSERT INTO config(name,data) VALUES(?,?)",
        [
            ("key.key.openai_api_key", "SYNTHETIC-CREDENTIAL-LIKE-STATE"),
            ("ai_provider_openai.settings", "SYNTHETIC-PROVIDER-STATE"),
            ("system.site", "SYNTHETIC-PUBLIC-CONFIG"),
        ],
    )
    connection.execute(
        "INSERT INTO node_field_data VALUES(1,'Synthetic public content')"
    )
    connection.commit()
    return connection


def canonical_digest(connection: sqlite3.Connection) -> str:
    state: list[dict[str, Any]] = []
    for table in sorted(table_names(connection)):
        table_columns = columns(connection, table)
        quoted_columns = ", ".join(
            quote_identifier(column) for column in table_columns
        )
        rows = connection.execute(
            f"SELECT {quoted_columns} FROM {quote_identifier(table)}"
        ).fetchall()
        normalized_rows = []
        for row in rows:
            normalized = [
                value.hex() if isinstance(value, bytes) else value
                for value in row
            ]
            normalized_rows.append(normalized)
        normalized_rows.sort(
            key=lambda row: json.dumps(
                row,
                sort_keys=True,
                separators=(",", ":"),
            )
        )
        state.append(
            {
                "table": table,
                "columns": table_columns,
                "rows": normalized_rows,
            }
        )
    canonical = json.dumps(
        state,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")
    return hashlib.sha256(canonical).hexdigest()


def state_targets_remaining(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> int:
    contract = execution["runtime_key_value_state"]
    table = str(contract["table"])
    if table not in table_names(connection):
        return 0
    count = 0
    collection = str(contract["collection"])
    for name in contract["exact_names"]:
        row = connection.execute(
            f"SELECT COUNT(*) FROM {quote_identifier(table)} "
            "WHERE collection = ? AND name = ?",
            (collection, str(name)),
        ).fetchone()
        count += int(row[0])
    for prefix in contract["name_prefixes"]:
        escaped = (
            str(prefix)
            .replace("\\", "\\\\")
            .replace("%", "\\%")
            .replace("_", "\\_")
        )
        row = connection.execute(
            f"SELECT COUNT(*) FROM {quote_identifier(table)} "
            "WHERE collection = ? AND name LIKE ? ESCAPE '\\'",
            (collection, escaped + "%"),
        ).fetchone()
        count += int(row[0])
    return count


def credentials_remaining(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> int:
    contract = execution["credential_config"]
    table = str(contract["table"])
    if table not in table_names(connection):
        return 0
    count = 0
    for name in contract["exact_names"]:
        row = connection.execute(
            f"SELECT COUNT(*) FROM {quote_identifier(table)} WHERE name = ?",
            (str(name),),
        ).fetchone()
        count += int(row[0])
    for prefix in contract["name_prefixes"]:
        escaped = (
            str(prefix)
            .replace("\\", "\\\\")
            .replace("%", "\\%")
            .replace("_", "\\_")
        )
        row = connection.execute(
            f"SELECT COUNT(*) FROM {quote_identifier(table)} "
            "WHERE name LIKE ? ESCAPE '\\'",
            (escaped + "%",),
        ).fetchone()
        count += int(row[0])
    return count


def assert_sanitized(
    connection: sqlite3.Connection,
    execution: dict[str, Any],
) -> dict[str, str]:
    users = connection.execute(
        "SELECT uid,name,mail,pass,init,access,login "
        "FROM users_field_data ORDER BY uid"
    ).fetchall()
    for uid, name, mail, password, init, access, login in users:
        expected_name = f"preprod-user-{int(uid)}"
        expected_mail = f"preprod-user+{int(uid)}@example.invalid"
        if (
            name != expected_name
            or mail != expected_mail
            or init != expected_mail
            or password != ""
            or int(access) != 0
            or int(login) != 0
        ):
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
        remaining = count_rows(connection, table)
        if remaining != 0:
            raise SanitizationError(f"{table} purge assertion failed")
        evidence[key] = "0"

    cache_remaining = sum(
        count_rows(connection, table)
        for table in table_names(connection)
        if any(
            table.startswith(str(prefix))
            for prefix in execution["cache_table_prefixes"]
        )
    )
    if cache_remaining != 0:
        raise SanitizationError("cache purge assertion failed")
    evidence["CACHE_ROWS_AFTER"] = "0"

    if state_targets_remaining(connection, execution) != 0:
        raise SanitizationError("runtime state reset assertion failed")
    evidence["TARGETED_RUNTIME_STATE_AFTER"] = "0"

    if credentials_remaining(connection, execution) != 0:
        raise SanitizationError("credential-like state assertion failed")
    evidence["IMPORTED_PROD_SECRET_LIKE_STATE_SURVIVES"] = "NO"

    if count_rows(connection, "node_field_data") != 1:
        raise SanitizationError("public editorial fidelity assertion failed")
    if connection.execute(
        "SELECT COUNT(*) FROM config WHERE name = 'system.site'"
    ).fetchone()[0] != 1:
        raise SanitizationError("non-sensitive config fidelity assertion failed")
    if connection.execute(
        "SELECT COUNT(*) FROM key_value "
        "WHERE collection = 'state' AND name = 'synthetic.public.state'"
    ).fetchone()[0] != 1:
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
    raise SanitizationError(
        "unknown mandatory sensitive class was silently accepted"
    )


def prove() -> dict[str, str]:
    policy = load_policy()
    execution = validate_policy(policy)

    first = create_fixture()
    second = create_fixture()
    try:
        sanitize(first, policy)
        first_evidence = assert_sanitized(first, execution)
        state_a = canonical_digest(first)

        sanitize(second, policy)
        assert_sanitized(second, execution)
        state_b = canonical_digest(second)

        if state_a != state_b:
            raise SanitizationError("deterministic proof failed")

        sanitize(first, policy)
        assert_sanitized(first, execution)
        state_c = canonical_digest(first)
        if state_c != state_a:
            raise SanitizationError("second-pass idempotence failed")

        prove_unknown_sensitive_state_fail_closed(policy)

        return {
            "POLICY_VERSION": POLICY_VERSION,
            "SYNTHETIC_FIXTURE_PROOF": "PASS",
            **first_evidence,
            "DETERMINISTIC_PROOF": "PASS",
            "SECOND_PASS_IDEMPOTENCE": "PASS",
            "UNKNOWN_SENSITIVE_STATE_FAIL_CLOSED": "PASS",
            "STATE_A_SHA256": state_a,
            "STATE_B_SHA256": state_b,
            "STATE_C_SHA256": state_c,
            "REAL_PROD_ACCESS": "NONE",
            "REAL_PROD_DB_READ": "NONE",
            "REAL_PROD_SNAPSHOT": "NONE",
            "REAL_PREPROD_DB_MUTATION": "NONE",
            "REAL_SANITIZATION": "NOT_PERFORMED",
            "ACTIVATION": "NOT_PERFORMED",
            "PII_OR_SECRET_IN_EVIDENCE": "NONE",
        }
    finally:
        first.close()
        second.close()


def main(argv: list[str]) -> int:
    if argv != ["PROVE"]:
        print("ERROR=PROVE_ONLY", file=sys.stderr)
        return 2
    try:
        evidence = prove()
    except (SanitizationError, KeyError, TypeError, ValueError, sqlite3.Error):
        print("SANITIZATION_PROOF=FAIL", file=sys.stderr)
        return 1

    for key in sorted(evidence):
        print(f"{key}={evidence[key]}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
