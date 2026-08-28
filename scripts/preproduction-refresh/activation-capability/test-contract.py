#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import importlib.util
import json
import pathlib
import sys
import tempfile

BASE = pathlib.Path(__file__).resolve().parent
HELPER = BASE / "agency-preprod-refresh-control"
PROFILE = BASE / "profile.json"
AUTH = BASE / "data-activation-authority.disabled.json"
SUDOERS = BASE / "provisioning/agency-preprod-refresh-control.sudoers"
PLAN = BASE / "provisioning/run-plan.sh"
APPLY_REMOTE = BASE / "provisioning/remote-provision-root.sh"
FENCE = BASE / "nginx/agency-preprod-refresh-fence.conf"
INTERNAL = BASE / "nginx/agency-preprod-refresh-internal-readiness.conf"
HARDENING = BASE / "side_effect_hardening.py"
STATE_DIGEST = BASE / "runtime_state_digest.py"
PROVISION_PROFILE = BASE / "provisioning/profile.json"
BUNDLE = BASE / "bundle.json"
SUCCESSOR = BASE / "successor-data-activation-authority.json"

def load_helper():
    from importlib.machinery import SourceFileLoader
    loader = SourceFileLoader("agency874_helper", str(HELPER))
    spec = importlib.util.spec_from_loader("agency874_helper", loader)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module

def expect_contract_error(fn):
    try:
        fn()
    except Exception as exc:
        assert exc.__class__.__name__ == "ContractError"
        return
    raise AssertionError("expected ContractError")

