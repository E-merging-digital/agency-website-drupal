#!/usr/bin/python3 -I
"""Root-owned Agency PREPROD staging sanitizer.

This module is not a CLI. It is loaded only by the fixed root-owned helper after
that helper verifies the module and policy bundle identities. It accepts only an
internally-derived staging database name and the already parsed repository
sanitization policy.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
from typing import Any

POLICY_VERSION = "agency-preprod-refresh-v1"
MARIADB_BIN = "/usr/bin/mariadb"
RUNTIME_DB = "agency_preprod"
STAGING_PREFIX = "agency_preprod_stage_"
STAGING_DB_RE = re.compile(r"^agency_preprod_stage_[0-9a-f]{12}$")
SAFE_IDENTIFIER = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")
CLEAN_ENV = {"PATH": "/usr/bin:/bin", "HOME": "/root", "LC_ALL": "C"}


class SanitizationError(RuntimeError):
    """Fail-closed sanitization contract violation."""


def validate_target_database(database: str) -> None:
    """Require the fixed derived staging namespace and reject runtime DB."""
    if database == RUNTIME_DB:
        raise SanitizationError("Runtime database is outside sanitization authority.")
    if not STAGING_DB_RE.fullmatch(database):
        raise SanitizationError("Target is not an internally-derived staging database.")


def quote_identifier(identifier: str) -> str:
    """Quote a repository-owned MariaDB identifier."""
    if not SAFE_IDENTIFIER.fullmatch(identifier):
        raise SanitizationError("Unsafe schema identifier.")
    return f"`{identifier}`"


def sql_string(value: str) -> str:
    """Encode a repository-owned string without SQL quoting ambiguity."""
    return f"CONVERT(0x{value.encode('utf-8').hex()} USING utf8mb4)"


def run_sql(query: str, database: str | None = None) -> str:
    """Execute only internally-constructed SQL on the fixed local socket."""
    command = [
        MARIADB_BIN,
        "--protocol=socket",
        "--batch",
        "--skip-column-names",
        "--raw",
    ]
    if database is not None:
        validate_target_database(database)
        command.append(f"--database={database}")
    result = subprocess.run(
        command,
        input=query + "\n",
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=False,
        env=CLEAN_ENV,
    )
    if result.returncode != 0:
        raise SanitizationError("Bounded MariaDB sanitization operation failed.")
    return result.stdout.strip()


def table_names(database: str) -> set[str]:
    """Return validated table names for the staging DB."""
    validate_target_database(database)
    output = run_sql(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        f"WHERE TABLE_SCHEMA={sql_string(database)} ORDER BY TABLE_NAME"
    )
    names = {line.strip() for line in output.splitlines() if line.strip()}
    for name in names:
        quote_identifier(name)
    return names


def columns(database: str, table: str) -> list[str]:
    """Return validated column names for a known table."""
    validate_target_database(database)
    quote_identifier(table)
    output = run_sql(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        f"WHERE TABLE_SCHEMA={sql_string(database)} "
        f"AND TABLE_NAME={sql_string(table)} ORDER BY ORDINAL_POSITION"
    )
    names = [line.strip() for line in output.splitlines() if line.strip()]
    for name in names:
        quote_identifier(name)
    return names


def table_ref(database: str, table: str) -> str:
    """Return a validated qualified table reference."""
    validate_target_database(database)
    return f"{quote_identifier(database)}.{quote_identifier(table)}"


def require_columns(
    database: str,
    table: str,
    required: set[str],
    *,
    required_table: bool = False,
) -> None:
    """Fail closed if a present known-sensitive schema is incompatible."""
    existing = table_names(database)
    if table not in existing:
        if required_table:
            raise SanitizationError("Required sensitive table is absent.")
        return
    actual = set(columns(database, table))
    if required - actual:
        raise SanitizationError("Known sensitive table has incompatible schema.")


def clear_table(database: str, table: str) -> None:
    """Clear a mapped table if present."""
    if table in table_names(database):
        run_sql(f"DELETE FROM {table_ref(database, table)}")


def count_rows(database: str, table: str) -> int:
    """Return a non-sensitive row count."""
    if table not in table_names(database):
        return 0
    value = run_sql(f"SELECT COUNT(*) FROM {table_ref(database, table)}")
    if not re.fullmatch(r"[0-9]+", value):
        raise SanitizationError("Row-count proof is invalid.")
    return int(value, 10)


def validate_policy(policy: dict[str, Any]) -> dict[str, Any]:
    """Validate the sole agency-preprod-refresh-v1 semantic authority."""
    if policy.get("schema_version") != 1:
        raise SanitizationError("Unsupported policy schema.")
    if policy.get("policy_version") != POLICY_VERSION:
        raise SanitizationError("Unexpected sanitization policy identity.")

    execution = policy.get("sanitization_execution")
    if not isinstance(execution, dict):
        raise SanitizationError("Sanitization execution contract missing.")
    if execution.get("unknown_mandatory_class") != "FAIL_CLOSED":
        raise SanitizationError("Unknown mandatory classes must fail closed.")

    mandatory = policy.get("mandatory_sanitization")
    if not isinstance(mandatory, list):
        raise SanitizationError("Mandatory sanitization contract missing.")

    mandatory_ids: set[str] = set()
    for rule in mandatory:
        if not isinstance(rule, dict) or rule.get("required") is not True:
            raise SanitizationError("All mandatory sanitization rules must remain required.")
        rule_id = rule.get("id")
        if not isinstance(rule_id, str) or not rule_id or rule_id in mandatory_ids:
            raise SanitizationError("Mandatory sanitization rule identity is invalid.")
        mandatory_ids.add(rule_id)

    handlers = execution.get("mandatory_class_handlers")
    if not isinstance(handlers, dict):
        raise SanitizationError("Mandatory class handlers missing.")
    if set(handlers) != mandatory_ids:
        raise SanitizationError("Mandatory sensitive class is not exactly handled.")
    if set(handlers.values()) - set(HANDLERS):
        raise SanitizationError("Unknown sanitization handler.")

    github_evidence = policy.get("github_evidence", {})
    if (
        github_evidence.get("raw_sql_allowed") is not False
        or github_evidence.get("pii_allowed") is not False
        or github_evidence.get("secrets_allowed") is not False
    ):
        raise SanitizationError("Evidence boundary is weaker than policy.")

    return execution


def sanitize_users(database: str, execution: dict[str, Any]) -> None:
    """Deterministically anonymize user identifiers and invalidate auth."""
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
    require_columns(database, table, required, required_table=True)
    uid_column = quote_identifier(str(contract["uid_column"]))
    output = run_sql(
        f"SELECT {uid_column} FROM {table_ref(database, table)} ORDER BY {uid_column}"
    )
    for raw_uid in [line.strip() for line in output.splitlines() if line.strip()]:
        if not re.fullmatch(r"[0-9]+", raw_uid):
            raise SanitizationError("User uid is not a stable numeric identifier.")
        uid = int(raw_uid, 10)
        username = f"preprod-user-{uid}"
        email = f"preprod-user+{uid}@example.invalid"
        run_sql(
            f"UPDATE {table_ref(database, table)} SET "
            f"{quote_identifier(str(contract['name_column']))}={sql_string(username)},"
            f"{quote_identifier(str(contract['mail_column']))}={sql_string(email)},"
            f"{quote_identifier(str(contract['init_column']))}={sql_string(email)},"
            f"{quote_identifier(str(contract['password_column']))}='',"
            f"{quote_identifier(str(contract['access_column']))}=0,"
            f"{quote_identifier(str(contract['login_column']))}=0 "
            f"WHERE {uid_column}={uid}"
        )


def purge_tables_for_class(
    database: str,
    execution: dict[str, Any],
    class_id: str,
) -> None:
    """Clear all explicitly mapped tables for one mandatory class."""
    purge_by_class = execution.get("purge_tables_by_class", {})
    tables = purge_by_class.get(class_id)
    if not isinstance(tables, list):
        raise SanitizationError("Purge table mapping missing.")
    for table in tables:
        if not isinstance(table, str):
            raise SanitizationError("Invalid purge table mapping.")
        clear_table(database, table)


def clear_caches(database: str, execution: dict[str, Any]) -> None:
    """Clear only versioned cache prefixes from the policy."""
    prefixes = execution.get("cache_table_prefixes")
    if not isinstance(prefixes, list) or not prefixes:
        raise SanitizationError("Cache table prefixes missing.")
    for table in sorted(table_names(database)):
        if any(table.startswith(str(prefix)) for prefix in prefixes):
            clear_table(database, table)


def delete_key_value_state(database: str, execution: dict[str, Any]) -> None:
    """Reset imported cron/update/announcements/link-checker state."""
    contract = execution["runtime_key_value_state"]
    table = str(contract["table"])
    require_columns(database, table, {"collection", "name", "value"})
    if table not in table_names(database):
        return
    collection = str(contract["collection"])
    for name in contract["exact_names"]:
        run_sql(
            f"DELETE FROM {table_ref(database, table)} WHERE "
            f"collection={sql_string(collection)} AND name={sql_string(str(name))}"
        )
    for raw_prefix in contract["name_prefixes"]:
        prefix = str(raw_prefix)
        run_sql(
            f"DELETE FROM {table_ref(database, table)} WHERE "
            f"collection={sql_string(collection)} AND "
            f"LEFT(name,CHAR_LENGTH({sql_string(prefix)}))={sql_string(prefix)}"
        )


def remove_credential_config(database: str, execution: dict[str, Any]) -> None:
    """Remove imported credential/provider config mapped by the policy."""
    contract = execution["credential_config"]
    table = str(contract["table"])
    require_columns(database, table, {"name", "data"})
    if table not in table_names(database):
        return
    for name in contract["exact_names"]:
        run_sql(
            f"DELETE FROM {table_ref(database, table)} "
            f"WHERE name={sql_string(str(name))}"
        )
    for raw_prefix in contract["name_prefixes"]:
        prefix = str(raw_prefix)
        run_sql(
            f"DELETE FROM {table_ref(database, table)} WHERE "
            f"LEFT(name,CHAR_LENGTH({sql_string(prefix)}))={sql_string(prefix)}"
        )


def assert_preprod_admin_boundary(
    _database: str,
    execution: dict[str, Any],
) -> None:
    """Require imported accounts never become the PREPROD admin route."""
    admin = execution.get("preprod_admin")
    if not isinstance(admin, dict):
        raise SanitizationError("PREPROD admin boundary missing.")
    if admin.get("source") != "PREPROD_SERVER_OWNED":
        raise SanitizationError("PREPROD admin must remain server-owned.")
    if admin.get("restore_in_issue_855") is not False:
        raise SanitizationError("Imported accounts may not restore PREPROD admin.")


def handle_users(database: str, execution: dict[str, Any]) -> None:
    sanitize_users(database, execution)


def handle_preprod_admin(database: str, execution: dict[str, Any]) -> None:
    assert_preprod_admin_boundary(database, execution)


def handle_webform(database: str, execution: dict[str, Any]) -> None:
    purge_tables_for_class(database, execution, "webform_submissions")


def handle_sessions(database: str, execution: dict[str, Any]) -> None:
    purge_tables_for_class(database, execution, "sessions")


def handle_flood(database: str, execution: dict[str, Any]) -> None:
    purge_tables_for_class(database, execution, "flood_rate_limit")


def handle_dblog(database: str, execution: dict[str, Any]) -> None:
    purge_tables_for_class(database, execution, "dblog_watchdog")


def handle_caches(database: str, execution: dict[str, Any]) -> None:
    clear_caches(database, execution)


def handle_batch_temp(database: str, execution: dict[str, Any]) -> None:
    purge_tables_for_class(database, execution, "batch_temp_state")


def handle_queues(database: str, execution: dict[str, Any]) -> None:
    purge_tables_for_class(database, execution, "queues")


def handle_runtime_state(database: str, execution: dict[str, Any]) -> None:
    delete_key_value_state(database, execution)


def handle_one_time_auth(database: str, execution: dict[str, Any]) -> None:
    sanitize_users(database, execution)
    purge_tables_for_class(database, execution, "batch_temp_state")


def handle_credentials(database: str, execution: dict[str, Any]) -> None:
    remove_credential_config(database, execution)


def handle_production_state(database: str, execution: dict[str, Any]) -> None:
    delete_key_value_state(database, execution)


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


def sanitize(database: str, policy: dict[str, Any]) -> dict[str, Any]:
    """Apply every mandatory policy class exactly once in policy order."""
    execution = validate_policy(policy)
    validate_target_database(database)
    handlers = execution["mandatory_class_handlers"]
    for rule in policy["mandatory_sanitization"]:
        rule_id = str(rule["id"])
        HANDLERS[str(handlers[rule_id])](database, execution)
    return execution


def targeted_runtime_state_remaining(
    database: str,
    execution: dict[str, Any],
) -> int:
    """Count only imported runtime state targeted by the policy."""
    contract = execution["runtime_key_value_state"]
    table = str(contract["table"])
    if table not in table_names(database):
        return 0
    collection = str(contract["collection"])
    count = 0
    for name in contract["exact_names"]:
        value = run_sql(
            f"SELECT COUNT(*) FROM {table_ref(database, table)} WHERE "
            f"collection={sql_string(collection)} AND name={sql_string(str(name))}"
        )
        count += int(value, 10)
    for raw_prefix in contract["name_prefixes"]:
        prefix = str(raw_prefix)
        value = run_sql(
            f"SELECT COUNT(*) FROM {table_ref(database, table)} WHERE "
            f"collection={sql_string(collection)} AND "
            f"LEFT(name,CHAR_LENGTH({sql_string(prefix)}))={sql_string(prefix)}"
        )
        count += int(value, 10)
    return count


def credentials_remaining(database: str, execution: dict[str, Any]) -> int:
    """Count mapped imported credential-like config remaining."""
    contract = execution["credential_config"]
    table = str(contract["table"])
    if table not in table_names(database):
        return 0
    count = 0
    for name in contract["exact_names"]:
        value = run_sql(
            f"SELECT COUNT(*) FROM {table_ref(database, table)} "
            f"WHERE name={sql_string(str(name))}"
        )
        count += int(value, 10)
    for raw_prefix in contract["name_prefixes"]:
        prefix = str(raw_prefix)
        value = run_sql(
            f"SELECT COUNT(*) FROM {table_ref(database, table)} WHERE "
            f"LEFT(name,CHAR_LENGTH({sql_string(prefix)}))={sql_string(prefix)}"
        )
        count += int(value, 10)
    return count


def canonical_proof_digest(
    database: str,
    execution: dict[str, Any],
) -> str:
    """Hash only deterministic sanitized identities and non-sensitive counts."""
    user = execution["users"]
    table = str(user["table"])
    required = {
        str(user["uid_column"]),
        str(user["name_column"]),
        str(user["mail_column"]),
        str(user["init_column"]),
        str(user["password_column"]),
        str(user["access_column"]),
        str(user["login_column"]),
    }
    require_columns(database, table, required, required_table=True)
    uid = quote_identifier(str(user["uid_column"]))
    name = quote_identifier(str(user["name_column"]))
    mail = quote_identifier(str(user["mail_column"]))
    init = quote_identifier(str(user["init_column"]))
    password = quote_identifier(str(user["password_column"]))
    access = quote_identifier(str(user["access_column"]))
    login = quote_identifier(str(user["login_column"]))
    users = run_sql(
        f"SELECT {uid},{name},{mail},{init},{password},{access},{login} "
        f"FROM {table_ref(database, table)} ORDER BY {uid}"
    )

    counts: dict[str, int] = {}
    for tables in execution["purge_tables_by_class"].values():
        for mapped in tables:
            counts[str(mapped)] = count_rows(database, str(mapped))
    for existing in sorted(table_names(database)):
        if any(
            existing.startswith(str(prefix))
            for prefix in execution["cache_table_prefixes"]
        ):
            counts[existing] = count_rows(database, existing)
    counts["targeted_runtime_state"] = targeted_runtime_state_remaining(
        database,
        execution,
    )
    counts["credentials_remaining"] = credentials_remaining(database, execution)
    counts["node_field_data"] = count_rows(database, "node_field_data")

    payload = json.dumps(
        {"users": users.splitlines(), "counts": counts},
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()


def assert_sanitized(
    database: str,
    execution: dict[str, Any],
) -> dict[str, str]:
    """Assert mandatory sensitive-state removal and editorial fidelity."""
    user = execution["users"]
    table = str(user["table"])
    required = {
        str(user["uid_column"]),
        str(user["name_column"]),
        str(user["mail_column"]),
        str(user["init_column"]),
        str(user["password_column"]),
        str(user["access_column"]),
        str(user["login_column"]),
    }
    require_columns(database, table, required, required_table=True)
    uid_col = quote_identifier(str(user["uid_column"]))
    rows = run_sql(
        f"SELECT {uid_col},"
        f"{quote_identifier(str(user['name_column']))},"
        f"{quote_identifier(str(user['mail_column']))},"
        f"{quote_identifier(str(user['init_column']))},"
        f"{quote_identifier(str(user['password_column']))},"
        f"{quote_identifier(str(user['access_column']))},"
        f"{quote_identifier(str(user['login_column']))} "
        f"FROM {table_ref(database, table)} ORDER BY {uid_col}"
    )
    for line in [raw for raw in rows.splitlines() if raw]:
        fields = line.split("\t")
        if len(fields) != 7 or not re.fullmatch(r"[0-9]+", fields[0]):
            raise SanitizationError("User sanitization proof is malformed.")
        uid = int(fields[0], 10)
        expected_name = f"preprod-user-{uid}"
        expected_mail = f"preprod-user+{uid}@example.invalid"
        if (
            fields[1] != expected_name
            or fields[2] != expected_mail
            or fields[3] != expected_mail
            or fields[4] != ""
            or fields[5] != "0"
            or fields[6] != "0"
        ):
            raise SanitizationError("User sanitization assertion failed.")

    evidence: dict[str, str] = {
        "user_sanitization": "PASS",
        "auth_material_invalidation": "PASS",
    }
    for class_id, tables in execution["purge_tables_by_class"].items():
        for mapped in tables:
            if count_rows(database, str(mapped)) != 0:
                raise SanitizationError("Mapped sensitive table was not cleared.")
        evidence[f"{class_id}_purge"] = "PASS"

    cache_remaining = 0
    for existing in table_names(database):
        if any(
            existing.startswith(str(prefix))
            for prefix in execution["cache_table_prefixes"]
        ):
            cache_remaining += count_rows(database, existing)
    if cache_remaining != 0:
        raise SanitizationError("Imported cache state was not cleared.")
    if targeted_runtime_state_remaining(database, execution) != 0:
        raise SanitizationError("Imported runtime state was not reset.")
    if credentials_remaining(database, execution) != 0:
        raise SanitizationError("Imported credential-like state survived.")

    # The synthetic lifecycle fixture must retain editorial content. A real
    # import with an empty node table remains valid, so absence is not treated
    # as a runtime failure; the CI proof separately requires the fixture count.
    editorial_count = count_rows(database, "node_field_data")

    evidence.update(
        {
            "cache_purge": "PASS",
            "runtime_state_reset": "PASS",
            "credential_state_removal": "PASS",
            "editorial_row_count": str(editorial_count),
            "sanitized_state_sha256": canonical_proof_digest(database, execution),
        }
    )
    return evidence
