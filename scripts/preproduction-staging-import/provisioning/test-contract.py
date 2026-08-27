#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]
REPO = pathlib.Path(__file__).resolve().parents[3]
PROVISIONING = pathlib.Path(__file__).resolve().parent
PROFILE = json.loads((PROVISIONING / "profile.json").read_text(encoding="utf-8"))
REMOTE = (PROVISIONING / "remote-provision-root.sh").read_text(encoding="utf-8")
REMOTE_PLAN = (PROVISIONING / "remote-plan-readonly.sh").read_text(encoding="utf-8")
PLAN = (PROVISIONING / "run-plan.sh").read_text(encoding="utf-8")
APPLY = (PROVISIONING / "run-apply.sh").read_text(encoding="utf-8")
PRIVILEGED = ROOT / "privileged"
HELPER = PRIVILEGED / "agency-preprod-staging-db"
HELPER_DIGEST = PRIVILEGED / "agency-preprod-staging-db.sha256"
SANITIZER = PRIVILEGED / "agency-preprod-staging-sanitizer.py"
SANITIZER_DIGEST = PRIVILEGED / "agency-preprod-staging-sanitizer.py.sha256"
POLICY = REPO / "scripts" / "preproduction-refresh" / "sanitization-policy.json"
POLICY_DIGEST = PRIVILEGED / "sanitization-policy.sha256"

EXPECTED_HELPER = "a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71"
EXPECTED_SANITIZER = "fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f"
EXPECTED_POLICY = "cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb"

def digest(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

assert PROFILE["issue_number"] == 851
assert PROFILE["revision_issue_number"] == 859
assert PROFILE["profile_id"] == "agency-preprod-capability-provision-v1"
assert PROFILE["helper"]["expected_sha256"] == EXPECTED_HELPER == HELPER_DIGEST.read_text().strip() == digest(HELPER)
assert PROFILE["bundle"]["sanitizer"]["expected_sha256"] == EXPECTED_SANITIZER == SANITIZER_DIGEST.read_text().strip() == digest(SANITIZER)
assert PROFILE["bundle"]["policy"]["expected_sha256"] == EXPECTED_POLICY == POLICY_DIGEST.read_text().strip() == digest(POLICY)
assert PROFILE["bundle"]["policy"]["policy_version"] == "agency-preprod-refresh-v1"
assert PROFILE["bundle"]["mutable_checkout_runtime_read"] == "FORBIDDEN"
assert PROFILE["sudoers"]["destination"] == "/etc/sudoers.d/agency-preprod-staging-db"
assert PROFILE["sudoers"]["nopasswd_scope"] == "FIXED_HELPER_ONLY"
assert PROFILE["sudoers"]["change_required_for_issue_859"] is False
assert PROFILE["sudoers"]["direct_mariadb"] == "FORBIDDEN"
assert PROFILE["sudoers"]["generic_root_executor_for_deploy_user"] == "NONE"
assert PROFILE["sudoers"]["setenv"] == "FORBIDDEN"
assert PROFILE["apply"]["allowed_helper_proofs"] == ["PRECHECK", "VERIFY_ABSENCE"]
assert PROFILE["apply"]["snapshot_bytes"] == 0
assert PROFILE["apply"]["import"] == "FORBIDDEN"
assert PROFILE["apply"]["import_sanitize_prove"] == "FORBIDDEN"
assert PROFILE["apply"]["bundle_atomic_install"] is True
assert PROFILE["plan"]["preprod_mutation"] == "NONE"
assert PROFILE["execution"]["prod_access"] == "NONE"
assert PROFILE["real_provisioning_performed_in_issue_859"] is False

for required in [
    "HELPER_PATH='/usr/local/sbin/agency-preprod-staging-db'",
    "BUNDLE_DIR='/usr/local/lib/agency-preprod-staging'",
    "SANITIZER_PATH=\"$BUNDLE_DIR/agency-preprod-staging-sanitizer.py\"",
    "POLICY_PATH=\"$BUNDLE_DIR/sanitization-policy.json\"",
    "SUDOERS_PATH='/etc/sudoers.d/agency-preprod-staging-db'",
    "EXPECTED_HELPER_DIGEST='" + EXPECTED_HELPER + "'",
    "EXPECTED_SANITIZER_DIGEST='" + EXPECTED_SANITIZER + "'",
    "EXPECTED_POLICY_DIGEST='" + EXPECTED_POLICY + "'",
    "NOPASSWD: NOSETENV: ${HELPER_PATH}",
    'visudo -cf "$sudo_candidate"',
    "rollback_on_exit",
    'PRECHECK "$REQUEST_ID" 0',
    'VERIFY_ABSENCE "$REQUEST_ID" 0',
    "preprod_runtime_db_touched=NO",
    "prod_access=NONE",
    "issue_834_apply=NOT_PERFORMED",
]:
    assert required in REMOTE, required

policy_line = next(line for line in REMOTE.splitlines() if line.startswith('desired_policy='))
assert "NOPASSWD: NOSETENV: ${HELPER_PATH}" in policy_line
assert "*" not in policy_line and "?" not in policy_line
assert "mariadb" not in policy_line.lower()
assert "NOPASSWD: ALL" not in policy_line
assert re.search(r"(^|\s)SETENV:", policy_line) is None

prevalidate = REMOTE.index('visudo -cf "$sudo_candidate"')
activate = REMOTE.index('mv -f -- "$sudo_candidate" "$SUDOERS_PATH"')
commit = REMOTE.index("committed=1")
assert prevalidate < activate < commit

for line in REMOTE.splitlines():
    if "runuser" in line and "sudo -n" in line:
        assert " PRECHECK " in line or " VERIFY_ABSENCE " in line
        assert " IMPORT " not in line
        assert "IMPORT_SANITIZE_PROVE" not in line

assert "sudo -n -l" not in PLAN
assert "scp" not in PLAN
assert "ROOT_USER" not in PLAN
assert "run-apply.sh" not in PLAN
assert "sudo -n -l" in REMOTE_PLAN
assert "PRECHECK" in REMOTE_PLAN
assert "IMPORT_SANITIZE_PROVE" not in REMOTE_PLAN

assert "ROOT_USER='root'" in APPLY
assert "PREPROD_PROVISIONING_SSH_KEY" in APPLY
for fixed_source in ["$HELPER", "$HELPER_DIGEST", "$SANITIZER", "$SANITIZER_DIGEST", "$POLICY", "$POLICY_DIGEST", "$REMOTE_ROOT"]:
    assert fixed_source in APPLY
for forbidden in ["PREPROD_SUDO_PASSWORD", "SUDO_PASSWORD", "ssh-keyscan", "StrictHostKeyChecking=no", "accept-new", "eval "]:
    assert forbidden not in APPLY

print("PROVISIONING_ROUTE=PASS")
print("PLAN_MUTATION_FREE=YES")
print("APPLY_ONE_SHOT=YES")
print("FIXED_HELPER_PATH=PASS")
print("HELPER_BUNDLE_DIGEST_BOUND=PASS")
print("SUDOERS_FIXED_HELPER_ONLY=PASS")
print("SUDOERS_CHANGE_REQUIRED=NO")
print("DIRECT_MARIADB_SUDO=FORBIDDEN")
print("GENERIC_ROOT_EXECUTOR=NONE")
print("SETENV=FORBIDDEN")
print("VISUDO_PREVALIDATION=PASS")
print("ROLLBACK_FAIL_CLOSE=PASS")
print("PRECHECK_ZERO_DATA=PASS")
print("VERIFY_ABSENCE_ZERO_DATA=PASS")
print("REAL_PROVISIONING_PERFORMED=NO")