def main() -> None:
    helper = load_helper()
    profile = json.loads(PROFILE.read_text())
    authority = json.loads(AUTH.read_text())
    provision = json.loads(PROVISION_PROFILE.read_text())
    bundle = json.loads(BUNDLE.read_text())
    successor = json.loads(SUCCESSOR.read_text())
    helper_text = HELPER.read_text()
    hardening_text = HARDENING.read_text()
    plan = PLAN.read_text()
    remote = APPLY_REMOTE.read_text()
    fence = FENCE.read_text()
    internal = INTERNAL.read_text()
    sudoers = SUDOERS.read_text().strip()

    assert profile["issue_number"] == 874 and profile["parent_issue"] == 816
    canonical = profile["canonical_sanitization"]
    assert canonical["policy_id"] == "agency-preprod-refresh-v1"
    assert canonical["sanitizer_sha256"] == "fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f"
    assert canonical["policy_sha256"] == "cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb"
    assert canonical["existing_helper_sha256"] == "a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71"
    assert "agency-preprod-staging-sanitizer.py" in helper_text
    assert "sanitizer.sanitize(op.staging_db, policy)" in helper_text
    for duplicate in ("def sanitize_users", "def purge_tables_for_class", "sanitize-and-harden.php"):
        assert duplicate not in helper_text and duplicate not in hardening_text
    print("canonical_sanitizer_reused=PASS")
    print("duplicate_sanitizer_authority_absent=PASS")

    valid = {"action":"PRECHECK","request_id":"apply-874-synthetic-r1","main_sha":"7"*40,"operation_profile":"agency-preprod-refresh-capability-v1","snapshot_bytes":0}
    op = helper.parse_operation(json.dumps(valid).encode())
    assert op.staging_db.startswith("agency_preprod_stage_")
    assert op.candidate_db.startswith("agency_preprod_candidate_")
    assert op.staging_db != op.candidate_db != helper.RUNTIME_DB
    for key, value in (("sql","DROP DATABASE agency_preprod"),("path","/tmp/x"),("database","agency_preprod"),("table","users_field_data"),("executable","/bin/bash"),("authority","ENABLE")):
        payload = dict(valid); payload[key] = value
        expect_contract_error(lambda p=payload: helper.parse_operation(json.dumps(p).encode()))
    print("caller_controlled_surface_rejected=PASS")

    assert authority == {"schema_version":1,"state":"DISABLED","profile_id":None,"successor_issue":None,"request_authority_model":"NOT_INSTALLED"}
    assert profile["data_activation_authority"]["installed_state"] == "DISABLED"
    assert profile["data_activation_authority"]["helper_can_enable"] is False
    assert "ENABLE" not in helper.ALLOWED_ACTIONS
    for action in ("IMPORT_SANITIZE_HARDEN_RETAIN","BACKUP_ACTIVATE_CONVERGE_VALIDATE","ROLLBACK_RECORDED"):
        payload = dict(valid, action=action, snapshot_bytes=(128 if action == "IMPORT_SANITIZE_HARDEN_RETAIN" else 0))
        candidate = helper.parse_operation(json.dumps(payload).encode())
        expect_contract_error(lambda c=candidate: helper.dispatch(c))
    print("activation_authority_default_disabled=PASS")
    print("deploy_user_cannot_enable_authority=PASS")

    assert sudoers == "agency-preprod ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-preprod-refresh-control"
    for forbidden in ("NOPASSWD: ALL"," SETENV:","mariadb","bash","python","*"):
        assert forbidden not in sudoers
    print("negative_sudo_privilege_matrix=PASS")

    assert "/var/lib/agency-preprod-refresh/refresh-maintenance.flag" in fence and "return 503;" in fence
    for bypass in ("$http_","$arg_","$cookie_","allow ","satisfy ","auth_basic"):
        assert bypass not in fence
    assert "listen 127.0.0.1:18087;" in internal and "location = /health/ready" in internal
    assert "fastcgi_pass unix:/run/php/php8.4-fpm-agency-preprod.sock;" in internal
    assert "listen 0.0.0.0" not in internal and "listen [::]" not in internal
    print("fence_fail_closed_static=PASS")
    print("internal_health_ready_route=PASS")
    print("public_bypass_absent=PASS")

    with tempfile.TemporaryDirectory() as tmp:
        lock = pathlib.Path(tmp) / "lock"; lock.write_bytes(b"")
        first = helper.acquire_lock(str(lock), create=False)
        try: expect_contract_error(lambda: helper.acquire_lock(str(lock), create=False))
        finally: first.close()
    print("deploy_refresh_lock_exclusion=PASS")

    responses = {"TABLE_TYPE='BASE TABLE'":"a\nb","SELECT COUNT(*) FROM information_schema.TABLES":"2","information_schema.VIEWS":"0","information_schema.TRIGGERS":"0","information_schema.EVENTS":"0","information_schema.ROUTINES":"0","information_schema.REFERENTIAL_CONSTRAINTS":"0"}
    original_sql = helper.root_sql
    helper.root_sql = lambda query: next(answer for needle, answer in responses.items() if needle in query)
    try:
        assert helper.base_tables("agency_preprod_stage_0123456789ab") == ["a","b"]
        responses["information_schema.VIEWS"] = "1"
        expect_contract_error(lambda: helper.base_tables("agency_preprod_stage_0123456789ab"))
    finally: helper.root_sql = original_sql
    assert "RENAME TABLE " in helper_text
    assert profile["activation"]["database_rename"] == "FORBIDDEN"
    assert profile["governed_content_apply"] == "FORBIDDEN_BY_DATA_REFRESH_AUTHORITY"
    print("base_table_subset_enforcement=PASS")
    print("atomic_multi_table_rename_model=PASS")

    assert "sudo " not in plan and "agency-preprod-refresh-control" not in plan and "PLAN_MUTATION=NONE" in plan
    assert "restore_prestate" in remote and "nginx -t" in remote and "systemctl reload nginx" in remote
    assert "HUMAN_RECOVERY_REQUIRED=true" in remote and "data_activation_authority=DISABLED" in remote and "root:root:711" in remote
    print("provisioning_plan_mutation_none=PASS")
    print("provisioning_rollback_model=PASS")

    digest_paths = {"helper":HELPER,"side_effect_hardening":HARDENING,"runtime_state_digest":STATE_DIGEST,"disabled_authority_state":AUTH,"fence_snippet":FENCE,"internal_readiness":INTERNAL,"capability_profile":PROFILE}
    for key, path in digest_paths.items():
        assert provision["digests"][key] == hashlib.sha256(path.read_bytes()).hexdigest()
    assert provision["apply"]["data_activation_authority_after_apply"] == "DISABLED"
    assert bundle["data_activation_authority_after_provisioning"] == "DISABLED"
    assert bundle["files"]["helper"]["sha256"] == provision["digests"]["helper"]
    assert helper.SIDE_EFFECT_SHA256 == provision["digests"]["side_effect_hardening"]
    assert profile["fence"]["state_dir_mode"] == "0711"
    assert successor["state"] == "NO_DATA_ACTIVATION_AUTHORITY"
    assert successor["future_authority"] == "SEPARATE_816_CHILD_OR_SUCCESSOR_REQUIRED"
    assert successor["issue_874_may_enable_activation"] is False
    print("bundle_digests_pinned=PASS")
    print("successor_authority_marker=PASS")
    print("LOCAL_874_CONTRACT=PASS")

if __name__ == "__main__": main()
