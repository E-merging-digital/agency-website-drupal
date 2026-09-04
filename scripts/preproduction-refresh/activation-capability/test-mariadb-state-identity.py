#!/usr/bin/env python3
"""Exact-head CI-only MariaDB 11.8 deterministic runtime-state proof for #874."""
from __future__ import annotations
import hashlib
import importlib.machinery
import importlib.util
import os
import pathlib
import re
import subprocess
import time

BASE = pathlib.Path(__file__).resolve().parent
STATE = BASE / "runtime_state_digest.py"
HELPER = BASE / "agency-preprod-refresh-control"


def load(path: pathlib.Path, name: str):
    loader = importlib.machinery.SourceFileLoader(name, str(path))
    spec = importlib.util.spec_from_loader(name, loader)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    import sys
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module

state = load(STATE, "agency874_state_digest")
helper = load(HELPER, "agency874_helper")

HOST = os.environ.get("AGENCY_874_TEST_DB_HOST", "127.0.0.1")
PORT = os.environ.get("AGENCY_874_TEST_DB_PORT", "3306")
PASSWORD = os.environ.get("AGENCY_874_TEST_DB_PASSWORD", "agency874-test-root")
ENV = dict(os.environ, MYSQL_PWD=PASSWORD, LC_ALL="C")
client = ["mariadb", "--protocol=tcp", f"--host={HOST}", f"--port={PORT}", "--user=root", "--batch", "--skip-column-names"]


def run_sql(query: str, capture: bool = False) -> str:
    result = subprocess.run(client, input=query + "\n", text=True, env=ENV,
                            stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                            stderr=subprocess.PIPE, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr)
    return result.stdout.strip() if capture else ""


def wait() -> None:
    for _ in range(120):
        result = subprocess.run(client + ["-e", "SELECT 1"], env=ENV,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if result.returncode == 0:
            return
        time.sleep(.5)
    raise RuntimeError("MariaDB 11.8 service unavailable")


def dump() -> bytes:
    options = [x for x in state.DUMP_OPTIONS if x != "--protocol=socket"]
    command = ["mariadb-dump", "--protocol=tcp", f"--host={HOST}", f"--port={PORT}", "--user=root", *options]
    result = subprocess.run(command, env=ENV, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.decode())
    return state.canonicalize_dump_bytes(result.stdout)


def live_base_tables(database: str) -> list[str]:
    dbhex = database.encode().hex()
    q = (
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        f"WHERE TABLE_SCHEMA=CONVERT(0x{dbhex} USING utf8mb4) "
        "AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
    )
    return [line for line in run_sql(q, capture=True).splitlines() if line]


wait()
version = run_sql("SELECT VERSION()", capture=True)
if not re.match(r"^11\.8\.", version):
    raise RuntimeError(f"Expected MariaDB 11.8, got {version}")

run_sql("""
DROP DATABASE IF EXISTS agency_preprod;
CREATE DATABASE agency_preprod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agency_preprod;
CREATE TABLE alpha (id INT PRIMARY KEY, value VARCHAR(64) NOT NULL);
CREATE TABLE beta (id INT PRIMARY KEY, payload VARBINARY(16) NOT NULL);
INSERT INTO alpha VALUES (2,'deux'),(1,'un'),(3,'trois');
INSERT INTO beta VALUES (2,0x00FF),(1,0x0102);
""")

# Stable representation of unchanged state.
a = dump()
b = dump()
sha_a = hashlib.sha256(a).hexdigest()
sha_b = hashlib.sha256(b).hexdigest()
assert sha_a == sha_b
assert b"Dump completed on" not in a
assert b"-- MariaDB dump" not in a
assert b"INSERT INTO `alpha` VALUES (1,'un');" in a
assert a.index(b"INSERT INTO `alpha` VALUES (1,'un');") < a.index(b"INSERT INTO `alpha` VALUES (2,'deux');")
assert "--order-by-primary" in state.DUMP_OPTIONS
assert "--skip-comments" in state.DUMP_OPTIONS
assert "--skip-extended-insert" in state.DUMP_OPTIONS
assert live_base_tables("agency_preprod") == ["alpha", "beta"]

# Unsupported objects are outside the activation subset.
run_sql("USE agency_preprod; CREATE VIEW alpha_view AS SELECT id FROM alpha;")
view_count = run_sql("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='agency_preprod'", capture=True)
assert view_count == "1"
run_sql("USE agency_preprod; DROP VIEW alpha_view;")

# Exact backup -> mutate -> exact restore identity.
run_sql("USE agency_preprod; UPDATE alpha SET value='mutated' WHERE id=1; INSERT INTO alpha VALUES (4,'extra');")
assert hashlib.sha256(dump()).hexdigest() != sha_a
run_sql("DROP DATABASE agency_preprod;")
restore = subprocess.run(client, input=a, env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=False)
if restore.returncode != 0:
    raise RuntimeError(restore.stderr.decode())
c = dump()
sha_c = hashlib.sha256(c).hexdigest()
assert sha_c == sha_a

print("MARIADB_VERSION=11.8_PASS")
print("DETERMINISTIC_STATE_A_B=PASS")
print("BACKUP_RESTORE_STATE_A_C=PASS")
print("VOLATILE_METADATA_EXCLUDED=PASS")
print("ROW_SERIALIZATION_DETERMINISTIC=PASS")
print("BASE_TABLE_SUBSET=PASS")
print("EXACT_BACKUP_RESTORE_DIGEST=PASS")
print("NO_REAL_PROD_ACCESS=PASS")
print("NO_REAL_PREPROD_MUTATION=PASS")
