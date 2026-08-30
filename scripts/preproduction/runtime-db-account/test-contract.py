#!/usr/bin/env python3
"""Static/synthetic contract tests for #893. Never executes APPLY."""

from __future__ import annotations

import hashlib
import importlib.util
import json
from importlib.machinery import SourceFileLoader
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
REPO = Path(__file__).resolve().parents[3]
HELPER = HERE / "agency-preprod-runtime-db-account"
SUDOERS = HERE / "agency-preprod-runtime-db-account.sudoers"
CAPABILITY = HERE / "capability.json"
BOOTSTRAP = REPO / "scripts" / "preproduction" / "bootstrap-host.sh"
SETTINGS = REPO / "scripts" / "preproduction" / "settings.php.template"
STAGING_HELPER = (
    REPO
    / "scripts"
    / "preproduction-staging-import"
    / "privileged"
    / "agency-preprod-staging-db"
)
STAGING_TEST = (
    REPO
    / "scripts"
    / "preproduction-staging-import"
    / "privileged"
    / "test-contract.py"
)
PLAN_TEST = (
    REPO
    / "scripts"
    / "preproduction-refresh"
    / "activation-capability"
    / "provisioning"
    / "test-plan-evidence.py"
)

loader = SourceFileLoader("agency_preprod_runtime_db_account", str(HELPER))
spec = importlib.util.spec_from_loader(loader.name, loader)
assert spec is not None
module = importlib.util.module_from_spec(spec)
sys.modules[loader.name] = module
loader.exec_module(module)

source = HELPER.read_text(encoding="utf-8")
sudoers = SUDOERS.read_text(encoding="utf-8")
capability = json.loads(CAPABILITY.read_text(encoding="utf-8"))
bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
settings = SETTINGS.read_text(encoding="utf-8")
staging_helper = STAGING_HELPER.read_text(encoding="utf-8")
staging_test = STAGING_TEST.read_text(encoding="utf-8")
plan_test = PLAN_TEST.read_text(encoding="utf-8")

# Canonical Drupal/fresh-bootstrap contract.
assert "'database' => '@@DB_NAME@@'" in settings
assert "'username' => '@@DB_USER@@'" in settings
assert "'host' => '127.0.0.1'" in settings
assert "'port' => '3306'" in settings
assert 'DB_NAME="agency_preprod"' in bootstrap
assert 'DB_USER="agency_preprod"' in bootstrap
assert 'DB_ACCOUNT_HOST="127.0.0.1"' in bootstrap
assert "CREATE USER IF NOT EXISTS '$DB_USER'@'$DB_ACCOUNT_HOST'" in bootstrap
assert "ALTER USER '$DB_USER'@'$DB_ACCOUNT_HOST'" in bootstrap
assert "ON \\`$DB_NAME\\`.* TO '$DB_USER'@'$DB_ACCOUNT_HOST'" in bootstrap
assert "'$DB_USER'@'localhost'" not in bootstrap
assert "'$DB_USER'@'%'" not in bootstrap
assert "ON *.*" not in bootstrap

# Fixed-purpose capability surface.
assert module.TARGET_DATABASE == "agency_preprod"
assert module.TARGET_USER == "agency_preprod"
assert module.TARGET_HOST == "127.0.0.1"
assert module.RUNTIME_ENV == Path(
    "/var/www/agency-preprod/shared/settings/runtime.env"
)
assert module.ALLOWED_ACTIONS == {"PRECHECK", "APPLY", "VERIFY"}
assert module.HELPER_PATH == "/usr/local/sbin/agency-preprod-runtime-db-account"
assert module.MARIADB_BIN == "/usr/bin/mariadb"

exact = module.classify(True, ("127.0.0.1",), True)
assert exact["RUNTIME_ACCOUNT_STATE"] == "EXACT"
assert exact["EXPECTED_DB_GRANT"] == "EXACT"

localhost_only = module.classify(True, ("localhost",), False)
assert localhost_only["ACCOUNT_127_PRESENT"] == "NO"
assert localhost_only["ACCOUNT_LOCALHOST_PRESENT"] == "YES"
assert localhost_only["RUNTIME_ACCOUNT_STATE"] == "RECONCILIATION_REQUIRED"

wildcard = module.classify(True, ("%",), False)
assert wildcard["RUNTIME_ACCOUNT_STATE"] == "UNSAFE"

bad_grant = module.classify(True, ("127.0.0.1",), False)
assert bad_grant["RUNTIME_ACCOUNT_STATE"] == "RECONCILIATION_REQUIRED"

missing_db = module.classify(False, ("127.0.0.1",), True)
assert missing_db["RUNTIME_ACCOUNT_STATE"] == "UNSAFE"

