#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import importlib.util
import pathlib
import re
from importlib.machinery import SourceFileLoader

ROOT = pathlib.Path(__file__).resolve().parent
HELPER = ROOT / "agency-preprod-staging-db"
DIGEST = ROOT / "agency-preprod-staging-db.sha256"
CAPABILITY = ROOT / "capability.json"

loader = SourceFileLoader("agency_preprod_staging_db", str(HELPER))
spec = importlib.util.spec_from_loader(loader.name, loader)
assert spec is not None
module = importlib.util.module_from_spec(spec)
loader.exec_module(module)

source = HELPER.read_text(encoding="utf-8")
expected_digest = DIGEST.read_text(encoding="utf-8").strip()
actual_digest = hashlib.sha256(HELPER.read_bytes()).hexdigest()
assert re.fullmatch(r"[0-9a-f]{64}", expected_digest)
assert actual_digest == expected_digest

assert module.HELPER_PATH == "/usr/local/sbin/agency-preprod-staging-db"
assert module.MARIADB_BIN == "/usr/bin/mariadb"
assert module.RUNTIME_DB == "agency_preprod"
assert module.ALLOWED_ACTIONS == {"PRECHECK", "IMPORT", "CLEANUP", "VERIFY_ABSENCE"}
assert module.MAX_SNAPSHOT_BYTES == 1_099_511_627_776

scope_a = module.derive_scope("apply-834-synthetic-r1")
scope_b = module.derive_scope("apply-834-synthetic-r1")
scope_c = module.derive_scope("apply-834-synthetic-r2")
assert scope_a == scope_b
assert scope_a != scope_c
assert re.fullmatch(r"agency_preprod_stage_[0-9a-f]{12}", scope_a.database)
assert re.fullmatch(r"agency_stage_[0-9a-f]{12}", scope_a.database_user)
assert scope_a.database != module.RUNTIME_DB
assert scope_a.database.startswith("agency_preprod_stage_")
assert scope_a.database_user.startswith("agency_stage_")

for invalid in ["short", "contains space", "x" * 81, "db;DROP"]:
    try:
        module.derive_scope(invalid)
    except module.ContractError:
        pass
    else:
        raise AssertionError(f"invalid request_id accepted: {invalid!r}")

assert module.validate_snapshot_bytes("0", "PRECHECK") == 0
assert module.validate_snapshot_bytes("1099511627776", "PRECHECK") == 1_099_511_627_776
for raw, action in [("-1", "PRECHECK"), ("1099511627777", "PRECHECK"), ("0", "IMPORT")]:
    try:
        module.validate_snapshot_bytes(raw, action)
    except module.ContractError:
        pass
    else:
        raise AssertionError(f"invalid snapshot bound accepted: {raw}/{action}")

# No shell/eval/environment-controlled executable path exists in the privileged helper.
for forbidden in ["shell=True", "os.system(", "subprocess.call(", "eval(", "exec(", "PYTHONPATH", "BASH_ENV"]:
    assert forbidden not in source

# IMPORT uses a dedicated ephemeral DB account, no global grants, and disables LOCAL INFILE.
assert "CREATE USER '{scope.database_user}'@'localhost'" in source
assert "ON `{scope.database}`.*" in source
assert "--database={scope.database}" in source
assert "--local-infile=0" in source
assert "GRANT ALL" not in source
assert "ON *.*" not in source

# Root administrative statements can interpolate only request-derived scope members.
assert "DROP DATABASE IF EXISTS `{scope.database}`" in source
assert "DROP USER IF EXISTS '{scope.database_user}'@'localhost'" in source
assert "CREATE DATABASE `{scope.database}`" in source
assert "RUNTIME_DB = \"agency_preprod\"" in source
assert "database == RUNTIME_DB" in source

print("BOUNDED_HELPER_CONTRACT=PASS")
print("RUNTIME_DB_TARGETABLE=NO")
print("STAGING_DB_NAME_CALLER_CONTROLLED=NO")
print("GENERIC_PRIVILEGED_SQL_EXECUTOR=NONE")
print("GENERIC_PRIVILEGED_SHELL=NONE")
print("HELPER_DIGEST=REPOSITORY_VERIFIABLE")
print("IMPORT_SCOPE=DERIVED_STAGING_DB_ONLY")
