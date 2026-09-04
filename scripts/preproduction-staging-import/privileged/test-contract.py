#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import importlib.util
import json
import pathlib
import re
import sys
from importlib.machinery import SourceFileLoader

ROOT = pathlib.Path(__file__).resolve().parent
REPO = pathlib.Path(__file__).resolve().parents[3]
HELPER = ROOT / "agency-preprod-staging-db"
HELPER_DIGEST = ROOT / "agency-preprod-staging-db.sha256"
SANITIZER = ROOT / "agency-preprod-staging-sanitizer.py"
SANITIZER_DIGEST = ROOT / "agency-preprod-staging-sanitizer.py.sha256"
POLICY = REPO / "scripts" / "preproduction-refresh" / "sanitization-policy.json"
POLICY_DIGEST = ROOT / "sanitization-policy.sha256"
CAPABILITY = ROOT / "capability.json"
BUNDLE = ROOT / "bundle.json"

loader = SourceFileLoader("agency_preprod_staging_db", str(HELPER))
spec = importlib.util.spec_from_loader(loader.name, loader)
assert spec is not None
module = importlib.util.module_from_spec(spec)
sys.modules[loader.name] = module
loader.exec_module(module)

source = HELPER.read_text(encoding="utf-8")
expected_helper = HELPER_DIGEST.read_text(encoding="utf-8").strip()
expected_sanitizer = SANITIZER_DIGEST.read_text(encoding="utf-8").strip()
expected_policy = POLICY_DIGEST.read_text(encoding="utf-8").strip()
assert hashlib.sha256(HELPER.read_bytes()).hexdigest() == expected_helper == "a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71"
assert hashlib.sha256(SANITIZER.read_bytes()).hexdigest() == expected_sanitizer == "fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f"
assert hashlib.sha256(POLICY.read_bytes()).hexdigest() == expected_policy == "cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb"

assert module.HELPER_PATH == "/usr/local/sbin/agency-preprod-staging-db"
assert module.BUNDLE_DIR == "/usr/local/lib/agency-preprod-staging"
assert module.SANITIZER_SHA256 == expected_sanitizer
assert module.POLICY_SHA256 == expected_policy
assert module.MARIADB_BIN == "/usr/bin/mariadb"
assert module.RUNTIME_DB == "agency_preprod"
assert module.ALLOWED_ACTIONS == {"PRECHECK", "IMPORT", "IMPORT_SANITIZE_PROVE", "CLEANUP", "VERIFY_ABSENCE"}
assert module.MAX_SNAPSHOT_BYTES == 1_099_511_627_776

scope_a = module.derive_scope("apply-859-synthetic-r1")
scope_b = module.derive_scope("apply-859-synthetic-r1")
scope_c = module.derive_scope("apply-859-synthetic-r2")
assert scope_a == scope_b and scope_a != scope_c
assert re.fullmatch(r"agency_preprod_stage_[0-9a-f]{12}", scope_a.database)
assert re.fullmatch(r"agency_stage_[0-9a-f]{12}", scope_a.database_user)
assert scope_a.database != module.RUNTIME_DB

for invalid in ["short", "contains space", "x" * 81, "db;DROP"]:
    try:
        module.derive_scope(invalid)
    except module.ContractError:
        pass
    else:
        raise AssertionError(f"invalid request_id accepted: {invalid!r}")

assert module.validate_snapshot_bytes("0", "PRECHECK") == 0
assert module.validate_snapshot_bytes("1099511627776", "PRECHECK") == 1_099_511_627_776
for raw, action in [("-1", "PRECHECK"), ("1099511627777", "PRECHECK"), ("0", "IMPORT"), ("0", "IMPORT_SANITIZE_PROVE")]:
    try:
        module.validate_snapshot_bytes(raw, action)
    except module.ContractError:
        pass
    else:
        raise AssertionError(f"invalid snapshot bound accepted: {raw}/{action}")

for forbidden in ["shell=True", "os.system(", "subprocess.call(", "eval(", "exec(", "PYTHONPATH", "BASH_ENV", "NOPASSWD:.*mariadb"]:
    assert forbidden not in source
assert 'Expected ACTION REQUEST_ID SNAPSHOT_BYTES only.' in source
assert '"IMPORT_SANITIZE_PROVE"' in source
assert "total != snapshot_bytes" in source
assert "cleanup_scope(scope)" in source
assert "require_absent(scope)" in source
assert "load_bundle()" in source
assert "require_root_owned_bundle_file" in source
assert 'stat.S_ISLNK(metadata.st_mode)' in source
assert 'metadata.st_uid != 0 or metadata.st_gid != 0' in source
assert "CREATE USER '{scope.database_user}'@'localhost'" in source
assert "ON `{scope.database}`.*" in source
assert "--database={scope.database}" in source
assert "--local-infile=0" in source
assert "GRANT ALL" not in source and "ON *.*" not in source
assert "database == RUNTIME_DB" in source

capability = json.loads(CAPABILITY.read_text(encoding="utf-8"))
assert capability["issue_number"] == 849
assert capability["revision_issue_number"] == 859
assert capability["actions"] == ["PRECHECK", "IMPORT", "IMPORT_SANITIZE_PROVE", "CLEANUP", "VERIFY_ABSENCE"]
assert capability["database_scope"]["runtime_targetable"] is False
assert capability["one_shot"]["unsanitized_persistence_between_caller_actions"] == "FORBIDDEN"
assert capability["one_shot"]["cleanup_on_success"] == "MANDATORY"
assert capability["one_shot"]["cleanup_on_import_failure"] == "MANDATORY"
assert capability["one_shot"]["cleanup_on_sanitization_failure"] == "MANDATORY"
assert capability["one_shot"]["cleanup_on_assertion_failure"] == "MANDATORY"
assert capability["root_owned_bundle"]["mutable_checkout_runtime_read"] == "FORBIDDEN"
assert capability["sudo"]["direct_mariadb"] == "FORBIDDEN"

bundle = json.loads(BUNDLE.read_text(encoding="utf-8"))
assert bundle["revision_issue_number"] == 859
assert bundle["files"]["helper"]["sha256"] == expected_helper
assert bundle["files"]["sanitizer"]["sha256"] == expected_sanitizer
assert bundle["files"]["policy"]["sha256"] == expected_policy
assert bundle["files"]["policy"]["policy_version"] == "agency-preprod-refresh-v1"
assert bundle["sudoers"]["change_required"] is False
assert bundle["real_provisioning_performed"] is False

print("BOUNDED_HELPER_CONTRACT=PASS")
print("ONE_SHOT_ACTION=IMPORT_SANITIZE_PROVE")
print("RUNTIME_DB_TARGETABLE=NO")
print("STAGING_DB_NAME_CALLER_CONTROLLED=NO")
print("GENERIC_PRIVILEGED_SQL_EXECUTOR=NONE")
print("GENERIC_PRIVILEGED_SHELL=NONE")
print("HELPER_BUNDLE_DIGEST=REPOSITORY_VERIFIABLE")
print("MUTABLE_CHECKOUT_ROOT_EXECUTION=FORBIDDEN")
print("CLEANUP_ALL_OUTCOMES=MANDATORY")