unexpected_host = module.classify(True, ("127.0.0.1", "10.0.0.5"), True)
assert unexpected_host["RUNTIME_ACCOUNT_STATE"] == "UNSAFE"

# Caller cannot supply SQL, target, secret, path, command, or executable.
assert "len(sys.argv) != 2" in source
for forbidden in (
    "argparse",
    "shell=True",
    "os.system(",
    "subprocess.call(",
    "eval(",
    "exec(",
    "--database",
    "--user",
    "--host",
    "--password",
    "--execute",
    "sys.argv[2]",
    "os.environ",
):
    assert forbidden not in source, forbidden

assert "read_runtime_password()" in source
assert 're.fullmatch(r"[0-9a-f]{64}", values[0])' in source
assert "stderr=subprocess.DEVNULL" in source
assert "password = \"\"" in source
assert "print(password" not in source
assert "print(query" not in source
assert "print(output" not in source
assert "print(lines" not in source
assert "runtime.env" not in "\n".join(
    line for line in source.splitlines() if line.lstrip().startswith("print(")
)

# PRECHECK and VERIFY are metadata-only; APPLY is source-only in #893.
for key in (
    "TARGET_DATABASE_PRESENT",
    "ACCOUNT_127_PRESENT",
    "ACCOUNT_LOCALHOST_PRESENT",
    "EXPECTED_DB_GRANT",
    "RUNTIME_ACCOUNT_STATE",
):
    assert key in source
assert 'if action in {"PRECHECK", "VERIFY"}' in source
assert 'if action == "APPLY"' in source
assert capability["status"] == "DESIGNED_NOT_INSTALLED_NOT_EXECUTED"
assert capability["real_world_execution"]["installed"] is False
assert capability["real_world_execution"]["executed"] is False
assert capability["real_world_execution"]["preprod_mariadb_mutation"] is False

# Bundle is pinnable/root-owned if separately authorized later.
assert capability["installation"]["helper"]["owner"] == "root"
assert capability["installation"]["helper"]["group"] == "root"
assert capability["installation"]["helper"]["mode"] == "0755"
assert capability["installation"]["sudoers"]["owner"] == "root"
assert capability["installation"]["sudoers"]["group"] == "root"
assert capability["installation"]["sudoers"]["mode"] == "0440"
assert capability["installation"]["helper"]["sha256"] == hashlib.sha256(
    HELPER.read_bytes()
).hexdigest()
assert capability["installation"]["sudoers"]["sha256"] == hashlib.sha256(
    SUDOERS.read_bytes()
).hexdigest()

# Separate sudoers grammar exposes only exact helper actions.
assert "/usr/bin/mariadb" not in sudoers
assert " ALL=(ALL)" not in sudoers
assert " /bin/" not in sudoers
assert " /usr/bin/" not in sudoers
for action in ("PRECHECK", "APPLY", "VERIFY"):
    assert (
        f"/usr/local/sbin/agency-preprod-runtime-db-account {action}"
        in sudoers
    )

# #849 stays staging-only and cannot target agency_preprod.
assert 'RUNTIME_DB = "agency_preprod"' in staging_helper
assert 'STAGING_PREFIX = "agency_preprod_stage_"' in staging_helper
assert "if database == RUNTIME_DB" in staging_helper
assert 'RUNTIME_DB_TARGETABLE=NO' in staging_test
assert 'GENERIC_PRIVILEGED_SQL_EXECUTOR=NONE' in staging_test

# #891 fail-closed semantics remain encoded in its existing synthetic matrix.
assert '#891_A_EXPECTED_DB=PASS' in plan_test
assert '#891_B_SAFE_RUNTIME_DB_MISMATCH=FAIL_CLOSED' in plan_test
assert '#891_C_G_BOUNDED_UNAVAILABLE_REASONS=FAIL_CLOSED' in plan_test
assert '"DRUSH_FAILED": "NONZERO"' in plan_test
assert 'expect_error(obs, f"runtime database identity probe unavailable: {state}")' in plan_test

print("FRESH_BOOTSTRAP_TCP_LOOPBACK=PASS")
print("LOCALHOST_ONLY=INSUFFICIENT")
print("WILDCARD_ACCOUNT=FORBIDDEN")
print("RUNTIME_GRANT_SCOPE=agency_preprod.*")
print("#849_RUNTIME_TARGET=FORBIDDEN")
print("CAPABILITY_ACTIONS=PRECHECK,APPLY,VERIFY")
print("CALLER_CONTROLLED_SQL=NO")
print("CALLER_CONTROLLED_DB_USER_HOST_PASSWORD_PATH=NO")
print("SECRET_OUTPUT=NONE")
print("APPLY_EXECUTION=NONE")
print("#891_FAIL_CLOSED=PRESERVED")
